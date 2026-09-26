#!/bin/sh
# Erzeugt die HAProxy-Konfiguration des Verteilers in der Hauptinstanz des
# auth-Containers (Ausgabe auf stdout).
#
#   sso-routes.sh "KENNUNG|ziel|netz1,netz2|host1,host2;KENNUNG2|..."
#
# Je Route wird eine eigene auth-Instanz (ziel = Hostname:Port, Standard-Port
# 80) fuer die Domaene einer weiteren Identitaetsquelle angesprochen. Die
# Zuordnung erfolgt ueber den aufgerufenen Hostnamen (Vorrang) oder das
# Client-Netz (CIDR). Alle uebrigen Anfragen gehen an die Hauptinstanz
# (Apache auf 127.0.0.1:8081).
#
# NTLM ist verbindungsgebunden: HAProxy arbeitet im HTTP-Modus mit
# Keep-Alive und bindet Server-Verbindungen nach einer NTLM-/Negotiate-
# Aufforderung automatisch exklusiv an die Client-Verbindung.
#
# TLS (TLS_ENABLED=true, Dateien von tls-sync.sh in TLS_DIR): HAProxy
# terminiert HTTPS auf :443 (nur HTTP/1.1, NTLM ist verbindungsgebunden) und
# leitet HTTP auf HTTPS um - im Modus "fallback" ausser fuer die
# freigegebenen Quellnetze.
set -eu

routes="${1:-}"
tls_dir="${TLS_DIR:-/etc/intranet-tls}"
https_port="${HTTPS_PUBLIC_PORT:-443}"
public_scheme="${OFFICE_PUBLIC_SCHEME:-http}"

tls=false
case "$(printf '%s' "${TLS_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|on) [ -s "${tls_dir}/server.pem" ] && tls=true ;;
esac

cat <<'CFG'
global
    log stdout format raw local0 warning
    maxconn 4096

defaults
    mode http
    log global
    option dontlognull
    timeout connect 5s
    timeout client 5m
    timeout server 1h
    timeout http-request 30s
    timeout http-keep-alive 5m
    timeout tunnel 1h

resolvers docker
    nameserver dns 127.0.0.11:53
    hold valid 10s

frontend web
    bind :80
CFG
[ "$tls" = true ] && printf '    bind :443 ssl crt %s alpn http/1.1 ssl-min-ver TLSv1.2\n' "${tls_dir}/server.pem"
cat <<'CFG'
    # Identitaets-Header werden nie vom Client uebernommen.
    http-request del-header X-Remote-User
    http-request del-header X-Remote-Groups
    http-request del-header X-Remote-Source
    # Hostname ohne Port (IPv6-Literale werden nie zugeordnet).
    http-request set-var(txn.host) req.hdr(host),lower,field(1,:)
CFG

if [ "$tls" = true ]; then
    echo "    http-request set-header X-Forwarded-Proto https if { ssl_fc }"
    echo "    http-request set-header X-Forwarded-Proto ${public_scheme} if !{ ssl_fc }"

    mode="$(cat "${tls_dir}/mode" 2>/dev/null || echo fallback)"
    tls_nets=""
    if [ "$mode" != "strict" ]; then
        for net in $(cat "${tls_dir}/networks" 2>/dev/null || true); do
            if printf '%s' "$net" | grep -Eq '^[0-9A-Fa-f:.]+/[0-9]{1,3}$'; then
                tls_nets="${tls_nets} ${net}"
            fi
        done
    fi
    port_suffix=""
    [ "$https_port" = "443" ] || port_suffix=":${https_port}"
    if ! printf '%s' "$https_port" | grep -Eq '^[0-9]{1,5}$'; then
        echo "sso-routes: ungueltiger HTTPS_PUBLIC_PORT '${https_port}'" >&2
        exit 1
    fi
    exempt=""
    [ -n "$tls_nets" ] && exempt=" !{ src${tls_nets} }"
    # Umleitung ohne IPv6-Literale (deren Host kann HAProxy hier nicht sauber trennen).
    echo "    http-request redirect location https://%[var(txn.host)]${port_suffix}%[pathq] code 302 if !{ ssl_fc } !{ path /auth-health } !{ req.hdr(host) -m beg [ }${exempt}"
fi

backends=""
host_rules=""
net_rules=""

old_ifs="$IFS"
IFS=';'
for route in $routes; do
    IFS="$old_ifs"
    route="$(printf '%s' "$route" | tr -d ' \t\r\n')"
    [ -z "$route" ] && { IFS=';'; continue; }

    key="$(printf '%s' "$route" | cut -d'|' -f1 | tr '[:lower:]' '[:upper:]')"
    target="$(printf '%s' "$route" | cut -d'|' -f2)"
    nets="$(printf '%s' "$route" | cut -d'|' -f3 | tr ',' ' ')"
    hosts="$(printf '%s' "$route" | cut -d'|' -f4 | tr ',' ' ' | tr '[:upper:]' '[:lower:]')"

    if ! printf '%s' "$key" | grep -Eq '^[A-Z][A-Z0-9_]{0,31}$'; then
        echo "sso-routes: ungueltige Kennung '${key}'" >&2
        exit 1
    fi
    if ! printf '%s' "$target" | grep -Eq '^[A-Za-z0-9._-]+(:[0-9]{1,5})?$'; then
        echo "sso-routes: ungueltiges Ziel '${target}' fuer ${key}" >&2
        exit 1
    fi
    case "$target" in *:*) ;; *) target="${target}:80" ;; esac

    for net in $nets; do
        if ! printf '%s' "$net" | grep -Eq '^[0-9A-Fa-f:.]+(/[0-9]{1,3})?$'; then
            echo "sso-routes: ungueltiges Netz '${net}' fuer ${key}" >&2
            exit 1
        fi
    done
    for host in $hosts; do
        if ! printf '%s' "$host" | grep -Eq '^[a-z0-9.-]+$'; then
            echo "sso-routes: ungueltiger Hostname '${host}' fuer ${key}" >&2
            exit 1
        fi
    done
    if [ -z "$nets" ] && [ -z "$hosts" ]; then
        echo "sso-routes: Route ${key} ohne Netz und Hostname wird ignoriert." >&2
        IFS=';'
        continue
    fi

    # Ist die Instanz nicht erreichbar, bedient die Hauptinstanz die Anfrage
    # (Seite bleibt nutzbar, nur ohne automatische Anmeldung dieser Domaene).
    [ -n "$hosts" ] && host_rules="${host_rules}    use_backend src_${key} if { var(txn.host) -m str ${hosts} } { nbsrv(src_${key}) gt 0 }
"
    [ -n "$nets" ] && net_rules="${net_rules}    use_backend src_${key} if { src ${nets} } { nbsrv(src_${key}) gt 0 }
"
    backends="${backends}
backend src_${key}
    option httpchk GET /auth-health
    http-check expect status 204
    server ${key} ${target} send-proxy check check-send-proxy inter 10s resolvers docker init-addr last,libc,none
"
    IFS=';'
done
IFS="$old_ifs"

printf '%s%s' "$host_rules" "$net_rules"
cat <<'CFG'
    default_backend primary

backend primary
    server primary 127.0.0.1:8081 send-proxy
CFG
printf '%s' "$backends"
