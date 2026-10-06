# syntax=docker/dockerfile:1
# mail-proxy: SMTP-/IMAP-Proxy fuer Orvanta-Benutzer ohne Exchange-Postfach.
# Reiner Python-Standardbibliothek-Dienst (mail_proxy.py, keine pip-Pakete):
# nimmt ausschliesslich HMAC-signierte Anfragen des app-Containers im
# internen Netz "mail_proxy" entgegen und spricht IMAP/SMTP mit den in der
# Verwaltung hinterlegten Mailservern. Keine Datenbank, keine gespeicherten
# Zugangsdaten – diese kommen pro Anfrage vom app-Container (siehe
# docs/mail-proxy.md).
FROM python:3.13-slim

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates; \
    rm -rf /var/lib/apt/lists/*

COPY docker/mail-proxy/mail_proxy.py /app/mail_proxy.py

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MAIL_PROXY_PORT=8025 \
    MAIL_PROXY_KEY_FILE=/run/mail-proxy/keys/secrets.key

# Gleiche UID wie www-data im app-Container: nur so ist der SecretBox-
# Schluessel (storage/keys, 0700/0600) lesbar.
USER 33:33

# Nur im internen Docker-Netz erreichbar (keine Portfreigabe in Compose).
EXPOSE 8025

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["python3", "/app/mail_proxy.py", "--healthcheck"]

ENTRYPOINT ["python3", "/app/mail_proxy.py"]
