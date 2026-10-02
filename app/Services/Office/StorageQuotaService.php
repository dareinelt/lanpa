<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Exceptions\ValidationException;
use App\Repositories\StorageQuotaRepository;
use App\Security\SsoAuth;
use App\Services\SettingsService;
use App\Support\Validator;
use Closure;
use JsonException;

/**
 * Speicherplatz-Kontingente (Quota) der Benutzer in Nextcloud.
 *
 * Wirksames Kontingent eines Benutzers:
 *   1. individuelles Kontingent (Begruendung Pflicht, mit Verlauf)
 *   2. sonst das groesste Kontingent seiner AD-Gruppen mit eigener Regel
 *   3. sonst der Standard (Einstellung office_quota_default_mb, 500 MB)
 *
 * Nextcloud erhaelt den Standard (files/default_quota) und alle davon
 * abweichenden Benutzer signiert ueber intranet_integration/api/quota.
 * Die Gesundheitspruefung vergleicht den Fingerabdruck und uebertraegt bei
 * Abweichung erneut (z. B. nach geaenderten Gruppenmitgliedschaften durch
 * die AD-Synchronisation).
 *
 * @phpstan-type QuotaUser array{id:int,uid:string,display_name:string,department:string,source_label:string,groups:list<string>,group_mb:?int,group_name:string,override:?array<string,mixed>,base_mb:int,effective_mb:int,origin:string,active:bool}
 */
final class StorageQuotaService
{
    public const DEFAULT_SETTING = 'office_quota_default_mb';
    public const LAST_PUSH_SETTING = 'office_quota_last_push';
    public const DEFAULT_MB = 500;
    public const MIN_MB = 1;
    /** 10 TB */
    public const MAX_MB = 10485760;
    public const MAX_REASON = 1000;

    /** Gleiches Muster wie die Nextcloud-App (SamAccountName[@kennung]). */
    public const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';

    /** @var Closure(): array<int,array{key:string,label:string}> */
    private readonly Closure $sources;

    /** @var list<QuotaUser>|null */
    private ?array $usersCache = null;

    /**
     * @param null|Closure(): array<int,array{key:string,label:string}> $sources Identitaetsquellen (ID => Kennung, Beschriftung); 0 = Hauptquelle
     */
    public function __construct(
        private readonly StorageQuotaRepository $repository,
        private readonly SettingsService $settings,
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe,
        ?Closure $sources = null
    ) {
        $this->sources = $sources ?? static fn (): array => [0 => ['key' => '', 'label' => '']];
    }

    public function defaultMb(): int
    {
        $value = $this->settings->int(self::DEFAULT_SETTING, self::DEFAULT_MB);

        return $value >= self::MIN_MB && $value <= self::MAX_MB ? $value : self::DEFAULT_MB;
    }

    /**
     * "500", "500 MB", "2 GB", "1,5 GB", "1 TB" -> Megabyte (1 GB = 1024 MB).
     */
    public static function parseSize(string $value): ?int
    {
        $value = strtoupper(str_replace(' ', '', trim($value)));
        if (preg_match('/^(\d{1,9}(?:[.,]\d{1,3})?)(MB|M|MIB|GB|G|GIB|TB|T|TIB)?$/', $value, $m) !== 1) {
            return null;
        }

        $number = (float) str_replace(',', '.', $m[1]);
        $factor = match ($m[2] ?? '') {
            'GB', 'G', 'GIB' => 1024,
            'TB', 'T', 'TIB' => 1024 * 1024,
            default => 1,
        };
        $mb = (int) round($number * $factor);

        return $mb >= self::MIN_MB && $mb <= self::MAX_MB ? $mb : null;
    }

    /**
     * 500 -> "500 MB", 2048 -> "2 GB", 1536 -> "1,5 GB".
     */
    public static function formatMb(?int $mb): string
    {
        if ($mb === null) {
            return '–';
        }
        if ($mb >= 1024 * 1024 && $mb % 262144 === 0) {
            return str_replace('.', ',', (string) round($mb / (1024 * 1024), 2)) . ' TB';
        }
        if ($mb >= 1024 && $mb % 256 === 0) {
            return str_replace('.', ',', (string) round($mb / 1024, 2)) . ' GB';
        }

        return $mb . ' MB';
    }

    /**
     * @return list<array{id:int,group_name:string,quota_mb:int,reason:string,updated_by:string,updated_at:string,members:int}>
     */
    public function groupRules(): array
    {
        $rules = $this->repository->groupRules();
        $memberships = $this->repository->membershipsFor(array_column($rules, 'group_name'));
        $counts = [];
        foreach ($memberships as $groups) {
            foreach (array_unique($groups) as $group) {
                $counts[$group] = ($counts[$group] ?? 0) + 1;
            }
        }

        return array_map(
            static fn (array $rule): array => $rule + ['members' => $counts[mb_strtolower($rule['group_name'])] ?? 0],
            $rules
        );
    }

    /**
     * Alle aktiven Benutzer mit wirksamem Kontingent.
     *
     * @return list<QuotaUser>
     */
    public function users(): array
    {
        if ($this->usersCache !== null) {
            return $this->usersCache;
        }

        $default = $this->defaultMb();
        $rules = [];
        foreach ($this->repository->groupRules() as $rule) {
            $rules[mb_strtolower($rule['group_name'])] = $rule;
        }
        $memberships = $this->repository->membershipsFor(array_keys($rules));
        $overrides = [];
        foreach ($this->repository->overrides() as $override) {
            $overrides[strtolower($override['user_uid'])] = $override;
        }
        $sources = ($this->sources)();

        $users = [];
        foreach ($this->repository->activeUsers() as $row) {
            $source = $sources[$row['identity_source_id']] ?? null;
            if ($source === null) {
                continue;
            }
            $uid = SsoAuth::officeUid($row['samaccount_name'], $source['key']);
            if (preg_match(self::UID_PATTERN, $uid) !== 1) {
                continue;
            }

            $groupMb = null;
            $groupName = '';
            $groups = [];
            foreach (array_unique($memberships[$row['id']] ?? []) as $group) {
                if (!isset($rules[$group])) {
                    continue;
                }
                $groups[] = $rules[$group]['group_name'];
                if ($groupMb === null || $rules[$group]['quota_mb'] > $groupMb) {
                    $groupMb = $rules[$group]['quota_mb'];
                    $groupName = $rules[$group]['group_name'];
                }
            }
            sort($groups, SORT_STRING | SORT_FLAG_CASE);

            $override = $overrides[strtolower($uid)] ?? null;
            $base = $groupMb ?? $default;
            $users[] = [
                'id' => $row['id'],
                'uid' => $uid,
                'display_name' => $row['display_name'] !== '' ? $row['display_name'] : $uid,
                'department' => $row['department'],
                'source_label' => $row['identity_source_id'] > 0 ? $source['label'] : '',
                'groups' => $groups,
                'group_mb' => $groupMb,
                'group_name' => $groupName,
                'override' => $override,
                'base_mb' => $base,
                'effective_mb' => $override !== null ? $override['quota_mb'] : $base,
                'origin' => $override !== null ? 'override' : ($groupMb !== null ? 'group' : 'default'),
                'active' => true,
            ];
        }

        return $this->usersCache = $users;
    }

    /**
     * @return QuotaUser|null
     */
    public function findUser(int $phonebookId): ?array
    {
        foreach ($this->users() as $user) {
            if ($user['id'] === $phonebookId) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Suche ueber Name, Kennung und Abteilung.
     *
     * @return list<QuotaUser>
     */
    public function searchUsers(string $term, int $limit = 50): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($term))) ?: [], 'strlen'));
        if ($tokens === []) {
            return [];
        }

        $found = [];
        foreach ($this->users() as $user) {
            $haystack = mb_strtolower($user['display_name'] . ' ' . $user['uid'] . ' ' . $user['department']);
            foreach ($tokens as $token) {
                if (!str_contains($haystack, $token)) {
                    continue 2;
                }
            }
            $found[] = $user;
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * Benutzer mit mehr als dem Standard (individuell oder ueber Gruppen),
     * groesste Kontingente zuerst. Individuelle Kontingente nicht mehr
     * aktiver Benutzer erscheinen ebenfalls (active = false).
     *
     * @return list<QuotaUser>
     */
    public function aboveDefault(): array
    {
        $default = $this->defaultMb();

        return $this->sorted(array_values(array_filter(
            $this->withInactiveOverrides(),
            static fn (array $user): bool => $user['effective_mb'] > $default
        )));
    }

    /**
     * Individuelle Kontingente, die nicht ueber dem Standard liegen.
     *
     * @return list<QuotaUser>
     */
    public function overridesNotAboveDefault(): array
    {
        $default = $this->defaultMb();

        return $this->sorted(array_values(array_filter(
            $this->withInactiveOverrides(),
            static fn (array $user): bool => $user['override'] !== null && $user['effective_mb'] <= $default
        )));
    }

    /**
     * @return array{total:int,above:int,overrides:int,groups:int}
     */
    public function summary(): array
    {
        return [
            'total' => count($this->users()),
            'above' => count($this->aboveDefault()),
            'overrides' => count($this->repository->overrides()),
            'groups' => count($this->repository->groupRules()),
        ];
    }

    public function setDefault(string $input, string $reason, string $admin): void
    {
        $mb = self::parseSize($input);
        if ($mb === null) {
            throw new ValidationException(['default_quota' => self::sizeError()]);
        }

        $old = $this->defaultMb();
        $reason = Validator::cleanText($reason, self::MAX_REASON);
        if ($old === $mb && $this->settings->get(self::DEFAULT_SETTING) !== '') {
            return;
        }

        $this->settings->update([self::DEFAULT_SETTING => (string) $mb]);
        $this->repository->addHistory('default', '', 'Standard', 'change', $old, $mb, $reason, $admin);
        $this->usersCache = null;
    }

    public function saveGroupRule(string $groupName, string $input, string $reason, string $admin): void
    {
        $groupName = Validator::cleanText($groupName, 190);
        $groupName = trim($groupName, " \t,;");
        $errors = [];
        if ($groupName === '' || preg_match('/[,;]/', $groupName) === 1) {
            $errors['group_name'] = 'Bitte genau eine AD-Gruppe angeben.';
        }
        $mb = self::parseSize($input);
        if ($mb === null) {
            $errors['group_quota'] = self::sizeError();
        }
        if ($errors !== [] || $mb === null) {
            throw new ValidationException($errors);
        }

        $reason = Validator::cleanText($reason, self::MAX_REASON);
        $existing = $this->repository->findGroupRuleByName($groupName);
        if ($existing !== null && $existing['quota_mb'] === $mb && $existing['group_name'] === $groupName && $existing['reason'] === $reason) {
            return;
        }

        $this->repository->saveGroupRule($groupName, $mb, $reason, $admin);
        $this->repository->addHistory('group', $groupName, $groupName, $existing === null ? 'set' : 'change', $existing['quota_mb'] ?? null, $mb, $reason, $admin);
        $this->usersCache = null;
    }

    public function deleteGroupRule(int $id, string $admin): ?string
    {
        $rule = $this->repository->findGroupRule($id);
        if ($rule === null) {
            return null;
        }

        $this->repository->deleteGroupRule($id);
        $this->repository->addHistory('group', $rule['group_name'], $rule['group_name'], 'remove', $rule['quota_mb'], null, '', $admin);
        $this->usersCache = null;

        return $rule['group_name'];
    }

    /**
     * Setzt oder aendert das individuelle Kontingent eines Benutzers.
     */
    public function saveOverride(int $phonebookId, string $input, string $reason, string $admin): void
    {
        $user = $this->findUser($phonebookId);
        if ($user === null) {
            throw new ValidationException(['user' => 'Der Benutzer wurde nicht gefunden oder hat keine Nextcloud-Kennung.']);
        }

        $errors = [];
        $mb = self::parseSize($input);
        if ($mb === null) {
            $errors['quota'] = self::sizeError();
        }
        $reason = Validator::cleanText($reason, self::MAX_REASON);
        if (mb_strlen($reason) < 5) {
            $errors['reason'] = 'Bitte eine Begründung angeben (mindestens 5 Zeichen).';
        }
        if ($errors !== [] || $mb === null) {
            throw new ValidationException($errors);
        }

        $old = $user['override'];
        if ($old !== null && $old['quota_mb'] === $mb && $old['reason'] === $reason) {
            return;
        }

        $this->repository->saveOverride($user['uid'], $user['display_name'], $mb, $reason, $admin);
        $this->repository->addHistory(
            'user',
            $user['uid'],
            $user['display_name'],
            $old === null ? 'set' : 'change',
            $old !== null ? (int) $old['quota_mb'] : $user['base_mb'],
            $mb,
            $reason,
            $admin
        );
        $this->usersCache = null;
    }

    /**
     * Entfernt ein individuelles Kontingent (Begruendung Pflicht); danach
     * gilt wieder Gruppe bzw. Standard.
     */
    public function removeOverride(string $uid, string $reason, string $admin): void
    {
        $override = $this->repository->findOverride($uid);
        if ($override === null) {
            throw new ValidationException(['user' => 'Für diesen Benutzer ist kein individuelles Kontingent hinterlegt.']);
        }
        $reason = Validator::cleanText($reason, self::MAX_REASON);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['remove_reason' => 'Bitte eine Begründung angeben (mindestens 5 Zeichen).']);
        }

        $base = $this->defaultMb();
        foreach ($this->users() as $user) {
            if (strcasecmp($user['uid'], $override['user_uid']) === 0) {
                $base = $user['base_mb'];
                break;
            }
        }

        $this->repository->deleteOverride($override['user_uid']);
        $this->repository->addHistory('user', $override['user_uid'], $override['display_name'], 'remove', $override['quota_mb'], $base, $reason, $admin);
        $this->usersCache = null;
    }

    /**
     * @return array{user_uid:string,display_name:string,quota_mb:int,reason:string,created_by:string,updated_by:string,created_at:string,updated_at:string}|null
     */
    public function findOverride(string $uid): ?array
    {
        return $this->repository->findOverride($uid);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(int $limit = 100, int $offset = 0, ?string $subjectType = null, ?string $subject = null): array
    {
        return $this->repository->history($limit, $offset, $subjectType, $subject);
    }

    public function countHistory(?string $subjectType = null, ?string $subject = null): int
    {
        return $this->repository->countHistory($subjectType, $subject);
    }

    /**
     * Abweichende Kontingente fuer Nextcloud (Kennung => MB); Benutzer mit
     * dem Standard fehlen (dafuer gilt files/default_quota).
     *
     * @return array<string,int>
     */
    public function nextcloudUsers(): array
    {
        $default = $this->defaultMb();
        $users = [];
        foreach ($this->repository->overrides() as $override) {
            if (preg_match(self::UID_PATTERN, $override['user_uid']) === 1 && $override['quota_mb'] !== $default) {
                $users[$override['user_uid']] = $override['quota_mb'];
            }
        }
        foreach ($this->users() as $user) {
            if ($user['override'] === null && $user['effective_mb'] !== $default) {
                $users[$user['uid']] = $user['effective_mb'];
            }
        }
        ksort($users, SORT_STRING);

        return $users;
    }

    public function fingerprint(): string
    {
        return self::fingerprintOf($this->defaultMb(), $this->nextcloudUsers());
    }

    /**
     * @param array<string,int> $users
     */
    public static function fingerprintOf(int $defaultMb, array $users): string
    {
        ksort($users, SORT_STRING);

        return hash('sha256', $defaultMb . "\n" . json_encode($users, JSON_UNESCAPED_SLASHES));
    }

    public function inSync(mixed $remoteFingerprint): bool
    {
        return is_string($remoteFingerprint) && $remoteFingerprint !== '' && hash_equals($this->fingerprint(), $remoteFingerprint);
    }

    /**
     * @return array{version:int,default_mb:int,users:array<string,int>,fingerprint:string}
     */
    public function payload(): array
    {
        $default = $this->defaultMb();
        $users = $this->nextcloudUsers();

        return [
            'version' => 1,
            'default_mb' => $default,
            'users' => $users,
            'fingerprint' => self::fingerprintOf($default, $users),
        ];
    }

    /**
     * Uebertraegt Standard und abweichende Kontingente an Nextcloud.
     *
     * @return array{ok:bool,message:string}
     */
    public function pushToNextcloud(): array
    {
        $result = $this->doPush();
        $this->rememberPush($result);

        return $result;
    }

    /**
     * Uebertraegt nur bei aktivem Office (sonst null).
     *
     * @return array{ok:bool,message:string}|null
     */
    public function pushIfEnabled(): ?array
    {
        return $this->office->isEnabled() ? $this->pushToNextcloud() : null;
    }

    /**
     * Ergebnis der letzten Uebertragung (informativ).
     *
     * @return array{ok:bool,message:string,at:string,fingerprint:string,in_sync:bool}|null
     */
    public function lastPush(): ?array
    {
        $data = json_decode($this->settings->get(self::LAST_PUSH_SETTING), true);
        if (!is_array($data)) {
            return null;
        }
        $fingerprint = (string) ($data['fingerprint'] ?? '');

        return [
            'ok' => !empty($data['ok']),
            'message' => (string) ($data['message'] ?? ''),
            'at' => (string) ($data['at'] ?? ''),
            'fingerprint' => $fingerprint,
            'in_sync' => !empty($data['ok']) && $fingerprint !== '' && hash_equals($this->fingerprint(), $fingerprint),
        ];
    }

    public function officeEnabled(): bool
    {
        return $this->office->isEnabled();
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function doPush(): array
    {
        $secret = $this->office->jwtSecret();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).'];
        }

        try {
            $body = json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
        } catch (JsonException) {
            return ['ok' => false, 'message' => 'Kontingente konnten nicht erzeugt werden.'];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/quota';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . OfficeJwt::quotaConfigToken($secret, $body),
        ], $body, max(60, (int) ($infra['timeout'] ?? 4) * 3));

        if ($response['error'] !== null || $response['status'] === 0) {
            return ['ok' => false, 'message' => 'Nextcloud nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort')];
        }
        if ($response['status'] === 401) {
            return ['ok' => false, 'message' => 'Nextcloud hat die Übergabe abgelehnt (JWT-Secret abweichend).'];
        }
        if ($response['status'] === 404) {
            return ['ok' => false, 'message' => 'Nextcloud-App intranet_integration ist nicht aktiv oder veraltet (docker compose restart nextcloud).'];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Unerwartete Antwort von Nextcloud (HTTP ' . $response['status'] . ').'];
        }

        return [
            'ok' => !empty($data['ok']),
            'message' => Validator::cleanText((string) ($data['message'] ?? (!empty($data['ok']) ? 'An Nextcloud übergeben.' : 'Übergabe fehlgeschlagen.')), 300),
        ];
    }

    /**
     * @param array{ok:bool,message:string} $result
     */
    private function rememberPush(array $result): void
    {
        try {
            $this->settings->update([self::LAST_PUSH_SETTING => (string) json_encode([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'at' => date('Y-m-d H:i:s'),
                'fingerprint' => $result['ok'] ? $this->fingerprint() : '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable) {
            // Nur informativ; die Gesundheitspruefung gleicht ohnehin erneut ab.
        }
    }

    /**
     * Aktive Benutzer plus individuelle Kontingente nicht mehr aktiver Konten.
     *
     * @return list<QuotaUser>
     */
    private function withInactiveOverrides(): array
    {
        $users = $this->users();
        $known = [];
        foreach ($users as $user) {
            $known[strtolower($user['uid'])] = true;
        }
        foreach ($this->repository->overrides() as $override) {
            if (isset($known[strtolower($override['user_uid'])])) {
                continue;
            }
            $users[] = [
                'id' => 0,
                'uid' => $override['user_uid'],
                'display_name' => $override['display_name'] !== '' ? $override['display_name'] : $override['user_uid'],
                'department' => '',
                'source_label' => '',
                'groups' => [],
                'group_mb' => null,
                'group_name' => '',
                'override' => $override,
                'base_mb' => $this->defaultMb(),
                'effective_mb' => $override['quota_mb'],
                'origin' => 'override',
                'active' => false,
            ];
        }

        return $users;
    }

    /**
     * @param list<QuotaUser> $users
     *
     * @return list<QuotaUser>
     */
    private function sorted(array $users): array
    {
        usort($users, static fn (array $a, array $b): int => [$b['effective_mb'], mb_strtolower($a['display_name'])] <=> [$a['effective_mb'], mb_strtolower($b['display_name'])]);

        return $users;
    }

    private static function sizeError(): string
    {
        return 'Bitte eine Größe zwischen 1 MB und 10 TB angeben, z. B. „500 MB“, „2 GB“ oder „1,5 GB“.';
    }
}
