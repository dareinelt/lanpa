# Notfallplan-Editor – technische Referenz (für Entwickler und Coding-Agenten)

Ergänzt [docs/notfallplan.md](notfallplan.md) (Einrichtung, Betrieb, Rechte) und
[docs/notfallplan-anleitung.md](notfallplan-anleitung.md) (Einsatzanleitung).
Dieses Dokument beschreibt, **wie** der visuelle Ablaufeditor unter
`/admin/notfallplan/bearbeiten` aufgebaut ist: Dateien, Datenmodell, Oberfläche,
Validierung, Live-Vorschau, Vier-Augen-Freigabe, Invarianten, Tests und typische
Änderungsaufgaben. Fundstellen sind als `Datei` + Symbol angegeben – bei
Abweichungen gilt der Code.

**Kurzfassung (TL;DR)**

- Ein Plan ist **ein JSON-Dokument** (`definition`) mit Titel, Beschreibung und
  einer geordneten Liste von 1–80 Elementen (`nodes`). Verbindungen zeigen nur
  auf **vorherige** Elemente → die Listenreihenfolge ist eine topologische
  Sortierung, der Graph ist per Konstruktion ein DAG.
- Der Editor ist reines Vanilla-JS ohne Bibliotheken
  (`public/assets/js/emergency-plan.js`, Block `if (editor)`); das Diagramm ist
  ein selbst gezeichnetes SVG mit automatischem Ebenen-Layout (`diagram()`).
- Speichern: `POST /admin/notfallplan/speichern` (JSON im Formfeld
  `definition`) → `EmergencyPlanService::save()` → `EmergencyPlanDefinition::validate()`
  + `prepare()` (SMS-Vorlage kopieren) → `EmergencyPlanRepository::savePlan()`
  mit optimistischer Sperre über `revision`.
- **Speichern veröffentlicht nie.** Veröffentlichung nur über den Vier-Augen-Workflow
  (`submit` → `approve`/`reject` durch unbeteiligte Person, `withdraw` sofort).
- Live-Vorschau: zweiter Tab, Kopplung per `BroadcastChannel`, serverseitig
  zustandsloses Rendern über `EmergencyPlanPreview` + `EmergencyPlanRuntime`
  (keine DB-Schreibzugriffe, kein Versand).
- Zugriff nur Rollen `admin` und `kaep` (`EmergencyPlanService::isManager()`),
  auch für Export/Import.

## Inhalt

1. Begriffe
2. Code-Landkarte
3. Routen und Rechte
4. Datenmodell
5. Oberfläche und Interaktionsdesign
6. Validierung (Server)
7. Speichern und Konflikte
8. Vier-Augen-Freigabe
9. Live-Vorschau
10. Laufzeitsemantik (was der Editor konfiguriert)
11. Invarianten (nicht brechen!)
12. Tests
13. Änderungsrezepte
14. Bekannte Eigenheiten

---

## 1. Begriffe

| Begriff (UI) | Code | Bedeutung |
| --- | --- | --- |
| Plan | `emergency_plans`-Zeile | Enthält Entwurf (`definition`) und ggf. veröffentlichte Fassung (`published_definition`) |
| Entwurf | `definition`, `revision` | Bearbeitbarer Stand; nie auslösbar |
| Veröffentlichte Version | `published_definition`, `published_revision` | Unveränderliche Kopie des freigegebenen Entwurfs inkl. `publication`-Metadaten |
| Element / Baustein / Maßnahme | `node` | Ein Schritt im Ablauf |
| Vorgänger / Verbindung | `node.dependencies[]` (`{id, when}`) | Kante vom Vorgänger zum Element |
| Verknüpfung UND/ODER | `node.join` = `all` / `any` | Wie mehrere Vorgänger kombiniert werden |
| Zielzeit | `node.minutes` | Minuten ab Ereignisstart, `0` = keine |
| Akteur | `actor` (`ad:<uid>` / `local:<name>`) | Identität für Autorenschaft und Freigabe |
| Mitautoren | `contributors` | Alle Akteure, die seit der letzten Freigabe gespeichert haben |

## 2. Code-Landkarte

| Datei | Verantwortung |
| --- | --- |
| `views/emergency/editor.php` | Editor-Seite im Office-Stil (eigener Browser-Tab, volle Breite): Titelleiste mit Schnellzugriff, Menüband mit vier Reitern (Start / Ablauf & Verbindungen / Prüfen & Freigabe / Ansicht), 3-Spalten-Arbeitsbereich (Schritte-Palette, Diagramm-Canvas, Eigenschaften), Statusleiste sowie `<dialog>`-Fenster für Vier-Augen-Freigabe (+ Protokoll) und Hilfe. Übergibt Plan und Alarmvorlagen als JSON in versteckten `<textarea>` (`data-ep-initial`, `data-ep-alarms`). |
| `views/layouts/editor.php` | Schlankes Vollbild-Layout nur für den Editor (kein Admin-Menü, kein Seitenrahmen; `body.admin.ep-shell`). Wird von `EmergencyPlanController::edit()` über `adminView(..., 'layouts.editor')` gewählt. |
| `public/assets/js/emergency-plan.js` | Gemeinsames Skript für Editor (`[data-ep-editor]`), statische Diagramme (`renderStaticDiagram`), Live-Vorschau (`[data-ep-preview]`), Ereignisansicht (`[data-ep-event]`) und Startformular (`.ep-start`). Hilfsfunktionen `element()`, `svgElement()`, `diagram()`, `post()`. Der Editor-Block enthält zusätzlich Undo/Redo-Journal, Client-Prüfung, Zoom/Pan, Suche und Drag-and-Drop. |
| `public/assets/css/emergency-plan.css` | Office-Layout (`.ep-office`-Grid, `.ep-titlebar`, `.ep-ribbon`/`.ep-rg`/`.ep-rb`, `.ep-workspace3`, `.ep-palette`, `.ep-canvas`, `.ep-inspector`, `.ep-statusbar`, `.ep-dialog`), SVG-Knotenfarben (`.ep-graph-node--<typ>/<status>/--error/--hint`), Dark-Theme, Responsive-Umbrüche (1200 px / 900 px / 650 px). |
| `app/Controllers/EmergencyPlanController.php` | `access()` (Rollen-/Akteursermittlung), `edit()`, `save()`, `preview()`, `previewRender()`, `review()`, `exportPlans()`, `exportPart()`, `exportNextcloud()`, `importPlans()`, `importFinish()` sowie die Benutzer-/Ereignisaktionen. |
| `app/Services/EmergencyPlanDefinition.php` | Reine Funktionen: `validate()` (Schema + Graphregeln), `readiness()` (Freigabe-/Wartezustand je Element), `text()` (Textprüfung). Konstanten `TYPES`, `STATUSES`. |
| `app/Services/EmergencyPlanSms.php` | SMS an einzelne Rufnummern: Konstanten (`MAX_LENGTH`, `MAX_NUMBERS`, `PLACEHOLDERS`), `length()`, `render()`, `alarm()` (eingebettete Versanddaten), `resolve()` (Bausteine im Ereignis-Snapshot ersetzen). Versand je Rufnummer: `AlarmService::triggerEmergency()`. |
| `app/Services/EmergencyPlanService.php` | `save()`, `prepare()` (Validierung + SMS-Vorlage einbetten), `alarmOptions()` (aktive Alarmkacheln für das Dropdown), `importPlans()` (Einzeldateien v1/v2), `importDefinitions()`, `matchAlarm()`. |
| `app/Services/EmergencyPlanTransfer.php` | Export-Sätze (Format v3): `createExport()` (Teildateien ≤ `PART_MAX_BYTES` = 15 MB, Anhänge in Abschnitten), `exportPart()`, `stageImport()` (Teil hochladen, Stand fehlender Teile), `importStaged()` (Vollständigkeitsprüfung, dann `importDefinitions()`); Arbeitsverzeichnis `storage/emergency-transfer`. |
| `app/Services/Office/NextcloudFilesService.php` | Ablage einer Datei in den Nextcloud-Dateien einer Person (signiertes Token `OfficeJwt::filesToken()`). |
| `app/Repositories/EmergencyPlanRepository.php` | `plan()`, `plans()`, `publishedPlan()`, `savePlan()`, `importPlans()`, `submit()`, `review()`, `withdraw()`, `reviews()`, `reviewLog()`; Transaktionen und `assertChanged()` (HTTP 409). |
| `app/Services/EmergencyPlanPreview.php` | Baut aus Entwurf + simulierten Aktionen ein flüchtiges Ereignis (ohne Repository, ohne Versand). |
| `app/Services/EmergencyPlanRuntime.php` | Nebenwirkungsfreie Zustandsübergänge (`change()`), gemeinsam genutzt von Einsatz und Vorschau. |
| `views/emergency/preview.php` | Hülle des Vorschau-Tabs (Sandbox-Banner, Reset/Retry, Statuszeilen, `data-ep-preview-content`). |
| `views/emergency/plan.php`, `views/emergency/event.php` | Plan- und Ereignisansicht; werden in der Vorschau mit `preview => true` gerendert. |
| `views/emergency/publication.php` | Anzeige von Autoren/Freigeber/Version aus `definition.publication`. |
| `views/emergency/index.php` | Planliste mit „Neuen Notfallplan entwerfen (neuer Tab)“ / „Im Editor öffnen (neuer Tab)“ (`target="_blank" rel="noopener"`) / „Vorschau“, Freigabeeinstellungen, Export (Download/Nextcloud) und mehrteiliger Import. |
| `database/migrations/025_emergency_plans.sql`, `027_emergency_plan_approval.sql` | Tabellen `emergency_plans`, `emergency_plan_reviews` (Freigabespalten). |
| `public/index.php` | Routen (Gruppe `$requireKaep`, auch für Export/Import); KAEP-Rolle wird in `$requireAuth` auf `/admin/notfallplan*` beschränkt. |

## 3. Routen und Rechte

Alle Editor-Routen liegen in `public/index.php` in der Gruppe
`[$requireAuth] → [$requireKaep]`. Zusätzlich prüft
`EmergencyPlanController::access($request, true)` erneut die Rolle.

| Methode | Pfad | Controller | Zweck |
| --- | --- | --- | --- |
| GET | `/admin/notfallplan` | `index` | Planliste, Einstellungen, Export/Import |
| GET | `/admin/notfallplan/bearbeiten[?id=N]` | `edit` | Editor (ohne `id` bzw. `id=0`: neuer Plan; unbekannte `id`: 404) |
| POST | `/admin/notfallplan/speichern` | `save` | Entwurf speichern (JSON-Antwort) |
| GET | `/admin/notfallplan/vorschau#<uuid>` | `preview` | Vorschau-Tab (Hülle) |
| POST | `/admin/notfallplan/vorschau` | `previewRender` | Vorschau rendern (JSON `{html}` bzw. `{error}`) |
| POST | `/admin/notfallplan/freigabe` | `review` | `action` = `submit` / `approve` / `reject` / `withdraw`; Redirect zurück in den Editor |
| POST | `/admin/notfallplan/plaene/export` | `exportPlans` | Export-Satz erzeugen (`plans[]`), JSON `{set, plans, parts[{part,name,bytes,url}], nextcloud{available,reason,folder,url}}` |
| GET | `/admin/notfallplan/plaene/export/datei?set=&part=` | `exportPart` | Teildatei herunterladen (nur eigener Satz) |
| POST | `/admin/notfallplan/plaene/export/nextcloud` | `exportNextcloud` | Teildatei (`set`, `part`) in den eigenen Nextcloud-Dateien ablegen |
| POST | `/admin/notfallplan/plaene/import` | `importPlans` | eine Datei (`file`) hochladen; JSON-Stand `{set, parts, received, missing, complete, plans}` |
| POST | `/admin/notfallplan/plaene/import/abschluss` | `importFinish` | vollständigen Satz (`set`) prüfen und importieren |
| POST | `/admin/notfallplan/anhang` | `uploadAttachment` | Anhang hochladen (Formfeld `file`, JSON `{id,name,mime,size}`) |
| GET | `/admin/notfallplan/anhang?id=<sha256>&name=…` | `attachment` | Anhang ausliefern (Editor/Vorschau) |
| GET | `/notfallplan/anhang?id=<sha256>&name=…` | `attachment` | Anhang ausliefern (Planansicht/Einsatz, jede Person mit Notfallplan-Zugriff) |

Alle POST-Routen prüfen CSRF (`requireValidCsrf`). Antworten tragen
`Cache-Control: no-store`.

**Einstieg über die Office-Kachel:** Mitglieder der KAEP-AD-Gruppen
(`AdminGroupService::isKaepMember()`) sehen unter „Office“ die App
„Notfallplan-Editor“ (`OfficeAppCatalog::EMERGENCY_PLAN_*`, ergänzt in
`OfficeAppService::allowedFor()`). `OfficeController::launch`
(`/office-app?app=notfallplan`) meldet den SSO-Benutzer per
`Auth::loginDirectory()` an (sofern noch keine Sitzung mit Rolle `admin`/`kaep`
besteht) und leitet auf `/admin/notfallplan` weiter; die Rechteprüfung der
Editor-Routen bleibt unverändert.

**Akteur-Ermittlung** (`access()`): Wird eine echte (nicht simulierte)
Windows-Anmeldung erkannt, ist der Akteur immer `ad:<office_uid>` – auch bei
lokalem Adminlogin. Sonst `ad:`/`local:` + Benutzername. Damit ist ein Wechsel
zwischen lokalem Konto und SSO kein „zweites Augenpaar“.

## 4. Datenmodell

### 4.1 Tabelle `emergency_plans` (relevante Spalten)

| Spalte | Inhalt |
| --- | --- |
| `id` | Plan-ID |
| `title` | Kopie von `definition.title` (für Listen) |
| `definition` | Entwurf als JSON (MEDIUMTEXT) |
| `revision` | Entwurfsrevision; optimistische Sperre, +1 bei jedem Speichern und bei `withdraw` |
| `review_state` | `draft` / `pending` / `rejected` / `approved` |
| `contributors` | JSON-Liste der Akteure seit der letzten Freigabe |
| `submitted_by`, `submitted_at` | offener Freigabeantrag |
| `published` | `1` = auslösbar |
| `published_definition`, `published_revision` | freigegebene Fassung (bleibt bei neuen Entwürfen unverändert) |
| `updated_by`, `updated_at` | letzter Speichervorgang (UTC) |

`emergency_plan_reviews`: append-only Protokoll (`plan_id`, `revision`, `actor`,
`action` ∈ `saved|submitted|approved|rejected|withdrawn|imported`, `comment`,
`created_at` UTC).

### 4.2 JSON-Format `definition`

```json
{
  "title": "Brandfall",
  "description": "Erste Hinweise …",
  "nodes": [
    {
      "id": "n3f2…",
      "type": "decision",
      "title": "Räumung erforderlich?",
      "text": "Anweisung / Erläuterung",
      "owner": "Einsatzleitung",
      "phone": "1234",
      "link": "https://intranet.example/raeumung",
      "minutes": 15,
      "checks": [],
      "dependencies": [{ "id": "n1a…", "when": "always" }],
      "join": "all",
      "alarm_id": 0,
      "attachments": [{ "id": "<sha256>", "name": "Lageplan.pdf", "mime": "application/pdf", "size": 48213 }]
    }
  ]
}
```

| Feld | Regeln (`EmergencyPlanDefinition::validate`) |
| --- | --- |
| `title` | Pflicht, ≤ 190 Zeichen |
| `description` | optional, ≤ 4000 |
| `nodes` | Liste, 1–80 Einträge |
| `node.id` | `^[a-zA-Z][a-zA-Z0-9_-]{0,63}$`, eindeutig. Der Editor erzeugt `n` + UUID ohne Bindestriche (`makeNode`). |
| `node.type` | `action`, `contact`, `decision`, `checklist`, `note`, `sms` |
| `node.title` | Pflicht, ≤ 190 |
| `node.text` | ≤ 4000 |
| `node.owner` | ≤ 190 (Zuständigkeit/Funktion) |
| `node.phone` | ≤ 100 |
| `node.link` | leer oder absolute `http`/`https`-URL, ≤ 1000 |
| `node.minutes` | Ganzzahl 0–10080 (7 Tage) |
| `node.checks` | Liste ≤ 20 Texte (je Pflicht, ≤ 300); bei `checklist` mind. 1 |
| `node.dependencies` | Liste ≤ 80 von `{id, when}`; `id` muss ein **vorheriges** Element sein, keine Duplikate; `when` ∈ `always`, `yes`, `no` – `yes`/`no` nur, wenn der Vorgänger eine `decision` ist |
| `node.join` | `all` (UND, Standard) oder `any` (ODER) |
| `node.x`, `node.y` | optional, nur gemeinsam: Ganzzahlen 0–100000 (`MAX_COORDINATE`), Diagrammposition aus dem Drag-and-Drop im Editor; fehlen beide, wird automatisch angeordnet. Reines Layout ohne Einfluss auf den Ablauf |
| `node.alarm_id` | Ganzzahl ≥ 0; bei `sms` mit `sms_mode = template` Pflicht (> 0), sonst auf `0` normalisiert |
| `node.sms_mode` | nur `sms`: `template` (Alarmvorlage, Standard – auch für alte Pläne ohne Feld) oder `numbers` (einzelne Rufnummern); andere Typen → `template` |
| `node.sms_numbers` | nur bei `numbers`: Liste 1–20 Rufnummern (`Validator::isPhoneNumber`, ≤ 64, getrimmt, Leerzeilen verworfen, entdoppelt); sonst `[]` |
| `node.attachments` | Liste ≤ 10 (`EmergencyPlanAttachments::validateList`), nur bei `action`, `contact`, `decision`, `note` (sonst Fehler, leer → `[]`; fehlt bei alten Plänen/Snapshots). Eintrag: `id` = SHA-256 (64 hex) des Inhalts, eindeutig je Schritt; `name` Pflicht ≤ 190 (bereinigt); `mime` ∈ PDF/PNG/JPEG/GIF/WebP; `size` 1 – 20 MB. `prepare()` prüft, dass jeder Anhang in `emergency_plan_attachments` existiert, und übernimmt `mime`/`size` aus der Datenbank (Vorschau: ohne DB-Prüfung). |
| `node.sms_text` | nur bei `numbers`: Pflicht, ≤ 255; zusätzlich ≤ 255 **nach Einsetzen der Textbausteine** (`EmergencyPlanSms::length()`: `{Notfallplan}` = Plantitel, `{Schritt}` = Elementtitel, `{Datum}` = 10, `{Uhrzeit}` = 5 Zeichen); sonst `''` |

Alle Texte: Steuerzeichen außer Tab/LF/CR verboten, Werte werden getrimmt.
Unbekannte Felder werden von `validate()` verworfen (Whitelist-Ausgabe).

**Serverseitige Ergänzungen** (nicht vom Editor gesetzt):

- `node.alarm` – bei `sms` von `EmergencyPlanService::prepare()` eingebettete
  Kopie der Alarmvorlage (`title`, `alarm_text`, `alarm_group_number`,
  `alarm_group_description`, `alarm_group_type`). Wird bei jedem Speichern neu
  aus der Navigation kopiert; der Editor sendet das Feld ggf. mit, es wird
  verworfen und neu erzeugt. Bei `sms_mode = numbers` erzeugt
  `EmergencyPlanSms::alarm()` das Feld aus dem Element (`alarm_text` = Rohtext mit
  Textbausteinen, `alarm_group_number` = Rufnummern kommagetrennt,
  `alarm_group_type = number`, zusätzlich `numbers` und `placeholders = true`).
  `EmergencyPlanRepository::start()` ersetzt die Textbausteine einmalig im
  Ereignis-Snapshot (`EmergencyPlanSms::resolve()`, Zeitpunkt = `started_at` in
  `APP_TIMEZONE`) und entfernt `placeholders`; Entwurf und veröffentlichte
  Fassung behalten die Bausteine.
- `publication` – nur in `published_definition`: `authors`, `approved_by`,
  `approved_at`, `revision` (`EmergencyPlanRepository::review()`).

### 4.2a Anhänge (Tabelle `emergency_plan_attachments`, Migration 032)

Inhaltsadressiert: `id` = SHA-256 der Rohdaten, `mime`, `size`, `data`
(**base64**, `LONGTEXT`), `created_by`, `created_at`. Upload
(`EmergencyPlanService::uploadAttachment()`) erkennt den Typ an den Magic Bytes
(`EmergencyPlanAttachments::detectMime`, kein SVG), speichert gleiche Inhalte nur
einmal und liefert die Metadaten für `node.attachments`. Anhänge sind
unveränderlich und werden nie gelöscht (kein GC) – Entwurf, veröffentlichte
Fassung und Ereignis-Snapshots referenzieren dieselben IDs. Auslieferung mit
`Content-Disposition: inline`, ETag, `immutable`-Cache und eigener CSP
(`object-src 'self'` für den PDF-Viewer); `public/index.php` überschreibt vom
Controller gesetzte Sicherheitsheader nicht mehr.

### 4.3 Exportformat

Export-Satz (Version 3) aus 1…200 Teildateien, je ≤ 15 MB (`EmergencyPlanTransfer::PART_MAX_BYTES`):
`{"format": "lanpa-notfallplaene", "version": 3, "exported_at": …, "set": {id, part, parts}, "manifest": {plans: [{title, sha256}], attachments: {"<sha256>": {mime, size, chunks}}}, "plans": [{index, title, source_id, source_revision, definition}], "chunks": [{attachment, index, data(base64-Abschnitt)}]}` –
`manifest` nur in Teil 1; ist eine Datei voll, folgt die nächste. Immer der
**Entwurf**, ≤ 100 Pläne. Der Import sammelt die Teile je Person und Satz
(`stageImport()`), meldet fehlende Teile und importiert erst nach vollständiger
Prüfung (alle Teile, Satz-ID/Teilanzahl/Exportzeit, Plan-Prüfsummen, alle Abschnitte
genau einmal, Anhang-SHA-256/Größe/Typ). Einzeldateien Version 1 (ohne Anhänge) und
2 (`attachments` mit `data`) bleiben importierbar. Jeder referenzierte Anhang muss im
Satz oder bereits lokal vorhanden sein; Anhänge werden in derselben
Transaktion wie die Pläne gespeichert. Import ordnet SMS-Elemente über
`definition.nodes[].alarm.title` (case-insensitive, bei Mehrdeutigkeit zusätzlich
Text + Zielrufnummer) lokalen Vorlagen zu und legt alles in einer Transaktion als
neue Entwürfe an (`imported`). Details: [notfallplan.md](notfallplan.md#export-und-import-von-notfallplänen).

## 5. Oberfläche und Interaktionsdesign

### 5.1 Seitenaufbau (`views/emergency/editor.php`)

Der Editor öffnet sich aus der Planliste in einem **eigenen Browser-Tab** und
nutzt das Vollbild-Layout `views/layouts/editor.php` (ohne Admin-Navigation).
Wurzel ist `.ep-office[data-ep-editor]`, ein CSS-Grid mit vier Zeilen auf
`100vh`:

1. **Titelleiste** `.ep-titlebar`: Schnellzugriff (Speichern, Rückgängig,
   Wiederholen), Dokumenttitel (`data-ep-doc-title`) mit Ungespeichert-Punkt
   (`data-ep-dirty-flag`), Freigabestatus (`data-ep-review-status`),
   **Live-Vorschau** (`data-ep-open-preview`) und **Schließen** (`data-ep-close`;
   schließt den Tab, wenn er per `window.open`/Link geöffnet wurde, sonst
   Rücksprung zur Planliste).
2. **Menüband** `.ep-ribbon`: Reiterleiste (`role="tablist"`, Buttons
   `data-ep-tab`) und Panels (`data-ep-tabpanel`), gegliedert in Gruppen
   `.ep-rg` mit großen Schaltflächen `.ep-rb` (Inline-SVG-Icon + Textlabel):
   - **Start** – Schritt hinzufügen (`data-ep-add-type="<typ>"`, 1 Klick),
     Bearbeiten (Rückgängig/Wiederholen `data-ep-undo`/`data-ep-redo`,
     Duplizieren, Löschen), Reihenfolge (`data-ep-move`), Plan
     (Plan-Angaben einblenden `data-ep-plan-toggle`, Beispielvorlagen
     `data-ep-template`).
   - **Ablauf & Verbindungen** – Suche (`data-ep-search`, Treffer
     `data-ep-search-result`), Verknüpfung UND/ODER (`data-ep-join`),
     Hinweis auf das Voraussetzungs-Fieldset im Eigenschaften-Bereich.
   - **Prüfen & Freigabe** – Plan prüfen (`data-ep-validate`), Entwurf
     speichern (`data-ep-save`), Freigabe & Protokoll
     (`data-ep-open-dialog="review"`), Statuszeile.
   - **Ansicht** – Zoom (`data-ep-zoom="in|out|fit|center|reset"`), Bereiche
     ein-/ausblenden (`data-ep-toggle-panel="palette|inspector"`),
     Live-Vorschau, Hilfe (`data-ep-open-dialog="help"`), Design wechseln
     (`data-theme-toggle` aus `app.js`).
3. **Arbeitsbereich** `.ep-workspace3` (`250px | 1fr | 360px`):
   - **Schritte** `.ep-palette`: Baustein-Buttons (`data-ep-add-type`,
     zusätzlich `draggable` mit `data-ep-drag-type`), Zähler
     (`data-ep-count`), nummerierte Schrittliste (`data-ep-list`) mit
     Typfarbe, Fehler-Badge und Suchfilter.
   - **Ablaufdiagramm** `.ep-canvas[data-ep-canvas]` → `data-ep-diagram`
     (SVG) auf Punktraster, Zoom-Overlay unten rechts, Drop-Zone für
     Bausteine, Fußzeile mit Bedienhinweis.
   - **Eigenschaften** `.ep-inspector`: aufklappbares Prüfergebnis
     (`data-ep-issues`), Plan-Angaben (`data-ep-plan-panel`: `data-ep-title`,
     `data-ep-description`; automatisch sichtbar, solange kein Schritt
     gewählt ist) und Schritt bearbeiten (`data-ep-node-panel` →
     `data-ep-fields`).
   - ≤ 1200 px: schmalere Seitenspalten; ≤ 900 px: Spalten untereinander,
     Seite scrollt.
4. **Statusleiste** `.ep-statusbar`: Freigabestatus, Revision
   (`data-ep-revision`), Schrittzahl, Prüfstatus-Button
   (`data-ep-issue-count`), Meldungen (`data-ep-message`, `aria-live`),
   Vorschau-Kopplung (`data-ep-preview-status`), Zoomstufe
   (`data-ep-zoom-level`).
5. **Dialoge**: `<dialog data-ep-dialog="review">` enthält unverändert das
   serverseitig gerenderte Freigabe-Panel `[data-ep-review-panel]` (klassische
   Formulare `data-ep-review-form`) und das Freigabeprotokoll;
   `<dialog data-ep-dialog="help">` die Bedienhilfe und Tastenkürzel.
   Nur bei neuen Plänen (`id = 0`) zusätzlich `<dialog data-ep-setup>`: Pflicht-
   Overlay für Plantitel (`data-ep-setup-title`) und Kurzbeschreibung
   (`data-ep-setup-description`), das beim Öffnen modal erscheint, sich nicht
   per Esc/Hintergrundklick schließen lässt und die Werte nach `definition`
   sowie in die Plan-Angaben übernimmt; *Abbrechen* (`data-ep-setup-cancel`)
   löst `data-ep-close` aus.
6. `<noscript>`-Hinweis: ohne JavaScript keine Bearbeitung.

Mehrere Bedienelemente mit derselben Funktion (z. B. `data-ep-save` in
Titelleiste und Menüband) sind erlaubt; das Skript bindet immer **alle**
Treffer (`$$()`/`setText()`).

### 5.2 Clientzustand (Block `if (editor)`)

| Variable | Bedeutung |
| --- | --- |
| `initial` | Serverstand (`id`, `revision`, …); nach Speichern aktualisiert |
| `definition` | **Einzige Quelle der Wahrheit** im Browser; alle Eingaben mutieren dieses Objekt direkt |
| `selected` | ID des gewählten Schritts |
| `dirty` | ungespeicherte Änderungen (steuert `beforeunload`, Titelpunkt und Sperre der Freigabeformulare) |
| `journal` | Undo/Redo-Journal: `past[]`/`future[]` mit JSON-Schnappschüssen (`definition` + `selected`), max. 100 Einträge; Texteingaben desselben Felds werden zusammengefasst (Coalescing). Darf nicht `history` heißen (`window.history` wird in `save()` genutzt). |
| `issues` | Ergebnis der Client-Prüfung (`null` = noch nicht geprüft; sonst Liste `{nodeId, level, text}`) |
| `zoom`, `filter` | Zoomfaktor des Diagramms (0,3–2,5) und Suchbegriff |
| `planPanelPinned` | Plan-Angaben trotz gewähltem Schritt eingeblendet |
| `previewChannel`, `previewVersion` | Live-Vorschau-Kopplung (siehe 9) |

Jede Änderung ruft `mark()` auf: `dirty = true`, Meldung „Ungespeicherte
Änderungen.“, `previewVersion++`, Vorschau-Push entprellt (150 ms); wurde
bereits einmal geprüft, läuft `validate()` live mit. Strukturelle Änderungen
gehen über `commit(label, fn)` (Schnappschuss ins Journal → Mutation →
`mark()` → `render()`). Rendering ist bewusst einfach: `render()` baut Liste,
Diagramm und Inspector komplett neu; Texteingaben rufen nur `refreshGraph()`
(und bei Titel `renderList()`) auf, damit der Fokus im Eingabefeld bleibt.

### 5.3 Funktionen des Editors

| Funktion | UI | Umsetzung / Regeln |
| --- | --- | --- |
| Schritt hinzufügen | Baustein-Button (Palette oder Menüband „Start“) **oder** Baustein aufs Diagramm ziehen | `addNode(type)` → `makeNode(type)`; wird **ans Ende** gehängt und automatisch mit dem bisher letzten Schritt verbunden (`when: always`). Max. 80. Checklisten starten mit einem Prüfpunkt „Prüfpunkt“. Drag-and-Drop nutzt `dataTransfer` (`text/ep-type`) und die Drop-Zone `.ep-canvas`. |
| Auswählen | Klick/Enter/Leertaste auf SVG-Knoten oder Listeneintrag | `select(id)` → `render()`; Listeneintrag erhält `aria-current`; Diagramm scrollt den Knoten in den sichtbaren Bereich. |
| Felder bearbeiten | Eigenschaften-Bereich | `inputField()` für Titel/Frage, Anweisung, Zuständigkeit, Telefon, Informationslink, Zielzeit (`type=number`, 0–10080). `maxLength` entspricht den Servergrenzen. Laienfreundliche Beschriftungen („Schritt“, „Voraussetzung“, „Zielzeit“). |
| Anhänge | Fieldset „Anhänge (Bilder, PDF)“ im Inspector zwischen Telefon und Informationslink (nur `action`/`contact`/`decision`/`note`); Kontextmenü „Anhang hinzufügen“ (Upload-Overlay mit Dropzone) und „Anhänge verwalten (n)“ (Liste mit „Vorschau“ und „✕“ + Ja/Nein-Rückfrage) | `uploadAttachments()` prüft Typ/Größe/Anzahl im Browser, lädt jede Datei per `POST /admin/notfallplan/anhang` hoch und hängt die Metadaten als ein Journal-Schritt an; `removeAttachment()` entfernt nur die Referenz. Drag-and-Drop von Dateien auf das Fieldset. Nach `await` wird der Schritt per `nodeById()` neu gesucht (Undo ersetzt Objekte). |
| Prüfpunkte | Textarea (nur `checklist`) | Eine Zeile = ein Punkt; Zeilen werden getrimmt, Leerzeilen verworfen. Grenze 20 prüft erst der Server. |
| SMS-Empfänger | Segment-Schalter „Alarmvorlage (Gruppe)“ / „Einzelne Rufnummern“ (nur `sms`) | setzt `node.sms_mode`; Wechsel ist ein Journal-Schritt und rendert den Inspector neu. |
| SMS-Vorlage | Dropdown (nur `sms`, Modus `template`) | Optionen aus `alarmOptions()` (aktive Navigationselemente vom Typ `alarm`); Anzeige „An <Ziel>: <Text>“. Hinweis: Daten werden beim Speichern kopiert, im Einsatz separat bestätigt. |
| SMS an einzelne Rufnummern | Textarea Rufnummern (eine pro Zeile), Textarea SMS-Text (`maxLength` 255) mit Zähler `x/255` (`.ep-sms-counter`, `is-over` bei Überschreitung), Textbaustein-Schaltflächen und Beispielvorschau (nur Modus `numbers`) | Bausteine (`SMS_PLACEHOLDERS`, synchron zu `EmergencyPlanSms::PLACEHOLDERS`) werden per `setRangeText` an der Cursorposition eingefügt. Der Zähler nutzt `smsLength()` (gleiche Regel wie der Server) und wird über `smsRefresh` aus `mark()` aktualisiert, damit auch Änderungen am Plan- oder Schritttitel sofort zählen. |
| Voraussetzungen | Fieldset „Voraussetzungen (vorherige Schritte)“ | Checkbox je **vorherigem** Schritt; Bedingung `Erledigt` bzw. bei Entscheidungen `Antwort Ja`/`Antwort Nein`. Klartext-Zusammenfassung („Startet, sobald ALLE/MINDESTENS EINE der Voraussetzungen erledigt ist: …“). Erster Schritt: „Startpunkt: keine Voraussetzungen.“ |
| Verknüpfung UND/ODER | Segment-Schalter „Alle (UND)“ / „Eine genügt (ODER)“ (Inspector und Menüband „Ablauf“) | `node.join` = `all` / `any`; Schalter erscheint nur bei ≥ 2 Voraussetzungen. |
| Schritt im Diagramm ziehen | Knoten mit der Maus ziehen (Schwelle 5 px, sonst normaler Klick) | Pointer-Events am `.ep-canvas` (`nodeDrag`). Beim ersten Ziehen werden die gerenderten Positionen **aller** Schritte als `node.x`/`node.y` festgeschrieben; der Knoten folgt live (`is-dragging`). **Loslassen auf freier Fläche**: nur Layout, Position auf 20-px-Raster gerundet, ein Journal-Schritt. **Loslassen auf einem anderen Schritt** (`is-drop-target`): `linkTo(id, ziel)` – Position bleibt unverändert, das Ziel wird **einzige** Voraussetzung (`when` bleibt erhalten, falls schon verbunden, sonst `always`), danach `dependencyOrder()` (stabile topologische Sortierung, damit Kanten weiter nach vorne zeigen). Ist das Ziel bereits (indirekter) Nachfolger, wird mit „Verbinden nicht möglich“ abgebrochen. `pointercancel` stellt den Ausgangszustand wieder her. |
| Automatisch anordnen | Menüband „Ansicht“ → „Automatisch anordnen“ (`data-ep-auto-layout`) | Entfernt `x`/`y` aller Schritte (ein Journal-Schritt); danach gilt wieder das Ebenen-Layout. |
| Reihenfolge | „Nach oben“ / „Nach unten“ | `move(±1)` tauscht Nachbarn und verweigert den Tausch, wenn danach eine Kante auf einen späteren Schritt zeigen würde („Verschieben würde eine Verbindung umkehren…“). |
| Duplizieren | Button | `structuredClone` direkt dahinter, neue ID, Titel + „ (Kopie)“, ohne `x`/`y`; Voraussetzungen werden übernommen, Nachfolger nicht. Max. 80. |
| Löschen | Button mit `confirm`, Taste `Entf` bei fokussiertem Diagramm | Entfernt Schritt **und alle Kanten darauf**; Nachfolger ohne weitere Voraussetzungen werden Startpunkte. |
| Rückgängig / Wiederholen | Schnellzugriff, Menüband, `Strg+Z` / `Strg+Y` (`Strg+Umschalt+Z`) | `undo()`/`redo()` stellen den Schnappschuss aus `journal` wieder her, setzen `dirty` und rendern neu. Betrifft nur den Entwurf im Browser, nie den Serverstand. |
| Plan prüfen | Menüband „Prüfen“, Statusleiste | `validate()` – **Komfortprüfung im Browser**, Server bleibt maßgeblich (siehe 6). Fehler: leerer Plantitel, leerer Schritttitel, Checkliste ohne Prüfpunkt, SMS ohne Vorlage, SMS an einzelne Rufnummern ohne/mit ungültiger/zu vielen Rufnummern, ohne Text oder über 255 Zeichen, ungültiger Link. Hinweise: Schritt ohne Voraussetzung (außer dem ersten), Entscheidung ohne Folgeschritt. Betroffene Schritte werden in Liste (Badge) und Diagramm (`--error`/`--hint`) markiert; Klick auf einen Eintrag wählt den Schritt. |
| Suchen / Filtern | Feld im Menüband „Ablauf & Verbindungen“ | Filtert die Schrittliste und dimmt nicht passende Knoten (`is-dimmed`); gesucht wird in Titel, Anweisung und Zuständigkeit. |
| Zoom / Pan | Overlay im Diagramm, Menüband „Ansicht“, `Strg++`/`Strg+-`/`Strg+0`, Strg + Mausrad, Ziehen mit der Maus | `setZoom(f)` skaliert die SVG-Breite/-Höhe relativ zur `viewBox` (0,3–2,5); `fitZoom()` passt an die Fläche an (beim Laden), `centerSelected()` scrollt zum gewählten Knoten. |
| Beispielvorlagen | „Beispiel Brandfall/MANV“ (Menüband „Start“) | Ersetzt den gesamten Entwurf (Rückfrage, falls nicht leer) durch eine lineare Kette; Kante nach einer Entscheidung ist `yes`. Beschreibung kennzeichnet „BEISPIEL – … fachlich freigeben“. |
| Speichern | „Entwurf speichern“ (Titelleiste, Menüband), `Strg+S` | siehe 7. |
| Live-Vorschau | Titelleiste, Menüband, `Alt+Umschalt+V` | öffnet neuen Tab, siehe 9. |
| Bereiche / Design | Menüband „Ansicht“ | `ep-hide-palette`/`ep-hide-inspector` am `.ep-office`; Theme über `data-theme-toggle` aus `app.js`. |
| Schließen | Titelleiste | `window.close()` bei geöffnetem Opener, sonst Rücksprung zur Planliste; `beforeunload` warnt bei `dirty`. |
| Ungespeichert verlassen | – | `beforeunload`-Warnung, solange `dirty`. |
| Freigabe mit ungespeicherten Änderungen | Formulare im Freigabe-Dialog | Submit wird blockiert, Dialog schließt: „Bitte Änderungen zuerst speichern…“. |

### 5.4 Diagramm (`diagram(container, definition, selected, onSelect, progress)`)

- **Layout**: Ebene eines Elements = `max(Ebene der Vorgänger) + 1`, Start = 0.
  Innerhalb einer Ebene in Listenreihenfolge nebeneinander, zentriert.
  Raster: Knoten 240 × 92 px, Spaltenbreite 280 px, Zeilenhöhe 148 px;
  SVG-Breite `max(340, maxSpalten × 280)`. Gilt, solange kein Schritt eine
  gespeicherte Position hat.
- **Manuelles Layout**: Sobald ein Schritt `x`/`y` besitzt (Drag-and-Drop im
  Editor), werden diese Koordinaten (linke obere Ecke, SVG-Einheiten) genutzt.
  Schritte ohne Position (neu, dupliziert, eingefügt) landen unter ihrem
  ersten Vorgänger bzw. oben links und weichen belegten Plätzen nach rechts
  aus. SVG-Größe = größte Ausdehnung + Rand. Die Positionen sind reines
  Layout und beeinflussen Reihenfolge und Ablauf nicht.
- **Kanten**: kubische Bézierkurve mit achsenparallelen Anschlüssen je nach
  Lage des Ziels: darunter Unterkante → Oberkante, darüber Oberkante →
  Unterkante, sonst seitlich von Kante zu Kante. Die Pfeilspitze ist ein
  eigenes Dreieck (`.ep-arrow`, kein SVG-Marker); die Linie endet exakt
  mittig an seiner Basis und läuft dort in Pfeilrichtung ein, sodass Pfeile
  nie verzerrt wirken. Alle Kanten liegen in einer Gruppe `.ep-edges`
  (`pointer-events: none`) **über** den Knoten, damit sie durchgehend
  sichtbar bleiben; `yes`/`no` erhalten ein Label „Ja“/„Nein“ am
  Kurvenmittelpunkt.
- **Knoten**: `<g role="button" tabindex="0">` mit `aria-label` „N. Titel“;
  Zeile 1 „N · Typ“, Zeilen 2–3 Titel (je 28 Zeichen, danach „…“),
  Zeile 4 im Editor die Zuständigkeit, mit `progress` der Status
  („Wartet auf Vorgänger“, „Entfällt“, Statusname).
- **Styling** über Klassen `ep-graph-node--<typ>`, `--<status>`, `--skipped`,
  `is-selected`; Entscheidungen haben abgerundete Ecken (`rx=25`).
- Dieselbe Funktion zeichnet die statischen Diagramme in Plan-, Ereignis- und
  Vorschauansicht (`renderStaticDiagram`); dort scrollt ein Klick zur
  Maßnahmenkarte `#node-<id>`.


**Anhänge:** Hat ein Schritt `attachments`, zeichnet `diagram()` oben rechts im
Knoten eine Büroklammer (`.ep-node-clip`, Klick öffnet `showAttachments()`).
`showAttachments(list, start, opener)` ist ein modales `<dialog class="ep-viewer">`
im Stil der App-Mitteilungen (`.announcement-overlay__box`), bei mehreren
Anhängen mit Tabs (`role=tablist`, Pfeiltasten/Pos1/Ende) an der Oberkante; Bilder
als `<img>`, PDF als `<iframe>`. In Plan- und Einsatzansicht rendert
`views/emergency/attachment-button.php` die Schaltfläche `.ep-clip` mit
`data-ep-attachments` (Dokument-Delegation in `emergency-plan.js`).
## 6. Validierung (Server)

`EmergencyPlanDefinition::validate(array $input, bool $preview = false)`
iteriert die Elemente **in Reihenfolge** und merkt sich gesehene IDs/Typen
(`$seen`). Dadurch sind Zyklen, Vorwärtskanten und unbekannte Vorgänger mit
einer einzigen Prüfung („`isset($seen[$edge['id']])`“) ausgeschlossen.

Mit `$preview = true` (nur Live-Vorschau) sind gelockert: leerer Plantitel,
leere Elementliste, leere Elementtitel, Checkliste ohne Prüfpunkte, SMS ohne
Vorlage bzw. ohne Rufnummern/Text. Alle übrigen Regeln (Längen, IDs, Graph, Links, Zielzeit) gelten
unverändert – ungültige Zwischenstände erscheinen als Fehlermeldung in der Vorschau.

`EmergencyPlanService::prepare()` prüft zusätzlich jede SMS-Vorlage (nur Modus `template`): muss
existieren, Typ `alarm`, aktiv, Text nicht leer und ≤ 255 Zeichen,
Zielrufnummer nicht leer, `alarm_group_type` ∈ `group|number`.

Fehler werden als `ValidationException(['plan' => …])` bzw. `['sms' => …]`
geworfen und vom Controller als HTTP 422 `{error}` zurückgegeben. Der Client
validiert nur das Nötigste (Maximalzahl, Kantenrichtung beim Verschieben,
`maxLength`) – **maßgeblich ist immer der Server**.

## 7. Speichern und Konflikte

Ablauf (`data-ep-save`-Handler → `EmergencyPlanController::save()`):

1. Client sendet `FormData`: `_token`, `id` (0 = neu), `revision`,
   `definition` (JSON). Alle Bedienelemente des Editors sind währenddessen deaktiviert.
2. Controller: JSON ≤ 600 000 Byte, Tiefe ≤ 64, muss Objekt/Array sein.
3. `EmergencyPlanService::save()` → `prepare()` → `EmergencyPlanRepository::savePlan()`
   in einer Transaktion:
   - neu: `INSERT`, `revision` = 1, `contributors = [actor]`;
   - bestehend: `UPDATE … WHERE id = ? AND revision = ?` → `revision + 1`,
     `review_state = 'draft'`, offener Antrag (`submitted_*`) wird gelöscht.
     Trifft das `UPDATE` keine Zeile, wirft `assertChanged()` HTTP 409.
   - `contributors`: war der Plan `approved`, beginnt die Liste neu mit dem
     aktuellen Akteur; sonst wird der Akteur ergänzt.
   - Protokolleintrag `saved`.
4. Antwort `{id, revision, message}`; Client setzt `initial.id/revision`,
   `dirty = false`, ersetzt die URL per `history.replaceState` durch
   `/admin/notfallplan/bearbeiten?id=<id>`, aktualisiert Revision/Status in
   Statusleiste und Titelleiste und ersetzt das Freigabe-Panel im Dialog durch
   einen Link zum Neuladen (der Antrag ist nur über die serverseitig gerenderte
   Seite möglich).
5. Fehler (422/409/Sitzung abgelaufen = keine JSON-Antwort): Meldung +
   „Der Entwurf bleibt hier erhalten.“ – nichts wird überschrieben; der Benutzer
   muss den Stand abgleichen (z. B. Seite in neuem Tab öffnen) und erneut speichern.

## 8. Vier-Augen-Freigabe

Zustandsautomat von `review_state` (alle Übergänge transaktional mit
`revision`-Prüfung, sonst 409):

```
           save                submit              approve (andere Person)
 (neu) ─────────▶ draft ────────────────▶ pending ───────────────────────▶ approved
                   ▲  ▲                     │  │                             │
                   │  └──── save ───────────┘  │ reject (Kommentar Pflicht)   │ save
                   │                           ▼                              │
                   └──────── save ────────── rejected ◀───────────────────────┘
 withdraw (jederzeit bei published=1): published=0, revision+1, review_state=draft
```

- `submit` nur aus `draft`/`rejected` und nur für die aktuell gespeicherte Revision.
- `approve`/`reject` nur aus `pending`; verboten für alle `contributors` und
  `submitted_by` (HTTP 403, auch für Administratoren). Die View zeigt die
  Buttons nur, wenn `$canReview` gilt; der Server prüft unabhängig davon.
- `approve` kopiert `definition` + `publication`-Block nach
  `published_definition`, setzt `published = 1`, `published_revision = revision`.
- `reject` benötigt einen nicht leeren Kommentar (≤ 2000).
- `withdraw` sperrt sofort ohne zweite Person; Folgeveröffentlichung braucht
  wieder `submit` + `approve`. Die Rücknahme macht den Prüfer **nicht** zum Mitautor.
- Neuer Entwurf ändert die Live-Version nicht; laufende Ereignisse nutzen
  ohnehin ihren eigenen Snapshot.

## 9. Live-Vorschau

```
 Editor-Tab                                   Vorschau-Tab (/admin/notfallplan/vorschau#<uuid>)
 ──────────                                   ─────────────────────────────────────────────────
 Klick „Live-Vorschau“ → uuid, BroadcastChannel('ep-preview-<uuid>')
                       ◀──── {type:'sync'} / {type:'ping'} (alle 5 s) ────
 {type:'definition', definition, version} ──▶  bei neuer version: Struktur vergleichen,
 {type:'alive', version}                  ──▶  ggf. Simulation zurücksetzen, rendern
 pagehide: {type:'disconnected'}          ──▶  Warnhinweis
                                               POST /admin/notfallplan/vorschau
                                               preview = {definition, operations[], started, startedAt}
                                               ◀── {html} (emergency.plan bzw. emergency.event)
```

- Kanalname mit zufälliger UUID → mehrere Editoren/Vorschauen stören sich nicht.
  Vorschau akzeptiert nur Hash `^[a-f0-9-]{36}$`.
- Entwurf und Simulationsschritte (`operations`) existieren **nur im
  Arbeitsspeicher** der Tabs; kein Local Storage, keine Datenbank.
- Server: `previewRender()` (Rolle + CSRF, JSON ≤ 1,2 MB) →
  `EmergencyPlanPreview::build()` validiert mit `$preview = true`, bettet
  SMS-Vorlagen aus `alarmOptions()` ein und spielt `operations` (≤ 200) über
  `EmergencyPlanRuntime::change()` nach. SMS werden als „SIMULATION“ markiert.
  Kein Repository, kein Versand, keine Kennwortprüfung.
- „Strukturänderung“ = Änderung an `[id, type, dependencies, join, checks, alarm_id, sms_mode, sms_numbers]`
  irgendeines Elements → Simulation wird zurückgesetzt; reine Textänderungen
  behalten den Fortschritt.
- Rendering ist versions- und epochengesichert (`version`, `epoch`), entprellt
  (150 ms), mit 15-s-Timeout; Formulareingaben, offene `<details>`, Fokus und
  Scrollposition werden über Neu-Renderings erhalten (`replaceContent`).
- Verbindungsstatus: ohne Nachricht vom Editor > 15 s erscheint ein Warnhinweis.

## 10. Laufzeitsemantik (was der Editor konfiguriert)

Der Editor erzeugt nur die Definition; die Bedeutung entsteht in
`EmergencyPlanDefinition::readiness()` und `EmergencyPlanRuntime::change()`:

- Je Kante ein Ergebnis: Vorgänger `skipped` → `skip`; Vorgänger nicht `done` →
  `wait`; `when = always` oder Antwort passt → `yes`; sonst `skip`.
- Keine Kanten → `ready` (Startpunkt; mehrere Startpunkte laufen parallel).
- `join = all`: irgendein `skip` → `skipped`, sonst irgendein `wait` → `waiting`, sonst `ready`.
- `join = any`: irgendein `yes` → `ready`, sonst irgendein `wait` → `waiting`, sonst `skipped`.
- Entscheidungen verlangen beim Erledigen `answer` `yes`/`no`; Checklisten
  erst „Erledigt“, wenn alle Punkte bestätigt sind; SMS-Elemente benötigen eine
  separate Bestätigung (`action = sms`), „Erledigt“ ohne Gateway-Erfolg nur mit Kommentar.
- `minutes` wird ab `started_at` des Ereignisses als Zielzeit ausgewertet.

Konsequenz für Plangestaltung: Ein Zusammenführungsknoten nach einem
Ja/Nein-Zweig braucht `join = any`, sonst entfällt er, sobald ein Zweig entfällt.

## 11. Invarianten (nicht brechen!)

1. **Reihenfolge = topologische Ordnung.** Kanten zeigen nur auf frühere
   Elemente. Client (`move`, Vorgänger-Checkboxen nur für frühere Elemente) und
   Server (`validate`) erzwingen das; `readiness()` und `diagram()` verlassen
   sich darauf (Single Pass).
2. **Server validiert alles**; Client-Prüfungen sind nur Komfort.
3. **Speichern veröffentlicht nie** und setzt `review_state` immer auf `draft`.
4. **Optimistische Sperre** über `revision` bei jedem schreibenden Plan-Übergang;
   bei Konflikt wird nichts überschrieben (409).
5. **Vier Augen gelten für alle Rollen**; Mitautoren und Antragsteller dürfen
   nicht entscheiden. Akteur bei erkannter AD-Anmeldung = AD-Identität.
6. `published_definition` und Ereignis-Snapshots werden durch Entwurfsänderungen
   **nie** verändert.
7. SMS-Daten werden beim Speichern aus der Vorlage **kopiert**; spätere
   Vorlagenänderungen wirken erst nach erneutem Speichern + Freigabe.
   Textbausteine werden ausschließlich beim Ereignisstart ersetzt (nie im Entwurf
   oder in `published_definition`).
8. Vorschau ist **nebenwirkungsfrei**: kein Repository-Zugriff, kein Versand,
   gleiche Zustandsregeln wie Einsatz (`EmergencyPlanRuntime`).
9. Ausgaben im DOM ausschließlich über `textContent`/`Html::e()`; das Diagramm
   setzt Text nur per `textContent` (XSS-Schutz). Nur das vom Server gerenderte
   Vorschau-HTML wird per `innerHTML` eingesetzt.
10. Grenzwerte in Client (`maxLength`, 80 Elemente) und Server (`validate`) synchron halten.
11. Anhänge sind unveränderlich und inhaltsadressiert; Definitionen enthalten nur
    Metadaten, nie Dateiinhalte. Export enthält alle referenzierten Anhänge.

## 12. Tests

Dependency-freier Runner: `php tests/run.php`.

| Datei | Relevante Tests |
| --- | --- |
| `tests/Unit/EmergencyPlanTest.php` | „Graphvalidierung, Grenzen und sichere Links“, „Ja/Nein-Zweige und ODER-Zusammenführung“, „Vier-Augen-Pflicht für Autoren, Mitautoren und Antragsteller“, „Ablehnung benötigt Kommentar, Historie bleibt erhalten“, „Entwurf ändert Live-Plan nicht; veraltete Freigabe scheitert“, „Rücknahme durch Prüfer erzeugt keine künstliche Mitautorschaft“, „Export und Import …“ |
| `tests/Unit/EmergencyPlanPreviewTest.php` | Gelockerte Vorschauvalidierung, gleiche Laufzeitregeln wie Einsatz, Checklisten, simulierte SMS, Rendern ohne echte Endpunkte, Begrenzung auf 200 Aktionen |

Das Editor-JavaScript hat keine automatisierten Tests; Änderungen manuell im
Browser prüfen (Hinzufügen, Verbinden, Verschieben, Löschen, Speichern,
Konflikt durch zweiten Tab, Live-Vorschau inkl. Strukturänderung).

## 13. Änderungsrezepte

| Aufgabe | Vorgehen |
| --- | --- |
| **Neues Feld je Element** | `EmergencyPlanDefinition::validate()` (prüfen + in `$result` aufnehmen, sonst wird es verworfen), `makeNode()` Default, Inspector-Feld in `render()`, Anzeige in `views/emergency/plan.php`/`event.php` (und ggf. KAEP-Dashboard), Test in `EmergencyPlanTest.php`. Alte Pläne haben das Feld nicht → in Views mit Default lesen. Strukturrelevant für die Vorschau? Dann in `nextStructure` aufnehmen. |
| **Neuer Elementtyp** | `EmergencyPlanDefinition::TYPES`, `types` in JS, `<option>` in `editor.php`, CSS `.ep-graph-node--<typ>`, Laufzeitregeln in `EmergencyPlanRuntime::change()`, Ereignis-/Vorschau-Views, Tests. |
| **Grenzwert ändern** (Elemente, Prüfpunkte, Längen) | Server (`validate`) **und** Client (`maxLength`, 80-Prüfungen, Hilfetexte) sowie `docs/notfallplan.md` anpassen. |
| **Neue Beispielvorlage** | Button `data-ep-template="<key>"` in `editor.php`, Inhalt im Template-Handler in `emergency-plan.js`; Vorlage als „BEISPIEL“ kennzeichnen. |
| **Layout des Diagramms** | Nur `diagram()` ändern; wird auch für Plan-, Ereignis- und Vorschauansicht genutzt. Rastermaße konsistent halten (Kantenanker `+120`/`+92`). |
| **Freigabeworkflow ändern** | `EmergencyPlanRepository::submit/review/withdraw/savePlan`, Panel in `editor.php`, Tests „Vier-Augen…“. Invariante 5 nicht aufweichen. |
| **Exportformat ändern** | `EmergencyPlanTransfer::VERSION` erhöhen und Import abwärtskompatibel halten (`EmergencyPlanService::IMPORT_VERSIONS` für Einzeldateien); Tests „Export und Import…“, „Export-Satz …“. |

Nach Änderungen: `php tests/run.php`; diese Referenz sowie bei Benutzersicht
`docs/notfallplan.md` und `agentsindex.md` aktualisieren.

## 14. Bekannte Eigenheiten

- Die vom Controller gemeldete neue Revision wird berechnet
  (`requestedId === 0 ? 1 : revision + 1`), nicht aus der Datenbank gelesen.
- Nach erfolgreichem Speichern ersetzt der Client das Freigabe-Panel durch einen
  Link; Freigabe anfordern ist erst nach Neuladen möglich.
- Ist eine im Plan gewählte Alarmvorlage inzwischen inaktiv, fehlt sie im
  Dropdown (Anzeige „Keine aktive Vorlage gewählt“), `alarm_id` bleibt aber
  gesetzt; Speichern scheitert dann bis zur Neuauswahl mit
  „Eine ausgewählte SMS-Vorlage ist nicht aktiv oder unvollständig.“
- Zielzeit-Feld: leere Eingabe wird zu `0`; Nachkommawerte lehnt der Server ab.
- Die Grenze von 20 Prüfpunkten und 80 Kanten prüft nur der Server.
- Beispielvorlagen prüfen keine 80-Elemente-Grenze (sie sind deutlich kleiner).
- Die Live-Vorschau benötigt `BroadcastChannel` und denselben Browserprofil-Kontext;
  sie ersetzt keine Entwurfssicherung.
