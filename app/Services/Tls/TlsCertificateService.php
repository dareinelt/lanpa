<?php

declare(strict_types=1);

namespace App\Services\Tls;

use App\Exceptions\ValidationException;
use App\Repositories\TlsCertificateRepository;
use App\Security\SecretBox;
use App\Services\SettingsService;
use App\Support\Validator;
use Closure;
use RuntimeException;

/**
 * HTTPS-Zertifikate des auth-Containers:
 *
 * 1. CSR erstellen (privater Schluessel bleibt verschluesselt in der Anwendung),
 * 2. von der CA ausgestelltes Zertifikat importieren (Vorschau + Bestaetigung,
 *    automatische Zuordnung zum CSR ueber den oeffentlichen Schluessel),
 * 3. Zertifikat aktivieren.
 *
 * Solange kein gueltiges Zertifikat aktiv ist, liefert der auth-Container ein
 * selbstsigniertes Notfall-Zertifikat aus und erlaubt reines HTTP nur aus den
 * hinterlegten Quellnetzen; alle anderen Anfragen werden auf HTTPS
 * umgeleitet. Mit gueltigem aktivem Zertifikat wird jede HTTP-Anfrage auf
 * HTTPS umgeleitet.
 */
final class TlsCertificateService
{
    public const DEFAULT_HTTP_NETWORKS = '192.168.200.0/21';

    public const MODE_STRICT = 'strict';
    public const MODE_FALLBACK = 'fallback';

    public const KIND_CSR = 'csr';
    public const KIND_FALLBACK = 'fallback';

    /** Abrufintervall des auth-Containers (Sekunden) plus Reserve fuer die Anzeige. */
    public const SYNC_STALE_AFTER = 300;

    private const FALLBACK_DAYS = 365;

    /** @var array<string,array{label:string,type:int,bits?:int,curve?:string}> */
    private const KEY_TYPES = [
        'rsa3072' => ['label' => 'RSA 3072 Bit (empfohlen)', 'type' => OPENSSL_KEYTYPE_RSA, 'bits' => 3072],
        'rsa2048' => ['label' => 'RSA 2048 Bit (maximale Kompatibilität)', 'type' => OPENSSL_KEYTYPE_RSA, 'bits' => 2048],
        'rsa4096' => ['label' => 'RSA 4096 Bit', 'type' => OPENSSL_KEYTYPE_RSA, 'bits' => 4096],
        'ec256' => ['label' => 'ECDSA P-256', 'type' => OPENSSL_KEYTYPE_EC, 'curve' => 'prime256v1'],
        'ec384' => ['label' => 'ECDSA P-384', 'type' => OPENSSL_KEYTYPE_EC, 'curve' => 'secp384r1'],
    ];

    private const DN_FIELDS = [
        'organization' => ['O', 'organizationName'],
        'organizational_unit' => ['OU', 'organizationalUnitName'],
        'locality' => ['L', 'localityName'],
        'state' => ['ST', 'stateOrProvinceName'],
        'country' => ['C', 'countryName'],
        'email' => ['emailAddress', 'emailAddress'],
    ];

    private readonly Closure $clock;

    public function __construct(
        private readonly TlsCertificateRepository $repository,
        private readonly SettingsService $settings,
        private readonly SecretBox $secretBox,
        private readonly string $appUrl,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return (int) ($this->clock)();
    }

    /**
     * Hostname aus APP_URL (Vorschlag fuer den CSR, Pruefung beim Import).
     */
    public function appHost(): string
    {
        $host = parse_url($this->appUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower(trim($host, '[]')) : '';
    }

    /**
     * @return array<string,string>
     */
    public static function keyTypes(): array
    {
        return array_map(static fn (array $type): string => $type['label'], self::KEY_TYPES);
    }

    // ------------------------------------------------------------------
    // Quellnetze fuer reines HTTP
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function httpNetworks(): array
    {
        $value = $this->settings->get('tls_http_networks', self::DEFAULT_HTTP_NETWORKS);
        if ($value === 'none') {
            return [];
        }

        try {
            return self::parseNetworks($value);
        } catch (ValidationException) {
            return [self::DEFAULT_HTTP_NETWORKS];
        }
    }

    /**
     * @return list<string>
     */
    public function updateHttpNetworks(string $input): array
    {
        $networks = self::parseNetworks($input);
        $this->settings->update(['tls_http_networks' => $networks === [] ? 'none' : implode("\n", $networks)]);

        return $networks;
    }

    /**
     * Quellnetze in CIDR-Schreibweise (IPv4/IPv6); einzelne Adressen werden
     * zu /32 bzw. /128 ergaenzt.
     *
     * @return list<string>
     */
    public static function parseNetworks(string $input): array
    {
        $networks = [];
        foreach (preg_split('/[\s,;]+/', trim($input)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            $ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $ipv6 = !$ipv4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $max = $ipv4 ? 32 : 128;
            if ((!$ipv4 && !$ipv6) || ($prefix !== null && (preg_match('/^\d{1,3}$/', $prefix) !== 1 || (int) $prefix > $max))) {
                throw new ValidationException(['tls_http_networks' => sprintf('„%s“ ist kein gültiges Netz (Beispiel: 192.168.200.0/21).', $entry)]);
            }

            $network = strtolower((string) $address) . '/' . ($prefix === null ? $max : (int) $prefix);
            if (!in_array($network, $networks, true)) {
                $networks[] = $network;
            }
        }

        if (count($networks) > 32) {
            throw new ValidationException(['tls_http_networks' => 'Es sind höchstens 32 Netze möglich.']);
        }

        return $networks;
    }

    // ------------------------------------------------------------------
    // 1. CSR erstellen
    // ------------------------------------------------------------------

    /**
     * Vorbelegung des CSR-Formulars: Werte des letzten Requests bzw. Hostname
     * aus APP_URL.
     *
     * @return array<string,string>
     */
    public function csrDefaults(): array
    {
        $defaults = [
            'common_name' => $this->appHost(),
            'san' => $this->appHost(),
            'organization' => '',
            'organizational_unit' => '',
            'locality' => '',
            'state' => '',
            'country' => 'DE',
            'email' => '',
            'key_type' => 'rsa3072',
        ];

        foreach ($this->repository->all() as $row) {
            if ((string) $row['kind'] !== self::KIND_CSR) {
                continue;
            }
            $subject = json_decode((string) ($row['subject'] ?? ''), true);
            $defaults['common_name'] = (string) $row['common_name'];
            $defaults['san'] = (string) ($row['san'] ?? '');
            foreach (array_keys(self::DN_FIELDS) as $field) {
                $defaults[$field] = is_array($subject) ? (string) ($subject[$field] ?? '') : '';
            }
            break;
        }

        return $defaults;
    }

    /**
     * @param array<string,string> $input
     */
    public function createRequest(array $input, string $user): int
    {
        $errors = [];

        $commonName = strtolower(trim((string) ($input['common_name'] ?? '')));
        if (!self::isCertificateName($commonName)) {
            $errors['common_name'] = 'Bitte einen gültigen Hostnamen oder eine IP-Adresse angeben (z. B. intranet.firma.local).';
        }

        $san = [];
        if ($commonName !== '' && !isset($errors['common_name'])) {
            $san[] = $commonName;
        }
        foreach (preg_split('/[\s,;]+/', (string) ($input['san'] ?? '')) ?: [] as $name) {
            $name = strtolower(trim($name));
            if ($name === '') {
                continue;
            }
            if (!self::isCertificateName($name)) {
                $errors['san'] = sprintf('„%s“ ist kein gültiger Hostname bzw. keine gültige IP-Adresse.', $name);
                break;
            }
            if (!in_array($name, $san, true)) {
                $san[] = $name;
            }
        }
        if (count($san) > 50) {
            $errors['san'] = 'Es sind höchstens 50 alternative Namen möglich.';
        }

        $subject = [];
        foreach (array_keys(self::DN_FIELDS) as $field) {
            $subject[$field] = Validator::cleanText((string) ($input[$field] ?? ''), 64);
        }
        $subject['country'] = strtoupper($subject['country']);
        if ($subject['country'] !== '' && preg_match('/^[A-Z]{2}$/', $subject['country']) !== 1) {
            $errors['country'] = 'Bitte den zweistelligen Ländercode angeben (z. B. DE).';
        }
        if ($subject['email'] !== '' && filter_var($subject['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }

        $keyType = (string) ($input['key_type'] ?? 'rsa3072');
        if (!isset(self::KEY_TYPES[$keyType])) {
            $errors['key_type'] = 'Bitte einen Schlüsseltyp auswählen.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $dn = ['commonName' => $commonName];
        foreach (self::DN_FIELDS as $field => [, $name]) {
            if ($subject[$field] !== '') {
                $dn[$name] = $subject[$field];
            }
        }

        $generated = $this->generate(self::KEY_TYPES[$keyType], $dn, $san, false);

        return $this->repository->insert([
            'kind' => self::KIND_CSR,
            'common_name' => $commonName,
            'subject' => json_encode($subject, JSON_UNESCAPED_UNICODE),
            'san' => implode("\n", $san),
            'key_type' => $generated['key_type'],
            'private_key' => $this->secretBox->encrypt($generated['private_key']),
            'public_key_hash' => $generated['public_key_hash'],
            'csr_pem' => $generated['csr'],
            'created_at' => $this->now(),
            'created_by' => mb_substr($user, 0, 100),
        ]);
    }

    /**
     * @return array{filename:string,content:string}|null
     */
    public function csrDownload(int $id): ?array
    {
        $row = $this->repository->find($id);
        if ($row === null || (string) $row['kind'] !== self::KIND_CSR || (string) ($row['csr_pem'] ?? '') === '') {
            return null;
        }

        $name = preg_replace('/[^a-z0-9.-]+/', '_', str_replace('*', 'wildcard', (string) $row['common_name'])) ?: 'request';

        return [
            'filename' => sprintf('%s_%s.csr', $name, date('Ymd-His', (int) $row['created_at'])),
            'content' => (string) $row['csr_pem'],
        ];
    }

    // ------------------------------------------------------------------
    // 2. Zertifikat importieren (Vorschau + Bestaetigung)
    // ------------------------------------------------------------------

    /**
     * Prueft ein hochgeladenes Zertifikat und liefert die Daten fuer die
     * Vorschau. Gespeichert wird noch nichts.
     *
     * @return array<string,mixed>
     */
    public function previewImport(string $raw): array
    {
        $certificates = CertificateInspector::extractCertificates($raw);

        // Serverzertifikat = das Zertifikat, zu dem ein CSR gehoert.
        $leaf = null;
        $request = null;
        $others = [];
        foreach ($certificates as $pem) {
            if ($leaf === null) {
                $details = CertificateInspector::details($pem);
                $match = $this->repository->findRequestByPublicKey($details['public_key_hash']);
                if ($match !== null) {
                    $leaf = $pem;
                    $request = $match;
                    continue;
                }
            }
            $others[] = $pem;
        }

        if ($leaf === null || $request === null) {
            throw new ValidationException(['certificate' => count($certificates) > 1
                ? 'Keines der Zertifikate gehört zu einem hier erstellten Request. Bitte das Zertifikat importieren, das die CA zu einem dieser Requests ausgestellt hat.'
                : 'Das Zertifikat gehört zu keinem hier erstellten Request (der private Schlüssel fehlt). Bitte das Zertifikat importieren, das die CA zu einem dieser Requests ausgestellt hat.']);
        }

        $details = CertificateInspector::details($leaf);
        $chain = CertificateInspector::orderChain($leaf, $others);
        $now = $this->now();
        $status = CertificateInspector::status($details['not_before'], $details['not_after'], $now);

        if ((string) ($request['cert_fingerprint'] ?? '') === $details['fingerprint']) {
            throw new ValidationException(['certificate' => sprintf('Dieses Zertifikat ist bereits beim Request vom %s hinterlegt.', date('d.m.Y H:i', (int) $request['created_at']))]);
        }
        if ((int) $request['active'] === 1 && !CertificateInspector::isUsable($status)) {
            throw new ValidationException(['certificate' => 'Das Zertifikat würde das aktive Zertifikat ersetzen, ist aber derzeit nicht gültig.']);
        }
        if ($details['is_ca']) {
            throw new ValidationException(['certificate' => 'Das Zertifikat ist ein CA-Zertifikat und kann nicht als Serverzertifikat verwendet werden.']);
        }

        $warnings = [];
        if ($status === CertificateInspector::STATUS_EXPIRED) {
            $warnings[] = 'Das Zertifikat ist abgelaufen und kann nicht aktiviert werden.';
        } elseif ($status === CertificateInspector::STATUS_NOT_YET_VALID) {
            $warnings[] = 'Das Zertifikat ist noch nicht gültig und kann erst ab ' . date('d.m.Y H:i', $details['not_before']) . ' aktiviert werden.';
        } elseif ($status === CertificateInspector::STATUS_EXPIRING) {
            $warnings[] = 'Das Zertifikat läuft in weniger als ' . CertificateInspector::EXPIRY_WARNING_DAYS . ' Tagen ab.';
        }
        $host = $this->appHost();
        if (!CertificateInspector::coversHost($details['san'], $details['common_name'], $host)) {
            $warnings[] = sprintf('Das Zertifikat enthält den Hostnamen „%s“ (APP_URL) nicht – Browser würden eine Warnung anzeigen.', $host);
        }
        if ($details['self_signed']) {
            $warnings[] = 'Das Zertifikat ist selbstsigniert.';
        } elseif ($chain === []) {
            $warnings[] = 'Die Datei enthält keine Zwischenzertifikate. Verwendet die CA Zwischenzertifikate, bitte die vollständige Kette (PEM mit allen Zertifikaten) importieren.';
        }
        if ((string) ($request['certificate_pem'] ?? '') !== '') {
            $warnings[] = 'Beim Request ist bereits ein Zertifikat hinterlegt (gültig bis '
                . date('d.m.Y', (int) $request['cert_not_after']) . '). Es wird ersetzt.';
        }

        return [
            'details' => $details,
            'status' => $status,
            'usable' => CertificateInspector::isUsable($status),
            'warnings' => $warnings,
            'chain' => array_map(static function (string $pem): array {
                $info = CertificateInspector::details($pem);

                return ['subject' => $info['subject'], 'issuer' => $info['issuer'], 'not_after' => $info['not_after']];
            }, $chain),
            'request' => [
                'id' => (int) $request['id'],
                'common_name' => (string) $request['common_name'],
                'created_at' => (int) $request['created_at'],
                'active' => (int) $request['active'] === 1,
            ],
            'pending' => [
                'request_id' => (int) $request['id'],
                'fingerprint' => $details['fingerprint'],
                'certificates' => array_merge([$leaf], $chain),
            ],
        ];
    }

    /**
     * Uebernimmt ein zuvor in der Vorschau bestaetigtes Zertifikat.
     *
     * @param array<string,mixed> $pending
     *
     * @return array{id:int,activated:bool}
     */
    public function confirmImport(array $pending, bool $activate, string $user): array
    {
        $certificates = array_values(array_filter((array) ($pending['certificates'] ?? []), 'is_string'));
        $preview = $this->previewImport(implode("\n", $certificates));
        if ($preview['pending']['request_id'] !== (int) ($pending['request_id'] ?? 0)
            || $preview['pending']['fingerprint'] !== (string) ($pending['fingerprint'] ?? '')) {
            throw new ValidationException(['certificate' => 'Die Vorschau ist nicht mehr aktuell. Bitte das Zertifikat erneut importieren.']);
        }

        $leaf = $preview['pending']['certificates'][0];
        $chain = array_slice($preview['pending']['certificates'], 1);
        $details = $preview['details'];
        $id = $preview['request']['id'];

        $this->repository->update($id, [
            'certificate_pem' => $leaf,
            'chain_pem' => $chain === [] ? null : implode('', $chain),
            'cert_subject' => mb_substr($details['subject'], 0, 1024),
            'cert_issuer' => mb_substr($details['issuer'], 0, 1024),
            'cert_serial' => mb_substr($details['serial'], 0, 128),
            'cert_fingerprint' => $details['fingerprint'],
            'cert_san' => implode("\n", $details['san']),
            'cert_not_before' => $details['not_before'],
            'cert_not_after' => $details['not_after'],
            'cert_uploaded_at' => $this->now(),
            'cert_uploaded_by' => mb_substr($user, 0, 100),
        ]);

        $activated = false;
        if ($activate && $preview['usable'] && !$preview['request']['active']) {
            $this->activate($id);
            $activated = true;
        }

        return ['id' => $id, 'activated' => $activated || $preview['request']['active']];
    }

    // ------------------------------------------------------------------
    // 3. Aktivieren / Deaktivieren / Loeschen
    // ------------------------------------------------------------------

    public function activate(int $id): void
    {
        $row = $this->repository->find($id);
        if ($row === null || (string) $row['kind'] !== self::KIND_CSR || (string) ($row['certificate_pem'] ?? '') === '') {
            throw new ValidationException(['certificate' => 'Zu diesem Request ist kein Zertifikat hinterlegt.']);
        }
        $status = $this->rowStatus($row);
        if (!CertificateInspector::isUsable($status)) {
            throw new ValidationException(['certificate' => 'Nur ein aktuell gültiges Zertifikat kann aktiviert werden.']);
        }
        if ($this->secretBox->decrypt((string) $row['private_key']) === null) {
            throw new ValidationException(['certificate' => 'Der private Schlüssel dieses Requests kann nicht entschlüsselt werden (z. B. nach einer Wiederherstellung auf einem anderen Server).']);
        }

        $this->repository->activate($id, $this->now());
    }

    public function deactivate(): void
    {
        $this->repository->activate(null, $this->now());
    }

    public function deleteRequest(int $id): void
    {
        $row = $this->repository->find($id);
        if ($row === null || (string) $row['kind'] !== self::KIND_CSR) {
            throw new ValidationException(['certificate' => 'Der Request wurde nicht gefunden.']);
        }
        if ((string) ($row['certificate_pem'] ?? '') !== '') {
            throw new ValidationException(['certificate' => 'Requests mit Zertifikat bleiben zur Nachvollziehbarkeit erhalten.']);
        }

        $this->repository->delete($id);
    }

    // ------------------------------------------------------------------
    // Uebersicht
    // ------------------------------------------------------------------

    /**
     * Requests (und verwendete Notfall-Zertifikate) fuer die Tabelle.
     *
     * @return list<array<string,mixed>>
     */
    public function overview(): array
    {
        $rows = $this->repository->all();
        $servedId = $this->servedId($rows);

        $result = [];
        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            if ($kind === self::KIND_FALLBACK && $row['first_used_at'] === null) {
                continue;
            }

            $status = $this->rowStatus($row);
            $hasCertificate = (string) ($row['certificate_pem'] ?? '') !== '';
            $active = $kind === self::KIND_CSR && (int) $row['active'] === 1;
            $result[] = [
                'id' => (int) $row['id'],
                'kind' => $kind,
                'created_at' => (int) $row['created_at'],
                'created_by' => (string) $row['created_by'],
                'common_name' => (string) $row['common_name'],
                'san' => self::lines((string) ($row['san'] ?? '')),
                'key_type' => (string) $row['key_type'],
                'has_certificate' => $hasCertificate,
                'cert_issuer' => (string) ($row['cert_issuer'] ?? ''),
                'cert_issuer_cn' => self::dnValue((string) ($row['cert_issuer'] ?? ''), 'CN'),
                'cert_subject' => (string) ($row['cert_subject'] ?? ''),
                'cert_serial' => (string) ($row['cert_serial'] ?? ''),
                'cert_fingerprint' => (string) ($row['cert_fingerprint'] ?? ''),
                'cert_san' => self::lines((string) ($row['cert_san'] ?? '')),
                'cert_not_before' => $row['cert_not_before'] === null ? null : (int) $row['cert_not_before'],
                'cert_not_after' => $row['cert_not_after'] === null ? null : (int) $row['cert_not_after'],
                'cert_uploaded_at' => $row['cert_uploaded_at'] === null ? null : (int) $row['cert_uploaded_at'],
                'cert_uploaded_by' => (string) ($row['cert_uploaded_by'] ?? ''),
                'chain_count' => substr_count((string) ($row['chain_pem'] ?? ''), 'BEGIN CERTIFICATE'),
                'status' => $status,
                'days_left' => $row['cert_not_after'] === null ? null : (int) floor(((int) $row['cert_not_after'] - $this->now()) / 86400),
                'active' => $active,
                'activated_at' => $row['activated_at'] === null ? null : (int) $row['activated_at'],
                'first_used_at' => $row['first_used_at'] === null ? null : (int) $row['first_used_at'],
                'last_used_at' => $row['last_used_at'] === null ? null : (int) $row['last_used_at'],
                'in_use' => $servedId === (int) $row['id'],
                'can_activate' => $kind === self::KIND_CSR && !$active && $hasCertificate && CertificateInspector::isUsable($status),
                'can_delete' => $kind === self::KIND_CSR && !$hasCertificate,
                'has_csr' => (string) ($row['csr_pem'] ?? '') !== '',
            ];
        }

        return $result;
    }

    /**
     * Zusammenfassung fuer den Statusbereich.
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        $rows = $this->repository->all();
        $active = $this->repository->findActive();
        $activeStatus = $active === null ? CertificateInspector::STATUS_NONE : $this->rowStatus($active);
        $mode = $active !== null && CertificateInspector::isUsable($activeStatus) ? self::MODE_STRICT : self::MODE_FALLBACK;

        $lastSync = null;
        foreach ($rows as $row) {
            if ($row['last_used_at'] !== null && ($lastSync === null || (int) $row['last_used_at'] > $lastSync)) {
                $lastSync = (int) $row['last_used_at'];
            }
        }

        return [
            'mode' => $mode,
            'active' => $active === null ? null : [
                'id' => (int) $active['id'],
                'common_name' => (string) $active['common_name'],
                'not_after' => (int) $active['cert_not_after'],
                'issuer_cn' => self::dnValue((string) ($active['cert_issuer'] ?? ''), 'CN'),
                'days_left' => (int) floor(((int) $active['cert_not_after'] - $this->now()) / 86400),
            ],
            'active_status' => $activeStatus,
            'networks' => $this->httpNetworks(),
            'last_sync' => $lastSync,
            'sync_stale' => $lastSync === null || $this->now() - $lastSync > self::SYNC_STALE_AFTER,
            'served_id' => $this->servedId($rows),
            'app_host' => $this->appHost(),
        ];
    }

    // ------------------------------------------------------------------
    // Auslieferung an den auth-Container
    // ------------------------------------------------------------------

    /**
     * Konfiguration fuer den auth-Container (/internal/tls-config) und
     * Vermerk, welches Zertifikat er verwendet.
     *
     * @return array<string,string>
     */
    public function authConfig(): array
    {
        $now = $this->now();
        $row = $this->repository->findActive();
        $key = null;
        if ($row !== null && CertificateInspector::isUsable($this->rowStatus($row))) {
            $key = $this->secretBox->decrypt((string) $row['private_key']);
            if ($key === null) {
                app_logger()->error('Privater Schlüssel des aktiven Zertifikats nicht entschlüsselbar – Notfall-Zertifikat wird verwendet.', ['id' => (int) $row['id']]);
            }
        }

        if ($row !== null && $key !== null) {
            $mode = self::MODE_STRICT;
            $label = sprintf('Zertifikat #%d (%s)', (int) $row['id'], (string) $row['common_name']);
        } else {
            $mode = self::MODE_FALLBACK;
            $row = $this->ensureFallback();
            $key = (string) $this->secretBox->decrypt((string) $row['private_key']);
            $label = sprintf('Notfall-Zertifikat #%d (selbstsigniert)', (int) $row['id']);
        }

        $this->repository->markUsed((int) $row['id'], $now);

        return [
            'TLS_MODE' => $mode,
            'TLS_HTTP_NETWORKS' => implode(' ', $this->httpNetworks()),
            'TLS_CERT' => rtrim((string) $row['certificate_pem']) . "\n" . (string) ($row['chain_pem'] ?? ''),
            'TLS_KEY' => $key,
            'TLS_ID' => (string) (int) $row['id'],
            'TLS_LABEL' => $label,
        ];
    }

    /**
     * Liefert ein gueltiges selbstsigniertes Notfall-Zertifikat und erzeugt
     * bei Bedarf (fehlt, laeuft bald ab, nicht entschluesselbar) ein neues.
     *
     * @return array<string,mixed>
     */
    public function ensureFallback(): array
    {
        $row = $this->repository->latestFallback();
        if ($row !== null
            && $this->rowStatus($row) === CertificateInspector::STATUS_VALID
            && $this->secretBox->decrypt((string) $row['private_key']) !== null) {
            return $row;
        }

        $host = $this->appHost() !== '' ? $this->appHost() : 'localhost';
        $san = array_values(array_unique([$host, 'localhost']));
        $generated = $this->generate(self::KEY_TYPES['rsa2048'], ['commonName' => $host, 'organizationName' => 'Intranet (Notfall-Zertifikat)'], $san, true);
        $details = CertificateInspector::details($generated['certificate']);

        $id = $this->repository->insert([
            'kind' => self::KIND_FALLBACK,
            'common_name' => $host,
            'subject' => null,
            'san' => implode("\n", $san),
            'key_type' => $generated['key_type'],
            'private_key' => $this->secretBox->encrypt($generated['private_key']),
            'public_key_hash' => $generated['public_key_hash'],
            'csr_pem' => null,
            'created_at' => $this->now(),
            'created_by' => 'System',
            'certificate_pem' => $generated['certificate'],
            'cert_subject' => $details['subject'],
            'cert_issuer' => $details['issuer'],
            'cert_serial' => $details['serial'],
            'cert_fingerprint' => $details['fingerprint'],
            'cert_san' => implode("\n", $details['san']),
            'cert_not_before' => $details['not_before'],
            'cert_not_after' => $details['not_after'],
            'cert_uploaded_at' => $this->now(),
            'cert_uploaded_by' => 'System',
        ]);
        app_logger()->info('Selbstsigniertes Notfall-Zertifikat erzeugt.', ['id' => $id, 'host' => $host]);

        return (array) $this->repository->find($id);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row
     */
    private function rowStatus(array $row): string
    {
        if ((string) ($row['certificate_pem'] ?? '') === '') {
            return CertificateInspector::STATUS_NONE;
        }

        return CertificateInspector::status(
            $row['cert_not_before'] === null ? null : (int) $row['cert_not_before'],
            $row['cert_not_after'] === null ? null : (int) $row['cert_not_after'],
            $this->now()
        );
    }

    /**
     * Eintrag, den der auth-Container zuletzt abgerufen hat.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function servedId(array $rows): ?int
    {
        $id = null;
        $latest = null;
        foreach ($rows as $row) {
            if ($row['last_used_at'] !== null && ($latest === null || (int) $row['last_used_at'] > $latest)) {
                $latest = (int) $row['last_used_at'];
                $id = (int) $row['id'];
            }
        }

        return $id;
    }

    public static function isCertificateName(string $name): bool
    {
        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_starts_with($name, '*.')) {
            $name = substr($name, 2);
            if (!str_contains($name, '.')) {
                return false;
            }
        }

        return $name !== '' && filter_var($name, FILTER_VALIDATE_IP) === false && Validator::isHostname($name);
    }

    /**
     * Erzeugt Schluessel + CSR bzw. (selfSigned) ein selbstsigniertes Zertifikat.
     *
     * @param array{label:string,type:int,bits?:int,curve?:string} $type
     * @param array<string,string> $dn
     * @param list<string> $san
     *
     * @return array{private_key:string,public_key_hash:string,key_type:string,csr:string,certificate:string}
     */
    private function generate(array $type, array $dn, array $san, bool $selfSigned): array
    {
        $config = $this->writeOpensslConfig($san, $type['type'] === OPENSSL_KEYTYPE_EC);
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => $type['type']];
            if (isset($type['bits'])) {
                $options['private_key_bits'] = $type['bits'];
            }
            if (isset($type['curve'])) {
                $options['curve_name'] = $type['curve'];
            }

            $key = openssl_pkey_new($options);
            if ($key === false) {
                throw new RuntimeException('Schlüssel konnte nicht erzeugt werden: ' . self::opensslErrors());
            }

            $csr = openssl_csr_new($dn, $key, $options + ['req_extensions' => 'v3_req']);
            if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('CSR konnte nicht erzeugt werden: ' . self::opensslErrors());
            }

            $certificate = '';
            if ($selfSigned) {
                $x509 = openssl_csr_sign($csr, null, $key, self::FALLBACK_DAYS, $options + ['x509_extensions' => 'v3_self'], random_int(1, PHP_INT_MAX));
                if ($x509 === false || !openssl_x509_export($x509, $certificate)) {
                    throw new RuntimeException('Zertifikat konnte nicht erzeugt werden: ' . self::opensslErrors());
                }
            }

            if (!openssl_csr_export($csr, $csrPem) || !openssl_pkey_export($key, $privateKey, null, ['config' => $config])) {
                throw new RuntimeException('Export fehlgeschlagen: ' . self::opensslErrors());
            }

            return [
                'private_key' => (string) $privateKey,
                'public_key_hash' => CertificateInspector::publicKeyHash($key),
                'key_type' => CertificateInspector::keyType($key),
                'csr' => (string) $csrPem,
                'certificate' => $certificate,
            ];
        } finally {
            @unlink($config);
        }
    }

    /**
     * @param list<string> $san
     */
    private function writeOpensslConfig(array $san, bool $ec): string
    {
        $alt = [];
        $dns = 0;
        $ip = 0;
        foreach ($san as $name) {
            $alt[] = filter_var($name, FILTER_VALIDATE_IP) !== false ? 'IP.' . (++$ip) . ' = ' . $name : 'DNS.' . (++$dns) . ' = ' . $name;
        }
        $sanLine = $alt === [] ? '' : "subjectAltName = @alt_names\n";
        $keyUsage = $ec ? 'critical, digitalSignature' : 'critical, digitalSignature, keyEncipherment';

        $content = "[req]\ndistinguished_name = req_dn\nutf8 = yes\nstring_mask = utf8only\n\n[req_dn]\n\n"
            . "[v3_req]\n{$sanLine}keyUsage = {$keyUsage}\nextendedKeyUsage = serverAuth\n\n"
            . "[v3_self]\n{$sanLine}basicConstraints = critical, CA:FALSE\nkeyUsage = {$keyUsage}\nextendedKeyUsage = serverAuth\nsubjectKeyIdentifier = hash\n\n"
            . ($alt === [] ? '' : "[alt_names]\n" . implode("\n", $alt) . "\n");

        $file = tempnam(sys_get_temp_dir(), 'tls');
        if ($file === false || file_put_contents($file, $content) === false) {
            throw new RuntimeException('Temporäre OpenSSL-Konfiguration kann nicht geschrieben werden.');
        }

        return $file;
    }

    private static function opensslErrors(): string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return implode('; ', $messages);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $value)), static fn (string $line): bool => $line !== ''));
    }

    private static function dnValue(string $dn, string $field): string
    {
        foreach (explode(', ', $dn) as $part) {
            if (str_starts_with($part, $field . '=')) {
                return substr($part, strlen($field) + 1);
            }
        }

        return '';
    }
}
