#!/bin/sh
# Startskript des Einstiegs-/Authentifizierungs-Containers.
#
# - SSO_ENABLED=true:    Samba/winbind (security = domain, NTLM/RPC OHNE
#                        Kerberos) konfigurieren, ggf. Domaene beitreten und
#                        die NTLM-Anmeldung in Apache aktivieren (-D SSO_NTLM).
#                        SSO_DC darf mehrere Domaenencontroller enthalten
#                        (Leerzeichen/Komma getrennt); SSO_DC_IP die passenden
#                        IP-Adressen in derselben Reihenfolge.
# - SSO_SOURCE=KENNUNG:  Instanz fuer eine weitere Identitaetsquelle (eigene
#                        Domaene ohne Vertrauensstellung). Setzt den Header
#                        X-Remote-Source auf die Kennung (-D SSO_WORKER).
# - SSO_ROUTES:          Nur Hauptinstanz: verteilt Anfragen per HAProxy auf
#                        die Instanzen weiterer Quellen. Format je Route
#                        "KENNUNG|ziel|netz1,netz2|host1,host2", Routen durch
#                        ";" getrennt (erzeugt von scripts/sso-domains.sh).
# - SSO_PROXY_PROTOCOL:  Instanz erwartet das PROXY-Protokoll (hinter HAProxy).
# - Konfiguration:       Domaene, Domaenencontroller, Konto fuer den
#                        Domaenenbeitritt und SSO_ROUTES werden beim Start
#                        von der Anwendung abgerufen (im Adminbereich
#                        gepflegt, verschluesselt gespeichert). Nur wenn dort
#                        nichts hinterlegt ist, gelten die gleichnamigen
#                        Umgebungsvariablen (Altinstallationen).
# - OFFICE_ENABLED=true: Reverse-Proxy fuer Nextcloud/Euro-Office aktivieren
#                        (-D OFFICE, bindet /etc/apache2/intranet/office.conf ein).
# - TLS_ENABLED=true:    HTTPS auf Port 443 mit dem Zertifikat aus der
#                        Zertifikatsverwaltung (tls-sync.sh, alle 60 s
#                        abgeglichen). Ohne gueltiges Zertifikat gilt ein
#                        selbstsigniertes Notfall-Zertifikat, HTTP bleibt dann
#                        fuer die freigegebenen Quellnetze erlaubt. Nur in der
#                        Hauptinstanz (nicht bei SSO_SOURCE). Aus (false) z. B.
#                        hinter einem externen TLS-Proxy.
set -e

: "${SSO_ENABLED:=false}"
: "${SSO_SOURCE:=}"
: "${SSO_ROUTES:=}"
: "${SSO_PROXY_PROTOCOL:=false}"
: "${OFFICE_ENABLED:=false}"
: "${APP_URL:=http://localhost:8080}"
: "${TLS_ENABLED:=true}"
: "${HTTPS_PUBLIC_PORT:=443}"

is_true() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

# Liste aus Leerzeichen/Komma/Semikolon -> durch Leerzeichen getrennt.
split_list() {
    printf '%s' "$1" | tr ',;' '  ' | tr -s ' ' | sed 's/^ //; s/ $//'
}

APACHE_DEFINES=""
AUTH_HTTP_PORT=80

SSO_SOURCE="$(printf '%s' "$SSO_SOURCE" | tr '[:lower:]' '[:upper:]')"
if [ -n "$SSO_SOURCE" ]; then
    if ! printf '%s' "$SSO_SOURCE" | grep -Eq '^[A-Z][A-Z0-9_]{0,31}$'; then
        echo "[auth] FEHLER: Ungueltige SSO_SOURCE '${SSO_SOURCE}' (erlaubt: A-Z, 0-9, _)." >&2
        exit 1
    fi
    if [ -n "$SSO_ROUTES" ]; then
        echo "[auth] WARNUNG: SSO_ROUTES wird in Instanzen weiterer Quellen ignoriert." >&2
        SSO_ROUTES=""
    fi
    APACHE_DEFINES="${APACHE_DEFINES} -D SSO_WORKER"
fi
export SSO_SOURCE

# Konfiguration dieser Instanz von der Anwendung abrufen. Antwort: je Zeile
# NAME=base64(wert); es werden nur bekannte Namen uebernommen (kein eval).
fetch_config() {
    token_file="${SSO_CONFIG_TOKEN_FILE:-/run/intranet-sso/token}"
    url="${SSO_CONFIG_URL:-http://app/internal/sso-config}?source=${SSO_SOURCE}"
    if [ ! -r "$token_file" ]; then
        echo "[auth] WARNUNG: Token ${token_file} fehlt - Konfiguration kann nicht abgerufen werden." >&2
        return 1
    fi

    old_umask="$(umask)"
    umask 077
    header_file="$(mktemp)"
    config_file="$(mktemp)"
    umask "$old_umask"
    printf 'X-Intranet-Sso-Token: %s\n' "$(tr -d '\r\n' < "$token_file")" > "$header_file"

    attempt=0
    until curl -fsS --max-time 10 -H "@${header_file}" -o "$config_file" "$url"; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge "${SSO_CONFIG_RETRIES:-30}" ]; then
            rm -f "$header_file" "$config_file"
            echo "[auth] WARNUNG: Konfiguration konnte nicht von ${url%%\?*} abgerufen werden." >&2
            return 1
        fi
        sleep 2
    done
    rm -f "$header_file"

    while IFS='=' read -r name value || [ -n "$name" ]; do
        case "$name" in
            SSO_CONFIGURED|SSO_ENABLED|SSO_DOMAIN|SSO_DC|SSO_DC_IP|SSO_JOIN_USER|SSO_JOIN_PASSWORD|SSO_ROUTES) ;;
            *) continue ;;
        esac
        decoded="$(printf '%s' "$value" | base64 -d 2>/dev/null)" || continue
        export "FETCHED_${name}=${decoded}"
    done < "$config_file"
    rm -f "$config_file"
    return 0
}

if fetch_config; then
    SSO_ROUTES="${FETCHED_SSO_ROUTES:-}"
    [ -n "$SSO_SOURCE" ] && SSO_ROUTES=""
    if [ "${FETCHED_SSO_CONFIGURED:-0}" = "1" ]; then
        [ -n "${FETCHED_SSO_ENABLED:-}" ] && SSO_ENABLED="$FETCHED_SSO_ENABLED"
        SSO_DOMAIN="${FETCHED_SSO_DOMAIN:-}"
        SSO_DC="${FETCHED_SSO_DC:-}"
        SSO_DC_IP="${FETCHED_SSO_DC_IP:-}"
        SSO_JOIN_USER="${FETCHED_SSO_JOIN_USER:-}"
        SSO_JOIN_PASSWORD="${FETCHED_SSO_JOIN_PASSWORD:-}"
        echo "[auth] Konfiguration aus der Verwaltung uebernommen."
    elif is_true "$SSO_ENABLED" && [ -z "${SSO_DOMAIN:-}" ]; then
        echo "[auth] WARNUNG: In der Verwaltung ist keine Domaene fuer die Windows-Anmeldung hinterlegt (Active Directory -> Hauptquelle)." >&2
    fi
    unset FETCHED_SSO_JOIN_PASSWORD FETCHED_SSO_CONFIGURED FETCHED_SSO_ENABLED FETCHED_SSO_DOMAIN \
        FETCHED_SSO_DC FETCHED_SSO_DC_IP FETCHED_SSO_JOIN_USER FETCHED_SSO_ROUTES
elif [ -n "$SSO_SOURCE" ]; then
    # Ohne Konfiguration kann eine Zweigstellen-Instanz keiner Domaene beitreten.
    echo "[auth] WARNUNG: Instanz ${SSO_SOURCE} ohne Konfiguration - Windows-Anmeldung deaktiviert." >&2
    SSO_ENABLED=false
fi
: "${SSO_DOMAIN:=WORKGROUP}"

if is_true "$SSO_ENABLED"; then
    mkdir -p /var/run/samba /var/lib/samba/winbindd_privileged

    DCS="$(split_list "${SSO_DC:-}")"
    DC_IPS="$(split_list "${SSO_DC_IP:-}")"

    password_server=""
    [ -n "${DCS}" ] && password_server="   password server = ${DCS}"

    # Samba-/Winbind-Grundkonfiguration.
    cat > /etc/samba/smb.conf <<CONF
[global]
   workgroup = ${SSO_DOMAIN}
   server string = Intranet Auth
   security = domain
${password_server}
   winbind use default domain = yes
   winbind offline logon = yes
   winbind enum users = no
   winbind enum groups = no
   idmap config * : backend = tdb
   idmap config * : range = 10000-20000
   log file = /dev/stderr
CONF

    # Optionale DC-Eintraege in /etc/hosts, damit die Domaenencontroller auch
    # ohne externes DNS aufloesbar sind (SSO_DC und SSO_DC_IP paarweise).
    if [ -n "${DCS}" ] && [ -n "${DC_IPS}" ]; then
        remaining_ips="${DC_IPS}"
        for dc in ${DCS}; do
            ip="${remaining_ips%% *}"
            [ -z "$ip" ] && break
            if [ "$ip" = "$remaining_ips" ]; then remaining_ips=""; else remaining_ips="${remaining_ips#* }"; fi
            # "-" = fuer diesen DC keine IP-Adresse (Aufloesung per DNS).
            [ "$ip" = "-" ] && continue
            if ! grep -q " ${dc}\$" /etc/hosts; then
                echo "${ip} ${dc}" >> /etc/hosts
            fi
        done
    fi

    # Domaenenbeitritt ueber RPC (NTLM, ohne Kerberos) VOR dem Start von
    # winbindd; ein bereits bestehender Beitritt wird wiederverwendet. Ist ein
    # Domaenencontroller nicht erreichbar, wird der naechste versucht.
    if [ -n "${DCS}" ] && [ -n "${SSO_JOIN_USER}" ] && [ -n "${SSO_JOIN_PASSWORD}" ]; then
        joined=false
        for dc in ${DCS}; do
            if net rpc testjoin -S "${dc}" >/dev/null 2>&1; then
                echo "[auth] Domaenenbeitritt ${SSO_DOMAIN} besteht bereits (${dc})."
                joined=true
                break
            fi
            echo "[auth] Tritt der Domaene ${SSO_DOMAIN} ueber ${dc} bei ..."
            if net rpc join -U "${SSO_JOIN_USER}%${SSO_JOIN_PASSWORD}" -S "${dc}"; then
                joined=true
                break
            fi
            echo "[auth] WARNUNG: Domaenenbeitritt ueber ${dc} fehlgeschlagen." >&2
        done
        [ "$joined" = true ] || echo "[auth] WARNUNG: Domaenenbeitritt fehlgeschlagen (kein DC erreichbar)." >&2
    fi
    # Das Kennwort wird nach dem Beitritt nicht mehr benoetigt (nicht an Apache vererben).
    unset SSO_JOIN_PASSWORD

    winbindd -D
    # Apache (www-data) darf die privilegierte winbind-Pipe nutzen.
    chgrp winbindd_priv /var/lib/samba/winbindd_privileged 2>/dev/null || true
    chmod 0750 /var/lib/samba/winbindd_privileged 2>/dev/null || true

    APACHE_DEFINES="${APACHE_DEFINES} -D SSO_NTLM"
    if [ -n "$SSO_SOURCE" ]; then
        echo "[auth] NTLM-Anmeldung aktiv (Domaene ${SSO_DOMAIN}, Identitaetsquelle ${SSO_SOURCE})."
    else
        echo "[auth] NTLM-Anmeldung aktiv (Domaene ${SSO_DOMAIN})."
    fi
else
    echo "[auth] SSO_ENABLED ist nicht gesetzt - keine NTLM-Anmeldung, reiner Proxy."
fi

# Oeffentliches Schema (http/https) aus APP_URL (X-Forwarded-Proto ohne eigenes TLS).
case "$APP_URL" in
    https://*) OFFICE_PUBLIC_SCHEME=https ;;
    *)         OFFICE_PUBLIC_SCHEME=http ;;
esac
export OFFICE_PUBLIC_SCHEME

# HTTPS: Zertifikat und HTTP-Richtlinie aus der Zertifikatsverwaltung.
if [ -n "$SSO_SOURCE" ]; then
    TLS_ENABLED=false
fi
if ! printf '%s' "$HTTPS_PUBLIC_PORT" | grep -Eq '^[0-9]{1,5}$'; then
    echo "[auth] FEHLER: Ungueltiger HTTPS_PUBLIC_PORT '${HTTPS_PUBLIC_PORT}'." >&2
    exit 1
fi
export TLS_ENABLED HTTPS_PUBLIC_PORT
if is_true "$TLS_ENABLED"; then
    /usr/local/bin/tls-sync.sh once
fi

# HAProxy-Verteiler fuer weitere Identitaetsquellen (nur Hauptinstanz).
if [ -n "$SSO_ROUTES" ]; then
    printf '%s' "$SSO_ROUTES" > /etc/haproxy/sso-routes
    /usr/local/bin/sso-routes.sh "$SSO_ROUTES" > /etc/haproxy/haproxy.cfg
    haproxy -c -q -f /etc/haproxy/haproxy.cfg
    haproxy -D -f /etc/haproxy/haproxy.cfg -p /run/haproxy.pid
    # Apache lauscht dann nur lokal und erhaelt die Client-Adresse per PROXY-Protokoll.
    AUTH_HTTP_PORT=8081
    SSO_PROXY_PROTOCOL=true
    APACHE_DEFINES="${APACHE_DEFINES} -D SSO_ROUTER"
    is_true "$TLS_ENABLED" && APACHE_DEFINES="${APACHE_DEFINES} -D TLS_PROXY"
    printf 'Listen 127.0.0.1:%s\n' "$AUTH_HTTP_PORT" > /etc/apache2/ports.conf
    echo "[auth] Verteiler fuer weitere Identitaetsquellen aktiv."
elif is_true "$TLS_ENABLED"; then
    APACHE_DEFINES="${APACHE_DEFINES} -D TLS_APACHE"
    printf 'Listen %s\nListen 443\n' "$AUTH_HTTP_PORT" > /etc/apache2/ports.conf
else
    printf 'Listen %s\n' "$AUTH_HTTP_PORT" > /etc/apache2/ports.conf
fi
export AUTH_HTTP_PORT

if is_true "$SSO_PROXY_PROTOCOL"; then
    APACHE_DEFINES="${APACHE_DEFINES} -D PROXY_PROTOCOL"
fi

if is_true "$OFFICE_ENABLED"; then
    APACHE_DEFINES="${APACHE_DEFINES} -D OFFICE"
    echo "[auth] Euro-Office-Proxy aktiv (/office/, /eurooffice/)."
fi

# apache2ctl uebergibt APACHE_ARGUMENTS an httpd.
export APACHE_ARGUMENTS="${APACHE_ARGUMENTS:-}${APACHE_DEFINES}"

if is_true "$TLS_ENABLED"; then
    # Abgleich im Hintergrund: laedt Apache/HAProxy bei Aenderungen neu.
    /usr/local/bin/tls-sync.sh loop &
    echo "[auth] HTTPS aktiv (Port 443, oeffentlich ${HTTPS_PUBLIC_PORT})."
fi

exec "$@"
