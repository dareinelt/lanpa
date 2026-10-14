# syntax=docker/dockerfile:1
# Anwenden und Ueberwachen der Windows-Anmeldung (SSO) fuer alle
# Identitaetsquellen. Der Dienst ist der einzige mit Docker-Socket: der
# Adminbereich loest darueber das Anwenden aus und liest den Zustand zurueck
# (siehe docker/sso-agent/sso-agent.sh und docker-compose.yml).
FROM alpine:3.22

# bash   - Agent (Assoziative Felder, mapfile)
# docker-cli, docker-cli-compose, docker-cli-buildx - compose-Aufrufe
# jq     - Auftrags- und Zustandsdateien
# coreutils - date, timeout, stat (Alpine-Busybox genuegt nicht)
RUN apk add --no-cache \
        bash \
        coreutils \
        docker-cli \
        docker-cli-buildx \
        docker-cli-compose \
        jq \
        tzdata

COPY docker/sso-agent/sso-agent.sh /usr/local/bin/sso-agent
RUN chmod 0755 /usr/local/bin/sso-agent

# Der Agent meldet seine Bereitschaft ueber /tmp/sso-agent-alive (alle 5 s).
# Aelter als zwei Minuten = die Schleife steht.
HEALTHCHECK --interval=60s --timeout=5s --start-period=20s --retries=3 \
    CMD find /tmp/sso-agent-alive -mmin -2 2>/dev/null | grep -q .

ENTRYPOINT ["/usr/local/bin/sso-agent"]
CMD ["agent"]
