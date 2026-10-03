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
    public const EXPORT_FORMAT = 'lanpa-notfallplaene';
    public const EXPORT_VERSION = 1;
    public const EXPORT_MAX_PLANS = 100;
    /** Entspricht upload_max_filesize in docker/php/php.ini. */
    public const IMPORT_MAX_BYTES = 2097152;

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

    public const ACCESS_FULL = 'full';
    /** Nur Auslösen und Abarbeiten eigener laufender Ereignisse, keine Ereignishistorie. */
    public const ACCESS_TRIGGER = 'trigger';

    public function canView(?array $user): bool
    {
        return $this->accessLevel($user) !== null;
    }

    /** Benutzerzugriff über die Freigabegruppen; die volle Freigabegruppe hat Vorrang. */
    public function accessLevel(?array $user): ?string
    {
        if ($user === null || !$this->settings->bool('emergency_plan_enabled')) {
            return null;
        }
        $groups = array_map(static fn (string $name) => mb_strtolower(trim($name)), $user['groups']);
        foreach (['emergency_plan_group' => self::ACCESS_FULL, 'emergency_plan_trigger_group' => self::ACCESS_TRIGGER] as $key => $level) {
            $group = mb_strtolower(trim($this->settings->get($key)));
            if ($group !== '' && in_array($group, $groups, true)) {
                return $level;
            }
        }

        return null;
    }

    public static function actor(array $user): string
    {
        return 'ad:' . mb_strtolower($user['office_uid']);
    }

    public static function dashboardActor(?array $user, ?string $directoryRole, ?string $localRole, ?string $localName): ?string
    {
        if ($user !== null && empty($user['fake']) && self::isManager($directoryRole)) {
            return self::actor($user);
        }
        if (self::isManager($localRole) && $localName !== null) {
            return $user !== null && empty($user['fake']) ? self::actor($user) : 'local:' . mb_strtolower($localName);
        }

        return null;
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
        $id = $this->repository->savePlan($id, $revision, $this->prepare($input), $actor);
        app_logger()->info('Notfallplan-Entwurf gespeichert.', ['id' => $id, 'actor' => $actor]);

        return $id;
    }

    /**
     * Erzeugt eine portable Exportdatei (JSON) mit den aktuellen Entwürfen der gewählten Pläne.
     *
     * @param list<int> $ids
     */
    public function exportPlans(array $ids): string
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => is_int($id) && $id > 0)));
        if ($ids === []) {
            throw new ValidationException(['export' => 'Bitte mindestens einen Notfallplan für den Export auswählen.']);
        }
        if (count($ids) > self::EXPORT_MAX_PLANS) {
            throw new ValidationException(['export' => 'Höchstens ' . self::EXPORT_MAX_PLANS . ' Notfallpläne je Exportdatei.']);
        }
        $plans = [];
        foreach ($ids as $id) {
            $plan = $this->repository->plan($id);
            $plans[] = [
                'title' => $plan['definition']['title'],
                'source_id' => (int) $plan['id'],
                'source_revision' => (int) $plan['revision'],
                'definition' => $plan['definition'],
            ];
        }

        $contents = json_encode([
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'plans' => $plans,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        if (strlen($contents) > self::IMPORT_MAX_BYTES) {
            throw new ValidationException(['export' => 'Die Exportdatei wäre größer als 2 MB und könnte nicht importiert werden. Bitte weniger Notfallpläne auswählen.']);
        }

        return $contents;
    }

    /**
     * Importiert alle Pläne einer Exportdatei als neue Entwürfe (alles oder nichts).
     * SMS-Elemente werden anhand des Titels auf die lokalen, aktiven Alarmvorlagen abgebildet.
     *
     * @return list<int> IDs der angelegten Entwürfe
     */
    public function importPlans(string $contents, string $actor): array
    {
        if ($contents === '' || strlen($contents) > self::IMPORT_MAX_BYTES) {
            self::importFail('Die Datei ist leer oder größer als 2 MB.');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::importFail('Die Datei ist keine gültige Notfallplan-Exportdatei (JSON).');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::EXPORT_FORMAT) {
            self::importFail('Die Datei ist keine Notfallplan-Exportdatei.');
        }
        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            self::importFail('Die Version der Exportdatei wird nicht unterstützt.');
        }
        $plans = $data['plans'] ?? null;
        if (!is_array($plans) || !array_is_list($plans) || $plans === [] || count($plans) > self::EXPORT_MAX_PLANS) {
            self::importFail('Die Exportdatei muss 1 bis ' . self::EXPORT_MAX_PLANS . ' Notfallpläne enthalten.');
        }
        $alarms = $this->alarmOptions();
        $definitions = [];
        $missing = [];
        foreach ($plans as $index => $entry) {
            $label = 'Plan ' . ($index + 1);
            if (!is_array($entry) || !is_array($entry['definition'] ?? null) || !is_array($entry['definition']['nodes'] ?? null)) {
                self::importFail($label . ': ungültiger Aufbau.');
            }
            $input = $entry['definition'];
            if (is_string($input['title'] ?? null) && trim($input['title']) !== '') {
                $label = 'Plan „' . mb_substr(trim($input['title']), 0, 190) . '“';
            }
            foreach ($input['nodes'] as &$node) {
                if (!is_array($node) || ($node['type'] ?? null) !== 'sms') {
                    continue;
                }
                $match = self::matchAlarm(is_array($node['alarm'] ?? null) ? $node['alarm'] : [], $alarms);
                if (is_string($match)) {
                    $missing[$match] = true;
                    $node['alarm_id'] = 0;
                } else {
                    $node['alarm_id'] = $match;
                }
                unset($node['alarm']);
            }
            unset($node);
            if ($missing !== []) {
                continue;
            }
            try {
                $definitions[] = $this->prepare($input);
            } catch (ValidationException $exception) {
                self::importFail($label . ': ' . implode(' ', $exception->errors()));
            }
        }
        if ($missing !== []) {
            self::importFail('Folgende SMS-Alarmvorlagen fehlen auf diesem System oder sind nicht eindeutig: '
                . implode(', ', array_keys($missing)) . '. Bitte zuerst gleichnamige, aktive Alarmierungen anlegen. Es wurde nichts importiert.');
        }
        $ids = $this->repository->importPlans($definitions, $actor);
        app_logger()->info('Notfallpläne importiert.', ['ids' => $ids, 'actor' => $actor]);

        return $ids;
    }

    /**
     * @param array<string,mixed> $hint Alarmdaten aus der Exportdatei
     * @param list<array<string,mixed>> $alarms lokale, aktive Alarmvorlagen
     * @return int|string ID der passenden Vorlage oder Bezeichnung für die Fehlermeldung
     */
    private static function matchAlarm(array $hint, array $alarms): int|string
    {
        $title = is_string($hint['title'] ?? null) ? trim($hint['title']) : '';
        if ($title === '') {
            return '(ohne Titel)';
        }
        $normalize = static fn (mixed $value): string => mb_strtolower(trim((string) $value));
        $candidates = array_values(array_filter($alarms, static fn (array $alarm) => $normalize($alarm['title']) === $normalize($title)));
        if (count($candidates) > 1) {
            $candidates = array_values(array_filter($candidates, static fn (array $alarm) => trim((string) $alarm['text']) === trim((string) ($hint['alarm_text'] ?? ''))
                && trim((string) $alarm['target']) === trim((string) ($hint['alarm_group_number'] ?? ''))));
        }

        return count($candidates) === 1 ? (int) $candidates[0]['id'] : '„' . mb_substr($title, 0, 190) . '“';
    }

    private static function importFail(string $message): never
    {
        throw new ValidationException(['import' => $message]);
    }

    /** Prüft die Definition und hängt die aktuellen Daten der SMS-Alarmvorlagen an. */
    private function prepare(array $input): array
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

        return $definition;
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

    public function requireEvent(int $id, string $actor, bool $manager, bool $activeOnly = false): array
    {
        $event = $this->repository->event($id);
        if (!$manager && $event['actor'] !== $actor) {
            throw new HttpException(403, 'Nur die auslösende Person und das KAEP-Team dürfen dieses Ereignis öffnen.');
        }
        if (!$manager && $activeOnly && $event['status'] !== 'active') {
            throw new HttpException(403, 'Abgeschlossene Ereignisse sind nur für das KAEP-Team einsehbar.');
        }

        return $event;
    }

    public function update(array $event, string $actor, array $input): void
    {
        $change = EmergencyPlanRuntime::change($event, $actor, $input);
        $this->repository->change($event, $change['state'], $actor, $change['node'], $change['action'],
            $change['message'], $change['close'], $change['sms'], $change['checkChanges']);
        if ($change['sms']) {
            try {
                $result = ($this->sendSms)($change['alarm']);
            } catch (\Throwable $exception) {
                app_logger()->error('Notfallplan: SMS-Ergebnis unklar.', ['event' => $event['id'], 'node' => $change['node']]);
                $result = ['status' => 'error', 'message' => 'Versandergebnis unklar. Gateway prüfen und Ersatzmeldeweg nutzen.'];
            }
            $this->repository->finishSms((int) $event['id'], $change['node'], $actor, $result['status'], $result['message']);
        }
    }
}
