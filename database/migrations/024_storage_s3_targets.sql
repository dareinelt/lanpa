-- Speicherziele des Cold-Tiers optional als S3-kompatibler Objektspeicher
-- (AWS S3, MinIO, Ceph RGW, Wasabi, NetApp StorageGRID ...) statt SMB-Freigabe.
--
-- kind = 'smb': unc_path, username, password, domain, smb_version wie bisher.
-- kind = 's3' : unc_path haelt den kanonischen Ort s3://<host[:port]>/<bucket>[/<praefix>]
--               (Eindeutigkeit), username = Access Key ID, password = Secret
--               Access Key (verschluesselt, SecretBox). capacity_bytes ist die
--               optionale Kapazitaet (Bucket-Quota) fuer den Fuellstand; 0 = ohne Grenze.

ALTER TABLE storage_targets ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'smb' AFTER label;
ALTER TABLE storage_targets ADD COLUMN s3_endpoint VARCHAR(255) NOT NULL DEFAULT '' AFTER smb_version;
ALTER TABLE storage_targets ADD COLUMN s3_region VARCHAR(64) NOT NULL DEFAULT '' AFTER s3_endpoint;
ALTER TABLE storage_targets ADD COLUMN s3_bucket VARCHAR(63) NOT NULL DEFAULT '' AFTER s3_region;
ALTER TABLE storage_targets ADD COLUMN s3_prefix VARCHAR(255) NOT NULL DEFAULT '' AFTER s3_bucket;
ALTER TABLE storage_targets ADD COLUMN s3_path_style TINYINT(1) NOT NULL DEFAULT 1 AFTER s3_prefix;
ALTER TABLE storage_targets ADD COLUMN s3_verify_tls TINYINT(1) NOT NULL DEFAULT 1 AFTER s3_path_style;
ALTER TABLE storage_targets ADD COLUMN capacity_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER s3_verify_tls;
