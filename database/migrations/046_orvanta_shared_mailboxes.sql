-- Orvanta: zusaetzlich berechtigte Postfaecher (Vollzugriff / "Senden als").
--
-- Auf einem Exchange-Server koennen Benutzer neben ihrem eigenen Postfach
-- weitere Postfaecher nutzen (Postfachberechtigung "Vollzugriff") und daraus
-- senden ("Senden als"). Outlook zeigt diese Postfaecher als weitere Knoten
-- im Ordnerbaum; Orvanta bildet sie genauso ab.
--
-- Exchange/EWS bietet keine Abfrage "auf welche Postfaecher darf dieser
-- Benutzer zugreifen" – die Berechtigungen liegen im Active Directory in
-- msExchMailboxSecurityDescriptor (binaer). Die Zuordnung wird deshalb im
-- Adminbereich gepflegt. Jede Zuordnung prueft Orvanta ueber EWS mit dem
-- Dienstkonto: Nur ein per ExchangeImpersonation erreichbares Postfach wird
-- im Ordnerbaum angezeigt (verified_at, verify_error, checked_at als
-- Zeitpunkt des letzten Versuchs). calendar_visible ist die Checkbox im
-- Kalender des Benutzers: standardmaessig ausgeblendet.
--
-- Archiviert wird weiterhin ausschliesslich das primaere Benutzerpostfach
-- (orvanta_archives); zusaetzlich berechtigte Postfaecher laufen nicht in die
-- Archivierung ein.
CREATE TABLE orvanta_shared_mailboxes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(190) NOT NULL,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL DEFAULT '',
    send_as TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    verified_at DATETIME NULL,
    verify_error VARCHAR(500) NOT NULL DEFAULT '',
    checked_at DATETIME NULL,
    calendar_visible TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_shared_mailboxes (user_uid, email),
    KEY idx_orvanta_shared_mailboxes_user (user_uid, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
