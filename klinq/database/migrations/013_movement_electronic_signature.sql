-- 013_movement_electronic_signature.sql – Entnahme/Retoure am Desktop (ohne Kamera-Scan) werden
-- durch erneute Eingabe des eigenen Passworts elektronisch signiert. signed_electronically markiert
-- den Vorgang, signed_at hält den Zeitpunkt fest; der unterzeichnende Benutzer ist created_by.

ALTER TABLE movements
    ADD COLUMN signed_electronically TINYINT(1) NOT NULL DEFAULT 0 AFTER source,
    ADD COLUMN signed_at TIMESTAMP NULL AFTER signed_electronically;
