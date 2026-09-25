#!/bin/sh
# Startskript fuer den NTLM-Authentifizierungs-Container.
# Konfiguriert Samba/winbind (security = domain, also NTLM/RPC OHNE Kerberos)
# und startet anschliessend Apache.
set -e

: "${SSO_DOMAIN:=WORKGROUP}"

mkdir -p /var/run/samba

# Samba-/Winbind-Grundkonfiguration.
cat > /etc/samba/smb.conf <<EOF
[global]
   workgroup = ${SSO_DOMAIN}
   server string = Intranet Auth
   security = domain
   winbind use default domain = yes
   winbind offline logon = yes
   winbind enum users = no
   winbind enum groups = no
   idmap config * : backend = tdb
   idmap config * : range = 10000-20000
EOF

# Optionaler DC-Eintrag in /etc/hosts, damit der Domaenencontroller auch ohne
# externes DNS aufloesbar ist.
if [ -n "${SSO_DC}" ] && [ -n "${SSO_DC_IP}" ]; then
    echo "${SSO_DC_IP} ${SSO_DC}" >> /etc/hosts
fi

# Winbind starten, bevor ein evtl. Domaenenbeitritt erfolgt.
winbindd

# Optionaler Domaenenbeitritt ueber RPC (NTLM, ohne Kerberos).
if [ -n "${SSO_DC}" ] && [ -n "${SSO_JOIN_USER}" ] && [ -n "${SSO_JOIN_PASSWORD}" ]; then
    echo "Tritt der Domaene ${SSO_DOMAIN} bei ..."
    echo "${SSO_JOIN_PASSWORD}" | net rpc join -U "${SSO_JOIN_USER}%${SSO_JOIN_PASSWORD}" -S "${SSO_DC}" || true
fi

exec "$@"
