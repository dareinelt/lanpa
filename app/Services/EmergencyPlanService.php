<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Repositories\EmergencyPlanRepository;
use App\Repositories\NavigationRepository;
use App\Security\Auth;
use Closure;
use PDOException;
use RuntimeException;

final class EmergencyPlanService
{
    public function __construct(
        public readonly EmergencyPlanRepository $repository,
        private readonly SettingsService $settings,
        private readonly NavigationRepository $navigation,
        private readonly Closure $verifyPassword,
        private readonly Closure $recipients,
        private readonly Closure $sendSms,
        private readonly string $baseUrl
    ) {
    }

    public static function isManager(?string $role): bool
    {
        return in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_KAEP], true);
    }

    public function canView(?array $user): bool
    {
        $group = trim($this->settings->get('emergency_plan_group'));

        return $user !== null && $this->settings->bool('emergency_plan_enabled') && $group !== ''
            && in_array(mb_strtolower($group), array_map(static fn (string $name) => mb_strtolower(trim($name)), $user['groups']), true);
    }

    public static function actor(array $user): string
    {
        return 'ad:' . mb_strtolower($user['office_uid']);
    }

    public function alarmOptions(): array
    {
        $items = [];
        foreach ($this->navigation->all() as $item) {
            if ($item['type'] === 'alarm' && (int) $item['active'] === 1) {
                $items[] = ['id' => (int) $item['id'], 'title' => $item['title'],
                    'text' => $item['alarm_text'], 'target' => $item['alarm_group_number'],
                    'mode' => $item['alarm_group_type']];
            }
        }

        return $items;
    }

    public function save(int $id, int $revision, array $input, string $actor): int
    {
        $definition = EmergencyPlanDefinition::validate($input);
        foreach ($definition['nodes'] as &$node) {
            if ($node['type'] !== 'sms') {
                continue;
            }
            $alarm = $this->navigation->find($node['alarm_id']);
            if ($alarm === null || $alarm['type'] !== 'alarm' || (int) $alarm['active'] !== 1
                || trim((string) $alarm['alarm_text']) === '' || mb_strlen((string) $alarm['alarm_text']) > 255
                || trim((string) $alarm['alarm_group_number']) === ''
                || !in_array($alarm['alarm_group_type'], ['group', 'number'], true)) {
                throw new ValidationException(['sms' => 'Eine ausgewählte SMS-Vorlage ist nicht aktiv oder unvollständig.']);
            }
            $node['alarm'] = array_intersect_key($alarm, array_flip(['title', 'alarm_text', 'alarm_group_number', 'alarm_group_description', 'alarm_group_type']));
        }
        unset($node);
        $id = $this->repository->savePlan($id, $revision, $definition, $actor);
        app_logger()->info('Notfallplan-Entwurf gespeichert.', ['id' => $id, 'actor' => $actor]);

        return $id;
    }

    public function start(int $id, int $revision, array $user, string $password, string $key): int
    {
        if (!$this->canView($user) || !empty($user['fake'])) {
            throw new HttpException(403, 'Eine echte, freigegebene Windows-Anmeldung ist erforderlich.');
        }
        $actor = self::actor($user);
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new HttpException(422, 'Ungültige Startbestätigung. Bitte Plan erneut öffnen.');
        }
        $existing = $this->repository->existingRequest($key, $actor);
        if ($existing !== null) {
            return $existing;
        }
        $plan = $this->repository->publishedPlan($id);
        if (!(bool) $plan['published'] || (int) $plan['revision'] !== $revision) {
            throw new HttpException(409, 'Der Plan wurde geändert oder zurückgezogen. Bitte erneut prüfen.');
        }
        if (!$this->repository->reservePasswordAttempt($actor, time())) {
            throw new HttpException(429, 'Zu viele Kennwortbestätigungen. Bitte nach fünf Minuten erneut versuchen.');
        }
        try {
            $valid = ($this->verifyPassword)($user, $password);
        } catch (RuntimeException $exception) {
            app_logger()->error('AD-Kennwortbestätigung nicht verfügbar.', ['actor' => $actor]);
            throw new HttpException(503, 'AD-Kennwortbestätigung nicht verfügbar. Kein Ereignis gestartet. Nutzen Sie den festgelegten Ersatzmeldeweg.');
        }
        if (!$valid) {
            app_logger()->warning('Notfallplan: Kennwortbestätigung abgelehnt.', ['actor' => $actor]);
            throw new HttpException(403, 'AD-Kennwort konnte nicht bestätigt werden. Kein Ereignis gestartet.');
        }
        $recipients = ($this->recipients)();
        try {
            return $this->repository->start($plan, $actor, $key, $recipients['emails'], $recipients['missing'], $this->baseUrl);
        } catch (PDOException $exception) {
            $existing = $this->repository->existingRequest($key, $actor);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    public function requireEvent(int $id, string $actor, bool $manager): array
    {
        $event = $this->repository->event($id);
        if (!$manager && $event['actor'] !== $actor) {
            throw new HttpException(403, 'Nur die auslösende Person und das KAEP-Team dürfen dieses Ereignis öffnen.');
        }

        return $event;
    }

    public function update(array $event, string $actor, array $input): void
    {
        if ($event['status'] !== 'active' || (int) ($input['revision'] ?? 0) !== (int) $event['revision']) {
            throw new HttpException(409, 'Stand veraltet oder Ereignis abgeschlossen. Bitte aktualisieren.');
        }
        $nodeId = (string) ($input['node'] ?? '');
        $action = (string) ($input['action'] ?? '');
        $comment = EmergencyPlanDefinition::text($input['comment'] ?? '', 2000, 'Kommentar');
        if ($action === 'close') {
            $ready = EmergencyPlanDefinition::readiness($event['snapshot'], $event['state']);
            foreach ($ready as $id => $availability) {
                if ($availability !== 'skipped' && ($event['state'][$id]['status'] ?? 'open') !== 'done' && $comment === '') {
                    throw new ValidationException(['close' => 'Für den Abschluss mit offenen Maßnahmen ist eine Begründung erforderlich.']);
                }
            }
            foreach ($event['sms'] as $sms) {
                if ($sms['status'] === 'pending' && $comment === '') {
                    throw new ValidationException(['close' => 'Ungeklärter SMS-Versand: vor Abschluss prüfen und im Abschlusskommentar dokumentieren.']);
                }
            }
            $this->repository->change($event, $event['state'], $actor, '', 'closed', $comment ?: 'Ereignis abgeschlossen.', true);

            return;
        }
        $node = null;
        foreach ($event['snapshot']['nodes'] as $candidate) {
            if ($candidate['id'] === $nodeId) {
                $node = $candidate;
            }
        }
        if ($node === null) {
            throw new HttpException(422, 'Maßnahme nicht gefunden.');
        }
        if ($action === 'comment') {
            if ($comment === '') {
                throw new ValidationException(['comment' => 'Bitte einen Kommentar eingeben.']);
            }
            $this->repository->change($event, $event['state'], $actor, $nodeId, 'comment', $comment);

            return;
        }
        $ready = EmergencyPlanDefinition::readiness($event['snapshot'], $event['state']);
        if ($ready[$nodeId] !== 'ready') {
            throw new HttpException(409, 'Die Voraussetzungen dieser Maßnahme sind noch nicht erfüllt oder der Zweig entfällt.');
        }
        if ($action === 'sms') {
            if ($node['type'] !== 'sms' || isset($event['sms'][$nodeId]) || ($event['state'][$nodeId]['status'] ?? '') === 'done') {
                throw new HttpException(409, 'SMS wurde bereits angefordert oder ist hier nicht vorgesehen. Versandstatus prüfen; keine erneute Auslösung.');
            }
            $this->repository->change($event, $event['state'], $actor, $nodeId, 'sms_requested', 'SMS-Versand separat bestätigt.', false, true);
            try {
                $result = ($this->sendSms)($node['alarm']);
            } catch (\Throwable $exception) {
                app_logger()->error('Notfallplan: SMS-Ergebnis unklar.', ['event' => $event['id'], 'node' => $nodeId]);
                $result = ['status' => 'error', 'message' => 'Versandergebnis unklar. Gateway prüfen und Ersatzmeldeweg nutzen.'];
            }
            $this->repository->finishSms((int) $event['id'], $nodeId, $actor, $result['status'], $result['message']);

            return;
        }
        if ($action !== 'status' || !in_array($input['status'] ?? '', EmergencyPlanDefinition::STATUSES, true)) {
            throw new HttpException(422, 'Ungültige Statusänderung.');
        }
        $state = $event['state'];
        $old = $state[$nodeId] ?? ['status' => 'open'];
        if ($old['status'] === 'done') {
            throw new HttpException(409, 'Erledigte Maßnahmen bleiben unverändert. Korrekturen bitte als Kommentar dokumentieren.');
        }
        $new = ['status' => $input['status'], 'answer' => '', 'checks' => [], 'check_details' => []];
        $checkChanges = [];
        if ($node['type'] === 'decision' && $new['status'] === 'done') {
            if (!in_array($input['answer'] ?? '', ['yes', 'no'], true)) {
                throw new ValidationException(['answer' => 'Bitte die Entscheidung mit Ja oder Nein beantworten.']);
            }
            $new['answer'] = $input['answer'];
        }
        if ($node['type'] === 'checklist') {
            $checked = $input['checks'] ?? [];
            if (!is_array($checked) || count($checked) > count($node['checks'])) {
                throw new HttpException(422, 'Ungültige Prüfpunkte.');
            }
            foreach ($checked as $index) {
                if (!is_scalar($index) || !ctype_digit((string) $index) || !isset($node['checks'][(int) $index])) {
                    throw new HttpException(422, 'Ungültiger Prüfpunkt.');
                }
                $new['checks'][] = (int) $index;
            }
            $new['checks'] = array_values(array_unique($new['checks']));
            foreach ($node['checks'] as $index => $label) {
                $wasChecked = in_array($index, $old['checks'] ?? [], true);
                $isChecked = in_array($index, $new['checks'], true);
                if ($isChecked) {
                    $new['check_details'][$index] = $wasChecked && isset($old['check_details'][$index])
                        ? $old['check_details'][$index] : ['actor' => $actor, 'at' => gmdate('Y-m-d H:i:s')];
                }
                if ($wasChecked !== $isChecked) {
                    $checkChanges[] = [
                        'action' => $isChecked ? 'check_done' : 'check_reopened',
                        'message' => 'Prüfpunkt ' . ($index + 1) . ': ' . $label . ' – ' . ($isChecked ? 'bestätigt' : 'Bestätigung zurückgenommen'),
                    ];
                }
            }
            if ($new['status'] === 'done' && count($new['checks']) !== count($node['checks'])) {
                throw new ValidationException(['checks' => 'Vor „Erledigt“ bitte alle Prüfpunkte bestätigen.']);
            }
        }
        if ($node['type'] === 'sms' && $new['status'] === 'done' && ($event['sms'][$nodeId]['status'] ?? '') !== 'success' && $comment === '') {
            throw new ValidationException(['sms' => 'Keine Gateway-Bestätigung: Ersatzalarmierung bitte im Kommentar dokumentieren.']);
        }
        $state[$nodeId] = $new;
        $labels = ['open' => 'Offen', 'in_progress' => 'In Arbeit', 'blocked' => 'Blockiert', 'done' => 'Erledigt'];
        $message = $labels[$old['status']] . ' → ' . $labels[$new['status']]
            . ($new['answer'] !== '' ? '; Entscheidung: ' . ($new['answer'] === 'yes' ? 'Ja' : 'Nein') : '')
            . ($node['type'] === 'checklist' ? '; Prüfpunkte: ' . implode(', ', array_map(static fn ($i) => $node['checks'][$i], $new['checks'])) : '')
            . ($comment !== '' ? '; ' . $comment : '');
        $this->repository->change($event, $state, $actor, $nodeId, 'status', $message, false, false, $checkChanges);
    }
}
