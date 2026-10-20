"""
Minimaler E-Mail-Versanddienst für KLINQ (Entnahme-/Retourennachweise,
Übergabeprotokolle). Kapselt die SMTP-Anbindung an einen echten Mailserver, damit die
PHP-Anwendung selbst keine SMTP-Zugangsdaten verwalten muss (analog zum "pdf"-Dienst).

Endpunkte:
  GET  /health → 200 "ok", wenn ein SMTP-Server konfiguriert ist, sonst 503
  POST /send   → Body: JSON {"to": "...", "subject": "...", "html": "...", "text": "...",
                              "headers": {"Message-ID": "...", "In-Reply-To": "...", "References": "..."},
                              "attachments": [{"filename": "...", "content_base64": "...", "mime_type": "..."}]}
                 Antwort: 200 {"ok": true} bei Erfolg, sonst 4xx/5xx mit {"ok": false, "error": "..."}

Konfiguration ausschließlich über Umgebungsvariablen (siehe .env.example MAIL_*/SMTP_*):
  SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION (none|starttls|ssl), SMTP_USERNAME, SMTP_PASSWORD,
  SMTP_FROM_ADDRESS, SMTP_FROM_NAME, SMTP_TIMEOUT
"""
import base64
import json
import os
import re
import smtplib
import ssl
import sys
from email.message import EmailMessage
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MAX_BODY = int(os.environ.get("MAIL_MAX_BODY_BYTES", str(16 * 1024 * 1024)))
PORT = int(os.environ.get("MAIL_PORT", "8025"))

SMTP_HOST = os.environ.get("SMTP_HOST", "").strip()
SMTP_PORT = int(os.environ.get("SMTP_PORT", "587") or "587")
SMTP_ENCRYPTION = os.environ.get("SMTP_ENCRYPTION", "starttls").strip().lower()
SMTP_USERNAME = os.environ.get("SMTP_USERNAME", "").strip()
SMTP_PASSWORD = os.environ.get("SMTP_PASSWORD", "")
SMTP_TIMEOUT = int(os.environ.get("SMTP_TIMEOUT", "10") or "10")
SMTP_FROM_ADDRESS = os.environ.get("SMTP_FROM_ADDRESS", "").strip()
SMTP_FROM_NAME = os.environ.get("SMTP_FROM_NAME", "").strip()

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


class ConfigError(Exception):
    pass


def build_message(payload):
    to_addr = str(payload.get("to", "")).strip()
    if not EMAIL_RE.match(to_addr):
        raise ValueError("invalid or missing 'to' address")
    subject = str(payload.get("subject", "")).strip()
    if not subject:
        raise ValueError("missing 'subject'")
    html = payload.get("html")
    text = payload.get("text")
    if not html and not text:
        raise ValueError("either 'html' or 'text' is required")

    msg = EmailMessage()
    from_addr = SMTP_FROM_ADDRESS or SMTP_USERNAME
    if not from_addr:
        raise ConfigError("no SMTP_FROM_ADDRESS configured")
    msg["From"] = "%s <%s>" % (SMTP_FROM_NAME, from_addr) if SMTP_FROM_NAME else from_addr
    msg["To"] = to_addr
    msg["Subject"] = subject
    # Optionale Kopfzeilen für Threading/Auto-Reply-Kennzeichnung (Whitelist, keine Zeilenumbrüche)
    for name in ("Message-ID", "In-Reply-To", "References", "Reply-To", "Auto-Submitted", "X-Ticket-Number"):
        value = str((payload.get("headers") or {}).get(name) or "").strip()
        if value and "\n" not in value and "\r" not in value:
            msg[name] = value
    msg.set_content(text or "Bitte verwenden Sie einen HTML-fähigen E-Mail-Client, um diese Nachricht anzuzeigen.")
    if html:
        msg.add_alternative(html, subtype="html")

    for attachment in payload.get("attachments") or []:
        filename = str(attachment.get("filename") or "anhang.pdf")
        mime_type = str(attachment.get("mime_type") or "application/octet-stream")
        maintype, _, subtype = mime_type.partition("/")
        content = base64.b64decode(str(attachment.get("content_base64") or ""))
        msg.add_attachment(content, maintype=maintype or "application", subtype=subtype or "octet-stream", filename=filename)

    return msg


def send_message(msg):
    if not SMTP_HOST:
        raise ConfigError("no SMTP_HOST configured")

    if SMTP_ENCRYPTION == "ssl":
        server = smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT, timeout=SMTP_TIMEOUT, context=ssl.create_default_context())
    else:
        server = smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=SMTP_TIMEOUT)
    try:
        server.ehlo()
        if SMTP_ENCRYPTION == "starttls":
            server.starttls(context=ssl.create_default_context())
            server.ehlo()
        if SMTP_USERNAME:
            server.login(SMTP_USERNAME, SMTP_PASSWORD)
        server.send_message(msg)
    finally:
        try:
            server.quit()
        except Exception:  # noqa: BLE001 – Verbindung ist ohnehin am Ende
            pass


class Handler(BaseHTTPRequestHandler):
    server_version = "assets-mail/1.0"

    def log_message(self, fmt, *args):  # knappe Logs auf stdout
        sys.stdout.write("%s - %s\n" % (self.address_string(), fmt % args))
        sys.stdout.flush()

    def _send_json(self, status, data):
        body = json.dumps(data).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            if SMTP_HOST:
                self._send_json(200, {"ok": True})
            else:
                self._send_json(503, {"ok": False, "error": "smtp not configured"})
        else:
            self._send_json(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        if self.path != "/send":
            self._send_json(404, {"ok": False, "error": "not found"})
            return
        length = int(self.headers.get("Content-Length") or 0)
        if length <= 0 or length > MAX_BODY:
            self._send_json(413, {"ok": False, "error": "body missing or too large"})
            return
        raw = self.rfile.read(length)
        try:
            payload = json.loads(raw.decode("utf-8"))
            msg = build_message(payload)
            send_message(msg)
        except ConfigError as exc:
            self._send_json(503, {"ok": False, "error": str(exc)})
            return
        except (ValueError, TypeError, KeyError) as exc:
            self._send_json(400, {"ok": False, "error": str(exc)})
            return
        except smtplib.SMTPException as exc:
            self._send_json(502, {"ok": False, "error": "smtp error: %s" % exc})
            return
        except OSError as exc:
            self._send_json(502, {"ok": False, "error": "connection error: %s" % exc})
            return
        self._send_json(200, {"ok": True})


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    sys.stdout.write("assets-mail listening on :%d (smtp=%s)\n" % (PORT, SMTP_HOST or "not configured"))
    sys.stdout.flush()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
