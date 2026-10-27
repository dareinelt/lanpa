<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use App\Support\Secret;
use App\Support\Validator;

/**
 * Konfiguration des Versandkontos für ausgehende Help-Desk-E-Mails. Gepflegt wird es vollständig in
 * der Anwendung („Auswertung & System → Administration → E-Mail-Versand“); die Umgebungsvariablen
 * aus `config/helpdesk.php` dienen nur als Startwerte.
 *
 * Ist kein Konto aktiv, versendet der Help Desk weiterhin über den Mail-Container
 * (`mail.service_url`), damit bestehende Installationen unverändert laufen. Das Kontopasswort wird
 * verschlüsselt abgelegt (siehe Support\Secret) und nie an die Oberfläche zurückgegeben.
 */
final class OutboundMailSettingsService
{
    /** Präfix aller Schlüssel in system_settings. */
    public const PREFIX = 'helpdesk.outbound_mail.';

    public const ENCRYPTIONS = ['ssl' => 'SSL/TLS (Port 465)', 'starttls' => 'STARTTLS (Port 587)', 'none' => 'Unverschlüsselt (nur internes Relay)'];

    /** Felder, die als Wahrheitswert gespeichert werden. */
    private const BOOLEANS = ['enabled', 'verify_peer'];

    /** Felder, die als Ganzzahl gespeichert werden. */
    private const INTEGERS = ['port', 'timeout'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Secret $secret,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
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
            $result[$key] = match (true) {
                $value === null => $default,
                in_array($key, self::BOOLEANS, true) => $value === '1' || strtolower($value) === 'true',
                in_array($key, self::INTEGERS, true) => $value === '' ? (int) $default : (int) $value,
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

    /** Server und Absender sind hinterlegt (Grundlage für Verbindungstest und Testnachricht). */
    public function configured(): bool
    {
        return trim((string) $this->get('host', '')) !== '' && trim((string) $this->get('from_address', '')) !== '';
    }

    /** Der Help Desk versendet über dieses Konto statt über den Mail-Container. */
    public function enabled(): bool
    {
        return (bool) $this->get('enabled', false) && $this->configured();
    }

    public function fromAddress(): string
    {
        return trim((string) $this->get('from_address', ''));
    }

    public function fromName(): string
    {
        return trim((string) $this->get('from_name', ''));
    }

    /** Antwortadresse („Antwort-an“); leer = Antworten gehen an die Absenderadresse. */
    public function replyTo(): string
    {
        return trim((string) $this->get('reply_to', ''));
    }

    /** Kontopasswort im Klartext (leer, wenn keines hinterlegt oder nicht entschlüsselbar). */
    public function password(): string
    {
        $stored = $this->settings->withPrefix(self::PREFIX)['password'] ?? null;
        if ($stored === null || $stored === '') {
            return (string) $this->config->get('helpdesk.outbound_mail.password', '');
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
     * Optionen für den SMTP-Versand (inkl. Passwort).
     * @return array<string,mixed>
     */
    public function mailerOptions(): array
    {
        $all = $this->all();
        unset($all['password_set'], $all['password_unreadable']);
        $all['password'] = $this->password();

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
        $this->audit->log('update', 'settings', null, 'Help-Desk-Versandkonto', $this->auditView($before), $this->auditView($after));

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
            ->string('host', 'SMTP-Server', false, 190)
            ->int('port', 'Port', false, 1, 65535)
            ->in('encryption', 'Verschlüsselung', array_keys(self::ENCRYPTIONS), true)
            ->string('username', 'Benutzername', false, 190)
            ->email('from_address', 'Absenderadresse')
            ->string('from_name', 'Absendername', false, 190)
            ->email('reply_to', 'Antwort-an-Adresse')
            ->int('timeout', 'Zeitüberschreitung', false, 3, 120)
            ->bool('verify_peer');

        $enabled = (bool) $v->value('enabled');
        if ($enabled && (string) ($v->value('host') ?? '') === '') {
            $v->addError('host', 'Server ist erforderlich.');
        }
        if ($enabled && (string) ($v->value('from_address') ?? '') === '') {
            $v->addError('from_address', 'Absenderadresse ist erforderlich.');
        }
        $username = (string) ($v->value('username') ?? '');
        if ($username !== '') {
            if ((string) ($v->value('encryption') ?? '') === 'none') {
                $v->addError('encryption', 'Die Anmeldung am Server benötigt TLS. Ohne Verschlüsselung sind nur interne Relays ohne Anmeldung zulässig.');
            }
            $passwordSet = trim((string) ($input['password'] ?? '')) !== '' || (!empty($current['password_set']) && empty($input['remove_password']));
            if (!$passwordSet) {
                $v->addError('password', 'Passwort ist erforderlich.');
            }
        }

        $data = $v->validated();
        $defaults = $this->defaults();
        $clean = [];
        foreach ($defaults as $key => $default) {
            $value = $data[$key] ?? null;
            $clean[$key] = match (true) {
                in_array($key, self::BOOLEANS, true) => (bool) $value,
                in_array($key, self::INTEGERS, true) => $value === null ? (int) $default : (int) $value,
                default => $value === null ? '' : (string) $value,
            };
        }
        // Ohne Portangabe gilt der Standardport der gewählten Verschlüsselung.
        $port = $data['port'] ?? null;
        $clean['port'] = $port === null || (int) $port <= 0
            ? ($clean['encryption'] === 'ssl' ? 465 : 587)
            : (int) $port;

        return $clean;
    }

    // ------------------------------------------------------------------ Intern

    /** Startwerte aus der Umgebung (config/helpdesk.php). @return array<string,mixed> */
    private function defaults(): array
    {
        $env = (array) $this->config->get('helpdesk.outbound_mail', []);

        return [
            'enabled' => (bool) ($env['enabled'] ?? false),
            'host' => (string) ($env['host'] ?? ''),
            'port' => (int) ($env['port'] ?? 587),
            'encryption' => (string) ($env['encryption'] ?? 'starttls'),
            'username' => (string) ($env['username'] ?? ''),
            'from_address' => (string) ($env['from_address'] ?? ''),
            'from_name' => (string) ($env['from_name'] ?? ''),
            'reply_to' => (string) ($env['reply_to'] ?? ''),
            'timeout' => (int) ($env['timeout'] ?? 15),
            'verify_peer' => (bool) ($env['verify_peer'] ?? true),
        ];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> Werte für das Audit-Log (ohne Geheimnisse) */
    private function auditView(array $values): array
    {
        unset($values['password_unreadable']);
        $values['password_set'] = !empty($values['password_set']) ? 'ja' : 'nein';

        return array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? 'ja' : 'nein') : (string) $v, $values);
    }
}
