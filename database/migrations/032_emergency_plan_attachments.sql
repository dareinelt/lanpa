-- Anhänge (Bilder/PDF) an Notfallplan-Schritten: base64-kodiert, inhaltsadressiert und unveränderlich,
-- damit Entwürfe, veröffentlichte Fassungen und Ereignis-Snapshots dieselben Inhalte referenzieren können.
CREATE TABLE emergency_plan_attachments (
    id CHAR(64) NOT NULL PRIMARY KEY,
    mime VARCHAR(100) NOT NULL,
    size INT UNSIGNED NOT NULL,
    data LONGTEXT NOT NULL,
    created_by VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
