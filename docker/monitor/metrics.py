#!/usr/bin/env python3
"""Kennzahlen der uebrigen Container fuer die Kacheln auf dem Admin-Dashboard.

Anders als der auth-Container kann sich diese Messung nicht selbst
durchfuehren: app, db, mail-proxy, nextcloud und eurooffice bringen kein
Messskript mit. Dieser Container liest die Werte deshalb ueber den (nur lesend
gemounteten) Docker-Socket aus der Docker-Engine - dieselben Zahlen, die
"docker stats" zeigt.

Je Container und Messfenster:

  service      Kennung des Containers aus docker-compose.yml (app, db,
               mail-proxy, nextcloud, eurooffice); die Anwendung kennt nur
               diese Kennungen
  cpu_percent  CPU-Auslastung bezogen auf die Bezugsgroesse (0-100)
  cpu_limit    Bezugsgroesse der CPU-Messung in Kernen: die zugewiesene
               Obergrenze des Containers (NanoCpus bzw. cpu_quota/cpu_period),
               ohne Obergrenze die Kerne des Hosts
  cpu_limited  1 = Bezugsgroesse ist die Obergrenze des Containers, 0 = ohne
               Obergrenze gelten die Kerne des Hosts
  ram_percent  Arbeitsspeicher-Auslastung bezogen auf die Obergrenze des
               Containers (memory.max; ohne Obergrenze der Arbeitsspeicher des
               Hosts). Belegt ist der Verbrauch abzueglich des Dateicaches, der
               ohne Bedarf freigegeben wuerde (inactive_file) - derselbe Wert
               wie in "docker stats".
  ram_used     belegter Arbeitsspeicher in Byte
  ram_total    Bezugsgroesse in Byte

Eine Momentaufnahme waere hier unbrauchbar: die CPU-Auslastung entsteht aus der
Differenz der verbrauchten CPU-Zeit zwischen zwei Proben. Beim Start wird
deshalb zuerst eine Basislinie gelesen und FIRST_WINDOW Sekunden gewartet.

Container, die nicht laufen (nextcloud und eurooffice gehoeren zum Profil
"office" von docker-compose.yml), werden uebersprungen; die Anwendung zeigt
fuer sie den Hinweis, dass noch keine Messwerte eingegangen sind.

Jede Probe geht an POST /internal/container-metrics der Anwendung (gemeinsames
Token aus dem Volume sso_token, Header X-Intranet-Sso-Token).

Aufruf:
  metrics.py loop           Endlosschleife (vom Container gestartet)
  metrics.py once           eine Probe mit kurzem Messfenster (Diagnose)
  metrics.py --healthcheck  0, wenn der Socket erreichbar ist und zuletzt
                            gemessen wurde

Umgebung:
  MONITOR_METRICS_URL    Ziel (Standard http://app/internal/container-metrics)
  SSO_CONFIG_TOKEN_FILE  Token-Datei (Standard /run/intranet-sso/token)
  MONITOR_SOCKET         Docker-Socket (Standard /var/run/docker.sock)
  MONITOR_INTERVAL       Abstand der Proben in Sekunden (Standard 60)
  MONITOR_SERVICES       Kennungen der Container, mit Komma getrennt
"""

from __future__ import annotations

import http.client
import json
import os
import re
import socket
import sys
import time
import urllib.parse
import urllib.request

URL_DEFAULT = "http://app/internal/container-metrics"
TOKEN_FILE_DEFAULT = "/run/intranet-sso/token"
SOCKET_DEFAULT = "/var/run/docker.sock"
SERVICES_DEFAULT = "app,db,mail-proxy,nextcloud,eurooffice"
INTERVAL_DEFAULT = 60

# Erste Probe kurz nach dem Start, damit das Dashboard nicht eine Minute leer
# bleibt.
FIRST_WINDOW = 15

# Messfenster von "once" (Diagnose).
ONCE_WINDOW = 2

# Zeitlimit fuer die Abfrage der Docker-Engine und die Meldung an die
# Anwendung (Sekunden).
TIMEOUT = 10

# Zeitstempel der letzten abgeschlossenen Messung (Lebenszeichen des Loops fuer
# den Healthcheck des Containers).
STATE_PATH = "/tmp/monitor-state"

# Docker meldet ohne Obergrenze einen riesigen Platzhalterwert; alles darueber
# gilt als "keine Obergrenze" (dann ist der Arbeitsspeicher des Hosts die
# Bezugsgroesse, wie bei "docker stats").
NO_LIMIT_BYTES = 1 << 60

# Label, mit dem Compose seine Container versieht.
SERVICE_LABEL = "com.docker.compose.service"
PROJECT_LABEL = "com.docker.compose.project"

# Dateicache des Containers, der ohne Bedarf freigegeben wuerde und damit nicht
# als Verbrauch zaehlt (cgroup v2: inactive_file, cgroup v1: total_inactive_file).
INACTIVE_FILES = ("inactive_file", "total_inactive_file")


def log(message: str) -> None:
    print(f"[monitor] {message}", flush=True)


def warn(message: str) -> None:
    print(f"[monitor] WARNUNG: {message}", file=sys.stderr, flush=True)


class UnixConnection(http.client.HTTPConnection):
    """HTTP ueber den Unix-Socket der Docker-Engine."""

    def __init__(self, path: str, timeout: float) -> None:
        super().__init__("localhost", timeout=timeout)
        self._path = path

    def connect(self) -> None:
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self._path)


class Docker:
    """Lesezugriffe auf die Docker-Engine (Socket ist nur lesend gemountet)."""

    def __init__(self, path: str, timeout: float = TIMEOUT) -> None:
        self._path = path
        self._timeout = timeout
        self._limits: dict[str, float] = {}
        self._host_memory: int | None = None

    def get(self, path: str) -> object:
        connection = UnixConnection(self._path, self._timeout)
        try:
            connection.request("GET", path)
            response = connection.getresponse()
            body = response.read()
            if response.status != 200:
                raise RuntimeError(f"HTTP {response.status} auf {path}")
            return json.loads(body)
        finally:
            connection.close()

    def project(self) -> str:
        """Projektname des eigenen Compose-Stacks.

        Ohne ihn koennte ein gleichnamiger Dienst eines anderen Stacks auf
        demselben Host verwechselt werden. Der Name steht als Label am eigenen
        Container; sein Hostname ist die Kennung des Containers.
        """
        hostname = socket.gethostname()
        if re.fullmatch(r"[0-9a-f]{12,64}", hostname) is None:
            return ""

        try:
            info = self.get(f"/containers/{hostname}/json")
        except Exception as error:  # noqa: BLE001 - ohne Label wird weiter gemessen
            warn(f"Eigener Container nicht lesbar ({error}); es gelten alle Container mit dem Dienstnamen.")
            return ""

        config = info.get("Config") if isinstance(info, dict) else None
        labels = config.get("Labels") if isinstance(config, dict) else None
        if not isinstance(labels, dict):
            return ""

        return str(labels.get(PROJECT_LABEL) or "")

    def container(self, service: str, project: str) -> str | None:
        """Kennung des laufenden Containers eines Dienstes (None: laeuft nicht)."""
        labels = [f"{SERVICE_LABEL}={service}"]
        if project != "":
            labels.append(f"{PROJECT_LABEL}={project}")

        query = urllib.parse.urlencode({"filters": json.dumps({"label": labels})})
        found = self.get(f"/containers/json?{query}")
        if not isinstance(found, list) or found == []:
            return None

        first = found[0]
        if not isinstance(first, dict):
            return None

        return str(first.get("Id") or "") or None

    def cpu_limit(self, container_id: str) -> float:
        """Zugewiesene CPU-Obergrenze des Containers in Kernen (0 = keine)."""
        if container_id in self._limits:
            return self._limits[container_id]

        info = self.get(f"/containers/{container_id}/json")
        host = info.get("HostConfig") if isinstance(info, dict) else None
        host = host if isinstance(host, dict) else {}

        nanocpus = host.get("NanoCpus") or 0
        quota = host.get("CpuQuota") or 0
        period = host.get("CpuPeriod") or 0

        limit = 0.0
        if isinstance(nanocpus, int) and nanocpus > 0:
            limit = nanocpus / 1_000_000_000
        elif isinstance(quota, int) and isinstance(period, int) and quota > 0 and period > 0:
            limit = quota / period

        self._limits[container_id] = limit

        return limit

    def stats(self, container_id: str) -> dict:
        """Eine Probe der Laufzeitwerte (ohne Warten auf einen zweiten Umlauf)."""
        stats = self.get(f"/containers/{container_id}/stats?stream=false&one-shot=true")

        return stats if isinstance(stats, dict) else {}

    def host_memory(self) -> int | None:
        """Arbeitsspeicher des Hosts in Byte (Bezugsgroesse ohne Obergrenze)."""
        if self._host_memory is not None:
            return self._host_memory

        info = self.get("/info")
        total = info.get("MemTotal") if isinstance(info, dict) else None
        self._host_memory = int(total) if isinstance(total, int) and total > 0 else None

        return self._host_memory


def cpu_totals(stats: dict) -> tuple[float, float] | None:
    """Verbrauchte CPU-Zeit und Systemzeit der Probe in Nanosekunden."""
    cpu = stats.get("cpu_stats")
    if not isinstance(cpu, dict):
        return None

    usage = cpu.get("cpu_usage")
    used = usage.get("total_usage") if isinstance(usage, dict) else None
    system = cpu.get("system_cpu_usage")
    if not isinstance(used, (int, float)) or not isinstance(system, (int, float)):
        return None

    return float(used), float(system)


def cpu_count(stats: dict) -> int:
    """Kerne des Hosts; sie sind die Bezugsgroesse ohne CPU-Obergrenze."""
    cpu = stats.get("cpu_stats")
    cpu = cpu if isinstance(cpu, dict) else {}

    online = cpu.get("online_cpus")
    if isinstance(online, int) and online > 0:
        return online

    usage = cpu.get("cpu_usage")
    per_cpu = usage.get("percpu_usage") if isinstance(usage, dict) else None
    if isinstance(per_cpu, list) and per_cpu:
        return len(per_cpu)

    return 1


def cpu_values(stats: dict, previous: dict, limit: float) -> tuple[float, float, bool]:
    """CPU-Auslastung in Prozent, Bezugsgroesse in Kernen und ihre Herkunft.

    Ohne Obergrenze sind die Kerne des Hosts die Bezugsgroesse; die Auslastung
    ist dann der Anteil des Hosts, den der Container belegt (wie "docker
    stats"). Mit Obergrenze ist die Auslastung der Anteil, den der Container
    von seiner Obergrenze verbraucht.
    """
    limited = limit > 0
    reference = limit if limited else float(cpu_count(stats))
    current = cpu_totals(stats)
    before = cpu_totals(previous)

    if current is None or before is None or reference <= 0:
        return 0.0, reference, limited

    used = current[0] - before[0]
    system = current[1] - before[1]
    if system <= 0:
        return 0.0, reference, limited

    cores = max(0.0, used) / system * float(cpu_count(stats))

    return min(100.0, cores / reference * 100), reference, limited


def memory_values(stats: dict, host_total: int | None) -> tuple[int, int] | None:
    """Belegter Arbeitsspeicher und Bezugsgroesse in Byte (None: nicht lesbar)."""
    memory = stats.get("memory_stats")
    if not isinstance(memory, dict):
        return None

    used = memory.get("usage")
    if not isinstance(used, (int, float)) or used < 0:
        return None

    used = float(used)
    details = memory.get("stats")
    if isinstance(details, dict):
        for key in INACTIVE_FILES:
            inactive = details.get(key)
            if isinstance(inactive, (int, float)) and inactive > 0:
                used = max(0.0, used - float(inactive))
                break

    limit = memory.get("limit")
    total = limit if isinstance(limit, (int, float)) and 0 < limit < NO_LIMIT_BYTES else host_total
    if not isinstance(total, (int, float)) or total <= 0:
        return None

    return int(used), int(total)


def sample(docker: Docker, services: list[str], project: str) -> dict[str, tuple[str, dict, float]]:
    """Rohwerte je laufendem Container: Kennung, Probe und CPU-Obergrenze."""
    values: dict[str, tuple[str, dict, float]] = {}
    for service in services:
        try:
            container_id = docker.container(service, project)
            if container_id is None:
                continue

            values[service] = (container_id, docker.stats(container_id), docker.cpu_limit(container_id))
        except Exception as error:  # noqa: BLE001 - die Schleife darf nie enden
            warn(f"Container {service} nicht messbar: {error}")

    return values


def measure(
    docker: Docker,
    services: list[str],
    project: str,
    snapshots: dict[str, tuple[str, dict]],
    window: int,
) -> tuple[dict[str, dict[str, object]], dict[str, tuple[str, dict]]]:
    """Eine Probe je Dienst; ohne vorherige Messung wird `window` Sekunden gemessen."""
    if snapshots == {}:
        snapshots = {
            service: (values[0], values[1].get("cpu_stats") or {})
            for service, values in sample(docker, services, project).items()
        }
        time.sleep(window)

    current = sample(docker, services, project)
    values: dict[str, dict[str, object]] = {}
    following: dict[str, tuple[str, dict]] = {}

    for service, (container_id, stats, limit) in current.items():
        previous = snapshots.get(service)
        if previous is None or previous[0] != container_id:
            # Neuer Container (Neustart): die Engine liefert die vorherige Probe.
            previous = (container_id, stats.get("precpu_stats") or {})

        percent, reference, limited = cpu_values(stats, previous[1], limit)
        values[service] = {
            "percent": percent,
            "limit": reference,
            "limited": limited,
            "memory": memory_values(stats, docker.host_memory()),
        }
        following[service] = (container_id, stats.get("cpu_stats") or {})

    return values, following


def payload(service: str, values: dict[str, object]) -> dict[str, str]:
    """Formularfelder der Meldung.

    Ohne lesbaren Arbeitsspeicher entfallen ram_percent, ram_used und
    ram_total; die Anwendung zeigt dann nur die CPU-Auslastung.
    """
    fields = {
        "service": service,
        "cpu_percent": f"{float(values['percent']):.2f}",
        "cpu_limit": f"{float(values['limit']):.2f}",
        "cpu_limited": "1" if values["limited"] else "0",
    }

    memory = values.get("memory")
    if isinstance(memory, tuple) and memory[1] > 0:
        used, total = memory
        fields["ram_percent"] = f"{used / total * 100:.2f}"
        fields["ram_used"] = str(used)
        fields["ram_total"] = str(total)

    return fields


def read_file(path: str) -> str:
    try:
        with open(path, "r", encoding="ascii", errors="replace") as handle:
            return handle.read()
    except OSError:
        return ""


def read_token(path: str) -> str:
    token = read_file(path).strip()

    return token if len(token) >= 32 else ""


def human_bytes(value: int) -> str:
    """Groesse in Byte lesbar, z. B. 1610612736 => "1.5 GiB"."""
    units = ("B", "KiB", "MiB", "GiB", "TiB")
    size = float(max(0, value))
    index = 0
    while size >= 1024 and index < len(units) - 1:
        size /= 1024
        index += 1

    return f"{size:.0f} {units[index]}" if index == 0 else f"{size:.1f} {units[index]}"


def describe(values: dict[str, object]) -> str:
    reference = "Obergrenze" if values["limited"] else "Host"
    text = f"CPU {float(values['percent']):.2f} % von {float(values['limit']):.2f} Kern(en) ({reference})"

    memory = values.get("memory")
    if isinstance(memory, tuple) and memory[1] > 0:
        used, total = memory
        text += (
            f", Arbeitsspeicher {used / total * 100:.2f} % "
            f"({human_bytes(used)} von {human_bytes(total)})"
        )

    return text


def report(url: str, token: str, fields: dict[str, str]) -> int:
    request = urllib.request.Request(
        url,
        data=urllib.parse.urlencode(fields).encode("ascii"),
        method="POST",
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Intranet-Sso-Token": token,
            "User-Agent": "intranet-container-metrics/1.0",
        },
    )
    with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
        return int(response.status)


def send(url: str, token_file: str, service: str, values: dict[str, object]) -> bool:
    token = read_token(token_file)
    if token == "":
        warn(f"Token aus {token_file} fehlt oder ist zu kurz; Meldung uebersprungen.")

        return False

    try:
        status = report(url, token, payload(service, values))
    except Exception as error:  # noqa: BLE001 - die Schleife darf nie enden
        warn(f"Meldung der Kennzahlen von {service} fehlgeschlagen: {error}")

        return False

    log(f"Kennzahlen gemeldet (HTTP {status}): {service} - {describe(values)}.")

    return True


def touch(path: str) -> None:
    """Zeitstempel der letzten abgeschlossenen Messung aktualisieren."""
    try:
        with open(path, "w", encoding="ascii"):
            pass
    except OSError:
        pass


def run_once(url: str, token_file: str, socket_path: str, services: list[str]) -> int:
    docker = Docker(socket_path)
    values, _ = measure(docker, services, docker.project(), {}, ONCE_WINDOW)

    if values == {}:
        warn("Kein laufender Container gefunden.")

        return 1

    success = True
    for service, sample_values in values.items():
        if not send(url, token_file, service, sample_values):
            success = False

    return 0 if success else 1


def run_loop(url: str, token_file: str, socket_path: str, services: list[str], interval: int) -> int:
    docker = Docker(socket_path)
    project = docker.project()
    snapshots: dict[str, tuple[str, dict]] = {}
    window = FIRST_WINDOW
    log(f"Kennzahlen aktiv: alle {interval} s an {url} ({', '.join(services)}).")

    while True:
        try:
            values, snapshots = measure(docker, services, project, snapshots, window)
        except Exception as error:  # noqa: BLE001 - die Schleife darf nie enden
            warn(f"Messung fehlgeschlagen: {error}")
            time.sleep(interval)
            continue

        for service, sample_values in values.items():
            send(url, token_file, service, sample_values)

        touch(STATE_PATH)
        window = interval
        time.sleep(interval)


def healthcheck(socket_path: str, state_path: str, interval: int) -> int:
    """0, wenn der Docker-Socket erreichbar ist und zuletzt gemessen wurde."""
    if not os.path.exists(socket_path):
        warn(f"Docker-Socket {socket_path} fehlt.")

        return 1

    try:
        age = time.time() - os.stat(state_path).st_mtime
    except OSError:
        warn(f"Noch keine abgeschlossene Messung ({state_path} fehlt).")

        return 1

    limit = max(180, interval * 3)
    if age > limit:
        warn(f"Letzte Messung vor {age:.0f} s (Grenze {limit} s).")

        return 1

    return 0


def positive_int(value: str, fallback: int) -> int:
    return int(value) if value.isdigit() and int(value) > 0 else fallback


def service_list(raw: str) -> list[str]:
    names = [name.strip() for name in raw.split(",") if name.strip() != ""]

    return names if names != [] else [name for name in SERVICES_DEFAULT.split(",") if name != ""]


def main(argv: list[str]) -> int:
    mode = argv[1] if len(argv) > 1 else "loop"
    url = os.environ.get("MONITOR_METRICS_URL", URL_DEFAULT)
    token_file = os.environ.get("SSO_CONFIG_TOKEN_FILE", TOKEN_FILE_DEFAULT)
    socket_path = os.environ.get("MONITOR_SOCKET", SOCKET_DEFAULT)
    interval = positive_int(os.environ.get("MONITOR_INTERVAL", ""), INTERVAL_DEFAULT)
    services = service_list(os.environ.get("MONITOR_SERVICES", SERVICES_DEFAULT))

    if mode in ("--healthcheck", "healthcheck"):
        return healthcheck(socket_path, STATE_PATH, interval)

    if mode == "once":
        return run_once(url, token_file, socket_path, services)

    if mode != "loop":
        print(__doc__, file=sys.stderr)

        return 2

    return run_loop(url, token_file, socket_path, services, interval)


if __name__ == "__main__":
    sys.exit(main(sys.argv))
