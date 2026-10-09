<?php

declare(strict_types=1);

use App\Controllers\Admin\OrvantaSharedMailboxController;
use App\Core\View;
use App\Repositories\OrvantaSharedMailboxRepository;
use App\Services\Orvanta\OrvantaSharedMailboxService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Schema parallel zur Migration 046. NOW() aus MySQL wird als Funktion
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
function sharedMailboxSetup(array $settings = []): array
{
    $pdo = sharedMailboxPdo();
    $parts = orvantaExchange($settings);
    $repository = new OrvantaSharedMailboxRepository($pdo);

    return [
        'service' => new OrvantaSharedMailboxService($repository, $parts['exchange']),
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

    $team = $service->save('dreinelt', 'Team@Demo.Local', 'Team Postfach');
    Assert::true($team['ok'], 'Das Beispielpostfach team@demo.local ist erreichbar: ' . $team['error']);
    $row = $setup['repository']->find($team['id']);
    Assert::same('team@demo.local', $row['email'], 'Adressen werden klein gespeichert.');
    Assert::true($row['verified'], 'Ein bestätigtes Postfach gilt als erreichbar.');
    Assert::same('', $row['verify_error']);
    Assert::true($row['checked_at'] !== '', 'Der Prüfzeitpunkt wird festgehalten.');

    $buero = $service->save('dreinelt', 'buero@demo.local', 'Büro', ['send_as' => false, 'sort_order' => 2]);
    Assert::true($buero['ok'], 'Das Beispielpostfach buero@demo.local ist erreichbar.');

    $fremd = $service->save('dreinelt', 'fremd@example.local', 'Fremdes Postfach');
    Assert::false($fremd['ok'], 'Ein unbekanntes Postfach gilt als nicht erreichbar.');
    Assert::true($fremd['error'] !== '', 'Der Fehler der Prüfung wird gemeldet.');
    $row = $setup['repository']->find($fremd['id']);
    Assert::false($row['verified']);
    Assert::same($fremd['error'], $row['verify_error'], 'Der Fehlertext steht in der Zuordnung.');
    Assert::true($row['checked_at'] !== '', 'Auch eine gescheiterte Prüfung vermerkt den Zeitpunkt.');

    Assert::same(2, count($service->available(['username' => 'dreinelt'])), 'Nur erreichbare Postfächer erscheinen im Ordnerbaum.');
    Assert::same(3, count($service->forAdmin('')), 'Im Adminbereich bleibt die Zuordnung sichtbar.');

    // Eine erzwungene Prüfung liefert das Ergebnis erneut.
    Assert::same('', $service->verify($team['id'], true));
    Assert::same('Zuordnung nicht gefunden.', $service->verify(999, true), 'Unbekannte Zuordnungen melden sich verständlich.');
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

Runner::test('Orvanta: Zuordnung speichern prüft die Eingaben', function (): void {
    $setup = sharedMailboxSetup();
    $service = $setup['service'];
    $fail = static function (string $uid, string $email, string $name = '') use ($service): string {
        try {
            $service->save($uid, $email, $name);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return '';
    };

    Assert::contains('Benutzer', $fail('', 'team@demo.local'), 'Ohne Benutzer wird nicht gespeichert.');
    Assert::contains('E-Mail-Adresse', $fail('dreinelt', 'keine-adresse'), 'Ungültige Adressen werden abgewiesen.');
    Assert::contains('zu lang', $fail('dreinelt', 'team@demo.local', str_repeat('x', 191)), 'Zu lange Anzeigenamen werden abgewiesen.');
    Assert::same(0, (int) $setup['pdo']->query('SELECT COUNT(*) FROM orvanta_shared_mailboxes')->fetchColumn(), 'Fehlerhafte Eingaben landen nicht in der Datenbank.');

    $first = $service->save('dreinelt', 'team@demo.local', 'Team Postfach');
    Assert::true($first['id'] > 0, 'Die Zuordnung erhält eine Kennung.');

    $again = $service->save('DREINELT', 'TEAM@demo.local', 'Team Postfach neu', ['send_as' => false, 'sort_order' => 4]);
    Assert::same(1, (int) $setup['pdo']->query('SELECT COUNT(*) FROM orvanta_shared_mailboxes')->fetchColumn(), 'Eine erneute Zuordnung ersetzt die vorhandene.');
    $row = $setup['repository']->find($again['id']);
    Assert::same('Team Postfach neu', $row['display_name']);
    Assert::same(false, $row['send_as'], 'Ohne "Senden als" wird die Adresse nicht als Absender angeboten.');
    Assert::same(4, $row['sort_order']);
    Assert::null($setup['service']->sender(['username' => 'dreinelt'], 'dreinelt@example.local', 'team@demo.local'), 'Ohne "Senden als" ist die Adresse kein Absender.');

    $service->delete($first['id']);
    Assert::same(0, (int) $setup['pdo']->query('SELECT COUNT(*) FROM orvanta_shared_mailboxes')->fetchColumn(), 'Eine entfernte Zuordnung ist weg.');
});

Runner::test('Orvanta: Benutzer für die Zuordnung suchen', function (): void {
    $setup = sharedMailboxSetup();
    $pdo = $setup['pdo'];
    $pdo->exec("INSERT INTO identity_sources (id, source_key, label) VALUES (3, 'zweig', 'Zweigstelle Nord')");
    $pdo->exec("INSERT INTO phonebook (id, identity_source_id, samaccount_name, display_name, email, active) VALUES
        (1, 0, 'dreinelt', 'Daniel-André Reinelt', 'dreinelt@example.local', 1),
        (2, 3, 'mueller', 'Anna Müller', 'anna.mueller@zweig.example.local', 1),
        (3, 0, 'ausgeschieden', 'Ausgeschieden Person', '', 0),
        (4, 0, NULL, 'Ohne Kennung', 'ohne@example.local', 1)");

    $service = $setup['service'];
    $users = $service->searchUsers('müller');
    Assert::same(1, count($users));
    Assert::same('mueller@ZWEIG', $users[0]['uid'], 'Weitere Identitätsquellen werden als name@KENNUNG geführt.');
    Assert::same('Anna Müller', $users[0]['display_name']);
    Assert::same('Zweigstelle Nord', $users[0]['source']);

    Assert::same([], $service->searchUsers('m'), 'Erst ab zwei Zeichen wird gesucht.');
    Assert::same('dreinelt', $service->searchUsers('dreinelt')[0]['uid'], 'Der Benutzername wird gefunden.');
    Assert::same('Hauptquelle', $service->searchUsers('dreinelt')[0]['source'], 'Ohne Quelle gilt die Hauptquelle.');
    Assert::same('dreinelt', $service->searchUsers('DANIEL')[0]['uid'], 'Die Suche ignoriert Groß-/Kleinschreibung.');
    Assert::same('dreinelt', $service->searchUsers('dreinelt@example.local')[0]['uid'], 'Auch die eigene Adresse wird durchsucht.');
    Assert::same([], $service->searchUsers('%'), 'Platzhalter der LIKE-Suche werden maskiert.');
    Assert::same([], $service->searchUsers('ausgeschieden'), 'Inaktive Einträge werden nicht angeboten.');
    Assert::same([], $service->searchUsers('ohne'), 'Einträge ohne SamAccountName haben keine Office-Kennung.');
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
        'entry' => null,
        'term' => '',
        'users' => [],
        'uid' => '',
        'orvantaEnabled' => true,
        'orvantaDemo' => true,
        'tablesMissing' => false,
        'base' => OrvantaSharedMailboxController::BASE,
    ];

    $html = View::render('admin.orvanta-shared-mailboxes', $data);
    Assert::contains('Archiviert wird weiterhin nur das primäre Benutzerpostfach', $html, 'Die Archivierungsregel steht auf der Seite.');
    Assert::contains('team@demo.local', $html, 'Der Demomodus nennt die prüfbaren Beispielpostfächer.');
    Assert::contains('Noch keine zusätzlichen Postfächer zugeordnet.', $html);
    Assert::contains('name="postfach"', $html);
    Assert::contains('action="/admin/office/orvanta/postfaecher/speichern"', $html, 'Das Formular zeigt auf die Speicherroute.');
    Assert::false(str_contains($html, 'style="'), 'Die Seite kommt ohne Inline-Stile aus.');

    // Suche mit Ergebnis: Werte werden escaped und die Kennung übernommen.
    $html = View::render('admin.orvanta-shared-mailboxes', [
        'term' => 'dreinelt',
        'users' => [[
            'uid' => 'dreinelt<script>',
            'username' => 'dreinelt',
            'display_name' => 'Daniel & André',
            'email' => 'dreinelt@example.local',
            'source' => 'Hauptquelle',
        ]],
    ] + $data);
    Assert::contains('Daniel &amp; André', $html);
    Assert::contains('dreinelt&lt;script&gt;', $html);
    Assert::false(str_contains($html, '<script>'), 'Die Ausgabe ist escaped.');
    Assert::contains('href="/admin/office/orvanta/postfaecher?benutzer=dreinelt%3Cscript%3E#zuordnung"', $html, 'Die Auswahl übernimmt die Office-Kennung.');

    $html = View::render('admin.orvanta-shared-mailboxes', ['term' => 'niemand', 'users' => []] + $data);
    Assert::contains('Kein Benutzer gefunden', $html);

    // Tabelle mit einer Zuordnung und Bearbeitungsformular.
    $entry = [
        'id' => 7,
        'uid' => 'dreinelt',
        'email' => 'team@demo.local',
        'name' => 'Team Postfach',
        'send_as' => true,
        'active' => false,
        'sort_order' => 2,
        'calendar_visible' => false,
        'verified' => false,
        'error' => 'Das Postfach konnte nicht geoeffnet werden.',
        'checked_at' => '2026-02-01 09:30:00',
    ];
    $html = View::render('admin.orvanta-shared-mailboxes', ['entries' => [$entry], 'entry' => $entry] + $data);
    Assert::contains('Zuordnung bearbeiten', $html);
    Assert::contains('value="7"', $html, 'Das Formular bearbeitet die vorhandene Zuordnung.');
    Assert::contains('value="team@demo.local"', $html);
    Assert::contains('nicht erreichbar', $html);
    Assert::contains('Das Postfach konnte nicht geoeffnet werden.', $html);
    Assert::contains('ausgeblendet', $html, 'Der Kalenderzustand steht in der Übersicht.');
    Assert::contains('inaktiv', $html);
    Assert::contains('data-confirm="Zuordnung „team@demo.local“ für „dreinelt“ wirklich entfernen?"', $html);
    Assert::contains('href="/admin/office/orvanta/postfaecher?id=7#zuordnung"', $html);

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
    $result = $setup['service']->save('dreinelt', 'team@demo.local', 'Team Postfach');
    Assert::true($result['ok'], $result['error']);

    $xml = $setup['transport']->last();
    Assert::contains('<t:PrimarySmtpAddress>dreinelt@demo.local</t:PrimarySmtpAddress>', $xml, 'Die Prüfung gibt sich als Benutzer aus, nicht als Dienstkonto.');
    Assert::contains('<t:DistinguishedFolderId Id="msgfolderroot"><t:Mailbox><t:EmailAddress>team@demo.local</t:EmailAddress></t:Mailbox>', $xml, 'Geprüft wird der Zugriff auf das zusätzliche Postfach.');

    // Verweigert Exchange dem Benutzer den Zugriff, nennt die Meldung den Vollzugriff.
    $setup['transport']->forced = sharedMailboxEwsError('GetFolder', 'ErrorAccessDenied');
    $denied = $setup['service']->verify($result['id'], true);
    Assert::contains('keinen Vollzugriff', $denied);
    Assert::false($setup['repository']->find($result['id'])['verified']);
});

Runner::test('Orvanta: ohne Postfachadresse des Benutzers bleibt die Prüfung offen', function (): void {
    $setup = sharedMailboxSetup();
    $result = $setup['service']->save('dreinelt', 'team@demo.local', 'Team Postfach');
    Assert::false($result['ok']);
    Assert::same(OrvantaSharedMailboxService::PENDING, $result['error']);
    Assert::same(0, count($setup['transport']->requests), 'Ohne Benutzeradresse wird Exchange nicht befragt.');
    $row = $setup['repository']->find($result['id']);
    Assert::false($row['verified']);
    Assert::same('', $row['checked_at'], 'Die Prüfung wird bei der nächsten Anmeldung nachgeholt.');

    // Bei der Anmeldung liefert Orvanta die Adresse des Benutzers mit.
    $setup['service']->refresh(['username' => 'dreinelt'], 'dreinelt@demo.local');
    Assert::true($setup['repository']->find($result['id'])['verified']);

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
