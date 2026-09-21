-- Einzelnummern-Versand: Alarm-Kacheln koennen wahlweise an eine Gruppe
-- (mode=group) oder an eine einzelne Rufnummer (mode=number) versendet werden.
-- Dafuer erhaelt die Tabelle alarm_groups einen Typ. Die Gruppenzuordnung der
-- Aktivierungs-Rufnummern entfaellt; dort ist die Rufnummer selbst das Ziel.
-- Der Alarmierungsverlauf merkt sich den Versandmodus.
ALTER TABLE alarm_groups
    ADD COLUMN type ENUM('group', 'number') NOT NULL DEFAULT 'group' AFTER description;

ALTER TABLE activation_numbers
    DROP FOREIGN KEY fk_activation_numbers_alarm_group;

ALTER TABLE activation_numbers
    DROP COLUMN alarm_group_id;

ALTER TABLE alarm_log
    ADD COLUMN mode ENUM('group', 'number') NOT NULL DEFAULT 'group' AFTER group_description;
