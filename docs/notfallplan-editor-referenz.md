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
  Export/Import nur `admin`.

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
| `views/emergency/editor.php` | Editor-Seite: Kopfleiste, Plantitel/-beschreibung, Bedienhilfe + Beispielvorlagen, 3-Spalten-Editor (Bausteine, Diagramm, Inspector), Freigabe-Panel und Freigabeprotokoll. Übergibt Plan und Alarmvorlagen als JSON in versteckten `<textarea>` (`data-ep-initial`, `data-ep-alarms`). |
| `public/assets/js/emergency-plan.js` | Gemeinsames Skript für Editor (`[data-ep-editor]`), statische Diagramme (`renderStaticDiagram`), Live-Vorschau (`[data-ep-preview]`), Ereignisansicht (`[data-ep-event]`) und Startformular (`.ep-start`). Hilfsfunktionen `element()`, `svgElement()`, `diagram()`, `post()`. |
| `public/assets/css/emergency-plan.css` | Layout (`.ep-editor`-Grid, `.ep-palette`, `.ep-workspace`, `.ep-inspector`), SVG-Knotenfarben (`.ep-graph-node--<typ>/<status>`), Dark-Theme, Responsive-Umbrüche (1200 px / 650 px). |
| `app/Controllers/EmergencyPlanController.php` | `access()` (Rollen-/Akteursermittlung), `edit()`, `save()`, `preview()`, `previewRender()`, `review()`, `exportPlans()`, `importPlans()` sowie die Benutzer-/Ereignisaktionen. |
| `app/Services/EmergencyPlanDefinition.php` | Reine Funktionen: `validate()` (Schema + Graphregeln), `readiness()` (Freigabe-/Wartezustand je Element), `text()` (Textprüfung). Konstanten `TYPES`, `STATUSES`. |
| `app/Services/EmergencyPlanService.php` | `save()`, `prepare()` (Validierung + SMS-Vorlage einbetten), `alarmOptions()` (aktive Alarmkacheln für das Dropdown), `exportPlans()`, `importPlans()`, `matchAlarm()`. |
| `app/Repositories/EmergencyPlanRepository.php` | `plan()`, `plans()`, `publishedPlan()`, `savePlan()`, `importPlans()`, `submit()`, `review()`, `withdraw()`, `reviews()`, `reviewLog()`; Transaktionen und `assertChanged()` (HTTP 409). |
| `app/Services/EmergencyPlanPreview.php` | Baut aus Entwurf + simulierten Aktionen ein flüchtiges Ereignis (ohne Repository, ohne Versand). |
| `app/Services/EmergencyPlanRuntime.php` | Nebenwirkungsfreie Zustandsübergänge (`change()`), gemeinsam genutzt von Einsatz und Vorschau. |
| `views/emergency/preview.php` | Hülle des Vorschau-Tabs (Sandbox-Banner, Reset/Retry, Statuszeilen, `data-ep-preview-content`). |
| `views/emergency/plan.php`, `views/emergency/event.php` | Plan- und Ereignisansicht; werden in der Vorschau mit `preview => true` gerendert. |
| `views/emergency/publication.php` | Anzeige von Autoren/Freigeber/Version aus `definition.publication`. |
| `views/emergency/index.php` | Planliste mit „Neuen Notfallplan entwerfen“ / „Bearbeiten / Vorschau“, Freigabeeinstellungen, Export/Import. |
| `database/migrations/025_emergency_plans.sql`, `027_emergency_plan_approval.sql` | Tabellen `emergency_plans`, `emergency_plan_reviews` (Freigabespalten). |
| `public/index.php` | Routen (Gruppe `$requireKaep`, darin `$requireAdmin` für Export/Import); KAEP-Rolle wird in `$requireAuth` auf `/admin/notfallplan*` beschränkt. |

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
| POST | `/admin/notfallplan/plaene/export` | `exportPlans` | nur `admin` |
| POST | `/admin/notfallplan/plaene/import` | `importPlans` | nur `admin` |

Alle POST-Routen prüfen CSRF (`requireValidCsrf`). Antworten tragen
`Cache-Control: no-store`.

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
      "alarm_id": 0
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
| `node.alarm_id` | Ganzzahl ≥ 0; bei `sms` Pflicht (> 0), bei anderen Typen auf `0` normalisiert |

Alle Texte: Steuerzeichen außer Tab/LF/CR verboten, Werte werden getrimmt.
Unbekannte Felder werden von `validate()` verworfen (Whitelist-Ausgabe).

**Serverseitige Ergänzungen** (nicht vom Editor gesetzt):

- `node.alarm` – bei `sms` von `EmergencyPlanService::prepare()` eingebettete
  Kopie der Alarmvorlage (`title`, `alarm_text`, `alarm_group_number`,
  `alarm_group_description`, `alarm_group_type`). Wird bei jedem Speichern neu
  aus der Navigation kopiert; der Editor sendet das Feld ggf. mit, es wird
  verworfen und neu erzeugt.
- `publication` – nur in `published_definition`: `authors`, `approved_by`,
  `approved_at`, `revision` (`EmergencyPlanRepository::review()`).

### 4.3 Exportformat

`{"format": "lanpa-notfallplaene", "version": 1, "exported_at": …, "plans": [{title, source_id, source_revision, definition}]}` –
immer der **Entwurf**, ≤ 100 Pläne, ≤ 2 MB. Import ordnet SMS-Elemente über
`definition.nodes[].alarm.title` (case-insensitive, bei Mehrdeutigkeit zusätzlich
Text + Zielrufnummer) lokalen Vorlagen zu und legt alles in einer Transaktion als
neue Entwürfe an (`imported`). Details: [notfallplan.md](notfallplan.md#export-und-import-von-notfallplänen).

## 5. Oberfläche und Interaktionsdesign

### 5.1 Seitenaufbau (`views/emergency/editor.php`)

1. **Kopfleiste** `.ep-editor-bar` (sticky): „Zur Übersicht“, Freigabestatus
   (`data-ep-review-status`), **Entwurf speichern** (`data-ep-save`),
   **Live-Vorschau in neuem Tab** (`data-ep-open-preview`), Statusmeldungen
   (`data-ep-message`, `aria-live`), Vorschau-Status (`data-ep-preview-status`).
2. **Planmetadaten**: Plantitel (`data-ep-title`), Kurzbeschreibung (`data-ep-description`).
3. **Bedienhilfe und Beispielvorlagen** (`<details>`): Erklärtext und Buttons
   `data-ep-template="fire"` / `"manf"`.
4. **Editor-Grid** `.ep-editor` mit drei Spalten (`200px | 1fr | 280–340px`):
   - **Bausteine** `.ep-palette`: Typauswahl (`data-ep-add-type`),
     „Element hinzufügen“ (`data-ep-add`), nummerierte Elementliste (`data-ep-list`).
   - **Ablaufdiagramm** `.ep-workspace` → `data-ep-diagram` (SVG).
   - **Element bearbeiten** `.ep-inspector` → `data-ep-fields`.
   - ≤ 1200 px: Inspector rutscht unter die beiden anderen Spalten; ≤ 650 px: einspaltig.
5. **Vier-Augen-Freigabe** `[data-ep-review-panel]` (serverseitig gerendert,
   klassische Formulare mit `data-ep-review-form`) inkl. Freigabeprotokoll.
6. `<noscript>`-Hinweis: ohne JavaScript keine Bearbeitung.

### 5.2 Clientzustand (Block `if (editor)`)

| Variable | Bedeutung |
| --- | --- |
| `initial` | Serverstand (`id`, `revision`, …); nach Speichern aktualisiert |
| `definition` | **Einzige Quelle der Wahrheit** im Browser; alle Eingaben mutieren dieses Objekt direkt |
| `selected` | ID des gewählten Elements |
| `dirty` | ungespeicherte Änderungen (steuert `beforeunload` und Sperre der Freigabeformulare) |
| `previewChannel`, `previewVersion` | Live-Vorschau-Kopplung (siehe 9) |

Jede Änderung ruft `mark()` auf: `dirty = true`, Meldung „Ungespeicherte
Änderungen.“, `previewVersion++`, Vorschau-Push entprellt (150 ms).
Rendering ist bewusst einfach: `render()` baut Liste, Diagramm und Inspector
komplett neu; Texteingaben rufen nur `refreshGraph()` (und bei Titel
`renderList()`) auf, damit der Fokus im Eingabefeld bleibt.

### 5.3 Funktionen des Editors

| Funktion | UI | Umsetzung / Regeln |
| --- | --- | --- |
| Element hinzufügen | Typ wählen → „Element hinzufügen“ | `makeNode(type)`; wird **ans Ende** gehängt und automatisch mit dem bisher letzten Element verbunden (`when: always`). Max. 80. Checklisten starten mit einem Prüfpunkt „Prüfpunkt“. |
| Auswählen | Klick/Enter/Leertaste auf SVG-Knoten oder Listeneintrag | `select(id)` → `render()`; Listeneintrag erhält `aria-current`. |
| Felder bearbeiten | Inspector | `inputField()` für Titel/Frage, Anweisung, Zuständigkeit, Telefon, Informationslink, Zielzeit (`type=number`, 0–10080). `maxLength` entspricht den Servergrenzen. |
| Prüfpunkte | Textarea (nur `checklist`) | Eine Zeile = ein Punkt; Zeilen werden getrimmt, Leerzeilen verworfen. Grenze 20 prüft erst der Server. |
| SMS-Vorlage | Dropdown (nur `sms`) | Optionen aus `alarmOptions()` (aktive Navigationselemente vom Typ `alarm`); Anzeige „An <Ziel>: <Text>“. Hinweis: Daten werden beim Speichern kopiert, im Einsatz separat bestätigt. |
| Vorgänger | Fieldset „Vorgänger / Verbindungen“ | Checkbox je **vorherigem** Element; Bedingung `Erledigt` bzw. bei Entscheidungen zusätzlich `Antwort Ja`/`Antwort Nein`. Erstes Element: „Startpunkt: keine Vorgänger.“ |
| Verknüpfung | Select „Freigabe der Maßnahme“ | `all` = „Alle Vorgänger (UND)“, `any` = „Mindestens ein Vorgänger (ODER)“. |
| Reihenfolge | „↑ Nach oben“ / „↓ Nach unten“ | `move(±1)` tauscht Nachbarn und verweigert den Tausch, wenn danach eine Kante auf ein späteres Element zeigen würde („Verschieben würde eine Verbindung umkehren…“). |
| Duplizieren | Button | `structuredClone` direkt dahinter, neue ID, Titel + „ (Kopie)“; Vorgänger werden übernommen, Nachfolger nicht. Max. 80. |
| Löschen | Button mit `confirm` | Entfernt Element **und alle Kanten darauf**; Nachfolger ohne weitere Vorgänger werden Startpunkte. |
| Beispielvorlagen | „Beispiel Brandfall/MANF übernehmen“ | Ersetzt den gesamten Entwurf (Rückfrage, falls nicht leer) durch eine lineare Kette; Kante nach einer Entscheidung ist `yes`. Beschreibung kennzeichnet „BEISPIEL – … fachlich freigeben“. |
| Speichern | „Entwurf speichern“ | siehe 7. |
| Live-Vorschau | Link | siehe 9. |
| Ungespeichert verlassen | – | `beforeunload`-Warnung, solange `dirty`. |
| Freigabe mit ungespeicherten Änderungen | Formulare im Freigabe-Panel | Submit wird blockiert: „Bitte Änderungen zuerst speichern…“. |

### 5.4 Diagramm (`diagram(container, definition, selected, onSelect, progress)`)

- **Layout**: Ebene eines Elements = `max(Ebene der Vorgänger) + 1`, Start = 0.
  Innerhalb einer Ebene in Listenreihenfolge nebeneinander, zentriert.
  Raster: Knoten 240 × 92 px, Spaltenbreite 280 px, Zeilenhöhe 148 px;
  SVG-Breite `max(340, maxSpalten × 280)`. Kein manuelles Positionieren –
  Layout ergibt sich ausschließlich aus Reihenfolge und Kanten.
- **Kanten**: kubische Bézierkurve von Knotenunterkante zu Oberkante mit
  Pfeil-Marker (eindeutige ID `ep-arrow-N` je Diagramm); `yes`/`no` erhalten
  ein Label „Ja“/„Nein“.
- **Knoten**: `<g role="button" tabindex="0">` mit `aria-label` „N. Titel“;
  Zeile 1 „N · Typ“, Zeilen 2–3 Titel (je 28 Zeichen, danach „…“),
  Zeile 4 im Editor die Zuständigkeit, mit `progress` der Status
  („Wartet auf Vorgänger“, „Entfällt“, Statusname).
- **Styling** über Klassen `ep-graph-node--<typ>`, `--<status>`, `--skipped`,
  `is-selected`; Entscheidungen haben abgerundete Ecken (`rx=25`).
- Dieselbe Funktion zeichnet die statischen Diagramme in Plan-, Ereignis- und
  Vorschauansicht (`renderStaticDiagram`); dort scrollt ein Klick zur
  Maßnahmenkarte `#node-<id>`.

## 6. Validierung (Server)

`EmergencyPlanDefinition::validate(array $input, bool $preview = false)`
iteriert die Elemente **in Reihenfolge** und merkt sich gesehene IDs/Typen
(`$seen`). Dadurch sind Zyklen, Vorwärtskanten und unbekannte Vorgänger mit
einer einzigen Prüfung („`isset($seen[$edge['id']])`“) ausgeschlossen.

Mit `$preview = true` (nur Live-Vorschau) sind gelockert: leerer Plantitel,
leere Elementliste, leere Elementtitel, Checkliste ohne Prüfpunkte, SMS ohne
Vorlage. Alle übrigen Regeln (Längen, IDs, Graph, Links, Zielzeit) gelten
unverändert – ungültige Zwischenstände erscheinen als Fehlermeldung in der Vorschau.

`EmergencyPlanService::prepare()` prüft zusätzlich jede SMS-Vorlage: muss
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
   `/admin/notfallplan/bearbeiten?id=<id>` und ersetzt das Freigabe-Panel durch
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
- „Strukturänderung“ = Änderung an `[id, type, dependencies, join, checks, alarm_id]`
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
8. Vorschau ist **nebenwirkungsfrei**: kein Repository-Zugriff, kein Versand,
   gleiche Zustandsregeln wie Einsatz (`EmergencyPlanRuntime`).
9. Ausgaben im DOM ausschließlich über `textContent`/`Html::e()`; das Diagramm
   setzt Text nur per `textContent` (XSS-Schutz). Nur das vom Server gerenderte
   Vorschau-HTML wird per `innerHTML` eingesetzt.
10. Grenzwerte in Client (`maxLength`, 80 Elemente) und Server (`validate`) synchron halten.

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
| **Exportformat ändern** | `EXPORT_VERSION` erhöhen und Import abwärtskompatibel halten; Tests „Export und Import…“. |

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
