#!/bin/sh
# Startskript des Einstiegs-/Authentifizierungs-Containers.
#
# - SSO_ENABLED=true:    Samba/winbind (security = domain, NTLM/RPC OHNE
#                        Kerberos) konfigurieren, ggf. Domaene beitreten und
#                        die NTLM-Anmeldung in Apache aktivieren (-D SSO_NTLM).
# - OFFICE_ENABLED=true: Reverse-Proxy fuer Nextcloud/Euro-Office aktivieren
#                        (-D OFFICE, bindet /etc/apache2/intranet/office.conf ein).
set -e

: "${SSO_DOMAIN:=WORKGROUP}"
: "${SSO_ENABLED:=false}"
: "${OFFICE_ENABLED:=false}"
: "${APP_URL:=http://localhost:8080}"

is_true() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

APACHE_DEFINES=""

if is_true "$SSO_ENABLED"; then
    mkdir -p /var/run/samba /var/lib/samba/winbindd_privileged

    password_server=""
    [ -n "${SSO_DC}" ] && password_server="   password server = ${SSO_DC}"

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

    # Optionaler DC-Eintrag in /etc/hosts, damit der Domaenencontroller auch
    # ohne externes DNS aufloesbar ist.
    if [ -n "${SSO_DC}" ] && [ -n "${SSO_DC_IP}" ] && ! grep -q " ${SSO_DC}\$" /etc/hosts; then
        echo "${SSO_DC_IP} ${SSO_DC}" >> /etc/hosts
    fi

    # Domaenenbeitritt ueber RPC (NTLM, ohne Kerberos) VOR dem Start von
    # winbindd; ein bereits bestehender Beitritt wird wiederverwendet.
    if [ -n "${SSO_DC}" ] && [ -n "${SSO_JOIN_USER}" ] && [ -n "${SSO_JOIN_PASSWORD}" ]; then
        if net rpc testjoin -S "${SSO_DC}" >/dev/null 2>&1; then
            echo "[auth] Domaenenbeitritt ${SSO_DOMAIN} besteht bereits."
        else
            echo "[auth] Tritt der Domaene ${SSO_DOMAIN} bei ..."
            net rpc join -U "${SSO_JOIN_USER}%${SSO_JOIN_PASSWORD}" -S "${SSO_DC}" \
                || echo "[auth] WARNUNG: Domaenenbeitritt fehlgeschlagen." >&2
        fi
    fi

    winbindd -D
    # Apache (www-data) darf die privilegierte winbind-Pipe nutzen.
    chgrp winbindd_priv /var/lib/samba/winbindd_privileged 2>/dev/null || true
    chmod 0750 /var/lib/samba/winbindd_privileged 2>/dev/null || true

    APACHE_DEFINES="${APACHE_DEFINES} -D SSO_NTLM"
    echo "[auth] NTLM-Anmeldung aktiv (Domaene ${SSO_DOMAIN})."
else
    echo "[auth] SSO_ENABLED ist nicht gesetzt - keine NTLM-Anmeldung, reiner Proxy."
fi

# Oeffentliches Schema (http/https) fuer X-Forwarded-Proto aus APP_URL.
case "$APP_URL" in
    https://*) OFFICE_PUBLIC_SCHEME=https ;;
    *)         OFFICE_PUBLIC_SCHEME=http ;;
esac
export OFFICE_PUBLIC_SCHEME

if is_true "$OFFICE_ENABLED"; then
    APACHE_DEFINES="${APACHE_DEFINES} -D OFFICE"
    echo "[auth] Euro-Office-Proxy aktiv (/office/, /eurooffice/)."
fi

# apache2ctl uebergibt APACHE_ARGUMENTS an httpd.
export APACHE_ARGUMENTS="${APACHE_ARGUMENTS:-}${APACHE_DEFINES}"

exec "$@"
