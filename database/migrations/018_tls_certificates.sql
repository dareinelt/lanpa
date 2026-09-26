-- HTTPS-Zertifikate des auth-Containers.
--
-- Jede Zeile ist entweder ein im Adminbereich erzeugter Certificate Signing
-- Request (kind = 'csr') samt privatem Schluessel und – nach dem Import – dem
-- dazu ausgestellten Zertifikat, oder ein automatisch erzeugtes,
-- selbstsigniertes Notfall-Zertifikat (kind = 'fallback'), das verwendet wird,
-- solange kein gueltiges Zertifikat aktiv ist.
--
-- Der private Schluessel wird ausschliesslich verschluesselt gespeichert
-- (App\Security\SecretBox, Schluessel in storage/keys/) und nur ueber die
-- interne Schnittstelle /internal/tls-config an den auth-Container gegeben.
--
-- Zeitpunkte sind Unix-Zeitstempel (Sekunden, UTC), damit Anwendung,
-- Datenbank und SNMP-Agent unabhaengig von Zeitzonen rechnen.

CREATE TABLE IF NOT EXISTS tls_certificates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL DEFAULT 'csr',
    common_name VARCHAR(255) NOT NULL,
    subject TEXT NULL,
    san TEXT NULL,
    key_type VARCHAR(32) NOT NULL,
    private_key TEXT NOT NULL,
    public_key_hash CHAR(64) NOT NULL,
    csr_pem TEXT NULL,
    created_at BIGINT NOT NULL,
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    certificate_pem TEXT NULL,
    chain_pem TEXT NULL,
    cert_subject VARCHAR(1024) NULL,
    cert_issuer VARCHAR(1024) NULL,
    cert_serial VARCHAR(128) NULL,
    cert_fingerprint CHAR(64) NULL,
    cert_san TEXT NULL,
    cert_not_before BIGINT NULL,
    cert_not_after BIGINT NULL,
    cert_uploaded_at BIGINT NULL,
    cert_uploaded_by VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    activated_at BIGINT NULL,
    first_used_at BIGINT NULL,
    last_used_at BIGINT NULL,
    PRIMARY KEY (id),
    KEY idx_tls_certificates_kind (kind, active),
    KEY idx_tls_certificates_public_key (public_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
