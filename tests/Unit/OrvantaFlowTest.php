<?php

declare(strict_types=1);

use App\Repositories\MailProxyRepository;
use App\Repositories\OrvantaFlowRepository;
use App\Services\MailProxy\MailProxyRoute;
use App\Services\Orvanta\OrvantaPresenceService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Abbild der fuer den Nachrichtenfluss relevanten Tabellen
 * (database/migrations/039_mail_proxy.sql, 047_orvanta_flow_presence.sql).
 */
function flowPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE identity_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_key TEXT NOT NULL, label TEXT NOT NULL,
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
            user_uid TEXT PRIMARY KEY, backend TEXT NOT NULL DEFAULT \'exchange\',
            first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, requests INTEGER NOT NULL DEFAULT 0
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


