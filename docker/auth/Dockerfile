# syntax=docker/dockerfile:1
# Vorgelagerter Einstiegs-/Authentifizierungs-Container (einziger Container
# mit veroeffentlichtem HTTP-Port).
#
# 1. Windows-Anmeldung (HTTP Negotiate/SPNEGO) per Kerberos oder NTLM:
#    Apache + mod_auth_gssapi. Kerberos-Tickets werden gegen die Keytab des
#    Computerkontos geprueft (Service Principal HTTP/<Hostname der URL>),
#    NTLM-Antworten ueber den GSSAPI-Mechanismus gss-ntlmssp via winbind
#    gegen den Domaenencontroller. Der identifizierte Benutzer wird als Header
#    "X-Remote-User" an die Anwendung (app) weitergereicht. Nur aktiv bei
#    SSO_ENABLED=true.
#    (Das frueher verwendete Paket libapache2-mod-auth-ntlm-winbind ist in
#    Debian bookworm nicht mehr enthalten.)
#    Weitere Domaenen ohne Vertrauensstellung (Zweigstellen, Toechter, ...)
#    laufen als eigene Instanzen dieses Images (SSO_SOURCE=<KENNUNG>, siehe
#    scripts/sso-domains.sh). Die Hauptinstanz verteilt die Anfragen dann per
#    HAProxy (Client-Netz bzw. Hostname) auf die passende Instanz.
# 2. HTTPS mit dem Zertifikat aus der Zertifikatsverwaltung (tls-sync.sh).
# 3. Reverse-Proxy fuer Euro-Office: /office/ -> nextcloud, /eurooffice/ ->
#    eurooffice (inkl. WebSockets). Nur aktiv bei OFFICE_ENABLED=true.
# 4. Reverse-Proxy fuer LLMInt (KI-Oberflaeche, eigener Stack): /ki/ ->
#    LLMINT_UPSTREAM. Nur aktiv bei LLMINT_ENABLED=true.
# 5. Kennzahlen fuer das Admin-Dashboard: CPU-Last und offene TCP-Verbindungen
#    werden im Container gemessen (cgroup, /proc/net/tcp) und an die Anwendung
#    gemeldet (docker/auth/metrics.py). Nur die Hauptinstanz.

# --- Stufe 1: gss-ntlmssp aus den Debian-Quellen mit Patches bauen ----------
# Das Debian-Paket gss-ntlmssp 1.2.0 weist NTLM-NEGOTIATE-Nachrichten ohne
# optionales Versionsfeld (32 Byte) als ungueltig ab - genau diese schicken
# der eingebaute NTLM-Client von Chromium/Firefox und curl. Ausserdem liefert
# es bei gesetztem GssapiCredStore (Keytab fuer Kerberos) ohne expliziten
# Acceptor-Namen kein Server-Credential ("Not a server credential type").
# Ausserdem sendet es in der CHALLENGE ein leeres MsvAvFlags-Feld, das Windows
# bei der MIC-Berechnung ignoriert - die MIC-Pruefung schlaegt fehl und der
# Browser zeigt trotz Intranetzone einen Anmeldedialog (upstream #98, 1.3.1).
# Alles wird hier per Quellpatch behoben (docker/auth/patches/).
FROM debian:bookworm-slim AS gssntlmssp-build

ENV DEBIAN_FRONTEND=noninteractive
COPY docker/auth/patches/ /tmp/patches/
RUN set -eux; \
    sed -i 's/^Types: deb$/Types: deb deb-src/' /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends dpkg-dev devscripts equivs patch; \
    apt-get build-dep -y gss-ntlmssp; \
    cd /tmp && apt-get source gss-ntlmssp; \
    cd /tmp/gss-ntlmssp-*/; \
    for p in /tmp/patches/*.patch; do patch -p1 < "$p"; done; \
    DEB_BUILD_OPTIONS=nocheck dpkg-buildpackage -b -uc -us; \
    mkdir -p /out && cp /tmp/gss-ntlmssp_*.deb /out/

# --- Stufe 2: Laufzeit-Image ------------------------------------------------
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-auth-gssapi \
        gss-ntlmssp \
        winbind \
        krb5-user \
        chrony \
        haproxy \
        samba-common-bin \
        python3 \
        ca-certificates \
        curl \
        openssl \
    ; \
    rm -rf /var/lib/apt/lists/*

# Gepatchtes gss-ntlmssp (siehe Stufe 1) ueber das Debian-Paket installieren.
RUN --mount=type=bind,from=gssntlmssp-build,source=/out,target=/tmp/gss-out \
    set -eux; \
    dpkg -i /tmp/gss-out/gss-ntlmssp_*.deb; \
    apt-mark hold gss-ntlmssp

# Apache-Module; Apache (www-data) benoetigt fuer die NTLM-Pruefung Zugriff
# auf die privilegierte winbind-Pipe (Gruppe winbindd_priv).
RUN set -eux; \
    a2enmod proxy proxy_http proxy_wstunnel headers auth_gssapi rewrite alias remoteip ssl; \
    getent group winbindd_priv >/dev/null || groupadd -r winbindd_priv; \
    usermod -a -G winbindd_priv www-data; \
    printf '%s\n' 'ServerName localhost' 'ServerTokens Prod' 'ServerSignature Off' 'TraceEnable Off' \
        > /etc/apache2/conf-available/zz-intranet-security.conf; \
    a2enconf zz-intranet-security; \
    ln -sf /dev/stdout /var/log/apache2/access.log; \
    ln -sf /dev/stderr /var/log/apache2/error.log

COPY docker/auth/ntlm.conf /etc/apache2/sites-available/000-default.conf
COPY docker/auth/common.conf /etc/apache2/intranet/common.conf
COPY docker/auth/office.conf /etc/apache2/intranet/office.conf
COPY docker/auth/llmint.conf /etc/apache2/intranet/llmint.conf
COPY docker/auth/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/auth/sso-routes.sh /usr/local/bin/sso-routes.sh
COPY docker/auth/tls-sync.sh /usr/local/bin/tls-sync.sh
COPY docker/auth/backend-watch.sh /usr/local/bin/backend-watch.sh
COPY docker/auth/metrics.py /usr/local/bin/metrics.py
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/sso-routes.sh /usr/local/bin/tls-sync.sh \
        /usr/local/bin/backend-watch.sh /usr/local/bin/metrics.py

EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/auth-health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
