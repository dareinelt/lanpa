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
