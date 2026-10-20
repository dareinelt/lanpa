<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use App\Support\Secret;
use App\Support\Validator;

/**
 * Konfiguration des Help-Desk-Postfachs. Alle Werte werden in `system_settings` gepflegt und sind
 * vollständig über „Auswertung & System → Administration → E-Mail-Postfach“ einstellbar; die
 * Umgebungsvariablen aus `config/helpdesk.php` dienen nur noch als Startwerte (Erstinbetriebnahme).
 *
 * Das Postfachpasswort wird verschlüsselt abgelegt (siehe Support\Secret) und nie an die Oberfläche
 * zurückgegeben – dort ist lediglich sichtbar, ob ein Passwort hinterlegt ist.
 */
final class MailboxSettingsService
{
    /** Präfix aller Schlüssel in system_settings. */
    public const PREFIX = 'helpdesk.mail.';

    public const DRIVERS = ['imap' => 'IMAP-Postfach', 'file' => 'Verzeichnis mit .eml-Dateien'];
    public const ENCRYPTIONS = ['ssl' => 'SSL/TLS (Port 993)', 'starttls' => 'STARTTLS (Port 143)', 'none' => 'Unverschlüsselt (nur Testbetrieb)'];

    /** Felder, die als Wahrheitswert gespeichert werden. */
    private const BOOLEANS = ['enabled', 'verify_peer', 'move_processed', 'allow_unknown_senders'];

    /** Felder, die als Ganzzahl gespeichert werden. */
    private const INTEGERS = ['port', 'timeout', 'batch_size', 'interval_minutes'];

    /** Laufzeitwerte (kein Bestandteil des Formulars). */
    public const KEY_LAST_RUN = self::PREFIX . 'last_run_at';
    public const KEY_LAST_RESULT = self::PREFIX . 'last_result';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Secret $secret,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly Permissions $permissions,
        private readonly TicketMasterDataRepository $masterData
    ) {}

    /**
     * Effektive Konfiguration (Datenbank vor Umgebung), ohne Passwort.
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);
        $result = [];
        foreach ($this->defaults() as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($value === null || ($value === '' && !is_string($default))) {
                $result[$key] = $default;
                continue;
            }
            $result[$key] = match (true) {
                in_array($key, self::BOOLEANS, true) => $value === '1' || strtolower($value) === 'true',
                in_array($key, self::INTEGERS, true) => (int) $value,
                default => $value,
            };
        }
        $result['password_set'] = $this->password() !== '';
        $result['password_unreadable'] = $this->passwordUnreadable();

        return $result;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', false);
    }

    /** Postfachpasswort im Klartext (leer, wenn keines hinterlegt oder nicht entschlüsselbar). */
    public function password(): string
    {
        $stored = $this->settings->withPrefix(self::PREFIX)['password'] ?? null;
        if ($stored === null || $stored === '') {
            return (string) $this->config->get('helpdesk.mail.password', '');
        }

        return $this->secret->decrypt($stored) ?? '';
    }

    /** Ein gespeichertes Passwort ist vorhanden, lässt sich aber nicht entschlüsseln (APP_KEY geändert). */
    public function passwordUnreadable(): bool
    {
        $stored = $this->settings->withPrefix(self::PREFIX)['password'] ?? '';

        return $stored !== '' && $this->secret->decrypt($stored) === null;
    }

    /**
     * Optionen für den Postfach-Client (inkl. Passwort).
     * @return array<string,mixed>
     */
    public function mailboxOptions(): array
    {
        $all = $this->all();
        unset($all['password_set'], $all['password_unreadable']);
        $all['password'] = $this->password();
        // Der IMAP-Client verschiebt nur, wenn ein Zielordner gesetzt ist
        if (!$all['move_processed']) {
            $all['processed_mailbox'] = '';
        }

        return $all;
    }

    /**
     * Konfiguration aus dem Formular speichern. Ein leeres Passwortfeld lässt das gespeicherte Passwort unverändert.
     * @param array<string,mixed> $input
     * @return array<string,mixed> Neue effektive Konfiguration
     */
    public function save(array $input): array
    {
        $this->currentUser->require('settings.manage');
        $before = $this->all();
        $data = $this->validate($input, $before);

        $values = [];
        foreach ($data as $key => $value) {
            $values[self::PREFIX . $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $password = trim((string) ($input['password'] ?? ''));
        if (!empty($input['remove_password'])) {
            $values[self::PREFIX . 'password'] = '';
        } elseif ($password !== '') {
            $values[self::PREFIX . 'password'] = $this->secret->encrypt($password);
        }
        $this->settings->setMany($values, $this->currentUser->id());

        $after = $this->all();
        $this->audit->log('update', 'settings', null, 'Help-Desk-Postfach', $this->auditView($before), $this->auditView($after));

        return $after;
    }

    /**
     * Eingaben prüfen und in typisierte Werte überführen.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public function validate(array $input, array $current): array
    {
        $v = new Validator($input);
        $v->bool('enabled')
            ->in('driver', 'Postfachtyp', array_keys(self::DRIVERS), true)
            ->string('host', 'Server', false, 190)
            ->int('port', 'Port', false, 1, 65535)
            ->in('encryption', 'Verschlüsselung', array_keys(self::ENCRYPTIONS), true)
            ->string('username', 'Benutzername', false, 190)
            ->string('mailbox', 'Postfachverzeichnis', false, 190)
            ->bool('move_processed')
            ->string('processed_mailbox', 'Zielverzeichnis', false, 190)
            ->string('file_path', 'Verzeichnis', false, 255)
            ->int('timeout', 'Zeitüberschreitung', true, 3, 120)
            ->bool('verify_peer')
            ->int('batch_size', 'Nachrichten je Lauf', true, 1, 500)
            ->int('interval_minutes', 'Abrufintervall', true, 0, 1440)
            ->string('system_user', 'Systembenutzer', true, 190)
            ->bool('allow_unknown_senders')
            ->string('default_type', 'Standard-Tickettyp', true, 60);

        $enabled = (bool) $v->value('enabled');
        $driver = (string) ($v->value('driver') ?? 'imap');
        if ($enabled && $driver === 'imap') {
            if (($v->value('host') ?? '') === '') {
                $v->addError('host', 'Server ist erforderlich.');
            }
            if (($v->value('username') ?? '') === '') {
                $v->addError('username', 'Benutzername ist erforderlich.');
            }
            $passwordSet = trim((string) ($input['password'] ?? '')) !== '' || (!empty($current['password_set']) && empty($input['remove_password']));
            if (!$passwordSet) {
                $v->addError('password', 'Passwort ist erforderlich.');
            }
        }
        if ($enabled && $driver === 'file' && ($v->value('file_path') ?? '') === '') {
            $v->addError('file_path', 'Verzeichnis ist erforderlich.');
        }
        if ((bool) $v->value('move_processed') && $driver === 'imap' && ($v->value('processed_mailbox') ?? '') === '') {
            $v->addError('processed_mailbox', 'Zielverzeichnis ist erforderlich, wenn verarbeitete E-Mails verschoben werden sollen.');
        }
        $mailbox = (string) ($v->value('mailbox') ?? '');
        if ((bool) $v->value('move_processed') && $mailbox !== '' && $mailbox === (string) ($v->value('processed_mailbox') ?? '')) {
            $v->addError('processed_mailbox', 'Zielverzeichnis und Quellverzeichnis dürfen nicht identisch sein.');
        }
        $systemUser = (string) ($v->value('system_user') ?? '');
        if ($systemUser !== '' && !$this->isValidSystemUser($systemUser)) {
            $v->addError('system_user', 'Der Benutzer existiert nicht, ist inaktiv oder besitzt das Recht „helpdesk.create“ nicht.');
        }
        $type = (string) ($v->value('default_type') ?? '');
        if ($type !== '' && $this->masterData->typeByCode($type) === null) {
            $v->addError('default_type', 'Der Tickettyp ist unbekannt.');
        }

        $data = $v->validated();
        $defaults = $this->defaults();
        $clean = [];
        foreach ($defaults as $key => $default) {
            $value = $data[$key] ?? null;
            $clean[$key] = match (true) {
                in_array($key, self::BOOLEANS, true) => (bool) $value,
                in_array($key, self::INTEGERS, true) => $value === null ? $default : (int) $value,
                default => $value === null ? '' : (string) $value,
            };
        }
        $clean['mailbox'] = $clean['mailbox'] !== '' ? $clean['mailbox'] : 'INBOX';
        $clean['port'] = $clean['port'] > 0 ? $clean['port'] : ($clean['encryption'] === 'ssl' ? 993 : 143);
        if (!$clean['move_processed']) {
            $clean['processed_mailbox'] = '';
        }

        return $clean;
    }

    // ------------------------------------------------------------------ Laufzeit

    /** Ist ein Abruf laut Intervall fällig? */
    public function due(): bool
    {
        $interval = (int) $this->get('interval_minutes', 0);
        if (!$this->enabled() || $interval <= 0) {
            return false;
        }
        $last = $this->lastRunAt();
        if ($last === null) {
            return true;
        }

        return time() - $last >= $interval * 60;
    }

    /** Zeitpunkt des letzten Abrufs als UTC-Zeitstempel („Y-m-d H:i:s“). */
    public function lastRun(): ?string
    {
        return $this->settings->get(self::KEY_LAST_RUN);
    }

    /** @return array<string,mixed>|null Zusammenfassung des letzten Laufs */
    public function lastResult(): ?array
    {
        $raw = $this->settings->get(self::KEY_LAST_RESULT);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $result */
    public function recordRun(array $result): void
    {
        unset($result['items']);
        $this->settings->setMany([
            self::KEY_LAST_RUN => gmdate('Y-m-d H:i:s'),
            self::KEY_LAST_RESULT => (string) json_encode($result, JSON_UNESCAPED_UNICODE),
        ], $this->currentUser->id());
    }

    public function recordError(string $message): void
    {
        $this->settings->setMany([
            self::KEY_LAST_RUN => gmdate('Y-m-d H:i:s'),
            self::KEY_LAST_RESULT => (string) json_encode(['error' => mb_substr($message, 0, 500)], JSON_UNESCAPED_UNICODE),
        ], $this->currentUser->id());
    }

    /** Benutzerkonten, die als Systembenutzer des E-Mail-Eingangs in Frage kommen. @return list<array<string,mixed>> */
    public function systemUserCandidates(): array
    {
        $candidates = [];
        foreach ($this->users->all() as $user) {
            if (!empty($user['is_active']) && $this->permissions->hasEffective((string) $user['role'], (array) ($user['groups'] ?? []), 'helpdesk.create')) {
                $candidates[] = $user;
            }
        }

        return $candidates;
    }

    // ------------------------------------------------------------------ Intern

    /** Startwerte aus der Umgebung (config/helpdesk.php). @return array<string,mixed> */
    private function defaults(): array
    {
        $env = (array) $this->config->get('helpdesk.mail', []);
        $processed = trim((string) ($env['processed_mailbox'] ?? ''));

        return [
            'enabled' => (bool) ($env['enabled'] ?? false),
            'driver' => (string) ($env['driver'] ?? 'imap'),
            'host' => (string) ($env['host'] ?? ''),
            'port' => (int) ($env['port'] ?? 993),
            'encryption' => (string) ($env['encryption'] ?? 'ssl'),
            'username' => (string) ($env['username'] ?? ''),
            'mailbox' => (string) ($env['mailbox'] ?? 'INBOX'),
            'move_processed' => $processed !== '',
            'processed_mailbox' => $processed,
            'file_path' => (string) ($env['file_path'] ?? 'storage/mail-inbox'),
            'timeout' => (int) ($env['timeout'] ?? 15),
            'verify_peer' => (bool) ($env['verify_peer'] ?? true),
            'batch_size' => (int) ($env['batch_size'] ?? 50),
            'interval_minutes' => (int) ($env['interval_minutes'] ?? 0),
            'system_user' => (string) ($env['system_user'] ?? 'admin'),
            'allow_unknown_senders' => (bool) ($env['allow_unknown_senders'] ?? true),
            'default_type' => (string) ($env['default_type'] ?? 'incident'),
        ];
    }

    private function lastRunAt(): ?int
    {
        $last = $this->lastRun();
        if ($last === null || $last === '') {
            return null;
        }
        $time = strtotime($last . ' UTC');

        return $time === false ? null : $time;
    }

    private function isValidSystemUser(string $username): bool
    {
        $user = $this->users->findByUsername($username);

        return $user !== null && !empty($user['is_active']) && $this->permissions->hasEffective((string) $user['role'], (array) ($user['groups'] ?? []), 'helpdesk.create');
    }

    /** @param array<string,mixed> $values @return array<string,mixed> Werte für das Audit-Log (ohne Geheimnisse) */
    private function auditView(array $values): array
    {
        unset($values['password_unreadable']);
        $values['password_set'] = !empty($values['password_set']) ? 'ja' : 'nein';

        return array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? 'ja' : 'nein') : (string) $v, $values);
    }
}
