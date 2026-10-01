#!/bin/sh
# Startskript des Einstiegs-/Authentifizierungs-Containers.
#
# - SSO_ENABLED=true:    Samba/winbind konfigurieren, ggf. Domaene beitreten
#                        und die Windows-Anmeldung in Apache aktivieren
#                        (-D SSO_NTLM; zusaetzlich -D SSO_KRB5, wenn der
#                        Kerberos-Realm ermittelt und eine Keytab fuer das
#                        Computerkonto erzeugt werden konnte). Realm: SSO_REALM
#                        oder automatisch per CLDAP vom DC. Kerberos-SPNs:
#                        HTTP/<Hostname aus APP_URL> und HTTP/<SSO_SPN_HOSTS>.
#                        SSO_DC darf mehrere Domaenencontroller enthalten
#                        (Leerzeichen/Komma getrennt); SSO_DC_IP die passenden
#                        IP-Adressen in derselben Reihenfolge. SSO_NTP:
#                        Zeitserver (leer = die Domaenencontroller); mit
#                        CAP_SYS_TIME stellt chronyd die Uhr des Containers,
#                        sonst wird die Abweichung nur gemessen und gemeldet.
#                        Computername im AD: SSO_NETBIOS_NAME (sonst der
#                        Hostname); der Beitritt liegt in /var/lib/samba
#                        (Volume) und wird bei jedem Start wiederverwendet.
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
            SSO_CONFIGURED|SSO_ENABLED|SSO_DOMAIN|SSO_DC|SSO_DC_IP|SSO_NTP|SSO_JOIN_USER|SSO_JOIN_PASSWORD|SSO_ROUTES) ;;
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
        SSO_NTP="${FETCHED_SSO_NTP:-}"
        SSO_JOIN_USER="${FETCHED_SSO_JOIN_USER:-}"
        SSO_JOIN_PASSWORD="${FETCHED_SSO_JOIN_PASSWORD:-}"
        echo "[auth] Konfiguration aus der Verwaltung uebernommen."
    elif is_true "$SSO_ENABLED" && [ -z "${SSO_DOMAIN:-}" ]; then
        echo "[auth] WARNUNG: In der Verwaltung ist keine Domaene fuer die Windows-Anmeldung hinterlegt (Active Directory -> Hauptquelle)." >&2
    fi
    unset FETCHED_SSO_JOIN_PASSWORD FETCHED_SSO_CONFIGURED FETCHED_SSO_ENABLED FETCHED_SSO_DOMAIN \
        FETCHED_SSO_DC FETCHED_SSO_DC_IP FETCHED_SSO_NTP FETCHED_SSO_JOIN_USER FETCHED_SSO_ROUTES
elif [ -n "$SSO_SOURCE" ]; then
    # Ohne Konfiguration kann eine Zweigstellen-Instanz keiner Domaene beitreten.
    echo "[auth] WARNUNG: Instanz ${SSO_SOURCE} ohne Konfiguration - Windows-Anmeldung deaktiviert." >&2
    SSO_ENABLED=false
fi
: "${SSO_DOMAIN:=WORKGROUP}"

if is_true "$SSO_ENABLED"; then
    mkdir -p /var/run/samba /var/lib/samba/winbindd_privileged

    # Fester Computername im AD (NetBIOS, max. 15 Zeichen). Ohne ihn waere es
    # die Container-ID und jeder Neuaufbau legte ein neues Computerkonto an.
    NETBIOS_NAME="$(printf '%s' "${SSO_NETBIOS_NAME:-$(hostname)}" | tr '[:lower:]' '[:upper:]')"
    if ! printf '%s' "$NETBIOS_NAME" | grep -Eq '^[A-Z0-9][A-Z0-9-]{0,14}$'; then
        echo "[auth] FEHLER: Ungueltiger Computername '${NETBIOS_NAME}' (SSO_NETBIOS_NAME: A-Z, 0-9, -, max. 15 Zeichen)." >&2
        exit 1
    fi

    DCS="$(split_list "${SSO_DC:-}")"
    DC_IPS="$(split_list "${SSO_DC_IP:-}")"

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

    # Zeitabgleich: Kerberos toleriert hoechstens 5 Minuten Abweichung zwischen
    # Client, DC und diesem Container. Zeitserver aus SSO_NTP, sonst die DCs
    # (AD-Domaenencontroller sind immer auch NTP-Server). Mit CAP_SYS_TIME
    # (cap_add: SYS_TIME) wird die Uhr sofort gestellt und chronyd haelt sie
    # laufend nach; ohne die Berechtigung wird die Abweichung nur gemessen.
    NTP_SERVERS="$(split_list "${SSO_NTP:-}")"
    [ -z "$NTP_SERVERS" ] && NTP_SERVERS="$DCS"
    if [ -n "$NTP_SERVERS" ]; then
        mkdir -p /run/chrony /var/lib/chrony
        {
            for s in ${NTP_SERVERS}; do echo "server ${s} iburst"; done
            echo "makestep 1 -1"
            echo "driftfile /var/lib/chrony/drift"
            echo "cmdport 0"
        } > /etc/chrony/chrony.conf
        if chronyd -q -t 15 -f /etc/chrony/chrony.conf >/tmp/chrony.log 2>&1; then
            echo "[auth] Uhrzeit mit ${NTP_SERVERS} abgeglichen: $(grep -o 'System clock wrong by [^ ]* seconds' /tmp/chrony.log | tail -1)."
            chronyd -f /etc/chrony/chrony.conf || echo "[auth] WARNUNG: chronyd konnte nicht gestartet werden." >&2
        elif grep -q 'CAP_SYS_TIME not present' /tmp/chrony.log; then
            if chronyd -Q -t 15 -f /etc/chrony/chrony.conf >/tmp/chrony.log 2>&1 \
                && offset="$(grep -o 'System clock wrong by [^ ]* seconds' /tmp/chrony.log | tail -1 | awk '{print $5}')" && [ -n "$offset" ]; then
                if awk -v o="$offset" 'BEGIN { exit ((o < 0 ? -o : o) > 300) ? 0 : 1 }'; then
                    echo "[auth] WARNUNG: Uhrzeit weicht um ${offset} s von ${NTP_SERVERS} ab - Kerberos wird fehlschlagen. Uhr des Docker-Hosts stellen oder dem auth-Dienst cap_add: SYS_TIME geben." >&2
                else
                    echo "[auth] Uhrzeit geprueft (Abweichung ${offset} s zu ${NTP_SERVERS}); Stellen der Uhr nicht erlaubt (cap_add: SYS_TIME fehlt)."
                fi
            else
                echo "[auth] WARNUNG: Zeitserver ${NTP_SERVERS} nicht erreichbar - Uhrzeit nicht geprueft." >&2
            fi
        else
            echo "[auth] WARNUNG: Zeitserver ${NTP_SERVERS} nicht erreichbar - Uhrzeit nicht abgeglichen." >&2
        fi
        rm -f /tmp/chrony.log
    fi

    # Kerberos-Realm der Domaene ermitteln (SSO_REALM oder CLDAP-Abfrage am
    # DC). Ohne Realm bleibt nur NTLM ueber RPC (z. B. NT4-artige Domaenen).
    SSO_REALM="$(printf '%s' "${SSO_REALM:-}" | tr '[:lower:]' '[:upper:]')"
    KDC_HOSTS=""
    if [ -n "${DCS}" ]; then
        for dc in ${DCS}; do
            lookup="$(net ads lookup -S "${dc}" 2>/dev/null)" || continue
            if [ -z "$SSO_REALM" ]; then
                SSO_REALM="$(printf '%s\n' "$lookup" | sed -n 's/^Domain:[[:space:]]*//p' | head -n1 | tr '[:lower:]' '[:upper:]')"
            fi
            # Fuer Kerberos muss der DC unter seinem DNS-Namen erreichbar sein.
            dc_fqdn="$(printf '%s\n' "$lookup" | sed -n 's/^Domain Controller:[[:space:]]*//p' | head -n1)"
            if [ -n "$dc_fqdn" ] && [ "$dc_fqdn" != "$dc" ] && ! getent hosts "$dc_fqdn" >/dev/null 2>&1; then
                dc_ip="$(getent ahostsv4 "$dc" 2>/dev/null | awk 'NR==1 {print $1}')"
                [ -n "$dc_ip" ] && echo "${dc_ip} ${dc_fqdn}" >> /etc/hosts
            fi
            KDC_HOSTS="${KDC_HOSTS} ${dc_fqdn:-$dc}"
        done
        KDC_HOSTS="${KDC_HOSTS# }"
    fi
    if [ -n "$SSO_REALM" ] && ! printf '%s' "$SSO_REALM" | grep -Eq '^[A-Z0-9][A-Z0-9.-]*$'; then
        echo "[auth] WARNUNG: Ungueltiger Kerberos-Realm '${SSO_REALM}' - Kerberos deaktiviert." >&2
        SSO_REALM=""
    fi

    KRB5_KEYTAB=/etc/krb5.keytab
    if [ -n "$SSO_REALM" ]; then
        REALM_LOWER="$(printf '%s' "$SSO_REALM" | tr '[:upper:]' '[:lower:]')"
        kdc_lines=""
        for kdc in ${KDC_HOSTS:-$DCS}; do
            kdc_lines="${kdc_lines}        kdc = ${kdc}
"
        done
        # Feste KDC-Liste (kein DNS-SRV noetig), keine Hostnamen-Kanonisierung:
        # der Client fordert ein Ticket fuer genau den Hostnamen der URL an.
        cat > /etc/krb5.conf <<CONF
[libdefaults]
    default_realm = ${SSO_REALM}
    dns_lookup_realm = false
    dns_lookup_kdc = false
    dns_canonicalize_hostname = false
    rdns = false
    forwardable = false
    ticket_lifetime = 10h
    default_keytab_name = FILE:${KRB5_KEYTAB}

[realms]
    ${SSO_REALM} = {
${kdc_lines}    }

[domain_realm]
    .${REALM_LOWER} = ${SSO_REALM}
    ${REALM_LOWER} = ${SSO_REALM}
CONF
        security_conf="   security = ads
   realm = ${SSO_REALM}
   dns hostname = $(printf '%s' "$NETBIOS_NAME" | tr '[:upper:]' '[:lower:]').${REALM_LOWER}
   # winbind haelt die Keytab bei Kennwortwechseln des Computerkontos aktuell.
   kerberos method = secrets and keytab"
    else
        security_conf="   security = domain
   # Ohne Realm (kein AD/Kerberos): nur RPC.
   winbind rpc only = yes"
    fi

    password_server=""
    [ -n "${DCS}" ] && password_server="   password server = ${DCS}"

    # Samba-/Winbind-Grundkonfiguration.
    cat > /etc/samba/smb.conf <<CONF
[global]
   workgroup = ${SSO_DOMAIN}
   netbios name = ${NETBIOS_NAME}
   server string = Intranet Auth
${security_conf}
${password_server}
   winbind use default domain = yes
   winbind offline logon = yes
   winbind enum users = no
   winbind enum groups = no
   idmap config * : backend = tdb
   idmap config * : range = 10000-20000
   log file = /dev/stderr
CONF

    # Service Principal Names fuer Kerberos: HTTP/<Hostname der Intranet-URL>
    # plus optionale weitere Hostnamen (SSO_SPN_HOSTS). Der Browser fordert
    # das Ticket fuer den Hostnamen aus der Adresszeile an.
    spn_hosts=""
    app_host="$(printf '%s' "$APP_URL" | sed -E 's#^[a-zA-Z]+://##; s#[/:?].*$##' | tr '[:upper:]' '[:lower:]')"
    for h in ${app_host} $(split_list "${SSO_SPN_HOSTS:-}" | tr '[:upper:]' '[:lower:]'); do
        case "$h" in
            ""|localhost|*[!a-z0-9.-]*) continue ;;
        esac
        printf '%s' "$h" | grep -Eq '^[0-9.]+$' && continue
        case " ${spn_hosts} " in *" ${h} "*) continue ;; esac
        spn_hosts="${spn_hosts} ${h}"
    done
    spn_hosts="${spn_hosts# }"

    # Prueft, ob die Keytab zum Computerkonto passt (Schluessel/Salt).
    keytab_ok() {
        [ -r "$KRB5_KEYTAB" ] || return 1
        KRB5CCNAME=MEMORY:keytab-check kinit -k -t "$KRB5_KEYTAB" "${NETBIOS_NAME}\$@${SSO_REALM}" >/dev/null 2>&1
    }

    # Keytab aus dem Computerkonto erzeugen und die HTTP-SPNs eintragen.
    build_keytab() {
        rm -f "$KRB5_KEYTAB"
        net ads keytab create -P >/dev/null 2>&1 || return 1
        for h in ${spn_hosts}; do
            net ads keytab add "HTTP/${h}" -P >/dev/null 2>&1 \
                || echo "[auth] WARNUNG: HTTP/${h} konnte nicht in die Keytab uebernommen werden." >&2
        done
        keytab_ok
    }

    # Registriert fehlende HTTP-SPNs am Computerkonto (benoetigt das Beitrittskonto).
    register_spns() {
        [ -n "${spn_hosts}" ] || return 0
        [ -n "${SSO_JOIN_USER}" ] && [ -n "${SSO_JOIN_PASSWORD}" ] || return 0
        registered="$(net ads setspn list -P 2>/dev/null | sed 's/^[[:space:]]*//' | tr '[:upper:]' '[:lower:]')"
        for h in ${spn_hosts}; do
            printf '%s\n' "$registered" | grep -qxF "http/${h}" && continue
            if net ads setspn add "HTTP/${h}" -U "${SSO_JOIN_USER}%${SSO_JOIN_PASSWORD}" >/dev/null 2>&1; then
                echo "[auth] SPN HTTP/${h} am Computerkonto ${NETBIOS_NAME} registriert."
            else
                echo "[auth] WARNUNG: SPN HTTP/${h} konnte nicht registriert werden (bereits an ein anderes Konto vergeben?). Kerberos fuer diesen Hostnamen nicht moeglich." >&2
            fi
        done
    }

    # Domaenenbeitritt VOR dem Start von winbindd; ein bestehender Beitritt
    # wird wiederverwendet. Mit Realm per ADS (Kerberos + Keytab), sonst per
    # RPC (nur NTLM). Ist ein DC nicht erreichbar, wird der naechste versucht.
    KRB5_ACTIVE=false
    if [ -n "${DCS}" ]; then
        joined=false
        have_creds=false
        [ -n "${SSO_JOIN_USER}" ] && [ -n "${SSO_JOIN_PASSWORD}" ] && have_creds=true
        for dc in ${DCS}; do
            if [ -n "$SSO_REALM" ]; then
                if net ads testjoin -S "${dc}" >/dev/null 2>&1 && build_keytab; then
                    echo "[auth] Domaenenbeitritt ${SSO_DOMAIN} als ${NETBIOS_NAME} besteht bereits (${dc})."
                    joined=true
                    break
                fi
                [ "$have_creds" = true ] || continue
                # Kein (passender) Beitritt: ein frueherer RPC-Beitritt liefert
                # keine brauchbaren Kerberos-Schluessel und wird erneuert.
                echo "[auth] Tritt der Domaene ${SSO_DOMAIN} (Realm ${SSO_REALM}) als ${NETBIOS_NAME} ueber ${dc} bei ..."
                if net ads join -U "${SSO_JOIN_USER}%${SSO_JOIN_PASSWORD}" -S "${dc}" --no-dns-updates \
                        "dnshostname=$(printf '%s' "$NETBIOS_NAME" | tr '[:upper:]' '[:lower:]').${REALM_LOWER}"; then
                    if build_keytab; then
                        joined=true
                        break
                    fi
                    echo "[auth] WARNUNG: Keytab passt nach dem Beitritt nicht zum Computerkonto." >&2
                else
                    echo "[auth] WARNUNG: ADS-Domaenenbeitritt ueber ${dc} fehlgeschlagen." >&2
                fi
            else
                if net rpc testjoin -S "${dc}" >/dev/null 2>&1; then
                    echo "[auth] Domaenenbeitritt ${SSO_DOMAIN} als ${NETBIOS_NAME} besteht bereits (${dc})."
                    joined=true
                    break
                fi
                [ "$have_creds" = true ] || continue
                echo "[auth] Tritt der Domaene ${SSO_DOMAIN} als ${NETBIOS_NAME} ueber ${dc} bei (RPC) ..."
                if net rpc join -U "${SSO_JOIN_USER}%${SSO_JOIN_PASSWORD}" -S "${dc}"; then
                    joined=true
                    break
                fi
                echo "[auth] WARNUNG: Domaenenbeitritt ueber ${dc} fehlgeschlagen." >&2
            fi
        done

        if [ "$joined" = true ]; then
            if [ -n "$SSO_REALM" ]; then
                register_spns
                # SPNs koennen seit dem letzten Start dazugekommen sein.
                build_keytab || true
                chgrp www-data "$KRB5_KEYTAB" && chmod 0640 "$KRB5_KEYTAB"
                KRB5_ACTIVE=true
            fi
        elif [ "$have_creds" = true ]; then
            echo "[auth] WARNUNG: Domaenenbeitritt fehlgeschlagen (kein DC erreichbar)." >&2
        elif [ -n "$SSO_REALM" ]; then
            echo "[auth] WARNUNG: Kein gueltiger Domaenenbeitritt und kein Beitrittskonto hinterlegt - Windows-Anmeldung nicht moeglich." >&2
        fi
    fi
    # Das Kennwort wird nach dem Beitritt nicht mehr benoetigt (nicht an Apache vererben).
    unset SSO_JOIN_PASSWORD

    winbindd -D
    # Apache (www-data) darf die privilegierte winbind-Pipe nutzen.
    chgrp winbindd_priv /var/lib/samba/winbindd_privileged 2>/dev/null || true
    chmod 0750 /var/lib/samba/winbindd_privileged 2>/dev/null || true

    APACHE_DEFINES="${APACHE_DEFINES} -D SSO_NTLM"
    mechs="NTLM"
    if [ "$KRB5_ACTIVE" = true ]; then
        APACHE_DEFINES="${APACHE_DEFINES} -D SSO_KRB5"
        export SSO_KRB5_KEYTAB="$KRB5_KEYTAB"
        mechs="Kerberos (SPNs: $(for h in ${spn_hosts}; do printf 'HTTP/%s ' "$h"; done | sed 's/ $//')) und NTLM"
    elif [ -n "$SSO_REALM" ]; then
        echo "[auth] WARNUNG: Kerberos nicht verfuegbar - nur NTLM." >&2
    fi
    if [ -n "$SSO_SOURCE" ]; then
        echo "[auth] Windows-Anmeldung aktiv: ${mechs} (Domaene ${SSO_DOMAIN}, Identitaetsquelle ${SSO_SOURCE})."
    else
        echo "[auth] Windows-Anmeldung aktiv: ${mechs} (Domaene ${SSO_DOMAIN})."
    fi
else
    echo "[auth] SSO_ENABLED ist nicht gesetzt - keine Windows-Anmeldung, reiner Proxy."
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
