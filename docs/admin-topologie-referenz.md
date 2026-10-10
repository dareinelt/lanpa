# Gesamt-Topologie der Anwendung – technische Referenz

Diese Datei beschreibt das **Topologie-Dashboard** (`/admin/topologie`) auf
Code-Ebene: Aufbau der Hülle, Erhebung der Daten, Datenvertrag, Knoten- und
Kantensemantik, Zustandsmodell, Modulhierarchie, Anordnung und Projektion,
Aktualisierung, Interaktion, Entwurfsmodus sowie die Eigenheiten, die man beim
Ändern kennen muss.

Sie ist die **technische** Ergänzung zur Betriebsdokumentation in
[`agentsindex.md`](../agentsindex.md) (Routen, Tabellen, Dateiverzeichnis) und
grenzt sich von den modulspezifischen Referenzen ab: Wer wissen will, *wie ein
Modul intern funktioniert*, liest die jeweilige Modulreferenz; wer wissen will,
*wie die Topologie daraus ein Bild baut und warum sie dabei manches nur
ableitet statt misst*, liest dieses Dokument.

> **Rangfolge:** Bei Widersprüchen gilt immer der Code. Fundstellen sind als
> Klasse/Funktion angegeben (nicht als Zeilennummer), damit sie beim
> Weiterentwickeln gültig bleiben.

## TL;DR

- Die Ansicht ist eine **eigenständige Vollbildseite** (Layout `layouts.editor`,
  ohne Seitenmenü) mit eigener dunkler „Leitwarte“-Gestaltung; sie erbt nur
  Schrift, Fokus und Hilfsklassen der Intranet-Oberfläche.
- Es gibt **keine eigene Datenquelle**: `TopologyCollector` liest die
  vorhandenen Dienste und Repositories **ausschließlich lesend** aus und
  `LanpaTopologyService` formt daraus ein Netz aus Bausteinen, Beziehungen und
  Modulgruppen. Erstzustand wird als JSON eingebettet, danach wird
  `GET /admin/topologie/daten` gepollt (`Cache-Control: no-store`).
- **Zustände werden nicht erfunden.** Jeder Knotenzustand stammt entweder aus
  einer echten Messung oder ist ausdrücklich als `derived`/`suspected`
  gekennzeichnet. Was gar nicht geprüft wird, ist `unknown` **und**
  `unwatched` und zählt nicht gegen die Gesamtbewertung.
- Zeichnen und Aktualisieren laufen in **reinem Vanilla-JS (ES5, IIFE)** auf
  `<canvas>`; keine Bibliothek, kein Bundler, kein `innerHTML`, keine
  Inline-Stile.
- **Zufall ist deterministisch** (FNV-1a-Hash + xorshift), damit Sterne,
  Wolkenpunkte und Streuung nach jedem Laden identisch aussehen.
- Standard-Aktualisierung: **Abfrageintervall der Orvanta-Überwachung × 3**,
  begrenzt auf 60…600 s; Rückfallwert 120 s nur bei nicht lesbarer Einstellung.
- Der **Entwurfsmodus** blendet Bausteine und ganze Modulgruppen per
  Rechtsklick aus. Die Auswahl liegt entweder global (Tabelle `settings`) oder
  persönlich (Tabelle `admin_user_preferences`); persönlich hat Vorrang.
  Ausblenden wirkt **nur auf die Anzeige** – Kennzahlen, Störungen und Lücken
  zählen weiterhin alle Bausteine.
- Der wichtigste Fallstrick beim Ändern: Feldnamen des JSON sind Vertrag
  zwischen Service, Hülle, Skript und Tests – und die Projektion **muss**
  Punkte hinter der Kamera abfangen.

## Inhalt

1. [Zweck und Abgrenzung](#1-zweck-und-abgrenzung)
2. [Begriffe und Code-Bezeichner](#2-begriffe-und-code-bezeichner)
3. [Code-Landkarte](#3-code-landkarte)
4. [Routen, Rechte und Aufrufkette](#4-routen-rechte-und-aufrufkette)
5. [Datenquellen und Erhebungslogik](#5-datenquellen-und-erhebungslogik)
6. [Datenvertrag (JSON)](#6-datenvertrag-json)
7. [Knotenmodell und Zustandssemantik](#7-knotenmodell-und-zustandssemantik)
8. [Kanten und Beziehungen](#8-kanten-und-beziehungen)
9. [Modulhierarchie](#9-modulhierarchie)
10. [Kennzahlen, Störungen und Lücken](#10-kennzahlen-störungen-und-lücken)
11. [Frische, Aktualisierung und Differenzbildung](#11-frische-aktualisierung-und-differenzbildung)
12. [Anordnung (Layout) und Projektion](#12-anordnung-layout-und-projektion)
13. [Darstellungsschichten und Animationen](#13-darstellungsschichten-und-animationen)
14. [Interaktion und Tastatur](#14-interaktion-und-tastatur)
15. [Entwurfsmodus (Anzeigeauswahl)](#15-entwurfsmodus-anzeigeauswahl)
16. [Hülle, CSP und Barrierefreiheit](#16-hülle-csp-und-barrierefreiheit)
17. [Invarianten (nicht brechen)](#17-invarianten-nicht-brechen)
18. [Tests](#18-tests)
19. [Bekannte Grenzen und nicht überwachte Beziehungen](#19-bekannte-grenzen-und-nicht-überwachte-beziehungen)
20. [Änderungsrezepte](#20-änderungsrezepte)
21. [Fehlersuche](#21-fehlersuche)
22. [Abbildungen](#22-abbildungen)

---

## 1. Zweck und Abgrenzung

| Frage | Antwort |
|---|---|
| Was zeigt die Ansicht? | Alle Bausteine der Anwendung lanpa – Module, Dienste, Datenbanken, Container, Netze, Zertifikate, Speicherziele, externe Systeme – samt ihren Beziehungen, Zuständen und Messzeitpunkten. |
| Wozu dient sie? | Als **eine** Seite, auf der man Abhängigkeiten und Störungen sieht, ohne sechs Adminbereiche einzeln zu öffnen. Sie ist ausdrücklich eine **Übersicht**, kein Ersatz für die Detailseiten. |
| Woher kommen die Daten? | Ausschließlich aus vorhandenen Diensten/Repositories (`TopologyCollector`). Es gibt keine eigene Erhebung, keine eigene Tabelle und keinen eigenen Cache. |
| Was tut die Ansicht **nicht**? | Sie löst keine Sicherung aus, stellt nichts wieder her, ändert keine Konfiguration, startet keine Wartung und führt keine Schreiboperation aus. Einzige Schreiboperation ist das Speichern der Anzeigeauswahl im Entwurfsmodus. |
| Warum kein Framework? | Projektprinzip: keine Abhängigkeiten (kein Composer, npm, CDN, keine externen Fonts/Icons). Die Ansicht ist handgeschriebenes PHP, JS und CSS. |
| Wie ist sie erreichbar? | `GET /admin/topologie`, im Seitenmenü unter **System → Topologie**. Nur für Administratoren. |

**Abgrenzung zu den Modul-Topologien.** Die
[Nachrichtenfluss-Topologie](topologie-referenz.md) zeigt einen einzelnen
Modulpfad (Exchange/Proxy/Archiv) sehr detailliert. Dieses Dashboard zeigt
**alle** Module gleichzeitig, dafür je Baustein weniger Tiefe. Beide teilen
Gestaltung und Interaktionsidee, haben aber getrennte Skripte, Services und
Endpunkte. Wer an einem der beiden etwas ändert, sollte das andere nicht
mitziehen.

---

## 2. Begriffe und Code-Bezeichner

| Begriff | Bedeutung |
|---|---|
| **Baustein** | Ein Knoten des Netzes (`node`). Fachlich: Modul, Dienst, Container, Netz, Speicherziel, Zertifikat, externes System. |
| **Modulgruppe** | Ein logischer Bereich (`group`), z. B. „Zugriff und Oberfläche“. Gruppen sind **keine** Knoten: sie haben eine Farbe und einen Zustand, aber keine Kante und keine eigene Messung. |
| **Beziehung** | Eine Kante (`edge`) zwischen zwei Bausteinen, z. B. `depends`, `stores`, `routes`. |
| **Tatsache** | Ein Bereichsergebnis der Erhebung (`fact`/`section`) mit `state`, `message`, `measured_at`, `available`, `data`. |
| **Zustand** | Einer von sechs Werten: `ok`, `warn`, `error`, `off`, `unknown`, `stale` (siehe §7). |
| **Aussagekraft** | Wie belastbar ein Zustand ist: `proven` (gemessen), `derived` (aus Konfiguration abgeleitet), `suspected` (Vermutung), `unwatched` (nicht überwacht). |
| **Frische** | Alter der Messung in Sekunden; ab `STALE_SECONDS` (300 s) gilt eine Quelle als veraltet. |
| **Entwurfsmodus** | Anzeigemodus, in dem per Rechtsklick Bausteine/Gruppen ausgeblendet werden und die Auswahl global oder persönlich gespeichert werden kann. |

---

## 3. Code-Landkarte

| Ebene | Datei | Aufgabe |
|---|---|---|
| Route | `public/index.php` | Drei Routen in der `$requireAdmin`-Gruppe. |
| Controller | `app/Controllers/Admin/TopologyController.php` | Hülle ausliefern, JSON ausliefern, Anzeigeauswahl speichern. |
| Hülle | `views/admin/topology.php` | Markup, eingebetteter Startzustand, eingebettete Anzeigeauswahl. |
| Skript | `public/assets/js/admin-topology.js` | Anordnung, Zeichnen, Interaktion, Kontextmenü, Entwurfsmodus, Aktualisierung. |
| Stil | `public/assets/css/topology.css` | Gestaltung der Ansicht (eigener Namensraum `.topo*`). |
| Aufbau | `app/Services/Topology/LanpaTopologyService.php` | Baut Knoten, Gruppen, Kanten, Kennzahlen und den JSON-Vertrag. |
| Erhebung | `app/Services/Topology/TopologyCollector.php` | 17 lesende Bereiche (Tatsachen). |
| Modell | `app/Services/Topology/TopologyGraph.php` | Vokabulare und Strukturprüfung des Netzes. |
| Zustände | `app/Services/Topology/TopologyStatus.php` | Sechs-Zustands-Modell, Rangfolge, Übersetzung aus Fremdwerten. |
| Auswahl | `app/Services/Topology/TopologyVisibilityService.php` | Anzeigeauswahl global/persönlich lesen, speichern, löschen. |
| Ablage | `app/Repositories/AdminUserPreferenceRepository.php` | Persönliche Einstellungen je Kontoname. |
| Verdrahtung | `app/Core/Container.php` | `lanpaTopology()`, `topologyVisibility()`. |
| Menü | `views/layouts/admin.php` | Eintrag `topology` unter `system`. |
| Schema | `database/migrations/055_topology_visibility.sql` | Tabelle `admin_user_preferences`. |
| Tests | `tests/Unit/TopologyTest.php` | Modell-, Vertrags-, Zustands- und Entwurfsmodus-Tests. |

---

## 4. Routen, Rechte und Aufrufkette

| Methode | Pfad | Controller-Aktion | Rechte | Antwort |
|---|---|---|---|---|
| `GET` | `/admin/topologie` | `index()` | `$requireAdmin` | HTML, `Cache-Control: no-store` |
| `GET` | `/admin/topologie/daten` | `data()` | `$requireAdmin` | JSON, `Cache-Control: no-store` |
| `POST` | `/admin/topologie/entwurf` | `saveVisibility()` | `$requireAdmin` + CSRF | JSON `{ok, selection}` |

Alle drei liegen in der Gruppe `$router->group([$requireAdmin], …)`, die
ihrerseits in `$router->group([$requireAuth], …)` verschachtelt ist. Damit gilt:

- Ohne Anmeldung → `302` auf `/admin/login`.
- Angemeldet ohne Adminrolle → `403` (`HttpException`) bzw. JSON-Fehler bei
  `/admin/api*`.
- Die KAEP-Rolle wird schon von `$requireAuth` abgewiesen, weil sie außer
  `/admin/notfallplan` nichts sehen darf.

**Aufrufkette der Hülle**

```
GET /admin/topologie
  → TopologyController::index()
      Container::lanpaTopology()->setRefreshInterval($this->refreshInterval())
      Container::lanpaTopology()->overview()          // baut den Graph
      Container::topologyVisibility()->selection($username)
  → AdminController::adminView('admin.topology', […], 200, 'layouts.editor')
      → views/admin/topology.php
```

`AdminController::adminView()` injiziert `appName`, `themeCss`, `flashes`,
`csrfToken`, `adminUser`, `adminRole`, `documentationEnabled`, `assetVersion`,
`errors`, `old`, `openIncidents`. Die Hülle ergänzt `pageScript` =
`admin-topology.js`, `extraStyles` = `topology.css` und `base` = `/admin/topologie`.

**Aktualisierungsintervall.** `TopologyController::refreshInterval()` liest
`Container::orvantaConfig()->pollInterval()` und rechnet × 3, begrenzt auf
`MIN_INTERVAL` = 60 s und `MAX_INTERVAL` = 600 s. Schlägt das Lesen fehl, gilt
120 s. Der Wert landet als `refresh_interval` im JSON **und** als
`data-refresh-interval` in der Hülle.

---

## 5. Datenquellen und Erhebungslogik

`TopologyCollector::collect()` ruft 17 Bereiche auf. Jeder Bereich ist einzeln
in `try/catch` gefasst: ein defektes oder nicht migriertes Modul darf die
Gesamtansicht nicht verhindern, sondern erscheint als `unknown` mit
„unbekannt“-Meldung. Jeder Bereich liefert dieselbe Form:

```php
['state' => 'ok', 'message' => '…', 'measured_at' => 1730000000,
 'available' => true, 'data' => [ … ]]
```

| Bereich | Quelle | Aussage |
|---|---|---|
| `runtime` | `PHP_VERSION`, `Request::isSecure()`, `X-Forwarded-For` | Die Anwendung antwortet; ob über Proxy und verschlüsselt. |
| `database` | `Database` (`SELECT 1`) | Erreichbarkeit der Datenbank – **keine** Integritätsprüfung. |
| `containers` | `ContainerMetricsService::dashboard()` | Containerkennzahlen über die Karten (`available`/`stale`/`crit`). |
| `orvanta` | `OrvantaFlowService::collect()` + `evaluate()` | Nachrichtenfluss (begrenzte Live-Proben). |
| `mail_proxy` | Proxy-Diagnose `diagnostics(true)` | SMTP-/IMAP-Proxy; ohne Server `off`/„deaktiviert“. |
| `office` | `OfficeHealthService::publicSummary()` | Office-Gesundheit. |
| `office_backup` | Office-Sicherungsagent | Verfügbarkeit und Status des Agenten. |
| `storage` | `StorageService::overview()` | Speicher-HA und Synchronisation. |
| `snapshots` | Snapshot-Status | Anzahl Snapshots; `restore_tested` ist fest `false`. |
| `tls` | Zertifikatsverwaltung | Aktives Zertifikat; `sync_stale` → `stale`. |
| `identity` | Identitätsquellen, SSO-Worker, AD-Synchronisation | Konfigurierte Quellen und Anzahl. |
| `mail_dispatch` | SMTP-Konfiguration und Warteschlange | `enabled`, `queued`, `sending`, `failed`. |
| `alarm` | Alarmierungskonfiguration | Nur Konfigurationsprüfung. |
| `emergency` | Notfallplan | Nur Konfigurationsprüfung. |
| `monitoring` | SNMP-Konfiguration | Nur Konfigurationsprüfung. |
| `ai` | KI-Konfiguration | Konfiguriert, aber **bewusst** `unknown`: Erreichbarkeit wird hier nicht geprüft. |
| `optional` | Optionale Erweiterungen | Immer `unknown` – „werden von der Anwendung aus nicht überwacht“. |

**Grundsätze** (Klassendocblock von `TopologyCollector`):

1. Ausschließlich lesend.
2. Jeder Bereich einzeln abgesichert.
3. Keine langen Wartezeiten; wo Proben möglich sind, sind sie begrenzt.
4. Fehlende Daten sind `unknown`, nicht `ok`.

**Veraltung.** `TopologyCollector::STALE_SECONDS` = 300. Ein Bereich, dessen
`measured_at` älter ist, wird in der Frischeübersicht als `stale` markiert und
im Kopf als veralteter Stand ausgewiesen.

---

## 6. Datenvertrag (JSON)

`LanpaTopologyService::overview()` liefert `contract($graph, $facts)`. Der
Vertrag ist **bindend** zwischen Service, Hülle, Skript und Tests.

| Feld | Typ | Bedeutung |
|---|---|---|
| `schema_version` | string | Vertragsversion, derzeit `1.0`. |
| `generated_at` | string | Ortszeit, für die Anzeige. |
| `generated_iso` | string | ISO-Zeit, für Vergleiche. |
| `refresh_interval` | int | Sekunden bis zur nächsten Aktualisierung. |
| `overall` | object | Gesamtbewertung (siehe §10). |
| `groups` | list | Modulgruppen (siehe §9). |
| `nodes` | object | Bausteine, geschlüsselt nach Kennung. |
| `edges` | list | Beziehungen. |
| `incidents` | list | Bausteine in `error`/`warn`. |
| `gaps` | list | Bausteine in `unknown`/`stale`, die **nicht** `unwatched` sind. |
| `freshness` | object | Alter je Quelle und Gesamturteil. |
| `kpis` | list | Zwölf Kennzahlen (siehe §10). |
| `validation` | list | Strukturhinweise aus `TopologyGraph::validate()`. |
| `validation_ok` | bool | `true`, wenn `validation` leer ist. |

**`overall`**

```json
{ "state": "warn", "label": "Einschränkung", "message": "2 Einschränkungen",
  "errors": 0, "warnings": 2, "unrated": 0, "unwatched": 3,
  "counts": { "ok": 40, "warn": 2, "error": 0, "off": 6,
              "unknown": 3, "stale": 0 },
  "incidents": 2, "nodes": 54 }
```

**Baustein**

```json
{ "id": "core:lanpa", "title": "lanpa", "subtitle": "Intranet-Anwendung",
  "kind": "core", "kind_label": "Kern", "group": "group:access",
  "parent": null, "layer": 1, "optional": false,
  "state": "ok", "state_label": "In Ordnung", "severity": 0,
  "message": "Die Anwendung beantwortet Anfragen.",
  "evidence": "proven", "evidence_label": "gemessen", "unwatched": false,
  "measured_at": 1730000000, "facts": [ { "label": "PHP", "value": "8.4.2" } ],
  "containers": [ "app" ], "link": "/admin", "children": [ "…" ] }
```

**Beziehung**

```json
{ "id": "e:core:lanpa→db:mysql", "from": "core:lanpa", "to": "db:mysql",
  "type": "depends", "type_label": "hängt ab von", "label": "…",
  "state": "ok", "state_label": "In Ordnung", "message": "",
  "evidence": "derived", "evidence_label": "abgeleitet",
  "protocol": "TCP", "measured_at": 1730000000 }
```

**Modulgruppe**

```json
{ "id": "group:access", "title": "Zugriff und Oberfläche",
  "subtitle": "…", "parent": null, "optional": false, "module": "access",
  "link": "/admin", "nodes": [ "…" ], "node_count": 7,
  "state": "ok", "state_label": "In Ordnung" }
```

**Frische**

```json
{ "generated_ts": 1730000000, "measured_ts": 1730000000, "age_seconds": 0,
  "stale_after": 300, "fresh": true,
  "sources": { "database": { "state": "ok", "measured_at": 1730000000,
                             "age_seconds": 0, "stale": false } } }
```

**Verknüpfungen.** `LanpaTopologyService::safeLink()` gibt nur relative
Verwaltungsziele weiter, die `^/[a-z0-9/_-]*$` erfüllen. Absolute oder fremde
Ziele werden verworfen (`null`); das Skript zeigt dann keinen „Verwalten“-Knopf.
Das ist eine Sicherheitsmaßnahme, kein Schönheitsfehler.

---

## 7. Knotenmodell und Zustandssemantik

`TopologyStatus` kennt sechs Zustände mit fester Rangfolge (`RANK`):

| Zustand | Rang | Bezeichnung | Bedeutung |
|---|---|---|---|
| `ok` | 0 | In Ordnung | Geprüft und unauffällig. |
| `off` | 1 | Abgeschaltet | Bewusst nicht aktiv (Einstellung aus, Dienst deaktiviert). |
| `stale` | 2 | Veraltet | Es gibt eine Messung, sie ist aber zu alt. |
| `unknown` | 3 | Unbekannt | Keine belastbare Aussage. |
| `warn` | 4 | Einschränkung | Funktioniert, aber mit Abstrichen. |
| `error` | 5 | Störung | Funktioniert nicht. |

`worst()` und `severity()` vergleichen über diesen Rang. `isHealthy()` (ok/off),
`isProblem()` (warn/error), `isUnrated()` (unknown/stale) und `fresh()` sind
Abfragehelfer.

**Übersetzung aus Fremdwerten.** `fromSource()` bildet die Zustandsvokabeln der
Fachmodule auf diese sechs Werte ab:

- → `ok`: `ok`, `online`, `up`, `active`, `ready`, `healthy`, `success`,
  `valid`, `in_sync`, `syncing`, `sent`, `passed`, `reachable`
- → `warn`: `warn`, `warning`, `degraded`, `partial`, `lagging`, `blocked`,
  `paused`, `maintenance`, `queued`, `sending`, `expiring`, `starting`
- → `error`: `error`, `critical`, `down`, `offline`, `failed`, `failure`,
  `unavailable`, `unhealthy`, `unreachable`, `expired`, `not-yet-valid`,
  `invalid`
- → `off`: `off`, `disabled`, `inactive`, `not_configured`, `none`, `skipped`
- → `stale`: `stale`, `outdated`
- → `unknown`: `unknown`, leer

**Nicht überwacht ≠ unbekannt.** Beim Bau des Vertrags gilt:

```php
$unwatched = $state === TopologyStatus::UNKNOWN && !is_int($measuredAt);
```

Ein Baustein ohne eigenen Messzeitpunkt ist **vorhanden, aber nicht
überwacht**. Er wird:

- aus `incidents` **und** `gaps` ausgeschlossen,
- aus `overall.unrated` herausgerechnet (`unrated = unknown + stale − unwatched`),
- in der Anzeige mit der Aussagekraft `unwatched` gekennzeichnet.

Damit zieht ein grundsätzlich unüberwachbarer Baustein (z. B. ein fremdes
Exchange-System) die Gesamtbewertung nicht dauerhaft auf „unbekannt“.

**Aussagekraft (`evidence`).** Vier Werte:

| Wert | Bezeichnung | Bedeutung |
|---|---|---|
| `proven` | gemessen | Aus einer echten Messung/Probe. |
| `derived` | abgeleitet | Aus Konfiguration oder einem anderen Ergebnis logisch gefolgert. |
| `suspected` | vermutet | Annahme, nicht belegt. |
| `unwatched` | nicht überwacht | Wird gar nicht geprüft. |

Kanten werden grundsätzlich gestrichelt gezeichnet, wenn sie nicht `proven`
sind – die Linienart ist damit ein sichtbarer Hinweis auf die Belastbarkeit.

---

## 8. Kanten und Beziehungen

`TopologyGraph::EDGE_TYPES` (neun Typen):

| Typ | Bezeichnung | Richtung |
|---|---|---|
| `depends` | hängt ab von | Nutzer → Abhängigkeit |
| `routes` | leitet weiter an | Quelle → Ziel |
| `stores` | speichert in | Schreiber → Speicher |
| `backs_up` | sichert | Sicherung → Ziel |
| `restores` | stellt wieder her aus | Wiederherstellung → Quelle |
| `monitors` | überwacht | Überwachung → Ziel |
| `contains` | enthält | Netz/Container → Inhalt |
| `authenticates` | authentifiziert gegen | Dienst → Identitätsquelle |
| `replicates` | repliziert nach | Quelle → Ziel |

**Statische und fließende Kanten.** Das Skript unterscheidet:

- `FLOW_TYPES` = `routes`, `stores`, `mail`, `authenticates`, `replicates` –
  auf diesen laufen Partikel (Datenfluss).
- `STATIC_TYPES` = `depends`, `backs_up`, `restores`, `monitors`, `contains` –
  ruhende Beziehungen, keine Partikel.

**Aufbau.** `baseEdges()` legt die festen Beziehungen aus der Konfiguration an
(`derived`); `dynamicEdges()` ergänzt Kanten aus den Tatsachen, z. B.
Ausfälle oder Replikationsrichtungen. Netzwerkkanten entstehen aus
`NETWORKS` (6 Netze) und `CONTAINER_NETWORKS` (21 Container), die
`docker-compose.yml` spiegeln – sie sind `contains` + `derived`.

---

## 9. Modulhierarchie

`LanpaTopologyService::groups()` baut eine zweistufige Hierarchie. Wurzelgruppen
(`parent === null`):

`group:access`, `group:identity`, `group:data`, `group:mail`, `group:orvanta`,
`group:office`, `group:ai`, `group:storage`, `group:protect`, `group:monitor`,
`group:network`, `group:optional`.

Kindgruppen:

- `group:orvanta.exchange`, `group:orvanta.proxy`, `group:orvanta.archive`
- `group:office.nextcloud`, `group:office.eurooffice`
- `group:storage.tiers`

Jeder Baustein trägt `group` (seine direkte Gruppe) und – im Skript abgeleitet –
`topGroup` (seine Wurzelgruppe). `topGroup` steuert Clusterbildung,
Einklappen, Fokus und die Anzeigeauswahl.

Gruppenzustand = schlechtester Zustand ihrer Bausteine (`worst()`). Gruppen
haben **keine** eigene Messung und **keine** Kante.

---

## 10. Kennzahlen, Störungen und Lücken

**Gesamtbewertung** (`overall()`):

```
error  falls errors > 0
warn   falls warnings > 0
unknown falls unrated > 0
ok     sonst
```

Die Meldung ist jeweils sprechend: bei `error` werden die Namen der gestörten
Bausteine aufgezählt, bei `warn` die Anzahl, bei `unknown` die Anzahl der
Bausteine ohne belastbare Messung, bei `ok` „Alle erfassten Bausteine sind in
Ordnung.“

**Störungen und Lücken** (`flagged()`):

- `incidents` = Bausteine in `error` oder `warn`.
- `gaps` = Bausteine in `unknown` oder `stale`, **ohne** `unwatched`.
- Beide sind nach Rang absteigend, dann alphabetisch sortiert und tragen
  `node`, `title`, `group`, `group_title`, `state`, `state_label`, `severity`,
  `message`, `optional`, `measured_at`.

**Zwölf Kennzahlen** (`kpis()`):

| Schlüssel | Bezeichnung | Berechnung |
|---|---|---|
| `nodes` | Bausteine | Anzahl aller Bausteine |
| `groups` | Module | Anzahl aller Gruppen |
| `edges` | Beziehungen | Anzahl aller Kanten |
| `errors` | Störungen | Anzahl `error` |
| `warnings` | Einschränkungen | Anzahl `warn` |
| `unrated` | Ohne Messung | Anzahl `unknown` + `stale` |
| `containers` | Container mit Kennzahlen | „X von Y“ – Karten mit `available` und ohne `stale` |
| `exchange` | Exchange-Hosts | Anzahl Bausteine der Art `external` |
| `identity` | Identitätsquellen | Anzahl Bausteine der Art `identity` |
| `tiers` | Cold-Tier-Ziele | Anzahl `storage.data.targets` |
| `snapshots` | Snapshots | `snapshots.data.snapshots_total` |
| `mail` | Mails wartend | `mail_dispatch.data.queued` |

---

## 11. Frische, Aktualisierung und Differenzbildung

**Frische.** `freshness()` berechnet je Bereich das Alter
(`now − measured_at`), markiert Bereiche über `STALE_SECONDS` (300 s) als
`stale` und liefert `fresh` als Gesamturteil. Ist `fresh === false`, blendet
das Skript das Band `[data-topo-banner="stale"]` ein.

**Aktualisierung.** Das Skript pollt `data-refresh-url`
(`/admin/topologie/daten`) mit `credentials: 'same-origin'`, `Accept:
application/json` und `cache: 'no-store'`. Das Intervall kommt aus
`data-refresh-interval`. Ist der Tab verborgen (`document.hidden`), wird die
Abfrage übersprungen, der Zeitgeber aber neu gesetzt. Die Schaltfläche
„Aktualisieren“ (`r`) löst eine sofortige Abfrage aus.

**Differenzbildung.** `diff(data)` vergleicht die Zustände gegen die letzte
Antwort und schreibt Änderungen ins Ereignisprotokoll:

- Zustandswechsel: `Titel: alter Zustand → neuer Zustand`.
- Entfallene Bausteine: `Baustein entfallen: <Kennung>`.
- Neu aufgetretene `error` lösen zusätzlich einen Pulse-Effekt aus.
- Strukturhinweise (`validation_ok === false`) werden als Warnung protokolliert.

**Positionen bleiben stabil.** Die Anordnung läuft nur beim ersten Empfang
(`layout()`); spätere Antworten aktualisieren Zustände, Kennzahlen und
Meldungen, nicht die Positionen. Positionen springen daher nur, wenn Bausteine
hinzukommen oder entfallen.

---

## 12. Anordnung (Layout) und Projektion

**Konstanten** (Skriptkopf): `FOCAL` = 1150, `MAX_PARTICLES_PER_EDGE` = 12,
`AMBIENT_PER_NODE` = 8, `STARS` = 200, `IDLE_BEFORE_ROTATE` = 4000,
`GROUP_RING_PAD` = 96.

**3D-Anordnung** (`layout()`, Teil 1): jede Wurzelgruppe bildet einen Cluster
auf einem großen Ring (`groupRadius = min(620, 240 + count * 34)`). Innerhalb
eines Clusters liegen die Wurzelknoten auf einem eigenen Ring
(`radius = min(210, 62 + roots.length * 26)`), die Kindknoten um ihren
Elternknoten (`min(150, 46 + kids.length * 24)`). Kinder ohne sichtbaren
Elternknoten werden trotzdem im Cluster untergebracht. Anschließend verschiebt
`layer` jeden Knoten vertikal (`pos3.y += (3 - layer) * 26`), damit Zugriffe
oben und Speicher unten liegen.

**2D-Anordnung** (`layout()`, Teil 2): Modulgruppen bilden ein Raster mit
`columns = ceil(sqrt(count))` und 560 px Spaltenabstand. Wurzelknoten stehen
untereinander (Abstand 84 px, Block vertikal um die Zeilenmitte zentriert),
Kindknoten rechts daneben (190 px, 62 px Abstand).

**Streuung und Zufall.** `hashString()` (FNV-1a) und `rng()` (xorshift) erzeugen
für jede Kennung reproduzierbare Streuung. Sterne (`buildStars()`) und
Wolkenpunkte (`graph.ambient`) sehen deshalb nach jedem Laden gleich aus.

**Projektion** (`project()`): Drehung über `yaw`/`pitch`, Perspektive über
`scale3 = FOCAL / max(40, FOCAL - z2)`. Zwei Punkte sind wichtig:

1. **Punkte hinter der Kamera.** Für `z2 >= FOCAL - 40` wäre der Maßstab
   negativ oder null; Canvas wirft dann bei negativen Radien und die
   Zeichenschleife bricht ab. Deshalb wird der Nenner nach unten auf 40
   begrenzt und das Ergebnis als `behind` markiert. `drawGroups()` und
   `edgePath()` überspringen solche Punkte.
2. **Weiche Überblendung.** `view.mix` (1 = 3D, 0 = 2D) mischt 3D- und
   2D-Koordinaten, damit der Wechsel nicht springt.

**Einpassen.** `fit()` setzt Zoom und Verschiebung so, dass das gesamte Netz
sichtbar ist.

---

## 13. Darstellungsschichten und Animationen

Reihenfolge im Bildaufbau:

1. **Hintergrund und Sterne** – dunkle Fläche, deterministisch verteilte
   Sterne mit leichtem Funkeln.
2. **Gruppenringe** – je Wurzelgruppe ein Ring mit Titel, Zustandsfarbe und
   Knotenzahl; bei eingeklappten Gruppen bleibt der wichtigste Knoten als
   Anker sichtbar (`isGroupAnchor()`).
3. **Kanten** – gefüllt oder gestrichelt; `edgeAlpha()` dämpft Kanten, die zum
   ausgewählten Knoten oder zur Fokusgruppe nicht passen.
4. **Partikel** – auf `FLOW_TYPES`-Kanten; die Anzahl je Kante richtet sich
   nach `edge.activity` (`min(MAX_PARTICLES_PER_EDGE, 1 + round(log(activity+1) * 2.2))`).
5. **Knoten** – Form und Farbe nach `kind`, Zustandsring nach `state`,
   Beschriftung bei `view.labels`.
6. **Wolkenpunkte** – je Knoten `AMBIENT_PER_NODE` Punkte (Kernknoten doppelt),
   langsam um den Knoten kreisend.
7. **Effekte** – Pulse bei neu aufgetretenen Störungen.

**Dämpfung statt Ausblenden.** `nodeAlpha()` dämpft statt zu entfernen:

- `view.focusProblems` („Nur Störungen“) → unauffällige Knoten auf 0,16.
- `view.focusGroup` → Knoten außerhalb der Fokusgruppe auf 0,35.
- Auswahl → nicht benachbarte Knoten auf 0,5.
- Entwurfsmodus-Ausblendung → 0,16 plus gestrichelter Ring `[2,5]` (Geist).

**Reduzierte Bewegung.** `prefers-reduced-motion` schaltet Rotation und
Partikel beim Start ab (`reducedMotion`), nicht aber die Darstellung selbst.

---

## 14. Interaktion und Tastatur

| Geste | Wirkung |
|---|---|
| Ziehen mit linker Maustaste | Ansicht drehen |
| Umschalt + Ziehen, mittlere Maustaste, Rechtsklick (außerhalb Entwurfsmodus) | Verschieben |
| Mausrad | Zoomen |
| Klick | Details öffnen (`hitTest`) |
| Doppelklick | Knoten zentrieren, sonst einpassen |
| Rechtsklick | **Im Entwurfsmodus** Kontextmenü; sonst verschieben |
| Zwei-Finger-Geste | Zoomen |

**Tastatur** (`bindKeyboard()`, Fokus auf der Zeichenfläche):

| Taste | Wirkung |
|---|---|
| `←` `→` | Drehen um die Hochachse |
| `↑` `↓` | Neigen |
| `+` / `-` | Zoomen |
| `0` | Ansicht zurücksetzen |
| `f` / `F` | Einpassen |
| `r` / `R` | Aktualisieren |
| `p` / `P` | Partikel umschalten |
| `l` / `L` | Beschriftungen umschalten |
| `g` / `G` | Modulgruppen einklappen |
| `2` / `3` | 2D / 3D |
| `!` | Nur Störungen hervorheben |
| `n` / `N` | Auswahl vorwärts/rückwärts durchlaufen |
| `e` / `E` | Entwurfsmodus umschalten |
| `Enter` | Details des überfahrenen Knotens öffnen |
| `Escape` | Erst Kontextmenü, dann Detailtafel schließen |
| `Leertaste` | Automatische Drehung umschalten |

**Treffererkennung.** `hitTest(point)` sucht den nächstgelegenen gezeichneten
Knoten im Trefferradius; `groupAt(point)` und `groupCircle(groupId)` ordnen
einen Punkt einem Gruppenring zu. Beide berücksichtigen **auch Geister**, damit
im Entwurfsmodus ein ausgeblendeter Baustein per Rechtsklick wieder
eingeblendet werden kann.

**Detailtafel.** `[data-topo-panel]` füllt Art, Titel, Zustandsabzeichen,
Meldung, Fakten, Container, untergeordnete Bausteine, Beziehungen und – falls
vorhanden – den „Verwalten“-Link. Die Tafel ist beim Laden **geschlossen**;
`select(null)` schließt sie.

**Ereignisprotokoll.** `[data-topo-log]` sammelt Zustandswechsel,
Entfallmeldungen, Speichervorgänge und Fehler; „Einklappen“ blendet die Liste
aus, ohne sie zu leeren.

---

## 15. Entwurfsmodus (Anzeigeauswahl)

**Absicht.** Der Entwurfsmodus beantwortet die Frage „welche Bausteine sollen
in dieser Ansicht überhaupt erscheinen?“. Er ist ausdrücklich ein
**Anzeigefilter**, keine Zugriffsbeschränkung: der Graph bleibt vollständig,
Kennzahlen, Störungen und Lücken zählen weiter alle Bausteine.

**Bedienung.**

1. Entwurfsmodus einschalten (`e`/`E` oder die Schaltfläche im Kopf). Die
   Entwurfsleiste `[data-topo-draft]` erscheint, der Zustand `is-draft` wird
   auf die Wurzel gesetzt.
2. Rechtsklick auf einen Baustein oder eine Gruppe öffnet das Kontextmenü mit
   **„Ausblenden“** bzw. **„Einblenden“**. Bei Bausteinen zusätzlich
   „Details anzeigen“.
3. **„Global speichern“**, **„Persönlich speichern“**, **„Zurücksetzen“** oder
   **„Persönliche Einstellung löschen“**.
4. Entwurfsmodus beenden.

**Geister.** Im Entwurfsmodus bleiben ausgeblendete Bausteine blass sichtbar
(Alpha 0,16, gestrichelter Ring `[2,5]`) und bleiben anklickbar, damit sie
wieder eingeblendet werden können. Außerhalb des Entwurfsmodus verschwinden sie
wirklich.

```js
isHiddenByUser(node) = hiddenNodes[node.key] || hiddenGroups[node.topGroup]
isGhost(node)        = view.draft && isHiddenByUser(node)
isDrawn(node)        = !isHidden(node) || isGhost(node)
```

**Verschieben per Rechtsklick ist im Entwurfsmodus abgeschaltet**
(`panning = shiftKey || button === 1 || (button === 2 && !view.draft)`), damit
der Rechtsklick eindeutig dem Menü gehört. `endPointer()` steigt bei
`button === 2 && view.draft` aus, damit kein Klick die Auswahl verändert.

**Ablagen und Vorrang.**

| Herkunft (`source`) | Ablage | Geltung |
|---|---|---|
| `personal` | Tabelle `admin_user_preferences`, Schlüssel `topology_hidden` je Kontoname | Nur dieses Konto |
| `global` | Tabelle `settings`, Schlüssel `topology_hidden` | Alle Betrachter |
| `default` | – | Nichts ist ausgeblendet |

Ein persönlicher Eintrag hat **Vorrang** und ist eine **vollständige Auswahl**,
kein Zusatz zum globalen Eintrag. „Persönliche Einstellung löschen“ entfernt
die persönliche Zeile; danach gilt wieder der globale Eintrag.

**Speichern.** Das Skript schickt `application/x-www-form-urlencoded` mit
`_token` (CSRF), `scope` (`global`/`personal`/`clear`), `nodes[]` und
`groups[]`
(`URLSearchParams`, `credentials: 'same-origin'`). Der Server antwortet mit der
**danach wirksamen** Auswahl:

```json
{ "ok": true, "selection": { "source": "personal",
  "source_label": "Persönliche Einstellung",
  "nodes": [ "…" ], "groups": [ "group:optional" ] } }
```

Das Skript übernimmt diese Antwort über `applySelection()` als neuen
Bezugspunkt. „Zurücksetzen“ stellt genau diesen Bezugspunkt wieder her, ohne zu
speichern. Unbekannter `scope` → `422` mit `{ok:false, error:…}`.

**Normalisierung.** `TopologyVisibilityService::normalizeIds()` akzeptiert nur
Listen (`array_is_list`), übernimmt Zeichenketten und Ganzzahlen, verwirft
Leerraum-Kennungen und Kennungen über `MAX_ID_LENGTH` (190 Byte, Passung zur
Spalte), entfernt Doppelte, sortiert stabil (`SORT_STRING`) und begrenzt auf
`MAX_ENTRIES` (500). Unbekannte Kennungen werden **nicht** verworfen – sie
können zu einem Baustein gehören, der gerade nicht messbar ist.

**Persönliche Einstellung ohne Kontonamen.** Ist der Kontoname leer
(nicht ermittelbar), ist „Persönlich speichern“ abgeschaltet
(`data-personal-available="0"`); es gilt nur der globale Eintrag. Der
Kontoname wird bewusst **nicht** als Fremdschlüssel auf `admin_users`
abgebildet, weil AD-Konten dort keine Zeile haben.

**Sichtbare Rückmeldung.** Die Entwurfsleiste nennt Herkunft und Anzahl
(`Keine Auswahl gespeichert · ausgeblendet: 1 Baustein(e), 1 Gruppe(n)`), die
Sprungliste markiert ausgeblendete Gruppen (`is-hidden-by-user`), und das
Protokoll schreibt jeden Ein-/Ausblendevorgang mit.

**Kontextmenü.** `openMenu()` baut die Einträge zur Laufzeit mit
`createElement`/`textContent` (kein `innerHTML`), setzt `role="menu"`/
`role="menuitem"`, fokussiert den ersten Eintrag, ist mit `↑`/`↓` bedienbar,
schließt bei `Escape` und bei Zeigerdruck außerhalb und wird am Fensterrand
geklemmt. Der Titel lautet `Baustein: …` bzw. `Gruppe: …`.

**Sonderfall: Gruppe ausgeblendet, Einzelbaustein einblenden.** `toggleNode()`
entfernt in diesem Fall zuerst den Gruppeneintrag, sonst bliebe der Baustein
unsichtbar.

---

## 16. Hülle, CSP und Barrierefreiheit

- Die Hülle liefert zwei `<script type="application/json">`-Blöcke:
  `data-topo-initial` (der ganze Graph) und `data-topo-visibility` (die
  wirksame Auswahl). Beide werden mit `Html::json()` geschrieben
  (`JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`)
  und sind damit auch innerhalb von `<script>` sicher.
- Wurzelattribute: `data-lanpa-topology`, `data-refresh-url`,
  `data-refresh-interval`, `data-base-url`, `data-save-url`, `data-csrf`,
  `data-personal-available`, `data-overall-state`, `data-schema-version`.
- Alle Ausgaben laufen über `Html::e()`; URLs über `Html::url()`.
- **Kein Inline-JavaScript, keine Inline-Handler, keine Inline-Stile.** Das
  Skript wird als `pageScript` eingebunden und ist über die CSP-Nonce gedeckt.
  Das ist der Grund, warum das Kontextmenü zur Laufzeit gebaut wird.
- Die Zeichenfläche ist `tabindex="0"` mit `role="application"` und
  beschreibendem `aria-label`; Statusbereiche nutzen `role="status"` bzw.
  `aria-live="polite"`; Umschalter tragen `aria-pressed`, deaktivierte
  Schaltflächen zusätzlich `aria-disabled`.
- Es gibt **zwei** Schaltflächen mit `data-topo-action="draft"` (Kopfzeile und
  „Beenden“ in der Entwurfsleiste). Deshalb arbeiten `setPressed()` und
  `setDisabled()` mit `querySelectorAll` – das ist Absicht, kein Fehler.
- `<noscript>` erklärt, dass die Ansicht JavaScript benötigt.
- Die Ansicht lädt **keine** externen Ressourcen: keine Fonts, keine Icons,
  keine CDN-Skripte. Symbole sind Textzeichen.

---

## 17. Invarianten (nicht brechen)

1. **Nur lesend.** Keine Aktion der Ansicht darf eine Sicherung auslösen,
   etwas wiederherstellen, eine Konfiguration ändern oder einen Dienst
   neustarten. Ausnahme: die Anzeigeauswahl im Entwurfsmodus.
2. **Vertragsnamen sind stabil.** Wer ein JSON-Feld umbenennt, muss Service,
   Hülle, Skript und Tests gemeinsam ändern.
3. **Sechs Zustände, feste Rangfolge.** Neue Zustände nur über
   `TopologyStatus` – sonst bricht `worst()`, `severity()` und die
   Gesamtbewertung.
4. **`unwatched` wird nie zu `unknown`.** Wer den Messzeitpunkt-Sonderfall
   entfernt, lässt die Gesamtbewertung dauerhaft auf „unbekannt“ kippen.
5. **Projektion fängt Punkte hinter der Kamera ab.** Ohne die
   `FOCAL - 40`-Begrenzung wirft Canvas bei negativen Radien und die
   Zeichenschleife bricht ab.
6. **Kein `innerHTML`, kein Inline-JS, keine Inline-Stile.** CSP-Nonce beachten.
7. **`safeLink()` filtert Links.** Kein ungeprüftes Durchreichen von URLs.
8. **Deterministischer Zufall.** Kein `Math.random()` – sonst sieht die
   Ansicht nach jedem Laden anders aus.
9. **Entwurfsauswahl wirkt nur auf die Anzeige.** Kennzahlen, Störungen und
   Lücken bleiben vollständig.
10. **SQL nur über Prepared Statements**, Ausgaben nur über `Html::e()`.

---

## 18. Tests

`tests/Unit/TopologyTest.php` prüft:

- **Modell** (`TopologyGraph`): Vokabulare, `group()`/`node()`/`edge()`,
  `childrenOf()`, `dependenciesOf()`, `dependentsOf()`, `neighboursOf()` und
  `validate()` – doppelte Kennungen, ungültige Art/Zustand/Typ/Aussagekraft,
  unbekannte Endpunkte, Selbstkante, unbekannte Gruppe, fehlende Eltern,
  Hierarchiezyklen und verwaiste Knoten (außer den Wurzeln `core:lanpa` und
  `users:clients`).
- **Vertrag** (`LanpaTopologyService`): Pflichtfelder, Knoten-/Kantenform,
  `overall`-Rechenregel, `freshness`, zwölf Kennzahlen, `safeLink()`.
- **Zustände** (`TopologyStatus`): Rangfolge, `worst()`, `fromSource()`-Tabelle,
  `isHealthy()`/`isProblem()`/`isUnrated()`/`fresh()`.
- **Entwurfsmodus** (`TopologyVisibilityService`, Hülle, Skript, Stil):
  Normalisierung, Herkunftsbezeichnungen, global/persönlich/löschen samt
  Vorrang, beschädigtes JSON (über Reflexion), Hüllenmarkup und eingebettetes
  JSON sowie statische Zusicherungen für JS und CSS.

Ein Testdoppel `TopologySettingsPdo` übersetzt das MySQL-`ON DUPLICATE KEY
UPDATE` des `SettingsRepository` in `ON CONFLICT(setting_key) DO UPDATE SET …`,
damit die Tests ohne MySQL laufen.

**Aufruf:**

```bash
php tests/run.php
find . -name "*.php" -print0 | xargs -0 -n1 php -l
node --check public/assets/js/admin-topology.js
```

Es gibt **keinen** Linter, Formatter oder Typechecker – bitte keinen ergänzen.

---

## 19. Bekannte Grenzen und nicht überwachte Beziehungen

**Gemessen** (`proven`): Laufzeit und Verschlüsselung, Datenbankerreichbarkeit,
Containerkennzahlen, Nachrichtenfluss, Speicher-HA und -Synchronisation,
Zertifikat, Warteschlange, Identitätsquellen.

**Nur aus der Konfiguration abgeleitet** (`derived`): alle Netz- und
Containerkanten, `depends`-Kanten zwischen Modulen, die Zuordnung von
Bausteinen zu Modulgruppen, die Reihenfolge der Identitätsquellen.

**Vermutet** (`suspected`): Beziehungen, für die es keine direkte Probe gibt,
z. B. der Weg eines Sicherungsziels oder die Wirkung eines Replikats.

**Nicht überwacht** (`unwatched` bzw. bewusst `unknown`):

- Die Erreichbarkeit der KI-Anbindung wird hier **nicht** geprüft („ai“ ist
  konfiguriert, aber `unknown`).
- Optionale Erweiterungen werden von der Anwendung aus nicht überwacht
  (`optional` ist immer `unknown`).
- Der Bereich `database` beweist mit `SELECT 1` die **Erreichbarkeit**, nicht
  die **Integrität** der Daten.
- `snapshots.restore_tested` ist fest `false`: eine Wiederherstellung wird
  nicht automatisch geprüft.
- `alarm`, `emergency` und `monitoring` prüfen nur die Konfiguration, nicht die
  Wirkung.

**Weitere Grenzen:**

- In einer lokalen Umgebung ohne Einträge in `identity_sources`,
  `orvanta_exchange_hosts` und `mail_proxy_servers` erscheinen einzelne
  Exchange-Hosts und Proxy-Server **nicht** als eigene Bausteine – die
  Modulgruppen bleiben dann leer. Das ist eine Datenlage, kein Fehler.
- Die Ansicht ist eine Übersicht: sie ersetzt keine Moduldiagnose.
- Ein falsches CSRF-Token erzeugt eine `419`-Fehlerseite, aber die
  HTTP-Statuszeile lautet `500`. Das ist **vorbestehendes** globales Verhalten
  (z. B. auch bei `POST /admin/design`) und wird hier bewusst nicht geändert.

---

## 20. Änderungsrezepte

**Neuen Baustein hinzufügen**

1. Tatsache im passenden `TopologyCollector`-Bereich ergänzen (lesend!).
2. Im zugehörigen Builder in `LanpaTopologyService` `node()` aufrufen:
   `kind`, `group`, `parent`, `layer`, Zustand über `TopologyStatus::fromSource()`
   und `evidence` setzen.
3. In `kpis()`/`flagged()` nur dann eingreifen, wenn der Baustein eine eigene
   Kennzahl braucht.
4. Test für Zustand und Zählung ergänzen.

**Neue Kante**

`baseEdges()` (fest) oder `dynamicEdges()` (aus Tatsachen) ergänzen. Das Skript
zeichnet automatisch; Partikel gibt es, wenn der Typ in `FLOW_TYPES` steht.

**Neue Modulgruppe**

In `groups()` anlegen, `parent` setzen (sonst wird sie eine Wurzelgruppe und
bekommt einen eigenen Cluster), Zustand aus den Mitgliedern ableiten und die
Mitglieder über `group` zuordnen.

**Neue Kennzahl**

Eintrag in `kpis()` ergänzen (Schlüssel, Bezeichnung, Wert, optional `state`).
Die Hülle läuft über die gelieferte Liste und rendert je Eintrag
`[data-topo-kpi="<schlüssel>"]`, das Skript füllt den Wert nach. Ohne eigenen
`state` bleibt die Kachel neutral (`plain`) – sie wird also **nicht** als
„gesund“ dargestellt.

**Neues Werkzeug**

1. Schaltfläche mit `data-topo-action="<name>"` in `views/admin/topology.php`.
2. Eintrag in `actions` im Skript; bei Umschaltern `setPressed()` verwenden.
3. Tastenkürzel in `bindKeyboard()` ergänzen, falls sinnvoll.
4. Statische Zusicherung im Test ergänzen.

**Neue Detailfelder**

`facts` am Knoten ergänzen – die Detailtafel baut Zeilen zur Laufzeit, es gibt
keine Vorlage.

**Neue Aktion im Kontextmenü**

1. `menuEntry(label, action, key, pressed)` in `openMenu()` ergänzen.
2. Fall in `runMenuAction()` ergänzen.
3. Wenn die Aktion etwas speichert: `scope` im Controller und die
   Normalisierung im Service prüfen.

**Anderes Aktualisierungsintervall**

`TopologyController::MIN_INTERVAL`/`MAX_INTERVAL` anpassen oder die Basis im
`OrvantaConfigService` ändern; die Ansicht rechnet × 3 und begrenzt selbst.
Keine Änderung an der Hülle nötig.

---

## 21. Fehlersuche

| Symptom | Wahrscheinliche Ursache / Prüfung |
|---|---|
| Leere Bühne, Konsole zeigt „Zeichenfehler“ | Canvas-Kontext fehlt (sehr alter Browser) oder ein Zeichenfehler – die Meldung nennt die Stelle; die Schleife läuft weiter. |
| Leere Bühne, sonst nichts | `data-topo-initial` nicht lesbar → das Skript lädt selbst nach; Antwort von `/admin/topologie/daten` prüfen. |
| Bausteine fehlen | Antwort prüfen: fehlende Migrationen oder abgeschaltete Dienste lassen Bereiche leer, ohne Fehler zu melden. |
| Alles blass | „Nur Störungen“ aktiv, Fokusgruppe gesetzt oder ein Knoten ausgewählt (Dämpfung). |
| Kanten gestrichelt | `evidence` ist nicht `proven` – das ist die beabsichtigte Kennzeichnung. |
| Gesamtbewertung „Unbekannt“ trotz grüner Bausteine | `overall.unrated > 0`: es gibt Bausteine in `unknown`/`stale`, die **nicht** `unwatched` sind – Lückenband prüfen. |
| „Aktualisieren“ wirkt nicht | Tab verborgen (`document.hidden`), Intervall 60…600 s, `no-store` erzwungen; Netzwerkantwort prüfen. |
| Positionen springen | Bausteine hinzugekommen oder entfallen – das ist die einzige Neuordnung. |
| „Verwalten“ fehlt | `link` ist leer oder nicht relativ; `safeLink()` verwirft absolute/fremde Ziele bewusst. |
| Entwurfsmodus speichert nicht | CSRF-Token (`data-csrf`) abgelaufen → Seite neu laden. Bei „Persönlich“ prüfen, ob `data-personal-available` auf `1` steht. |
| Ausblenden wirkt nicht | Persönliche Auswahl hat Vorrang – Herkunft in der Entwurfsleiste lesen oder „Persönliche Einstellung löschen“. |
| Rechtsklick verschiebt statt Menü zu öffnen | Entwurfsmodus ist aus (`e` drücken). |
| Kontextmenü erscheint am falschen Ort | Menü wird am Fensterrand geklemmt; `openMenu()` prüfen. |

---

## 22. Abbildungen

| Bild | Inhalt |
|---|---|
| `docs/screenshots/130-admin-topologie-3d-gesamtansicht.png` | 3D-Gesamtansicht: Modulgruppen als Cluster auf dem äußeren Ring, Kopfleiste mit Gesamtstatus, Legende. |
| `docs/screenshots/131-admin-topologie-2d-gesamtansicht.png` | 2D-Gesamtansicht: Modulgruppen als Spalten, Bausteine von oben nach unten. |
| `docs/screenshots/132-admin-topologie-2d-detailtafel.png` | Detailtafel eines Bausteins mit Zustand, Fakten, Containern und Beziehungen. |
| `docs/screenshots/133-admin-topologie-stoerungen-kennzahlen.png` | Bänder für Störungen und Lücken sowie die zwölf Kennzahlen. |
| `docs/screenshots/134-admin-topologie-entwurfsmodus-kontextmenue.png` | Entwurfsmodus mit ausgeblendetem Baustein und ausgeblendeter Gruppe (blass = Geist) sowie geöffnetem Kontextmenü mit „Einblenden“. |

---

## Siehe auch

- [`docs/topologie-referenz.md`](topologie-referenz.md) – Nachrichtenfluss-Topologie des Moduls Orvanta (gleiche Gestaltungsidee, eigener Endpunkt).
- [`docs/orvanta-referenz.md`](orvanta-referenz.md) – Nachrichtenfluss-Modul, Erhebung und Endpunkt.
- [`docs/mail-proxy-referenz.md`](mail-proxy-referenz.md) – Proxy-Pfad und Zustandsquellen.
- [`docs/storage-referenz.md`](storage-referenz.md) – Speicher-Tiers, Füllstände und Snapshots.
- [`docs/office.md`](office.md) – Office-Gesundheit, die in den Bereich `office` einfließt.
- [`docs/llmint.md`](llmint.md) – KI-Anbindung (bewusst `unknown`, Erreichbarkeit wird nicht geprüft).
- [`agentsindex.md`](../agentsindex.md) – Routen, Tabellen und Dateiverzeichnis.
