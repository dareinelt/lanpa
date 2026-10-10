#!/usr/bin/env python3
"""Kennzahlen des auth-Containers fuer die Karte auf dem Admin-Dashboard.

Gemessen wird im Container selbst (cgroup und das Zugriffsprotokoll des
Proxys); jede Probe umfasst das Messfenster seit der vorherigen Probe:

  cpu_percent  CPU-Auslastung bezogen auf das CPU-Limit des Containers
               (cpu.max bzw. cfs_quota/cfs_period; ohne Limit gilt ein Kern)
  cpu_limit    Bezugsgroesse der Messung in Kernen
  connections  im Messfenster aufgebaute Verbindungen von Clients; Quelle ist
               das Zugriffsprotokoll (LOG_PATH), das je Zeile die
               Client-Adresse und den Quellport enthaelt. Mehrere Anfragen
               einer Keep-Alive-Verbindung zaehlen dadurch nur einmal.
  sources      Anfragen je Quellnetz im Messfenster als JSON-Objekt
               (IPv4 /24, IPv6 /64, "lokal" fuer Loopback; ab MAX_SOURCES als
               "weitere"); dieselbe Quelle, nach jedem Lesen geleert

Eine Momentaufnahme offener Verbindungen waere hier unbrauchbar: Apache
schliesst eine untaetige Verbindung nach wenigen Sekunden (KeepAliveTimeout),
sodass eine Probe im Minutentakt sie fast nie antrifft. Ein Zaehler des
Betriebssystems (PassiveOpens) zaehlt ausserdem die Healthchecks des
Containers mit und stuende damit dauerhaft ueber null.

Jede Probe geht an POST /internal/auth-metrics der Anwendung (gemeinsames
Token aus dem Volume sso_token, Header X-Intranet-Sso-Token). Gespeichert
werden nur Zaehler; die Anwendung bildet daraus die Werte des Messfensters,
Spitzen und Mittel.

Aufruf:
  metrics.py loop   Endlosschleife (vom entrypoint gestartet)
  metrics.py once   eine Probe mit kurzem Messfenster (Diagnose)

Umgebung:
  AUTH_METRICS_URL       Ziel (Standard http://app/internal/auth-metrics)
  SSO_CONFIG_TOKEN_FILE  Token-Datei (Standard /run/intranet-sso/token)
  AUTH_METRICS_INTERVAL  Abstand der Proben in Sekunden (Standard 60)
"""

from __future__ import annotations

import ipaddress
import json
import os
import sys
import time
import urllib.parse
import urllib.request

URL_DEFAULT = "http://app/internal/auth-metrics"
TOKEN_FILE_DEFAULT = "/run/intranet-sso/token"
INTERVAL_DEFAULT = 60

# Erste Probe kurz nach dem Start, damit das Dashboard nicht eine Minute leer
# bleibt; sie umfasst die CPU-Zeit seit dem Start des Containers.
FIRST_WINDOW = 15

# Messfenster von "once" (Diagnose).
ONCE_WINDOW = 2

# Hoechstzahl der Quellnetze je Probe; der Rest wird als "weitere" summiert.
MAX_SOURCES = 12

# Zeitlimit fuer die Meldung an die Anwendung (Sekunden).
TIMEOUT = 10

# Zugriffsprotokoll des Proxys: eine Zeile je Anfrage mit der Client-Adresse
# und ihrem Quellport (common.conf, CustomLog nur mit -D AUTH_METRICS). Es wird
# nach jedem Lesen geleert und bleibt damit klein.
LOG_PATH = "/var/log/apache2/intranet-metrics.log"

CGROUP_USAGE_FILES = (
    "/sys/fs/cgroup/cpu.stat",
    "/sys/fs/cgroup/cpuacct/cpuacct.usage",
    "/sys/fs/cgroup/cpu/cpuacct.usage",
)


def log(message: str) -> None:
    print(f"[auth] {message}", flush=True)


def warn(message: str) -> None:
    print(f"[auth] WARNUNG: {message}", file=sys.stderr, flush=True)


def read_file(path: str) -> str:
    try:
        with open(path, "r", encoding="ascii", errors="replace") as handle:
            return handle.read()
    except OSError:
        return ""


def cpu_usage_seconds() -> float | None:
    """Verbrauchte CPU-Zeit des Containers in Sekunden (None: nicht lesbar)."""
    for path in CGROUP_USAGE_FILES:
        text = read_file(path)
        if text == "":
            continue
        if path.endswith("cpu.stat"):
            for line in text.splitlines():
                parts = line.split()
                if len(parts) == 2 and parts[0] == "usage_usec":
                    try:
                        return float(parts[1]) / 1_000_000
                    except ValueError:
                        break
            continue
        value = text.strip()
        if value.isdigit():
            return float(value) / 1_000_000_000

    return None


def cpu_limit_cores() -> float:
    """CPU-Limit des Containers in Kernen (1.0, wenn kein Limit gesetzt ist)."""
    fields = read_file("/sys/fs/cgroup/cpu.max").split()
    if len(fields) == 2 and fields[0] != "max":
        try:
            quota, period = float(fields[0]), float(fields[1])
            if quota > 0 and period > 0:
                return quota / period
        except ValueError:
            pass

    quota = read_file("/sys/fs/cgroup/cpu/cpu.cfs_quota_us").strip()
    period = read_file("/sys/fs/cgroup/cpu/cpu.cfs_period_us").strip()
    if quota.isdigit() and period.isdigit() and int(quota) > 0 and int(period) > 0:
        return int(quota) / int(period)

    return 1.0


def source_label(address: str) -> str:
    """Quellnetz einer Client-Adresse aus dem Zugriffsprotokoll."""
    try:
        host = ipaddress.ip_address(address)
    except ValueError:
        return "unbekannt"

    if host.version == 6:
        mapped = host.ipv4_mapped
        if mapped is not None:
            host = mapped

    if host.is_loopback:
        return "lokal"

    prefix = 24 if host.version == 4 else 64

    return str(ipaddress.ip_network(f"{host}/{prefix}", strict=False))


def read_log(path: str) -> list[str]:
    """Zeilen des Zugriffsprotokolls im Messfenster; die Datei wird danach geleert.

    Gelesen wird von vorne; damit sind ein Umlauf oder eine Kuerzung der Datei
    unproblematisch. Zwischen Lesen und Leeren eintreffende Zeilen gehen
    verloren (selten und fuer die Anzeige unerheblich).
    """
    try:
        with open(path, "r+", encoding="ascii", errors="replace") as handle:
            lines = handle.read()
            handle.seek(0)
            handle.truncate(0)
    except OSError:
        return []

    return lines.splitlines()


def window_traffic(path: str) -> tuple[int, dict[str, int]]:
    """Verbindungen und Anfragen je Quellnetz im Messfenster.

    Eine Zeile ist "<Client-Adresse> <Quellport>" (common.conf). Verbindungen
    werden ueber Adresse und Quellport unterschieden, sodass eine
    Keep-Alive-Verbindung mit mehreren Anfragen nur einmal zaehlt.
    """
    connections: set[tuple[str, str]] = set()
    sources: dict[str, int] = {}

    for line in read_log(path):
        fields = line.split()
        if not fields:
            continue

        address = fields[0]
        port = fields[1] if len(fields) > 1 else ""
        connections.add((address, port))
        label = source_label(address)
        sources[label] = sources.get(label, 0) + 1

    return len(connections), sources


def compact_sources(sources: dict[str, int]) -> dict[str, int]:
    """Quellnetze auf MAX_SOURCES begrenzen; der Rest wird zusammengefasst."""
    if len(sources) <= MAX_SOURCES:
        return sources

    ordered = sorted(sources.items(), key=lambda item: (-item[1], item[0]))
    kept = dict(ordered[: MAX_SOURCES - 1])
    kept["weitere"] = sum(count for _, count in ordered[MAX_SOURCES - 1 :])

    return kept


def measure(
    previous: tuple[float, float | None] | None,
    window: int,
) -> tuple[dict[str, object], tuple[float, float | None]]:
    """Eine Probe; ohne vorherige Messung wird `window` Sekunden gemessen."""
    if previous is None:
        previous = (time.monotonic(), cpu_usage_seconds())
        time.sleep(window)

    limit = cpu_limit_cores()
    usage = cpu_usage_seconds()
    now = time.monotonic()

    percent: float | None = None
    elapsed = now - previous[0]
    if usage is not None and previous[1] is not None and elapsed > 0 and limit > 0:
        percent = (usage - previous[1]) / (elapsed * limit) * 100
        percent = min(100.0, max(0.0, percent))

    connections, sources = window_traffic(LOG_PATH)

    values: dict[str, object] = {
        "percent": percent,
        "limit": limit,
        "connections": connections,
        "sources": compact_sources(sources),
    }

    return values, (now, usage if usage is not None else previous[1])


def payload(values: dict[str, object]) -> dict[str, str]:
    """Formularfelder der Meldung (sources als JSON-Objekt)."""
    percent = values["percent"]

    return {
        "cpu_percent": f"{0.0 if percent is None else float(percent):.2f}",
        "cpu_limit": f"{float(values['limit']):.2f}",
        "connections": str(int(values["connections"])),
        "sources": json.dumps(values["sources"], sort_keys=True),
    }


def read_token(path: str) -> str:
    token = read_file(path).strip()

    return token if len(token) >= 32 else ""


def report(url: str, token: str, fields: dict[str, str]) -> int:
    request = urllib.request.Request(
        url,
        data=urllib.parse.urlencode(fields).encode("ascii"),
        method="POST",
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Intranet-Sso-Token": token,
            "User-Agent": "intranet-auth-metrics/1.0",
        },
    )
    with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
        return int(response.status)


def describe(values: dict[str, object]) -> str:
    sources = values["sources"]
    requests = sum(sources.values()) if isinstance(sources, dict) else 0
    percent = 0.0 if values["percent"] is None else float(values["percent"])

    return (
        f"CPU {percent:.2f} % von {float(values['limit']):.2f} Kern(en), "
        f"{int(values['connections'])} Verbindungen und {requests} Anfragen im Messfenster"
    )


def send(url: str, token_file: str, values: dict[str, object]) -> bool:
    token = read_token(token_file)
    if token == "":
        warn(f"Token aus {token_file} fehlt oder ist zu kurz; Meldung uebersprungen.")

        return False

    fields = payload(values)
    try:
        status = report(url, token, fields)
    except Exception as error:  # noqa: BLE001 - die Schleife darf nie enden
        warn(f"Meldung der Kennzahlen fehlgeschlagen: {error}")

        return False

    log(f"Kennzahlen gemeldet (HTTP {status}): {describe(values)}.")

    return True


def run_once(url: str, token_file: str) -> int:
    values, _ = measure(None, ONCE_WINDOW)

    return 0 if send(url, token_file, values) else 1


def run_loop(url: str, token_file: str, interval: int) -> int:
    previous = None
    window = FIRST_WINDOW
    log(f"Kennzahlen aktiv: alle {interval} s an {url}.")

    while True:
        try:
            values, previous = measure(previous, window)
        except Exception as error:  # noqa: BLE001 - die Schleife darf nie enden
            warn(f"Messung fehlgeschlagen: {error}")
            time.sleep(interval)
            continue

        if values["percent"] is None:
            warn("CPU-Zeit des Containers nicht lesbar (cgroup nicht eingebunden); Auslastung wird als 0 gemeldet.")

        send(url, token_file, values)
        window = interval
        time.sleep(interval)


def positive_int(value: str, fallback: int) -> int:
    return int(value) if value.isdigit() and int(value) > 0 else fallback


def main(argv: list[str]) -> int:
    mode = argv[1] if len(argv) > 1 else "loop"
    url = os.environ.get("AUTH_METRICS_URL", URL_DEFAULT)
    token_file = os.environ.get("SSO_CONFIG_TOKEN_FILE", TOKEN_FILE_DEFAULT)
    interval = positive_int(os.environ.get("AUTH_METRICS_INTERVAL", ""), INTERVAL_DEFAULT)

    if mode == "once":
        return run_once(url, token_file)

    if mode != "loop":
        print(__doc__, file=sys.stderr)

        return 2

    return run_loop(url, token_file, interval)


if __name__ == "__main__":
    sys.exit(main(sys.argv))
