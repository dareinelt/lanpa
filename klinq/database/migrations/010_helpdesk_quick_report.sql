-- Help Desk: öffentliches Störungsformular (/stoerung) ohne Anmeldung
-- Quelle "form" sowie die automatisch erkannten Melderdaten (Konto, Rechner, IP, AD-Abgleich).
ALTER TABLE tickets
    MODIFY COLUMN source ENUM('web','portal','api','email','phone','scheduler','form') NOT NULL DEFAULT 'web',
    ADD COLUMN reporter_username VARCHAR(100) NULL AFTER requester_email,
    ADD COLUMN reporter_host VARCHAR(255) NULL AFTER reporter_username,
    ADD COLUMN reporter_ip VARCHAR(45) NULL AFTER reporter_host,
    ADD COLUMN reporter_phone VARCHAR(50) NULL AFTER reporter_ip,
    ADD COLUMN reporter_department VARCHAR(150) NULL AFTER reporter_phone,
    ADD KEY idx_tickets_reporter_username (reporter_username),
    ADD KEY idx_tickets_reporter_host (reporter_host);
