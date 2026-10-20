"""
Minimaler HTML→PDF-Dienst für KLINQ (Übergabeprotokolle).

Endpunkte:
  GET  /health   → 200 "ok"
  POST /render   → Body: vollständiges HTML (UTF-8), Antwort: application/pdf

Der Dienst ist nur im Docker-Netz erreichbar und lädt keine externen Ressourcen
(url_fetcher blockiert alles außer data:-URIs).
"""
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from weasyprint import HTML
from weasyprint.urls import default_url_fetcher

MAX_BODY = int(os.environ.get("PDF_MAX_BODY_BYTES", str(8 * 1024 * 1024)))
PORT = int(os.environ.get("PDF_PORT", "8000"))


def safe_url_fetcher(url, *args, **kwargs):
    # Nur eingebettete Bilder (Unterschrift als data:-URI) zulassen, keine Netzwerkzugriffe
    if url.startswith("data:"):
        return default_url_fetcher(url, *args, **kwargs)
    raise ValueError("Externe Ressourcen sind nicht erlaubt: " + url[:80])


class Handler(BaseHTTPRequestHandler):
    server_version = "assets-pdf/1.0"

    def log_message(self, fmt, *args):  # knappe Logs auf stdout
        sys.stdout.write("%s - %s\n" % (self.address_string(), fmt % args))
        sys.stdout.flush()

    def _send(self, status, body, content_type="text/plain; charset=utf-8"):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            self._send(200, b"ok")
        else:
            self._send(404, b"not found")

    def do_POST(self):
        if self.path != "/render":
            self._send(404, b"not found")
            return
        length = int(self.headers.get("Content-Length") or 0)
        if length <= 0 or length > MAX_BODY:
            self._send(413, b"body missing or too large")
            return
        html = self.rfile.read(length).decode("utf-8", errors="replace")
        try:
            pdf = HTML(string=html, base_url=None, url_fetcher=safe_url_fetcher).write_pdf()
        except Exception as exc:  # noqa: BLE001 – Fehler an den Aufrufer melden
            self._send(500, ("render error: %s" % exc).encode("utf-8"))
            return
        self._send(200, pdf, "application/pdf")


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    sys.stdout.write("assets-pdf listening on :%d\n" % PORT)
    sys.stdout.flush()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
