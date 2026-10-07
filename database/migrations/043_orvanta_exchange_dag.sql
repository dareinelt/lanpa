-- Orvanta: Exchange-DAG-Kompatibilitaet (Database Availability Group).
-- Hosts der DAG mit ihren Lastkennzahlen sowie die Zuordnung laufender
-- Orvanta-Sitzungen zu einem Host (Sitzungsaffinitaet, Failover,
-- Fair-use-Verteilung). Der im Adminbereich unter Office -> Orvanta
-- eingetragene Exchange-Server ist immer der primaere Host (is_primary = 1);
-- weitere Hosts derselben DAG werden unter Office -> Orvanta - DAG-Hosts
-- ergaenzt.
CREATE TABLE orvanta_exchange_hosts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    host VARCHAR(190) NOT NULL,
    ews_url VARCHAR(2048) NOT NULL DEFAULT '',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    latency_samples INT UNSIGNED NOT NULL DEFAULT 0,
    last_latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    last_session_at DATETIME NULL,
    last_check_at DATETIME NULL,
    last_ok TINYINT(1) NOT NULL DEFAULT 1,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_exchange_host (host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orvanta_exchange_sessions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_hash CHAR(40) NOT NULL,
    user_uid VARCHAR(190) NOT NULL DEFAULT '',
    host VARCHAR(190) NOT NULL,
    failovers INT UNSIGNED NOT NULL DEFAULT 0,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    UNIQUE KEY uq_orvanta_exchange_session (session_hash),
    KEY idx_orvanta_exchange_session_host (host, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
