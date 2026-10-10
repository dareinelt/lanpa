<?php

declare(strict_types=1);

use App\Controllers\Admin\OrvantaSharedMailboxController;
use App\Controllers\OrvantaApiController;
use App\Core\View;
use App\Repositories\OrvantaSharedMailboxRepository;
use App\Services\LdapClient;
use App\Services\Orvanta\OrvantaDelegateDirectory;
use App\Services\Orvanta\OrvantaSharedMailboxService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Schema parallel zu den Migrationen 046 und 049. NOW() aus MySQL wird als Funktion
 * nachgereicht, der MySQL-Upsert in die SQLite-Schreibweise uebersetzt; die
 * eindeutige Zuordnung ist wie in MySQL (utf8mb4_unicode_ci) ohne Beachtung der
 * Gross-/Kleinschreibung.
 */
function sharedMailboxPdo(): PDO
{
    $pdo = new class ('sqlite::memory:') extends Pdo\Sqlite {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            // MySQL laeuft ohne Emulation (PDO::ATTR_EMULATE_PREPARES => false)
            // und lehnt die Wiederverwendung eines benannten Platzhalters ab;
            // SQLite akzeptiert sie. Die Pruefung macht den Unterschied sichtbar.
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $query, $placeholders);
            foreach (array_count_values($placeholders[0]) as $placeholder => $count) {
                if ($count > 1) {
                    throw new PDOException('SQLSTATE[HY093]: Invalid parameter number (' . $placeholder . ' mehrfach verwendet)');
                }
            }

            if (str_contains($query, 'ON DUPLICATE KEY UPDATE')) {
                $query = (string) preg_replace('~ON DUPLICATE KEY UPDATE.*$~s', '', $query);
                $rewritten = false;
                $query = (string) preg_replace_callback(
                    '~INSERT INTO (\w+) \(([^)]*)\)\s*VALUES \(([^)]*)\)~s',
                    static function (array $matches) use (&$rewritten): string {
                        $rewritten = true;

                        // MySQL behaelt beim Upsert die Kennung der vorhandenen
                        // Zeile; die Ersetzung uebernimmt sie ausdruecklich.
                        return 'INSERT OR REPLACE INTO ' . $matches[1] . ' (id, ' . $matches[2] . ') VALUES ('
                            . 'COALESCE((SELECT id FROM ' . $matches[1] . ' WHERE user_uid = :uid AND email = :email), NULL), '
                            . $matches[3] . ')';
                    },
                    $query,
                    1
                );
                if (!$rewritten) {
                    throw new RuntimeException('Upsert liess sich nicht uebersetzen: ' . $query);
                }
            }

            return parent::prepare($query, $options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
    $pdo->exec('CREATE TABLE orvanta_shared_mailboxes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(190) NOT NULL COLLATE NOCASE,
        email VARCHAR(190) NOT NULL COLLATE NOCASE,
        display_name VARCHAR(190) NOT NULL DEFAULT \'\',
        send_as INTEGER NOT NULL DEFAULT 1,
        active INTEGER NOT NULL DEFAULT 1,
        verified_at DATETIME NULL,
        verify_error VARCHAR(500) NOT NULL DEFAULT \'\',
        checked_at DATETIME NULL,
        calendar_visible INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_uid, email)
    )');
    $pdo->exec('CREATE TABLE identity_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_key VARCHAR(32) NOT NULL,
        label VARCHAR(100) NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE phonebook (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identity_source_id INTEGER NOT NULL DEFAULT 0,
        samaccount_name VARCHAR(190) NULL,
        display_name VARCHAR(190) NOT NULL DEFAULT \'\',
        email VARCHAR(190) NULL,
        active INTEGER NOT NULL DEFAULT 1
    )');

    return $pdo;
}

/**
 * @param array<string,mixed> $overrides
 */
function sharedMailboxRow(PDO $pdo, array $overrides = []): int
{
    $row = $overrides + [
        'uid' => 'dreinelt',
        'email' => 'team@demo.local',
        'display_name' => 'Team Postfach',
        'send_as' => 1,
        'active' => 1,
        'sort_order' => 1,
        'verified_at' => null,
        'verify_error' => '',
        'checked_at' => null,
        'calendar_visible' => 0,
    ];
    $statement = $pdo->prepare(
        'INSERT INTO orvanta_shared_mailboxes (user_uid, email, display_name, send_as, active, sort_order, verified_at, verify_error, checked_at, calendar_visible)
         VALUES (:uid, :email, :display_name, :send_as, :active, :sort_order, :verified_at, :verify_error, :checked_at, :calendar_visible)'
    );
    $statement->execute([
        'uid' => $row['uid'],
        'email' => $row['email'],
        'display_name' => $row['display_name'],
        'send_as' => (int) $row['send_as'],
        'active' => (int) $row['active'],
        'sort_order' => (int) $row['sort_order'],
        'verified_at' => $row['verified_at'],
        'verify_error' => $row['verify_error'],
        'checked_at' => $row['checked_at'],
        'calendar_visible' => (int) $row['calendar_visible'],
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string,string> $settings
 * @return array{service:OrvantaSharedMailboxService,repository:OrvantaSharedMailboxRepository,pdo:PDO,transport:RecordingExchangeTransport}
 */
function sharedMailboxSetup(array $settings = [], ?OrvantaDelegateDirectory $directory = null): array
{
    $pdo = sharedMailboxPdo();
    $parts = orvantaExchange($settings);
    $repository = new OrvantaSharedMailboxRepository($pdo);

    return [
        'service' => new OrvantaSharedMailboxService($repository, $parts['exchange'], null, $directory),
        'repository' => $repository,
        'pdo' => $pdo,
        'transport' => $parts['transport'],
    ];
}

/** Telefonbucheintrag des Benutzers der Hauptquelle (Postfachadresse fuer die Pruefung). */
function sharedMailboxPhonebook(PDO $pdo, string $account = 'dreinelt', string $email = 'dreinelt@demo.local', int $source = 0): void
{
    $statement = $pdo->prepare('INSERT INTO phonebook (identity_source_id, samaccount_name, display_name, email, active) VALUES (:source, :account, :name, :email, 1)');
    $statement->execute(['source' => $source, 'account' => $account, 'name' => $account, 'email' => $email]);
}

/**
 * EWS-Antwort mit Fehlercode fuer eine Operation.
 *
 * @return array{status:int,error:null,body:string}
 */
function sharedMailboxEwsError(string $operation, string $code): array
{
    return ['status' => 200, 'error' => null, 'body' => '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">'
        . '<m:' . $operation . 'Response><m:ResponseMessages><m:' . $operation . 'ResponseMessage ResponseClass="Error"><m:MessageText>Fehler</m:MessageText><m:ResponseCode>' . $code . '</m:ResponseCode></m:' . $operation . 'ResponseMessage></m:ResponseMessages></m:' . $operation . 'Response></s:Body></s:Envelope>'];
}

/** Bestaetigte Zuordnung (Pruefzeitpunkt jetzt). */
function sharedMailboxVerifiedRow(PDO $pdo, array $overrides = []): int
{
    $now = date('Y-m-d H:i:s');

    return sharedMailboxRow($pdo, $overrides + ['verified_at' => $now, 'checked_at' => $now]);
}

Runner::test('Orvanta: Office-Kennung verbindet Anmeldung und Zuordnung', function (): void {
    $service = sharedMailboxSetup()['service'];

    Assert::same('dreinelt', $service->uid(['username' => 'dreinelt']));
    Assert::same('dreinelt', $service->uid(['office_uid' => 'dreinelt', 'username' => 'anderer']), 'Die Office-Kennung der Sitzung hat Vorrang.');
    Assert::same('mueller@zweig', $service->uid(['username' => 'mueller', 'source_key' => 'ZWEIG']), 'Weitere Identitaetsquellen werden mit ihrer Kennung ergaenzt.');
    Assert::same('', $service->uid(['username' => '']), 'Ohne Benutzernamen gibt es keine Zuordnung.');
});

Runner::test('Orvanta: nur bestätigte und aktive Postfächer stehen zur Verfügung', function (): void {
    $setup = sharedMailboxSetup();
    $pdo = $setup['pdo'];
    sharedMailboxVerifiedRow($pdo, ['email' => 'team@demo.local', 'sort_order' => 2]);
    sharedMailboxVerifiedRow($pdo, ['email' => 'buero@demo.local', 'display_name' => '', 'sort_order' => 1, 'send_as' => 0]);
    sharedMailboxVerifiedRow($pdo, ['email' => 'archiv@demo.local', 'sort_order' => 0, 'active' => 0]);
    sharedMailboxRow($pdo, ['email' => 'fremd@demo.local', 'verify_error' => 'Das Postfach konnte nicht geoeffnet werden.', 'checked_at' => date('Y-m-d H:i:s')]);
    sharedMailboxVerifiedRow($pdo, ['uid' => 'mueller', 'email' => 'andere@demo.local']);

    $mailboxes = $setup['service']->available(['username' => 'DREINELT']);
    Assert::same(2, count($mailboxes), 'Nur aktive und über EWS bestätigte Postfächer des Benutzers erscheinen.');
    Assert::same(['buero@demo.local', 'team@demo.local'], array_column($mailboxes, 'email'), 'Die Reihenfolge folgt der Sortierung.');
    Assert::same('buero@demo.local', $mailboxes[0]['name'], 'Ohne Anzeigename wird die Adresse angezeigt.');
    Assert::same('Team Postfach', $mailboxes[1]['name']);
    Assert::same(true, $mailboxes[1]['send_as']);
    Assert::same(false, $mailboxes[0]['send_as']);
    Assert::same([], $setup['service']->available(['username' => '']), 'Ohne Office-Kennung gibt es keine Postfächer.');
});

Runner::test('Orvanta: Postfachkennung auflösen (Kennung, Adresse, unbekannt)', function (): void {
    $setup = sharedMailboxSetup();
    $teamId = sharedMailboxVerifiedRow($setup['pdo'], ['email' => 'team@demo.local']);
    sharedMailboxRow($setup['pdo'], ['email' => 'fremd@demo.local']);

    $user = ['username' => 'dreinelt'];
    Assert::same('team@demo.local', $setup['service']->resolve($user, (string) $teamId)['email'], 'Die Kennung aus dem Ordnerbaum löst auf.');
    Assert::same('team@demo.local', $setup['service']->resolve($user, 'TEAM@demo.local')['email'], 'Auch die Adresse wird akzeptiert.');
    Assert::null($setup['service']->resolve($user, ''), 'Ohne Angabe bleibt es beim eigenen Postfach.');
    Assert::null($setup['service']->resolve($user, 'unbekannt@demo.local'), 'Unbekannte Postfächer sind nicht erlaubt.');
    Assert::null($setup['service']->resolve($user, 'fremd@demo.local'), 'Unbestätigte Postfächer sind nicht erlaubt.');
    Assert::null($setup['service']->resolve(['username' => 'mueller'], (string) $teamId), 'Fremde Zuordnungen sind nicht erlaubt.');
});

Runner::test('Orvanta: Absenderadresse im Verfassen-Dialog', function (): void {
    $setup = sharedMailboxSetup();
    $pdo = $setup['pdo'];
    sharedMailboxVerifiedRow($pdo, ['email' => 'team@demo.local', 'display_name' => 'Team Postfach', 'send_as' => 1]);
    sharedMailboxVerifiedRow($pdo, ['email' => 'buero@demo.local', 'display_name' => 'Büro', 'send_as' => 0]);

    $service = $setup['service'];
    $user = ['username' => 'dreinelt'];
    $own = 'dreinelt@example.local';

    Assert::same(['email' => '', 'name' => ''], $service->sender($user, $own, ''), 'Ohne Auswahl wird aus dem eigenen Postfach gesendet.');
    Assert::same(['email' => '', 'name' => ''], $service->sender($user, $own, 'DREINELT@example.local'), 'Das eigene Postfach bleibt das eigene.');
    Assert::same(['email' => 'team@demo.local', 'name' => 'Team Postfach'], $service->sender($user, $own, 'Team@Demo.Local'), 'Mit "Senden als" ist die Adresse wählbar.');
    Assert::null($service->sender($user, $own, 'buero@demo.local'), 'Ohne "Senden als" ist die Adresse kein Absender.');
    Assert::null($service->sender($user, $own, 'fremd@demo.local'), 'Unbekannte Adressen sind kein Absender.');
});

Runner::test('Orvanta: Kalender zusätzlicher Postfächer per Kontrollkästchen', function (): void {
    $setup = sharedMailboxSetup();
    $pdo = $setup['pdo'];
    $teamId = sharedMailboxVerifiedRow($pdo, ['email' => 'team@demo.local']);
    $bueroId = sharedMailboxVerifiedRow($pdo, ['email' => 'buero@demo.local', 'calendar_visible' => 1]);
    sharedMailboxRow($pdo, ['email' => 'fremd@demo.local', 'calendar_visible' => 1]);

    $service = $setup['service'];
    $user = ['username' => 'dreinelt'];

    Assert::same(
        ['hidden' => [(string) $teamId], 'visible' => [(string) $bueroId]],
        $service->calendarSelection($user),
        'Standardmäßig ist der Kalender ausgeblendet; die Checkbox entscheidet.'
    );

    Assert::true($service->setCalendarVisible($user, $teamId, true), 'Die eigene Zuordnung lässt sich einblenden.');
    Assert::same([], $service->calendarSelection($user)['hidden']);
    Assert::same([(string) $bueroId, (string) $teamId], $service->calendarSelection($user)['visible']);

    Assert::true($service->setCalendarVisible($user, $teamId, false), 'Das Ausblenden gelingt ebenfalls.');
    Assert::same([(string) $teamId], $service->calendarSelection($user)['hidden']);

    $foreign = (int) $pdo->query("SELECT id FROM orvanta_shared_mailboxes WHERE email = 'fremd@demo.local'")->fetchColumn();
    Assert::false($service->setCalendarVisible(['username' => 'mueller'], $foreign, true), 'Fremde Zuordnungen lassen sich nicht umschalten.');
    Assert::same(1, (int) $pdo->query('SELECT calendar_visible FROM orvanta_shared_mailboxes WHERE id = ' . $foreign)->fetchColumn(), 'Die fremde Zuordnung bleibt unverändert.');
    Assert::false($service->setCalendarVisible($user, 999, true), 'Unbekannte Zuordnungen lassen sich nicht umschalten.');
});

Runner::test('Orvanta: Erreichbarkeit zusätzlicher Postfächer prüft Orvanta über EWS', function (): void {
    $setup = sharedMailboxSetup();
    $service = $setup['service'];
    sharedMailboxPhonebook($setup['pdo']);

    $teamId = sharedMailboxRow($setup['pdo'], ['email' => 'team@demo.local', 'display_name' => 'Team Postfach']);
    Assert::same('', $service->verify($teamId, true), 'Das Beispielpostfach team@demo.local ist erreichbar.');
    $row = $setup['repository']->find($teamId);
    Assert::true($row['verified'], 'Ein bestätigtes Postfach gilt als erreichbar.');
    Assert::same('', $row['verify_error']);
    Assert::true($row['checked_at'] !== '', 'Der Prüfzeitpunkt wird festgehalten.');

    $bueroId = sharedMailboxRow($setup['pdo'], ['email' => 'buero@demo.local', 'display_name' => 'Büro', 'send_as' => 0, 'sort_order' => 2]);
    Assert::same('', $service->verify($bueroId, true), 'Das Beispielpostfach buero@demo.local ist erreichbar.');

    $fremdId = sharedMailboxRow($setup['pdo'], ['email' => 'fremd@example.local', 'display_name' => 'Fremdes Postfach']);
    $error = $service->verify($fremdId, true);
    Assert::true($error !== '', 'Ein unbekanntes Postfach gilt als nicht erreichbar; der Fehler wird gemeldet.');
    $row = $setup['repository']->find($fremdId);
    Assert::false($row['verified']);
    Assert::same($error, $row['verify_error'], 'Der Fehlertext steht in der Zuordnung.');
    Assert::true($row['checked_at'] !== '', 'Auch eine gescheiterte Prüfung vermerkt den Zeitpunkt.');

    Assert::same(2, count($service->available(['username' => 'dreinelt'])), 'Nur erreichbare Postfächer erscheinen im Ordnerbaum.');
    Assert::same(3, count($service->forAdmin('')), 'Im Adminbereich bleibt die Zuordnung sichtbar.');

    // Eine erzwungene Prüfung liefert das Ergebnis erneut.
    Assert::same('', $service->verify($teamId, true));
    Assert::same('Zuordnung nicht gefunden.', $service->verify(999, true), 'Unbekannte Zuordnungen melden sich verständlich.');
});

/**
 * AD-Abfrage der automatisch eingebundenen Postfaecher durch eine feste
 * Antwort ersetzen (null = nicht ermittelbar).
 *
 * @param list<array{email:string,name:string}>|null $mailboxes
 */
function sharedMailboxDirectory(?array &$mailboxes, array &$calls = []): OrvantaDelegateDirectory
{
    $_SESSION = [];
    $config = orvantaConfig(['exchange_host' => 'exchange.example.local'])['config'];

    return new OrvantaDelegateDirectory($config, orvantaIdentitySources(), null, static function (array $source, string $username) use (&$mailboxes, &$calls): ?array {
        $calls[] = ['source' => (int) ($source['id'] ?? -1), 'username' => $username];

        return $mailboxes;
    });
}

Runner::test('Orvanta: Postfächer mit Auto-Mapping werden aus dem AD übernommen', function (): void {
    $mailboxes = [
        ['email' => 'Team@Demo.Local', 'name' => 'Team Postfach'],
        ['email' => 'fremd@example.local', 'name' => ''],
        ['email' => 'team@demo.local', 'name' => 'Doppelt'],
    ];
    $calls = [];
    $setup = sharedMailboxSetup([], sharedMailboxDirectory($mailboxes, $calls));
    $service = $setup['service'];
    $repository = $setup['repository'];
    $user = ['username' => 'dreinelt', 'source_id' => 0, 'id' => 7];

    // Vorhandene Zeile mit eingeblendetem Kalender bleibt erhalten; ein nicht mehr berechtigtes Postfach verschwindet.
    $manual = sharedMailboxRow($setup['pdo'], ['email' => 'team@demo.local', 'display_name' => 'Altes Team', 'active' => 0, 'calendar_visible' => 1, 'sort_order' => 5]);
    sharedMailboxRow($setup['pdo'], ['email' => 'buero@demo.local', 'display_name' => 'Büro']);

    $service->refresh($user, 'dreinelt@demo.local');

    Assert::same([['source' => 0, 'username' => 'dreinelt']], $calls, 'Das AD wird mit der Quelle und dem Anmeldenamen des Benutzers abgefragt.');
    $rows = $repository->forUser('dreinelt', false);
    Assert::same(2, count($rows), 'Doppelte Adressen aus dem AD werden zusammengefasst, nicht gelistete Postfächer entfernt.');
    $byEmail = [];
    foreach ($rows as $row) {
        $byEmail[$row['email']] = $row;
    }
    Assert::same($manual, $byEmail['team@demo.local']['id'], 'Die vorhandene Zeile wird weitergeführt.');
    Assert::true($byEmail['team@demo.local']['active'], 'Im AD gelistete Postfächer sind aktiv.');
    Assert::same(1, $byEmail['team@demo.local']['sort_order'], 'Die Reihenfolge folgt dem AD.');
    Assert::true($byEmail['team@demo.local']['calendar_visible'], 'Die Kalender-Auswahl des Benutzers bleibt erhalten.');
    Assert::same('Team Postfach', $byEmail['team@demo.local']['display_name'], 'Der Anzeigename kommt aus dem AD.');
    Assert::true($byEmail['team@demo.local']['verified'], 'Neue Postfächer werden anschließend über EWS geprüft.');
    Assert::true($byEmail['fremd@example.local']['send_as'], 'Aus dem AD übernommene Postfächer stehen mit „Senden als“ bereit; Exchange prüft den Versand.');
    Assert::false($byEmail['fremd@example.local']['verified'], 'Ein von Exchange abgelehntes Postfach bleibt ausgeblendet.');
    Assert::false(isset($byEmail['buero@demo.local']), 'Ein im AD nicht gelistetes Postfach wird entfernt – das AD ist die einzige Quelle.');

    $available = array_map(static fn (array $mailbox): string => $mailbox['email'], $service->available($user));
    sort($available);
    Assert::same(['team@demo.local'], $available, 'Im Ordnerbaum stehen alle bestätigten Postfächer.');

    // Wird der Vollzugriff im ECP entzogen, verschwindet das Postfach.
    $mailboxes = [['email' => 'fremd@example.local', 'name' => 'Fremd']];
    $_SESSION = [];
    $service->refresh($user, 'dreinelt@demo.local');
    $emails = array_map(static fn (array $row): string => $row['email'], $repository->forUser('dreinelt', false));
    sort($emails);
    Assert::same(['fremd@example.local'], $emails, 'Nicht mehr gelistete Postfächer werden entfernt.');
    Assert::same('Fremd', $repository->find($byEmail['fremd@example.local']['id'])['display_name'], 'Der Anzeigename folgt dem AD.');
    Assert::same(2, count($calls), 'Je Sitzung wird das AD höchstens einmal je Gültigkeitsdauer abgefragt.');

    // Antwort aus der Sitzung: kein weiterer AD-Zugriff.
    $service->refresh($user, 'dreinelt@demo.local');
    Assert::same(2, count($calls));
});

Runner::test('Orvanta: ohne AD-Antwort bleibt der Bestand der Postfächer unverändert', function (): void {
    $mailboxes = null;
    $setup = sharedMailboxSetup([], sharedMailboxDirectory($mailboxes));
    $id = sharedMailboxVerifiedRow($setup['pdo'], ['email' => 'team@demo.local']);

    Assert::false($setup['service']->syncFromDirectory(['username' => 'dreinelt', 'source_id' => 0, 'id' => 7]));
    Assert::same('team@demo.local', $setup['repository']->find($id)['email'] ?? '', 'Ohne Ergebnis wird nichts entfernt.');

    // Demomodus und Testbenutzer fragen das AD nicht.
    $mailboxes = [];
    $demo = sharedMailboxSetup([], new OrvantaDelegateDirectory(orvantaConfig()['config'], orvantaIdentitySources(), null, static fn (): array => []));
    sharedMailboxVerifiedRow($demo['pdo'], ['email' => 'team@demo.local']);
    Assert::false($demo['service']->syncFromDirectory(['username' => 'dreinelt', 'source_id' => 0, 'id' => 7]));
    Assert::same(1, count($demo['repository']->forUser('dreinelt')));

    $_SESSION = [];
    $fake = sharedMailboxDirectory($mailboxes);
    Assert::same(null, $fake->mailboxes(['username' => 'dreinelt', 'fake' => true, 'id' => 0]), 'Testbenutzer ohne Telefonbucheintrag existieren im AD nicht.');
    Assert::same(null, $fake->mailboxes(['username' => 'dreinelt', 'source_id' => 99]), 'Unbekannte Quelle: nicht ermittelbar.');
});

Runner::test('Orvanta: automatisch eingebundenes Postfach aus dem LDAP-Eintrag', function (): void {
    $entry = ['proxyaddresses' => ['count' => 2, 'smtp:alias@demo.local', 'SMTP:Team@Demo.Local'], 'displayname' => ['count' => 1, ' Team ']];
    Assert::same(['email' => 'team@demo.local', 'name' => 'Team'], LdapClient::delegatedMailboxFromEntry($entry));
    Assert::same(null, LdapClient::delegatedMailboxFromEntry(['proxyaddresses' => ['count' => 1, 'smtp:nur-alias@demo.local']]), 'Ohne primäre SMTP-Adresse kein Postfach.');
});

Runner::test('Orvanta: Prüfung wird erst nach Ablauf der Gültigkeit erneuert', function (): void {
    $setup = sharedMailboxSetup();
    $service = $setup['service'];
    $id = sharedMailboxRow($setup['pdo'], ['email' => 'team@demo.local']);

    $service->refresh(['username' => 'dreinelt'], 'dreinelt@demo.local');
    Assert::same(1, count($setup['transport']->requests), 'Ohne Prüfzeitpunkt wird geprüft.');
    Assert::true($setup['repository']->find($id)['verified'], 'Die Prüfung bestätigt das Postfach.');

    $service->refresh(['username' => 'dreinelt'], 'dreinelt@demo.local');
    Assert::same(1, count($setup['transport']->requests), 'Innerhalb der Gültigkeit wird nicht erneut geprüft.');

    $setup['pdo']->exec("UPDATE orvanta_shared_mailboxes SET checked_at = '2020-01-01 00:00:00' WHERE id = " . $id);
    $service->refresh(['username' => 'dreinelt'], 'dreinelt@demo.local');
    Assert::same(2, count($setup['transport']->requests), 'Nach Ablauf der Gültigkeit wird erneut geprüft.');
    Assert::true($setup['repository']->find($id)['verified'], 'Die erneute Prüfung bestätigt das Postfach wieder.');

    // Inaktive Zuordnungen werden nicht geprüft.
    sharedMailboxRow($setup['pdo'], ['email' => 'buero@demo.local', 'active' => 0]);
    $service->refresh(['username' => 'dreinelt'], 'dreinelt@demo.local');
    Assert::same(2, count($setup['transport']->requests), 'Inaktive Zuordnungen werden nicht geprüft.');
});

Runner::test('Orvanta: Adminübersicht führt die Zuordnungen aller Benutzer', function (): void {
    $setup = sharedMailboxSetup();
    $pdo = $setup['pdo'];
    $now = date('Y-m-d H:i:s');
    $teamId = sharedMailboxVerifiedRow($pdo, ['email' => 'team@demo.local', 'display_name' => 'Team Postfach', 'sort_order' => 3, 'calendar_visible' => 1]);
    sharedMailboxRow($pdo, ['uid' => 'mueller', 'email' => 'buero@demo.local', 'active' => 0, 'verify_error' => 'Nicht erreichbar.', 'checked_at' => $now]);

    $all = $setup['service']->forAdmin('');
    Assert::same(2, count($all));
    Assert::same(['dreinelt', 'mueller'], array_column($all, 'uid'));
    Assert::same($teamId, $all[0]['id']);
    Assert::same('team@demo.local', $all[0]['email']);
    Assert::same(3, $all[0]['sort_order']);
    Assert::true($all[0]['calendar_visible'], 'Die Kalenderauswahl des Benutzers ist sichtbar.');
    Assert::true($all[0]['verified']);
    Assert::same($now, $all[0]['checked_at']);
    Assert::false($all[1]['active']);
    Assert::false($all[1]['verified']);
    Assert::same('Nicht erreichbar.', $all[1]['error']);

    $one = $setup['service']->forAdmin('MUELLER');
    Assert::same(1, count($one), 'Die Kennung filtert ohne Beachtung der Schreibweise.');
    Assert::same('buero@demo.local', $one[0]['email']);
});

Runner::test('Orvanta: Demo liefert zusätzliche Postfächer getrennt vom eigenen', function (): void {
    $exchange = orvantaExchange()['exchange'];

    $own = $exchange->folders('dreinelt@demo.local');
    $team = $exchange->folders('team@demo.local');
    Assert::same(count($own), count($team), 'Das zusätzliche Postfach hat denselben Ordnerbaum.');
    Assert::same([], array_filter(array_column($team, 'id'), static fn (string $id): bool => !str_contains($id, '@team-demo-local')), 'Die Ordnerkennungen bleiben je Postfach eindeutig.');
    Assert::same([], array_filter(array_column($own, 'id'), static fn (string $id): bool => str_contains($id, '@')), 'Das eigene Postfach trägt kein Suffix.');

    Assert::true($exchange->probeMailbox('team@demo.local')['ok'], 'Das Beispielpostfach mit "Senden als" ist erreichbar.');
    Assert::true($exchange->probeMailbox('buero@demo.local')['ok'], 'Das Beispielpostfach ohne "Senden als" ist erreichbar.');
    Assert::false($exchange->probeMailbox('fremd@example.local')['ok'], 'Unbekannte Postfächer sind nicht erreichbar.');
    Assert::false($exchange->probeMailbox('keine-adresse')['ok'], 'Ungültige Adressen sind nicht erreichbar.');
});

Runner::test('Orvanta: Ordner zusätzlicher Postfächer hängen unter dem Wurzelknoten', function (): void {
    $exchange = orvantaExchange()['exchange'];
    $root = OrvantaApiController::MAILBOX_PREFIX . '3|';
    $prepared = OrvantaApiController::mailboxFolders($exchange->folders('team@demo.local'), $root, 3);
    $ids = array_column($prepared, 'id');

    Assert::same([], array_values(array_filter($ids, static fn (string $id): bool => !str_starts_with($id, $root))), 'Jede Ordnerkennung trägt das Präfix des Postfachs.');
    Assert::same([], array_values(array_filter(array_column($prepared, 'parent'), static fn (string $parent): bool => $parent !== $root && !in_array($parent, $ids, true))), 'Als Elternordner steht nur der Wurzelknoten oder ein Ordner der Liste.');
    Assert::true(count(array_filter(array_column($prepared, 'parent'), static fn (string $parent): bool => $parent === $root)) > 0, 'Die oberste Ebene hängt am Wurzelknoten des Postfachs.');
    Assert::same([3], array_values(array_unique(array_column($prepared, 'mailbox'))), 'Jeder Ordner trägt die Kennung des Postfachs.');

    // Der eigene Stammordner kommt aus dem Elternverweis: Ordner der obersten
    // Ebene tragen den des Postfachs, Unterordner bleiben untereinander.
    $nested = OrvantaApiController::mailboxFolders([
        ['id' => 'f1', 'name' => 'Posteingang', 'parent' => 'msgfolderroot', 'kind' => 'inbox'],
        ['id' => 'f2', 'name' => 'Projekte', 'parent' => 'f1', 'kind' => 'folder'],
        ['id' => 'f3', 'name' => 'Ablage', 'parent' => '', 'kind' => 'folder'],
    ], $root, 3);
    Assert::same($root . 'f1', $nested[0]['id']);
    Assert::same($root, $nested[0]['parent'], 'Die oberste Ebene hängt am Wurzelknoten des Postfachs.');
    Assert::same($root, $nested[2]['parent'], 'Ohne Elternverweis (Proxy-Postfach) gilt der Wurzelknoten.');
    Assert::same($root . 'f1', $nested[1]['parent'], 'Unterordner bleiben unter ihrem Elternordner.');
});

Runner::test('Orvanta: Absenderfeld im Verfassen-Dialog ist am Label verankert', function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $html = View::render('orvanta.index', [
        'orvanta' => [
            'user' => ['name' => 'Erika Muster', 'email' => 'erika.muster@firma.local', 'username' => 'emuster'],
            'backend' => 'exchange',
            'capabilities' => [],
            'defaultModule' => 'mail',
            'exchangeHost' => 'mail02.example.local',
            'owaUrl' => '',
            'demo' => false,
            'cacheFolder' => 'Orvanta',
            'aiAvailable' => false,
            'spellcheckAvailable' => false,
        ],
        'csrfToken' => 'token',
        'assetVersion' => '1',
    ]);

    // Das Merkmal darf nur am Label stehen: traegt auch das select es, findet
    // closest() das select selbst und blendet nur dieses aus.
    Assert::contains('<label class="ov-field" data-ov-compose-from hidden>', $html, 'Das Absenderfeld ist ein Label mit Merkmal.');
    Assert::contains('<select name="from" aria-label="Absenderadresse">', $html, 'Das Auswahlfeld traegt das Merkmal nicht selbst.');
});

Runner::test('Orvanta: Adminseite für zusätzliche Postfächer', function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $data = [
        'entries' => [],
        'orvantaEnabled' => true,
        'orvantaDemo' => true,
        'tablesMissing' => false,
        'base' => OrvantaSharedMailboxController::BASE,
    ];

    $html = View::render('admin.orvanta-shared-mailboxes', $data);
    Assert::contains('Archiviert wird weiterhin nur das primäre Benutzerpostfach', $html, 'Die Archivierungsregel steht auf der Seite.');
    Assert::contains('team@demo.local', $html, 'Der Demomodus nennt die prüfbaren Beispielpostfächer.');
    Assert::contains('Noch keine zusätzlichen Postfächer übernommen.', $html);
    Assert::contains('ausschließlich im Exchange (ECP) gepflegt', $html, 'Die Seite erklärt, dass die Zuordnung nur in Exchange erfolgt.');
    Assert::contains('msExchDelegateListBL', $html, 'Die Seite erklärt die Übernahme aus dem AD.');
    Assert::false(str_contains($html, 'postfaecher/speichern'), 'Es gibt kein Formular zum Zuordnen.');
    Assert::false(str_contains($html, 'postfaecher/loeschen'), 'Es gibt keine Schaltfläche zum Entfernen.');
    Assert::false(str_contains($html, 'name="postfach"'), 'Es gibt kein Eingabefeld für Postfächer.');
    Assert::false(str_contains($html, 'style="'), 'Die Seite kommt ohne Inline-Stile aus.');

    // Tabelle mit einer Zuordnung: Werte werden escaped, nur die Prüfung lässt sich anstoßen.
    $entry = [
        'id' => 7,
        'uid' => 'dreinelt<script>',
        'email' => 'team@demo.local',
        'name' => 'Team & Co',
        'send_as' => true,
        'active' => false,
        'sort_order' => 2,
        'calendar_visible' => false,
        'verified' => false,
        'error' => 'Das Postfach konnte nicht geoeffnet werden.',
        'checked_at' => '2026-02-01 09:30:00',
    ];
    $html = View::render('admin.orvanta-shared-mailboxes', ['entries' => [$entry]] + $data);
    Assert::contains('dreinelt&lt;script&gt;', $html);
    Assert::contains('Team &amp; Co', $html);
    Assert::false(str_contains($html, '<script>'), 'Die Ausgabe ist escaped.');
    Assert::contains('nicht erreichbar', $html);
    Assert::contains('Das Postfach konnte nicht geoeffnet werden.', $html);
    Assert::contains('ausgeblendet', $html, 'Der Kalenderzustand steht in der Übersicht.');
    Assert::contains('action="/admin/office/orvanta/postfaecher/pruefen"', $html, 'Die Prüfung lässt sich anstoßen.');
    Assert::contains('<input type="hidden" name="id" value="7">', $html);

    $html = View::render('admin.orvanta-shared-mailboxes', [
        'entries' => [array_replace($entry, ['verified' => true, 'checked_at' => '2026-02-01 09:30:00', 'error' => ''])],
    ] + $data);
    Assert::contains('erreichbar (01.02.2026 09:30)', $html, 'Der Prüfzeitpunkt wird lesbar angezeigt.');

    $html = View::render('admin.orvanta-shared-mailboxes', ['tablesMissing' => true] + $data);
    Assert::contains('Migration 046', $html, 'Ohne Tabelle weist die Seite auf die Migration hin.');

    $html = View::render('admin.orvanta-shared-mailboxes', ['orvantaEnabled' => false, 'orvantaDemo' => false] + $data);
    Assert::contains('nicht aktiviert', $html);
});

Runner::test('Orvanta: Postfachprüfung läuft mit den Rechten des Benutzers', function (): void {
    $setup = sharedMailboxSetup();
    sharedMailboxPhonebook($setup['pdo']);
    $id = sharedMailboxRow($setup['pdo'], ['email' => 'team@demo.local', 'display_name' => 'Team Postfach']);
    Assert::same('', $setup['service']->verify($id, true));

    $xml = $setup['transport']->last();
    Assert::contains('<t:PrimarySmtpAddress>dreinelt@demo.local</t:PrimarySmtpAddress>', $xml, 'Die Prüfung gibt sich als Benutzer aus, nicht als Dienstkonto.');
    Assert::contains('<t:DistinguishedFolderId Id="msgfolderroot"><t:Mailbox><t:EmailAddress>team@demo.local</t:EmailAddress></t:Mailbox>', $xml, 'Geprüft wird der Zugriff auf das zusätzliche Postfach.');

    // Verweigert Exchange dem Benutzer den Zugriff, nennt die Meldung den Vollzugriff.
    $setup['transport']->forced = sharedMailboxEwsError('GetFolder', 'ErrorAccessDenied');
    $denied = $setup['service']->verify($id, true);
    Assert::contains('keinen Vollzugriff', $denied);
    Assert::false($setup['repository']->find($id)['verified']);
});

Runner::test('Orvanta: ohne Postfachadresse des Benutzers bleibt die Prüfung offen', function (): void {
    $setup = sharedMailboxSetup();
    $id = sharedMailboxRow($setup['pdo'], ['email' => 'team@demo.local', 'display_name' => 'Team Postfach']);
    Assert::same(OrvantaSharedMailboxService::PENDING, $setup['service']->verify($id, true));
    Assert::same(0, count($setup['transport']->requests), 'Ohne Benutzeradresse wird Exchange nicht befragt.');
    $row = $setup['repository']->find($id);
    Assert::false($row['verified']);
    Assert::same('', $row['checked_at'], 'Die Prüfung wird bei der nächsten Anmeldung nachgeholt.');

    // Bei der Anmeldung liefert Orvanta die Adresse des Benutzers mit.
    $setup['service']->refresh(['username' => 'dreinelt'], 'dreinelt@demo.local');
    Assert::true($setup['repository']->find($id)['verified']);

    // Benutzer weiterer Quellen werden über die Quellenkennung gefunden.
    $setup['pdo']->exec("INSERT INTO identity_sources (id, source_key, label) VALUES (3, 'zweig', 'Zweigstelle Nord')");
    sharedMailboxPhonebook($setup['pdo'], 'mmuster', 'muster@zweig.local', 3);
    sharedMailboxPhonebook($setup['pdo'], 'mmuster', 'muster@haupt.local');
    Assert::same('muster@zweig.local', $setup['repository']->userAddress('mmuster@ZWEIG'));
    Assert::same('muster@haupt.local', $setup['repository']->userAddress('MMuster'));
    Assert::same('', $setup['repository']->userAddress('unbekannt'));
});

Runner::test('Orvanta: Senden aus weiteren Postfächern (Gesendete Elemente, „Senden als“)', function (): void {
    $parts = orvantaExchange();
    $parts['exchange']->send('dreinelt@demo.local', [
        'to' => ['kollegin@demo.local'],
        'subject' => 'Test',
        'body' => 'Hallo',
        'from' => 'team@demo.local',
        'sent_mailbox' => 'team@demo.local',
    ]);
    $xml = $parts['transport']->last();
    Assert::contains('<t:PrimarySmtpAddress>dreinelt@demo.local</t:PrimarySmtpAddress>', $xml, 'Gesendet wird als Benutzer; Exchange prüft „Senden als“.');
    Assert::contains('<m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"><t:Mailbox><t:EmailAddress>team@demo.local</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId></m:SavedItemFolderId>', $xml, 'Die Kopie landet im Postfach des Absenders.');

    $parts['transport']->forced = sharedMailboxEwsError('CreateItem', 'ErrorSendAsDenied');
    try {
        $parts['exchange']->send('dreinelt@demo.local', ['to' => ['kollegin@demo.local'], 'subject' => 'Test', 'body' => 'Hallo', 'from' => 'team@demo.local', 'sent_mailbox' => 'team@demo.local']);
        Assert::true(false, 'Ohne „Senden als“ muss der Versand scheitern.');
    } catch (\App\Services\Orvanta\OrvantaException $exception) {
        Assert::contains('„Senden als“', $exception->getMessage());
    }
});
