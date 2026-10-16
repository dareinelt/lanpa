-- Orvanta-Kalender fuer Postfaecher ohne Exchange (IMAP/SMTP-Proxy).
--
-- Benutzer, deren Postfach ueber den Mail-Proxy angebunden ist, haben keinen
-- Zugriff auf einen Exchange-Kalender. Fuer sie fuehrt Orvanta den Kalender
-- lokal in dieser Datenbank - gebunden an die AD-Kennung (user_uid), nicht an
-- das Postfach, damit eine Umbenennung des Postfachs die Termine nicht
-- verliert.
--
-- Vier Tabellen:
--
--   orvanta_calendar_events     Termine inklusive Serienkennung (event_uid) und
--                               Sequenznummer (sequence) fuer den Abgleich mit
--                               iCalendar-Einladungen. Zeitkonvention: starts_at
--                               und ends_at sind DATETIME in der Zeitzone der
--                               Anwendung (Spalte timezone dokumentiert sie).
--                               Nach aussen (iCalendar) werden die Zeiten immer
--                               als UTC uebertragen, damit Empfaenger in
--                               anderen Zeitzonen denselben Zeitpunkt sehen.
--                               attendee_count ist denormalisiert, damit die
--                               Bereichsabfrage des Kalenders ohne Nachfrage
--                               je Termin auskommt (keine N+1-Abfragen).
--
--   orvanta_calendar_attendees  Teilnehmer je Termin mit Rolle und Antwort-
--                               status (PARTSTAT). Die Fremdschluessel sorgen
--                               dafuer, dass beim Loeschen eines Termins keine
--                               verwaisten Teilnehmer zurueckbleiben.
--
--   orvanta_calendar_outbox     Versandauftraege fuer Einladungen, Aktualisie-
--                               rungen, Absagen und Antworten. Der eindeutige
--                               idempotency_key verhindert, dass wiederholte
--                               Aufrufe dieselbe Nachricht mehrfach versenden.
--                               Der Auftrag entsteht in derselben Transaktion
--                               wie der Termin, damit ein SMTP-Ausfall keinen
--                               Terminverlust verursacht - der Versand wird
--                               spaeter wiederholt. from_email ist der
--                               Absender (zugeordnetes Postfach), damit ein
--                               spaeterer Versuch ohne Sitzung auskommt.
--
--   orvanta_calendar_processed  Bereits verarbeitete eingehende iCalendar-
--                               Objekte je Benutzer (Deduplizierungsschluessel
--                               aus Methode, Serienkennung, Sequenz und
--                               Antwortstatus). Mehrfaches Oeffnen derselben
--                               Einladung erzeugt damit weder Duplikate noch
--                               mehrfach versendete Antworten.
CREATE TABLE IF NOT EXISTS orvanta_calendar_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    event_uid VARCHAR(191) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    description MEDIUMTEXT NULL,
    location VARCHAR(255) NOT NULL DEFAULT '',
    organizer_email VARCHAR(190) NOT NULL DEFAULT '',
    organizer_name VARCHAR(190) NOT NULL DEFAULT '',
    is_organizer TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    timezone VARCHAR(64) NOT NULL DEFAULT '',
    free_busy VARCHAR(20) NOT NULL DEFAULT 'Busy',
    status VARCHAR(20) NOT NULL DEFAULT 'CONFIRMED',
    sequence INT UNSIGNED NOT NULL DEFAULT 0,
    reminder_minutes INT NOT NULL DEFAULT -1,
    my_response VARCHAR(20) NOT NULL DEFAULT '',
    attendee_count INT UNSIGNED NOT NULL DEFAULT 0,
    source VARCHAR(20) NOT NULL DEFAULT 'local',
    origin_message_id VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_calendar_event (user_uid, event_uid),
    KEY idx_orvanta_calendar_range (user_uid, starts_at, ends_at),
    KEY idx_orvanta_calendar_status (user_uid, status, starts_at),
    KEY idx_orvanta_calendar_reminder (user_uid, status, reminder_minutes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orvanta_calendar_attendees (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL DEFAULT '',
    role VARCHAR(20) NOT NULL DEFAULT 'REQ-PARTICIPANT',
    response VARCHAR(20) NOT NULL DEFAULT 'NEEDS-ACTION',
    responded_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_calendar_attendee (event_id, email),
    KEY idx_orvanta_calendar_attendee_mail (email),
    CONSTRAINT fk_orvanta_calendar_attendee_event FOREIGN KEY (event_id)
        REFERENCES orvanta_calendar_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orvanta_calendar_outbox (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    event_id INT UNSIGNED NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'invite',
    idempotency_key VARCHAR(191) NOT NULL,
    from_email VARCHAR(190) NOT NULL DEFAULT '',
    recipients TEXT NULL,
    payload MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    next_attempt_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_calendar_outbox (idempotency_key),
    KEY idx_orvanta_calendar_outbox_due (status, next_attempt_at),
    KEY idx_orvanta_calendar_outbox_user (user_uid, status),
    KEY idx_orvanta_calendar_outbox_event (event_id),
    CONSTRAINT fk_orvanta_calendar_outbox_event FOREIGN KEY (event_id)
        REFERENCES orvanta_calendar_events (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orvanta_calendar_processed (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    item_hash CHAR(40) NOT NULL,
    event_uid VARCHAR(191) NOT NULL DEFAULT '',
    method VARCHAR(20) NOT NULL DEFAULT '',
    sequence INT UNSIGNED NOT NULL DEFAULT 0,
    event_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_calendar_processed (user_uid, item_hash),
    KEY idx_orvanta_calendar_processed_event (user_uid, event_uid),
    CONSTRAINT fk_orvanta_calendar_processed_event FOREIGN KEY (event_id)
        REFERENCES orvanta_calendar_events (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
