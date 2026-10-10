<?php

declare(strict_types=1);

use App\Repositories\MailProxyRepository;
use App\Repositories\OrvantaFlowRepository;
use App\Repositories\OrvantaRepository;
use App\Services\MailProxy\MailProxyRoute;
use App\Services\Orvanta\OrvantaFlowCharts;
use App\Services\Orvanta\OrvantaFlowCloud;
use App\Services\Orvanta\OrvantaFlowService;
use App\Services\Orvanta\OrvantaPresenceService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Abbild der fuer den Nachrichtenfluss relevanten Tabellen
 * (database/migrations/039_mail_proxy.sql, 048_orvanta_flow_presence.sql).
 */
function flowPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE identity_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_key TEXT NOT NULL, label TEXT NOT NULL, hosts TEXT NOT NULL DEFAULT \'\',
            base_dn TEXT NOT NULL DEFAULT \'\', sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1
        )'
    );
    $pdo->exec(
        'CREATE TABLE mail_proxy_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_source_id INTEGER NOT NULL DEFAULT 0 UNIQUE, name TEXT NOT NULL,
            smtp_host TEXT NOT NULL, smtp_port INTEGER NOT NULL DEFAULT 587, smtp_security TEXT NOT NULL DEFAULT \'starttls\',
            smtp_auth INTEGER NOT NULL DEFAULT 1, imap_host TEXT NOT NULL, imap_port INTEGER NOT NULL DEFAULT 993,
            imap_security TEXT NOT NULL DEFAULT \'tls\', verify_tls INTEGER NOT NULL DEFAULT 1,
            timeout_seconds INTEGER NOT NULL DEFAULT 20, active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE mail_proxy_mailboxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, username TEXT NOT NULL,
            email_address TEXT NOT NULL, display_name TEXT NOT NULL DEFAULT \'\', quota_mb INTEGER NOT NULL DEFAULT 0,
            password_encrypted TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (server_id, email_address)
        )'
    );
    $pdo->exec(
        'CREATE TABLE mail_proxy_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_source_id INTEGER NOT NULL DEFAULT 0,
            phonebook_id INTEGER NOT NULL UNIQUE, mailbox_id INTEGER NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE mail_proxy_state (
            id INTEGER PRIMARY KEY, generation INTEGER NOT NULL DEFAULT 1, last_success_at TEXT NULL,
            last_error_at TEXT NULL, last_error TEXT NOT NULL DEFAULT \'\', updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec('INSERT INTO mail_proxy_state (id, generation) VALUES (1, 1)');
    $pdo->exec(
        'CREATE TABLE mail_proxy_source_state (
            identity_source_id INTEGER PRIMARY KEY, last_success_at TEXT NULL, last_error_at TEXT NULL,
            last_error TEXT NOT NULL DEFAULT \'\', failures INTEGER NOT NULL DEFAULT 0, checked_at TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE orvanta_activity (
            user_uid TEXT PRIMARY KEY, backend TEXT NOT NULL DEFAULT \'exchange\', source_id INTEGER NOT NULL DEFAULT 0,
            first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, requests INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE orvanta_source_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT, sampled_at TEXT NOT NULL, source_id INTEGER NOT NULL DEFAULT 0,
            active_users INTEGER NOT NULL DEFAULT 0, UNIQUE (sampled_at, source_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE orvanta_user_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT, sampled_at TEXT NOT NULL, active_users INTEGER NOT NULL DEFAULT 0,
            exchange_users INTEGER NOT NULL DEFAULT 0, proxy_users INTEGER NOT NULL DEFAULT 0,
            ai_users INTEGER NOT NULL DEFAULT 0, UNIQUE (sampled_at)
        )'
    );
    $pdo->exec("INSERT INTO identity_sources (id, source_key, label, base_dn, active) VALUES (0, 'ZENTRALE', 'Zentrale', 'DC=zentrale,DC=example,DC=local', 1)");
    $pdo->exec("INSERT INTO identity_sources (id, source_key, label, base_dn, active) VALUES (5, 'HAMBURG', 'Zweigstelle Hamburg', 'DC=hh,DC=example,DC=local', 1)");
    $pdo->exec("INSERT INTO identity_sources (id, source_key, label, base_dn, active) VALUES (6, 'ALT', 'Stillgelegt', 'DC=alt,DC=local', 0)");

    return $pdo;
}

/**
 * @return MailProxyRepository
 */
function flowProxy(PDO $pdo): MailProxyRepository
{
    return new MailProxyRepository($pdo);
}

// --------------------------------------------------------------- Quellenzustand

Runner::test('Nachrichtenfluss: Postfachzahlen werden je Identitätsquelle getrennt gezählt', static function (): void {
    $pdo = flowPdo();
    $proxy = flowProxy($pdo);
    $pdo->exec("INSERT INTO mail_proxy_servers (id, identity_source_id, name, smtp_host, imap_host) VALUES (1, 0, 'Zentrale', 'smtp.zentrale', 'imap.zentrale')");
    $pdo->exec("INSERT INTO mail_proxy_servers (id, identity_source_id, name, smtp_host, imap_host) VALUES (2, 5, 'Hamburg', 'smtp.hh', 'imap.hh')");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (1, 1, 'a', 'a@zentrale.example', 'x', 1)");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (2, 1, 'b', 'b@zentrale.example', 'x', 1)");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (3, 1, 'c', 'c@zentrale.example', 'x', 0)");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (4, 2, 'd', 'd@hh.example', 'x', 1)");
    // a ist verbunden, b ist aktiv aber frei, c ist inaktiv (mit Zuordnung)
    $pdo->exec("INSERT INTO mail_proxy_mappings (identity_source_id, phonebook_id, mailbox_id) VALUES (0, 1, 1)");
    $pdo->exec("INSERT INTO mail_proxy_mappings (identity_source_id, phonebook_id, mailbox_id) VALUES (0, 3, 3)");

    $counts = $proxy->sourceCounts();
    Assert::same(2, count($counts), 'nur konfigurierte Quellen');
    Assert::same(3, $counts[0]['mailboxes']);
    Assert::same(2, $counts[0]['active_mailboxes']);
    Assert::same(2, $counts[0]['mapped_mailboxes']);
    Assert::same(1, $counts[0]['active_mapped_mailboxes']);
    Assert::same(1, $counts[0]['free_mailboxes']);
    Assert::same(2, $counts[0]['mappings']);
    Assert::same(1, $counts[5]['mailboxes']);
    Assert::same(1, $counts[5]['active_mailboxes']);
    Assert::same(0, $counts[5]['mapped_mailboxes']);
    Assert::same(1, $counts[5]['free_mailboxes']);
    Assert::false(isset($counts[6]), 'inaktive Quelle ohne Server hat keine Zahlen');
});

Runner::test('Nachrichtenfluss: Quelle ohne Postfach liefert Nullwerte statt Fehler', static function (): void {
    $pdo = flowPdo();
    $proxy = flowProxy($pdo);
    $pdo->exec("INSERT INTO mail_proxy_servers (id, identity_source_id, name, smtp_host, imap_host) VALUES (1, 5, 'Hamburg', 'smtp.hh', 'imap.hh')");

    $counts = $proxy->sourceCounts();
    Assert::same(0, $counts[5]['mailboxes']);
    Assert::same(0, $counts[5]['active_mailboxes']);
    Assert::same(0, $counts[5]['mapped_mailboxes']);
    Assert::same(0, $counts[5]['free_mailboxes']);
    Assert::same(0, $counts[5]['mappings']);
});

Runner::test('Nachrichtenfluss: Prüfpostfach je Quelle bevorzugt ein zugeordnetes Postfach', static function (): void {
    $pdo = flowPdo();
    $proxy = flowProxy($pdo);
    $pdo->exec("INSERT INTO mail_proxy_servers (id, identity_source_id, name, smtp_host, imap_host) VALUES (1, 0, 'Zentrale', 'smtp.z', 'imap.z')");
    $pdo->exec("INSERT INTO mail_proxy_servers (id, identity_source_id, name, smtp_host, imap_host) VALUES (2, 5, 'Hamburg', 'smtp.hh', 'imap.hh')");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (1, 1, 'frei', 'frei@zentrale.example', 'x', 1)");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (2, 1, 'zu', 'zu@zentrale.example', 'x', 1)");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (3, 1, 'aus', 'aus@zentrale.example', 'x', 0)");
    $pdo->exec("INSERT INTO mail_proxy_mailboxes (id, server_id, username, email_address, password_encrypted, active) VALUES (4, 2, 'hh', 'hh@hh.example', 'x', 0)");
    $pdo->exec("INSERT INTO mail_proxy_mappings (identity_source_id, phonebook_id, mailbox_id) VALUES (0, 9, 2)");

    Assert::same(['id' => 2, 'email' => 'zu@zentrale.example'], $proxy->probeMailbox(0), 'zugeordnetes Postfach zuerst');
    Assert::same(['id' => 0, 'email' => ''], $proxy->probeMailbox(5), 'inaktives Postfach wird nicht geprüft');
    Assert::same(['id' => 0, 'email' => ''], $proxy->probeMailbox(6), 'Quelle ohne Server liefert keine Kennung');
});

Runner::test('Nachrichtenfluss: Quellenzustand erfasst Erfolg, Fehler und Prüfung', static function (): void {
    $pdo = flowPdo();
    $proxy = flowProxy($pdo);

    Assert::same([], $proxy->sourceStates(), 'ohne Vorgänge kein Zustand');

    $proxy->recordSourceError(5, 'Verbindung zu imap.hh:993 fehlgeschlagen');
    $states = $proxy->sourceStates();
    Assert::same(1, $states[5]['failures']);
    Assert::same('Verbindung zu imap.hh:993 fehlgeschlagen', $states[5]['last_error']);
    Assert::true($states[5]['last_error_at'] !== '', 'Fehlerzeitpunkt wird gesetzt');
    Assert::same('', $states[5]['last_success_at'], 'ohne Erfolg kein Erfolgszeitpunkt');

    $proxy->recordSourceError(5, 'erneut');
    Assert::same(2, $proxy->sourceStates()[5]['failures'], 'Fehler zählt hoch');
    Assert::same('erneut', $proxy->sourceStates()[5]['last_error'], 'neuer Text ersetzt den alten');

    $proxy->recordSourceSuccess(5);
    $states = $proxy->sourceStates();
    Assert::same(0, $states[5]['failures'], 'Erfolg beendet den Fehlerzustand');
    Assert::true($states[5]['last_success_at'] !== '', 'Erfolgszeitpunkt wird gesetzt');
    Assert::same('erneut', $states[5]['last_error'], 'Fehlertext bleibt als Diagnose erhalten');

    // Zweiter Erfolg ohne Änderung darf die Zeile nicht verdoppeln.
    $proxy->recordSourceSuccess(5);
    Assert::same(1, count($proxy->sourceStates()));

    $proxy->recordSourceSuccess(6);
    $states = $proxy->sourceStates();
    Assert::same(0, $states[6]['failures'], 'Quelle ohne Vorgeschichte startet fehlerfrei');
    Assert::true($states[6]['last_success_at'] !== '');

    $proxy->touchSourceCheck(5);
    $proxy->touchSourceCheck(0);
    $states = $proxy->sourceStates();
    Assert::true($states[5]['checked_at'] !== '', 'Prüfzeitpunkt wird gesetzt');
    Assert::true($states[0]['checked_at'] !== '', 'Quelle ohne Vorgang wird durch die Prüfung angelegt');
    Assert::same(3, count($states));
});

Runner::test('Nachrichtenfluss: Fehlertext wird auf 500 Zeichen begrenzt', static function (): void {
    $pdo = flowPdo();
    $proxy = flowProxy($pdo);
    $proxy->recordSourceError(5, str_repeat('x', 800));

    Assert::same(500, mb_strlen($proxy->sourceStates()[5]['last_error']));
});

// ------------------------------------------------------------------ Aktivität

Runner::test('Nachrichtenfluss: Aktivität wird angelegt und fortgeschrieben', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $first = date('Y-m-d H:i:s', time() - 120);
    $second = date('Y-m-d H:i:s');

    $flow->touchActivity('mueller', 'exchange', $first);
    Assert::same(1, $flow->activityCount());
    Assert::same(1, $flow->activeUsers(date('Y-m-d H:i:s', time() - 300), date('Y-m-d H:i:s'))['total']);

    $flow->touchActivity('mueller', 'exchange', $second);
    Assert::same(1, $flow->activityCount(), 'kein zweiter Eintrag für denselben Benutzer');

    $row = $pdo->query("SELECT first_seen_at, last_seen_at, requests FROM orvanta_activity WHERE user_uid = 'mueller'")->fetch(PDO::FETCH_ASSOC);
    Assert::same($first, (string) $row['first_seen_at'], 'erste Sichtung bleibt erhalten');
    Assert::same($second, (string) $row['last_seen_at'], 'letzte Sichtung wird nachgeführt');
    Assert::same(2, (int) $row['requests'], 'Zähler zählt hoch');
});

Runner::test('Nachrichtenfluss: Aktivität merkt sich einen Wechsel des Backends', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $now = date('Y-m-d H:i:s');

    $flow->touchActivity('mueller', 'exchange', $now);
    $flow->touchActivity('mueller', 'proxy', $now);

    Assert::same('proxy', (string) $pdo->query("SELECT backend FROM orvanta_activity WHERE user_uid = 'mueller'")->fetchColumn());
    Assert::same(1, $flow->activeUsers(date('Y-m-d H:i:s', time() - 300), $now)['proxy']);
});

Runner::test('Nachrichtenfluss: leere Kennung wird nicht erfasst', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $flow->touchActivity('   ', 'exchange', date('Y-m-d H:i:s'));

    Assert::same(0, $flow->activityCount());
});

Runner::test('Nachrichtenfluss: aktive Nutzer beachten das Zeitfenster und die Backends', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $now = time();
    $flow->touchActivity('alt', 'exchange', date('Y-m-d H:i:s', $now - 600));
    $flow->touchActivity('exchange1', 'exchange', date('Y-m-d H:i:s', $now - 60));
    $flow->touchActivity('exchange2', 'exchange', date('Y-m-d H:i:s', $now - 10));
    $flow->touchActivity('proxy1', 'proxy', date('Y-m-d H:i:s', $now - 5));

    $inside = $flow->activeUsers(date('Y-m-d H:i:s', $now - 300), date('Y-m-d H:i:s', $now));
    Assert::same(3, $inside['total'], 'nur Aktivität im Fenster');
    Assert::same(2, $inside['exchange']);
    Assert::same(1, $inside['proxy']);

    $all = $flow->activeUsers(date('Y-m-d H:i:s', $now - 900), date('Y-m-d H:i:s', $now));
    Assert::same(4, $all['total'], 'größeres Fenster erfasst mehr');
});

// ---------------------------------------------------------------------- Proben

Runner::test('Nachrichtenfluss: je Zeitraster entsteht genau eine Probe', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $at = date('Y-m-d H:i:s');

    Assert::true($flow->recordSample($at, 4, 3, 1, 2), 'erste Probe wird geschrieben');
    Assert::false($flow->recordSample($at, 9, 9, 0, 0), 'zweite Probe im selben Raster wird verworfen');
    Assert::same(1, $flow->sampleCount());

    $row = $pdo->query('SELECT active_users, exchange_users, proxy_users, ai_users FROM orvanta_user_samples')->fetch(PDO::FETCH_ASSOC);
    Assert::same(4, (int) $row['active_users'], 'bestehende Probe wird nicht überschrieben');
    Assert::same(3, (int) $row['exchange_users']);
    Assert::same(1, (int) $row['proxy_users']);
    Assert::same(2, (int) $row['ai_users']);
    Assert::same($at, $flow->lastSampleAt());
});

Runner::test('Nachrichtenfluss: negative Probenwerte werden auf 0 begrenzt', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $flow->recordSample(date('Y-m-d H:i:s'), -5, -1, -2, -3);

    $row = $pdo->query('SELECT active_users, exchange_users, proxy_users, ai_users FROM orvanta_user_samples')->fetch(PDO::FETCH_ASSOC);
    Assert::same(0, (int) $row['active_users']);
    Assert::same(0, (int) $row['ai_users']);
});

Runner::test('Nachrichtenfluss: Probenkennzahlen liefern Minimum, Maximum und Mittelwert', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $now = time();
    $flow->recordSample(date('Y-m-d H:i:s', $now - 7200), 2, 2, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 3600), 7, 5, 2, 1);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 60), 5, 4, 1, 3);
    // Außerhalb des Fensters
    $flow->recordSample(date('Y-m-d H:i:s', $now - 100000), 99, 99, 0, 0);

    $stats = $flow->sampleStats(date('Y-m-d H:i:s', $now - 86400), date('Y-m-d H:i:s', $now));
    Assert::same(2, $stats['min']);
    Assert::same(7, $stats['max']);
    Assert::same(4.7, $stats['avg']);
    Assert::same(3, $stats['samples']);
});

Runner::test('Nachrichtenfluss: ohne Proben sind die Kennzahlen Null', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $stats = $flow->sampleStats(date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s'));

    Assert::same(0, $stats['min']);
    Assert::same(0, $stats['max']);
    Assert::same(0.0, $stats['avg']);
    Assert::same(0, $stats['samples']);
    Assert::same('', $flow->lastSampleAt());
});

Runner::test('Nachrichtenfluss: Tagesmaximum wird je Kalendertag gebildet', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $flow->recordSample($yesterday . ' 09:00:00', 3, 3, 0, 0);
    $flow->recordSample($yesterday . ' 14:00:00', 8, 6, 2, 0);
    $flow->recordSample($today . ' 08:00:00', 5, 5, 0, 0);

    $peaks = $flow->dailyPeaks(date('Y-m-d 00:00:00', strtotime('-7 days')), date('Y-m-d H:i:s'));
    Assert::same(8, $peaks[$yesterday], 'Tagesmaximum statt letzter Wert');
    Assert::same(5, $peaks[$today]);
    Assert::same(2, count($peaks), 'nur Tage mit Proben');

    $empty = $flow->dailyPeaks(date('Y-m-d 00:00:00', strtotime('-400 days')), date('Y-m-d H:i:s', strtotime('-300 days')));
    Assert::same([], $empty, 'leerer Zeitraum ohne Fehler');
});

// --------------------------------------------------------------------- Räumen

Runner::test('Nachrichtenfluss: alte Aktivität und alte Proben werden entfernt', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $now = time();
    $flow->touchActivity('frisch', 'exchange', date('Y-m-d H:i:s', $now - 60));
    $flow->touchActivity('alt', 'exchange', date('Y-m-d H:i:s', $now - 90000));
    $flow->recordSample(date('Y-m-d H:i:s', $now - 60), 1, 1, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 500 * 86400), 1, 1, 0, 0);

    $removed = $flow->purge(date('Y-m-d H:i:s', $now - 86400), date('Y-m-d H:i:s', $now - 400 * 86400));
    Assert::same(['activity' => 1, 'samples' => 1], $removed);
    Assert::same(1, $flow->activityCount(), 'frische Aktivität bleibt');
    Assert::same(1, $flow->sampleCount(), 'frische Probe bleibt');
    Assert::same(1, $flow->activeUsers(date('Y-m-d H:i:s', $now - 300), date('Y-m-d H:i:s', $now))['total']);
});

Runner::test('Nachrichtenfluss: Räumen ohne alte Zeilen ist folgenlos', static function (): void {
    $pdo = flowPdo();
    $flow = new OrvantaFlowRepository($pdo);
    $flow->touchActivity('mueller', 'exchange', date('Y-m-d H:i:s'));

    Assert::same(['activity' => 0, 'samples' => 0], $flow->purge(date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 400 * 86400)));
    Assert::same(1, $flow->activityCount());
});

// ------------------------------------------------------------------- Präsenz

/**
 * @param \Closure():int $clock
 */
function flowPresence(PDO $pdo, \Closure $clock): OrvantaPresenceService
{
    return new OrvantaPresenceService(new OrvantaFlowRepository($pdo), null, $clock);
}

Runner::test('Nachrichtenfluss: Präsenz leitet das Backend aus der Postfachauflösung ab', static function (): void {
    Assert::same('proxy', OrvantaPresenceService::backendFor(MailProxyRoute::proxy(4, 2, 5, 'd@hh.example')));
    Assert::same('exchange', OrvantaPresenceService::backendFor(MailProxyRoute::exchange()));
    Assert::same('exchange', OrvantaPresenceService::backendFor(MailProxyRoute::blocked(4, 5, 'deaktiviert')));
});

Runner::test('Nachrichtenfluss: Präsenz erfasst die Aktivität mit dem Backend', static function (): void {
    $pdo = flowPdo();
    $presence = flowPresence($pdo, static fn (): int => 1700000000);

    $presence->touch('mueller', 'proxy');
    $presence->touch('schmidt', 'exchange');
    $presence->touch('', 'exchange');

    Assert::same(2, (new OrvantaFlowRepository($pdo))->activityCount(), 'leere Kennung wird nicht erfasst');
    Assert::same('proxy', (string) $pdo->query("SELECT backend FROM orvanta_activity WHERE user_uid = 'mueller'")->fetchColumn());
    Assert::same(date('Y-m-d H:i:s', 1700000000), (string) $pdo->query("SELECT last_seen_at FROM orvanta_activity WHERE user_uid = 'mueller'")->fetchColumn());
});

Runner::test('Nachrichtenfluss: Präsenz wird auch beim Aufruf der App-Seite erfasst', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $presence = flowPresence($pdo, static fn (): int => $now);
    $flow = new OrvantaFlowRepository($pdo);

    // Zugriffsdatensatz von OrvantaController::authorize() beim Seitenaufruf
    $presence->touchAccess(['uid' => 'mueller', 'route' => MailProxyRoute::exchange(), 'user' => ['source_id' => 0]]);
    $presence->touchAccess(['uid' => 'schmidt', 'route' => MailProxyRoute::proxy(4, 2, 3, 'schmidt@mvz.example'), 'user' => ['source_id' => 3]]);
    // Ohne Postfachauflösung bzw. ohne Kennung entsteht keine Aktivität
    $presence->touchAccess(['uid' => 'ohne-route', 'user' => ['source_id' => 0]]);
    $presence->touchAccess(['uid' => '', 'route' => MailProxyRoute::exchange(), 'user' => []]);

    Assert::same(2, $flow->activityCount(), 'Seitenaufruf zählt als Aktivität');
    Assert::same('exchange', (string) $pdo->query("SELECT backend FROM orvanta_activity WHERE user_uid = 'mueller'")->fetchColumn());
    Assert::same('proxy', (string) $pdo->query("SELECT backend FROM orvanta_activity WHERE user_uid = 'schmidt'")->fetchColumn());
    Assert::same(3, (int) $pdo->query("SELECT source_id FROM orvanta_activity WHERE user_uid = 'schmidt'")->fetchColumn(), 'Aktivität hängt an der Identitätsquelle des Benutzers');

    $stats = $presence->stats();
    Assert::same(2, $stats['current'], 'Offene Sitzung erscheint als aktiver Nutzer');
    Assert::same(1, $stats['exchange']);
    Assert::same(1, $stats['proxy']);
    $since = date('Y-m-d H:i:s', $now - OrvantaPresenceService::ACTIVE_WINDOW);
    Assert::same([0 => 1, 3 => 1], $flow->activeUsersBySource($since, date('Y-m-d H:i:s', $now)), 'Summe der Quellen ergibt den Nutzerknoten');
});

Runner::test('Nachrichtenfluss: Probe entsteht einmal je Zeitraster', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $clock = static fn (): int => $now;
    $presence = flowPresence($pdo, $clock);
    $presence->touch('a', 'exchange');
    $presence->touch('b', 'exchange');
    $presence->touch('c', 'proxy');

    Assert::true($presence->sample(), 'erste Probe im Raster');
    Assert::false($presence->sample(), 'zweite Probe im selben Raster wird verworfen');
    Assert::same(1, (new OrvantaFlowRepository($pdo))->sampleCount());

    $row = $pdo->query('SELECT sampled_at, active_users, exchange_users, proxy_users, ai_users FROM orvanta_user_samples')->fetch(PDO::FETCH_ASSOC);
    $expected = date('Y-m-d H:i:s', $now - ($now % OrvantaPresenceService::SAMPLE_INTERVAL));
    Assert::same($expected, (string) $row['sampled_at'], 'Probe liegt auf dem Raster');
    Assert::same(3, (int) $row['active_users']);
    Assert::same(2, (int) $row['exchange_users']);
    Assert::same(1, (int) $row['proxy_users']);
    Assert::same(0, (int) $row['ai_users'], 'ohne KI-Datenquelle 0');
});

Runner::test('Nachrichtenfluss: Kennzahlen verbinden Aktivität und Proben', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $presence = flowPresence($pdo, static fn (): int => $now);
    $flow = new OrvantaFlowRepository($pdo);

    $flow->recordSample(date('Y-m-d H:i:s', $now - 3600), 2, 2, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 60), 6, 4, 2, 0);
    // Außerhalb der 24 Stunden
    $flow->recordSample(date('Y-m-d H:i:s', $now - 90000), 40, 40, 0, 0);
    $presence->touch('a', 'exchange');
    $presence->touch('b', 'proxy');
    $presence->touch('alt', 'exchange');

    $stats = $presence->stats();
    Assert::same(3, $stats['current']);
    Assert::same(2, $stats['exchange']);
    Assert::same(1, $stats['proxy']);
    Assert::same(2, $stats['min']);
    Assert::same(6, $stats['max']);
    Assert::same(4.0, $stats['avg']);
    Assert::same(2, $stats['samples']);
    Assert::same(OrvantaPresenceService::ACTIVE_WINDOW, $stats['window']);
    Assert::same(date('Y-m-d H:i:s', $now), $stats['generated_at']);
});

Runner::test('Nachrichtenfluss: ohne Proben fallen Kennzahlen auf den aktuellen Wert zurück', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $presence = flowPresence($pdo, static fn (): int => $now);
    $presence->touch('a', 'exchange');
    $presence->touch('b', 'proxy');

    $stats = $presence->stats();
    Assert::same(2, $stats['current']);
    Assert::same(2, $stats['min']);
    Assert::same(2, $stats['max']);
    Assert::same(2.0, $stats['avg']);
    Assert::same(0, $stats['samples']);
});

Runner::test('Nachrichtenfluss: Verlauf liefert fünf Zeiträume mit lückenlosen Tagen', static function (): void {
    $pdo = flowPdo();
    $now = strtotime('2024-06-30 12:00:00');
    $presence = flowPresence($pdo, static fn (): int => $now);
    $flow = new OrvantaFlowRepository($pdo);

    $flow->recordSample(date('Y-m-d H:i:s', $now - 2 * 86400), 7, 7, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 2 * 86400 + 3600), 4, 4, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now), 3, 3, 0, 0);
    // Älter als der längste Zeitraum
    $flow->recordSample(date('Y-m-d H:i:s', $now - 400 * 86400), 99, 99, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 300 * 86400), 12, 12, 0, 0);

    $history = $presence->history();
    Assert::same([14, 30, 90, 180, 365], $history['periods']);
    Assert::same(5, count($history['series']));
    Assert::same(12, $history['max'], 'Höchstwert über alle Zeiträume');

    $short = $history['series'][14];
    Assert::same(14, $short['days']);
    Assert::same(14, count($short['points']), 'ein Punkt je Tag');
    Assert::same(date('Y-m-d', $now), $short['points'][13]['day'], 'rechter Rand ist heute');
    Assert::same(date('Y-m-d', $now - 13 * 86400), $short['points'][0]['day']);
    Assert::same(3, $short['points'][13]['value']);
    Assert::same(7, $short['points'][11]['value'], 'Tagesmaximum des Tages vor zwei Tagen');
    Assert::null($short['points'][12]['value'], 'Tag ohne Probe hat keinen Wert');
    Assert::same(2, $short['samples'], 'nur Tage mit Daten');
    Assert::same(5.0, $short['avg'], 'Mittelwert der Tage mit Daten');

    $long = $history['series'][365];
    Assert::same(365, count($long['points']));
    Assert::same(12, $long['max'], 'auch ältere Proben im langen Zeitraum');
    Assert::same(3, $long['samples'], 'Probe jenseits der 400 Tage zählt nicht');
    Assert::same(7, $history['series'][90]['max'], 'der 90-Tage-Zeitraum endet vor der alten Probe');
});

Runner::test('Nachrichtenfluss: Verlauf ohne Proben ist leer, aber vollständig', static function (): void {
    $pdo = flowPdo();
    $now = strtotime('2024-06-30 12:00:00');
    $history = flowPresence($pdo, static fn (): int => $now)->history();

    Assert::same(0, $history['max']);
    foreach ([14, 30, 90, 180, 365] as $days) {
        Assert::same($days, count($history['series'][$days]['points']), 'Punkte für ' . $days . ' Tage');
        Assert::same(0, $history['series'][$days]['max']);
        Assert::same(0.0, $history['series'][$days]['avg']);
        Assert::same(0, $history['series'][$days]['samples']);
        Assert::null($history['series'][$days]['points'][0]['value']);
    }
});

Runner::test('Nachrichtenfluss: Präsenz räumt Aktivität nach 24 Stunden und Proben nach 400 Tagen', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $presence = flowPresence($pdo, static fn (): int => $now);
    $flow = new OrvantaFlowRepository($pdo);
    $flow->touchActivity('alt', 'exchange', date('Y-m-d H:i:s', $now - OrvantaPresenceService::ACTIVITY_TTL - 60));
    $flow->touchActivity('frisch', 'exchange', date('Y-m-d H:i:s', $now - 60));
    $flow->recordSample(date('Y-m-d H:i:s', $now - (OrvantaPresenceService::HISTORY_DAYS + 1) * 86400), 1, 1, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 86400), 2, 2, 0, 0);

    $removed = $presence->purge();
    Assert::same(1, $removed['activity']);
    Assert::same(1, $removed['samples']);
    Assert::same(1, $flow->activityCount());
    Assert::same(1, $flow->sampleCount());
});



// ---------------------------------------------------------------- Verlaufsgrafik

Runner::test('Nachrichtenfluss: Overlay-Grafik zeichnet fünf Zeiträume mit gemeinsamer Achse', static function (): void {
    $pdo = flowPdo();
    $now = strtotime('2024-06-30 12:00:00');
    $presence = flowPresence($pdo, static fn (): int => $now);
    $flow = new OrvantaFlowRepository($pdo);
    $flow->recordSample(date('Y-m-d H:i:s', $now), 8, 8, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 20 * 86400), 4, 4, 0, 0);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 200 * 86400), 2, 2, 0, 0);

    $charts = new OrvantaFlowCharts();
    $svg = $charts->overlay($presence->history());

    Assert::true(str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 340" width="100%" role="img"'), 'vektorbasiert, ohne feste Pixelbreite');
    Assert::true(str_ends_with($svg, '</svg>'));
    Assert::true(str_contains($svg, 'role="img"') && str_contains($svg, '<title>') && str_contains($svg, '<desc>'), 'Titel und Beschreibung für Screenreader');
    Assert::false(str_contains($svg, 'style="'), 'keine Inline-Stile (CSP)');
    Assert::same(5, substr_count($svg, 'class="ov-flow-line ov-flow-line--'), 'eine Linie je Zeitraum');
    foreach ([14, 30, 90, 180, 365] as $days) {
        Assert::true(str_contains($svg, 'ov-flow-line--' . $days . '"'), 'Zeitraum ' . $days . ' Tage vorhanden');
    }
    Assert::same(5, substr_count($svg, '<polyline'), 'nur Zeiträume mit Werten werden gezeichnet');
    Assert::true(str_contains($svg, '>8</text>'), 'y-Achse reicht bis zum Höchstwert');
    Assert::true(str_contains($svg, 'Anfang des Zeitraums') && str_contains($svg, 'heute'), 'relative x-Beschriftung');
    Assert::true(str_contains($svg, '30.06.2024: 8 aktive Nutzer (14 Tage)'), 'letzter Punkt mit Datum und Wert');
    Assert::same(5, substr_count($svg, 'ov-flow-grid'), 'vier Hilfslinien plus Nullinie');
});

Runner::test('Nachrichtenfluss: Overlay-Grafik unterbricht Lücken und zeigt keine Nullwerte', static function (): void {
    $history = ['max' => 5, 'series' => [14 => ['days' => 14, 'max' => 5, 'points' => [
        ['day' => '2024-06-17', 'value' => null],
        ['day' => '2024-06-18', 'value' => 5],
        ['day' => '2024-06-19', 'value' => null],
        ['day' => '2024-06-20', 'value' => 3],
    ]]]];
    $svg = (new OrvantaFlowCharts())->overlay($history);

    Assert::false(str_contains($svg, 'null'), 'Lücken werden nicht als Wert gezeichnet');
    Assert::same(1, substr_count($svg, '<polyline'), 'zwei getrennte Tage ergeben kein Linienstück');
    Assert::true(str_contains($svg, '20.06.2024: 3 aktive Nutzer (14 Tage)'), 'letzter Wert bleibt sichtbar');
});

Runner::test('Nachrichtenfluss: Overlay-Grafik ohne Daten meldet das verständlich', static function (): void {
    $charts = new OrvantaFlowCharts();
    $svg = $charts->overlay(['series' => []]);
    Assert::true(str_contains($svg, 'Keine Verlaufsdaten vorhanden.'));
    Assert::false(str_contains($svg, '<polyline>'));
    Assert::same([], $charts->tableRows(['series' => []]), 'ohne Zeiträume keine Wertetabelle');
});

Runner::test('Nachrichtenfluss: Wertetabelle nutzt den kürzesten Zeitraum und lässt Lücken leer', static function (): void {
    $history = ['max' => 5, 'series' => [
        30 => ['points' => [['day' => '2024-06-01', 'value' => 1]]],
        14 => ['points' => [
            ['day' => '2024-06-17', 'value' => null],
            ['day' => '2024-06-18', 'value' => 5],
        ]],
    ]];
    $rows = (new OrvantaFlowCharts())->tableRows($history);

    Assert::same(2, count($rows), 'nur der kürzeste Zeitraum');
    Assert::same('2024-06-17', $rows[0]['day']);
    Assert::null($rows[0]['value'], 'Tage ohne Probe bleiben leer');
    Assert::same(5, $rows[1]['value']);
});

Runner::test('Nachrichtenfluss: Zeiträume haben feste Farben und Bezeichnungen', static function (): void {
    $series = OrvantaFlowCharts::series();
    Assert::same([365, 180, 90, 30, 14], array_keys($series), 'Reihenfolge vom längsten zum kürzesten Zeitraum');
    foreach ($series as $days => $entry) {
        Assert::true(preg_match('/^#[0-9a-f]{6}$/', $entry['color']) === 1, 'Farbe für ' . $days);
        Assert::same($days . ' Tage', $entry['label']);
    }
    Assert::same('#dc2626', OrvantaFlowCharts::color(14));
    Assert::same('#64748b', OrvantaFlowCharts::color(7), 'unbekannter Zeitraum erhält einen neutralen Ton');
});

Runner::test('Nachrichtenfluss: Wolkengröße staffelt sich am Höchstwert', static function (): void {
    Assert::same(5, OrvantaFlowCloud::level(100, 100), 'Höchstwert ist die größte Stufe');
    Assert::same(5, OrvantaFlowCloud::level(90, 100), '90 Prozent ist die Untergrenze der größten Stufe');
    Assert::same(4, OrvantaFlowCloud::level(89, 100), 'knapp unter 90 Prozent');
    Assert::same(4, OrvantaFlowCloud::level(70, 100), '70 Prozent');
    Assert::same(3, OrvantaFlowCloud::level(69, 100), 'knapp unter 70 Prozent');
    Assert::same(3, OrvantaFlowCloud::level(50, 100), '50 Prozent');
    Assert::same(2, OrvantaFlowCloud::level(49, 100), 'knapp unter 50 Prozent');
    Assert::same(2, OrvantaFlowCloud::level(30, 100), '30 Prozent');
    Assert::same(1, OrvantaFlowCloud::level(29, 100), 'knapp unter 30 Prozent');
    Assert::same(1, OrvantaFlowCloud::level(0, 100), 'Null bleibt kleinste Stufe');
    Assert::same(1, OrvantaFlowCloud::level(5, 0), 'ohne Höchstwert keine Staffelung');
});

Runner::test('Nachrichtenfluss: Wolke staffelt Wörter abwechselnd und maskiert Text', static function (): void {
    $cloud = new OrvantaFlowCloud();
    $items = $cloud->items([
        ['label' => 'domäne-a', 'value' => 40],
        ['label' => 'domäne-b', 'value' => 12],
        ['label' => 'domäne-c', 'value' => 40],
    ]);

    Assert::same(3, count($items));
    Assert::same(['up', 'down', 'up'], array_column($items, 'direction'), 'Staffelung wechselt mit der Position');
    Assert::same([5, 2, 5], array_column($items, 'level'));
    Assert::same('domäne-b: 12', $items[1]['title'], 'Vorgabe-Titel aus Name und Wert');

    $html = $cloud->render([['label' => '<script>alert(1)</script>', 'value' => 3]]);
    Assert::false(str_contains($html, '<script>'), 'Beschriftung wird maskiert');
    Assert::true(str_contains($html, '&lt;script&gt;'), 'maskierte Beschriftung bleibt lesbar');
    Assert::false(str_contains($html, 'style="'), 'keine Inline-Stile (CSP)');
    Assert::true(str_contains($html, 'cloud__word--l5 cloud__word--up'), 'Stufe und Staffelung als CSS-Klasse');
});

Runner::test('Nachrichtenfluss: Wolke verwirft leere Einträge und meldet leere Listen', static function (): void {
    $cloud = new OrvantaFlowCloud();

    $items = $cloud->items([
        ['label' => '   ', 'value' => 5],
        ['label' => 'gültig', 'value' => -7],
    ]);
    Assert::same(1, count($items), 'Einträge ohne Beschriftung entfallen');
    Assert::same(0, $items[0]['value'], 'negative Werte werden auf 0 begrenzt');

    Assert::same('', $cloud->table([]), 'ohne Werte keine Tabelle');
    $empty = $cloud->render([], 'Noch keine Postfächer erfasst.');
    Assert::true(str_contains($empty, 'cloud--empty'));
    Assert::true(str_contains($empty, 'Noch keine Postfächer erfasst.'));
    Assert::false(str_contains($empty, '<ul'), 'leere Liste erzeugt kein Listenelement');
});

Runner::test('Nachrichtenfluss: Wolkenwerte stehen zusätzlich als Tabelle bereit', static function (): void {
    $cloud = new OrvantaFlowCloud();
    $html = $cloud->table(
        [
            ['label' => 'Benutzer 1', 'value' => 42, 'url' => '/admin/office/orvanta/ki'],
            ['label' => 'Benutzer 2', 'value' => 7],
        ],
        'Top-Nutzer als Tabelle',
        'Benutzer'
    );

    Assert::true(str_contains($html, '<details class="cloud-values">'), 'aufklappbare Wertetabelle');
    Assert::true(str_contains($html, '<summary>Top-Nutzer als Tabelle</summary>'));
    Assert::true(str_contains($html, '<th scope="col">Benutzer</th>'));
    Assert::true(str_contains($html, '<th scope="row"><a href="/admin/office/orvanta/ki">Benutzer 1</a></th><td>42</td>'));
    Assert::true(str_contains($html, '<th scope="row">Benutzer 2</th><td>7</td>'), 'ohne URL reiner Text');
    Assert::false(str_contains($html, 'style="'), 'keine Inline-Stile (CSP)');
});

Runner::test('Nachrichtenfluss: Wolke zeigt formatierte Werte und eigene Spaltenköpfe', static function (): void {
    $cloud = new OrvantaFlowCloud();
    $entries = [
        ['label' => 'anna', 'value' => 1073741824, 'display' => '1,0 GB'],
        ['label' => 'bert', 'value' => 1024],
    ];

    $html = $cloud->render($entries);
    Assert::true(str_contains($html, '>1,0 GB</span>'), 'formatierter Wert ersetzt die Rohzahl');
    Assert::true(str_contains($html, '>1024</span>'), 'ohne Formatierung bleibt die Zahl');
    Assert::true(str_contains($html, 'cloud__word--l5'), 'die Stufe richtet sich weiter nach dem Zahlenwert');

    $table = $cloud->table($entries, 'Größte Zwischenspeicher', 'Nutzer', 'Belegung');
    Assert::true(str_contains($table, '<th scope="col">Belegung</th>'), 'eigener Spaltenkopf für die Wertspalte');
    Assert::true(str_contains($table, '<td>1,0 GB</td>'), 'auch die Tabelle zeigt den formatierten Wert');
    Assert::true(str_contains($table, '<td>1024</td>'), 'Zahlen ohne Formatierung bleiben unverändert');
});

// ------------------------------------------------------- Nachrichtenflussdienst

/**
 * Gesunde Ausgangslage des Dashboards; einzelne Bereiche lassen sich gezielt
 * ueberschreiben, damit jeder Test nur seine Abweichung beschreibt.
 *
 * @param array<string,mixed> $overrides
 *
 * @return array<string,mixed>
 */
function flowInput(array $overrides = []): array
{
    $input = [
        'now' => 1_700_000_000,
        'proxy' => [
            'available' => true,
            'configured' => true,
            'ok' => true,
            'message' => 'Der Proxy-Dienst antwortet.',
            'details' => ['status' => 'ok', 'version' => '1.4.0', 'uptime' => 7200, 'connections' => 3, 'max_connections' => 32, 'pooled' => 1, 'requests' => 1200, 'errors' => 0],
            'counts' => ['servers' => 1, 'active_servers' => 1, 'mailboxes' => 12, 'active_mailboxes' => 10, 'mappings' => 8],
            'state' => ['generation' => 4, 'last_success_at' => '2023-11-14 21:00:00', 'last_error_at' => '', 'last_error' => ''],
            'cache_ttl' => 300,
            'servers' => [['smtp_host' => 'smtp.hh.example', 'smtp_port' => 587, 'imap_host' => 'imap.hh.example', 'imap_port' => 993]],
        ],
        'sources' => [[
            'id' => 0,
            'label' => 'Zentrale',
            'domain' => 'hh.example',
            'active' => true,
            'primary' => true,
            'counts' => ['mailboxes' => 12, 'active_mailboxes' => 10, 'mapped_mailboxes' => 8, 'active_mapped_mailboxes' => 8, 'free_mailboxes' => 2, 'mappings' => 8],
            'state' => ['last_success_at' => '2023-11-14 21:00:00', 'last_error_at' => '', 'last_error' => '', 'failures' => 0, 'checked_at' => '2023-11-14 21:00:00'],
        ]],
        'exchange' => [
            'available' => true,
            'configured' => true,
            'hosts' => [[
                'id' => 1, 'host' => 'ex01.hh.example', 'ews_url' => 'https://ex01.hh.example/EWS/Exchange.asmx',
                'is_primary' => true, 'active' => true, 'status' => 'online', 'status_label' => 'Online', 'sessions' => 2,
                'latency_label' => '12 ms', 'last_latency_label' => '11 ms', 'last_session_label' => 'vor 1 min',
                'last_check_label' => 'vor 1 min', 'url' => '/admin/office/orvanta/hosts', 'last_error' => '',
            ]],
            'sessions' => [
                ['user' => 'anna', 'host' => 'ex01.hh.example', 'client_ip' => '10.0.0.5', 'client_host' => 'nb-anna', 'failovers' => 0, 'requests' => 40, 'started_label' => 'vor 2 min', 'last_seen_label' => 'vor 1 min'],
                ['user' => 'bob', 'host' => 'ex01.hh.example', 'client_ip' => '10.0.0.6', 'client_host' => 'nb-anna', 'failovers' => 1, 'requests' => 12, 'started_label' => 'vor 5 min', 'last_seen_label' => 'vor 1 min'],
            ],
            'totals' => ['hosts' => 1, 'active' => 1, 'online' => 1, 'sessions' => 2],
        ],
        'presence' => ['current' => 4, 'exchange' => 3, 'proxy' => 1, 'min' => 1, 'max' => 9, 'avg' => 3.5, 'samples' => 288, 'window' => 300],
        'history' => ['periods' => [14, 30, 90, 180, 365], 'series' => [], 'max' => 9],
        'storage' => [
            'available' => true,
            'mode' => 'normal',
            'forecast_text' => '',
            'local' => [
                'total_bytes' => 100_000, 'free_bytes' => 70_000, 'used_bytes' => 30_000,
                'fill' => ['percent' => 30.0, 'state' => 'ok'], 'limit_bytes' => 0, 'limit_auto' => false,
                'bytes_local' => 12_000, 'read_bps' => 0, 'write_bps' => 0,
            ],
            'snapshot' => [
                'enabled' => true, 'configured' => true, 'unc_path' => '\\\\nas\\snapshots', 'state' => 'online',
                'state_label' => 'Online', 'message' => '', 'total_bytes' => 50_000, 'free_bytes' => 40_000,
                'used_bytes' => 10_000, 'fill' => ['percent' => 20.0, 'state' => 'ok'],
                'snapshots_total' => 3, 'snapshots_bytes' => 9_000, 'pending' => 0, 'failed' => 0,
                'last_snapshot_at' => '2023-11-14 20:00:00', 'retention_days' => 30, 'max_versions' => 10,
            ],
            'targets' => [[
                'id' => 1, 'label' => 'Tier A', 'kind' => 'smb', 'state' => 'online', 'message' => '',
                'unbounded' => false, 'total_bytes' => 1000, 'free_bytes' => 400,
                'fill' => ['percent' => 60.0, 'state' => 'ok'], 'in_sync' => true, 'lag_seconds' => 0,
                'read_bps' => 0, 'write_bps' => 0,
                'members' => [
                    ['id' => 1, 'label' => 'Tier A', 'state' => 'online', 'role' => 'root'],
                    ['id' => 2, 'label' => 'Tier A Teil 2', 'state' => 'online', 'role' => 'extension'],
                ],
            ]],
        ],
        'cache' => [
            'used' => 50 * 1024 * 1024,
            'quota' => 100 * 1024 * 1024,
            'items' => 20,
            'users' => 3,
            'percent' => 50,
            'top' => [['user_uid' => 'anna', 'items' => 9, 'bytes' => 30 * 1024 * 1024]],
        ],
        'ai' => [
            'enabled' => true, 'configured' => true, 'active' => true, 'has_key' => true,
            'name' => 'Lokale KI', 'url' => 'https://ki.hh.example/v1', 'model' => 'llama-3',
            'audio' => false, 'images' => true, 'names' => false, 'period_days' => 30,
            'from' => '2023-10-16', 'to' => '2023-11-14',
            'top' => [
                ['label' => 'Benutzer 1', 'value' => 42, 'title' => 'Benutzer 1: 42 Anfragen'],
                ['label' => 'Benutzer 2', 'value' => 7, 'title' => 'Benutzer 2: 7 Anfragen'],
            ],
            'remaining' => 5, 'requests' => 49, 'users' => 7, 'input_tokens' => 1000, 'output_tokens' => 2000,
        ],
    ];

    foreach ($overrides as $key => $value) {
        $input[$key] = $value;
    }

    return $input;
}

/**
 * Kennzahl nach Schluessel.
 *
 * @param array<string,mixed> $flow
 *
 * @return array<string,mixed>
 */
function flowKpi(array $flow, string $key): array
{
    foreach ($flow['kpis'] as $kpi) {
        if ($kpi['key'] === $key) {
            return $kpi;
        }
    }

    return [];
}

/**
 * Knoten nach Schluessel.
 *
 * @param array<string,mixed> $flow
 *
 * @return array<string,mixed>
 */
function flowNode(array $flow, string $key): array
{
    return $flow['nodes'][$key] ?? [];
}

Runner::test('Nachrichtenfluss: gesunde Ausgangslage ergibt Gesamtstatus "In Ordnung"', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    Assert::same('ok', $flow['overall']['state']);
    Assert::same('In Ordnung', $flow['overall']['label']);
    Assert::same(0, $flow['overall']['errors']);
    Assert::same(0, $flow['overall']['warnings']);
    Assert::same([], $flow['incidents']);
    Assert::same(date('d.m.Y H:i:s', 1_700_000_000), $flow['generated_at']);
    Assert::same(date('Y-m-d H:i:s', 1_700_000_000), $flow['generated_iso']);
});

Runner::test('Nachrichtenfluss: alle Bausteine erscheinen als Knoten mit Zustandsbezeichnung', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    Assert::same(
        ['proxy', 'source-0', 'host-1', 'users', 'ai', 'cache', 'tier-local', 'tier-1', 'tier-snapshot'],
        array_keys($flow['nodes']),
        'Proxy, Quelle, Host, Nutzer, KI, Zwischenspeicher, lokaler Speicher, Tier und Snapshot sind vorhanden'
    );
    foreach ($flow['nodes'] as $key => $node) {
        Assert::true(in_array($node['state'], ['ok', 'warn', 'error', 'off'], true), $key . ' hat einen gültigen Zustand');
        Assert::true(isset(OrvantaFlowService::STATE_LABELS[$node['state']]), $key . ' hat eine Zustandsbezeichnung');
        Assert::same($node['state'] === 'error', $node['alert'], $key . ' zeigt das Ausrufezeichen nur bei Störung');
    }
});

Runner::test('Nachrichtenfluss: Proxy-Ausfall stört alle Quellen und dämpft deren Postfächer', static function (): void {
    $input = flowInput();
    $input['proxy']['ok'] = false;
    $input['proxy']['message'] = 'Der Proxy-Dienst ist nicht erreichbar.';
    $flow = OrvantaFlowService::evaluate($input);

    $proxy = flowNode($flow, 'proxy');
    Assert::same('error', $proxy['state']);
    Assert::true(str_contains($proxy['message'], 'Transportweg ist unterbrochen'), 'Der Proxy erklärt die Folge');

    $source = flowNode($flow, 'source-0');
    Assert::same('error', $source['state']);
    Assert::true($source['muted'], 'Die Identitätsquelle wird gedämpft');
    Assert::true($source['cloud_muted'], 'Die Postfachwolke wird gedämpft');
    Assert::true($source['alert'], 'Die Quelle zeigt ein Ausrufezeichen');

    Assert::same('error', $flow['overall']['state']);
    Assert::same(2, $flow['overall']['errors']);
    Assert::same(2, count($flow['incidents']));
});

Runner::test('Nachrichtenfluss: Ausfall einer Identitätsquelle dämpft nur deren Postfächer', static function (): void {
    $input = flowInput();
    $input['sources'][] = [
        'id' => 4,
        'label' => 'Niederlassung',
        'domain' => 'nb.example',
        'active' => true,
        'primary' => false,
        'counts' => ['mailboxes' => 3, 'active_mailboxes' => 2, 'mapped_mailboxes' => 1, 'active_mapped_mailboxes' => 1, 'free_mailboxes' => 1, 'mappings' => 1],
        'state' => ['last_success_at' => '2023-11-14 18:00:00', 'last_error_at' => '2023-11-14 21:00:00', 'last_error' => 'Anmeldung abgelehnt.', 'failures' => 3, 'checked_at' => '2023-11-14 21:00:00'],
    ];
    $flow = OrvantaFlowService::evaluate($input);

    $broken = flowNode($flow, 'source-4');
    Assert::same('error', $broken['state']);
    Assert::same('Anmeldung abgelehnt.', $broken['message']);
    Assert::true($broken['cloud_muted'], 'Nur die Postfächer der gestörten Quelle werden gedämpft');
    Assert::false($broken['muted'], 'Die Quelle selbst bleibt sichtbar');

    $healthy = flowNode($flow, 'source-0');
    Assert::same('ok', $healthy['state']);
    Assert::false($healthy['cloud_muted'], 'Die gesunde Quelle bleibt unverändert');
    Assert::false($healthy['muted']);
});

Runner::test('Nachrichtenfluss: noch nie geprüfte Quelle ist eingeschränkt, deaktivierte Quelle aus', static function (): void {
    $input = flowInput();
    $input['sources'][0]['state'] = ['last_success_at' => '', 'last_error_at' => '', 'last_error' => '', 'failures' => 0, 'checked_at' => ''];
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('warn', flowNode($flow, 'source-0')['state']);
    Assert::same('warn', $flow['overall']['state']);

    $input['sources'][0]['active'] = false;
    $input['sources'][0]['state'] = ['last_success_at' => '2023-11-14 21:00:00', 'last_error_at' => '', 'last_error' => '', 'failures' => 0, 'checked_at' => '2023-11-14 21:00:00'];
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('off', flowNode($flow, 'source-0')['state']);
    Assert::false(flowNode($flow, 'source-0')['alert'], 'Eine deaktivierte Quelle ist keine Störung');
});

Runner::test('Nachrichtenfluss: ohne Proxy-Konfiguration bleibt der Proxy aus statt gestört', static function (): void {
    $input = flowInput();
    $input['proxy'] = [
        'available' => false, 'configured' => false, 'ok' => false,
        'message' => 'Nicht geprüft (keine Proxy-Konfiguration).',
        'details' => [], 'counts' => [], 'state' => [], 'cache_ttl' => 0, 'servers' => [],
    ];
    $flow = OrvantaFlowService::evaluate($input);

    Assert::same('off', flowNode($flow, 'proxy')['state']);
    Assert::same('Kein Mailserver konfiguriert', flowNode($flow, 'proxy')['subtitle']);
    Assert::same('ok', flowNode($flow, 'source-0')['state'], 'Ohne Proxy bleiben die Quellen unberührt');
    Assert::same('off', flowKpi($flow, 'proxy')['state']);
});

Runner::test('Nachrichtenfluss: Proxy mit inaktiven Mailservern gilt als eingeschränkt', static function (): void {
    $input = flowInput();
    $input['proxy']['counts']['servers'] = 3;
    $input['proxy']['counts']['active_servers'] = 2;
    $flow = OrvantaFlowService::evaluate($input);

    Assert::same('warn', flowNode($flow, 'proxy')['state']);
    Assert::same('warn', flowKpi($flow, 'proxy')['state']);
    Assert::same('warn', $flow['overall']['state']);
});

Runner::test('Nachrichtenfluss: Clients werden je Exchange-Host gesammelt und nach Anzahl sortiert', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    $host = flowNode($flow, 'host-1');
    Assert::same('ok', $host['state']);
    Assert::same('Verbundene Clients', $host['cloud_title']);
    Assert::same(
        [
            ['label' => 'nb-anna', 'value' => 2, 'title' => 'nb-anna: 2 Sitzung(en)'],
        ],
        $host['cloud'],
        'Gleiche Clientnamen werden zusammengefasst'
    );
    Assert::false($host['cloud_muted']);
    Assert::true(in_array(['label' => 'Umleitungen', 'value' => '1', 'state' => ''], $host['facts'], true), 'Umleitungen werden summiert');
});

Runner::test('Nachrichtenfluss: gestörter Exchange-Host zeigt Ausrufezeichen und dämpft seine Clients', static function (): void {
    $input = flowInput();
    $input['exchange']['hosts'][0]['status'] = 'offline';
    $input['exchange']['hosts'][0]['status_label'] = 'Gestört';
    $input['exchange']['hosts'][0]['last_error'] = 'Verbindung abgelehnt.';
    $input['exchange']['totals']['online'] = 0;
    $flow = OrvantaFlowService::evaluate($input);

    $host = flowNode($flow, 'host-1');
    Assert::same('error', $host['state']);
    Assert::true($host['alert']);
    Assert::true($host['cloud_muted']);
    Assert::same('Verbindung abgelehnt.', $host['message']);
    Assert::same('error', flowKpi($flow, 'exchange')['state']);
    Assert::same('error', $flow['overall']['state']);
});

Runner::test('Nachrichtenfluss: Hosts in Wartung sind eingeschränkt, ohne Clients zu dämpfen', static function (): void {
    $input = flowInput();
    $input['exchange']['hosts'][0]['status'] = 'maintenance';
    $input['exchange']['hosts'][0]['status_label'] = 'Wartung';
    $flow = OrvantaFlowService::evaluate($input);

    Assert::same('warn', flowNode($flow, 'host-1')['state']);
    Assert::false(flowNode($flow, 'host-1')['cloud_muted']);
    Assert::same('warn', $flow['overall']['state']);
    Assert::same('warn', flowKpi($flow, 'exchange')['state']);
});

Runner::test('Nachrichtenfluss: ohne Exchange-Konfiguration sind die Hosts gedämpft und aus', static function (): void {
    $input = flowInput();
    $input['exchange']['configured'] = false;
    $flow = OrvantaFlowService::evaluate($input);

    $host = flowNode($flow, 'host-1');
    Assert::same('off', $host['state']);
    Assert::true($host['muted']);
    Assert::same('Proxy-Betrieb', $host['muted_reason']);
    Assert::false($host['alert']);
});

Runner::test('Nachrichtenfluss: Nutzerknoten zeigt aktuell, min und max der letzten 24 Stunden', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    $users = flowNode($flow, 'users');
    Assert::same('ok', $users['state']);
    Assert::true(in_array(['label' => 'Aktuell', 'value' => '4', 'state' => ''], $users['facts'], true));
    Assert::true(in_array(['label' => 'Minimum 24 h', 'value' => '1', 'state' => ''], $users['facts'], true));
    Assert::true(in_array(['label' => 'Maximum 24 h', 'value' => '9', 'state' => ''], $users['facts'], true));
    Assert::true(in_array(['label' => 'Mittelwert 24 h', 'value' => '3,5', 'state' => ''], $users['facts'], true));
    Assert::same(['periods' => [14, 30, 90, 180, 365], 'series' => [], 'max' => 9], $users['chart'], 'Der Verlauf hängt am Nutzerknoten');

    $kpi = flowKpi($flow, 'users');
    Assert::same('4', $kpi['value']);
    Assert::same('24 h: min 1 / max 9', $kpi['hint']);
    Assert::same('flow-users', $kpi['anchor']);
});

Runner::test('Nachrichtenfluss: ohne Messwerte ist der Nutzerknoten eingeschränkt statt gestört', static function (): void {
    $input = flowInput();
    $input['presence'] = ['current' => 0, 'exchange' => 0, 'proxy' => 0, 'min' => 0, 'max' => 0, 'avg' => 0.0, 'samples' => 0, 'window' => 300];
    $flow = OrvantaFlowService::evaluate($input);

    Assert::same('warn', flowNode($flow, 'users')['state']);
    Assert::false(flowNode($flow, 'users')['alert']);
});

Runner::test('Nachrichtenfluss: Proxy-Ausfall dämpft den Nutzerknoten nur ohne Exchange-Nutzer', static function (): void {
    $input = flowInput();
    $input['proxy']['ok'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::false(flowNode($flow, 'users')['muted'], 'Exchange-Nutzer bleiben sichtbar');
    Assert::same('Nur Proxy-Nutzer betroffen', flowNode($flow, 'users')['muted_reason']);

    $input['presence']['exchange'] = 0;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::true(flowNode($flow, 'users')['muted']);
});

Runner::test('Nachrichtenfluss: Zuordnungsquote wird aus aktiven und verbundenen Postfächern berechnet', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());
    $kpi = flowKpi($flow, 'mapping');
    Assert::same('80 %', $kpi['value']);
    Assert::same('8 von 10 aktiven Postfächern zugeordnet', $kpi['hint']);
    Assert::same('warn', $kpi['state']);

    $input = flowInput();
    $input['sources'][0]['counts']['active_mapped_mailboxes'] = 10;
    Assert::same('ok', flowKpi(OrvantaFlowService::evaluate($input), 'mapping')['state']);

    $input = flowInput();
    $input['sources'][0]['counts']['active_mailboxes'] = 0;
    $input['sources'][0]['counts']['active_mapped_mailboxes'] = 0;
    Assert::same('off', flowKpi(OrvantaFlowService::evaluate($input), 'mapping')['state']);
});

Runner::test('Nachrichtenfluss: Kennzahlen fassen Quellen, Postfächer und Sitzungen zusammen', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    Assert::same('1 von 1', flowKpi($flow, 'sources')['value']);
    Assert::same('12 Postfächer, 10 aktiv', flowKpi($flow, 'sources')['hint']);
    Assert::same('1 von 1', flowKpi($flow, 'exchange')['value']);
    Assert::same('2 verbundene Sitzungen', flowKpi($flow, 'exchange')['hint']);
    Assert::same('flow-proxy', flowKpi($flow, 'proxy')['anchor']);
    Assert::same('flow-exchange', flowKpi($flow, 'exchange')['anchor']);
});

Runner::test('Nachrichtenfluss: Zwischenspeicher wechselt bei 75 und 90 Prozent auf gelb und rot', static function (): void {
    $quota = 100 * 1024 * 1024;
    foreach ([74 => 'ok', 75 => 'warn', 89 => 'warn', 90 => 'error', 100 => 'error'] as $percent => $expected) {
        $input = flowInput();
        $input['cache']['quota'] = $quota;
        $input['cache']['used'] = (int) round($quota * $percent / 100);
        $input['cache']['percent'] = $percent;
        $flow = OrvantaFlowService::evaluate($input);

        Assert::same($expected, flowNode($flow, 'cache')['state'], $percent . ' % ergibt ' . $expected);
        Assert::same($expected, flowKpi($flow, 'cache')['state'], $percent . ' % in der Kennzahl');
    }

    $input = flowInput();
    $input['cache']['percent'] = 90;
    $input['cache']['used'] = (int) round($quota * 0.9);
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('error', $flow['overall']['state']);
    Assert::true(str_contains(flowNode($flow, 'cache')['message'], 'fast voll'), 'Der Zwischenspeicher meldet den Grund');
});

Runner::test('Nachrichtenfluss: Zwischenspeicher ohne Quota ist aus und nennt die Belegung', static function (): void {
    $input = flowInput();
    $input['cache']['quota'] = 0;
    $input['cache']['percent'] = 0;
    $flow = OrvantaFlowService::evaluate($input);

    $cache = flowNode($flow, 'cache');
    Assert::same('off', $cache['state']);
    Assert::false($cache['alert']);
    Assert::same('Ohne feste Grenze', $cache['subtitle']);
    Assert::same('–', flowKpi($flow, 'cache')['value']);
    Assert::same('ok', $flow['overall']['state'], 'Ohne Quota entsteht keine Störung');
});

Runner::test('Nachrichtenfluss: Tier zeigt Belegung in GB und Zustand je Tier', static function (): void {
    $input = flowInput();
    $input['storage']['targets'][0]['total_bytes'] = 4 * 1024 ** 3;
    $input['storage']['targets'][0]['free_bytes'] = 1 * 1024 ** 3;
    $input['storage']['targets'][0]['fill'] = ['percent' => 75.0, 'state' => 'degraded'];
    $flow = OrvantaFlowService::evaluate($input);

    $tier = flowNode($flow, 'tier-1');
    Assert::same('warn', $tier['state'], 'Ein füllender Tier ist eingeschränkt');
    Assert::same('SMB', $tier['subtitle']);
    Assert::true(in_array(['label' => 'Belegt', 'value' => '3,0 GB von 4,0 GB', 'state' => 'warn'], $tier['facts'], true), 'Belegung wird in GB genannt');
    Assert::same(
        [
            ['label' => 'Tier A', 'value' => 'In Ordnung', 'state' => 'ok', 'title' => 'Tier A: In Ordnung'],
            ['label' => 'Tier A Teil 2 (Erweiterung)', 'value' => 'In Ordnung', 'state' => 'ok', 'title' => 'Tier A Teil 2: In Ordnung'],
        ],
        $tier['members']
    );

    Assert::same('3,0 GB von 4,0 GB belegt', flowKpi($flow, 'storage')['hint']);
    Assert::same('1 von 1', flowKpi($flow, 'storage')['value']);
});

Runner::test('Nachrichtenfluss: gestörter Tier ist rot, deaktivierter Tier ausgegraut', static function (): void {
    $input = flowInput();
    $input['storage']['targets'][0]['state'] = 'offline';
    $input['storage']['targets'][0]['message'] = 'Der Freigabepfad ist nicht erreichbar.';
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('error', flowNode($flow, 'tier-1')['state']);
    Assert::true(flowNode($flow, 'tier-1')['muted']);
    Assert::same('Der Freigabepfad ist nicht erreichbar.', flowNode($flow, 'tier-1')['message']);

    $input = flowInput();
    $input['storage']['targets'][0]['state'] = 'disabled';
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('off', flowNode($flow, 'tier-1')['state']);
    Assert::true(flowNode($flow, 'tier-1')['muted']);
    Assert::false(flowNode($flow, 'tier-1')['alert']);
});

Runner::test('Nachrichtenfluss: Tier ohne feste Kapazität nennt keine Belegung', static function (): void {
    $input = flowInput();
    $input['storage']['targets'][0]['unbounded'] = true;
    $input['storage']['targets'][0]['kind'] = 's3';
    $input['storage']['targets'][0]['total_bytes'] = 0;
    $input['storage']['targets'][0]['free_bytes'] = 0;
    $input['storage']['targets'][0]['fill'] = ['percent' => null, 'state' => 'disabled'];
    $flow = OrvantaFlowService::evaluate($input);

    $tier = flowNode($flow, 'tier-1');
    Assert::same('S3', $tier['subtitle']);
    Assert::true(in_array(['label' => 'Belegt', 'value' => 'ohne feste Kapazität', 'state' => ''], $tier['facts'], true));
    Assert::same('Keine Kapazität hinterlegt', flowKpi($flow, 'storage')['hint']);
});

Runner::test('Nachrichtenfluss: KI-Endpunkt zeigt Wolke, Restzahl und Gesamtanfragen', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    $ai = flowNode($flow, 'ai');
    Assert::same('ok', $ai['state']);
    Assert::same('Lokale KI', $ai['title']);
    Assert::same(2, count($ai['cloud']));
    Assert::same('Top-10 Nutzer (30 Tage)', $ai['cloud_title']);
    Assert::same('5 weitere Nutzer in den letzten 30 Tagen', $ai['cloud_more']);
    Assert::true(in_array(['label' => 'Anfragen 30 Tage', 'value' => '49', 'state' => ''], $ai['facts'], true));

    $kpi = flowKpi($flow, 'ai');
    Assert::same('49', $kpi['value']);
    Assert::same('7 Nutzer, Top-10 in der Wolke', $kpi['hint']);
});

Runner::test('Nachrichtenfluss: freigegebene Namensanzeige zeigt Kennungen, sonst Pseudonyme', static function (): void {
    $input = flowInput();
    $input['ai']['names'] = true;
    $input['ai']['top'] = [['label' => 'anna@hh.example', 'value' => 42, 'title' => 'anna@hh.example: 42 Anfragen']];
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('anna@hh.example', flowNode($flow, 'ai')['cloud'][0]['label']);

    $flow = OrvantaFlowService::evaluate(flowInput());
    Assert::same('Benutzer 1', flowNode($flow, 'ai')['cloud'][0]['label']);
});

Runner::test('Nachrichtenfluss: deaktivierter oder unkonfigurierter KI-Endpunkt ist aus bzw. eingeschränkt', static function (): void {
    $input = flowInput();
    $input['ai']['enabled'] = false;
    $input['ai']['active'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('off', flowNode($flow, 'ai')['state']);
    Assert::false(flowNode($flow, 'ai')['alert']);
    Assert::same('off', flowKpi($flow, 'ai')['state']);

    $input = flowInput();
    $input['ai']['active'] = false;
    $input['ai']['configured'] = false;
    $input['ai']['url'] = '';
    $input['ai']['model'] = '';
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('warn', flowNode($flow, 'ai')['state']);
    Assert::true(str_contains(flowNode($flow, 'ai')['message'], 'nicht vollständig konfiguriert'));

    $input = flowInput();
    $input['ai']['active'] = false;
    $input['ai']['has_key'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('warn', flowNode($flow, 'ai')['state']);
    Assert::true(str_contains(flowNode($flow, 'ai')['message'], 'Zugangsschlüssel'));
});

Runner::test('Nachrichtenfluss: Spuren tragen den schlechtesten Zustand ihrer Knoten', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());
    $lanes = [];
    foreach ($flow['lanes'] as $lane) {
        $lanes[$lane['key']] = $lane;
    }

    Assert::same('Proxy-Pfad (IMAP/SMTP)', $lanes['proxy']['title']);
    Assert::same(['source-0', 'proxy'], $lanes['proxy']['nodes']);
    Assert::same(['host-1', 'users'], $lanes['exchange']['nodes']);
    Assert::same('ok', $lanes['proxy']['state']);
    Assert::same('ok', $lanes['exchange']['state']);

    $input = flowInput();
    $input['exchange']['hosts'][0]['status'] = 'offline';
    $flow = OrvantaFlowService::evaluate($input);
    foreach ($flow['lanes'] as $lane) {
        if ($lane['key'] === 'exchange') {
            Assert::same('error', $lane['state'], 'Die Exchange-Spur übernimmt die Störung');
        }
    }
});

Runner::test('Nachrichtenfluss: Spuren nennen ihren Zustand im Klartext', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());
    foreach ($flow['lanes'] as $lane) {
        Assert::same('In Ordnung', $lane['state_label'], 'Die Spur „' . $lane['key'] . '“ nennt ihren Zustand');
    }

    $input = flowInput();
    $input['proxy']['ok'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    foreach ($flow['lanes'] as $lane) {
        $expected = $lane['key'] === 'proxy' ? 'Störung' : 'In Ordnung';
        Assert::same($expected, $lane['state_label'], 'Die Spur „' . $lane['key'] . '“ trägt ihren eigenen Zustand');
    }
});

Runner::test('Nachrichtenfluss: gedämpfte Knoten nennen den Grund im Klartext', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());
    Assert::same('', flowNode($flow, 'source-0')['muted_label'], 'Ein gesunder Knoten nennt keinen Grund');

    $input = flowInput();
    $input['proxy']['ok'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    $source = flowNode($flow, 'source-0');
    Assert::true($source['muted'], 'Die Quelle ist gedämpft');
    Assert::true(str_contains($source['muted_label'], $source['muted_reason']), 'Der Grund steht im Klartext');
    Assert::same('Werte ausgegraut (Proxy nicht erreichbar)', $source['muted_label']);

    $input = flowInput();
    $input['proxy']['ok'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    $users = flowNode($flow, 'users');
    Assert::false($users['muted'], 'Der Nutzerknoten bleibt erreichbar');
    Assert::same('', $users['muted_label'], 'Ein erreichbarer Knoten nennt keinen Grund');
});

Runner::test('Nachrichtenfluss: Kanten verbinden Quellen, Hosts und Endpunkte', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    Assert::true(in_array(['from' => 'source-0', 'to' => 'proxy', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Quelle → Proxy');
    Assert::true(in_array(['from' => 'host-1', 'to' => 'users', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Host → Nutzer');
    Assert::true(in_array(['from' => 'proxy', 'to' => 'users', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Proxy → Nutzer');
    Assert::true(in_array(['from' => 'ai', 'to' => 'users', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'KI → Nutzer');
    Assert::true(in_array(['from' => 'cache', 'to' => 'users', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Zwischenspeicher → Nutzer');

    $input = flowInput();
    $input['proxy']['ok'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::true(in_array(['from' => 'source-0', 'to' => 'proxy', 'state' => 'error', 'label' => ''], $flow['edges'], true), 'Gestörte Quelle färbt die Kante rot');
    Assert::true(in_array(['from' => 'proxy', 'to' => 'users', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Der Nutzerknoten bleibt über Exchange erreichbar');
});

/**
 * Identitaetsquelle ohne Proxy-Konfiguration (Transportweg Exchange).
 *
 * @return array<string,mixed>
 */
function flowExchangeSource(int $id = 7, bool $active = true): array
{
    return [
        'id' => $id,
        'label' => 'Werk Süd',
        'domain' => 'sued.example',
        'active' => $active,
        'primary' => false,
        'transport' => OrvantaFlowService::TRANSPORT_EXCHANGE,
        'counts' => ['mailboxes' => 0, 'active_mailboxes' => 0, 'mapped_mailboxes' => 0, 'active_mapped_mailboxes' => 0, 'free_mailboxes' => 0, 'mappings' => 0],
        'state' => ['last_success_at' => '', 'last_error_at' => '', 'last_error' => '', 'failures' => 0, 'checked_at' => ''],
    ];
}

Runner::test('Nachrichtenfluss: Quelle ohne Proxy-Konfiguration steht im Exchange-Pfad vor den Hosts', static function (): void {
    $input = flowInput();
    $input['sources'][] = flowExchangeSource();
    $flow = OrvantaFlowService::evaluate($input);

    $lanes = [];
    foreach ($flow['lanes'] as $lane) {
        $lanes[$lane['key']] = $lane;
    }
    Assert::same(['source-0', 'proxy'], $lanes['proxy']['nodes'], 'Der Proxy-Pfad enthält nur Quellen mit Mailserver');
    Assert::same(['source-7', 'host-1', 'users'], $lanes['exchange']['nodes'], 'Die Exchange-Quelle steht vor dem Host');

    $source = flowNode($flow, 'source-7');
    Assert::same('ok', $source['state'], 'Eine nie geprüfte Exchange-Quelle ist keine Einschränkung');
    Assert::same(OrvantaFlowService::TRANSPORT_EXCHANGE, $source['transport']);
    Assert::same([], $source['cloud'], 'Keine Postfachwolke ohne Proxy');
    Assert::same(0, $flow['overall']['warnings']);
    Assert::same('ok', $flow['overall']['state']);
    Assert::true(in_array(['from' => 'source-7', 'to' => 'host-1', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Quelle → Exchange-Host');
    Assert::false(in_array(['from' => 'source-7', 'to' => 'proxy', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Keine Kante zum Proxy');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, flowNode($flow, 'source-0')['transport']);
});

Runner::test('Nachrichtenfluss: Transportweg einer Quelle folgt der Domäne ihrer Verzeichnisserver', static function (): void {
    Assert::same(['khwf.de'], OrvantaFlowService::hostDomains(['exchange01.khwf.de', 'EXCHANGE02.KHWF.DE.', '10.0.0.5', 'exchange03', '']), 'IP-Adressen und kurze Namen liefern keine Domäne');
    Assert::same(['mvzintsz.local', 'khwf.de'], OrvantaFlowService::hostDomains(['dc01.mvzintsz.local', 'dc01.khwf.de']));

    $exchange = OrvantaFlowService::hostDomains(['exchange01.khwf.de', 'exchange02.khwf.de']);
    Assert::same(OrvantaFlowService::TRANSPORT_EXCHANGE, OrvantaFlowService::transportFor(['dc01.khwf.de', 'dc02.khwf.de'], $exchange, false), 'Gleiche Domäne wie die Exchange-Hosts → Exchange-Ast');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, OrvantaFlowService::transportFor(['dc01.mvzintsz.local'], $exchange, false), 'Abweichende Domäne → Proxy, auch ohne Mailserver');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, OrvantaFlowService::transportFor(['192.168.10.2'], $exchange, false), 'IP-Adresse → Proxy');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, OrvantaFlowService::transportFor(['dc01'], $exchange, false), 'Kurzer Hostname → Proxy');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, OrvantaFlowService::transportFor([], $exchange, false), 'Ohne Server → Proxy');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, OrvantaFlowService::transportFor(['dc01.khwf.de'], $exchange, true), 'Hinterlegter Mailserver legt den Proxy-Weg fest');
    Assert::same(OrvantaFlowService::TRANSPORT_PROXY, OrvantaFlowService::transportFor(['dc01.khwf.de'], [], false), 'Ohne Exchange-Hosts gibt es keinen Exchange-Ast');
    Assert::same(OrvantaFlowService::TRANSPORT_EXCHANGE, OrvantaFlowService::transportFor(['10.0.0.9', 'DC01.KHWF.DE'], $exchange, false), 'Ein passender FQDN genügt (Groß-/Kleinschreibung egal)');

    $input = flowInput();
    $input['sources'][0]['proxied'] = false;
    $input['sources'][0]['state']['last_success_at'] = '';
    $node = flowNode(OrvantaFlowService::evaluate($input), 'source-0');
    Assert::same('warn', $node['state'], 'Proxy-Quelle ohne Mailserver bleibt eine Einschränkung');
    Assert::same('Für diese Identitätsquelle ist im Proxy noch kein Mailserver hinterlegt.', $node['message']);
});

Runner::test('Nachrichtenfluss: Exchange-Quelle folgt den Hosts – Ausfall aller Hosts dämpft sie, Proxy-Ausfall nicht', static function (): void {
    $input = flowInput();
    $input['sources'][] = flowExchangeSource();
    $input['proxy']['ok'] = false;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('ok', flowNode($flow, 'source-7')['state'], 'Der Proxy-Ausfall berührt die Exchange-Quelle nicht');
    Assert::false(flowNode($flow, 'source-7')['muted']);

    $input = flowInput();
    $input['sources'][] = flowExchangeSource();
    $input['exchange']['hosts'][0]['status'] = 'offline';
    $flow = OrvantaFlowService::evaluate($input);
    $source = flowNode($flow, 'source-7');
    Assert::same('error', $source['state']);
    Assert::true($source['muted']);
    Assert::same('Werte ausgegraut (Exchange nicht erreichbar)', $source['muted_label']);
    Assert::true(in_array(['from' => 'source-7', 'to' => 'host-1', 'state' => 'error', 'label' => ''], $flow['edges'], true));

    $input = flowInput();
    $input['sources'][] = flowExchangeSource(7, false);
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('off', flowNode($flow, 'source-7')['state'], 'Eine deaktivierte Quelle ist aus');

    $input = flowInput();
    $input['sources'][] = flowExchangeSource();
    $input['exchange'] = ['available' => false, 'configured' => false, 'hosts' => [], 'sessions' => [], 'totals' => []];
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('off', flowNode($flow, 'source-7')['state'], 'Ohne Exchange gibt es keinen Transportweg');
    Assert::true(in_array(['from' => 'source-7', 'to' => 'users', 'state' => 'ok', 'label' => ''], $flow['edges'], true), 'Ohne Hosts zeigt die Kante auf die Nutzer');
});

Runner::test('Nachrichtenfluss: Störungen werden mit Titel, Meldung und Verweis gesammelt', static function (): void {
    $input = flowInput();
    $input['proxy']['ok'] = false;
    $input['storage']['targets'][0]['state'] = 'offline';
    $flow = OrvantaFlowService::evaluate($input);

    Assert::same(3, count($flow['incidents']));
    Assert::same('proxy', $flow['incidents'][0]['key']);
    Assert::same('IMAP-/SMTP-Proxy', $flow['incidents'][0]['title']);
    Assert::same('/admin/office/mail-proxy', $flow['incidents'][0]['url']);
    Assert::same('tier-1', $flow['incidents'][2]['key']);
    Assert::true(str_contains($flow['overall']['message'], 'Offene Vorfälle: 3'), 'Der Gesamtstatus nennt die Anzahl');
});

Runner::test('Nachrichtenfluss: fehlende Bereiche verhindern die Auswertung nicht', static function (): void {
    $flow = OrvantaFlowService::evaluate(['now' => 1_700_000_000]);

    Assert::same('off', flowNode($flow, 'proxy')['state']);
    Assert::same('warn', flowNode($flow, 'users')['state']);
    Assert::same('off', flowNode($flow, 'ai')['state']);
    Assert::same('off', flowNode($flow, 'cache')['state']);
    Assert::same([], $flow['incidents']);
    Assert::same('warn', $flow['overall']['state'], 'Ohne Messwerte bleibt es bei einer Einschränkung');
    Assert::same(8, count($flow['kpis']), 'Alle Kennzahlen sind auch ohne Daten vorhanden');
});

Runner::test('Nachrichtenfluss: Zwischenspeicher-Gesamtbelegung wird über alle Nutzer summiert', static function (): void {
    $pdo = flowPdo();
    $pdo->exec("CREATE TABLE orvanta_cache_items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_uid TEXT NOT NULL, kind TEXT NOT NULL, item_hash TEXT NOT NULL, name TEXT NOT NULL, path TEXT NOT NULL, content_type TEXT NOT NULL DEFAULT 'application/octet-stream', size_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO orvanta_cache_items (user_uid, kind, item_hash, name, path, size_bytes) VALUES ('anna', 'attachment', 'a', 'A', '/a', 500), ('anna', 'attachment', 'b', 'B', '/b', 300), ('bob', 'attachment', 'c', 'C', '/c', 200)");

    $totals = (new OrvantaRepository($pdo))->cacheTotals();
    Assert::same(3, $totals['items']);
    Assert::same(1000, $totals['bytes']);
    Assert::same(2, $totals['users']);

    $perUser = (new OrvantaRepository($pdo))->cacheUsagePerUser(10);
    Assert::same('anna', $perUser[0]['user_uid']);
    Assert::same(800, $perUser[0]['bytes']);
});

Runner::test('Nachrichtenfluss: Gesamtbelegung ohne Einträge ist null', static function (): void {
    $pdo = flowPdo();
    $pdo->exec("CREATE TABLE orvanta_cache_items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_uid TEXT NOT NULL, kind TEXT NOT NULL, item_hash TEXT NOT NULL, name TEXT NOT NULL, path TEXT NOT NULL, content_type TEXT NOT NULL DEFAULT 'application/octet-stream', size_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");

    $totals = (new OrvantaRepository($pdo))->cacheTotals();
    Assert::same(['items' => 0, 'bytes' => 0, 'users' => 0], $totals);
});

// ------------------------------------------------------------------- Ansicht

/**
 * Rendert die Dashboard-Ansicht mit genau den Variablen, die
 * OrvantaFlowController::index() bereitstellt.
 *
 * @param callable(array<string,mixed>&):void|null $modify Aenderungen an der Eingabe
 */
function flowRender(?callable $modify = null): string
{
    $_SESSION = [];
    $input = flowInput();
    if ($modify !== null) {
        $modify($input);
    }
    $flow = OrvantaFlowService::evaluate($input);
    $base = '/admin/office/orvanta/nachrichtenfluss';
    $orvantaEnabled = true;
    $orvantaDemo = false;
    $refreshInterval = 120;
    $checkLimit = 16;
    $checkableSources = [['id' => 0, 'label' => 'Zentrale (primär)'], ['id' => 5, 'label' => 'Zweigstelle Hamburg']];

    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/orvanta-flow.php';

    return (string) ob_get_clean();
}

Runner::test('Nachrichtenfluss: Ansicht ist ohne Daten vollständig und ohne Inline-Stile', static function (): void {
    $html = flowRender();

    Assert::contains('data-orvanta-flow', $html);
    Assert::contains('data-refresh-url="/admin/office/orvanta/nachrichtenfluss/daten"', $html);
    Assert::contains('data-refresh-interval="120"', $html);
    Assert::contains('data-flow-refresh', $html, 'Schalter zum manuellen Aktualisieren');
    Assert::contains('data-flow-head', $html);
    Assert::contains('data-flow-incident-list', $html);
    Assert::false(str_contains($html, 'style="'), 'keine Inline-Stile (CSP verbietet sie)');
    Assert::false(str_contains($html, 'Array'), 'keine durchgereichten Arrays');
    Assert::false(str_contains($html, '<script>'), 'keine eingebetteten Skripte');
});

Runner::test('Nachrichtenfluss: Ansicht bindet jede Kennzahl, jeden Knoten und jede Spur an Datenattribute', static function (): void {
    $html = flowRender();

    foreach (['users', 'sources', 'mapping', 'proxy', 'exchange', 'storage', 'cache', 'ai'] as $key) {
        Assert::contains('data-flow-kpi-item="' . $key . '"', $html);
        Assert::contains('data-flow-kpi="' . $key . '"', $html);
        Assert::contains('data-flow-kpi-hint="' . $key . '"', $html);
    }
    foreach (['proxy', 'source-0', 'host-1', 'users', 'ai', 'cache', 'tier-local', 'tier-1', 'tier-snapshot'] as $key) {
        Assert::contains('data-flow-node="' . $key . '"', $html);
        Assert::contains('id="flow-' . $key . '"', $html);
        Assert::contains('data-flow-node-state="', $html);
    }
    Assert::same(2, substr_count($html, 'data-flow-lane-label'), 'genau eine Zustandsbezeichnung je Spur');
    Assert::contains('data-flow-lane="proxy"', $html);
    Assert::contains('data-flow-lane="exchange"', $html);
    Assert::contains('id="flow-proxy-lane"', $html);
    Assert::contains('id="flow-exchange"', $html);
    Assert::contains('data-flow-edge="source-0&gt;proxy"', $html);
    Assert::contains('data-flow-edge="host-1&gt;users"', $html);
    Assert::contains('data-flow-edge="ai&gt;users"', $html);
    Assert::contains('data-flow-edge="cache&gt;users"', $html);
});

Runner::test('Nachrichtenfluss: Ansicht markiert Störungen mit Ausrufezeichen und blendet die Liste ein', static function (): void {
    $healthy = flowRender();
    Assert::false(str_contains($healthy, 'flow-alert'), 'ohne Störung kein Ausrufezeichen');
    Assert::contains('data-flow-incidents hidden', $healthy, 'leere Störungsliste bleibt ausgeblendet');

    $broken = flowRender(static function (array &$input): void {
        $input['proxy']['ok'] = false;
        $input['proxy']['message'] = 'Der Proxy-Dienst antwortet nicht.';
    });

    Assert::contains('data-flow-node="proxy" data-flow-node-state="error"', $broken);
    Assert::contains('flow-alert', $broken, 'rotes Ausrufezeichen an der Störung');
    Assert::false(str_contains($broken, 'data-flow-incidents hidden'), 'gefüllte Störungsliste ist sichtbar');
    Assert::contains('IMAP-/SMTP-Proxy', $broken);
    Assert::contains('Beheben', $broken, 'Verweis auf die zuständige Verwaltungsseite');
});

Runner::test('Nachrichtenfluss: Ansicht graut bei Proxy-Ausfall Quellen und Postfächer aus', static function (): void {
    $html = flowRender(static function (array &$input): void {
        $input['proxy']['ok'] = false;
    });

    Assert::contains('data-flow-node="source-0" data-flow-node-state="error"', $html);
    Assert::contains('flow-node--muted', $html, 'gedämpfter Knoten');
    Assert::contains('Werte ausgegraut (Proxy nicht erreichbar)', $html);
    Assert::contains('flow-cloud--muted', $html, 'ausgegraute Postfachwolke');
    Assert::true(
        substr_count($html, 'data-flow-node-muted hidden') < substr_count($html, 'data-flow-node-muted'),
        'mindestens ein Ausgrauhinweis ist sichtbar'
    );
});

Runner::test('Nachrichtenfluss: Ansicht zeigt bei Quellenstörung Ausrufezeichen ohne die Quelle auszugrauen', static function (): void {
    $html = flowRender(static function (array &$input): void {
        $input['sources'][0]['state']['last_error_at'] = '2023-11-14 21:30:00';
        $input['sources'][0]['state']['last_error'] = 'Anmeldung abgelehnt.';
        $input['sources'][0]['state']['checked_at'] = '2023-11-14 21:30:00';
    });

    Assert::contains('data-flow-node="source-0" data-flow-node-state="error"', $html);
    Assert::contains('data-flow-node="proxy" data-flow-node-state="ok"', $html, 'der Proxy bleibt in Ordnung');
    Assert::contains('Anmeldung abgelehnt.', $html);
    Assert::contains('flow-cloud--muted', $html, 'nur die Postfächer der Quelle sind ausgegraut');
    Assert::false(str_contains($html, 'Werte ausgegraut'), 'die Quelle selbst bleibt lesbar');
});

Runner::test('Nachrichtenfluss: Ansicht graut bei Host-Störung nur dessen Clients aus', static function (): void {
    $html = flowRender(static function (array &$input): void {
        $input['exchange']['hosts'][0]['status'] = 'offline';
        $input['exchange']['hosts'][0]['last_error'] = 'Der Host antwortet nicht.';
    });

    Assert::contains('data-flow-node="host-1" data-flow-node-state="error"', $html);
    Assert::contains('Der Host antwortet nicht.', $html);
    Assert::contains('flow-cloud--muted', $html, 'ausgegraute Clientwolke');
    Assert::contains('data-flow-node="users" data-flow-node-state="ok"', $html, 'der Nutzerknoten bleibt erreichbar');
});

Runner::test('Nachrichtenfluss: Ansicht zeigt Wolken, Werte als Tabelle und den Verlauf', static function (): void {
    $html = flowRender(static function (array &$input): void {
        $input['history']['series'] = [14 => ['days' => 14, 'max' => 9, 'points' => [
            ['day' => '2023-11-13', 'value' => 4],
            ['day' => '2023-11-14', 'value' => 9],
        ]]];
    });

    Assert::contains('Postfächer der Identitätsquelle', $html);
    Assert::contains('Verbundene Clients', $html);
    Assert::contains('Top-10 Nutzer (30 Tage)', $html);
    Assert::contains('Größte Zwischenspeicher je Nutzer', $html);
    Assert::contains('Werte als Tabelle', $html);
    Assert::contains('data-flow-node="tier-1" data-flow-node-state="ok"', $html);
    Assert::contains('class="cloud"', $html);
    Assert::contains('ov-flow-chart', $html);
    Assert::contains('flow-legend', $html, 'Legende der Zeiträume');
    Assert::false(str_contains($html, 'Keine Verlaufsdaten vorhanden.'), 'mit Proben wird gezeichnet');
});

Runner::test('Nachrichtenfluss: Ansicht zeichnet den Verlauf erst mit Proben', static function (): void {
    $html = flowRender();

    Assert::false(str_contains($html, 'flow-overlay'), 'ohne Proben kein Verlaufselement');
    Assert::false(str_contains($html, '<polyline'), 'ohne Proben keine Linien');
    Assert::false(str_contains($html, 'flow-legend'), 'ohne Proben keine Legende');
    Assert::contains('data-flow-node="users"', $html, 'der Nutzerknoten bleibt sichtbar');
});

Runner::test('Nachrichtenfluss: Prüfformular nutzt CSRF und begrenzt die Anzahl der Quellen', static function (): void {
    $html = flowRender();

    Assert::contains('method="post" action="/admin/office/orvanta/nachrichtenfluss/quellen/pruefen"', $html);
    Assert::contains('name="_token"', $html);
    Assert::contains('name="source"', $html);
    Assert::contains('Alle aktiven Quellen', $html);
    Assert::contains('Zweigstelle Hamburg', $html);
    Assert::contains('bei mehr als 16 aktiven Quellen', $html, 'die Obergrenze steht im Klartext');
});

Runner::test('Nachrichtenfluss: Ansicht nennt den Ausfall der Quelle auch ohne Prüfmöglichkeit', static function (): void {
    $html = flowRender(static function (array &$input): void {
        $input['sources'] = [];
    });

    Assert::contains('Identitätsquellen', $html);
    Assert::contains('data-flow-node="proxy"', $html);
    Assert::contains('Keine Verbindungen erfasst.', $html, 'die Proxy-Spur bleibt ohne Quelle leer');
});

// ------------------------------------------------------------------ Verdrahtung

Runner::test('Nachrichtenfluss: Routen, Navigation und Skript sind verdrahtet', static function (): void {
    $root = dirname(__DIR__, 2);
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach ([
        "\$router->get('/admin/office/orvanta/nachrichtenfluss', [OrvantaFlowController::class, 'index']);" => 'index',
        "\$router->get('/admin/office/orvanta/nachrichtenfluss/daten', [OrvantaFlowController::class, 'data']);" => 'data',
        "\$router->post('/admin/office/orvanta/nachrichtenfluss/quellen/pruefen', [OrvantaFlowController::class, 'checkSources']);" => 'checkSources',
    ] as $route => $method) {
        Assert::contains($route, $routes);
        Assert::true(method_exists(\App\Controllers\Admin\OrvantaFlowController::class, $method), $method . ' fehlt.');
    }

    Assert::contains("office_orvanta_flow", (string) file_get_contents($root . '/views/layouts/admin.php'));
    Assert::contains("'office_orvanta_flow'", (string) file_get_contents($root . '/app/Controllers/Admin/OrvantaFlowController.php'));

    $script = (string) file_get_contents($root . '/public/assets/js/admin-orvanta-flow.js');
    Assert::contains('data-orvanta-flow', $script);
    Assert::contains('data-refresh-url', $script);
    Assert::contains('data-flow-refresh', $script);
    Assert::false(str_contains($script, 'innerHTML'), 'die Liste wird ohne innerHTML aufgebaut');
    Assert::false(str_contains($script, 'eval('), 'kein dynamischer Code');
    Assert::contains('document.hidden', $script, 'die Aktualisierung pausiert im Hintergrund');
    Assert::contains('data-flow-node-muted', $script);
    Assert::contains('flow-cloud--muted', $script, 'das Ausgrauen der Wolken wird live nachgeführt');

    Assert::same(16, \App\Controllers\Admin\OrvantaFlowController::MAX_CHECK_SOURCES);
    Assert::same('/admin/office/orvanta/nachrichtenfluss', \App\Controllers\Admin\OrvantaFlowController::BASE);
});

Runner::test('Nachrichtenfluss: Gestaltung deckt alle Zustände und die dunkle Darstellung ab', static function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');

    foreach ([
        '.flow-head', '.flow-incidents', '.flow-alert', '.flow-kpis', '.flow-kpi',
        '.flow-lane', '.flow-edges', '.flow-edge', '.flow-grid', '.flow-node',
        '.flow-node--muted', '.flow-node--error', '.flow-facts', '.flow-cloud-block',
        '.flow-members', '.cloud', '.cloud__word--l1', '.cloud__word--l5', '.flow-cloud--muted',
        '.flow-overlay', '.flow-chart', '.ov-flow-chart', '.ov-flow-line', '.ov-flow-axis-label',
        '.flow-legend', '.flow-legend__swatch--d14', '.flow-legend__swatch--d365',
    ] as $selector) {
        Assert::contains($selector, $css);
    }
    Assert::contains('[data-theme="dark"] .flow-edge--error', $css, 'dunkle Darstellung der Störungen');
    Assert::same(substr_count($css, '{'), substr_count($css, '}'), 'die Gestaltung ist ausbalanciert');
});

// ------------------------------------------------------------------ Topologie-Ansicht

/**
 * Rendert die Topologie-Ansicht mit genau den Variablen, die
 * OrvantaFlowController::topology() bereitstellt.
 *
 * @param callable(array<string,mixed>&):void|null $modify Aenderungen an der Eingabe
 */
function flowTopologyRender(?callable $modify = null): string
{
    $_SESSION = [];
    $input = flowInput();
    if ($modify !== null) {
        $modify($input);
    }
    $flow = OrvantaFlowService::evaluate($input);
    unset($flow['history']);
    $base = '/admin/office/orvanta/nachrichtenfluss';
    $orvantaEnabled = true;
    $orvantaDemo = false;
    $refreshInterval = 120;

    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/orvanta-flow-topology.php';

    return (string) ob_get_clean();
}

Runner::test('Topologie: Ansicht liefert Hülle, Startdaten als JSON und Noscript-Liste ohne Inline-Stile', static function (): void {
    $html = flowTopologyRender();

    Assert::contains('data-flow-topology', $html);
    Assert::contains('data-refresh-url="/admin/office/orvanta/nachrichtenfluss/daten"', $html);
    Assert::contains('data-refresh-interval="120"', $html);
    Assert::contains('data-overall-state="ok"', $html);
    Assert::contains('<script type="application/json" data-flow-initial>', $html, 'Startdaten als nicht ausführbares JSON');
    Assert::false(str_contains($html, '<script>'), 'kein ausführbares Inline-Skript');
    Assert::false(str_contains($html, 'style="'), 'keine Inline-Stile (CSP verbietet sie)');
    Assert::false(str_contains($html, 'Array'), 'keine durchgereichten Arrays');
    Assert::contains('data-topo-canvas', $html);
    Assert::contains('data-topo-panel', $html);
    Assert::contains('data-topo-log-list', $html);
    Assert::contains('data-topo-incident-list', $html);
    Assert::contains('data-topo-kind-filter', $html);
    foreach (['mode-3d', 'mode-2d', 'rotate', 'particles', 'labels', 'focus-problems', 'zoom-in', 'zoom-out', 'fit', 'reset', 'refresh', 'fullscreen'] as $action) {
        Assert::contains('data-topo-action="' . $action . '"', $html, 'Werkzeug ' . $action);
    }
    Assert::contains('<noscript>', $html);
    Assert::contains('ex01.hh.example', $html, 'Noscript-Liste nennt die Knoten');
    Assert::contains('href="/admin/office/orvanta/nachrichtenfluss"', $html, 'Rücksprung zum Kartendashboard');

    preg_match('/<script type="application\/json" data-flow-initial>(.*?)<\/script>/s', $html, $match);
    $json = json_decode($match[1] ?? '', true);
    Assert::true(is_array($json), 'eingebettetes JSON ist gültig');
    Assert::true(isset($json['nodes']['users'], $json['nodes']['proxy'], $json['edges']), 'Knoten und Kanten eingebettet');
    Assert::false(isset($json['history']), 'kein Verlauf im Netz');
    Assert::false(isset($json['nodes']['users']['chart']), 'keine Verlaufsgrafik im Netz');
    Assert::false(str_contains($match[1] ?? '<', '<'), 'spitze Klammern im JSON sind maskiert');
});

Runner::test('Topologie: Ansicht zeigt Störungen im Band und im Gesamtstatus', static function (): void {
    $html = flowTopologyRender(static function (array &$input): void {
        $input['proxy'] = array_merge($input['proxy'], ['ok' => false, 'message' => 'Verbindung verweigert.']);
    });

    Assert::contains('data-overall-state="error"', $html);
    Assert::contains('topo-incidents--open', $html);
    Assert::contains('data-topo-focus="proxy"', $html, 'Störung springt zum Knoten');
    Assert::contains('href="/admin/office/mail-proxy"', $html, 'Link zur Behebung');
    Assert::contains('topo-pulse--error', $html);
});

Runner::test('Topologie: Ansicht nennt deaktiviertes Orvanta und Demo-Modus', static function (): void {
    $_SESSION = [];
    $flow = OrvantaFlowService::evaluate(flowInput());
    $base = '/admin/office/orvanta/nachrichtenfluss';
    $refreshInterval = 60;

    $orvantaEnabled = false;
    $orvantaDemo = false;
    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/orvanta-flow-topology.php';
    Assert::contains('Orvanta ist nicht aktiviert', (string) ob_get_clean());

    $orvantaEnabled = true;
    $orvantaDemo = true;
    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/orvanta-flow-topology.php';
    Assert::contains('Demo-Modus', (string) ob_get_clean());
});

Runner::test('Topologie: Route, Verweis, Skript und Gestaltung sind verdrahtet', static function (): void {
    $root = dirname(__DIR__, 2);
    $routes = (string) file_get_contents($root . '/public/index.php');
    Assert::contains("\$router->get('/admin/office/orvanta/nachrichtenfluss/topologie', [OrvantaFlowController::class, 'topology']);", $routes);
    Assert::true(method_exists(\App\Controllers\Admin\OrvantaFlowController::class, 'topology'));

    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/OrvantaFlowController.php');
    Assert::contains("'layouts.editor'", $controller, 'eigener Tab ohne Seitenmenü');
    Assert::contains("'admin-orvanta-flow-topology.js'", $controller);
    Assert::contains("'orvanta-flow-topology.css'", $controller);
    Assert::contains('overview(false)', $controller, 'ohne Verlauf');

    $card = flowRender();
    Assert::contains('href="/admin/office/orvanta/nachrichtenfluss/topologie" target="_blank" rel="noopener"', $card, 'Kartendashboard verweist in einen neuen Tab');

    $script = (string) file_get_contents($root . '/public/assets/js/admin-orvanta-flow-topology.js');
    Assert::contains('data-flow-topology', $script);
    Assert::contains('data-flow-initial', $script);
    Assert::contains('data-refresh-url', $script);
    Assert::contains('requestAnimationFrame', $script);
    Assert::contains('document.hidden', $script, 'Zeichnen und Abfrage pausieren im Hintergrund');
    Assert::contains('prefers-reduced-motion', $script);
    Assert::contains("'pointerdown'", $script);
    Assert::contains("'wheel'", $script);
    Assert::contains("'keydown'", $script);
    Assert::contains('quadraticCurveTo', $script, 'gebogene Kanten');
    Assert::false(str_contains($script, 'innerHTML'), 'DOM wird ohne innerHTML aufgebaut');
    Assert::false(str_contains($script, 'eval('), 'kein dynamischer Code');
    Assert::false(str_contains($script, 'document.write'), 'kein document.write');
    foreach (['source', 'proxy', 'host', 'users', 'ai', 'cache', 'tier'] as $kind) {
        Assert::contains("case '" . $kind . "'", $script, 'eigenes Symbol für ' . $kind);
    }

    $css = (string) file_get_contents($root . '/public/assets/css/orvanta-flow-topology.css');
    foreach ([
        '.topo', '.topo-head', '.topo-head__status--error', '.topo-pulse--error', '.topo-canvas', '.topo-toolbar',
        '.topo-tool.is-active', '.topo-legend', '.topo-tooltip', '.topo-panel', '.topo-panel__facts', '.topo-log',
        '.topo-log__item--error', '.topo-incidents--open', '.topo-incident', '.topo-noscript',
        '@media (prefers-reduced-motion: reduce)', '@media print',
    ] as $selector) {
        Assert::contains($selector, $css);
    }
    Assert::same(substr_count($css, '{'), substr_count($css, '}'), 'die Gestaltung ist ausbalanciert');
});

// ----------------------------------------------------- Präsenz je Identitätsquelle

Runner::test('Nachrichtenfluss: Aktivität merkt sich die Identitätsquelle des Nutzers', static function (): void {
    $pdo = flowPdo();
    $presence = flowPresence($pdo, static fn (): int => 1700000000);

    $presence->touch('mueller', 'proxy', 5);
    $presence->touch('schmidt', 'exchange');
    $presence->touch('mueller', 'proxy', 5);

    Assert::same(5, (int) $pdo->query("SELECT source_id FROM orvanta_activity WHERE user_uid = 'mueller'")->fetchColumn());
    Assert::same(0, (int) $pdo->query("SELECT source_id FROM orvanta_activity WHERE user_uid = 'schmidt'")->fetchColumn());

    $bySource = (new OrvantaFlowRepository($pdo))->activeUsersBySource(
        date('Y-m-d H:i:s', 1700000000 - 300),
        date('Y-m-d H:i:s', 1700000000)
    );
    Assert::same([0 => 1, 5 => 1], $bySource);
});

Runner::test('Nachrichtenfluss: Probe je Quelle entsteht gemeinsam mit der Gesamtprobe', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $presence = flowPresence($pdo, static fn (): int => $now);
    $presence->touch('a', 'exchange', 0);
    $presence->touch('b', 'exchange', 0);
    $presence->touch('c', 'proxy', 5);

    Assert::true($presence->sample());
    Assert::false($presence->sample(), 'zweite Probe im selben Raster wird verworfen');

    $sampledAt = date('Y-m-d H:i:s', $now - ($now % OrvantaPresenceService::SAMPLE_INTERVAL));
    $rows = $pdo->query('SELECT source_id, active_users FROM orvanta_source_samples ORDER BY source_id')->fetchAll(PDO::FETCH_ASSOC);
    Assert::same([['source_id' => 0, 'active_users' => 2], ['source_id' => 5, 'active_users' => 1]], array_map(static fn (array $r): array => ['source_id' => (int) $r['source_id'], 'active_users' => (int) $r['active_users']], $rows));
    Assert::same([0 => 2, 5 => 1], (new OrvantaFlowRepository($pdo))->sourceUsersAt($sampledAt));
    Assert::same(3, array_sum(array_column($rows, 'active_users')), 'Quellen summieren sich zur Gesamtprobe');
});

Runner::test('Nachrichtenfluss: Kennzahlen je Quelle summieren sich exakt zu den Gesamtwerten', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $presence = flowPresence($pdo, static fn (): int => $now);
    $flow = new OrvantaFlowRepository($pdo);

    // Spitze vor einer Stunde (6 Nutzer: 4 Zentrale, 2 Hamburg), danach weniger.
    $flow->recordSample(date('Y-m-d H:i:s', $now - 3600), 6, 4, 2, 0);
    $flow->recordSourceSamples(date('Y-m-d H:i:s', $now - 3600), [0 => 4, 5 => 2]);
    $flow->recordSample(date('Y-m-d H:i:s', $now - 60), 3, 2, 1, 0);
    $flow->recordSourceSamples(date('Y-m-d H:i:s', $now - 60), [0 => 1, 5 => 2]);
    // Außerhalb der 24 Stunden
    $flow->recordSample(date('Y-m-d H:i:s', $now - 90000), 40, 40, 0, 0);
    $flow->recordSourceSamples(date('Y-m-d H:i:s', $now - 90000), [0 => 40]);

    $presence->touch('a', 'exchange', 0);
    $presence->touch('b', 'proxy', 5);
    $presence->touch('c', 'proxy', 5);

    $stats = $presence->stats();
    Assert::same(3, $stats['current']);
    Assert::same(6, $stats['max']);
    Assert::same(date('Y-m-d H:i:s', $now - 3600), $stats['peak_at']);
    Assert::same(1, $stats['sources'][0]['current']);
    Assert::same(2, $stats['sources'][5]['current']);
    Assert::same(4, $stats['sources'][0]['peak']);
    Assert::same(2, $stats['sources'][5]['peak']);
    Assert::same($stats['current'], array_sum(array_column($stats['sources'], 'current')), 'aktuelle Werte summieren sich');
    Assert::same($stats['max'], array_sum(array_column($stats['sources'], 'peak')), 'Maxima summieren sich zum Gesamtmaximum');
});

Runner::test('Nachrichtenfluss: ohne Proben entspricht das Quellenmaximum dem aktuellen Wert', static function (): void {
    $pdo = flowPdo();
    $presence = flowPresence($pdo, static fn (): int => 1700000000);
    $presence->touch('a', 'exchange', 0);
    $presence->touch('b', 'proxy', 5);

    $stats = $presence->stats();
    Assert::same(['current' => 1, 'peak' => 1], $stats['sources'][0]);
    Assert::same(['current' => 1, 'peak' => 1], $stats['sources'][5]);
    Assert::same($stats['max'], array_sum(array_column($stats['sources'], 'peak')));
});

Runner::test('Nachrichtenfluss: Räumen entfernt auch alte Quellenproben', static function (): void {
    $pdo = flowPdo();
    $now = 1700000000;
    $flow = new OrvantaFlowRepository($pdo);
    $flow->recordSourceSamples(date('Y-m-d H:i:s', $now - 500 * 86400), [0 => 3]);
    $flow->recordSourceSamples(date('Y-m-d H:i:s', $now - 60), [0 => 2]);

    flowPresence($pdo, static fn (): int => $now)->purge();

    Assert::same(1, (int) $pdo->query('SELECT COUNT(*) FROM orvanta_source_samples')->fetchColumn());
});

// --------------------------------------------------------------- Zähler am Badge

Runner::test('Nachrichtenfluss: Knoten tragen Zähler für aktuell und 24 Stunden', static function (): void {
    $input = flowInput();
    $input['presence']['sources'] = [0 => ['current' => 4, 'peak' => 9]];
    $input['ai']['active_users'] = 2;
    $input['ai']['day_users'] = 11;
    $flow = OrvantaFlowService::evaluate($input);

    $users = flowNode($flow, 'users')['counters'];
    Assert::same(4, $users['current']['value']);
    Assert::same(9, $users['peak']['value']);

    $source = flowNode($flow, 'source-0')['counters'];
    Assert::same(4, $source['current']['value']);
    Assert::same(9, $source['peak']['value']);
    Assert::same($users['current']['value'], $source['current']['value'], 'Quelle und Nutzerknoten stimmen überein');

    $ai = flowNode($flow, 'ai')['counters'];
    Assert::same(2, $ai['current']['value']);
    Assert::same(11, $ai['peak']['value']);

    $host = flowNode($flow, 'host-1')['counters'];
    Assert::same(2, $host['current']['value']);
    Assert::false(isset($host['peak']), 'Hosts zeigen nur den aktuellen Wert');

    Assert::same([], flowNode($flow, 'cache')['counters']);
    Assert::same([], flowNode($flow, 'tier-1')['counters']);
});

Runner::test('Nachrichtenfluss: Quellen ohne Präsenzdaten zeigen 0', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());
    $source = flowNode($flow, 'source-0')['counters'];
    Assert::same(0, $source['current']['value']);
    Assert::same(0, $source['peak']['value']);
    Assert::same('0', $source['facts'][0]['value'] ?? flowNode($flow, 'source-0')['facts'][0]['value']);
});

Runner::test('Nachrichtenfluss: Exchange-Quellen tragen Zähler aus der Präsenz', static function (): void {
    $input = flowInput();
    $input['sources'][0]['transport'] = 'exchange';
    $input['presence']['sources'] = [0 => ['current' => 3, 'peak' => 7]];
    $flow = OrvantaFlowService::evaluate($input);

    $source = flowNode($flow, 'source-0');
    Assert::same('exchange', $source['transport']);
    Assert::same(3, $source['counters']['current']['value']);
    Assert::same(7, $source['counters']['peak']['value']);
});

// ----------------------------------------------------------- Speicher-Hierarchie

Runner::test('Nachrichtenfluss: lokaler Speicher ist Wurzel, Cold-Tier und Snapshot hängen daran', static function (): void {
    $flow = OrvantaFlowService::evaluate(flowInput());

    $local = flowNode($flow, 'tier-local');
    Assert::same('tier', $local['kind']);
    Assert::same('ok', $local['state']);
    Assert::true($local['primary']);
    Assert::same('/admin/storage', $local['link']['url']);

    $snapshot = flowNode($flow, 'tier-snapshot');
    Assert::same('ok', $snapshot['state']);
    Assert::same('\\\\nas\\snapshots', $snapshot['subtitle']);

    $edgeKeys = array_map(static fn (array $e): string => $e['from'] . '>' . $e['to'], $flow['edges']);
    Assert::true(in_array('tier-local>cache', $edgeKeys, true), 'lokaler Speicher speist den Zwischenspeicher');
    Assert::true(in_array('tier-1>tier-local', $edgeKeys, true), 'Cold-Tier hängt am lokalen Speicher');
    Assert::true(in_array('tier-snapshot>tier-local', $edgeKeys, true), 'Snapshot hängt am lokalen Speicher');
    Assert::false(in_array('tier-1>cache', $edgeKeys, true));

    Assert::same(['tier-local', 'tier-1', 'tier-snapshot'], $flow['tiers']);
});

Runner::test('Nachrichtenfluss: lokaler Speicher erscheint auch ohne Speicherziele', static function (): void {
    $input = flowInput();
    $input['storage']['targets'] = [];
    $input['storage']['snapshot']['enabled'] = false;
    $flow = OrvantaFlowService::evaluate($input);

    Assert::same(['tier-local'], $flow['tiers']);
    Assert::true(isset($flow['nodes']['tier-local']));
    Assert::false(isset($flow['nodes']['tier-snapshot']));
    Assert::same('0', flowNode($flow, 'tier-local')['facts'][7]['value'], 'keine Cold-Tiers');
});

Runner::test('Nachrichtenfluss: ohne Speicherdienst hängen Tiers direkt am Zwischenspeicher', static function (): void {
    $input = flowInput();
    $input['storage']['available'] = false;
    $flow = OrvantaFlowService::evaluate($input);

    Assert::false(isset($flow['nodes']['tier-local']));
    Assert::false(isset($flow['nodes']['tier-snapshot']));
    $edgeKeys = array_map(static fn (array $e): string => $e['from'] . '>' . $e['to'], $flow['edges']);
    Assert::true(in_array('tier-1>cache', $edgeKeys, true));
});

Runner::test('Nachrichtenfluss: lokaler Speicher warnt ohne Messwerte und stört bei kritischer Belegung', static function (): void {
    $input = flowInput();
    $input['storage']['local']['total_bytes'] = 0;
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('warn', flowNode($flow, 'tier-local')['state']);
    Assert::contains('Noch keine Messwerte', flowNode($flow, 'tier-local')['message']);

    $input = flowInput();
    $input['storage']['local']['fill'] = ['percent' => 97.0, 'state' => 'critical'];
    $flow = OrvantaFlowService::evaluate($input);
    Assert::same('error', flowNode($flow, 'tier-local')['state']);
    Assert::true(flowNode($flow, 'tier-local')['alert']);
});

Runner::test('Nachrichtenfluss: Snapshot-Speicher offline wird gedämpft und als Störung geführt', static function (): void {
    $input = flowInput();
    $input['storage']['snapshot']['state'] = 'offline';
    $input['storage']['snapshot']['message'] = 'Der Snapshot-Pfad ist nicht erreichbar.';
    $flow = OrvantaFlowService::evaluate($input);

    $snapshot = flowNode($flow, 'tier-snapshot');
    Assert::same('error', $snapshot['state']);
    Assert::true($snapshot['muted']);
    Assert::same('Der Snapshot-Pfad ist nicht erreichbar.', $snapshot['message']);
    Assert::same('tier-snapshot', $flow['incidents'][0]['key']);
});
