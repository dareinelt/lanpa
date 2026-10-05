<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Exceptions\ValidationException;
use App\Repositories\OrvantaRepository;
use App\Security\SecretBox;
use App\Support\Validator;

/**
 * Einstellungen von Orvanta (Tabelle orvanta_settings): Exchange-Server,
 * EWS-Endpunkt, Anmeldeverfahren des Dienstkontos (Kennwort verschluesselt),
 * Zuordnung der SSO-Identitaet (Impersonation), Zwischenspeicher-Quota und
 * Standardordner. Gepflegt im Adminbereich unter Office -> Orvanta.
 */
final class OrvantaConfigService
{
    public const AUTH_MODES = ['negotiate' => 'Negotiate / Windows-Anmeldung (Dienstkonto, Aushandlung per NTLM)', 'ntlm' => 'NTLM (Dienstkonto)', 'basic' => 'Basic (Dienstkonto, nur über HTTPS)'];
    public const IDENTITY_MODES = ['smtp' => 'E-Mail-Adresse aus dem Active Directory', 'upn' => 'Benutzerprinzipalname (Benutzer@Domäne)'];
    public const VERSIONS = ['Exchange2016' => 'Exchange 2016 / 2019 / Subscription Edition', 'Exchange2013_SP1' => 'Exchange 2013 SP1'];
    public const DEFAULT_FOLDERS = ['inbox' => 'Posteingang', 'calendar' => 'Kalender', 'contacts' => 'Kontakte', 'tasks' => 'Aufgaben', 'notes' => 'Notizen'];
    public const ARCHIVE_THRESHOLD_UNITS = ['percent' => 'Prozent der Postfachgrenze', 'mb' => 'Megabyte (absolut)'];

    public const DEFAULTS = [
        'exchange_enabled' => '0',
        'exchange_host' => '',
        'exchange_ews_url' => '',
        'exchange_version' => 'Exchange2016',
        'exchange_auth' => 'negotiate',
        'exchange_service_user' => '',
        'exchange_service_password' => '',
        'exchange_identity' => 'smtp',
        'exchange_upn_domain' => '',
        'exchange_verify_tls' => '1',
        'exchange_timeout' => '20',
        'exchange_owa_url' => '',
        'cache_quota_mb' => '250',
        'mailbox_quota_mb' => '0',
        'cache_folder' => 'Orvanta',
        'reminder_lead_minutes' => '15',
        'reminder_header' => '1',
        'default_folder' => 'inbox',
        'poll_interval' => '60',
        'archive_enabled' => '0',
        'archive_threshold' => '80',
        'archive_threshold_unit' => 'percent',
        'archive_age_days' => '60',
        'archive_folder' => 'Orvanta-Archiv',
        'archive_compression' => 'gzip',
        'archive_batch_size' => '50',
        'archive_poll_interval' => '3600',
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly OrvantaRepository $repository,
        private readonly SecretBox $secrets
    ) {
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = array_merge(self::DEFAULTS, $this->repository->settings());
            } catch (\PDOException) {
                // Vor der Migration: nur Standardwerte.
                $this->cache = self::DEFAULTS;
            }
        }

        return $this->cache;
    }

    public function get(string $key): string
    {
        return $this->all()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public function isEnabled(): bool
    {
        return $this->get('exchange_enabled') === '1' && $this->ewsUrl() !== '';
    }

    /**
     * Demomodus (Beispieldaten ohne Exchange): Server „demo“, nie im Produktionsmodus.
     */
    public function isDemo(): bool
    {
        return strtolower(trim($this->get('exchange_host'))) === DemoExchangeTransport::HOST
            && (string) \App\Core\Config::get('app.env', 'production') !== 'production';
    }

    public function ewsUrl(): string
    {
        $url = trim($this->get('exchange_ews_url'));
        if ($url !== '') {
            return $url;
        }
        $host = trim($this->get('exchange_host'));

        return $host === '' ? '' : 'https://' . $host . '/EWS/Exchange.asmx';
    }

    public function owaUrl(): string
    {
        return trim($this->get('exchange_owa_url'));
    }

    public function servicePassword(): string
    {
        return $this->secrets->decrypt($this->get('exchange_service_password')) ?? '';
    }

    public function hasServicePassword(): bool
    {
        return $this->get('exchange_service_password') !== '';
    }

    public function cacheQuotaBytes(): int
    {
        return max(0, (int) $this->get('cache_quota_mb')) * 1024 * 1024;
    }

    /**
     * Postfachgroesse fuer die Anzeige der Belegung, wenn Exchange keine
     * Grenze liefert (0 = ohne Grenze).
     */
    public function mailboxQuotaBytes(): int
    {
        return max(0, (int) $this->get('mailbox_quota_mb')) * 1024 * 1024;
    }

    public function cacheFolder(): string
    {
        $folder = trim($this->get('cache_folder'));

        return $folder === '' ? 'Orvanta' : $folder;
    }

    /** Terminerinnerungen zusaetzlich in den Kopfzeilen-Mitteilungen des Intranets anzeigen. */
    public function reminderHeaderEnabled(): bool
    {
        return $this->get('reminder_header') === '1';
    }

    public function reminderLeadMinutes(): int
    {
        return max(0, min(1440, (int) $this->get('reminder_lead_minutes')));
    }

    public function pollInterval(): int
    {
        return max(15, min(900, (int) $this->get('poll_interval')));
    }

    // ------------------------------------------------------------------ Langzeitarchiv

    /** Richtliniengesteuerte Archivierung aktiv (zusaetzlich zur Exchange-Anbindung). */
    public function archiveEnabled(): bool
    {
        return $this->isEnabled() && $this->get('archive_enabled') === '1';
    }

    /** Mindestalter archivierungsfaehiger Nachrichten in Tagen (Standard 60). */
    public function archiveAgeDays(): int
    {
        return max(1, min(3650, (int) $this->get('archive_age_days')));
    }

    /** Nachrichten je Verarbeitungsschritt (Batch). */
    public function archiveBatchSize(): int
    {
        return max(1, min(200, (int) $this->get('archive_batch_size')));
    }

    /** Pruefintervall des Archivierungs-Workers in Sekunden. */
    public function archivePollInterval(): int
    {
        return max(60, min(86400, (int) $this->get('archive_poll_interval')));
    }

    /** Nextcloud-Ordner des Langzeitarchivs (ein Pfadsegment je Benutzer). */
    public function archiveFolder(): string
    {
        $folder = trim($this->get('archive_folder'));

        return $folder === '' ? 'Orvanta-Archiv' : $folder;
    }

    /**
     * Archivierungsschwelle in Byte fuer die gegebene Postfachgrenze
     * ('percent') bzw. absolut ('mb'). 0 = Schwelle nicht bestimmbar
     * (prozentuale Schwelle ohne bekannte Postfachgrenze).
     */
    public function archiveThresholdBytes(int $mailboxLimit): int
    {
        $threshold = max(0, (int) $this->get('archive_threshold'));
        if ($this->get('archive_threshold_unit') === 'mb') {
            return $threshold * 1024 * 1024;
        }

        return $mailboxLimit > 0 ? (int) ($mailboxLimit * min(100, $threshold) / 100) : 0;
    }

    /**
     * Optionen fuer den Transport (Dienstkonto, Zeitlimit, TLS-Pruefung).
     *
     * @return array{auth:string,username:string,password:string,timeout:int,verify_tls:bool}
     */
    public function transportOptions(): array
    {
        return [
            'auth' => $this->get('exchange_auth'),
            'username' => trim($this->get('exchange_service_user')),
            'password' => $this->servicePassword(),
            'timeout' => max(3, min(120, (int) $this->get('exchange_timeout'))),
            'verify_tls' => $this->get('exchange_verify_tls') === '1',
        ];
    }

    /**
     * Adresse, mit der Exchange den angemeldeten Benutzer identifiziert
     * (Impersonation): SMTP-Adresse aus dem AD oder UPN.
     *
     * @param array{username:string,email?:string} $ssoUser
     */
    public function impersonationAddress(array $ssoUser): string
    {
        $email = trim((string) ($ssoUser['email'] ?? ''));
        if ($this->get('exchange_identity') === 'upn') {
            $domain = trim($this->get('exchange_upn_domain'));
            if ($domain !== '') {
                return $ssoUser['username'] . '@' . ltrim($domain, '@');
            }
        }

        return $email;
    }

    /**
     * Werte fuer das Formular (ohne Kennwort).
     *
     * @return array<string,string>
     */
    public function formValues(): array
    {
        $values = $this->all();
        $values['exchange_service_password'] = '';

        return $values;
    }

    /**
     * Validiert die Eingaben des Adminformulars und speichert sie. Ein leeres
     * Kennwortfeld laesst das gespeicherte Kennwort unveraendert.
     *
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $errors = [];
        $values = [];
        $text = static fn (string $key, int $max = 255): string => Validator::cleanText(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '', $max);

        $values['exchange_enabled'] = !empty($input['exchange_enabled']) ? '1' : '0';
        $host = $text('exchange_host');
        if ($host !== '' && $host !== DemoExchangeTransport::HOST && !Validator::isHostname($host)) {
            $errors['exchange_host'] = 'Bitte einen gültigen Hostnamen angeben (z. B. mail.example.com).';
        }
        $values['exchange_host'] = $host;

        $ews = $text('exchange_ews_url', 2048);
        if ($ews !== '' && !self::isHttpsUrl($ews)) {
            $errors['exchange_ews_url'] = 'Der EWS-Endpunkt muss eine vollständige http(s)-Adresse sein (z. B. https://mail.example.com/EWS/Exchange.asmx).';
        }
        $values['exchange_ews_url'] = $ews;
        if ($values['exchange_enabled'] === '1' && $host === '' && $ews === '') {
            $errors['exchange_host'] = 'Für die Aktivierung wird ein Exchange-Server oder ein EWS-Endpunkt benötigt.';
        }

        $version = $text('exchange_version', 40);
        $values['exchange_version'] = isset(self::VERSIONS[$version]) ? $version : 'Exchange2016';
        $auth = $text('exchange_auth', 20);
        if (!isset(self::AUTH_MODES[$auth])) {
            $errors['exchange_auth'] = 'Ungültiges Anmeldeverfahren.';
            $auth = 'negotiate';
        }
        $values['exchange_auth'] = $auth;
        $values['exchange_service_user'] = $text('exchange_service_user', 190);
        if ($auth === 'basic' && $ews !== '' && !str_starts_with(strtolower($ews), 'https://')) {
            $errors['exchange_auth'] = 'Basic-Authentifizierung ist nur über HTTPS zulässig.';
        }

        $password = is_scalar($input['exchange_service_password'] ?? null) ? (string) $input['exchange_service_password'] : '';
        if (!empty($input['exchange_service_password_clear'])) {
            $values['exchange_service_password'] = '';
        } elseif ($password !== '') {
            if (strlen($password) > 500) {
                $errors['exchange_service_password'] = 'Das Kennwort ist zu lang.';
            } else {
                $values['exchange_service_password'] = $this->secrets->encrypt($password);
            }
        }

        // Der Intranet-Server besitzt keine eigene Kerberos-Identitaet: ohne
        // Dienstkonto und Kennwort lehnt Exchange jede Anfrage mit 401 ab.
        if ($values['exchange_enabled'] === '1' && strtolower($host) !== DemoExchangeTransport::HOST) {
            if ($values['exchange_service_user'] === '') {
                $errors['exchange_service_user'] = 'Bitte ein Dienstkonto mit der Rolle ApplicationImpersonation angeben (z. B. FIRMA\svc-orvanta).';
            }
            $hasPassword = isset($values['exchange_service_password'])
                ? $values['exchange_service_password'] !== ''
                : $this->hasServicePassword();
            if (!$hasPassword && !isset($errors['exchange_service_password'])) {
                $errors['exchange_service_password'] = 'Bitte das Kennwort des Dienstkontos angeben.';
            }
        }

        $identity = $text('exchange_identity', 10);
        $values['exchange_identity'] = isset(self::IDENTITY_MODES[$identity]) ? $identity : 'smtp';
        $domain = ltrim($text('exchange_upn_domain', 190), '@');
        if ($domain !== '' && !Validator::isHostname($domain)) {
            $errors['exchange_upn_domain'] = 'Bitte eine gültige Domäne angeben (z. B. firma.local).';
        }
        $values['exchange_upn_domain'] = $domain;
        if ($values['exchange_identity'] === 'upn' && $domain === '') {
            $errors['exchange_upn_domain'] = 'Für den Benutzerprinzipalnamen wird die UPN-Domäne benötigt.';
        }

        $values['exchange_verify_tls'] = !empty($input['exchange_verify_tls']) ? '1' : '0';
        $timeout = (int) $text('exchange_timeout', 5);
        if ($timeout < 3 || $timeout > 120) {
            $errors['exchange_timeout'] = 'Das Zeitlimit muss zwischen 3 und 120 Sekunden liegen.';
        }
        $values['exchange_timeout'] = (string) $timeout;

        $owa = $text('exchange_owa_url', 2048);
        if ($owa !== '' && !self::isHttpsUrl($owa)) {
            $errors['exchange_owa_url'] = 'Bitte eine vollständige http(s)-Adresse angeben.';
        }
        $values['exchange_owa_url'] = $owa;

        $quota = (int) $text('cache_quota_mb', 8);
        if ($quota < 0 || $quota > 1048576) {
            $errors['cache_quota_mb'] = 'Das Quota muss zwischen 0 (deaktiviert) und 1.048.576 MB liegen.';
        }
        $values['cache_quota_mb'] = (string) $quota;

        $mailboxQuota = (int) $text('mailbox_quota_mb', 8);
        if ($mailboxQuota < 0 || $mailboxQuota > 10485760) {
            $errors['mailbox_quota_mb'] = 'Die Postfachgröße muss zwischen 0 (ohne Grenze) und 10.485.760 MB liegen.';
        }
        $values['mailbox_quota_mb'] = (string) $mailboxQuota;

        $folder = $text('cache_folder', 120);
        if (!\App\Services\Office\NextcloudFilesService::isSafeSegment($folder)) {
            $errors['cache_folder'] = 'Der Ordnername darf keine Sonderzeichen wie / \\ : * ? " < > | enthalten.';
        }
        $values['cache_folder'] = $folder;

        $lead = (int) $text('reminder_lead_minutes', 5);
        if ($lead < 0 || $lead > 1440) {
            $errors['reminder_lead_minutes'] = 'Die Vorlaufzeit muss zwischen 0 und 1440 Minuten liegen.';
        }
        $values['reminder_lead_minutes'] = (string) $lead;
        $values['reminder_header'] = !empty($input['reminder_header']) ? '1' : '0';

        $poll = (int) $text('poll_interval', 5);
        if ($poll < 15 || $poll > 900) {
            $errors['poll_interval'] = 'Das Abfrageintervall muss zwischen 15 und 900 Sekunden liegen.';
        }
        $values['poll_interval'] = (string) $poll;

        $default = $text('default_folder', 20);
        $values['default_folder'] = isset(self::DEFAULT_FOLDERS[$default]) ? $default : 'inbox';

        $values['archive_enabled'] = !empty($input['archive_enabled']) ? '1' : '0';
        $archiveUnit = $text('archive_threshold_unit', 10);
        if (!isset(self::ARCHIVE_THRESHOLD_UNITS[$archiveUnit])) {
            $archiveUnit = 'percent';
        }
        $values['archive_threshold_unit'] = $archiveUnit;
        $archiveThreshold = (int) $text('archive_threshold', 8);
        if ($archiveUnit === 'percent' && ($archiveThreshold < 1 || $archiveThreshold > 100)) {
            $errors['archive_threshold'] = 'Die Schwelle muss zwischen 1 und 100 Prozent liegen.';
        } elseif ($archiveUnit === 'mb' && ($archiveThreshold < 1 || $archiveThreshold > 10485760)) {
            $errors['archive_threshold'] = 'Die Schwelle muss zwischen 1 und 10.485.760 MB liegen.';
        }
        $values['archive_threshold'] = (string) $archiveThreshold;

        $archiveAge = (int) $text('archive_age_days', 5);
        if ($archiveAge < 1 || $archiveAge > 3650) {
            $errors['archive_age_days'] = 'Das Mindestalter muss zwischen 1 und 3650 Tagen liegen.';
        }
        $values['archive_age_days'] = (string) $archiveAge;

        $archiveFolder = $text('archive_folder', 120);
        if ($archiveFolder === '' || !\App\Services\Office\NextcloudFilesService::isSafeSegment($archiveFolder)) {
            $errors['archive_folder'] = 'Der Ordnername darf nicht leer sein und keine Sonderzeichen wie / \\ : * ? " < > | enthalten.';
        }
        $values['archive_folder'] = $archiveFolder;

        // Format-Invariante: aktuell ausschliesslich gzip (format_version 1).
        $values['archive_compression'] = 'gzip';

        $archiveBatch = (int) $text('archive_batch_size', 4);
        if ($archiveBatch < 1 || $archiveBatch > 200) {
            $errors['archive_batch_size'] = 'Die Batchgröße muss zwischen 1 und 200 Nachrichten liegen.';
        }
        $values['archive_batch_size'] = (string) $archiveBatch;

        $archivePoll = (int) $text('archive_poll_interval', 6);
        if ($archivePoll < 60 || $archivePoll > 86400) {
            $errors['archive_poll_interval'] = 'Das Prüfintervall muss zwischen 60 und 86.400 Sekunden liegen.';
        }
        $values['archive_poll_interval'] = (string) $archivePoll;

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->repository->saveSettings($values);
        $this->cache = null;
    }

    public static function isHttpsUrl(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
