-- Weiterleitung externer Navigationskacheln ueber den Reverse-Proxy des
-- auth-Containers (Zweigstellen/Aussenstellen ohne eigene DNS-/Zertifikats-
-- aufloesung). proxy_bypass_networks enthaelt CIDR-Quellnetze (durch Leerzeichen,
-- Komma oder Zeilenumbruch getrennt), die das Ziel direkt aufrufen.
ALTER TABLE navigation_items
    ADD COLUMN proxy_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER protected_access,
    ADD COLUMN proxy_bypass_networks VARCHAR(1000) NOT NULL DEFAULT '' AFTER proxy_enabled;
