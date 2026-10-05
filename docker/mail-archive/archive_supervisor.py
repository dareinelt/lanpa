#!/usr/bin/env python3
"""Supervisor des mail-archive-Containers (Orvanta-Langzeitarchiv).

Ruft den PHP-Archivierungs-Worker in einer Schleife mit `--once` auf. Die
gesamte Fachlogik (Exchange, Archivformat, Datenbank) liegt in den
PHP-Diensten; dieser Supervisor kuemmert sich nur um:

* das Intervall zwischen den Durchlaeufen (ARCHIVE_POLL_INTERVAL, Sekunden;
  der PHP-Worker liest sein eigentliches Richtlinien-Intervall aus der
  Konfiguration in der Datenbank),
* exponentielles Backoff nach Fehlern (60 s bis 30 min), damit ein kaputter
  Zustand weder die Datenbank noch Exchange mit Anfragen flutet,
* sauberes Beenden bei SIGTERM/SIGINT (docker stop): ein laufender Durchlauf
  wird nicht abgebrochen - der Journalmechanismus des Archivs verkraftet zwar
  harte Abbrueche verlustfrei, ein geordnetes Ende vermeidet aber unnoetige
  Wiederaufnahme-Arbeit.
"""

import os
import signal
import subprocess
import sys
import time

WORKER_CMD = ["php", "scripts/orvanta_archive_worker.php", "--once"]
WORKDIR = "/var/www/html"
MIN_BACKOFF = 60
MAX_BACKOFF = 1800

_stop = False


def _request_stop(signum, frame):  # noqa: ARG001 - Signatur von signal vorgegeben
    global _stop
    _stop = True
    print(f"mail-archive: Signal {signum} empfangen, beende nach dem laufenden Durchlauf.", flush=True)


def _interval() -> int:
    try:
        value = int(os.environ.get("ARCHIVE_POLL_INTERVAL", "3600"))
    except ValueError:
        value = 3600
    return max(60, min(86400, value))


def _sleep(seconds: int) -> None:
    """Schlafen in kleinen Schritten, damit SIGTERM zuegig wirkt."""
    deadline = time.monotonic() + seconds
    while not _stop and time.monotonic() < deadline:
        time.sleep(min(5, max(0.1, deadline - time.monotonic())))


def main() -> int:
    signal.signal(signal.SIGTERM, _request_stop)
    signal.signal(signal.SIGINT, _request_stop)
    backoff = MIN_BACKOFF
    print("mail-archive: Supervisor gestartet.", flush=True)
    while not _stop:
        try:
            result = subprocess.run(WORKER_CMD, cwd=WORKDIR, check=False)
            code = result.returncode
        except OSError as error:
            print(f"mail-archive: Worker-Start fehlgeschlagen: {error}", file=sys.stderr, flush=True)
            code = 1
        if code == 0:
            backoff = MIN_BACKOFF
            _sleep(_interval())
        else:
            print(f"mail-archive: Worker endete mit Status {code}, nächster Versuch in {backoff} s.", file=sys.stderr, flush=True)
            _sleep(backoff)
            backoff = min(MAX_BACKOFF, backoff * 2)
    print("mail-archive: Supervisor beendet.", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
