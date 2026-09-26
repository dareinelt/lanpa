#!/bin/sh
# Uebernimmt Zertifikat, Schluessel und HTTP-Richtlinie aus der
# Zertifikatsverwaltung der Anwendung (Adminbereich -> Zertifikate).
#
#   tls-sync.sh once   einmalig abrufen und anwenden (vor dem Start)
#   tls-sync.sh loop   alle TLS_SYNC_INTERVAL Sekunden (Standard 60) abrufen;
#                      bei Aenderungen Apache bzw. HAProxy neu laden
#
# Antwort von /internal/tls-config: je Zeile NAME=base64(wert).
#   TLS_MODE           strict (gueltiges Zertifikat aktiv: immer HTTPS) oder
#                      fallback (Notfall-Zertifikat, HTTP aus TLS_HTTP_NETWORKS)
#   TLS_HTTP_NETWORKS  Quellnetze (CIDR, Leerzeichen getrennt)
#   TLS_CERT/TLS_KEY   Zertifikat (inkl. Kette) und privater Schluessel (PEM)
#   TLS_ID/TLS_LABEL   Kennung und Bezeichnung (nur fuer das Protokoll)
#
# Ist die Anwendung beim Start nicht erreichbar und liegt noch kein
# Zertifikat vor, wird lokal ein selbstsigniertes Notfall-Zertifikat erzeugt
# (Modus fallback mit TLS_DEFAULT_HTTP_NETWORKS).
set -eu

TLS_DIR="${TLS_DIR:-/etc/intranet-tls}"
POLICY_FILE="${TLS_POLICY_FILE:-/etc/apache2/intranet/http-policy.conf}"
TOKEN_FILE="${SSO_CONFIG_TOKEN_FILE:-/run/intranet-sso/token}"
URL="${TLS_CONFIG_URL:-http://app/internal/tls-config}"
INTERVAL="${TLS_SYNC_INTERVAL:-60}"
DEFAULT_NETWORKS="${TLS_DEFAULT_HTTP_NETWORKS:-192.168.200.0/21}"
HTTPS_PORT="${HTTPS_PUBLIC_PORT:-443}"

log() {
    echo "[auth] $*"
}

warn() {
    echo "[auth] WARNUNG: $*" >&2
}

mkdir -p "$TLS_DIR"
chmod 0700 "$TLS_DIR"

# Nur gueltige CIDR-Angaben uebernehmen (Schutz der erzeugten Konfiguration).
clean_networks() {
    for net in $1; do
        if printf '%s' "$net" | grep -Eq '^[0-9A-Fa-f:.]+/[0-9]{1,3}$'; then
            printf '%s ' "$net"
        else
            warn "Ungueltiges Quellnetz '${net}' ignoriert."
        fi
    done | sed 's/ $//'
}

# Prueft, ob Zertifikat und Schluessel zusammengehoeren.
pair_matches() {
    cert_key="$(openssl x509 -in "$1" -noout -pubkey 2>/dev/null | openssl pkey -pubin -outform DER 2>/dev/null | openssl dgst -sha256 2>/dev/null)" || return 1
    key_key="$(openssl pkey -in "$2" -pubout -outform DER 2>/dev/null | openssl dgst -sha256 2>/dev/null)" || return 1
    [ -n "$cert_key" ] && [ "$cert_key" = "$key_key" ]
}

# Schreibt die Dateien atomar in TLS_DIR (cert.pem, key.pem, server.pem, mode, networks).
install_files() {
    cert_src="$1"
    key_src="$2"
    mode="$3"
    networks="$4"

    umask 077
    cp "$cert_src" "$TLS_DIR/cert.pem.new"
    cp "$key_src" "$TLS_DIR/key.pem.new"
    cat "$cert_src" "$key_src" > "$TLS_DIR/server.pem.new"
    printf '%s\n' "$mode" > "$TLS_DIR/mode.new"
    printf '%s\n' "$networks" > "$TLS_DIR/networks.new"
    for file in cert.pem key.pem server.pem mode networks; do
        mv -f "$TLS_DIR/${file}.new" "$TLS_DIR/${file}"
    done
}

# Selbstsigniertes Zertifikat, falls die Anwendung nicht erreichbar ist.
local_fallback() {
    [ -s "$TLS_DIR/cert.pem" ] && [ -s "$TLS_DIR/key.pem" ] && return 0

    host="$(printf '%s' "${APP_URL:-http://localhost}" | sed -E 's#^[a-zA-Z]+://##; s#[/:].*$##')"
    [ -n "$host" ] || host=localhost
    work="$(mktemp -d)"
    cat > "$work/openssl.cnf" <<CNF
[req]
distinguished_name = dn
x509_extensions = v3_self
prompt = no
[dn]
CN = ${host}
O = Intranet (Notfall-Zertifikat)
[v3_self]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:${host}, DNS:localhost
CNF
    if ! openssl req -x509 -newkey rsa:2048 -nodes -days 30 -config "$work/openssl.cnf" \
        -keyout "$work/key.pem" -out "$work/cert.pem" >/dev/null 2>&1; then
        rm -rf "$work"
        warn "Notfall-Zertifikat konnte nicht erzeugt werden."
        return 1
    fi
    install_files "$work/cert.pem" "$work/key.pem" fallback "$(clean_networks "$DEFAULT_NETWORKS")"
    rm -rf "$work"
    warn "Zertifikatsverwaltung nicht erreichbar - lokales Notfall-Zertifikat (selbstsigniert) erzeugt."
}

# Apache-Richtlinie fuer den HTTP-VirtualHost (Einzelinstanz ohne Verteiler).
write_policy() {
    mode="$(cat "$TLS_DIR/mode" 2>/dev/null || echo fallback)"
    networks="$(cat "$TLS_DIR/networks" 2>/dev/null || true)"
    port_suffix=""
    [ "$HTTPS_PORT" = "443" ] || port_suffix=":${HTTPS_PORT}"

    tmp="${POLICY_FILE}.new"
    {
        echo "# Erzeugt von tls-sync.sh (Modus ${mode}) - nicht von Hand bearbeiten."
        echo "RewriteEngine On"
        echo "RewriteCond %{REQUEST_URI} !^/auth-health\$"
        if [ "$mode" != "strict" ] && [ -n "$networks" ]; then
            expr=""
            for net in $networks; do
                [ -n "$expr" ] && expr="${expr} || "
                expr="${expr}-R '${net}'"
            done
            echo "RewriteCond expr \"!(${expr})\""
        fi
        # Hostname ohne Port (IPv6-Literal in eckigen Klammern) als %1.
        echo "RewriteCond %{HTTP_HOST} ^(\\[[^]]+\\]|[^:]+)"
        echo "RewriteRule ^ https://%1${port_suffix}%{REQUEST_URI} [R=302,L,NE]"
    } > "$tmp"
    mv -f "$tmp" "$POLICY_FILE"
}

apache_running() {
    pid_file="${APACHE_PID_FILE:-/var/run/apache2/apache2.pid}"
    [ -s "$pid_file" ] && kill -0 "$(cat "$pid_file")" 2>/dev/null
}

haproxy_running() {
    [ -s /run/haproxy.pid ] && kill -0 "$(head -n 1 /run/haproxy.pid)" 2>/dev/null
}

reload_services() {
    if [ -f /etc/haproxy/sso-routes ]; then
        cfg=/etc/haproxy/haproxy.cfg
        /usr/local/bin/sso-routes.sh "$(cat /etc/haproxy/sso-routes)" > "${cfg}.new"
        if ! haproxy -c -q -f "${cfg}.new"; then
            warn "Neue HAProxy-Konfiguration ungueltig - bisherige bleibt aktiv."
            rm -f "${cfg}.new"
            return 1
        fi
        mv -f "${cfg}.new" "$cfg"
        if haproxy_running; then
            haproxy -D -f "$cfg" -p /run/haproxy.pid -sf $(cat /run/haproxy.pid)
            log "HAProxy mit neuer TLS-Konfiguration neu geladen."
        fi
    else
        write_policy
        if apache_running; then
            if apache2ctl -t >/dev/null 2>&1; then
                apache2ctl graceful
                log "Apache mit neuer TLS-Konfiguration neu geladen."
            else
                warn "Apache-Konfiguration ungueltig - kein Neuladen."
                apache2ctl -t >&2 || true
                return 1
            fi
        fi
    fi
}

# Ruft die Konfiguration ab und schreibt sie bei Aenderung. Rueckgabe:
# 0 = geaendert, 1 = unveraendert, 2 = Fehler.
sync_once() {
    [ -r "$TOKEN_FILE" ] || { warn "Token ${TOKEN_FILE} fehlt - Zertifikatskonfiguration nicht abrufbar."; return 2; }

    work="$(mktemp -d)"
    printf 'X-Intranet-Sso-Token: %s\n' "$(tr -d '\r\n' < "$TOKEN_FILE")" > "$work/header"
    if ! curl -fsS --max-time 10 -H "@${work}/header" -o "$work/response" "$URL" 2>"$work/error"; then
        warn "Zertifikatskonfiguration nicht abrufbar: $(cat "$work/error")"
        rm -rf "$work"
        return 2
    fi

    hash="$(openssl dgst -sha256 < "$work/response")"
    if [ -f "$TLS_DIR/.hash" ] && [ "$(cat "$TLS_DIR/.hash")" = "$hash" ] && [ -s "$TLS_DIR/cert.pem" ]; then
        rm -rf "$work"
        return 1
    fi

    mode=""
    networks=""
    label=""
    : > "$work/cert.pem"
    : > "$work/key.pem"
    while IFS='=' read -r name value || [ -n "$name" ]; do
        case "$name" in
            TLS_MODE) mode="$(printf '%s' "$value" | base64 -d 2>/dev/null || true)" ;;
            TLS_HTTP_NETWORKS) networks="$(printf '%s' "$value" | base64 -d 2>/dev/null || true)" ;;
            TLS_LABEL) label="$(printf '%s' "$value" | base64 -d 2>/dev/null || true)" ;;
            TLS_CERT) printf '%s' "$value" | base64 -d > "$work/cert.pem" 2>/dev/null || true ;;
            TLS_KEY) printf '%s' "$value" | base64 -d > "$work/key.pem" 2>/dev/null || true ;;
        esac
    done < "$work/response"

    case "$mode" in
        strict|fallback) ;;
        *) warn "Unbekannter TLS-Modus '${mode}'."; rm -rf "$work"; return 2 ;;
    esac
    if ! pair_matches "$work/cert.pem" "$work/key.pem"; then
        warn "Zertifikat und Schluessel aus der Verwaltung passen nicht zusammen - bisherige Konfiguration bleibt aktiv."
        rm -rf "$work"
        return 2
    fi

    install_files "$work/cert.pem" "$work/key.pem" "$mode" "$(clean_networks "$networks")"
    printf '%s\n' "$hash" > "$TLS_DIR/.hash"
    rm -rf "$work"

    if [ "$mode" = "strict" ]; then
        log "TLS: ${label:-Zertifikat} aktiv, HTTP wird auf HTTPS umgeleitet."
    else
        log "TLS: Notfallmodus (${label:-selbstsigniert}), HTTP erlaubt aus: $(cat "$TLS_DIR/networks")."
    fi
    return 0
}

case "${1:-once}" in
    once)
        attempts="${TLS_SYNC_RETRIES:-10}"
        result=2
        while [ "$attempts" -gt 0 ]; do
            result=0
            sync_once || result=$?
            [ "$result" -ne 2 ] && break
            attempts=$((attempts - 1))
            [ "$attempts" -gt 0 ] && sleep 2
        done
        [ "$result" -eq 2 ] && local_fallback
        [ -f /etc/haproxy/sso-routes ] || write_policy
        ;;
    loop)
        while :; do
            sleep "$INTERVAL"
            result=0
            sync_once || result=$?
            if [ "$result" -eq 0 ]; then
                reload_services || true
            fi
        done
        ;;
    *)
        echo "Aufruf: $0 once|loop" >&2
        exit 1
        ;;
esac
