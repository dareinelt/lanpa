<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Basiswerte fuer das SMS-Alarmierungs-Gateway.
 *
 * Die Werte koennen im Adminbereich ueberschrieben werden (Tabelle `settings`).
 * Das Gateway-Passwort kann zusaetzlich ausschliesslich ueber die Umgebung bzw.
 * ein Docker-Secret (ALARM_PASSWORD / ALARM_PASSWORD_FILE) bereitgestellt werden,
 * damit es nicht im Klartext in der Datenbank abgelegt werden muss.
 */
return [
    'host' => Env::get('ALARM_HOST', ''),
    'username' => Env::get('ALARM_USERNAME', ''),
    'password' => Env::get('ALARM_PASSWORD', ''),
];
