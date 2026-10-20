FROM python:3.12-slim

# Nur Python-Standardbibliothek (smtplib/email) – keine zusätzlichen Pakete nötig.
# "mail" ist im Debian-Basisimage bereits als Systembenutzer reserviert, daher "mailsvc".
RUN useradd --system --create-home --uid 10002 mailsvc
WORKDIR /app
COPY docker/mail/server.py /app/server.py
COPY docker/mail/healthcheck.py /app/healthcheck.py
USER mailsvc

EXPOSE 8025
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD ["python", "/app/healthcheck.py"]
CMD ["python", "/app/server.py"]
