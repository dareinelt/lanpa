# Screenshots: KI-Unterstützung in Orvanta

Aufgenommen mit einem isolierten Docker-Stack (Demo-Postfach, Fake-SSO) und dem
lokalen llama.cpp-Testendpunkt (Profil `ki`) mit **Qwen3.5-9B (Q4_K_M)**,
Viewport 1400 × 900.

| Datei | Inhalt |
|---|---|
| `01-statusleiste-roboter.png` | Roboter-Indikator in der Statusleiste (KI erreichbar) |
| `02-hilfe-ki.png` | Kurzanleitung mit Abschnitt „KI-Unterstützung“ |
| `03-kontextmenue.png` | Markierter Text in einer neuen E-Mail, Rechtsklick → „Mit KI verbessern …“ |
| `04-dialog.png` | Dialog mit Textauszug, Anweisung und Vorschlags-Chips |
| `05-block.png` | Eingefügter Vorschlag als hellblau umrandeter Block |
| `06-block-menue.png` | Rechtsklick auf den Block: Verfeinern / Zurücksetzen / Markierung entfernen |
| `07-termin.png` | Termindialog mit Erinnerung (Einsatzort `reminder`) und KI-Block in der Beschreibung |
| `08-admin-bericht.png` | Adminbereich: anonymisierter Nutzungsbericht (30 Tage) mit SVG-Diagrammen |
| `09-admin-lokale-ki.png` | Adminbereich: Karte „Lokale KI“ – die einzige Stelle, an der Endpunkt und Modell konfiguriert werden |
| `10-verfeinert.png` | Ergebnis von „Weiter verfeinern“ (kürzer, per Du) |

## Reproduktion

```bash
cp .env.example .env
# in .env setzen:
#   APP_ENV=development  APP_URL=http://localhost:8080
#   SSO_FAKE_USER=erika.muster  SSO_FAKE_GROUPS=GG-Office-Basis
#   OFFICE_ENABLED=true
#   OFFICE_AI_SEED_URL=http://ki:8080/v1  OFFICE_AI_SEED_MODEL=qwen3.5-9b
#   KI_MODEL_REPO=unsloth/Qwen3.5-9B-GGUF  KI_MODEL_FILE=Qwen3.5-9B-Q4_K_M.gguf
#   KI_MODEL_ALIAS=qwen3.5-9b  KI_THREADS=8  ORVANTA_AI_TIMEOUT=120
docker compose --profile ki up -d --build db app ki     # Modell-Download ca. 6 GB
```

Danach Orvanta im Demo-Modus freischalten und einen Admin anlegen:

```sql
INSERT INTO orvanta_settings (setting_key, setting_value) VALUES
  ('exchange_enabled', '1'), ('exchange_host', 'demo');
INSERT INTO office_app_permissions (app_key, group_name) VALUES ('orvanta', 'GG-Office-Basis');
```

```bash
docker compose exec app php scripts/create_admin.php admin '<Kennwort>'
```

Orvanta: `http://localhost:8080/office/orvanta` – Bericht:
`http://localhost:8080/admin/office/orvanta?ki_zeitraum=30#orvanta-ki`. Für gefüllte
Diagramme vorher einige Verbesserungen auslösen (oder Testzeilen in
`orvanta_ai_usage` einfügen).

Das kleine Standardmodell (Qwen2.5-0.5B) genügt für den Funktionstest, liefert
aber sichtbar schwächere Texte; für Vorführungen das 9B-Modell verwenden.
