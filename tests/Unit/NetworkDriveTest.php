<?php

declare(strict_types=1);

use App\Controllers\NetworkDriveController;
use App\Exceptions\ValidationException;
use App\Repositories\NetworkDriveRepository;
use App\Repositories\SettingsRepository;
use App\Services\Office\NetworkDriveService;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\SettingsService;
use OCA\IntranetIntegration\Service\NetworkDriveService as NextcloudDrives;
use OCA\IntranetIntegration\Service\TokenVerifier;
use Tests\Support\Assert;
use Tests\Support\Runner;

require_once BASE_PATH . '/docker/nextcloud/apps/intranet_integration/lib/Service/TokenVerifier.php';
require_once BASE_PATH . '/docker/nextcloud/apps/intranet_integration/lib/Service/NetworkDriveService.php';

const DRIVES_URL = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/drives';

function drivesPdo(array $settings = []): PDO
{
    $pdo = officePdo($settings);
    $pdo->exec('CREATE TABLE network_drives (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid TEXT NOT NULL,
        display_name TEXT NOT NULL DEFAULT \'\',
        drive_letter TEXT NOT NULL,
        unc_path TEXT NOT NULL,
        domain TEXT NOT NULL DEFAULT \'\',
        computer_name TEXT NOT NULL DEFAULT \'\',
        reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_uid, drive_letter)
    )');

    return $pdo;
}

function drivesService(PDO $pdo, ?FakeOfficeProbe $probe = null): NetworkDriveService
{
    return new NetworkDriveService(
        new NetworkDriveRepository($pdo),
        new SettingsService(new SettingsRepository($pdo)),
        officeConfig(),
        $probe ?? new FakeOfficeProbe()
    );
}

function drivesOkProbe(): FakeOfficeProbe
{
    $probe = new FakeOfficeProbe();
    $probe->responses[DRIVES_URL] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'Netzlaufwerke übernommen.'])];

    return $probe;
}

Runner::test('Netzlaufwerke: Laufwerksbuchstaben und UNC-Pfade werden geprueft', static function (): void {
    Assert::same(['B', 'G'], NetworkDriveService::parseLetters('B:/, G:/'));
    Assert::same(['B', 'G', 'H'], NetworkDriveService::parseLetters('h:\\ b; G: g'));
    Assert::same([], NetworkDriveService::parseLetters('  '));
    Assert::null(NetworkDriveService::parseLetters('BG'));
    Assert::null(NetworkDriveService::parseLetters('1:'));
    Assert::same('B:/, G:/', NetworkDriveService::formatLetters(['B', 'G']));

    Assert::same(
        ['unc' => '\\\\Fs_9187\\public', 'host' => 'Fs_9187', 'share' => 'public', 'root' => ''],
        NetworkDriveService::parseUnc('\\\\Fs_9187\\public\\')
    );
    Assert::same(
        ['unc' => '\\\\fs01.example.local\\EDV$\\Team\\Ablage', 'host' => 'fs01.example.local', 'share' => 'EDV$', 'root' => 'Team/Ablage'],
        NetworkDriveService::parseUnc('//fs01.example.local/EDV$/Team/Ablage')
    );
    Assert::null(NetworkDriveService::parseUnc('C:\\Daten'));
    Assert::null(NetworkDriveService::parseUnc('\\\\server'));
    Assert::null(NetworkDriveService::parseUnc('\\\\server\\share\\..\\x'));
    Assert::null(NetworkDriveService::parseUnc('\\\\ser ver\\share'));
    Assert::null(NetworkDriveService::parseUnc('\\\\server\\sha:re'));
});

Runner::test('Netzlaufwerke: Meldung nur vom Anmeldeskript, nie per Cross-Site-Formular', static function (): void {
    $script = ['HTTP_X_INTRANET_CLIENT' => 'netzlaufwerke'];
    Assert::true(NetworkDriveController::isScriptRequest($script));
    Assert::true(NetworkDriveController::isScriptRequest($script + ['HTTP_SEC_FETCH_SITE' => 'none']));
    Assert::false(NetworkDriveController::isScriptRequest([]), 'Browser-Formulare koennen keinen eigenen Header setzen.');
    Assert::false(NetworkDriveController::isScriptRequest($script + ['HTTP_ORIGIN' => 'https://boese.example']));
    Assert::false(NetworkDriveController::isScriptRequest($script + ['HTTP_SEC_FETCH_SITE' => 'cross-site']));
});

Runner::test('Netzlaufwerke: Ausschlussliste Standard B:/ und G:/, leere Liste bleibt leer', static function (): void {
    $service = drivesService(drivesPdo());
    Assert::true($service->isEnabled());
    Assert::same(['B', 'G'], $service->excludedLetters());

    // Gespeicherte Form (saveSettings): Buchstaben bzw. "none" fuer eine leere Liste.
    Assert::same(['B', 'X'], drivesService(drivesPdo([NetworkDriveService::EXCLUDED_SETTING => 'B,X']))->excludedLetters());
    Assert::same([], drivesService(drivesPdo([NetworkDriveService::EXCLUDED_SETTING => NetworkDriveService::NONE]))->excludedLetters(), 'Leere Liste: alle Laufwerke werden weitergereicht.');
    Assert::false(drivesService(drivesPdo([NetworkDriveService::ENABLED_SETTING => '0']))->isEnabled());

    $errors = [];
    try {
        $service->saveSettings(true, 'Laufwerk B');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
    Assert::true(isset($errors['excluded']));
});

Runner::test('Netzlaufwerke: Meldung ersetzt den Stand, ausgeschlossene Laufwerke werden nie weitergereicht', static function (): void {
    $pdo = drivesPdo();
    $probe = drivesOkProbe();
    $service = drivesService($pdo, $probe);
    $user = ['office_uid' => 'amueller', 'display_name' => 'Anna Müller'];

    $result = $service->report($user, "B=\\\\fs01\\backup\r\nG:=\\\\Fs_9187\\public\nH=\\\\fs01\\home\\amueller\nX=C:\\lokal\nkaputt\nH=\\\\fs01\\doppelt", 'example', 'pc-0815');
    Assert::same(['B', 'G', 'H'], array_column($result['accepted'], 'letter'));
    Assert::same([true, true, false], array_column($result['accepted'], 'excluded'));
    Assert::same(3, count($result['ignored']));
    Assert::true($result['changed']);
    Assert::true($result['push']['ok'] ?? false);

    $rows = $service->rows();
    Assert::same(3, count($rows), 'Ausgeschlossene Laufwerke werden zur Information gespeichert.');
    Assert::same('EXAMPLE', $rows[0]['domain']);
    Assert::same('PC-0815', $rows[0]['computer_name']);
    Assert::same(['users' => 1, 'drives' => 3, 'passed' => 1], $service->summary());
    Assert::same(
        ['amueller' => [['letter' => 'H', 'host' => 'fs01', 'share' => 'home', 'root' => 'amueller', 'domain' => 'EXAMPLE']]],
        $service->nextcloudDrives()
    );

    $payload = json_decode((string) $probe->requests[0]['body'], true);
    Assert::same(['B', 'G'], $payload['excluded']);
    Assert::same(['H'], array_column($payload['users']['amueller'], 'letter'));
    Assert::false(str_contains((string) $probe->requests[0]['body'], 'backup'), 'Ausgeschlossene Laufwerke verlassen das Intranet nicht.');

    // Unveraenderte Meldung (jede Anmeldung): keine erneute Uebertragung.
    $result = $service->report($user, "H=\\\\fs01\\home\\amueller\nG=\\\\Fs_9187\\other", 'EXAMPLE', 'PC-0815');
    Assert::false($result['changed']);
    Assert::null($result['push']);
    Assert::same(1, count($probe->requests));

    // Neue Meldung ersetzt den bisherigen Stand.
    $result = $service->report($user, '', 'EXAMPLE', 'PC-0815');
    Assert::true($result['changed']);
    Assert::same([], $service->nextcloudDrives());
    Assert::same(2, count($probe->requests));

    // Ausgeschaltet: nichts wird weitergereicht.
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('" . NetworkDriveService::ENABLED_SETTING . "', '0')");
    $service = drivesService($pdo, $probe);
    $service->report($user, 'H=\\\\fs01\\home', 'EXAMPLE', 'PC-0815');
    Assert::same(1, count($service->rows()));
    Assert::same([], $service->nextcloudDrives());
    Assert::false($service->payload()['enabled']);

    $errors = [];
    try {
        $service->report(['office_uid' => 'böse uid', 'display_name' => ''], '', '', '');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
    Assert::true(isset($errors['user']));
});

Runner::test('Netzlaufwerke: Uebertragung an Nextcloud ist signiert und an den Inhalt gebunden', static function (): void {
    $pdo = drivesPdo();
    $probe = drivesOkProbe();
    $service = drivesService($pdo, $probe);
    $service->report(['office_uid' => 'cmeier@klinik', 'display_name' => 'Carla Meier'], 'K=\\\\klinik-fs\\daten', 'KLINIK', 'PC1');

    $result = $service->pushToNextcloud();
    Assert::true($result['ok'], $result['message']);
    $request = end($probe->requests);
    $claims = OfficeJwt::decode(substr($request['headers']['Authorization'], 7), 'test-secret-0123456789');
    Assert::same(OfficeJwt::DRIVES_AUDIENCE, $claims['aud'] ?? null);
    Assert::same(TokenVerifier::DRIVES_AUDIENCE, OfficeJwt::DRIVES_AUDIENCE);
    Assert::same(hash('sha256', (string) $request['body']), $claims['body'] ?? null);
    Assert::true((new TokenVerifier())->claims(substr($request['headers']['Authorization'], 7), 'test-secret-0123456789', TokenVerifier::DRIVES_AUDIENCE) !== null);

    // Nextcloud-App akzeptiert die Uebergabe (gleiche Pruefregeln).
    $normalized = NextcloudDrives::normalizePayload(json_decode((string) $request['body'], true));
    Assert::true(is_array($normalized));
    Assert::same(['cmeier@klinik'], array_keys($normalized['users']));
    Assert::same($service->fingerprint(), $normalized['fingerprint']);

    $empty = json_decode((string) json_encode(['users' => (object) drivesService(drivesPdo())->payload()['users']]));
    Assert::true($empty->users instanceof stdClass, 'Leere Benutzerliste wird als JSON-Objekt uebertragen.');

    $probe->responses[DRIVES_URL] = ['status' => 401, 'error' => null, 'body' => ''];
    Assert::contains('JWT', $service->pushToNextcloud()['message']);
    $probe->responses[DRIVES_URL] = ['status' => 404, 'error' => null, 'body' => ''];
    Assert::contains('veraltet', $service->pushToNextcloud()['message']);
});

Runner::test('Netzlaufwerke: Nextcloud-App prueft Uebergabe und benennt Einbindungen', static function (): void {
    $valid = [
        'version' => 1,
        'enabled' => true,
        'excluded' => ['B', 'G'],
        'users' => [
            'AMueller' => [
                ['letter' => 'H', 'host' => 'fs01', 'share' => 'home', 'root' => 'amueller', 'domain' => 'EXAMPLE'],
                ['letter' => 'G', 'host' => 'fs01', 'share' => 'public', 'root' => '', 'domain' => 'EXAMPLE'],
            ],
        ],
        'fingerprint' => str_repeat('a', 64),
    ];
    $normalized = NextcloudDrives::normalizePayload($valid);
    Assert::same(['amueller'], array_keys($normalized['users']), 'Kennungen werden ohne Gross-/Kleinschreibung zugeordnet.');
    Assert::same(['H'], array_column($normalized['users']['amueller'], 'letter'), 'Ausgeschlossene Laufwerke werden auch in Nextcloud verworfen.');

    $disabled = NextcloudDrives::normalizePayload(['enabled' => false] + $valid);
    Assert::same([], $disabled['users']);

    $bad = $valid;
    $bad['users']['AMueller'][0]['root'] = '../etc';
    Assert::true(is_string(NextcloudDrives::normalizePayload($bad)));
    $bad = $valid;
    $bad['users']['AMueller'][0]['host'] = 'fs01;rm';
    Assert::true(is_string(NextcloudDrives::normalizePayload($bad)));
    Assert::true(is_string(NextcloudDrives::normalizePayload(['fingerprint' => 'x'] + $valid)));
    Assert::true(is_string(NextcloudDrives::normalizePayload(['users' => ['böse uid' => []]] + $valid)));

    Assert::same('/Laufwerk H (amueller)', NextcloudDrives::mountPoint(['letter' => 'H', 'share' => 'home', 'root' => 'amueller']));
    Assert::same('/Laufwerk V (Daten)', NextcloudDrives::mountPoint(['letter' => 'V', 'share' => 'Daten$', 'root' => '']));
    Assert::same('amueller', NextcloudDrives::defaultLogin('amueller'));
    Assert::same('cmeier', NextcloudDrives::defaultLogin('cmeier@klinik'));
});

Runner::test('Netzlaufwerke: Gesundheitspruefung gleicht bei Abweichung ab', static function (): void {
    $secret = 'test-secret-0123456789';
    $diagnosticsUrl = 'GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics';
    $withDrives = static function (FakeOfficeProbe $probe, ?array $drives) use ($diagnosticsUrl): FakeOfficeProbe {
        $body = json_decode($probe->responses[$diagnosticsUrl]['body'], true);
        if ($drives !== null) {
            $body['drives'] = $drives;
        }
        $probe->responses[$diagnosticsUrl]['body'] = json_encode($body);

        return $probe;
    };
    $health = static fn (FakeOfficeProbe $probe, NetworkDriveService $drives): OfficeHealthService => new OfficeHealthService(
        officeConfig(), $probe, officeTempDir() . '/health.json', 30, null, null, null, null, $drives
    );
    $pushes = static fn (FakeOfficeProbe $probe): int => count(array_filter($probe->requests, static fn (array $r): bool => str_ends_with($r['url'], '/api/drives')));

    $pdo = drivesPdo();
    drivesService($pdo)->report(['office_uid' => 'amueller', 'display_name' => 'Anna'], 'H=\\\\fs01\\home', 'EXAMPLE', 'PC1');

    // Gleicher Stand: keine Uebertragung.
    $probe = FakeOfficeProbe::healthy($secret);
    $drives = drivesService($pdo, $probe);
    $withDrives($probe, ['fingerprint' => $drives->fingerprint(), 'opted_in' => 2, 'files_external' => true, 'smb_available' => true]);
    $result = $health($probe, $drives)->check();
    Assert::same(OfficeHealthService::OK, $result['components']['drives']['status']);
    Assert::contains('aktiv bei 2 Benutzer', $result['components']['drives']['message']);
    Assert::same(0, $pushes($probe));

    // Fehlendes smbclient: Hinweis, Office bleibt "ok".
    $probe = FakeOfficeProbe::healthy($secret);
    $withDrives($probe, ['fingerprint' => $drives->fingerprint(), 'opted_in' => 0, 'files_external' => true, 'smb_available' => false]);
    $result = $health($probe, drivesService($pdo, $probe))->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['drives']['status']);
    Assert::contains('smbclient', $result['components']['drives']['message']);
    Assert::same('ok', $result['state']);

    // Abweichung: wird nachgetragen.
    $probe = $withDrives(FakeOfficeProbe::healthy($secret), ['fingerprint' => 'alt', 'files_external' => true, 'smb_available' => true]);
    $probe->responses[DRIVES_URL] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'ok'])];
    $result = $health($probe, drivesService($pdo, $probe))->check();
    Assert::same(OfficeHealthService::OK, $result['components']['drives']['status']);
    Assert::same(1, $pushes($probe));

    // Veraltete Nextcloud-App ohne Laufwerksstatus.
    $probe = FakeOfficeProbe::healthy($secret);
    $result = $health($probe, drivesService($pdo, $probe))->check();
    Assert::contains('veraltet', $result['components']['drives']['message']);
    Assert::same('ok', $result['state']);
});
