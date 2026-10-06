#!/usr/bin/env python3
"""mail-proxy: SMTP-/IMAP-Proxy fuer Orvanta (Benutzer ohne Exchange-Postfach).

Interner HTTP-Dienst (nur Docker-Netz ``mail_proxy``) zwischen der PHP-
Anwendung und den extern konfigurierten Mailservern:

    PHP-App --(signierte JSON-Anfrage)--> mail-proxy --IMAP--> Postfach
                                                  \\--SMTP--> Versand

* Nur Python-Standardbibliothek (http.server, imaplib, smtplib, ssl, email).
* Jede Anfrage ist mit HMAC-SHA256 signiert (Zeitstempel, Einmalwert,
  Operation, SHA-256 des Rumpfs). Der Schluessel wird wie in PHP aus dem
  SecretBox-Schluessel abgeleitet (``hash_hmac('sha256', 'mail-proxy', key)``);
  die Schluesseldatei ist schreibgeschuetzt eingebunden.
* Zugangsdaten kommen pro Anfrage aus der (verschluesselt gespeicherten)
  Konfiguration der PHP-App, liegen nur im Arbeitsspeicher und werden nie
  protokolliert. Der IMAP-Verbindungspool haelt nur einen SHA-256-Kennwert
  der Verbindungsdaten (inkl. Konfigurationsgeneration).
* TLS-Zertifikate werden standardmaessig geprueft; Ziele werden aufgeloest,
  gegen Sperrlisten geprueft und die Verbindung an die gepruefte Adresse
  gebunden (kein DNS-Rebinding).
* Zeitlimits fuer jede Verbindung, begrenzte Anzahl gleichzeitiger Anfragen,
  begrenzte Rumpfgroesse, strukturierte JSON-Protokolle auf stdout.

Siehe docs/mail-proxy.md.
"""

from __future__ import annotations

import base64
import email
import email.parser
import email.policy
import email.utils
import hashlib
import hmac
import html
import html.parser
import imaplib
import ipaddress
import json
import os
import re
import signal
import smtplib
import socket
import ssl
import sys
import threading
import time
from datetime import datetime
from email.message import EmailMessage, Message
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSION = "1.0.0"


def _env_int(name: str, default: int, low: int, high: int) -> int:
    try:
        value = int(os.environ.get(name, str(default)))
    except ValueError:
        value = default
    return max(low, min(high, value))


LISTEN_HOST = os.environ.get("MAIL_PROXY_LISTEN_HOST", "0.0.0.0")
LISTEN_PORT = _env_int("MAIL_PROXY_PORT", 8025, 1, 65535)
KEY_FILE = os.environ.get("MAIL_PROXY_KEY_FILE", "/run/mail-proxy/keys/secrets.key")
MAX_CONNECTIONS = _env_int("MAIL_PROXY_MAX_CONNECTIONS", 32, 1, 512)
MAX_BODY = _env_int("MAIL_PROXY_MAX_BODY_MB", 48, 1, 256) * 1024 * 1024
MAX_MESSAGE = _env_int("MAIL_PROXY_MAX_MESSAGE_MB", 35, 1, 200) * 1024 * 1024
POOL_SIZE = _env_int("MAIL_PROXY_POOL_SIZE", 32, 0, 1024)
POOL_IDLE = _env_int("MAIL_PROXY_POOL_IDLE_SECONDS", 90, 5, 3600)
MAX_SKEW = 60
NONCE_LIMIT = 20000


def _deny_cidrs(value: str) -> list:
    networks = []
    for item in value.split(","):
        item = item.strip()
        if item:
            try:
                networks.append(ipaddress.ip_network(item, strict=False))
            except ValueError:
                print(json.dumps({"level": "warning", "event": "invalid deny cidr ignored", "value": item}), flush=True)
    return networks


DENY_CIDRS = _deny_cidrs(os.environ.get("MAIL_PROXY_DENY_CIDRS", ""))

KEY_PURPOSE = b"mail-proxy"
SMTP_PORTS = {25, 465, 587, 2525}
IMAP_PORTS = {143, 993}
KINDS = ("inbox", "drafts", "sentitems", "deleteditems", "junkemail")
ALLOWED_FLAGS = {"\\Seen", "\\Flagged", "\\Answered", "\\Deleted", "\\Draft", "$Forwarded"}
HOST_RE = re.compile(r"^(?=.{1,253}$)([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$")
NONCE_RE = re.compile(r"^[a-f0-9]{32}$")
OP_RE = re.compile(r"^[a-z]+\.[a-z_]+$")
EMAIL_RE = re.compile(r"^[^@\s<>()\[\],;:\"\\]+@[^@\s<>()\[\],;:\"\\]+\.[^@\s<>()\[\],;:\"\\]+$")

# Systemordner: Namen (klein, nach mUTF-7-Dekodierung) als Rueckfall, wenn der
# Server kein SPECIAL-USE (RFC 6154) meldet.
KIND_NAMES = {
    "sentitems": {"sent", "sent items", "sent messages", "sent mail", "gesendet", "gesendete elemente", "gesendete objekte", "gesendete nachrichten"},
    "deleteditems": {"trash", "deleted", "deleted items", "deleted messages", "papierkorb", "gelöschte elemente", "gelöschte objekte", "gelöscht"},
    "drafts": {"drafts", "draft", "entwürfe", "entwurf"},
    "junkemail": {"junk", "spam", "junk e-mail", "junk-e-mail", "junk email", "junk-mail", "bulk mail"},
}
KIND_FLAGS = {"\\sent": "sentitems", "\\trash": "deleteditems", "\\drafts": "drafts", "\\junk": "junkemail"}
DEFAULT_NAMES = {"sentitems": "Sent", "deleteditems": "Trash", "drafts": "Drafts"}


# ---------------------------------------------------------------------------
# Protokollierung (JSON, ohne Geheimnisse)
# ---------------------------------------------------------------------------

_LOG_LOCK = threading.Lock()
_SECRET_KEY_RE = re.compile(r"pass|secret|token|authorization|signature|credential", re.I)


def log(level: str, event: str, **fields: object) -> None:
    record = {"ts": datetime.now().astimezone().isoformat(timespec="seconds"), "level": level, "event": event}
    for key, value in fields.items():
        record[key] = "[redacted]" if _SECRET_KEY_RE.search(key) else value
    line = json.dumps(record, ensure_ascii=False, default=str)
    with _LOG_LOCK:
        sys.stdout.write(line + "\n")
        sys.stdout.flush()


# ---------------------------------------------------------------------------
# Fehler
# ---------------------------------------------------------------------------


class ProxyError(Exception):
    """Fachlicher Fehler mit Code fuer die PHP-Seite (ohne Geheimnisse)."""

    def __init__(self, code: str, message: str, status: int = 502) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status = status


def classify(exc: BaseException, stage: str) -> ProxyError:
    """Uebersetzt Netzwerk-/Protokollfehler, ohne Serverantworten durchzureichen."""
    if isinstance(exc, ProxyError):
        return exc
    if isinstance(exc, (socket.timeout, TimeoutError)):
        return ProxyError("timeout", f"Zeitüberschreitung ({stage}).", 504)
    if isinstance(exc, (ssl.SSLCertVerificationError, ssl.CertificateError)):
        return ProxyError("tls", f"TLS-Zertifikat ungültig ({stage}).")
    if isinstance(exc, ssl.SSLError):
        return ProxyError("tls", f"TLS-Fehler ({stage}).")
    if isinstance(exc, smtplib.SMTPAuthenticationError):
        return ProxyError("auth_failed", "SMTP-Anmeldung abgelehnt.")
    if isinstance(exc, smtplib.SMTPRecipientsRefused):
        return ProxyError("smtp_rejected", "Empfänger abgelehnt.")
    if isinstance(exc, (smtplib.SMTPSenderRefused, smtplib.SMTPDataError)):
        return ProxyError("smtp_rejected", "Nachricht vom SMTP-Server abgelehnt.")
    if isinstance(exc, smtplib.SMTPNotSupportedError):
        return ProxyError("tls", "Der SMTP-Server unterstützt die geforderte Verschlüsselung/Anmeldung nicht.")
    if isinstance(exc, (smtplib.SMTPServerDisconnected, smtplib.SMTPConnectError)):
        return ProxyError("unreachable", "SMTP-Server hat die Verbindung beendet.")
    if isinstance(exc, smtplib.SMTPException):
        return ProxyError("smtp_rejected", "SMTP-Fehler.")
    if isinstance(exc, imaplib.IMAP4.abort):
        return ProxyError("unreachable", f"IMAP-Verbindung abgebrochen ({stage}).")
    if isinstance(exc, imaplib.IMAP4.error):
        return ProxyError("invalid", f"IMAP-Befehl abgelehnt ({stage}).", 422)
    if isinstance(exc, socket.gaierror):
        return ProxyError("unreachable", "Mailserver-Name nicht auflösbar.")
    if isinstance(exc, OSError):
        return ProxyError("unreachable", f"Mailserver nicht erreichbar ({stage}).")
    return ProxyError("internal", "Interner Fehler im Mail-Proxy.", 500)


# ---------------------------------------------------------------------------
# Schluessel und Signatur
# ---------------------------------------------------------------------------


class KeyStore:
    def __init__(self, path: str) -> None:
        self.path = path
        self._lock = threading.Lock()
        self._key: bytes | None = None
        self._mtime = 0.0

    def key(self) -> bytes | None:
        with self._lock:
            try:
                mtime = os.stat(self.path).st_mtime
            except OSError:
                return self._key
            if self._key is None or mtime != self._mtime:
                try:
                    with open(self.path, "rb") as handle:
                        raw = base64.b64decode(handle.read().strip(), validate=True)
                except (OSError, ValueError):
                    return self._key
                if len(raw) != 32:
                    return self._key
                # Wie PHP: hash_hmac('sha256', 'mail-proxy', $rawKey) -> Hex-String als Schluessel.
                self._key = hmac.new(raw, KEY_PURPOSE, hashlib.sha256).hexdigest().encode("ascii")
                self._mtime = mtime
                del raw
            return self._key


def signature(key: bytes, timestamp: str, nonce: str, operation: str, body: bytes) -> str:
    message = "v1\n{}\n{}\n{}\n{}".format(timestamp, nonce, operation, hashlib.sha256(body).hexdigest())
    return hmac.new(key, message.encode("utf-8"), hashlib.sha256).hexdigest()


class NonceCache:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._seen: dict[str, float] = {}

    def use(self, nonce: str) -> bool:
        now = time.monotonic()
        with self._lock:
            if len(self._seen) > NONCE_LIMIT:
                self._seen = {k: v for k, v in self._seen.items() if v > now}
                if len(self._seen) > NONCE_LIMIT:
                    return False
            if self._seen.get(nonce, 0) > now:
                return False
            self._seen[nonce] = now + 2 * MAX_SKEW + 5
            return True


# ---------------------------------------------------------------------------
# Zielpruefung (SSRF) und gebundene Verbindungen
# ---------------------------------------------------------------------------


def ip_allowed(ip) -> bool:
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    if ip.is_loopback or ip.is_link_local or ip.is_multicast or ip.is_unspecified or ip.is_reserved:
        return False
    if isinstance(ip, ipaddress.IPv4Address) and ip in ipaddress.ip_network("0.0.0.0/8"):
        return False
    return not any(ip.version == net.version and ip in net for net in DENY_CIDRS)


def resolve_target(host: str, port: int, allowed_ports: set) -> str:
    """Prueft Host/Port und liefert die aufgeloeste, zulaessige IP-Adresse."""
    if not isinstance(host, str) or not isinstance(port, int) or port not in allowed_ports:
        raise ProxyError("forbidden_target", "Mailserver-Port nicht zulässig.")
    try:
        literal = ipaddress.ip_address(host)
    except ValueError:
        literal = None
    if literal is None and HOST_RE.match(host) is None:
        raise ProxyError("forbidden_target", "Mailserver-Name ungültig.")
    if literal is not None:
        candidates = [literal]
    else:
        try:
            infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
        except socket.gaierror as exc:
            raise ProxyError("unreachable", "Mailserver-Name nicht auflösbar.") from exc
        candidates = []
        for info in infos:
            try:
                candidates.append(ipaddress.ip_address(str(info[4][0]).split("%", 1)[0]))
            except ValueError:
                continue
    if not candidates:
        raise ProxyError("unreachable", "Mailserver-Name nicht auflösbar.")
    if not all(ip_allowed(ip) for ip in candidates):
        raise ProxyError("forbidden_target", "Mailserver-Adresse nicht zulässig.")
    return str(candidates[0])


def tls_context(verify: bool) -> ssl.SSLContext:
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    if not verify:
        # Nur auf ausdrueckliche Admin-Einstellung (verify_tls = 0).
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
    return context


class PinnedIMAP4(imaplib.IMAP4):
    def __init__(self, ip: str, host: str, port: int, timeout: float) -> None:
        self._mp_ip = ip
        super().__init__(host, port, timeout=timeout)

    def _create_socket(self, timeout=None):
        return socket.create_connection((self._mp_ip, self.port), timeout)


class PinnedIMAP4SSL(imaplib.IMAP4_SSL):
    def __init__(self, ip: str, host: str, port: int, context: ssl.SSLContext, timeout: float) -> None:
        self._mp_ip = ip
        super().__init__(host, port, ssl_context=context, timeout=timeout)

    def _create_socket(self, timeout=None):
        sock = socket.create_connection((self._mp_ip, self.port), timeout)
        return self.ssl_context.wrap_socket(sock, server_hostname=self.host)


class PinnedSMTP(smtplib.SMTP):
    def __init__(self, ip: str, timeout: float) -> None:
        self._mp_ip = ip
        super().__init__(timeout=timeout)

    def _get_socket(self, host, port, timeout):
        # _host wird von starttls() fuer SNI/Zertifikatspruefung verwendet.
        self._host = host
        return socket.create_connection((self._mp_ip, port), timeout, self.source_address)


class PinnedSMTPSSL(smtplib.SMTP_SSL):
    def __init__(self, ip: str, context: ssl.SSLContext, timeout: float) -> None:
        self._mp_ip = ip
        super().__init__(context=context, timeout=timeout)

    def _get_socket(self, host, port, timeout):
        self._host = host
        sock = socket.create_connection((self._mp_ip, port), timeout, self.source_address)
        return self.context.wrap_socket(sock, server_hostname=host)


# ---------------------------------------------------------------------------
# Konto (aus der Anfrage, nie protokolliert)
# ---------------------------------------------------------------------------


class Account:
    __slots__ = ("mailbox_id", "generation", "email", "display_name", "verify_tls", "timeout", "imap", "smtp", "fingerprint")

    def __init__(self, data: object) -> None:
        if not isinstance(data, dict):
            raise ProxyError("invalid", "Konto fehlt.", 422)
        try:
            self.mailbox_id = int(data["mailbox_id"])
            self.generation = int(data.get("generation", 0))
            self.email = str(data["email"])
            self.display_name = str(data.get("display_name", ""))
            self.verify_tls = bool(data.get("verify_tls", True))
            self.timeout = max(5, min(60, int(data.get("timeout", 20))))
            imap = data["imap"]
            smtp = data["smtp"]
            self.imap = {
                "host": str(imap["host"]), "port": int(imap["port"]), "security": str(imap["security"]),
                "username": str(imap["username"]), "password": str(imap["password"]),
            }
            self.smtp = {
                "host": str(smtp["host"]), "port": int(smtp["port"]), "security": str(smtp["security"]),
                "auth": bool(smtp.get("auth", True)), "username": str(smtp.get("username", "")), "password": str(smtp.get("password", "")),
            }
        except (KeyError, TypeError, ValueError) as exc:
            raise ProxyError("invalid", "Kontodaten unvollständig.", 422) from exc
        if self.mailbox_id <= 0 or EMAIL_RE.match(self.email) is None:
            raise ProxyError("invalid", "Kontodaten ungültig.", 422)
        if self.imap["security"] not in ("tls", "starttls"):
            raise ProxyError("forbidden_target", "IMAP ohne TLS ist nicht zulässig.")
        if self.smtp["security"] not in ("tls", "starttls", "none"):
            raise ProxyError("invalid", "SMTP-Verschlüsselung ungültig.", 422)
        if self.smtp["security"] == "none" and self.smtp["auth"]:
            raise ProxyError("forbidden_target", "SMTP-Anmeldung ohne TLS ist nicht zulässig.")
        material = "\n".join([
            str(self.mailbox_id), str(self.generation), str(self.verify_tls), self.imap["host"], str(self.imap["port"]),
            self.imap["security"], self.imap["username"], self.imap["password"],
        ])
        self.fingerprint = hashlib.sha256(material.encode("utf-8")).hexdigest()
        del material

    def __repr__(self) -> str:  # nie Zugangsdaten ausgeben
        return f"<Account mailbox_id={self.mailbox_id}>"


# ---------------------------------------------------------------------------
# IMAP-Hilfen
# ---------------------------------------------------------------------------


def mutf7_decode(raw: str) -> str:
    out = []
    i = 0
    while i < len(raw):
        ch = raw[i]
        if ch == "&":
            end = raw.find("-", i)
            if end == -1:
                out.append(raw[i:])
                break
            chunk = raw[i + 1:end]
            if chunk == "":
                out.append("&")
            else:
                padded = chunk.replace(",", "/") + "=" * (-len(chunk) % 4)
                try:
                    out.append(base64.b64decode(padded).decode("utf-16-be"))
                except (ValueError, UnicodeDecodeError):
                    out.append(raw[i:end + 1])
            i = end + 1
        else:
            out.append(ch)
            i += 1
    return "".join(out)


def mutf7_encode(text: str) -> str:
    out = []
    buffer = []

    def flush() -> None:
        if buffer:
            encoded = base64.b64encode("".join(buffer).encode("utf-16-be")).decode("ascii").rstrip("=").replace("/", ",")
            out.append("&" + encoded + "-")
            buffer.clear()

    for ch in text:
        if 0x20 <= ord(ch) <= 0x7E:
            flush()
            out.append("&-" if ch == "&" else ch)
        else:
            buffer.append(ch)
    flush()
    return "".join(out)


def quote(raw: str) -> str:
    if not isinstance(raw, str) or not raw or len(raw) > 1000 or any(ord(c) < 0x20 or ord(c) > 0x7E for c in raw):
        raise ProxyError("invalid", "Ordnername ungültig.", 422)
    return '"' + raw.replace("\\", "\\\\").replace('"', '\\"') + '"'


def _unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == '"' and value[-1] == '"':
        return re.sub(r'\\(.)', r"\1", value[1:-1])
    return value


LIST_RE = re.compile(r'^\((?P<flags>[^)]*)\)\s+(?P<delim>"(?:[^"\\]|\\.)*"|NIL)\s*(?P<name>.*)$', re.S)
FETCH_START = re.compile(rb"^\d+ \(")


def parse_list(data: list) -> list:
    folders = []
    for item in data:
        if item is None:
            continue
        if isinstance(item, tuple):
            head = item[0].decode("ascii", "replace")
            name_override = item[1].decode("ascii", "replace")
        else:
            head = item.decode("ascii", "replace")
            name_override = None
        match = LIST_RE.match(head.strip())
        if match is None:
            continue
        delim = match.group("delim")
        delimiter = "" if delim == "NIL" else _unquote(delim)
        name = name_override if name_override is not None else _unquote(match.group("name"))
        flags = {flag.lower() for flag in match.group("flags").split()}
        folders.append({"raw": name, "delimiter": delimiter, "flags": flags})
    return folders


def fetch_records(data: list) -> list:
    records: list = []
    for item in data:
        if isinstance(item, tuple):
            head = item[0]
            if FETCH_START.match(head) or not records:
                records.append({"meta": b"", "literals": []})
            records[-1]["meta"] += head
            records[-1]["literals"].append(item[1])
        elif isinstance(item, bytes):
            if FETCH_START.match(item):
                records.append({"meta": item, "literals": []})
            elif records:
                records[-1]["meta"] += item
    return records


def meta_int(meta: bytes, name: bytes) -> int:
    match = re.search(rb"(?:^|[ (])" + re.escape(name) + rb" (\d+)", meta)
    return int(match.group(1)) if match else 0


def meta_flags(meta: bytes) -> set:
    match = re.search(rb"FLAGS \(([^)]*)\)", meta)
    return set(match.group(1).decode("ascii", "replace").split()) if match else set()


def meta_internaldate(meta: bytes) -> int:
    match = re.search(rb'INTERNALDATE "([^"]+)"', meta)
    if not match:
        return 0
    try:
        return int(datetime.strptime(match.group(1).decode("ascii").strip(), "%d-%b-%Y %H:%M:%S %z").timestamp())
    except ValueError:
        return 0


def _ok(typ: str, stage: str) -> None:
    if typ != "OK":
        raise ProxyError("invalid", f"IMAP-Befehl abgelehnt ({stage}).", 422)


class ImapSession:
    """Eine angemeldete IMAP-Verbindung eines Postfachs."""

    def __init__(self, account: Account) -> None:
        self.fingerprint = account.fingerprint
        self.mailbox_id = account.mailbox_id
        self.last_used = time.monotonic()
        self._folders = None
        self._folders_at = 0.0
        cfg = account.imap
        ip = resolve_target(cfg["host"], cfg["port"], IMAP_PORTS)
        context = tls_context(account.verify_tls)
        stage = "IMAP-Verbindung"
        try:
            if cfg["security"] == "tls":
                conn = PinnedIMAP4SSL(ip, cfg["host"], cfg["port"], context, account.timeout)
            else:
                conn = PinnedIMAP4(ip, cfg["host"], cfg["port"], account.timeout)
                stage = "IMAP-STARTTLS"
                conn.starttls(context)
            stage = "IMAP-Anmeldung"
            try:
                conn.login(cfg["username"], cfg["password"])
            except imaplib.IMAP4.abort:
                raise
            except imaplib.IMAP4.error:
                raise ProxyError("auth_failed", "IMAP-Anmeldung abgelehnt.") from None
        except ProxyError:
            raise
        except Exception as exc:  # noqa: BLE001 - Klassifizierung ohne Details
            raise classify(exc, stage) from None
        self.conn = conn
        self.capabilities = {str(c).upper() for c in conn.capabilities}

    def alive(self) -> bool:
        try:
            return self.conn.noop()[0] == "OK"
        except Exception:  # noqa: BLE001
            return False

    def close(self) -> None:
        try:
            self.conn.logout()
        except Exception:  # noqa: BLE001
            try:
                self.conn.shutdown()
            except Exception:  # noqa: BLE001
                pass

    # -- Ordner ---------------------------------------------------------

    def folders(self, fresh: bool = False) -> list:
        if not fresh and self._folders is not None and time.monotonic() - self._folders_at < 60:
            return self._folders
        typ, data = self.conn.list('""', "*")
        _ok(typ, "LIST")
        folders = parse_list(data or [])
        names = {f["raw"] for f in folders}
        assigned: dict = {}
        for folder in folders:
            raw = folder["raw"]
            delim = folder["delimiter"]
            folder["noselect"] = "\\noselect" in folder["flags"] or "\\nonexistent" in folder["flags"]
            parent = raw.rsplit(delim, 1)[0] if delim and delim in raw else ""
            folder["parent_raw"] = parent if parent in names else ""
            leaf = raw.rsplit(delim, 1)[-1] if delim else raw
            folder["name"] = mutf7_decode(leaf)
            folder["kind"] = "folder"
            if raw.upper() == "INBOX":
                folder["kind"] = "inbox"
                folder["name"] = "Posteingang"
        for folder in folders:  # SPECIAL-USE zuerst
            for flag, kind in KIND_FLAGS.items():
                if flag in folder["flags"] and kind not in assigned and folder["kind"] == "folder":
                    folder["kind"] = kind
                    assigned[kind] = folder["raw"]
        for folder in folders:  # dann bekannte Namen
            if folder["kind"] != "folder" or folder["noselect"]:
                continue
            lowered = folder["name"].strip().lower()
            for kind, candidates in KIND_NAMES.items():
                if kind not in assigned and lowered in candidates:
                    folder["kind"] = kind
                    assigned[kind] = folder["raw"]
                    break
        self._folders = folders
        self._folders_at = time.monotonic()
        return folders

    def resolve(self, spec: str, create: bool = False) -> str:
        if not isinstance(spec, str) or spec == "":
            raise ProxyError("not_found", "Ordner nicht gefunden.", 404)
        if spec.startswith("name:"):
            raw = spec[5:]
            quote(raw)
            return raw
        if spec not in KINDS:
            raise ProxyError("not_found", "Ordner nicht gefunden.", 404)
        if spec == "inbox":
            return "INBOX"
        for folder in self.folders():
            if folder["kind"] == spec:
                return folder["raw"]
        if create and spec in DEFAULT_NAMES:
            raw = DEFAULT_NAMES[spec]
            typ, _ = self.conn.create(quote(raw))
            if typ != "OK":
                # Manche Server erwarten Unterordner der INBOX.
                inbox = next((f for f in self.folders(True) if f["raw"].upper() == "INBOX"), None)
                delim = (inbox["delimiter"] if inbox else "") or "."
                raw = "INBOX" + delim + DEFAULT_NAMES[spec]
                typ, _ = self.conn.create(quote(raw))
                _ok(typ, "CREATE")
            try:
                self.conn.subscribe(quote(raw))
            except imaplib.IMAP4.error:
                pass
            self._folders = None
            return raw
        raise ProxyError("not_found", "Ordner nicht vorhanden.", 404)

    def select(self, raw: str, readonly: bool) -> int:
        typ, _ = self.conn.select(quote(raw), readonly=readonly)
        if typ != "OK":
            raise ProxyError("not_found", "Ordner nicht gefunden.", 404)
        _, data = self.conn.response("UIDVALIDITY")
        try:
            return int((data or [b"0"])[-1])
        except (TypeError, ValueError):
            return 0

    def select_checked(self, spec: str, uidvalidity: int, readonly: bool) -> str:
        raw = self.resolve(spec)
        current = self.select(raw, readonly)
        if uidvalidity and current and current != uidvalidity:
            raise ProxyError("not_found", "Der Ordner wurde zwischenzeitlich neu aufgebaut. Bitte neu laden.", 404)
        return raw

    def status(self, raw: str) -> tuple:
        typ, data = self.conn.status(quote(raw), "(MESSAGES UNSEEN)")
        if typ != "OK" or not data:
            return 0, 0
        text = data[0].decode("ascii", "replace") if isinstance(data[0], bytes) else str(data[0])
        messages = re.search(r"MESSAGES (\d+)", text)
        unseen = re.search(r"UNSEEN (\d+)", text)
        return (int(messages.group(1)) if messages else 0, int(unseen.group(1)) if unseen else 0)

    def uid_search(self, *criteria: str, text: str | None = None) -> list:
        if text:
            self.conn.literal = text.encode("utf-8")
            try:
                typ, data = self.conn.uid("SEARCH", "CHARSET", "UTF-8", *criteria)
            except imaplib.IMAP4.error:
                typ, data = "NO", []
            finally:
                self.conn.literal = None
            if typ != "OK" and text.isascii():
                self.conn.literal = text.encode("ascii")
                try:
                    typ, data = self.conn.uid("SEARCH", *criteria)
                finally:
                    self.conn.literal = None
        else:
            typ, data = self.conn.uid("SEARCH", *criteria)
        _ok(typ, "SEARCH")
        uids: list = []
        for chunk in data or []:
            if isinstance(chunk, bytes):
                uids.extend(int(x) for x in chunk.split() if x.isdigit())
        return uids

    def fetch_raw(self, uid: int, section: str = "BODY.PEEK[]") -> tuple:
        typ, data = self.conn.uid("FETCH", str(uid), f"(UID FLAGS INTERNALDATE RFC822.SIZE {section})")
        _ok(typ, "FETCH")
        for record in fetch_records(data or []):
            if meta_int(record["meta"], b"UID") == uid and record["literals"]:
                return record["literals"][0], record
        raise ProxyError("not_found", "Nachricht nicht gefunden.", 404)

    def append(self, raw: str, flags: str, message: bytes) -> int:
        if len(message) > MAX_MESSAGE:
            raise ProxyError("too_large", "Nachricht zu groß.", 413)
        typ, data = self.conn.append(quote(raw), flags, imaplib.Time2Internaldate(time.time()), message)
        _ok(typ, "APPEND")
        text = b" ".join(d for d in (data or []) if isinstance(d, bytes)).decode("ascii", "replace")
        match = re.search(r"APPENDUID (\d+) (\d+)", text)
        if match:
            return int(match.group(2))
        # Ohne UIDPLUS: ueber die Message-ID suchen.
        parsed = email.message_from_bytes(message[:65536], policy=email.policy.default)
        message_id = str(parsed.get("Message-ID", "")).strip()
        if message_id:
            self.select(raw, True)
            found = self.uid_search("HEADER", "Message-ID", text=message_id)
            if found:
                return max(found)
        return 0

    def expunge_uids(self, uids: list) -> None:
        uid_set = ",".join(str(u) for u in uids)
        _ok(self.conn.uid("STORE", uid_set, "+FLAGS.SILENT", "(\\Deleted)")[0], "STORE")
        if "UIDPLUS" in self.capabilities:
            self.conn.uid("EXPUNGE", uid_set)
        else:
            self.conn.expunge()


# ---------------------------------------------------------------------------
# Verbindungspool
# ---------------------------------------------------------------------------


class Pool:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._sessions: dict = {}
        self._locks: dict = {}

    def mailbox_lock(self, mailbox_id: int) -> threading.Lock:
        with self._lock:
            return self._locks.setdefault(mailbox_id, threading.Lock())

    def take(self, account: Account) -> ImapSession:
        with self._lock:
            session = self._sessions.pop(account.mailbox_id, None)
        if session is not None:
            fresh = time.monotonic() - session.last_used < POOL_IDLE
            # Fingerabdruck enthaelt Generation und Zugangsdaten: nach jeder
            # Konfigurationsaenderung entsteht eine neue Verbindung.
            if session.fingerprint == account.fingerprint and fresh and session.alive():
                return session
            session.close()
        return ImapSession(account)

    def give(self, session: ImapSession) -> None:
        if POOL_SIZE == 0:
            session.close()
            return
        session.last_used = time.monotonic()
        evicted = []
        with self._lock:
            old = self._sessions.pop(session.mailbox_id, None)
            if old is not None and old is not session:
                evicted.append(old)
            self._sessions[session.mailbox_id] = session
            while len(self._sessions) > POOL_SIZE:
                oldest = min(self._sessions.values(), key=lambda s: s.last_used)
                evicted.append(self._sessions.pop(oldest.mailbox_id))
        for item in evicted:
            item.close()

    def sweep(self) -> None:
        now = time.monotonic()
        with self._lock:
            stale = [s for s in self._sessions.values() if now - s.last_used >= POOL_IDLE]
            for s in stale:
                self._sessions.pop(s.mailbox_id, None)
        for s in stale:
            s.close()

    def clear(self) -> int:
        with self._lock:
            sessions = list(self._sessions.values())
            self._sessions.clear()
        for s in sessions:
            s.close()
        return len(sessions)

    def size(self) -> int:
        with self._lock:
            return len(self._sessions)


POOL = Pool()


def with_imap(account: Account, work):
    """Fuehrt work(session) seriell je Postfach aus; eine abgebrochene
    Pool-Verbindung wird einmal neu aufgebaut."""
    lock = POOL.mailbox_lock(account.mailbox_id)
    if not lock.acquire(timeout=account.timeout * 2):
        raise ProxyError("busy", "Postfach ist beschäftigt.", 503)
    try:
        for attempt in (1, 2):
            session = POOL.take(account)
            try:
                result = work(session)
            except ProxyError:
                POOL.give(session)
                raise
            except (imaplib.IMAP4.abort, OSError) as exc:
                session.close()
                # Nur Verbindungsabbrueche einer Pool-Verbindung wiederholen
                # (SMTP-Versand wirft nie OSError, siehe smtp_send).
                if attempt == 1 and not isinstance(exc, (socket.timeout, TimeoutError, ssl.SSLError)):
                    continue
                raise classify(exc, "IMAP") from None
            except Exception as exc:  # noqa: BLE001
                session.close()
                raise classify(exc, "IMAP") from None
            POOL.give(session)
            return result
    finally:
        lock.release()
    raise ProxyError("unreachable", "IMAP-Verbindung fehlgeschlagen.")


# ---------------------------------------------------------------------------
# Nachrichten lesen
# ---------------------------------------------------------------------------

POLICY = email.policy.default.clone(max_line_length=998)


def _addresses(header) -> list:
    result = []
    if header is None:
        return result
    try:
        for address in header.addresses:
            result.append({"name": str(address.display_name or ""), "email": str(address.addr_spec or "")})
    except Exception:  # noqa: BLE001 - defekte Kopfzeilen
        for name, addr in email.utils.getaddresses([str(header)]):
            result.append({"name": name, "email": addr})
    return result


def _date(value) -> int:
    if not value:
        return 0
    try:
        return int(email.utils.parsedate_to_datetime(str(value)).timestamp())
    except (TypeError, ValueError, IndexError, OverflowError):
        return 0


def _importance(msg: Message) -> str:
    value = str(msg.get("Importance", "") or msg.get("X-Priority", "")).strip().lower()
    if value.startswith(("high", "1", "2", "urgent")):
        return "High"
    if value.startswith(("low", "5", "4", "non-urgent")):
        return "Low"
    return "Normal"


def _text(part: Message) -> str:
    try:
        return part.get_content()
    except (LookupError, KeyError, ValueError, AssertionError):
        payload = part.get_payload(decode=True) or b""
        return payload.decode("utf-8", "replace")


def _leaves(part: Message) -> list:
    if part.get_content_type() == "message/rfc822":
        return [part]
    if part.is_multipart():
        leaves: list = []
        for sub in part.get_payload():
            leaves.extend(_leaves(sub))
        return leaves
    return [part]


def message_parts(msg: Message) -> tuple:
    """(HTML-Teil, Text-Teil, Anhaenge) in stabiler Reihenfolge (Index = Anhangskennung)."""
    try:
        html_part = msg.get_body(preferencelist=("html",))
        text_part = msg.get_body(preferencelist=("plain",))
    except Exception:  # noqa: BLE001
        html_part = text_part = None
    attachments = [p for p in _leaves(msg) if p is not html_part and p is not text_part]
    return html_part, text_part, attachments


def _attachment_bytes(part: Message) -> bytes:
    if part.get_content_type() == "message/rfc822":
        payload = part.get_payload()
        inner = payload[0] if isinstance(payload, list) and payload else payload
        return inner.as_bytes(policy=POLICY) if isinstance(inner, Message) else b""
    return part.get_payload(decode=True) or b""


def _attachment_name(part: Message, index: int) -> str:
    try:
        name = part.get_filename()
    except Exception:  # noqa: BLE001
        name = None
    if not name and part.get_content_type() == "message/rfc822":
        payload = part.get_payload()
        inner = payload[0] if isinstance(payload, list) and payload else None
        subject = str(inner.get("Subject", "")) if isinstance(inner, Message) else ""
        name = (subject or "nachricht") + ".eml"
    name = re.sub(r"[\x00-\x1f\x7f/\\]+", "_", name or f"anhang-{index + 1}").strip() or f"anhang-{index + 1}"
    return name[:200]


def _content_id(part: Message) -> str:
    return str(part.get("Content-ID", "") or "").strip().strip("<>")


def summary(meta: bytes, header_bytes: bytes) -> dict:
    headers = email.parser.BytesHeaderParser(policy=POLICY).parsebytes(header_bytes or b"")
    from_list = _addresses(headers.get("From"))
    content_type = str(headers.get("Content-Type", "")).lower()
    flags = meta_flags(meta)
    return {
        "uid": meta_int(meta, b"UID"),
        "subject": str(headers.get("Subject", "") or ""),
        "preview": "",
        "from": from_list[0] if from_list else {"name": "", "email": ""},
        "to": _addresses(headers.get("To")),
        "date": _date(headers.get("Date")),
        "received": meta_internaldate(meta),
        "seen": "\\Seen" in flags,
        "flagged": "\\Flagged" in flags,
        # Heuristik ohne BODYSTRUCTURE-Analyse; die Detailansicht ist exakt.
        "has_attachments": content_type.startswith("multipart/mixed"),
        "size": meta_int(meta, b"RFC822.SIZE"),
        "importance": _importance(headers),
    }


HEADER_FIELDS = "BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE IMPORTANCE X-PRIORITY CONTENT-TYPE)]"


def op_folders(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        result = []
        for folder in session.folders(fresh=True)[:500]:
            total, unread = (0, 0) if folder["noselect"] else session.status(folder["raw"])
            result.append({
                "raw": folder["raw"], "name": folder["name"], "parent_raw": folder["parent_raw"], "kind": folder["kind"],
                "total": total, "unread": unread, "noselect": folder["noselect"],
            })
        return {"folders": result}
    return with_imap(account, work)


def op_create_folder(account: Account, payload: dict) -> dict:
    name = str(payload.get("name", "")).strip()
    if not name or len(name) > 255 or re.search(r"[\x00-\x1f\x7f]", name):
        raise ProxyError("invalid", "Ordnername ungültig.", 422)

    def work(session: ImapSession) -> dict:
        parent_spec = str(payload.get("parent", ""))
        folders = session.folders(fresh=True)
        delimiter = next((f["delimiter"] for f in folders if f["delimiter"]), "/")
        if delimiter in name:
            raise ProxyError("invalid", "Der Ordnername enthält ein unzulässiges Trennzeichen.", 422)
        encoded = mutf7_encode(name)
        raw = (session.resolve(parent_spec) + delimiter + encoded) if parent_spec else encoded
        typ, _ = session.conn.create(quote(raw))
        _ok(typ, "CREATE")
        try:
            session.conn.subscribe(quote(raw))
        except imaplib.IMAP4.error:
            pass
        session._folders = None
        return {"raw": raw}
    return with_imap(account, work)


def op_mark_folder_read(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        raw = session.resolve(str(payload.get("folder", "")))
        session.select(raw, False)
        uids = session.uid_search("UNSEEN")
        for start in range(0, len(uids), 500):
            chunk = ",".join(str(u) for u in uids[start:start + 500])
            session.conn.uid("STORE", chunk, "+FLAGS.SILENT", "(\\Seen)")
        return {"updated": len(uids)}
    return with_imap(account, work)


def _folder_size(session: ImapSession, raw: str, total: int) -> int:
    if total == 0 or total > 5000:
        return 0
    session.select(raw, True)
    typ, data = session.conn.uid("FETCH", "1:*", "(RFC822.SIZE)")
    if typ != "OK":
        return 0
    return sum(meta_int(r["meta"], b"RFC822.SIZE") for r in fetch_records(data or []))


def op_folder_status(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        raw = session.resolve(str(payload.get("folder", "")))
        folders = session.folders(fresh=True)
        own = next((f for f in folders if f["raw"] == raw), None)
        if own is None:
            raise ProxyError("not_found", "Ordner nicht gefunden.", 404)
        total, unread = session.status(raw)
        size = _folder_size(session, raw, total)
        prefix = raw + own["delimiter"] if own["delimiter"] else None
        subs = [f for f in folders if prefix and f["raw"].startswith(prefix) and not f["noselect"]]
        total_all, size_all = total, size
        for sub in subs[:100]:
            sub_total, _ = session.status(sub["raw"])
            total_all += sub_total
            size_all += _folder_size(session, sub["raw"], sub_total)
        return {
            "raw": raw, "name": own["name"], "total": total, "unread": unread, "subfolders": len(subs), "size": size,
            "total_with_subfolders": total_all, "size_with_subfolders": size_all,
        }
    return with_imap(account, work)


def op_messages(account: Account, payload: dict) -> dict:
    offset = max(0, int(payload.get("offset", 0)))
    limit = max(1, min(200, int(payload.get("limit", 50))))
    search = re.sub(r"[\x00-\x1f\x7f]+", " ", str(payload.get("search", ""))).strip()[:200]

    def work(session: ImapSession) -> dict:
        raw = session.resolve(str(payload.get("folder", "")))
        uidvalidity = session.select(raw, True)
        uids = session.uid_search("TEXT", text=search) if search else session.uid_search("ALL")
        uids.sort(reverse=True)
        page = uids[offset:offset + limit]
        items = []
        if page:
            typ, data = session.conn.uid("FETCH", ",".join(str(u) for u in page), f"(UID FLAGS INTERNALDATE RFC822.SIZE {HEADER_FIELDS})")
            _ok(typ, "FETCH")
            by_uid = {}
            for record in fetch_records(data or []):
                uid = meta_int(record["meta"], b"UID")
                if uid:
                    by_uid[uid] = summary(record["meta"], record["literals"][0] if record["literals"] else b"")
            items = [by_uid[u] for u in page if u in by_uid]
        return {"uidvalidity": uidvalidity, "total": len(uids), "items": items}
    return with_imap(account, work)


def _load_message(session: ImapSession, payload: dict) -> tuple:
    uid = int(payload.get("uid", 0))
    if uid <= 0:
        raise ProxyError("not_found", "Nachricht nicht gefunden.", 404)
    session.select_checked(str(payload.get("folder", "")), int(payload.get("uidvalidity", 0)), True)
    raw, record = session.fetch_raw(uid)
    return email.message_from_bytes(raw, policy=POLICY), record, raw


def op_message(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        msg, record, raw = _load_message(session, payload)
        header_end = raw.find(b"\r\n\r\n")
        result = summary(record["meta"], raw[: header_end + 4] if header_end >= 0 else raw[:65536])
        html_part, text_part, attachments = message_parts(msg)
        result["html"] = _text(html_part) if html_part is not None else ""
        result["text"] = _text(text_part) if text_part is not None else ""
        plain = result["text"] or re.sub(r"<[^>]+>", " ", result["html"])
        result["preview"] = re.sub(r"\s+", " ", html.unescape(plain)).strip()[:255]
        result["cc"] = _addresses(msg.get("Cc"))
        result["bcc"] = _addresses(msg.get("Bcc"))
        result["reply_to"] = _addresses(msg.get("Reply-To"))
        sender = _addresses(msg.get("Sender"))
        result["sender"] = sender[0] if sender else result["from"]
        result["message_id"] = str(msg.get("Message-ID", "") or "").strip()
        listing = []
        for index, part in enumerate(attachments):
            content_id = _content_id(part)
            disposition = part.get_content_disposition()
            listing.append({
                "index": index, "name": _attachment_name(part, index), "content_type": part.get_content_type(),
                "content_id": content_id, "size": len(_attachment_bytes(part)),
                "inline": bool(content_id) and disposition != "attachment",
            })
        result["attachments"] = listing
        result["has_attachments"] = any(not a["inline"] for a in listing)
        return result
    return with_imap(account, work)


def op_headers(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        uid = int(payload.get("uid", 0))
        session.select_checked(str(payload.get("folder", "")), int(payload.get("uidvalidity", 0)), True)
        raw, _ = session.fetch_raw(uid, "BODY.PEEK[HEADER]")
        headers = email.parser.BytesHeaderParser(policy=POLICY).parsebytes(raw)
        return {"headers": raw.decode("utf-8", "replace"), "subject": str(headers.get("Subject", "") or "")}
    return with_imap(account, work)


def op_attachment(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        msg, _, _ = _load_message(session, payload)
        _, _, attachments = message_parts(msg)
        index = int(payload.get("index", -1))
        if index < 0 or index >= len(attachments):
            raise ProxyError("not_found", "Anhang nicht gefunden.", 404)
        part = attachments[index]
        return {
            "name": _attachment_name(part, index), "content_type": part.get_content_type(),
            "content": base64.b64encode(_attachment_bytes(part)).decode("ascii"),
        }
    return with_imap(account, work)


def _uid_list(payload: dict) -> list:
    uids = payload.get("uids")
    if not isinstance(uids, list) or not uids or len(uids) > 500:
        raise ProxyError("invalid", "Ungültige Auswahl.", 422)
    try:
        values = sorted({int(u) for u in uids})
    except (TypeError, ValueError) as exc:
        raise ProxyError("invalid", "Ungültige Auswahl.", 422) from exc
    if values[0] <= 0:
        raise ProxyError("invalid", "Ungültige Auswahl.", 422)
    return values


def op_flags(account: Account, payload: dict) -> dict:
    uids = _uid_list(payload)
    add = [f for f in payload.get("add", []) if f in ALLOWED_FLAGS]
    remove = [f for f in payload.get("remove", []) if f in ALLOWED_FLAGS]

    def work(session: ImapSession) -> dict:
        session.select_checked(str(payload.get("folder", "")), int(payload.get("uidvalidity", 0)), False)
        uid_set = ",".join(str(u) for u in uids)
        if add:
            _ok(session.conn.uid("STORE", uid_set, "+FLAGS.SILENT", "(" + " ".join(add) + ")")[0], "STORE")
        if remove:
            _ok(session.conn.uid("STORE", uid_set, "-FLAGS.SILENT", "(" + " ".join(remove) + ")")[0], "STORE")
        return {"updated": len(uids)}
    return with_imap(account, work)


def _move(session: ImapSession, uids: list, target_raw: str) -> None:
    uid_set = ",".join(str(u) for u in uids)
    if "MOVE" in session.capabilities:
        _ok(session.conn.uid("MOVE", uid_set, quote(target_raw))[0], "MOVE")
        return
    _ok(session.conn.uid("COPY", uid_set, quote(target_raw))[0], "COPY")
    session.expunge_uids(uids)


def op_move(account: Account, payload: dict) -> dict:
    uids = _uid_list(payload)

    def work(session: ImapSession) -> dict:
        target = session.resolve(str(payload.get("target", "")), create=True)
        source = session.select_checked(str(payload.get("folder", "")), int(payload.get("uidvalidity", 0)), False)
        if source != target:
            _move(session, uids, target)
        return {"moved": len(uids)}
    return with_imap(account, work)


def op_delete(account: Account, payload: dict) -> dict:
    uids = _uid_list(payload)
    permanent = bool(payload.get("permanent", False))

    def work(session: ImapSession) -> dict:
        source = session.resolve(str(payload.get("folder", "")))
        trash = None if permanent else session.resolve("deleteditems", create=True)
        session.select_checked(str(payload.get("folder", "")), int(payload.get("uidvalidity", 0)), False)
        if trash is None or trash == source:
            session.expunge_uids(uids)
        else:
            _move(session, uids, trash)
        return {"deleted": len(uids)}
    return with_imap(account, work)


def op_quota(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        if "QUOTA" not in session.capabilities:
            return {"used": 0, "limit": 0}
        try:
            typ, data = session.conn.getquotaroot("INBOX")
        except imaplib.IMAP4.error:
            return {"used": 0, "limit": 0}
        parts = []
        for group in data or []:
            for item in group if isinstance(group, list) else [group]:
                parts.append(item.decode("ascii", "replace") if isinstance(item, bytes) else str(item))
        match = re.search(r"STORAGE (\d+) (\d+)", " ".join(parts))
        if typ != "OK" or not match:
            return {"used": 0, "limit": 0}
        return {"used": int(match.group(1)) * 1024, "limit": int(match.group(2)) * 1024}
    return with_imap(account, work)


# ---------------------------------------------------------------------------
# Nachrichten erstellen, Entwuerfe, Versand
# ---------------------------------------------------------------------------


class _TextExtractor(html.parser.HTMLParser):
    BLOCK = {"p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "blockquote", "table"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list = []
        self.skip = 0

    def handle_starttag(self, tag, attrs):
        if tag in ("style", "script", "head"):
            self.skip += 1
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in ("style", "script", "head") and self.skip:
            self.skip -= 1
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data):
        if not self.skip:
            self.parts.append(data)


def html_to_text(markup: str) -> str:
    parser = _TextExtractor()
    try:
        parser.feed(markup)
        parser.close()
    except Exception:  # noqa: BLE001
        return re.sub(r"<[^>]+>", " ", markup)
    text = "".join(parser.parts)
    return re.sub(r"\n{3,}", "\n\n", re.sub(r"[ \t]+", " ", text)).strip() + "\n"


def _clean_header(value: str, limit: int = 998) -> str:
    return re.sub(r"[\r\n\x00]+", " ", value).strip()[:limit]


def recipients(values: object) -> list:
    if not isinstance(values, list):
        return []
    result = []
    for value in values[:500]:
        name, addr = email.utils.parseaddr(_clean_header(str(value), 500))
        if not addr or EMAIL_RE.match(addr) is None:
            raise ProxyError("invalid", "Ungültige Empfängeradresse.", 422)
        result.append(email.utils.formataddr((name, addr)) if name else addr)
    return result


def _add_attachment(msg: EmailMessage, name: str, content_type: str, content: bytes, content_id: str = "", inline: bool = False) -> None:
    maintype, _, subtype = (content_type or "application/octet-stream").lower().partition("/")
    if not re.match(r"^[a-z0-9.+-]+$", maintype or "") or not re.match(r"^[a-z0-9.+-]+$", subtype or ""):
        maintype, subtype = "application", "octet-stream"
    kwargs = {"filename": _clean_header(name, 200) or "anhang", "disposition": "inline" if inline else "attachment"}
    if content_id:
        kwargs["cid"] = "<" + _clean_header(content_id, 200).strip("<>") + ">"
    if maintype == "message" and subtype == "rfc822":
        try:
            msg.add_attachment(email.message_from_bytes(content, policy=POLICY), **kwargs)
            return
        except Exception:  # noqa: BLE001 - Rueckfall als Datei
            pass
    if maintype in ("multipart", "message"):
        maintype, subtype = "application", "octet-stream"
    msg.add_attachment(content, maintype=maintype, subtype=subtype, **kwargs)


def build_message(account: Account, data: object, extra: list | None = None,
                  reference: Message | None = None, mode: str = "") -> EmailMessage:
    if not isinstance(data, dict):
        raise ProxyError("invalid", "Nachricht fehlt.", 422)
    msg = EmailMessage(policy=POLICY)
    # Absender ist immer das zugeordnete Postfach (kein freies "From").
    msg["From"] = email.utils.formataddr((account.display_name, account.email)) if account.display_name else account.email
    to = recipients(data.get("to"))
    cc = recipients(data.get("cc"))
    bcc = recipients(data.get("bcc"))
    if to:
        msg["To"] = ", ".join(to)
    if cc:
        msg["Cc"] = ", ".join(cc)
    if bcc:
        msg["Bcc"] = ", ".join(bcc)
    msg["Subject"] = _clean_header(str(data.get("subject", "")))
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg["Message-ID"] = email.utils.make_msgid(domain=account.email.rsplit("@", 1)[-1])
    importance = str(data.get("importance", "Normal"))
    if importance == "High":
        msg["Importance"] = "high"
        msg["X-Priority"] = "1"
    elif importance == "Low":
        msg["Importance"] = "low"
        msg["X-Priority"] = "5"
    if reference is not None and mode in ("reply", "replyall"):
        parent_id = str(reference.get("Message-ID", "") or "").strip()
        if parent_id:
            msg["In-Reply-To"] = _clean_header(parent_id)
            refs = " ".join(str(reference.get("References", "") or "").split() + [parent_id])
            msg["References"] = _clean_header(refs[-4000:])
    msg["X-Mailer"] = "Orvanta"
    body = str(data.get("body", ""))
    if bool(data.get("html", True)):
        msg.set_content(html_to_text(body))
        msg.add_alternative(body, subtype="html")
    else:
        msg.set_content(body)
    attachments = data.get("attachments") or []
    if not isinstance(attachments, list) or len(attachments) > 100:
        raise ProxyError("invalid", "Zu viele Anhänge.", 422)
    total = len(body)
    for item in attachments:
        if not isinstance(item, dict):
            continue
        try:
            content = base64.b64decode(str(item.get("content", "")), validate=True)
        except ValueError as exc:
            raise ProxyError("invalid", "Anhang ungültig.", 422) from exc
        total += len(content)
        if total > MAX_MESSAGE:
            raise ProxyError("too_large", "Nachricht zu groß.", 413)
        _add_attachment(msg, str(item.get("name", "anhang")), str(item.get("content_type", "")), content)
    for name, content_type, content, content_id, inline in extra or []:
        total += len(content)
        if total > MAX_MESSAGE:
            raise ProxyError("too_large", "Nachricht zu groß.", 413)
        _add_attachment(msg, name, content_type, content, content_id, inline)
    return msg


def _reference(session: ImapSession, payload: dict) -> tuple:
    ref = payload.get("reference")
    if not isinstance(ref, dict):
        return None, "", None
    mode = str(ref.get("mode", "reply"))
    if mode not in ("reply", "replyall", "forward"):
        mode = "reply"
    msg, _, _ = _load_message(session, ref)
    return msg, mode, ref


def carried_attachments(msg: Message | None, include_inline: bool) -> list:
    if msg is None:
        return []
    _, _, attachments = message_parts(msg)
    carried = []
    for index, part in enumerate(attachments):
        content_id = _content_id(part)
        inline = bool(content_id) and part.get_content_disposition() != "attachment"
        if inline and not include_inline:
            continue
        carried.append((_attachment_name(part, index), part.get_content_type(), _attachment_bytes(part), content_id, inline))
    return carried


def _prepare(session: ImapSession, account: Account, payload: dict) -> tuple:
    reference, mode, ref = _reference(session, payload)
    extra: list = []
    draft = payload.get("draft") if isinstance(payload.get("draft"), dict) else None
    if draft is not None:
        # Anhaenge des bisherigen Entwurfs uebernehmen (Orvanta sendet nur neue).
        old, _, _ = _load_message(session, draft)
        extra.extend(carried_attachments(old, include_inline=True))
    elif reference is not None and mode == "forward":
        extra.extend(carried_attachments(reference, include_inline=False))
    msg = build_message(account, payload.get("message"), extra, reference, mode)
    return msg, draft, mode, ref


def _delete_draft(session: ImapSession, draft: dict) -> None:
    try:
        session.select_checked(str(draft.get("folder", "")), int(draft.get("uidvalidity", 0)), False)
        session.expunge_uids([int(draft.get("uid", 0))])
    except Exception as exc:  # noqa: BLE001 - Aufraeumen ist nachrangig
        log("warning", "draft cleanup failed", error=type(exc).__name__)


def op_save_draft(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        msg, draft, _, _ = _prepare(session, account, payload)
        target = session.resolve("drafts", create=True)
        data = msg.as_bytes(policy=POLICY)
        uid = session.append(target, "(\\Draft \\Seen)", data)
        uidvalidity = session.select(target, True)
        if draft is not None:
            _delete_draft(session, draft)
        return {"uid": uid, "uidvalidity": uidvalidity, "folder": "drafts"}
    return with_imap(account, work)


def _smtp_connect(account: Account, stages: list) -> smtplib.SMTP:
    cfg = account.smtp
    ip = resolve_target(cfg["host"], cfg["port"], SMTP_PORTS)
    context = tls_context(account.verify_tls)
    stages[0] = "SMTP-Verbindung"
    conn = PinnedSMTPSSL(ip, context, account.timeout) if cfg["security"] == "tls" else PinnedSMTP(ip, account.timeout)
    try:
        conn.connect(cfg["host"], cfg["port"])
        conn.ehlo()
        if cfg["security"] == "starttls":
            stages[0] = "SMTP-STARTTLS"
            conn.starttls(context=context)
            conn.ehlo()
        if cfg["auth"]:
            stages[0] = "SMTP-Anmeldung"
            conn.login(cfg["username"], cfg["password"])
    except Exception:
        _smtp_close(conn)
        raise
    return conn


def _smtp_close(conn: smtplib.SMTP | None) -> None:
    if conn is None:
        return
    try:
        conn.quit()
    except Exception:  # noqa: BLE001
        try:
            conn.close()
        except Exception:  # noqa: BLE001
            pass


def smtp_send(account: Account, msg: EmailMessage) -> None:
    stages = ["SMTP"]
    conn = None
    try:
        conn = _smtp_connect(account, stages)
        stages[0] = "SMTP-Versand"
        envelope = [addr for _, addr in email.utils.getaddresses(
            [str(v) for v in (msg.get_all("To", []) + msg.get_all("Cc", []) + msg.get_all("Bcc", []))]
        ) if addr]
        if not envelope:
            raise ProxyError("invalid", "Keine Empfänger.", 422)
        # send_message entfernt Bcc aus den uebertragenen Kopfzeilen.
        conn.send_message(msg, from_addr=account.email, to_addrs=envelope)
    except ProxyError:
        raise
    except Exception as exc:  # noqa: BLE001
        raise classify(exc, stages[0]) from None
    finally:
        _smtp_close(conn)


def op_send(account: Account, payload: dict) -> dict:
    def work(session: ImapSession) -> dict:
        msg, draft, mode, ref = _prepare(session, account, payload)
        data = msg.as_bytes(policy=POLICY)
        if len(data) > MAX_MESSAGE:
            raise ProxyError("too_large", "Nachricht zu groß.", 413)
        smtp_send(account, msg)
        result = {"uid": 0, "uidvalidity": 0, "folder": "sentitems"}
        # Ab hier ist die Nachricht versendet: Nacharbeiten duerfen keinen
        # Fehler (und damit keinen erneuten Versand) ausloesen.
        try:
            target = session.resolve("sentitems", create=True)
            result["uid"] = session.append(target, "(\\Seen)", data)
            result["uidvalidity"] = session.select(target, True)
        except Exception as exc:  # noqa: BLE001
            log("warning", "sent copy failed", mailbox_id=account.mailbox_id, error=type(exc).__name__)
        if ref is not None:
            try:
                session.select_checked(str(ref.get("folder", "")), int(ref.get("uidvalidity", 0)), False)
                flag = "$Forwarded" if mode == "forward" else "\\Answered"
                session.conn.uid("STORE", str(int(ref.get("uid", 0))), "+FLAGS.SILENT", "(" + flag + ")")
            except Exception as exc:  # noqa: BLE001
                log("warning", "reference flag failed", mailbox_id=account.mailbox_id, error=type(exc).__name__)
        if draft is not None:
            _delete_draft(session, draft)
        return result
    return with_imap(account, work)


def op_test(account: Account, payload: dict) -> dict:
    """Prueft SMTP (Verbindung, TLS, Anmeldung) und IMAP – ohne Mailversand."""
    checks = []
    cfg = account.smtp
    stages = ["SMTP"]
    conn = None
    try:
        conn = _smtp_connect(account, stages)
        conn.noop()
        checks.append({"name": "SMTP-Verbindung", "ok": True, "message": f"{cfg['host']}:{cfg['port']}"})
        if cfg["security"] == "none":
            checks.append({"name": "SMTP-TLS", "ok": True, "message": "nicht verwendet (internes Relay)"})
        else:
            checks.append({"name": "SMTP-TLS", "ok": True, "message": "Zertifikat geprüft" if account.verify_tls else "ohne Zertifikatsprüfung"})
        if cfg["auth"]:
            checks.append({"name": "SMTP-Anmeldung", "ok": True, "message": ""})
    except Exception as exc:  # noqa: BLE001
        error = classify(exc, stages[0])
        checks.append({"name": stages[0], "ok": False, "code": error.code, "message": error.message})
    finally:
        _smtp_close(conn)
    try:
        session = ImapSession(account)
        checks.append({"name": "IMAP-Verbindung/TLS", "ok": True, "message": f"{account.imap['host']}:{account.imap['port']}"})
        checks.append({"name": "IMAP-Anmeldung", "ok": True, "message": ""})
        try:
            session.select("INBOX", True)
            checks.append({"name": "IMAP-Posteingang", "ok": True, "message": ""})
        except Exception as exc:  # noqa: BLE001
            error = classify(exc, "IMAP")
            checks.append({"name": "IMAP-Posteingang", "ok": False, "code": error.code, "message": error.message})
        session.close()
    except Exception as exc:  # noqa: BLE001
        error = classify(exc, "IMAP")
        checks.append({"name": "IMAP", "ok": False, "code": error.code, "message": error.message})
    return {"checks": checks}


OPERATIONS = {
    "imap.folders": op_folders,
    "imap.create_folder": op_create_folder,
    "imap.mark_folder_read": op_mark_folder_read,
    "imap.folder_status": op_folder_status,
    "imap.messages": op_messages,
    "imap.message": op_message,
    "imap.headers": op_headers,
    "imap.attachment": op_attachment,
    "imap.flags": op_flags,
    "imap.move": op_move,
    "imap.delete": op_delete,
    "imap.quota": op_quota,
    "imap.save_draft": op_save_draft,
    "smtp.send": op_send,
    "mailbox.test": op_test,
}


# ---------------------------------------------------------------------------
# HTTP-Server
# ---------------------------------------------------------------------------


class Stats:
    def __init__(self) -> None:
        self.started = time.time()
        self.lock = threading.Lock()
        self.active = 0
        self.requests = 0
        self.errors = 0


STATS = Stats()
KEYS = KeyStore(KEY_FILE)
NONCES = NonceCache()
SLOTS = threading.BoundedSemaphore(MAX_CONNECTIONS)


class Handler(BaseHTTPRequestHandler):
    server_version = "mail-proxy/" + VERSION
    sys_version = ""
    protocol_version = "HTTP/1.1"
    timeout = 30  # Zeitlimit fuer das Lesen der Anfrage

    def log_message(self, format, *args):  # noqa: A002 - eigene Protokollierung
        return

    def _send(self, status: int, body: dict) -> None:
        data = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(data)
        self.close_connection = True

    def _error(self, error: ProxyError) -> None:
        self._send(error.status, {"ok": False, "error": {"code": error.code, "message": error.message}})

    def do_GET(self) -> None:  # noqa: N802
        if self.path != "/health":
            self._error(ProxyError("not_found", "Unbekannter Pfad.", 404))
            return
        with STATS.lock:
            snapshot = {"connections": STATS.active, "requests": STATS.requests, "errors": STATS.errors}
        key_ok = KEYS.key() is not None
        self._send(200, {
            "status": "ok" if key_ok else "degraded",
            "version": VERSION,
            "uptime": int(time.time() - STATS.started),
            "max_connections": MAX_CONNECTIONS,
            "pooled": POOL.size(),
            "key": key_ok,
            **snapshot,
        })

    def do_POST(self) -> None:  # noqa: N802
        operation = self.path[4:] if self.path.startswith("/v1/") else ""
        if OP_RE.match(operation) is None or (operation not in OPERATIONS and operation != "cache.invalidate"):
            self._error(ProxyError("not_found", "Unbekannte Operation.", 404))
            return
        try:
            length = int(self.headers.get("Content-Length", "-1"))
        except ValueError:
            length = -1
        if length < 0:
            self._error(ProxyError("invalid", "Content-Length fehlt.", 411))
            return
        if length > MAX_BODY:
            self._error(ProxyError("too_large", "Anfrage zu groß.", 413))
            return
        if not SLOTS.acquire(blocking=False):
            self._error(ProxyError("busy", "Mail-Proxy ausgelastet.", 503))
            return
        started = time.monotonic()
        with STATS.lock:
            STATS.active += 1
            STATS.requests += 1
        mailbox_id = None
        try:
            body = self.rfile.read(length)
            self._authenticate(operation, body)
            payload = json.loads(body.decode("utf-8")) if body else {}
            del body
            if not isinstance(payload, dict):
                raise ProxyError("invalid", "Ungültige Anfrage.", 422)
            if operation == "cache.invalidate":
                closed = POOL.clear()
                log("info", "cache invalidated", pooled_closed=closed)
                self._send(200, {"ok": True, "data": {"closed": closed}})
                return
            account = Account(payload.pop("account", None))
            mailbox_id = account.mailbox_id
            data = OPERATIONS[operation](account, payload)
            del account, payload
            self._send(200, {"ok": True, "data": data})
            log("info", "request", op=operation, mailbox_id=mailbox_id, ms=int((time.monotonic() - started) * 1000))
        except ProxyError as exc:
            with STATS.lock:
                STATS.errors += 1
            log("warning" if exc.status < 500 else "error", "request failed", op=operation, mailbox_id=mailbox_id,
                code=exc.code, message=exc.message, ms=int((time.monotonic() - started) * 1000))
            self._error(exc)
        except (ValueError, UnicodeDecodeError):
            with STATS.lock:
                STATS.errors += 1
            self._error(ProxyError("invalid", "Ungültige Anfrage.", 422))
        except Exception as exc:  # noqa: BLE001
            with STATS.lock:
                STATS.errors += 1
            log("error", "request crashed", op=operation, mailbox_id=mailbox_id, error=type(exc).__name__)
            self._error(ProxyError("internal", "Interner Fehler im Mail-Proxy.", 500))
        finally:
            with STATS.lock:
                STATS.active -= 1
            SLOTS.release()

    def _authenticate(self, operation: str, body: bytes) -> None:
        key = KEYS.key()
        if key is None:
            raise ProxyError("unauthorized", "Schlüssel nicht verfügbar.", 401)
        timestamp = self.headers.get("X-Mail-Proxy-Timestamp", "")
        nonce = self.headers.get("X-Mail-Proxy-Nonce", "")
        provided = self.headers.get("X-Mail-Proxy-Signature", "")
        if not timestamp.isdigit() or abs(time.time() - int(timestamp)) > MAX_SKEW or NONCE_RE.match(nonce) is None:
            raise ProxyError("unauthorized", "Anfrage abgelehnt.", 401)
        expected = signature(key, timestamp, nonce, operation, body)
        if not hmac.compare_digest(expected, provided):
            raise ProxyError("unauthorized", "Anfrage abgelehnt.", 401)
        if not NONCES.use(nonce):
            raise ProxyError("unauthorized", "Anfrage abgelehnt.", 401)


class Server(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True
    request_queue_size = 64


def _janitor(stop: threading.Event) -> None:
    while not stop.wait(15):
        try:
            POOL.sweep()
        except Exception as exc:  # noqa: BLE001
            log("warning", "pool sweep failed", error=type(exc).__name__)


def healthcheck() -> int:
    import urllib.request

    try:
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with opener.open(f"http://127.0.0.1:{LISTEN_PORT}/health", timeout=4) as response:  # noqa: S310
            # Ohne lesbaren Schluessel ("degraded") ist der Dienst unbrauchbar.
            return 0 if response.status == 200 and json.loads(response.read()).get("status") == "ok" else 1
    except Exception:  # noqa: BLE001
        return 1


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] == "--healthcheck":
        return healthcheck()
    server = Server((LISTEN_HOST, LISTEN_PORT), Handler)
    stop = threading.Event()
    threading.Thread(target=_janitor, args=(stop,), daemon=True).start()

    def shutdown(signum, frame):  # noqa: ARG001
        log("info", "shutdown", signal=signum)
        stop.set()
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)
    log("info", "started", version=VERSION, port=LISTEN_PORT, max_connections=MAX_CONNECTIONS,
        pool_size=POOL_SIZE, key=KEYS.key() is not None)
    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        POOL.clear()
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
