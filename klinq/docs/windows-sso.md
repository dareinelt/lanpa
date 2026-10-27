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

Beim Anlegen bzw. Abgleich des Benutzers verknüpft `App\Services\Lanpa\LanpaUserService`
das Konto mit dem lokalen Mitarbeiterdatensatz (`users.employee_id`): zuerst über den
Anmeldenamen (`employees.username`), sonst über die E-Mail-Adresse. Eine bereits gepflegte
Verknüpfung wird nie überschrieben, nur weil der Abgleich gerade keinen Treffer liefert.
Diese Verknüpfung entscheidet über die Portal-Sichtbarkeit eigener Tickets (siehe
[helpdesk.md](helpdesk.md#serviceportal-portal)); fehlt sie ausnahmsweise (z. B. Mitarbeiter
erst nach der Anmeldung angelegt), greifen Help Desk und Ticketliste ersatzweise auf
Anmeldename/E-Mail zurück.

Das Ziel nach dem Einstieg steht im Parameter `next` (z. B. `/stoerung` aus dem Störungsmeldeformular).
Fehlt er oder ist er das Standardziel `/dashboard`, öffnet KLINQ das zuletzt verwendete Modul des
Benutzers (siehe [helpdesk.md](helpdesk.md#bereichswechsel-in-der-oberfläche)). Das gilt nur, solange
das Recht für dieses Modul vorliegt: `ModuleNavigation::entryUrl()` prüft die Berechtigungen und
liefert sonst `null`, sodass `startUrl()` auf den Help-Desk-Einstieg ausweicht. Ein Benutzer ohne
KLINQ-Berechtigung landet dadurch im Serviceportal statt in der Assetverwaltung.

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
| Eigenes Ticket im Portal nicht sichtbar (`403`) | Konto ist mit keinem Mitarbeiterdatensatz verknüpft (`users.employee_id` leer) und Anmeldename/E-Mail passen zu keinem `employees`-Datensatz – Mitarbeiter anlegen bzw. Anmeldename/E-Mail dort pflegen, dann erneut anmelden |