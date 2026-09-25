# syntax=docker/dockerfile:1
# Vorgelagerter Einstiegs-/Authentifizierungs-Container (einziger Container
# mit veroeffentlichtem HTTP-Port).
#
# 1. NTLM-Anmeldung (HTTP Negotiate) OHNE Kerberos: Apache + mod_auth_gssapi
#    mit dem GSSAPI-Mechanismus gss-ntlmssp, der die NTLM-Antworten ueber
#    winbind gegen den Domaenencontroller prueft. Der identifizierte
#    SamAccountName wird als Header "X-Remote-User" an die Anwendung (app)
#    weitergereicht. Nur aktiv bei SSO_ENABLED=true.
#    (Das frueher verwendete Paket libapache2-mod-auth-ntlm-winbind ist in
#    Debian bookworm nicht mehr enthalten.)
# 2. Reverse-Proxy fuer Euro-Office: /office/ -> nextcloud, /eurooffice/ ->
#    eurooffice (inkl. WebSockets). Nur aktiv bei OFFICE_ENABLED=true.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-auth-gssapi \
        gss-ntlmssp \
        winbind \
        samba-common-bin \
        ca-certificates \
        curl \
    ; \
    rm -rf /var/lib/apt/lists/*

# Apache-Module; Apache (www-data) benoetigt fuer die NTLM-Pruefung Zugriff
# auf die privilegierte winbind-Pipe (Gruppe winbindd_priv).
RUN set -eux; \
    a2enmod proxy proxy_http proxy_wstunnel headers auth_gssapi rewrite alias; \
    getent group winbindd_priv >/dev/null || groupadd -r winbindd_priv; \
    usermod -a -G winbindd_priv www-data; \
    printf '%s\n' 'ServerName localhost' 'ServerTokens Prod' 'ServerSignature Off' 'TraceEnable Off' \
        > /etc/apache2/conf-available/zz-intranet-security.conf; \
    a2enconf zz-intranet-security; \
    ln -sf /dev/stdout /var/log/apache2/access.log; \
    ln -sf /dev/stderr /var/log/apache2/error.log

COPY docker/auth/ntlm.conf /etc/apache2/sites-available/000-default.conf
COPY docker/auth/office.conf /etc/apache2/intranet/office.conf
COPY docker/auth/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/auth-health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
