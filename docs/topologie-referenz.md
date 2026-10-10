# Nachrichtenfluss-Topologie – technische Referenz

Diese Datei beschreibt die **Topologie-Ansicht des Nachrichtenflusses**
(`/admin/office/orvanta/nachrichtenfluss/topologie`) auf Code-Ebene: Aufbau der
Hülle, Datenvertrag, Knoten- und Kantensemantik, Anordnung und Projektion,
Animationen, Interaktion, Aktualisierung sowie die Eigenheiten, die man beim
Ändern kennen muss.

Sie ist die **technische** Ergänzung zum Konzeptabschnitt
[`docs/orvanta-nachrichtenfluss.md`](orvanta-nachrichtenfluss.md) §14
(Bedienung und Absicht) und zu
[`docs/orvanta-referenz.md`](orvanta-referenz.md) §23.8. Dieses Dokument
dupliziert die Konzepttexte nicht, sondern beschreibt, *wie* die Ansicht
funktioniert und *warum* sie so gebaut ist.

> **Rangfolge:** Bei Widersprüchen gilt immer der Code. Fundstellen sind als
> Klasse/Funktion angegeben (nicht als Zeilennummer), damit sie beim
> Weiterentwickeln gültig bleiben.

## TL;DR

- Die Ansicht ist eine **eigenständige Vollbildseite** (Layout `layouts.editor`,
  ohne Seitenmenü) mit eigener dunkler „Leitwarte“-Gestaltung; sie erbt nur
  Schrift, Fokus und Hilfsklassen der Intranet-Oberfläche.
- Sie zeigt **dieselben Knoten, Kanten und Störungen** wie das Kartendashboard,
  räumlich als Netz. Es gibt **keine eigene Datenquelle**: Erstzustand wird als
  JSON eingebettet, danach wird derselbe JSON-Endpunkt wie das Dashboard
  gepollt (`…/nachrichtenfluss/daten`, `Cache-Control: no-store`).
- Zustände, Bezeichnungen und Zähler stammen ausschließlich aus
  `OrvantaFlowService::evaluate()`; die Ansicht **erfindet keine Zustände**.
- Zeichnen und Aktualisieren laufen in **reinem Vanilla-JS (ES5, IIFE)** auf
  `<canvas>`; keine Bibliothek, kein Bundler, kein `innerHTML`, keine
  Inline-Stile.
- **Zufall ist deterministisch** (FNV-1a-Hash + xorshift), damit Sterne, Bögen
  und Wolkenpunkte nach jedem Laden identisch aussehen.
- Knoten behalten ihre Position über Aktualisierungen; nur neue/entfernte
  Knoten oder ein Wechsel des Transportwegs ordnen neu an.
- Standard-Aktualisierung: **180 s** (Einstellung `poll_interval` = 60 s × 3),
  Rückfallwert 120 s nur bei nicht lesbarer Einstellung.
- Der wichtigste Fallstrick beim Ändern: Feldnamen des JSON sind Vertrag
  zwischen Service, Hülle, Skript und Tests – und die Projektion **muss**
  Punkte hinter der Kamera abfangen.

## Inhalt

1. [Zweck und Abgrenzung](#1-zweck-und-abgrenzung)
2. [Begriffe und Code-Bezeichner](#2-begriffe-und-code-bezeichner)
3. [Code-Landkarte](#3-code-landkarte)
4. [Route, Aufrufkette und Antwort-Header](#4-route-aufrufkette-und-antwort-header)
5. [Datenvertrag (JSON)](#5-datenvertrag-json)
6. [Knotenmodell und Zustandssemantik](#6-knotenmodell-und-zustandssemantik)
7. [Kanten und Transportwege](#7-kanten-und-transportwege)
8. [Anordnung (Layout) und Projektion](#8-anordnung-layout-und-projektion)
9. [Darstellungsschichten und Animationen](#9-darstellungsschichten-und-animationen)
10. [Interaktion](#10-interaktion)
11. [Aktualisierung, Differenzbildung und Protokoll](#11-aktualisierung-differenzbildung-und-protokoll)
12. [Hülle, CSP und Barrierefreiheit](#12-hülle-csp-und-barrierefreiheit)
13. [Datenquellen und Nebenwirkungen](#13-datenquellen-und-nebenwirkungen)
14. [Invarianten (nicht brechen)](#14-invarianten-nicht-brechen)
15. [Tests](#15-tests)
16. [Bekannte Eigenheiten und Fallstricke](#16-bekannte-eigenheiten-und-fallstricke)
17. [Änderungsrezepte](#17-änderungsrezepte)
18. [Fehlersuche](#18-fehlersuche)
19. [Abbildungen](#19-abbildungen)

---

## 1. Zweck und Abgrenzung

| Frage | Antwort |
|---|---|
| Für wen? | Betrieb/Leitwarte (zweiter Bildschirm, Wandmonitor) – nicht für Konfiguration. |
| Was zeigt sie? | Alle Bausteine des Nachrichtenflusses als zusammenhängendes Netz mit Zustand, Zählern und Störungen. |
| Woher kommen die Daten? | `OrvantaFlowService::evaluate()` – identisch zum Kartendashboard. |
| Was kann sie? | Ansehen, filtern, zoomen, Knoten wählen, zur Behebung verlinken. |
| Was kann sie nicht? | Nichts konfigurieren, nichts schreiben, nichts prüfen (kein „Quellen prüfen“). |

Die Ansicht ist **lesend** und **zustandslos** auf dem Server: Sie schreibt
keine Daten, sondern liest lediglich `overview(false)` (dieselbe Erhebung wie
das Dashboard, jedoch ohne Verlauf) und bettet sie als JSON in die Seite ein.

---

## 2. Begriffe und Code-Bezeichner

| Begriff | Bedeutung im Code |
|---|---|
| Baustein / Knoten | Eintrag in `flow['nodes']`; `key` ist stabil und dient als Auswahl- und Diff-Schlüssel. |
| Kante | Eintrag in `flow['edges']` mit `from`, `to`, `state`, `label`. |
| Art (`kind`) | `source`, `proxy`, `host`, `users`, `ai`, `cache`, `tier` – bestimmt Farbe, Symbol, Radius und Legendenfilter. |
| Zustand | `ok`, `warn`, `error`, `off` – bestimmt Farbe, Puls, Ausrufezeichen und Dämpfung. |
| Gesamtstatus | `flow['overall']` – schlechtester Knotenzustand, mit Meldung und Zählern. |
| Störung | Eintrag in `flow['incidents']` – jeder Knoten mit Zustand `error`. |
| Spur (`lane`) | Fachliche Gruppe im JSON (`proxy` = „Proxy-Pfad (IMAP/SMTP)“, `exchange` = „Exchange-Pfad (EWS)“). |
| Zähler | `counters` am Knoten (`current`, `peak`) – Pillen am Knoten-Badge. |
| Wolke | `cloud` am Knoten – Top-Nutzer/Postfächer/Anfragen, im Detailfeld als Balken. |
| Gedämpft (`muted`) | Baustein wird ausgegraut, weil eine Ursache außerhalb seiner selbst vorliegt. |
| Zeichenzustand | `edge.drawState` – im Skript aus den Endpunktzuständen abgeleitet (nicht identisch mit `edge.state`). |
| Projektion | Umrechnung der 3D-Weltkoordinaten in Bildschirmkoordinaten (`project()`). |

---

## 3. Code-Landkarte

| Datei | Rolle |
|---|---|
| `public/index.php` (Abschnitt `OrvantaFlowController`) | Routenregistrierung in der Admin-Gruppe. |
| `app/Controllers/Admin/OrvantaFlowController.php` | `topology()` (Hülle rendern), `refreshInterval()`, `BASE`. |
| `views/admin/orvanta-flow-topology.php` | HTML-Hülle, Erstzustand als JSON, `<noscript>`-Liste. |
| `views/layouts/editor.php` | Vollbild-Layout ohne Seitenmenü (Theme-CSS mit Nonce, `extraStyles`, `pageScript`). |
| `public/assets/js/admin-orvanta-flow-topology.js` | Der gesamte Renderer: Modell, Anordnung, Projektion, Zeichnen, Eingabe, Aktualisierung. |
| `public/assets/css/orvanta-flow-topology.css` | Eigene Farbwelt, Layout der Ebenen, Responsive, `prefers-reduced-motion`, `print`. |
| `app/Services/Orvanta/OrvantaFlowService.php` | `evaluate()` und alle Knoten-/Kantenbauer – Quelle der Zustände und Texte. |
| `app/Services/Orvanta/OrvantaConfigService.php` | `pollInterval()` (Einstellung `poll_interval`). |
| `app/Services/Orvanta/OrvantaPresenceService.php` | Präsenzfenster für die Nutzerzähler. |
| `app/Repositories/OrvantaFlowRepository.php` | Persistenz von Aktivität und Proben (nur indirekt über den Service genutzt). |
| `app/Support/Html.php` | `e()`, `json()` (Escaping des eingebetteten JSON), `url()`. |
| `tests/Unit/OrvantaFlowTest.php` | Abschnitt „Topologie-Ansicht“ – Hülle, Vertrag, Verdrahtung. |

Es gibt **keine** zusätzlichen Dateien (kein Template-Fragment, kein separates
Modul): Hülle, Stil und Skript sind je eine Datei.

---

## 4. Route, Aufrufkette und Antwort-Header

```
GET /admin/office/orvanta/nachrichtenfluss/topologie
  → Router (Admin-Gruppe: Auth + Admin)
  → OrvantaFlowController::topology()
      → OrvantaFlowService::overview(false)      // Erhebung ohne Verlauf
      → OrvantaConfigService::pollInterval()
      → AdminController::adminView('admin.orvanta-flow-topology', …, 'layouts.editor')
      → Cache-Control: no-store
  → views/layouts/editor.php → views/admin/orvanta-flow-topology.php
  → pageScript: admin-orvanta-flow-topology.js, extraStyles: orvanta-flow-topology.css
```

Nachbar-Routen derselben Gruppe:

| Route | Zweck |
|---|---|
| `GET …/nachrichtenfluss` | Kartendashboard (`index()`). |
| `GET …/nachrichtenfluss/daten` | JSON-Antwort (`data()`, `Cache-Control: no-store`). |
| `GET …/nachrichtenfluss/topologie` | diese Ansicht. |
| `POST …/nachrichtenfluss/quellen/pruefen` | Quellen prüfen (CSRF-geschützt, höchstens `MAX_CHECK_SOURCES` = 16). |

**Übergebene Variablen** (`topology()`):

| Variable | Inhalt / Wirkung |
|---|---|
| `pageTitle` | `Nachrichtenfluss` |
| `titleSuffix` | `Orvanta` |
| `pageScript` | `admin-orvanta-flow-topology.js` |
| `extraStyles` | `['orvanta-flow-topology.css']` |
| `flow` | `overview(false)` – **ohne** `history`, ohne `chart` an den Knoten |
| `base` | `OrvantaFlowController::BASE` = `/admin/office/orvanta/nachrichtenfluss` |
| `orvantaEnabled` | Banner „Orvanta ist deaktiviert“ (Fehlerstil) |
| `orvantaDemo` | Banner „Demo-Modus“ (Warnstil) |
| `refreshInterval` | Sekunden bis zur nächsten Abfrage (siehe unten) |

**Aktualisierungsintervall** (`OrvantaFlowController::refreshInterval()`):

```text
refreshInterval = max(30, min(600, pollInterval() * 3))
Rückfallwert     = 120 s, wenn das Lesen der Einstellung fehlschlägt (Throwable)
```

`pollInterval()` liefert `max(15, min(900, Einstellung 'poll_interval'))` mit
Standard `60`. Daraus folgt: **Standardabfrage alle 180 s**, Untergrenze 30 s
(`poll_interval` = 15 → 45 s, aber nie unter 30), Obergrenze 600 s
(`poll_interval` = 900 → 600). Der Wert 120 s ist nur der Fehlerfall.

Das Skript begrenzt den Wert zusätzlich auf **5…600 s** und ersetzt ungültige
Werte durch 120 s (`data-refresh-interval` wird als Sekunden gelesen und in
Millisekunden umgerechnet).

---

## 5. Datenvertrag (JSON)

Die Ansicht nutzt **dieselbe Antwort** wie das Dashboard. Feldnamen sind
Vertrag zwischen `OrvantaFlowService`, Hülle, Skript und Tests.

### 5.1 Wurzelobjekt

| Feld | Typ | Bedeutung |
|---|---|---|
| `generated_at` | String | Erhebungszeitpunkt `d.m.Y H:i:s` (Anzeige in der Kopfzeile). |
| `generated_iso` | String | Zeitpunkt ISO-8601 (für Vergleiche/Diff). |
| `overall` | Objekt | `state`, `label`, `message`, `errors`, `warnings`. |
| `kpis` | Liste | Kennzahlen des Dashboards (`key`, `label`, `value`, `hint`, `state`, `anchor`). Die Topologie nutzt sie nicht. |
| `lanes` | Liste | `key`, `title`, `nodes`, `state`, `state_label` – fachliche Spuren. |
| `nodes` | Objekt | Bausteine **nach Schlüssel** (`nodes.users`, `nodes['source-3']`) – siehe 5.2. |
| `edges` | Liste | Kanten (siehe 5.3). |
| `incidents` | Liste | `key`, `title`, `message`, `url` (leer, wenn der Knoten keinen relativen Link hat) – nur Knoten mit Zustand `error`. |
| `tiers` | Liste | Schlüssel aller Speicher-Tiers in Anzeigereihenfolge. |
| `history` | Liste | Verlauf für die Dashboard-Grafiken – **wird in der Topologie nicht eingebettet**. |
| `ai` | Objekt | KI-Kennzahlen (`requests`, `active_users`, `day_users`, …). |
| `presence` | Objekt | `current`, `min`, `max`, `avg`, `samples`, `window`, `proxy`, `exchange`, `sources{id: {current, peak}}`. |

### 5.2 Knoten

Alle Felder werden von `OrvantaFlowService::node()` mit Vorgabewerten
angelegt, damit das Skript nicht auf Existenz prüfen muss:

| Feld | Typ | Bedeutung |
|---|---|---|
| `key` | String | Stabiler Schlüssel, z. B. `proxy`, `users`, `source-3`, `host-1`, `tier-local`. |
| `kind` | String | Art (siehe 6.1). |
| `title` | String | Anzeigename. |
| `subtitle` | String | Zusatzzeile (meist Code/Modus, z. B. „Hot-Tier“). |
| `state` | String | `ok`/`warn`/`error`/`off`. |
| `state_label` | String | Deutsche Bezeichnung aus `STATE_LABELS`. |
| `alert` | Bool | Ausrufezeichen-Markierung; der Service setzt sie aus `state === 'error'`. Die Kartendarstellung wertet sie aus, die Topologie **nicht** – dort folgt das „!“-Abzeichen dem Zustand. |
| `muted` | Bool | Gedämpft (ausgegraut). |
| `muted_reason` | String | Ursache der Dämpfung. |
| `muted_label` | String | Fertiger Text („Werte ausgegraut (…)“ / „nicht erreichbar“), in `node()` erzeugt. |
| `message` | String | Fachliche Meldung (Tooltip, Detailtafel, Protokoll). |
| `facts` | Liste | `label`/`value`/`state` – Zeilen der Detailtafel. |
| `cloud` | Liste | `label`/`value`/`share` – Balkenwerte. |
| `cloud_title`, `cloud_empty`, `cloud_more`, `cloud_muted` | String | Überschrift und Hinweise für das Detailfeld. |
| `link` | Objekt/null | `{url, label}` – Ziel und Beschriftung für „Verwalten“ (nur relative Pfade werden verlinkt). |
| `primary` | Bool | Hauptknoten der Art (wird mit Punkt markiert, z. B. lokaler Speicher). |
| `transport` | String | `proxy` oder `exchange` (Quellen/Hosts). |
| `chart` | Liste | Verlaufsgrafik – **wird vor dem Einbetten entfernt**. |
| `members` | Liste | Mitglieder (z. B. Postfach-Endungen) mit Zustand. |
| `counters` | Objekt | `{current: {value, label}, peak: {value, label}}` – Pillen am Badge. |

Knotenschlüssel, die der Service erzeugt: `proxy`, `source-<id>`, `host-<id>`,
`users`, `ai`, `cache`, `tier-local`, `tier-<id>`, `tier-snapshot`.

### 5.3 Kanten

`OrvantaFlowService::edge()` legt fest: `state` ist `error`, wenn der
Quellzustand `error` ist – **jeder andere Zustand wird zu `ok`** – und `label`
ist immer leer. Weitere Felder gibt es nicht; alles Weitere leitet das Skript
ab.

### 5.4 Störungen, Gesamtstatus, Spuren

- `incidents()` listet **jeden Knoten mit Zustand `error`** (Schlüssel, Titel,
  Meldung, URL).
- `overall()`: `error`, wenn irgendein Knoten `error` ist; sonst `warn`, wenn
  irgendein Knoten `warn` ist; sonst `ok`. Die Meldung lautet bei `error`
  „N Störung(en): Titel, …“, bei `warn` „N Einschränkung(en)“, sonst „Alle
  Bausteine sind in Ordnung.“; existieren Vorfälle, wird in **jedem** Zustand
  „ Offene Vorfälle: N.“ angehängt.
- `laneState()` liefert den schlechtesten Knotenzustand der Spur
  (`off` → `ok` → `warn` → `error`).

### 5.5 Was die Topologie davon nutzt

| Verwendet | Nicht verwendet (bleibt im Endpunkt) |
|---|---|
| `generated_at`, `overall`, `nodes`, `edges`, `incidents`, `tiers`, `presence`, `ai` | `kpis`, `history`, `lanes`, `generated_iso` (im Skript nicht ausgewertet), `chart` an Knoten |

Das ist Absicht: die Ansicht soll sich nicht mit dem Dashboard um Kennzahlen
streiten, nutzt aber dessen Zustandswahrheit.

---

## 6. Knotenmodell und Zustandssemantik

### 6.1 Arten (`kind`)

| `kind` | Radius (px) im Skript | Bedeutung |
|---|---|---|
| `source` | 15 | Identitätsquelle (LDAP/AD-Sync), je Transportweg. |
| `proxy` | 22 | IMAP-/SMTP-Proxy (`mail-proxy`). |
| `host` | 18 | Exchange-Host (EWS). |
| `users` | 27 | Orvanta-Nutzer (Zentrum des Netzes). |
| `ai` | 16 | KI-Endpunkt (LLMInt). |
| `cache` | 16 | Zwischenspeicher/Quota. |
| `tier` | 13 | Speicher-Tier (lokal, Snapshot, Cold). |

### 6.2 Die vier Zustände

| Zustand | Label (`STATE_LABELS`) | Farbe (CSS-Variable) | Puls |
|---|---|---|---|
| `ok` | In Ordnung | `--topo-ok` | ruhig |
| `warn` | Eingeschränkt | `--topo-warn` | langsam |
| `error` | Störung | `--topo-error` | schnell, mit Ringwellen und „!“ |
| `off` | Nicht aktiv | `--topo-off` | kein Puls |

### 6.3 Zustand je Baustein

| Baustein | Regel (Fundstelle) |
|---|---|
| Proxy | `off` ohne Konfiguration (keine Server), `error` wenn der Dienst nicht `ok` meldet, `warn` wenn `active_servers < servers`, sonst `ok` (`proxyState()`). Bei `error` wird die Meldung um „Der Transportweg ist unterbrochen: alle Identitätsquellen und deren Postfächer sind nicht erreichbar.“ ergänzt. |
| Identitätsquelle (Proxy-Pfad) | `off` wenn deaktiviert; `error` wenn der Proxy gestört ist oder der letzte Fehler nach dem letzten Erfolg liegt; `warn` wenn noch nie ein Erfolg verzeichnet wurde (Meldung unterscheidet, ob im Proxy ein Mailserver hinterlegt ist); sonst `ok`. |
| Identitätsquelle (Exchange-Pfad) | `off` wenn deaktiviert oder Exchange nicht konfiguriert; `error` wenn kein Exchange-Host erreichbar ist; sonst `ok`. Die Quelle selbst wird **nie** geprüft – ihr Zustand folgt den Hosts. |
| Exchange-Host | `online` → `ok`, `maintenance` → `warn`, `offline` → `error`, unbekannter Status → `warn`; `off`, wenn Exchange nicht konfiguriert ist. |
| Nutzer | `warn`, solange keine Messwerte vorliegen (`samples` = 0 und `current` = 0) – **nie** `error`. |
| KI | `off` wenn nicht freigegeben; `warn` wenn freigegeben, aber nicht vollständig konfiguriert, oder ohne Zugangsschlüssel und nicht aktiv; sonst `ok`. |
| Zwischenspeicher | `off` ohne feste Grenze (Quota ≤ 0, „Ohne feste Grenze“); `error` ab `CACHE_CRIT_PERCENT` = 90 %; `warn` ab `CACHE_WARN_PERCENT` = 75 %; sonst `ok`. |
| Lokaler Speicher (`tier-local`) | existiert, sobald `storage.available`; `warn` ohne Messwerte (`total_bytes` = 0) oder bei Speichermodus ≠ `normal`; `error` bei kritischer Füllung; `warn` bei degradierter Füllung; sonst `ok`. Immer `primary`. |
| Snapshot-Speicher (`tier-snapshot`) | nur vorhanden, wenn `storage.available` **und** `snapshot.enabled`; `off` bei deaktiviert, `error` bei `offline`/`invalid`, sonst wie Cold-Tier. |
| Cold-Tier (`tier-<id>`) | `online` → `ok`, `unknown` → `warn`, `disabled` → `off`, sonst (`offline`/`invalid`) → `error`; kritische Füllung stuft auf `error`, degradierte auf `warn` hoch. |

### 6.4 Dämpfen (`muted`)

Gedämpfte Knoten werden mit halber Deckkraft gezeichnet und tragen im Detail
den Grund. Dämpfung wird **nicht vererbt**: eine gestörte Quelle dämpft den
Proxy nicht.

| Baustein | Gedämpft wenn | Grund |
|---|---|---|
| Quelle (Proxy-Pfad) | Proxy gestört | „Proxy nicht erreichbar“ |
| Quelle (Exchange-Pfad) | kein Exchange-Host erreichbar | Exchange-Ausfall |
| Exchange-Host | Exchange nicht konfiguriert | „Proxy-Betrieb“ |
| Nutzer | Proxy gestört **und** `presence.exchange` = 0 | „Nur Proxy-Nutzer betroffen“ |
| Cold-Tier / Snapshot | `offline`, `invalid` oder `disabled` | Tier nicht verfügbar |
| Proxy, KI, Zwischenspeicher | nie | – |

Bei einem Proxy-Ausfall werden zusätzlich die Wolkenwerte der betroffenen
Quellen gedämpft (`cloud_muted`).

### 6.5 Zähler

| Knoten | `current` (rechts, gefüllt) | `peak` (links, umrandet) |
|---|---|---|
| Nutzer | aktive Nutzer (`presence.current`) | Maximum der letzten 24 h (`presence.max`) |
| Quelle | `presence.sources[id].current` | `presence.sources[id].peak` (0 ohne Daten) |
| Exchange-Host | Sitzungen | – |
| KI | aktive Sitzungen | Sitzungen der letzten 24 h |
| Proxy, Zwischenspeicher, Tiers | – | – |

Die Positionen sind im Docblock von `OrvantaFlowService::counter()` festgeschrieben:
`current` oben **rechts**, `peak` oben **links** am Badge. Im JSON ist
`counters` ein Objekt mit genau diesen beiden Feldern (je `{value, label}`);
das Skript zeichnet sie als Pillen – gefüllt rechts (`current`), umrandet
links (`peak`) – aber nur, wenn Beschriftungen aktiv sind und der Knoten groß
genug ist (`s ≥ 0,5`) oder gezeigt/gewählt wurde. Ohne `current` wird kein
Zähler gezeichnet.

### 6.6 Zahlenkonsistenz

Die Summe der Quellenzähler entspricht **exakt** dem Nutzerknoten (Tests
prüfen das). Grundlage ist das Präsenzfenster aus `OrvantaPresenceService`
(ACTIVE_WINDOW = 300 s, SAMPLE_INTERVAL = 300 s, HISTORY_DAYS = 400,
ACTIVITY_TTL = 86400). Quellen ohne Proben melden 0 – nicht „unbekannt“.

---

## 7. Kanten und Transportwege

### 7.1 Kantenliste

| Kante | Bedingung |
|---|---|
| `source-<id>` → `proxy` | Quelle im Proxy-Pfad. |
| `source-<id>` → `host-<id>` | Quelle im Exchange-Pfad (je Host eine Kante). |
| `source-<id>` → `users` | Quelle im Exchange-Pfad **ohne** Hosts. |
| `host-<id>` → `users` | Exchange-Host. |
| `proxy` → `users` | – |
| `ai` → `users` | – |
| `cache` → `users` | – |
| `tier-local` → `cache` | nur wenn `storage.available`. |
| `tier-<id>` → `tier-local` | Cold-Tier; **ohne** lokalen Speicher direkt → `cache`. |
| `tier-snapshot` → `tier-local` | – |

### 7.2 Transportweg-Erkennung

`transportFor(hosts, exchangeDomains, proxied)` entscheidet, ob eine
Identitätsquelle über den Proxy oder über Exchange läuft:

1. Ist die Quelle „proxied“ (es existiert eine Zeile in
   `mail_proxy_servers`) **oder** sind keine Exchange-Domänen konfiguriert →
   immer `proxy`.
2. Sonst `exchange`, wenn sich die Host-Domänen mit den Exchange-Domänen
   schneiden; andernfalls `proxy`.

`hostDomains()` normalisiert dafür die Hostnamen: Klammern und Punkte am Rand
entfernt, IP-Adressen übersprungen, alles ab dem **ersten** Punkt genommen,
klein geschrieben. Praktische Folge:

- `dc01.khwf.de` und `exchange01.khwf.de` treffen sich über `khwf.de` →
  Exchange-Pfad.
- Kurznamen (`dc01`), IP-Adressen und fremde Domänen bleiben am Proxy – auch
  dann, wenn gar kein Mailserver konfiguriert ist.

Quellen ohne Proxy-Konfiguration hängen deshalb **vor** den Exchange-Hosts,
nicht hinter dem Proxy.

### 7.3 Zeichenzustand und Aktivität (im Skript)

`edge.state` aus dem JSON kennt nur `ok`/`error`. Das Skript leitet für das
Zeichnen `drawState` ab:

```text
Start:  drawState = edge.state              // nur 'ok' oder 'error' aus dem JSON
off:    wenn einer der Endpunkte off ist    // hat Vorrang
error:  wenn einer der Endpunkte error ist  // auch bei Kantenzustand ok
warn:   wenn einer der Endpunkte warn ist und die Kante selbst nicht error ist
sonst:  ok
```

Die Aktivität einer Kante ist `min(from.activity, max(1, to.activity))`, wobei
`activityOf()` je Knoten zählt: Wolkenwerte summiert, Nutzer mindestens
`presence.current`, Proxy mindestens `presence.proxy` und immer ≥ 1, KI
`round(ai.requests / 30)`, Tiers fest 2, `off`-Knoten 0 – mit Untergrenze 1
(bei `error` 2).

### 7.4 Synthetische Kanten

Fehlt im JSON eine abgehende Kante für einen Knoten der Art `tier`
(z. B. ältere Antworten), legt das Skript beim Einlesen eine **gestrichelte
Hilfskante** zum Zwischenspeicher an (`synthetic`; nur wenn ein
Zwischenspeicher-Knoten existiert). Sie ist reine Darstellung: Sobald der
Service eine echte Kante liefert, ersetzt diese die Hilfskante. Gestrichelt
bedeutet also **nicht** „gestört“ – gestörte Kanten sind rot und tragen eine
Bruchmarke.

---

## 8. Anordnung (Layout) und Projektion

### 8.1 3D-Anordnung (`layout()`)

| Knoten | Weltkoordinate / Ring |
|---|---|
| `users` | (0, 0, 0) – Zentrum |
| `proxy` | (−230, 10, 40) |
| `ai` | (90, 215, −140) |
| `cache` | (40, −215, 90) |
| `tier-local` | (40, −360, 90) |
| Proxy-Quellen | Ring um x = −450 in der y/z-Ebene, Radius `min(230, 70 + n·28)` |
| Hosts | Ring um x = 290 in der y/z-Ebene, Radius `min(220, 60 + n·26)` |
| Exchange-Quellen | Ring um x = 510 in der y/z-Ebene, Radius `min(230, 70 + n·28)` |
| Cold-Tiers | Ring um y = −480 (ohne lokalen Speicher: −360) in der x/z-Ebene, Radius `min(240, 60 + n·30)` |

Jeder Knoten erhält zusätzlich einen kleinen, deterministischen Versatz
(±20/±15/±20) aus `hashString(seed + key)`, damit Ringe nicht wie Perlenketten
wirken. Ein Ring mit genau einem Knoten hat Radius 0 (Knoten sitzt im
Zentrum des Rings).

### 8.2 2D-Anordnung

| Spalte/Zeile | Position |
|---|---|
| Quellen (Proxy-Pfad) | Spalte x = −520, Abstand 96 |
| Proxy | x = −260 |
| Nutzer | x = 0 |
| Hosts | Spalte x = 300, Abstand 100 |
| Quellen (Exchange-Pfad) | Spalte x = 560, Abstand 96 |
| KI | y = −220 |
| Zwischenspeicher | y = 220 |
| Lokaler Speicher | y = 380 |
| Cold-Tiers / Snapshot | Zeile y = 520 (ohne lokalen Speicher: 380), Abstand 150 |

Zwischen 3D und 2D wird über `view.mix` überblendet, sodass derselbe Knoten
weich an seinen neuen Platz wandert.

### 8.3 Determinismus

`hashString()` (FNV-1a) und `rng()` (xorshift) erzeugen reproduzierbare
Zufallswerte. Verwendet für:

- Sternenhimmel (fester Startwert 20240917),
- Wolken-/Ambientpunkte je Knoten,
- Biegung und Anhebung der Kantenbögen (`bend`, `lift`),
- Ringversatz.

Folge: Nach einem Neuladen sieht das Netz **gleich** aus; Screenshots bleiben
vergleichbar, und ein Screenshot-Test wäre stabil.

### 8.4 Projektion (`project()`)

```text
Gier-/Nickwinkel (yaw/pitch) drehen den Punkt
scale3 = FOCAL / max(40, FOCAL - z2)        // FOCAL = 1150
s      = max(0.0001, scale * zoom)
Ergebnis: x, y, s, depth, behind
```

**Kamera-Schutz:** `behind = z2 >= FOCAL − 40`, wirksam ab `mix > 0,5`.
Punkte hinter der Kamera würden einen negativen Maßstab ergeben; Canvas wirft
dann `IndexSizeError` und würde die Zeichenschleife beenden. Solche Punkte
werden deshalb übersprungen (Kanten, Sterne, Ambient), und die Knoten bleiben
sichtbar. Wer an `FOCAL`, Zoom oder der Perspektive dreht, muss diesen Schutz
erhalten.

### 8.5 Einpassen, Zoom, Grenzen

- Zoombereich der Eingabe: **0,2 … 4** (Rad/Pinch/Zoom-Schaltflächen).
- `fit()` projiziert alle **sichtbaren** Knoten mit Zoom 1 (ausgeblendete
  Arten werden übersprungen), polstert mit dem dreifachen Knotenradius
  (+30 px in y), setzt den Zoom auf
  `min((Breite − 260)/Netzbreite, (Höhe − 160)/Netzbreite)` und verschiebt die
  Ansicht so, dass der Mittelpunkt des Rahmens im Bildzentrum liegt.
  Zoombereich dabei **0,3 … 2,4**.
- `resetView()` stellt Gier −0,35, Nick 0,22, Zoom 1 her und passt nach 50 ms
  erneut ein.
- `focusNode()` erhöht den Zoom auf mindestens 1,3 (nur wenn er unter 1,2
  liegt), damit ein angesprungener Knoten wirklich groß ist.

---

## 9. Darstellungsschichten und Animationen

### 9.1 Reihenfolge je Bild

```text
drawBackground()  Sterne mit Parallaxe und Funkeln
drawAmbient()     Wolkenschalen und Dendriten je Knoten
drawEdges()       Bögen (mit Glow) und je Kante drawParticles()
drawNodes()       nach Tiefe sortiert: Aura, Ringe, Kern, Symbol, je Knoten drawCounters() und Beschriftung
drawEffects()     Zustandswechsel-Wellen
drawVignette()    Randabdunklung
```

Die Reihenfolge steht in `frame()`; ein Fehler in einem dieser Schritte wird
abgefangen, damit die Schleife weiterläuft.

### 9.2 Hintergrund und Ambiente

- 220 Sterne, Helligkeit und Parallaxe aus der Tiefe, leichtes Funkeln.
- Je Knoten 10 Ambientpunkte (Nutzerknoten 20) auf Kugelschalen im Abstand
  36–106; nur die größeren Punkte erhalten eine feine Dendritenlinie zum
  Knoten. Sie machen große Knoten „lebendig“, ohne Werte zu behaupten.
- Im reinen 2D-Modus (`mix < 0,05`) entfällt das Ambiente.

### 9.3 Kanten und Partikel

- Kanten sind quadratische Bézierbögen (`controlPoint()` für 3D mit `bend`
  und `lift`, `controlPoint2()` für 2D); die Breite wächst logarithmisch mit
  der Aktivität, gestrichelte Hilfskanten sind dünner (`0,9` statt `1,4`).
- Außerhalb von `off` folgt ein zweiter Durchgang mit vierfacher Breite und
  18 % Deckkraft als Leuchtspur.
- Gestörte Kanten: Strichmuster `[8,6]` mit wanderndem `lineDashOffset`, in der
  Mitte eine pulsierende rote Bruchmarke (X). Partikel **zerplatzen** dort:
  ab t > 0,3 blenden sie aus und werden bei t > 0,46 neu gestartet.
- Partikelzahl je Kante (`syncParticles()`): `ok` →
  `min(14, 1 + round(ln(Aktivität + 1)·2,4))`, `warn` →
  `min(6, 1 + round(ln(Aktivität + 1)))`, `error` → 4, `off` → 0.
- In `warn`-Zuständen laufen Partikel langsamer (Faktor 0,45) und sind
  kleiner (Faktor 0,8).
- Partikel werden nur gezeichnet, wenn die Schaltfläche „Partikel“ aktiv ist
  und die Kante nicht `off` ist; bei Bewegungsreduktion stehen sie still.
- Die Kantenaktivität wird **nicht** in Zahlen angezeigt; sie ist reine
  Intuition („wie viel fließt gerade“).

### 9.4 Knoten

- Sortierung nach Tiefe (`depth`), damit Überlappungen stimmen; Knoten mit
  Deckkraft 0 werden übersprungen.
- Aura als radialer Verlauf mit Pulsgeschwindigkeit je Zustand (Fehler schnell,
  `warn` mittel, `ok` langsam, `off` ruhig).
- Gestörte Knoten erhalten **zwei** expandierende Ringe und ein rotes
  „!“-Badge – die Ringe nur ohne Bewegungsreduktion.
- Kern mit Verlauf (gedämpft dunkler), Zustandsring plus innerer Ring in der
  Arteigenfarbe (gedämpft in der `off`-Farbe), Symbol je Art (`drawGlyph`),
  Punktmarkierung für `primary`.
- Zeiger/Auswahl: weißer Ring bei `r · 1,35`, bei Auswahl gestrichelt und
  wandernd.
- Beschriftung mit dunklem, abgerundetem Hintergrund, ab `s > 0,45` (oder bei
  Zeiger/Auswahl), auf 28 Zeichen gekürzt; das Zustandslabel zusätzlich nur ab
  `s > 0,9` oder bei Zeiger/Auswahl.
- Tiefenabdunklung: `depthFade = clamp(0,4 … 1, s)`, multipliziert auf die
  Deckkraft.

### 9.5 Zustandswechsel-Wellen

`diff()` löst beim Zustandswechsel eine Welle vom Knoten aus (drei Ringe,
1800 ms; bei `error` 2600 ms) und schreibt eine Protokollzeile. Die Kopfzeile
zählt dabei mit einer kurzen Hüpf-Animation hoch (`topo-bump`).

### 9.6 Konstanten des Skripts

| Konstante | Wert | Wirkung |
|---|---|---|
| `FOCAL` | 1150 | Brennweite der Perspektive. |
| `MAX_PARTICLES_PER_EDGE` | 14 | Obergrenze Partikel je Kante. |
| `AMBIENT_PER_NODE` | 10 (Nutzer 20) | Wolkendichte. |
| `STARS` | 220 | Sternanzahl. |
| `IDLE_BEFORE_ROTATE` | 4000 ms | Ruhezeit bis zur Auto-Drehung. |
| Auto-Drehung | +0,09 rad/s | nur 3D, nicht bei Bewegungsreduktion, nicht beim Ziehen. |
| Glättung | `1 − 0,001^dt` | Nachführen von Gier/Nick/Zoom/Verschiebung. |
| Überblendung 3D↔2D | `1 − 0,02^dt` | weicher Moduswechsel. |
| `dt`-Obergrenze | 0,1 s | verhindert Sprünge nach Tab-Pausen. |

### 9.7 Bewegungsreduktion

`prefers-reduced-motion: reduce` wird beim Start über `matchMedia` gelesen und
schaltet Partikel, Auto-Drehung und Glättung (sofortige Werte) ab; CSS
deaktiviert zusätzlich alle Animationen. Zustände bleiben über Farbe, Ring,
Ausrufezeichen und Text erkennbar. Ein Protokolleintrag weist darauf hin.

---

## 10. Interaktion

### 10.1 Zeiger

| Eingabe | Wirkung |
|---|---|
| Ziehen (links) | 3D: drehen; 2D: verschieben. |
| Ziehen mit Umschalt/mittlerer/rechter Taste | immer verschieben. |
| Zwei Zeiger (Pinch) | Zoom 0,2 … 4. |
| Mausrad | Zoom um den Zeiger (`passive: false`, damit kein Seiten-Scroll). |
| Klick | Knoten wählen (unterdrückt, wenn die Bewegung zuvor insgesamt mehr als 3 px betrug). |
| Doppelklick | Knoten zentrieren; ins Leere: einpassen. |
| Kontextmenü | unterdrückt. |
| Zeiger verlässt die Bühne | Tooltip und Hover zurücksetzen. |
| Fenster verliert den Fokus | Zeigerzustand zurücksetzen (kein „klebendes“ Ziehen). |

### 10.2 Tastatur (Fokus auf der Bühne)

| Taste | Wirkung |
|---|---|
| `←` `↑` `→` `↓` | 3D: Gier ±0,12 / Nick ±0,1 (Nick auf ±1,2 begrenzt); mit `Alt` oder in 2D: verschieben (15/40 px). |
| `+` / `=` | Zoom ×1,25 (höchstens 4). |
| `-` / `_` | Zoom ÷1,25 (mindestens 0,2). |
| `0` | Ansicht zurücksetzen. |
| `F` | Einpassen. |
| `R` | Jetzt aktualisieren. |
| `P` | Partikel an/aus. |
| `L` | Beschriftungen an/aus. |
| `Leertaste` | Auto-Drehung an/aus. |
| `2` / `3` | 2D- bzw. 3D-Modus. |
| `!` | „Nur Probleme“. |
| `N` / `Umschalt+N` | nächster / voriger Knoten in Problemreihenfolge. |
| `Enter` | gewählten Knoten zentrieren. |
| `Esc` | Auswahl aufheben und Tooltip schließen. |

### 10.3 Werkzeugleiste und Filter

Die Schaltflächen tragen `data-topo-action`; ein einziger Klick-Handler auf dem
Wurzelelement wertet sie über eine Aktionszuordnung aus:

`refresh`, `fullscreen`, `mode-3d`, `mode-2d`, `rotate`, `particles`, `labels`,
`focus-problems`, `zoom-in`, `zoom-out`, `fit`, `reset`, `close-panel`,
`toggle-log`.

Vollbild nutzt die Fullscreen-API auf dem Wurzelelement (kein Browser-Menü
nötig). Der Moduswechsel passt nach 450 ms erneut ein (bei Bewegungsreduktion
sofort), damit das 2D-Layout vollständig im Bild ist.

Die Legende enthält Kontrollkästchen mit `data-topo-kind-filter` je Art. Sie
schalten `view.hiddenKinds`; ausgeblendete Arten werden nicht gezeichnet, ihre
Kanten verschwinden mit, und eine Auswahl auf einem ausgeblendeten Knoten wird
aufgehoben.

### 10.4 Tooltip, Detailtafel, Protokoll, Störungsband

| Element | Inhalt |
|---|---|
| Tooltip | Art · Zustandslabel und Meldung (bzw. Ausgraugrund), am Zeiger positioniert. |
| Detailtafel (`data-topo-panel`) | Art, Titel („ · primär“), Zustandsabzeichen, Untertitel als Code, Meldung, Ausgrauhinweis, Fakten als `dt`/`dd` (Warn-/Fehlerklassen), Wolkenwerte (höchstens 12) mit relativen Balken, Mitglieder mit Abzeichen, benachbarte Knoten (Richtungspfeil, Kantenzustand, „unterbrochen“ bei Fehler) und „Verwalten“ – **nur** bei relativen Links. |
| Protokoll (`data-topo-log-list`) | Höchstens 60 Einträge, neueste zuerst, je Ebene eingefärbt (`--ok/--warn/--error/--off`); einklappbar. |
| Störungsband (`data-topo-incidents`) | Nur bei Störungen offen (`--open`, sonst `--none`); jeder Eintrag springt über `data-topo-focus` zum Knoten, „Beheben“ verlinkt nur relative URLs. |

Die Auswahl hebt den Knoten und seine Nachbarn hervor; alle anderen Knoten
werden auf 0,55 gedämpft, Kanten ohne Bezug auf 0,45. Im Modus
„Nur Probleme“ sinkt alles ohne Fehler auf 0,18, gestörte Kanten auf 0,3.

---

## 11. Aktualisierung, Differenzbildung und Protokoll

### 11.1 Erstzustand und Abfrage

1. Die Hülle bettet den Erstzustand als
   `<script type="application/json" data-flow-initial>` ein – erzeugt mit
   `Html::json()` (JSON_HEX_TAG/AMP/APOS/QUOT, Unicode unescaped). Damit kann
   kein `</script>` aus Daten entstehen, und die Ansicht ist sofort ohne
   Netzabruf vollständig.
2. Fehlt der Block oder ist er nicht lesbar, ruft das Skript `load(true)` auf.
3. Danach fragt `setInterval(load(false), refreshInterval·1000)` den
   JSON-Endpunkt ab (`fetch` mit `credentials: 'same-origin'` und
   `Accept: application/json`).

Vor dem Einbetten entfernt der Controller `history` und an jedem Knoten
`chart` – die Ansicht braucht beides nicht, und die Seite bleibt klein.

### 11.2 Ablauf einer Abfrage

```text
load(manual)
  ├─ Abbruch, wenn schon eine Abfrage läuft
  ├─ Abbruch, wenn der Tab verborgen ist (außer manuell)
  ├─ Schaltfläche „Aktualisieren“ bekommt is-busy
  ├─ fetch(…/daten)
  ├─ Fehler → Protokollwarnung, letzter Stand bleibt sichtbar
  └─ Erfolg → apply(data)
        ├─ diff(data)      Zustandswechsel, neue/entfernte Knoten, Wellen, Protokoll
        ├─ ingest(data)    Modell übernehmen (Positionen erhalten)
        ├─ setOverall()    Kopfzeile, Zähler mit Hüpf-Animation
        ├─ setIncidents()  Störungsband neu aufbauen
        ├─ Zeitstempel aktualisieren
        ├─ Auswahl neu auswerten (Zahlen können sich geändert haben)
        └─ previous = data
```

### 11.3 Differenzbildung (`diff()`)

| Fall | Wirkung |
|---|---|
| Neuer Knoten | Welle, Protokoll „Neuer Baustein: …“. |
| Zustandswechsel | Welle (2600 ms bei `error`, sonst 1800 ms), Protokoll „Titel: alt → neu – Meldung“. |
| Entfernter Knoten | Protokoll „Baustein entfernt: …“. |
| Geänderter Gesamtstatus | Protokoll „Gesamtstatus: …“. |
| Erster neu gestörter Knoten | automatisch gewählt und zentriert (Zoom ≥ 1,3) – **nur** wenn nichts gewählt ist und nicht gezogen wird. |

`previous` ist nach dem ersten Einlesen gesetzt, damit auch die erste
Aktualisierung Änderungen erkennt.

### 11.4 Positionen, Sichtbarkeit, Größe

- `ingest()` übernimmt neue Knoten in die bestehende Anordnung; bestehende
  Knoten **behalten ihre Position**. Neu angeordnet wird nur, wenn Knoten
  hinzukommen/entfallen oder sich der Transportweg einer Quelle ändert.
  Entfernte Knoten verschwinden, eine Auswahl darauf wird aufgehoben.
- Kanten werden neu aufgebaut, behalten aber ihren Partikelzustand über die
  Kennung.
- `visibilitychange`: im verborgenen Tab werden Intervall und Zeichenschleife
  gestoppt; beim Zurückkehren wird sofort geladen, neu geplant und wieder
  gezeichnet.
- `resize` (Fenster), `ResizeObserver` auf der Bühne und `fullscreenchange`
  (mit 50 ms Nachlauf) passen die Zeichenfläche an; die Gerätepixelrate wird
  auf höchstens 2 begrenzt.
- Zeichenfehler werden je Bild abgefangen: einmalige Konsolenmeldung, dann
  Zurücksetzen von Strichmuster und Deckkraft. Ein Fehler darf die Schleife
  nicht beenden.

---

## 12. Hülle, CSP und Barrierefreiheit

- Wurzelelement `.topo` (fest positioniert, eigenes Raster) mit den
  Datenattributen `data-flow-topology`, `data-refresh-url`,
  `data-refresh-interval`, `data-dashboard-url`, `data-overall-state`.
- Die Ansicht bringt ihre **eigene Farbwelt** mit (CSS-Variablen `--topo-*`),
  unabhängig vom Intranet-Theme; übernommen werden nur Schrift, Fokus und
  Hilfsklassen.
- **Keine Inline-Stile und kein Inline-Skript** in der Hülle (CSP). Das Skript
  setzt ausschließlich `style.left`/`style.top` (Tooltip) und
  `style.width` (Wolkenbalken) über das CSSOM – das ist von `style-src` nicht
  betroffen.
- **Kein `innerHTML`**, kein `document.write`, kein `eval`: alle Elemente
  entstehen über `createElement`/`textContent`. Die Tests prüfen das.
- Links aus dem JSON werden nur übernommen, wenn sie mit `/` beginnen, dem
  ein Zeichen folgt, das weder `/` noch `\` ist (`/^\/[^/\\]/`) – Schutz vor
  fremden Zielen; angewandt auf den „Verwalten“-Link und die „Beheben“-Links.
- Barrierefreiheit: Bühne mit `tabindex="0"`, `role="application"` und
  `aria-label` (Bedienhinweis in Worten); Werkzeugleiste als `role="toolbar"`
  mit `aria-label`; Gesamtstatus als `role="status"` mit
  `aria-live="polite"`; das Protokoll ebenfalls über `aria-live="polite"`;
  Werkzeugschaltflächen mit `aria-pressed`; Legendenfelder als
  Kontrollkästchen; `<noscript>` listet **alle** Knoten mit Zustand und
  **alle** Kanten mit Zustand als Text.
- Responsive: unter 1100 px bricht die Kopfzeile um, unter 760 px werden
  Legende und Hinweis ausgeblendet und Protokoll/Detailtafel verkleinert.
- `print` erzwingt eine statische, helle Darstellung ohne Bedienelemente.
- Das Skript ist **ES5** (IIFE, `var`), wird mit `defer` geladen und ist ohne
  Modul-/Bundlersystem lauffähig – passend zur Zero-Dependency-Regel.

---

## 13. Datenquellen und Nebenwirkungen

- Der Controller erhebt **einmal** je Seitenaufruf (`overview(false)`) und
  liefert denselben Stand, den das Dashboard beim Laden zeigen würde.
- Die Ansicht schreibt nichts. Schreibende Aktionen des Moduls (Quellen
  prüfen, Einstellungen) liegen auf anderen Routen.
- Präsenzproben und Aktivitätszählung entstehen durch die normale Nutzung
  (`OrvantaPresenceService`, `OrvantaFlowRepository`), nicht durch diese
  Ansicht. Die Zähler ändern sich daher nur im Takt der Proben (300 s).
- `Cache-Control: no-store` verhindert, dass Proxy oder Browser einen alten
  Zustand zeigen.
- Kleine Nebenwirkung der gemeinsamen Hüllenfunktion: `adminView()` ermittelt
  auch hier die Zahl offener Vorfälle (`openIncidents`), obwohl die
  Topologie-Hülle sie nicht anzeigt.

---

## 14. Invarianten (nicht brechen)

1. **`evaluate()` bleibt rein** – keine Datenbank-, Netz- oder Dateizugriffe;
   Tests rufen es direkt mit vorbereiteten Eingaben auf.
2. **Feldnamen des JSON sind Vertrag** (`nodes`, `edges`, `incidents`,
   `overall`, `counters`, `cloud`, `facts`, `muted`, `state`, …). Umbenennen
   bricht Hülle, Skript und Tests gleichzeitig.
3. **Kein Verlauf in der eingebetteten Antwort** (`history` entfernt,
   `chart` je Knoten entfernt).
4. **Keine Inline-Stile, kein Inline-Skript, kein `innerHTML`** in Hülle und
   Skript.
5. **Positionstreue über Aktualisierungen** – nur neue/entfernte Knoten oder
   ein Transportwechsel ordnen neu an.
6. **Zählerkonsistenz**: Summe der Quellen = Nutzerknoten.
7. **Projektion fängt Punkte hinter der Kamera ab** (sonst negative Radien →
   Zeichenabbruch).
8. **Zeichenfehler dürfen die Schleife nicht beenden** (try/catch je Bild).
9. **Zustände und Bezeichnungen bleiben deckungsgleich mit dem Dashboard**
   (`STATE_LABELS`, Farbwelt) – die Topologie ist eine andere Sicht, keine
   andere Wahrheit.
10. **Marker im HTML bleiben erhalten**: `data-topo-canvas`, `data-topo-panel`,
    `data-topo-log-list`, `data-topo-incident-list`, `data-topo-kind-filter`,
    `data-refresh-url`, `data-refresh-interval`, `data-overall-state`, die
    zwölf in den Tests geprüften Werkzeugaktionen (im HTML zusätzlich
    `close-panel` und `toggle-log`) und `<noscript>` – die Tests prüfen genau
    diese Verdrahtung.
11. **Keine neuen Abhängigkeiten** (keine Bibliothek, kein CDN, kein Bundler).

---

## 15. Tests

`tests/Unit/OrvantaFlowTest.php`, Abschnitt „Topologie-Ansicht“ (Helfer
`flowTopologyRender()`, der die View mit genau den Controller-Variablen
rendert). Geprüft werden unter anderem:

- Hülle und Vertrag: `data-flow-topology`, `data-refresh-url`
  (`…/nachrichtenfluss/daten`), `data-refresh-interval="120"`,
  `data-overall-state="ok"`, Vorhandensein von
  `<script type="application/json" data-flow-initial>`.
- **Kein** ausführbares Inline-Skript, **kein** `style="`, kein roher
  PHP-`Array`-Ausdruck im HTML.
- Alle Bedienmarker: Bühne, Detailtafel, Protokoll, Störungsliste,
  Artenfilter, zwölf Werkzeugaktionen (`mode-3d`, `mode-2d`, `rotate`,
  `particles`, `labels`, `focus-problems`, `zoom-in`, `zoom-out`, `fit`,
  `reset`, `refresh`, `fullscreen`), Rücksprung-Link.
- JSON-Inhalt: gültig, ohne `history` und ohne `chart`, `<` korrekt escaped.
- Störungsband: `topo-incidents--open`, `data-topo-focus="proxy"`, relativer
  „Beheben“-Link (`/admin/office/mail-proxy`), `topo-pulse--error`.
- Banner bei deaktiviertem Orvanta (Fehler) und im Demo-Modus (Warnung).
- Verdrahtung: Route, Layout, Skript, `overview(false)`, Dashboard-Schaltfläche
  mit `target="_blank" rel="noopener"`.
- Skripteigenschaften: `requestAnimationFrame`, `document.hidden`,
  `prefers-reduced-motion`, Pointer-Events, `wheel`, `keydown`,
  `quadraticCurveTo`, `case 'kind'` für alle sieben Arten – und **kein**
  `innerHTML`, `eval`, `document.write`.
- Gestaltung: Selektoren inklusive `prefers-reduced-motion` und `print`,
  ausgeglichene Klammern.
- Speicherhierarchie: `tier-local` als Wurzel, `tier-snapshot` als Kind,
  Cold-Tiers am lokalen Speicher **oder** direkt am Zwischenspeicher,
  lokaler Speicher ohne Ziele, Warnung/Fehler bei Füllständen, gedämpfter
  Snapshot mit Störungseintrag.

Ausführen:

```bash
php tests/run.php
```

---

## 16. Bekannte Eigenheiten und Fallstricke

1. **Nicht gelesene Attribute:** `data-dashboard-url` und `data-topo-head`
   wertet das Skript nicht aus. Der Rücksprung ist ein gewöhnlicher Link in
   der Hülle; die Attribute bleiben als Markierung im Markup.
2. **Intervall zweistufig begrenzt:** serverseitig 30…600 s, im Skript 5…600 s
   mit Rückfall 120 s. Ein im HTML sichtbarer Wert ist also nicht
   zwangsläufig der wirksame.
3. **Standardintervall 180 s**, nicht 120 s: 120 s ist nur der Fehlerfall bei
   nicht lesbarer Einstellung.
4. **`F` passt ein, nicht Vollbild** – Vollbild liegt ausschließlich auf der
   Schaltfläche (Fullscreen-API).
5. **`edge.state` ≠ Zeichenzustand:** Der Service kennt nur `ok`/`error`;
   `warn` und `off` an Kanten entstehen erst im Skript aus den Endpunkten.
6. **Gestrichelte Kanten** bedeuten „vom Skript ergänzt“, nicht „gestört“ –
   gestörte Kanten sind rot und haben eine Bruchmarke.
7. **Zähler ohne `current`** werden nicht gezeichnet (Proxy, Zwischenspeicher,
   Tiers haben nur Beschriftungen).
8. **`tier-local` fehlt**, wenn der Speicherdienst nicht antwortet – dann
   hängen die Cold-Tiers direkt am Zwischenspeicher. Wer die Ansicht als
   Speicher-Übersicht nutzt, muss das wissen.
9. **`tier-snapshot` fehlt**, wenn er nicht aktiviert ist; „nicht konfiguriert“
   und „nicht vorhanden“ sehen in der Ansicht ähnlich aus, sind aber
   verschieden (einmal `off`-Knoten, einmal gar kein Knoten).
10. **Dämpfung wirkt doppelt**: `muted` (Alpha) und `cloud_muted` (Wolkenwerte)
    sind getrennte Felder.
11. **Kein „Quellen prüfen“** in dieser Ansicht – Prüfungen laufen über das
    Dashboard (`POST …/quellen/pruefen`, höchstens 16 Quellen je Lauf).
12. **Zahlen ändern sich nur im Probentakt** (300 s); ein „hängender“ Zähler
    ist meist kein Fehler.
13. **Deterministische Optik**: Bögen und Wolken sind nicht zufällig pro
    Aufruf, sondern aus Schlüsseln berechnet. „Zufällige“ Abweichungen deuten
    auf geänderte Knotenschlüssel hin.
14. **Skript ist ES5 ohne Module**: Neue Spracheigenschaften (z. B. Klassen,
    `let`/`const`, optionales Chaining) würden den Stil der Datei brechen.

---

## 17. Änderungsrezepte

**Neue Knotenart**

1. `kind` im Service festlegen und im Knoten setzen.
2. Im Skript ergänzen: Farbe (`KIND_COLORS`), Label (`KIND_LABELS`), Radius
   (`KIND_RADIUS`), Symbol in `drawGlyph()`.
3. Anordnung: Position bzw. Ring in `layout()` (3D und 2D) ergänzen.
4. Legende in `views/admin/orvanta-flow-topology.php` um ein
   `data-topo-kind-filter`-Feld erweitern.
5. Tests: `case 'kind'`-Prüfung und Legendenmarker ergänzen.

**Neuer Knoten (ohne neue Art)**

1. Knoten im Service bauen (`node()`, Zustandslogik, Zähler, Fakten).
2. In `evaluate()` in `nodes` und in die passende Spur aufnehmen und Kanten
   ergänzen.
3. Anordnung im Skript: in den richtigen Ring/Spalte einsortieren (läuft über
   `kind`/`transport` meist automatisch).
4. Test für Zustand und Zähler ergänzen.

**Neue Kante**

`self::edge()` in `evaluate()` ergänzen; das Skript zeichnet sie automatisch
und leitet den Zeichenzustand ab. Bei Speicherkanten beachten, dass das Skript
nur dann eine gestrichelte Hilfskante ergänzt, wenn **keine** Kante existiert.

**Neue Kennzahl an einem Knoten**

`facts` (Detailtafel) oder `counters` (Pillen) im Knotenbauer ergänzen. Für
Zähler `userCounters()` bzw. `counter()` verwenden; das Skript braucht keine
Änderung. Achtung: `current` = rechte, gefüllte Pille, `peak` = linke,
umrandete Pille.

**Neues Werkzeug**

1. Schaltfläche mit `data-topo-action="<name>"` in der Hülle.
2. Eintrag in der Aktionszuordnung des Skripts; bei Umschaltern `setPressed()`.
3. Test: Werkzeugmarker in der Hüllenprüfung ergänzen.

**Neue Detailfelder**

Feld am Knoten ergänzen und im Panel-Zweig des Skripts ausgeben; das Panel
baut Zeilen zur Laufzeit, es gibt keine Vorlage.

**Anderes Abfrageintervall**

Einstellung `poll_interval` (15…900 s) im `OrvantaConfigService` nutzen; die
Topologie rechnet ×3 und begrenzt auf 30…600 s. Keine Änderung an der Hülle
nötig.

---

## 18. Fehlersuche

| Symptom | Wahrscheinliche Ursache / Prüfung |
|---|---|
| Leere Bühne, Konsole zeigt „Zeichenfehler“ | Canvas-Kontext fehlt (sehr alte Browser) oder ein Zeichenfehler – die Meldung nennt die Stelle; die Schleife läuft weiter. |
| Leere Bühne, sonst nichts | `data-flow-initial` nicht lesbar → das Skript lädt selbst nach; Netzwerkantwort von `…/nachrichtenfluss/daten` prüfen. |
| Knoten fehlen | Antwort prüfen: fehlende Migrationen (Aktivität/Proben) oder abgeschaltete Dienste lassen Bereiche leer, ohne Fehler zu melden. |
| Kanten gestrichelt | Kein passender Kanteneintrag im JSON (Hilfskante). |
| Alles grau | „Nur Probleme“ aktiv oder Proxy-Ausfall (Dämpfung) – Zustandslabels prüfen. |
| Kein Refresh | Tab verborgen (`document.hidden`), Intervall 5…600 s, `no-store` erzwungen; manuelle Schaltfläche testen. |
| Positionen springen | Knoten hinzugekommen/entfallen oder Transportweg einer Quelle geändert (das ist die einzige Neuordnung). |
| Störung ohne Knotenmarkierung | `alert` und `state` sind getrennte Felder; im Detail die Meldung lesen. |
| „Beheben“ ohne Link | Die URL im JSON ist leer oder nicht relativ – absolute/fremde Ziele werden bewusst verworfen. |

---

## 19. Abbildungen

| Bild | Inhalt |
|---|---|
| `docs/screenshots/118-admin-orvanta-nachrichtenfluss-topologie-zuordnung.png` | 3D-Netz mit Zuordnung der Identitätsquellen (Proxy-Pfad links, Exchange-Pfad rechts). |
| `docs/screenshots/119-admin-orvanta-nachrichtenfluss-topologie-kennzahlen.png` | Zählerpillen und Kennzahlen am Knoten. |
| `docs/screenshots/120-admin-orvanta-nachrichtenfluss-topologie-speicher-tiers.png` | 2D-Ansicht mit lokalem VM-Speicher und Speicher-Tiers. |

---

## Siehe auch

- [`docs/orvanta-nachrichtenfluss.md`](orvanta-nachrichtenfluss.md) – Konzept und Bedienung (Abschnitt 14).
- [`docs/orvanta-referenz.md`](orvanta-referenz.md) – Nachrichtenfluss-Modul, Erhebung und Endpunkt (Abschnitt 23).
- [`docs/mail-proxy-referenz.md`](mail-proxy-referenz.md) – Proxy-Pfad und Zustandsquellen.
- [`docs/storage-referenz.md`](storage-referenz.md) – Speicher-Tiers, Füllstände und Snapshot.
- [`agentsindex.md`](../agentsindex.md) – Routen, Tabellen und Dateiverzeichnis.
