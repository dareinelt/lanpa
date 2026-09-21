<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Basiswerte fuer den SNMP-Agenten (net-snmp/snmpd).
 *
 * Alle Werte koennen im Adminbereich ueberschrieben werden (Tabelle `settings`).
 * Der am Host veroeffentlichte UDP-Port bleibt ausschliesslich ueber die
 * Umgebungsvariable SNMP_PORT konfiguriert (Docker-Port-Mapping).
 */
return [
    'community' => Env::get('SNMP_COMMUNITY', 'public'),
    'sys_location' => Env::get('SNMP_SYS_LOCATION', 'Intranet'),
    'sys_contact' => Env::get('SNMP_SYS_CONTACT', 'admin@example.internal'),
];
