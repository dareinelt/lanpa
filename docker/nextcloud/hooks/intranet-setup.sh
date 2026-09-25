#!/bin/sh
# Einrichtung der Nextcloud fuer die Intranet-Integration.
#
# Wird vom offiziellen Nextcloud-Image als www-data ausgefuehrt:
#   - post-installation: einmalig nach der Erstinstallation
#   - before-starting:   bei jedem Containerstart (idempotent)
#
# Alle Einstellungen werden aus Umgebungsvariablen/Docker-Secrets abgeleitet,
# sodass Aenderungen an .env mit einem Neustart wirksam werden. Apps werden
# ausschliesslich aus dem offiziellen Nextcloud-App-Store installiert.
set -u

occ() { php /var/www/html/occ --no-interaction "$@"; }
log() { echo "[intranet-setup] $*"; }
warn() { echo "[intranet-setup] WARNUNG: $*" >&2; }

is_true() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

read_secret() {
    # $1 = Name der *_FILE-Variablen
    eval "file=\${$1:-}"
    if [ -n "$file" ] && [ -r "$file" ]; then
        tr -d '\r\n' < "$file"
    fi
}

app_present() { occ app:getpath "$1" >/dev/null 2>&1; }

# Installiert eine App aus dem offiziellen App-Store bzw. aktiviert sie.
ensure_app() {
    app="$1"
    if app_present "$app"; then
        occ app:enable "$app" >/dev/null 2>&1 || warn "App $app konnte nicht aktiviert werden."
    else
        log "Installiere App $app aus dem Nextcloud-App-Store ..."
        occ app:install "$app" || warn "App $app konnte nicht installiert werden (Internetzugang zum App-Store?)."
    fi
}

# Verwaltete Dateien aktualisieren (Quelle: Bind-Mounts des Repositorys).
if [ -f /usr/src/nextcloud/config/intranet.config.php ] && [ -d /var/www/html/config ]; then
    cp /usr/src/nextcloud/config/intranet.config.php /var/www/html/config/intranet.config.php
    chmod 0640 /var/www/html/config/intranet.config.php
fi
if [ -d /opt/intranet/intranet_integration ] && [ -d /var/www/html/custom_apps ]; then
    rm -rf /var/www/html/custom_apps/intranet_integration
    cp -R /opt/intranet/intranet_integration /var/www/html/custom_apps/intranet_integration
fi

if ! occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
    log "Nextcloud ist noch nicht installiert - Einrichtung wird uebersprungen."
    exit 0
fi

if occ status --output=json 2>/dev/null | grep -q '"needsDbUpgrade":true'; then
    log "Datenbank-Upgrade ausstehend - Einrichtung erfolgt nach dem Upgrade."
    exit 0
fi

log "Grundkonfiguration ..."

# --- Webroot /office -------------------------------------------------------
occ config:system:set htaccess.RewriteBase --value=/office >/dev/null
occ maintenance:update:htaccess >/dev/null || warn ".htaccess konnte nicht aktualisiert werden."

# --- Vertrauenswuerdige Domains ---------------------------------------------
public_host=$(printf '%s' "${APP_URL:-http://localhost}" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##')
i=0
for domain in "$public_host" nextcloud $(printf '%s' "${NEXTCLOUD_EXTRA_TRUSTED_DOMAINS:-}" | tr ',;' '  '); do
    [ -n "$domain" ] || continue
    occ config:system:set trusted_domains "$i" --value="$domain" >/dev/null
    i=$((i + 1))
done
# Ueberzaehlige alte Eintraege entfernen.
while occ config:system:get trusted_domains "$i" >/dev/null 2>&1; do
    occ config:system:delete trusted_domains "$i" >/dev/null
    i=$((i + 1))
done

# --- Allgemeines -------------------------------------------------------------
occ config:system:set default_language --value="${NEXTCLOUD_DEFAULT_LANGUAGE:-de}" >/dev/null
occ config:system:set default_locale --value="${NEXTCLOUD_DEFAULT_LOCALE:-de_DE}" >/dev/null
occ config:system:set default_phone_region --value="${NEXTCLOUD_DEFAULT_PHONE_REGION:-DE}" >/dev/null
occ config:system:set maintenance_window_start --type=integer --value=1 >/dev/null
# Nextcloud spricht den DocumentServer ueber das interne Docker-Netz an.
occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
occ config:system:set skeletondirectory --value='' >/dev/null
occ background:cron >/dev/null 2>&1 || true
occ app:disable firstrunwizard >/dev/null 2>&1 || true

# --- Intranet-Integration (Fusszeile, Diagnose) -----------------------------
occ app:enable intranet_integration >/dev/null || warn "intranet_integration konnte nicht aktiviert werden."

# --- Euro-Office-Connector ----------------------------------------------------
ensure_app eurooffice
if app_present eurooffice; then
    # Massgeblich ist config/intranet.config.php (Secrets, interne Adressen).
    for key in DocumentServerUrl DocumentServerInternalUrl StorageUrl jwt_secret jwt_header; do
        occ config:app:delete eurooffice "$key" >/dev/null 2>&1 || true
    done
    # Editor im selben Tab oeffnen -> Intranet-Fusszeile bleibt sichtbar.
    occ config:app:set eurooffice sameTab --value=true >/dev/null
fi

# --- Active Directory (user_ldap) --------------------------------------------
if is_true "${NEXTCLOUD_LDAP_ENABLED:-false}" && [ -n "${LDAP_HOST:-}" ]; then
    log "Konfiguriere AD-Anbindung (user_ldap) ..."
    occ app:enable user_ldap >/dev/null

    prefix=$(occ ldap:show-config --output=json 2>/dev/null | php -r '$c=json_decode(stream_get_contents(STDIN),true); echo is_array($c)&&$c?array_key_first($c):"";')
    if [ -z "$prefix" ]; then
        prefix=$(occ ldap:create-empty-config -p | tr -d '\r\n ')
    fi

    port="${LDAP_PORT:-636}"
    starttls=0
    if is_true "${LDAP_USE_TLS:-true}"; then
        if [ "$port" = "636" ]; then host="ldaps://${LDAP_HOST}"; else host="ldap://${LDAP_HOST}"; starttls=1; fi
    else
        host="ldap://${LDAP_HOST}"
    fi
    certcheck=0
    is_true "${LDAP_VERIFY_CERT:-true}" || certcheck=1

    # Benutzerfilter: nur aktive Konten, optional nur Mitglieder bestimmter
    # AD-Gruppen (verschachtelt, LDAP_MATCHING_RULE_IN_CHAIN).
    base_filter="${NEXTCLOUD_LDAP_USER_FILTER:-(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))}"
    group_clause=""
    old_ifs=$IFS; IFS=';'
    for dn in ${NEXTCLOUD_LDAP_ALLOWED_GROUPS:-}; do
        dn=$(printf '%s' "$dn" | sed -E 's/^ +//; s/ +$//')
        [ -n "$dn" ] && group_clause="${group_clause}(memberOf:1.2.840.113556.1.4.1941:=${dn})"
    done
    IFS=$old_ifs
    if [ -n "$group_clause" ]; then
        user_filter="(&${base_filter}(|${group_clause}))"
    else
        user_filter="$base_filter"
    fi
    uid_attr="${LDAP_ATTR_SAMACCOUNT_NAME:-sAMAccountName}"
    login_filter="(&${user_filter}(|(${uid_attr}=%uid)(userPrincipalName=%uid)(mail=%uid)))"
    group_filter="${NEXTCLOUD_LDAP_GROUP_FILTER:-(objectClass=group)}"

    set_ldap() { occ ldap:set-config "$prefix" "$1" "$2" >/dev/null || warn "LDAP-Einstellung $1 fehlgeschlagen."; }
    set_ldap ldapHost "$host"
    set_ldap ldapPort "$port"
    set_ldap ldapTLS "$starttls"
    set_ldap turnOffCertCheck "$certcheck"
    set_ldap ldapAgentName "${LDAP_BIND_DN:-}"
    set_ldap ldapAgentPassword "$(read_secret NEXTCLOUD_LDAP_PASSWORD_FILE)"
    set_ldap ldapBase "${LDAP_BASE_DN:-}"
    set_ldap ldapBaseUsers "${NEXTCLOUD_LDAP_USERS_BASE_DN:-${LDAP_BASE_DN:-}}"
    # Gruppen-Pfad wie in der Intranet-AD-Konfiguration (mehrere per ";").
    groups_base="${NEXTCLOUD_LDAP_GROUPS_BASE_DN:-${LDAP_GROUP_BASE_DN:-${LDAP_BASE_DN:-}}}"
    set_ldap ldapBaseGroups "$(printf '%s' "$groups_base" | tr ';' '\n' | sed -E 's/^ +//; s/ +$//' | sed '/^$/d')"
    set_ldap ldapUserFilter "$user_filter"
    set_ldap ldapUserFilterMode 1
    set_ldap ldapLoginFilter "$login_filter"
    set_ldap ldapLoginFilterMode 1
    set_ldap ldapGroupFilter "$group_filter"
    set_ldap ldapGroupFilterMode 1
    set_ldap ldapUserDisplayName "${LDAP_ATTR_DISPLAY_NAME:-displayName}"
    set_ldap ldapEmailAttribute "${LDAP_ATTR_EMAIL:-mail}"
    set_ldap ldapGroupDisplayName cn
    set_ldap ldapGroupMemberAssocAttr member
    set_ldap useMemberOfToDetectMembership 1
    set_ldap ldapNestedGroups 1
    set_ldap ldapPagingSize "${LDAP_PAGE_SIZE:-500}"
    # Interne Nextcloud-Benutzernamen = SamAccountName (lesbar, passt zu NTLM).
    set_ldap ldapExpertUsernameAttr "$uid_attr"
    set_ldap ldapExpertUUIDUserAttr objectGUID
    set_ldap ldapExpertUUIDGroupAttr objectGUID
    set_ldap ldapConfigurationActive 1

    if [ -n "${NEXTCLOUD_LDAP_ADMIN_GROUP:-}" ]; then
        occ ldap:promote-group "${NEXTCLOUD_LDAP_ADMIN_GROUP}" --yes >/dev/null 2>&1 \
            || warn "AD-Gruppe ${NEXTCLOUD_LDAP_ADMIN_GROUP} konnte (noch) nicht zur Admin-Gruppe erklaert werden."
    fi
else
    if app_present user_ldap; then
        occ app:disable user_ldap >/dev/null 2>&1 || true
    fi
fi

# --- Automatische Windows-Anmeldung (NTLM ueber den auth-Container) ---------
if is_true "${SSO_ENABLED:-false}" && is_true "${NEXTCLOUD_LDAP_ENABLED:-false}"; then
    log "Aktiviere NTLM-Anmeldung (user_saml, Umgebungsvariable) ..."
    ensure_app user_saml
    if app_present user_saml; then
        occ config:app:set user_saml type --value=environment-variable >/dev/null
        # Nur Konten, die ueber das AD (user_ldap) bekannt sind.
        occ config:app:set user_saml general-require_provisioned_account --value=1 >/dev/null
        # Anmeldeformular (AD-Kennwort) bleibt fuer Nicht-Windows-Geraete nutzbar.
        occ config:app:set user_saml general-allow_multiple_user_back_ends --value=1 >/dev/null
        occ saml:config:get 2>/dev/null | grep -q '^ *- 1:' || occ saml:config:create >/dev/null
        occ saml:config:set \
            --general-uid_mapping=OFFICE_SSO_UID \
            --general-idp0_display_name="Windows-Anmeldung" \
            --saml-attribute-mapping-user_id_ldap_mapping="${LDAP_ATTR_SAMACCOUNT_NAME:-sAMAccountName}" \
            1 >/dev/null || warn "user_saml-Konfiguration fehlgeschlagen."
    fi
else
    if app_present user_saml; then
        occ app:disable user_saml >/dev/null 2>&1 || true
    fi
fi

# --- Office-Nutzung auf (AD-)Gruppen beschraenken (optional) ---------------
# Einstellung "groups" des Connectors (JSON-Liste der Nextcloud-Gruppen-IDs;
# bei user_ldap entspricht die ID dem cn der AD-Gruppe). Leer = alle Benutzer.
if app_present eurooffice; then
    groups_json=$(printf '%s' "${NEXTCLOUD_OFFICE_GROUPS:-}" | php -r '
        $g = array_values(array_filter(array_map("trim", preg_split("/[,;]/", stream_get_contents(STDIN))), "strlen"));
        echo $g ? json_encode($g, JSON_UNESCAPED_UNICODE) : "";')
    if [ -n "$groups_json" ]; then
        occ config:app:set eurooffice groups --value="$groups_json" >/dev/null
    else
        occ config:app:delete eurooffice groups >/dev/null 2>&1 || true
    fi
fi

log "Fertig."
exit 0
