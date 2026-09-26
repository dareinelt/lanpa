<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\SettingsRepository;
use App\Repositories\TlsCertificateRepository;
use App\Security\SecretBox;
use App\Services\SettingsService;
use App\Services\Tls\CertificateInspector;
use App\Services\Tls\TlsCertificateService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function tlsPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE tls_certificates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL DEFAULT \'csr\',
        common_name TEXT NOT NULL,
        subject TEXT NULL,
        san TEXT NULL,
        key_type TEXT NOT NULL,
        private_key TEXT NOT NULL,
        public_key_hash TEXT NOT NULL,
        csr_pem TEXT NULL,
        created_at INTEGER NOT NULL,
        created_by TEXT NOT NULL DEFAULT \'\',
        certificate_pem TEXT NULL,
        chain_pem TEXT NULL,
        cert_subject TEXT NULL,
        cert_issuer TEXT NULL,
        cert_serial TEXT NULL,
        cert_fingerprint TEXT NULL,
        cert_san TEXT NULL,
        cert_not_before INTEGER NULL,
        cert_not_after INTEGER NULL,
        cert_uploaded_at INTEGER NULL,
        cert_uploaded_by TEXT NULL,
        active INTEGER NOT NULL DEFAULT 0,
        activated_at INTEGER NULL,
        first_used_at INTEGER NULL,
        last_used_at INTEGER NULL
    )');

    return $pdo;
}

/**
 * @return array{0:TlsCertificateService,1:TlsCertificateRepository,2:SecretBox}
 */
function tlsService(?PDO $pdo = null, ?Closure $clock = null): array
{
    $pdo ??= tlsPdo();
    $box = new SecretBox(sys_get_temp_dir() . '/lanpa-tls-' . bin2hex(random_bytes(6)) . '/keys/secrets.key');
    $repository = new TlsCertificateRepository($pdo);
    $service = new TlsCertificateService($repository, new SettingsService(new SettingsRepository($pdo)), $box, 'https://intranet.example.local', $clock);

    return [$service, $repository, $box];
}

/**
 * Test-CA: stellt zu einem CSR ein Zertifikat aus (wie eine echte CA).
 *
 * @return array{0:string,1:string} [Serverzertifikat, CA-Zertifikat]
 */
function tlsIssue(string $csrPem, int $days = 90, array $san = ['intranet.example.local']): array
{
    $config = tempnam(sys_get_temp_dir(), 'tlstest');
    $alt = implode(', ', array_map(static fn (string $name): string => 'DNS:' . $name, $san));
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ca]\nbasicConstraints = critical, CA:TRUE\n[leaf]\nbasicConstraints = CA:FALSE\nsubjectAltName = {$alt}\n");
    $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
    $caCsr = openssl_csr_new(['commonName' => 'Test CA'], $caKey, ['config' => $config, 'digest_alg' => 'sha256']);
    $ca = openssl_csr_sign($caCsr, null, $caKey, 365, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'], 1);
    $leaf = openssl_csr_sign($csrPem, $ca, $caKey, $days, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'leaf'], 2);
    openssl_x509_export($leaf, $leafPem);
    openssl_x509_export($ca, $caPem);
    unlink($config);

    return [$leafPem, $caPem];
}

function tlsCreateRequest(TlsCertificateService $service): int
{
    return $service->createRequest([
        'common_name' => 'Intranet.Example.Local',
        'san' => "intranet\n10.0.0.5",
        'organization' => 'Beispiel GmbH',
        'country' => 'de',
        'key_type' => 'ec256',
    ], 'admin');
}

Runner::test('TLS: CSR wird mit SAN erzeugt, privater Schlüssel verschlüsselt gespeichert', function (): void {
    [$service, $repository, $box] = tlsService();
    $id = tlsCreateRequest($service);
    $row = $repository->find($id);

    Assert::true(SecretBox::isEncrypted((string) $row['private_key']));
    Assert::contains('BEGIN PRIVATE KEY', (string) $box->decrypt((string) $row['private_key']));
    Assert::same('ECDSA P-256', $row['key_type']);
    Assert::same("intranet.example.local\nintranet\n10.0.0.5", $row['san']);

    $subject = openssl_csr_get_subject((string) $row['csr_pem'], false);
    Assert::same('intranet.example.local', $subject['commonName']);
    Assert::same('DE', $subject['countryName']);

    $download = $service->csrDownload($id);
    Assert::contains('BEGIN CERTIFICATE REQUEST', $download['content']);
    Assert::true(str_ends_with($download['filename'], '.csr'));
});

Runner::test('TLS: ungültige CSR-Angaben werden abgelehnt', function (): void {
    [$service] = tlsService();
    try {
        $service->createRequest(['common_name' => 'kein host!', 'country' => 'Deutschland', 'key_type' => 'dsa'], 'admin');
        Assert::true(false, 'Validierung erwartet.');
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['common_name'], $exception->errors()['country'], $exception->errors()['key_type']));
    }
});

Runner::test('TLS: Import ordnet das Zertifikat per Schlüssel zu, zeigt Vorschau und aktiviert nach Bestätigung', function (): void {
    [$service, $repository] = tlsService();
    tlsCreateRequest($service);
    $id = tlsCreateRequest($service);
    [$leaf, $ca] = tlsIssue((string) $repository->find($id)['csr_pem']);

    // Kette in beliebiger Reihenfolge: CA zuerst.
    $preview = $service->previewImport($ca . $leaf);
    Assert::same($id, $preview['request']['id']);
    Assert::same('intranet.example.local', $preview['details']['common_name']);
    Assert::same(CertificateInspector::STATUS_VALID, $preview['status']);
    Assert::same(1, count($preview['chain']));
    Assert::same([], $preview['warnings']);
    Assert::null($repository->find($id)['certificate_pem'], 'Vor der Bestätigung wird nichts gespeichert.');

    $result = $service->confirmImport($preview['pending'], true, 'admin');
    Assert::true($result['activated']);
    $row = $repository->find($id);
    Assert::same(1, (int) $row['active']);
    Assert::contains('BEGIN CERTIFICATE', (string) $row['chain_pem']);

    $config = $service->authConfig();
    Assert::same(TlsCertificateService::MODE_STRICT, $config['TLS_MODE']);
    Assert::same(2, substr_count($config['TLS_CERT'], 'BEGIN CERTIFICATE'));
    Assert::contains('PRIVATE KEY', $config['TLS_KEY']);
    Assert::same('192.168.200.0/21', $config['TLS_HTTP_NETWORKS']);

    $overview = $service->overview();
    Assert::same(2, count($overview));
    Assert::true($overview[0]['active'] && $overview[0]['in_use'] && $overview[0]['has_certificate']);
    Assert::true($overview[1]['can_delete']);
});

Runner::test('TLS: DER-kodierte CRT-Datei wird erkannt', function (): void {
    [$service, $repository] = tlsService();
    $id = tlsCreateRequest($service);
    [$leaf] = tlsIssue((string) $repository->find($id)['csr_pem']);
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $leaf));

    $preview = $service->previewImport($der);
    Assert::same($id, $preview['request']['id']);
    Assert::true(in_array(true, array_map(static fn (string $w): bool => str_contains($w, 'Zwischenzertifikate'), $preview['warnings']), true));
});

Runner::test('TLS: fremde Zertifikate, private Schlüssel und Duplikate werden abgelehnt', function (): void {
    [$service, $repository] = tlsService();
    $id = tlsCreateRequest($service);

    $foreignKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $foreignCsr = openssl_csr_new(['commonName' => 'fremd.example'], $foreignKey, ['digest_alg' => 'sha256']);
    openssl_csr_export($foreignCsr, $foreignCsrPem);
    [$foreign] = tlsIssue($foreignCsrPem);

    foreach ([$foreign, "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----", 'Unsinn'] as $input) {
        try {
            $service->previewImport($input);
            Assert::true(false, 'Ablehnung erwartet.');
        } catch (ValidationException $exception) {
            Assert::true(isset($exception->errors()['certificate']));
        }
    }

    [$leaf] = tlsIssue((string) $repository->find($id)['csr_pem']);
    $service->confirmImport($service->previewImport($leaf)['pending'], false, 'admin');
    try {
        $service->previewImport($leaf);
        Assert::true(false, 'Duplikat erwartet.');
    } catch (ValidationException $exception) {
        Assert::contains('bereits', $exception->errors()['certificate']);
    }
});

Runner::test('TLS: ohne gültiges Zertifikat wird das Notfall-Zertifikat verwendet und in der Übersicht gezeigt', function (): void {
    $now = time();
    $clock = static function () use (&$now): int {
        return $now;
    };
    [$service, $repository] = tlsService(null, $clock);
    Assert::same([], $service->overview(), 'Unbenutztes Notfall-Zertifikat erscheint nicht.');

    $config = $service->authConfig();
    Assert::same(TlsCertificateService::MODE_FALLBACK, $config['TLS_MODE']);
    Assert::contains('Notfall-Zertifikat', $config['TLS_LABEL']);
    $overview = $service->overview();
    Assert::same(1, count($overview));
    Assert::same('fallback', $overview[0]['kind']);
    Assert::true($overview[0]['in_use']);

    // Aktives Zertifikat laeuft ab -> Notfallmodus.
    $id = tlsCreateRequest($service);
    [$leaf] = tlsIssue((string) $repository->find($id)['csr_pem'], 10);
    $service->confirmImport($service->previewImport($leaf)['pending'], true, 'admin');
    Assert::same(TlsCertificateService::MODE_STRICT, $service->authConfig()['TLS_MODE']);
    Assert::same(CertificateInspector::STATUS_EXPIRING, $service->overview()[0]['status']);

    $now += 11 * 86400;
    Assert::same(TlsCertificateService::MODE_FALLBACK, $service->authConfig()['TLS_MODE']);
    Assert::same(TlsCertificateService::MODE_FALLBACK, $service->state()['mode']);
    try {
        $service->activate($id);
        Assert::true(false, 'Abgelaufenes Zertifikat darf nicht aktiviert werden.');
    } catch (ValidationException) {
        Assert::true(true);
    }
});

Runner::test('TLS: Quellnetze werden geprüft und normalisiert', function (): void {
    Assert::same(['192.168.200.0/21', '10.1.2.3/32', 'fd00::/8'], TlsCertificateService::parseNetworks("192.168.200.0/21, 10.1.2.3\nFD00::/8 192.168.200.0/21"));
    Assert::same([], TlsCertificateService::parseNetworks(''));
    foreach (['192.168.1.0/33', '300.1.1.1', 'intranet', '10.0.0.0/abc'] as $invalid) {
        try {
            TlsCertificateService::parseNetworks($invalid);
            Assert::true(false, 'Ungültiges Netz: ' . $invalid);
        } catch (ValidationException) {
            Assert::true(true);
        }
    }
});

Runner::test('TLS: Hostname-Abdeckung inkl. Wildcard', function (): void {
    Assert::true(CertificateInspector::coversHost(['*.example.local'], '', 'intranet.example.local'));
    Assert::false(CertificateInspector::coversHost(['*.example.local'], '', 'a.b.example.local'));
    Assert::true(CertificateInspector::coversHost([], 'intranet', 'INTRANET'));
    Assert::false(CertificateInspector::coversHost(['other.local'], 'intranet', 'intranet'));
});
