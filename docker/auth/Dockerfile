# syntax=docker/dockerfile:1
# Vorgelagerter Authentifizierungs-Container fuer NTLM (HTTP Negotiate)
# OHNE Kerberos. Apache + mod_auth_ntlm_winbind + Samba/winbind fuehren den
# NTLM-Handshake gegen den Domaenencontroller durch und reichen den
# identifizierten SamAccountName als Header "X-Remote-User" an die
# Hauptanwendung (app) weiter.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-auth-ntlm-winbind \
        winbind \
        samba-common-bin \
        ca-certificates \
    ; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    a2enmod proxy proxy_http headers auth_ntlm_winbind rewrite

COPY ntlm.conf /etc/apache2/sites-available/000-default.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
