-- Erweiterung der Cold-Tiers (SMB-/S3-Tier) um weitere Speicherziele.
--
-- Ein Cold-Tier besteht aus einem Basisziel (parent_id IS NULL) und optional
-- weiteren Erweiterungszielen (parent_id = id des Basisziels) derselben Art
-- (SMB nur mit SMB, S3 nur mit S3). Jeder Cold-Tier haelt weiterhin eine
-- vollstaendige Kopie aller Daten; die Dateien eines Tiers verteilen sich auf
-- Basisziel und Erweiterungen (Ueberlauf, sobald ein Ziel voll ist).
-- Erweitert werden immer alle Cold-Tiers gleichzeitig um je ein Ziel.

ALTER TABLE storage_targets ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER kind;
ALTER TABLE storage_targets ADD KEY idx_storage_targets_parent (parent_id);
