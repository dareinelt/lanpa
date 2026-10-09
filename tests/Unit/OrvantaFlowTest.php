<?php

declare(strict_types=1);

use App\Repositories\MailProxyRepository;
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
