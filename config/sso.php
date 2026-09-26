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

    // Header mit der Kennung der Identitaetsquelle, gesetzt von den
    // auth-Instanzen weiterer Domaenen (Zweigstellen, Tochtergesellschaften).
    // Fehlt er, gilt die Hauptquelle.
    'source_header' => Env::get('SSO_SOURCE_HEADER', 'X-Remote-Source'),

    // Kommagetrennte Liste vertrauenswuerdiger Proxys (IPs oder Hostnamen),
    // deren Header uebernommen werden duerfen. Im Docker-Setup ist das der
    // Hostname des auth-Containers (z. B. "auth"). Leer = kein Vertrauen.
    'trusted_proxy' => Env::get('SSO_TRUSTED_PROXY', ''),

    // Die Seite bleibt ohne Anmeldung nutzbar (oeffentliche Elemente); NTLM
    // wird nur am Anmeldepunkt /sso/anmelden verlangt. Mit aktivem
    // automatischen Versuch wird jeder Browser einmal je Sitzung dorthin
    // geleitet: Domaenen-Clients werden unbemerkt erkannt, alle anderen
    // kehren ohne Anmeldung zurueck. false = nur ueber "Mit Windows anmelden".
    'auto_login' => Env::bool('SSO_AUTO_LOGIN', true),

    // Gueltigkeit einer erkannten Windows-Anmeldung in der Sitzung (Sekunden);
    // danach wird sie erneut geprueft. 0 = bis zum Ende der Browsersitzung.
    'session_lifetime' => Env::int('SSO_SESSION_LIFETIME', 28800),

    // Testmodus: simuliert eine bestehende Windows-Anmeldung dieses Benutzers
    // (SamAccountName) ohne NTLM/Domaene. In APP_ENV=production wirkungslos.
    'fake_user' => Env::get('SSO_FAKE_USER', ''),
    'fake_display_name' => Env::get('SSO_FAKE_DISPLAY_NAME', ''),
    'fake_email' => Env::get('SSO_FAKE_EMAIL', ''),
    // Kommagetrennte AD-Gruppen des Testbenutzers (z. B. fuer Office-Apps).
    'fake_groups' => Env::get('SSO_FAKE_GROUPS', ''),
    // Kennung der Identitaetsquelle des Testbenutzers (leer = Hauptquelle).
    'fake_source' => Env::get('SSO_FAKE_SOURCE', ''),
    'fake_allowed' => strtolower((string) Env::get('APP_ENV', 'production')) !== 'production',
];
