-- Netzlaufwerke: Benutzerkennungen einheitlich klein schreiben.
--
-- Windows meldet den Anmeldenamen in gemischter Schreibweise ("AMueller"),
-- die Nextcloud-App ordnet Laufwerke aber ueber klein geschriebene Kennungen
-- zu (intranet_integration, NetworkDriveService::normalizePayload()). Bisher
-- konnte dieselbe Person je nach Anmeldung unter zwei Schreibweisen in der
-- Tabelle stehen; die Admin-Uebersicht zeigte sie dann zweimal. Der Code
-- speichert und sucht ab jetzt klein geschrieben - ohne LOWER() auf der
-- Spalte, damit der Index uniq_network_drives_user_letter nutzbar bleibt.
--
-- Die eindeutige Schluesselung ist case-insensitiv (utf8mb4_unicode_ci), also
-- kann "AMueller"/H und "amueller"/H nicht nebeneinander existieren. Das
-- UPDATE kann daher keinen Schluesselkonflikt ausloesen; doppelte Eintraege
-- sind nicht zu bereinigen.

UPDATE network_drives SET user_uid = LOWER(user_uid);
