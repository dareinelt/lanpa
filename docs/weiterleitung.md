# Externe Navigationskacheln über den Reverse-Proxy (`/weiterleitung/<id>/`)

Kacheln vom Typ **extern (neuer Tab)** können ihr Ziel wahlweise über den
`auth`-Container der Landingpage aufrufen lassen. Der Browser spricht dann
ausschließlich den Intranet-Host an; die Adresse der Zielanwendung erscheint
weder in der Adresszeile noch im Zertifikat.

Damit entfallen für Zweigstellen und Außenstellen zwei typische Hindernisse:

- **DNS:** Der Name der Zielanwendung muss auf dem Client nicht auflösbar sein;
  aufgelöst wird er nur vom `auth`-Container.
- **Zertifikate:** Der Client prüft nur das Intranet-Zertifikat (aus
  Adminbereich → Zertifikate). Das Zertifikat des Ziels prüft der
  `auth`-Container gegen die System-CAs.

Die Zielanwendung bekommt von der Weiterleitung nichts mit: Sie sieht ihren
eigenen Hostnamen, ihren eigenen Pfad und ihre eigenen Adressen. Das gesamte
Umschreiben (HTML, CSS, JavaScript, Cookies, Umleitungen) findet im
`auth`-Container statt.

```mermaid
flowchart LR
    B[Browser<br/>Zweigstelle] -->|HTTPS intranet.example<br/>/weiterleitung/4/portal| A[auth-Container<br/>Apache, TLS, SSO]
    A -->|HTTPS portal.example<br/>Host: portal.example| Z[Zielanwendung]
    A -->|alles andere| P[app<br/>Landingpage]
```

Die Funktion ist **je Kachel** einzuschalten; ohne Haken ändert sich nichts am
bisherigen Verhalten. Es sind **keine Umgebungsvariablen nötig** – die
gesamte Einrichtung liegt im Adminbereich.

## 1. Aktivierung im Adminbereich

**Administration → Navigation → Element bearbeiten** (oder „Element
hinzufügen“), Typ **„Extern (neuer Tab)“**:

![Proxy-Optionen im Kachelformular](screenshots/126-admin-navigation-weiterleitung.png)

| Feld | Bedeutung |
| --- | --- |
| **Ziel über den Reverse-Proxy dieser Anwendung aufrufen** | Haken setzen, um diese Kachel über den Proxy zu leiten. Ohne Haken (Standard) wird die URL wie bisher direkt geöffnet. |
| **Quellnetze ohne Weiterleitung (CIDR)** | Netze, deren Clients das Ziel weiterhin **direkt** aufrufen (klassische URL). Mehrere Netze durch Komma, Leerzeichen oder Zeilenumbruch getrennt. Leer = alle Aufrufe über den Proxy. |

Das Quellnetz-Feld wird erst mit gesetztem Haken eingeblendet
(`public/assets/js/editor.js`); beim Typ „intern“ verschwinden beide Felder.

Beim Speichern wird geprüft, ob die Adresse gespiegelt werden kann. Ist der
Haken gesetzt und die Adresse ungeeignet, lehnt das Formular die Eingabe mit
einer Meldung am URL-Feld ab. Nicht spiegelbar sind Ziele, die der Proxy nicht
eindeutig auflösen kann:

- kein `http://`/`https://`-Schema,
- Zugangsdaten in der URL (`https://benutzer:passwort@host/…`),
- Platzhalter oder Zeichen außerhalb von `A-Z a-z 0-9 . _ - ~ % + @ : =`
  (z. B. Wildcard-Hosts wie `https://*.example.internal/` oder Leerzeichen),
- Adressen, die keine vollständige Basis sind.

Zielpfad, Abfrage und Fragment sind erlaubt (`https://host/portal/start.php?x=1`).
Die Prüfung ist identisch mit der des Containers
(`App\Support\TileProxy::isProxyableUrl()` ↔ `docker/auth/weiterleitung-sync.sh`),
damit niemals ein Verweis auf einen nicht bedienten Adressraum entsteht.

## 2. Quellnetz-Ausnahmen (CIDR)

Ein Aufruf wird **direkt** bedient, wenn die Client-Adresse in einem der
eingetragenen Netze liegt:

- `192.168.10.0/24` – Netz (IPv4)
- `10.20.30.7/32` – einzelne Adresse
- `2001:db8::/32` – Netz (IPv6)

Die Entscheidung fällt **serverseitig** beim Rendern der Kachel; im Browser ist
keine Sonderlogik nötig. Als Client-Adresse gilt:

1. der **letzte** Eintrag von `X-Forwarded-For` (`Request::clientIp()`) – dieser
   wird vom `auth`-Container gesetzt und ist als einziger nicht frei wählbar,
   oder
2. `REMOTE_ADDR`, wenn kein solcher Kopf vorliegt.

Ist die Client-Adresse unbekannt (leer), wird **weitergeleitet**: Die
Erreichbarkeit des Ziels hat Vorrang, nur ausdrücklich genannte Netze werden
ausgenommen.

## 3. Adressraum und Aufruf

Der Proxy spiegelt das Ziel unter einem eigenen Präfix; die Kennung ist der
Primärschlüssel der Kachel und bleibt deshalb über Umbenennungen stabil:

| Kachel-URL | Adresse der Kachel |
| --- | --- |
| `https://portal.example/` | `/weiterleitung/4/` |
| `https://portal.example/portal` | `/weiterleitung/4/portal/` |
| `https://portal.example/portal/start.php?x=1` | `/weiterleitung/4/portal/start.php?x=1` |

Der Zielpfad steht **1:1 hinter dem Präfix** und wird nicht abgeschnitten. Das
ist Absicht: Die Zielanwendung wird unter demselben Pfad erreicht wie bei einem
Direktaufruf, damit ihre absoluten Verweise unverändert passen.

Endet die Adresse auf einem Verzeichnis (letztes Segment ohne Punkt), ergänzt
die Anwendung einen Schrägstrich, damit relative Verweise der Zielanwendung
gültig bleiben (`App\Support\TileProxy::url()`).

Klickt jemand eine nicht mehr vorhandene Kachel an, greift der leere
Adressraum: Der `auth`-Container liefert die Hinweisseite, statt die Anfrage an
die Landingpage weiterzureichen.

## 4. Verhalten des Proxys (Referenz)

Für jede Kachel erzeugt `weiterleitung-sync.sh` einen `<Location>`-Block
(`/etc/apache2/intranet/weiterleitung.conf`). Der Adressraum steht in
`common.conf` **vor** dem Auffang-`ProxyPass /` der Landingpage.

| Bereich | Umsetzung |
| --- | --- |
| Weiterleitung | `ProxyPass <origin>/ upgrade=websocket timeout=600 retry=3` – WebSockets und langsame Oberflächen funktionieren mit |
| Umleitung des Ziels | `ProxyPassReverse <origin>/`; `Location`-Kopfzeilen zusätzlich per `Header edit` auf den Präfix umgeschrieben (absolute Adressen des Ziels und wurzelrelative Pfade) |
| Cookies | `ProxyPassReverseCookiePath / <pfad>` und Entfernen der `Domain`-Angabe – die Cookies gehören sonst dem falschen Host und der Browser verwirft sie |
| Adressen im HTML | `ProxyHTMLEnable On` + `ProxyHTMLURLMap` für `https://host`, `http://host`, `//host` und generisch `/` |
| Adressen in CSS/JS | `AddOutputFilterByType SUBSTITUTE text/css application/javascript` + `Substitute` für dieselben Formen |
| Kompression | `SetEnv no-gzip 1` und `RequestHeader unset Accept-Encoding` – gepackte Antworten lassen sich nicht umschreiben |
| Host-Tarnung | `RequestHeader set Host "<ziel-host>"`, `ProxyAddHeaders On`, `X-Forwarded-Prefix`; `X-Remote-User`/`X-Remote-Source` werden entfernt |
| Windows-Anmeldung | `AuthType None` im Adressraum – das Ziel authentifiziert selbst; die Intranet-SSO greift hier nicht |
| Fehler des Ziels | `ErrorDocument` 500/502/503 → Hinweisseite |
| Zertifikat des Ziels | `SSLProxyEngine On`; `SSLProxyVerify require` gegen die System-CAs (mit `NAV_PROXY_SSL_VERIFY=none` abschaltbar, z. B. bei eigenem Zertifikat des Ziels) |

Die Konfiguration wird nur übernommen, wenn `apache2ctl -t` sie akzeptiert;
sonst bleibt der bisherige Stand aktiv (Warnung im Protokoll). Änderungen im
Adminbereich werden im laufenden Betrieb übernommen – der Sync-Daemon gleicht
alle 60 s ab und lädt Apache nur bei echten Änderungen neu
(`apache2ctl graceful`).

## 5. Hinweisseite

Ist das Ziel nicht erreichbar (DNS-Fehler, Zertifikat, Dienst aus), liefert der
`auth`-Container eine eigene Seite statt der Apache-Fehlerseite:

![Hinweisseite, wenn das Ziel nicht erreichbar ist](screenshots/127-weiterleitung-nicht-verfuegbar.png)

Die Seite ist bewusst **ohne** Windows-Anmeldung erreichbar (`Require all
granted`, `AuthType None`) – sonst würde eine nicht erreichbare Kachel eine
Anmeldeschleife auslösen. Sie wird über `/weiterleitung-nicht-verfuegbar` auch
direkt von der Anwendung ausgeliefert
(`WeiterleitungController::unavailable`), damit die Route auch außerhalb des
Proxys funktioniert.

## 6. Betriebsparameter im `auth`-Container

Diese Werte betreffen nur den Betrieb des Containers; die fachliche
Konfiguration (Ziele, Quellnetze) liegt ausschließlich im Adminbereich. Alle
haben einen Arbeitsstandard und müssen nicht gesetzt werden.

| Variable | Bedeutung | Standard |
| --- | --- | --- |
| `NAV_PROXY_SSL_VERIFY` | `require` prüft Zertifikat und Namen des Ziels gegen die System-CAs, `none` schaltet die Prüfung ab (nur für Ziele mit eigenem Zertifikat) | `require` |
| `NAV_PROXY_INTERVAL` | Abstand des Abgleichs in Sekunden | `60` |
| `NAV_PROXY_URL` | Adresse des internen Endpunkts `/internal/nav-proxy-config` | `http://app/internal/nav-proxy-config` |
| `NAV_PROXY_FILE` | Ablageort der erzeugten Konfiguration | `/etc/apache2/intranet/weiterleitung.conf` |

Der Abruf erfolgt mit dem Token aus dem Volume `sso_token` und mit
`SSO_SOURCE`, damit auch Instanzen weiterer Identitätsquellen ihre eigenen
Ziele erhalten. Der Endpunkt antwortet nur ohne `X-Forwarded-*`-Kopfzeilen und
nur der passende `auth`-Container.

## 7. Grenzen

- **Adressen ohne Präfix:** Protokollrelative Adressen ohne Schrägstrich
  (`//host`) und rein relative Pfade ohne führenden Schrägstrich werden nur
  umgeschrieben, wenn sie der bekannten Form entsprechen. Bei ungewöhnlich
  gebauten Oberflächen kann ein Verweis auf das Ziel zeigen und dort scheitern.
- **Mehrfach-Hosting am Ziel:** Der Proxy setzt den `Host`-Kopf auf den Host aus
  der Kachel-URL. Antwortet das Ziel je Hostnamen unterschiedlich, gilt diese
  eine Anwendung.
- **Ein Ziel je Kachel:** Weiterleitungen auf einen anderen Host schreibt der
  Proxy auf den eigenen Präfix um; ein Verweis auf einen zweiten Host bleibt
  unverändert und wird direkt aufgerufen (dort gelten wieder DNS und
  Zertifikat des Clients).
- **Geschützte Kacheln:** Kacheln mit SMS-Zugangscode nutzen den Proxy
  ebenfalls. Die Freischaltung bleibt davon unberührt, weil sie an der
  Landingpage und nicht am Ziel hängt.
- **Kein Statusendpunkt:** Der Sync-Stand ist nicht über die Oberfläche
  abfragbar; Auskunft gibt das Protokoll des `auth`-Containers.

## 8. Fehlersuche

| Symptom | Ursache / Abhilfe |
| --- | --- |
| Kachel öffnet die Hinweisseite | Ziel vom `auth`-Container nicht erreichbar. Prüfen: `docker compose exec auth getent hosts <ziel-host>`; Zertifikat: `openssl s_client -connect <ziel-host>:443`. |
| Kachel ruft weiterhin die Original-URL auf | Client-Adresse liegt in einem Quellnetz ohne Weiterleitung – oder die Adresse ist nicht spiegelbar (Abschnitt 1). Die Ausnahmenetze im Formular prüfen. |
| Kachel zeigt leere Seite | Konfiguration wurde nicht übernommen (Protokoll: `WARNUNG`/`Apache-Konfiguration ungueltig`), oder der Zielpfad stimmt nicht. `docker compose exec auth cat /etc/apache2/intranet/weiterleitung.conf` und `apache2ctl -t`. |
| Bilder/Styles fehlen, Verweise zeigen auf den Zielhost | Adresse, die der Proxy nicht umschreibt (Abschnitt 7). Ziel möglichst unter einem eigenen Pfad spiegeln, nicht auf der Wurzel. |
| Änderung im Adminbereich wirkt verzögert | Der Abgleich läuft alle 60 s (`NAV_PROXY_INTERVAL`); alternativ `docker compose restart auth`. |
| Hinweisseite verlangt eine Anmeldung | Es läuft noch eine ältere Konfiguration; `docker compose restart auth`. |

Protokoll des Sync-Daemons:

```bash
docker compose logs auth | grep -i weiterleitung
```

## 9. Tests

- `tests/Unit/TileProxyTest.php` – Adressbildung, Anwendungsregeln, CIDR-Ausnahmen.
- `tests/Unit/IpNetworkTest.php` – Parsen und Prüfen der CIDR-Angaben.
- `tests/Unit/WeiterleitungProxyTest.php` – Vertrag zwischen Anwendung und
  Container: Include in `common.conf`, erzeugte Apache-Konfiguration,
  TLS-Prüfung, Rollback, Hinweisseite, Musterabgleich der Adressen.

```bash
php tests/run.php
```

## 10. Beteiligte Dateien

| Datei | Aufgabe |
| --- | --- |
| `app/Support/TileProxy.php` | Adressbildung und Entscheidung (Proxy oder Direktaufruf) |
| `app/Support/IpNetwork.php` | CIDR-Listen parsen und prüfen |
| `app/Repositories/NavigationRepository.php` | `activeExternalWithProxy()` – Ziele für den Sync |
| `app/Controllers/InternalController.php` | `navProxyConfig()` – interner Endpunkt |
| `app/Controllers/WeiterleitungController.php` | Hinweisseite |
| `views/partials/tiles.php` | Verwendung von `proxy_url` |
| `views/admin/navigation/form.php` | Checkbox und Quellnetz-Feld |
| `public/assets/js/editor.js` | Ein-/Ausblenden der Felder |
| `docker/auth/weiterleitung-sync.sh` | Abgleich und Erzeugung der Apache-Konfiguration |
| `docker/auth/common.conf` | Include des Adressraums vor dem Auffang-Proxy |
| `database/migrations/051_navigation_proxy.sql` | Spalten `proxy_enabled`, `proxy_bypass_networks` |
