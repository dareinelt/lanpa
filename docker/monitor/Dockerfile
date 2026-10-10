# syntax=docker/dockerfile:1
# Sammel-Container fuer die Kennzahlen der uebrigen Container (CPU und
# Arbeitsspeicher) auf dem Admin-Dashboard.
#
# Reiner Python-Standardbibliothek-Dienst (metrics.py, keine pip-Pakete): liest
# die Werte ueber den read-only gemounteten Docker-Socket aus der Docker-Engine
# (dieselben Zahlen wie "docker stats") und meldet sie an
# POST /internal/container-metrics der Anwendung (gemeinsames Token aus dem
# Volume sso_token).
#
# Der Container laeuft als root, weil der Docker-Socket nur fuer root bzw. die
# Gruppe docker zugaenglich ist (wie beim snmp-Container). Er haelt keine
# Zugangsdaten und ist nur im internen Netz erreichbar; der Socket entspricht
# weitgehenden Rechten auf dem Host - siehe docs/installation.md.
FROM python:3.13-slim

COPY docker/monitor/metrics.py /app/metrics.py

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MONITOR_METRICS_URL=http://app/internal/container-metrics \
    MONITOR_SOCKET=/var/run/docker.sock \
    MONITOR_INTERVAL=60 \
    MONITOR_SERVICES=app,db,mail-proxy,nextcloud,eurooffice \
    SSO_CONFIG_TOKEN_FILE=/run/intranet-sso/token

HEALTHCHECK --interval=60s --timeout=5s --start-period=30s --retries=3 \
    CMD ["python3", "/app/metrics.py", "--healthcheck"]

ENTRYPOINT ["python3", "/app/metrics.py"]
