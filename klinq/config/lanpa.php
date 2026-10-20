<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Anbindung an das Intranet (LANPA).
 *
 * KLINQ laeuft als Bestandteil des Intranets hinter dessen auth-Container. Die
 * Windows-Anmeldung (Kerberos/SSO) und die Sitzung uebernimmt das Intranet: es
 * stellt einen kurzlebigen, signierten Einstiegsschein aus, den KLINQ unter
 * /sso/lanpa prueft. Rollen und Berechtigungsgruppen werden ebenfalls im
 * Intranet gepflegt (AD-Gruppen) und mit dem Schein uebergeben.
 */
return [
    // Anbindung aktiv? Ohne Intranet laesst sich KLINQ weiterhin mit lokalen Konten betreiben.
    'enabled' => Env::bool('LANPA_ENABLED', true),

    // Gemeinsames Geheimnis mit dem Intranet (KLINQ_SSO_SECRET, mindestens 32 Zeichen).
    'sso_secret' => Env::get('KLINQ_SSO_SECRET', ''),

    // Aussteller und Empfaenger des Einstiegsscheins.
    'issuer' => Env::get('LANPA_SSO_ISSUER', 'lanpa'),
    'audience' => Env::get('LANPA_SSO_AUDIENCE', 'klinq'),

    // Basisadresse des Intranets (nur fuer absolute Verweise noetig, z. B. Rueckweg).
    'base_url' => Env::get('LANPA_BASE_URL', ''),

    // Interne Adresse des Intranet-Containers fuer Aufrufe ohne Proxy
    // (z. B. http://intranet-app:8080). Darueber wird /internal/klinq/ki abgefragt.
    'internal_url' => Env::get('LANPA_INTERNAL_URL', ''),

    // Stylesheet mit den Designfarben des Intranets. Standard: gleicher Host wie KLINQ
    // (protokollrelativ, damit es hinter dem Proxy unabhaengig vom Schema funktioniert).
    'design_css' => Env::get('LANPA_DESIGN_CSS', ''),

    // Zugang fuer die internen Schnittstellen des Intranets
    // (/internal/klinq/melder, /internal/klinq/ki). Im Container liegt das
    // gemeinsame Token im Volume des Intranets (service_token_file).
    'service_token' => Env::get('KLINQ_SERVICE_TOKEN', ''),
    'service_token_file' => Env::get('KLINQ_SERVICE_TOKEN_FILE', '/run/intranet-sso/token'),

    // Ablage bereits verwendeter Scheine (Einmalverwendung).
    'replay_store' => Env::get('LANPA_SSO_REPLAY_STORE', 'storage/tmp/lanpa-sso-used.json'),
];
