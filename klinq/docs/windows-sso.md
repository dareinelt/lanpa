# Windows-SSO über das Intranet (LANPA)

KLINQ bringt **keine eigene** Windows-Anmeldung mehr mit. Im Regelbetrieb läuft KLINQ als
Container hinter dem Reverse-Proxy der Intranet-Anwendung **LANPA**; dort übernimmt der
`auth`-Container die Windows-Anmeldung per NTLM/Kerberos (SPNEGO) und setzt nach
erfolgreicher Prüfung den Header `X-Remote-User`. KLINQ vertraut diesem Header, weil es ihn
ausschließlich vom Proxy erhält.

Damit entfallen in KLINQ: Keytab-Erzeugung, SPN-Pflege, `krb5.conf`, `mod_auth_gssapi` und
die eigenen Routen `/sso/pruefung` bzw. `/sso/abbruch`. Einzige verbleibende SSO-Route ist
`/sso/lanpa` – die Annahmestelle für das signierte Intranet-Ticket.

## Ablauf

```
Browser ──▶ LANPA /klinq/ (auth-Container, NTLM/Kerberos)
              │  401 → Aushandlung, danach X-Remote-User: DOMAIN\benutzer
              ▼
           klinq-app  ──  ProxyPass, unverändert
```

1. Der Benutzer öffnet `https://intranet.firma.local/klinq/`.
2. Der `auth`-Container verlangt eine Windows-Authentifizierung. Gelingt sie, reicht er die
   Anfrage mit `X-Remote-User` an `klinq-app` weiter.
3. Beim Aufruf über die Intranet-Kachel bzw. `/klinq-starten` stellt LANPA zusätzlich ein
   **signiertes Ticket** aus (HMAC-SHA256 über `KLINQ_SSO_SECRET`) und ruft damit
   `/klinq/sso/lanpa` auf. KLINQ prüft Signatur, Aussteller, Zielgruppe, Gültigkeitsdauer
   und Einmaligkeit (`LANPA_SSO_REPLAY_STORE`) und legt daraus die Sitzung an.

Das Ticket enthält Anmeldename, Anzeigename, E-Mail, Telefon, Abteilung, Standort,
Rechnername, IP-Adresse sowie die vom Intranet vergebenen Berechtigungen.

## Konfiguration (`.env`)

| Variable | Bedeutung |
|---|---|
| `LANPA_ENABLED` | Anbindung an das Intranet aktivieren |
| `KLINQ_SSO_SECRET` | Gemeinsames Geheimnis für die Ticket-Signatur (identisch mit `KLINQ_SSO_SECRET` in LANPA) |
| `LANPA_SSO_ISSUER` | Erwarteter Aussteller im Ticket (Standard `lanpa`) |
| `LANPA_SSO_AUDIENCE` | Erwartete Zielgruppe im Ticket (Standard `klinq`) |
| `LANPA_BASE_URL` | Öffentliche Adresse des Intranets, z. B. `https://intranet.firma.local` |
| `LANPA_INTERNAL_URL` | Interne Adresse für Dienst-zu-Dienst-Aufrufe (Standard `http://app`) |
| `LANPA_DESIGN_CSS` | Design-Ergänzung des Intranets; leer = `/klinq-design.css` ableiten |
| `KLINQ_SERVICE_TOKEN` | Dienst-Token für die internen Endpunkte des Intranets |
| `KLINQ_SERVICE_TOKEN_FILE` | Datei mit dem Dienst-Token (Standard `/run/intranet-sso/token`), wird vor `KLINQ_SERVICE_TOKEN` gelesen |
| `LANPA_SSO_REPLAY_STORE` | Ablage bereits verwendeter Tickets (Standard `storage/tmp/lanpa-sso-used.json`) |
| `KLINQ_BASE_PATH` | Unterpfad, unter dem KLINQ im Intranet erscheint (Standard `/klinq`) |

## Benutzererkennung im Störungsformular

`App\Services\Helpdesk\ReporterIdentityService` ermittelt die Melderdaten in dieser
Reihenfolge:

1. **Intranet-Claims** aus dem LANPA-Ticket (`detected_by = lanpa`) – Vor- und Nachname,
   E-Mail, Telefon, Abteilung, Standort, Rechner und IP kommen direkt aus dem `auth`-Container.
2. **Header `X-Remote-User`**, wenn `HELPDESK_QUICK_REPORT_TRUST_USER_HEADER=true` gesetzt ist
   (im Intranet-Betrieb aktiv) – der Anmeldename wird anschließend über den AD-Abgleich
   (`AdUserLookupService`) zu Kontaktdaten ergänzt.
3. **KLINQ-Sitzung** des angemeldeten Benutzers (eigenständiger Betrieb).

## Eigenständiger Betrieb

Ohne Intranet lässt sich KLINQ weiterhin allein betreiben: Dann entfällt die
Windows-Anmeldung, und der Zugang erfolgt über die lokale Anmeldung von KLINQ (`/login`).
Für das Störungsformular stehen nur die Daten zur Verfügung, die KLINQ selbst ermitteln
kann (IP-Adresse, optional per Reverse-DNS aufgelöster Rechnername).

## Fehlersuche

| Symptom | Ursache |
|---|---|
| Browser fragt nach Benutzername/Passwort | Der Client liegt außerhalb der Intranetzone oder der Name ist kein SPN-Ziel – Namensauflösung und Zonen-Einstellung prüfen |
| `X-Remote-User` fehlt in KLINQ | `KLINQ_ENABLED` im `auth`-Container nicht gesetzt oder `klinq-app` nicht in `backend_hosts` |
| Ticket wird abgelehnt (Signatur) | `KLINQ_SSO_SECRET` unterscheidet sich zwischen LANPA und KLINQ |
| Ticket wird abgelehnt (bereits verwendet) | `LANPA_SSO_REPLAY_STORE` nicht beschreibbar oder zwischen mehreren Instanzen nicht geteilt |
| Störungsformular ohne Kontaktdaten | `HELPDESK_QUICK_REPORT_TRUST_USER_HEADER=false` oder AD-Abgleich nicht erreichbar |