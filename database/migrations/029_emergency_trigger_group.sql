-- Auslösegruppe: Mitglieder sehen und bearbeiten gemeinsam die laufenden Ereignisse der Gruppe.
ALTER TABLE emergency_events
    ADD COLUMN trigger_group VARCHAR(190) NULL,
    ADD KEY emergency_trigger_group (trigger_group, status);
