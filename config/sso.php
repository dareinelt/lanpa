<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Automatische Windows-Authentifizierung (NTLM / HTTP Negotiate) ohne Kerberos.
 *
 * Der vorgelagerte `auth`-Container fuehrt den NTLM-Handshake durch und reicht
 * den identifizierten SamAccountName per Header an die Anwendung weiter. Die
 * Anwendung vertraut diesem Header nur, wenn SSO aktiviert ist UND die Anfrage
 * von einem konfigurierten, vertrauenswuerdigen Proxy (dem auth-Container)
 * stammt. Dadurch kann ein direkter Client den Header nicht faelschen.
 */
return [
    // SSO ein- bzw. ausschalten. Ohne Aktivierung verhaelt sich die Seite
    // wie bisher (nur oeffentliche Kacheln / regulärer Login).
    'enabled' => Env::bool('SSO_ENABLED', false),

    // Header, den der auth-Container mit dem SamAccountName befuellt.
    'header' => Env::get('SSO_HEADER', 'X-Remote-User'),

    // Optionaler Header mit einer kommagetrennten Liste der AD-Gruppen.
    'groups_header' => Env::get('SSO_GROUPS_HEADER', 'X-Remote-Groups'),

    // Kommagetrennte Liste vertrauenswuerdiger Proxys (IPs oder Hostnamen),
    // deren Header uebernommen werden duerfen. Im Docker-Setup ist das der
    // Hostname des auth-Containers (z. B. "auth"). Leer = kein Vertrauen.
    'trusted_proxy' => Env::get('SSO_TRUSTED_PROXY', ''),
];
