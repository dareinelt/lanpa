-- Interne Navigationsziele optional in einem neuen Browser-Tab oeffnen.
-- Externe Ziele oeffnen weiterhin immer in einem neuen Tab; die Option gilt
-- daher nur fuer den Typ "internal" (z. B. die KLINQ-Kachel).
ALTER TABLE navigation_items
    ADD COLUMN open_in_new_tab TINYINT(1) NOT NULL DEFAULT 0 AFTER protected_access;
