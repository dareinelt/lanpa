# Code-Statistik

Gezählt werden nicht-leere Zeilen in allen getrackten Textdateien (Binärdateien ausgenommen).
**Gesamt: ca. 73.650 Zeilen in 556 Dateien.** Die Funktionszuordnung erfolgt heuristisch über Datei- und Pfadnamen und ist daher eine Näherung.

## Nach Sprache

| Sprache | Zeilen |
|---|---|
| PHP | 51.721 |
| JavaScript | 5.034 |
| Markdown | 4.381 |
| Shell | 4.076 |
| CSS | 3.865 |
| HTML | 2.090 |
| SQL | 849 |
| YAML | 530 |
| Config/.cnf/.example | 503 |
| PowerShell | 235 |
| Sonstige (Patch, XML, JSON, Diagramme, ohne Endung) | 367 |

## Nach Funktion

| Funktion | Zeilen | davon PHP |
|---|---|---|
| Speicher (Storage, Snapshots, Tiering, Incidents, Quota, Backup) | 15.903 | 14.352 |
| Intranet (Seiten, Navigation, Links, Design, Statistik) | 9.876 | 5.558 |
| Office/Nextcloud/Netzlaufwerke | 9.813 | 7.622 |
| Verzeichnis (LDAP/AD/SSO/Auth/Telefonbuch) | 8.164 | 6.390 |
| Tests | 7.809 | 7.765 |
| Doku (README, docs, Handbücher) | 7.058 | – |
| Notfallplan/Alarmierung | 5.623 | 3.860 |
| Sonstiges (Admin-Basis, Repos, Skripte) | 3.711 | 1.994 |
| Kern/Infrastruktur (Core, Config, Migrationen, Docker) | 3.028 | 1.970 |
| TLS/SMTP/SNMP | 2.666 | 2.210 |

## Kreuztabelle (Funktion × Sprache)

| Funktion | Aufschlüsselung |
|---|---|
| Speicher | PHP 14.352, JS 679, Shell 403, SQL 285, CSS 131 |
| Intranet | PHP 5.558, CSS 2.702, JS 1.519, SQL 73 |
| Office/Nextcloud | PHP 7.622, JS 909, Shell 678, CSS 375, Config 128, SQL 60 |
| Verzeichnis | PHP 6.390, Shell 985, JS 295, PowerShell 134, Config 133, SQL 92 |
| Tests | PHP 7.765, JS 44 |
| Doku | Markdown 4.374, HTML 2.090, CSS 428, JS 123 |
| Notfallplan/Alarmierung | PHP 3.860, JS 1.393, CSS 229, SQL 141 |
| Sonstiges | PHP 1.994, Shell 1.614, PowerShell 101 |
| Kern/Infrastruktur | PHP 1.970, YAML 529, .example 207, SQL 137, Shell 74 |
| TLS/SMTP/SNMP | PHP 2.210, Shell 307, JS 72, SQL 61 |
