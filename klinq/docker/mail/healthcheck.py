"""Healthcheck für den mail-Container: /health liefert 200 (SMTP konfiguriert) oder
503 (Dienst läuft, aber kein SMTP-Server hinterlegt) – beides gilt als "Container gesund"."""
import sys
import urllib.error
import urllib.request

try:
    urllib.request.urlopen("http://127.0.0.1:8025/health", timeout=3)
    code = 200
except urllib.error.HTTPError as exc:
    code = exc.code

sys.exit(0 if code in (200, 503) else 1)
