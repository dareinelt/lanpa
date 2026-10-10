#!/bin/sh
# Uebernimmt die Weiterleitungsziele externer Navigationskacheln aus der
# Anwendung (Adminbereich -> Navigation) und erzeugt daraus die
# Proxy-Konfiguration des auth-Containers (/weiterleitung/<id>/, siehe
# docs/weiterleitung.md).
#
#   weiterleitung-sync.sh once   einmalig abrufen und anwenden (vor dem Start)
#   weiterleitung-sync.sh loop   alle NAV_PROXY_INTERVAL Sekunden (Standard 60)
#                                abrufen; bei Aenderungen Apache neu laden
#
# Antwort von /internal/nav-proxy-config: je Zeile NAME=base64(wert).
#   NAV_PROXY_COUNT        Anzahl der Ziele
#   NAV_PROXY_<n>_PATH     oeffentlicher Pfad (/weiterleitung/<id>/)
#   NAV_PROXY_<n>_TARGET   Ziel-URL (Basis der Zielanwendung, Zielpfad
#                          inklusive; die Anwendung haengt ihn an den
#                          oeffentlichen Pfad an)
#   NAV_PROXY_<n>_TITLE    Bezeichnung der Kachel (nur fuer die Konfiguration)
#
# Die Anwendung entscheidet anhand der Client-Adresse, ob eine Kachel auf diesen
# Adressraum oder direkt zeigt; die Quellnetz-Ausnahmen sind deshalb nicht Teil
# dieser Konfiguration.
set -eu

CONF_FILE="${NAV_PROXY_FILE:-/etc/apache2/intranet/weiterleitung.conf}"
TOKEN_FILE="${SSO_CONFIG_TOKEN_FILE:-/run/intranet-sso/token}"
URL="${NAV_PROXY_URL:-http://app/internal/nav-proxy-config}"
INTERVAL="${NAV_PROXY_INTERVAL:-60}"
SOURCE="${SSO_SOURCE:-}"
UNAVAILABLE_PATH="/weiterleitung-nicht-verfuegbar"
# Pruefung des Zielzertifikats (wie docker/auth/llmint.conf): require gegen die
# System-CAs, none nur fuer Ziele mit eigenem/self-signed Zertifikat.
SSL_VERIFY="${NAV_PROXY_SSL_VERIFY:-require}"

log() {
    echo "[auth] $*"
}

warn() {
    echo "[auth] WARNUNG: $*" >&2
}

# Wert eines Schluessels aus der Antwort der Anwendung (base64 dekodiert).
value() {
    while IFS='=' read -r name encoded || [ -n "$name" ]; do
        if [ "$name" = "$2" ]; then
            printf '%s' "$encoded" | base64 -d 2>/dev/null || true
            return 0
        fi
    done < "$1"

    return 1
}

# Ziel-URL zerlegen: Origin (Schema, Host, Port) und Pfad. Dieselbe
# Einschraenkung prueft die Anwendung beim Speichern der Kachel
# (App\\Support\\TileProxy::isProxyableUrl).
url_ok() {
    printf '%s' "$1" | grep -Eq '^https?://[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:[0-9]{1,5})?(/[A-Za-z0-9._~%+@:=-]*)*(\?[^#[:space:]]*)?(#[^[:space:]]*)?$'
}

# Quellnetz-/Pfadangaben fuer die Konfiguration entschaerfen: nur Ziffern,
# Schraegstriche und die fuer die Zielpfade erlaubten Zeichen.
path_ok() {
    printf '%s' "$1" | grep -Eq '^/weiterleitung/[0-9]+/$'
}

# Liest die Ziele aus der Antwort in eine Liste "pfad|origin|host|zielpfad|titel".
# Ungueltige Eintraege werden uebersprungen (Schutz der erzeugten Konfiguration).
collect_routes() {
    response="$1"
    count="$2"
    : > "$3"

    index=0
    while [ "$index" -lt "$count" ]; do
        path="$(value "$response" "NAV_PROXY_${index}_PATH" || true)"
        target="$(value "$response" "NAV_PROXY_${index}_TARGET" || true)"
        title="$(value "$response" "NAV_PROXY_${index}_TITLE" | tr -d '|' || true)"
        index=$((index + 1))

        if ! path_ok "$path"; then
            warn "Ungueltiger Weiterleitungspfad '${path}' ignoriert."
            continue
        fi
        if ! url_ok "$target"; then
            warn "Ziel '${target}' kann nicht gespiegelt werden und wird ignoriert."
            continue
        fi

        origin="$(printf '%s' "$target" | sed -E 's#^(https?://[^/]+).*$#\1#')"
        target_path="$(printf '%s' "$target" | sed -E 's#^https?://[^/]+##')"
        host="$(printf '%s' "$origin" | sed -E 's#^[a-zA-Z]+://##')"
        printf '%s|%s|%s|%s|%s\n' "$path" "$origin" "$host" "$target_path" "$title" >> "$3"
    done
}

# Schreibt die Apache-Konfiguration nach stdout.
generate() {
    routes="$1"

    echo "# Erzeugt von weiterleitung-sync.sh - nicht von Hand bearbeiten."
    echo "# Externe Navigationskacheln ueber den Reverse-Proxy des Intranets"
    echo "# (Adminbereich -> Navigation, docs/weiterleitung.md)."
    echo "#"
    echo "# Das Ziel wird unter /weiterleitung/<id>/ gespiegelt: der Pfad hinter"
    echo "# dem Praefix geht unveraendert an den Origin (der Zielpfad steckt"
    echo "# bereits im Verweis der Kachel). Absolute Adressen in HTML, CSS und"
    echo "# JavaScript werden auf den Praefix umgeschrieben."
    echo

    if [ -s "$routes" ] && cut -d'|' -f2 "$routes" | grep -q '^https://'; then
        echo "# Ziele per HTTPS: Zertifikat des Ziels gegen die System-CAs pruefen."
        echo "SSLProxyEngine On"
        case "$SSL_VERIFY" in
            none)
                echo "# NAV_PROXY_SSL_VERIFY=none: Zertifikat und Name des Ziels werden nicht geprueft."
                echo "SSLProxyVerify none"
                echo "SSLProxyCheckPeerCN off"
                echo "SSLProxyCheckPeerName off"
                ;;
            *)
                echo "SSLProxyVerify require"
                echo "SSLProxyCACertificateFile /etc/ssl/certs/ca-certificates.crt"
                echo "SSLProxyCheckPeerName On"
                ;;
        esac
        echo
    fi

    # Adressraum ohne hinterlegtes Ziel: nicht an die Anwendung weiterreichen.
    echo "# Kein Ziel hinterlegt: Adressraum /weiterleitung/ bleibt beim Proxy."
    echo "<Location /weiterleitung/>"
    echo "    ProxyPass \"!\""
    echo "    ErrorDocument 404 ${UNAVAILABLE_PATH}"
    echo "</Location>"
    echo
    echo "# Hinweisseite des Proxys ist ohne Windows-Anmeldung erreichbar."
    echo "<Location ${UNAVAILABLE_PATH}>"
    echo "    <IfDefine SSO_NTLM>"
    echo "        AuthType None"
    echo "    </IfDefine>"
    echo "    Require all granted"
    echo "</Location>"
    echo

    while IFS='|' read -r path origin host target_path title || [ -n "$path" ]; do
        [ -n "$path" ] || continue
        id="$(printf '%s' "$path" | sed -E 's#^/weiterleitung/([0-9]+)/$#\1#')"

        echo "# Kachel ${id}: ${title} -> ${origin}${target_path}"

        echo "<Location ${path}>"
        echo "    <IfDefine SSO_NTLM>"
        echo "        # Das Ziel authentifiziert selbst; keine Windows-Anmeldung."
        echo "        AuthType None"
        echo "    </IfDefine>"
        echo "    Require all granted"
        echo
        # Der Zielpfad wird nicht abgeschnitten: /weiterleitung/<id>/x -> Origin/x.
        echo "    ProxyPass ${origin}/ upgrade=websocket timeout=600 retry=3"
        echo "    ProxyPassReverse ${origin}/"
        echo
        # Cookies gehoeren dem Proxy-Host: Pfad auf den Praefix umschreiben,
        # Domain-Angabe entfernen (sonst verwirft der Browser sie).
        echo "    ProxyPassReverseCookiePath / ${path}"
        echo "    Header edit Set-Cookie \"(?i);[[:space:]]*domain=[^;]*\" \"\""
        # Umleitungen des Ziels: absolute Adresse auf den Praefix umschreiben ...
        echo "    Header edit Location \"^https?://${host}/\" \"${path}\""
        # ... und wurzelrelative Pfade voranstellen (bereits umgeschriebene nicht).
        echo "    Header edit Location \"^/(?!weiterleitung/${id}/)\" \"${path}\""
        echo
        # Unkomprimiert, damit die Adressen unten umgeschrieben werden koennen.
        echo "    SetEnv no-gzip 1"
        echo "    RequestHeader unset Accept-Encoding"
        echo
        # Die Zielanwendung darf von der Weiterleitung nichts mitbekommen: sie
        # sieht ihren eigenen Host und baut darauf ihre Adressen.
        echo "    ProxyAddHeaders On"
        echo "    RequestHeader set Host \"${host}\""
        echo "    RequestHeader set X-Forwarded-Prefix \"${path%/}\""
        echo "    RequestHeader unset X-Remote-User"
        echo "    RequestHeader unset X-Remote-Source"
        echo
        # Absolute Adressen im HTML (mod_proxy_html) ...
        echo "    ProxyHTMLEnable On"
        echo "    ProxyHTMLURLMap https://${host} /weiterleitung/${id}"
        echo "    ProxyHTMLURLMap http://${host} /weiterleitung/${id}"
        echo "    ProxyHTMLURLMap //${host} /weiterleitung/${id}"
        echo "    ProxyHTMLURLMap / ${path}"
        echo
        # ... und in CSS/JavaScript (mod_substitute).
        echo "    AddOutputFilterByType SUBSTITUTE text/css application/javascript"
        echo "    Substitute \"s|https://${host}/|${path}|i\""
        echo "    Substitute \"s|http://${host}/|${path}|i\""
        echo "    Substitute \"s|//${host}/|${path}|i\""
        echo
        echo "    ErrorDocument 500 ${UNAVAILABLE_PATH}"
        echo "    ErrorDocument 502 ${UNAVAILABLE_PATH}"
        echo "    ErrorDocument 503 ${UNAVAILABLE_PATH}"
        echo "</Location>"
        echo
    done < "$routes"
}

apache_running() {
    pid_file="${APACHE_PID_FILE:-/var/run/apache2/apache2.pid}"
    [ -s "$pid_file" ] && kill -0 "$(cat "$pid_file")" 2>/dev/null
}

reload_apache() {
    if apache2ctl -t >/dev/null 2>&1; then
        apache2ctl graceful
        log "Apache mit neuen Weiterleitungszielen neu geladen."
    else
        warn "Apache-Konfiguration ungueltig - kein Neuladen."
        apache2ctl -t >&2 || true
        return 1
    fi
}

# Ruft die Ziele ab und schreibt sie bei Aenderung. Rueckgabe:
# 0 = geaendert, 1 = unveraendert, 2 = Fehler.
sync_once() {
    [ -r "$TOKEN_FILE" ] || { warn "Token ${TOKEN_FILE} fehlt - Weiterleitungsziele nicht abrufbar."; return 2; }

    work="$(mktemp -d)"
    printf 'X-Intranet-Sso-Token: %s\n' "$(tr -d '\r\n' < "$TOKEN_FILE")" > "$work/header"
    url="$URL"
    if [ -n "$SOURCE" ]; then
        url="${URL}?source=${SOURCE}"
    fi
    if ! curl -fsS --max-time 10 -H "@${work}/header" -o "$work/response" "$url" 2>"$work/error"; then
        warn "Weiterleitungsziele nicht abrufbar: $(cat "$work/error")"
        rm -rf "$work"
        return 2
    fi

    count="$(value "$work/response" NAV_PROXY_COUNT || true)"
    case "$count" in
        ''|*[!0-9]*) warn "Ungueltige Anzahl der Weiterleitungsziele '${count}'."; rm -rf "$work"; return 2 ;;
    esac

    collect_routes "$work/response" "$count" "$work/routes"
    generate "$work/routes" > "$work/conf"

    if [ -f "$CONF_FILE" ] && cmp -s "$work/conf" "$CONF_FILE"; then
        rm -rf "$work"
        return 1
    fi

    # Erst pruefen, dann uebernehmen: eine ungueltige Konfiguration darf den
    # laufenden Proxy nicht ausser Betrieb setzen.
    if [ -f "$CONF_FILE" ]; then
        cp "$CONF_FILE" "$work/previous"
    fi
    install -m 0644 "$work/conf" "$CONF_FILE"
    if ! apache2ctl -t >/dev/null 2>&1; then
        warn "Erzeugte Weiterleitungskonfiguration ist ungueltig - bisherige bleibt aktiv."
        apache2ctl -t >&2 || true
        if [ -f "$work/previous" ]; then
            cp "$work/previous" "$CONF_FILE"
        else
            rm -f "$CONF_FILE"
        fi
        rm -rf "$work"
        return 2
    fi

    routes_count="$(grep -c . "$work/routes" || true)"
    rm -rf "$work"
    log "Weiterleitungsziele uebernommen (${routes_count} Ziel(e) von ${count})."
    return 0
}

case "${1:-once}" in
    once)
        result=0
        sync_once || result=$?
        # Ohne erreichbare Anwendung bleibt die Konfiguration leer; der
        # Adressraum /weiterleitung/ zeigt dann die Hinweisseite.
        if [ "$result" -eq 2 ] && [ ! -f "$CONF_FILE" ]; then
            generate /dev/null > "$CONF_FILE"
            log "Weiterleitungsziele noch nicht verfuegbar - leerer Adressraum aktiv."
        fi
        ;;
    loop)
        while :; do
            sleep "$INTERVAL"
            result=0
            sync_once || result=$?
            if [ "$result" -eq 0 ] && apache_running; then
                reload_apache || true
            fi
        done
        ;;
    *)
        echo "Aufruf: $0 once|loop" >&2
        exit 1
        ;;
esac
