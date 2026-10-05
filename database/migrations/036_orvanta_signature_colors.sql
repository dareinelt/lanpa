-- Orvanta: Schrift- und Trennzeichenfarbe je Signaturvorlage.
--
-- Gespeichert wird der Schluessel einer Designfarbe (z. B. "color_text"),
-- nicht der Hex-Wert: Aendert sich das Design, folgen die Signaturen.
ALTER TABLE orvanta_signatures
    ADD COLUMN text_color VARCHAR(40) NOT NULL DEFAULT 'color_text' AFTER phone_prefix,
    ADD COLUMN separator_color VARCHAR(40) NOT NULL DEFAULT 'color_accent' AFTER text_color;
