-- Netzlaufwerke der Windows-Clients fuer Nextcloud.
--
-- Ein Anmeldeskript auf dem Client (scripts/network-drives-report.ps1, per
-- Gruppenrichtlinie) meldet die gemappten Netzlaufwerke (Buchstabe -> UNC-Pfad)
-- per Windows-Anmeldung an /sso/laufwerke. Jede Meldung ersetzt den Stand des
-- Benutzers (letzte Meldung gilt, auch bei mehreren Geraeten). Benutzer werden
-- ueber ihre Nextcloud-Kennung (SamAccountName bzw. "name@kennung") gefuehrt.
-- Die Liste der nie weitergereichten Laufwerke steht in der Einstellung
-- office_network_drives_excluded (Standard B:, G:).

CREATE TABLE IF NOT EXISTS network_drives (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uid VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    drive_letter CHAR(1) NOT NULL,
    unc_path VARCHAR(500) NOT NULL,
    domain VARCHAR(64) NOT NULL DEFAULT '',
    computer_name VARCHAR(64) NOT NULL DEFAULT '',
    reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_network_drives_user_letter (user_uid, drive_letter),
    KEY idx_network_drives_reported (reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
