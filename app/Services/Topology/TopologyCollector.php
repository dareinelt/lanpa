<?php

declare(strict_types=1);

namespace App\Services\Topology;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Services\Monitoring\ContainerMetricsService;
use App\Services\Orvanta\OrvantaFlowService;
use Throwable;

/**
 * Sammelt die Tatsachen, aus denen die Gesamt-Topologie gebildet wird.
 *
 * Grundsaetze:
 * - Ausschliesslich lesend. Es werden keine Konfigurationen geaendert, keine
 *   Sicherungen erstellt und keine Wiederherstellungen ausgeloest.
 * - Jeder Bereich ist einzeln abgesichert: ein defektes oder nicht migriertes
 *   Modul darf die Topologie nicht verhindern, sondern erscheint als
 *   "unbekannt" mit Begruendung.
 * - Keine unkontrollierten Live-Proben. Verwendet werden die bereits
 *   vorhandenen, zwischengespeicherten oder gespeicherten Zustandsangaben der
 *   Module (siehe docs/admin-topologie-referenz.md).
 *
 * Die Rueckgabe ist eine flache Sammlung von Bereichen. Jeder Bereich hat die
 * Form ['state' => string, 'message' => string, 'measured_at' => ?int,
 * 'available' => bool, 'data' => array]. 'state' ist die Vokabel der Quelle und
 * wird erst in {@see LanpaTopologyService} auf das Topologie-Modell abgebildet.
 */
final class TopologyCollector
{
    /** Alter einer Messung, ab dem sie als veraltet gilt (Sekunden). */
    public const STALE_SECONDS = 300;

    private const SECTION_KEYS = [
        'runtime',
        'database',
        'containers',
        'orvanta',
        'mail_proxy',
        'office',
        'office_backup',
        'storage',
        'snapshots',
        'tls',
        'identity',
        'mail_dispatch',
        'alarm',
        'emergency',
        'monitoring',
        'ai',
        'optional',
    ];

    /**
     * @return array<string,mixed>
     */
    public function collect(): array
    {
        $now = time();
        $facts = ['now' => $now];
        foreach (self::SECTION_KEYS as $key) {
            $facts[$key] = [
                'state' => 'unknown',
                'message' => 'Nicht geprüft.',
                'measured_at' => null,
                'available' => false,
                'data' => [],
            ];
        }

        $facts['runtime'] = $this->runtime($now);
        $facts['database'] = $this->database($now);
        $facts['containers'] = $this->containers($now);
        $facts['orvanta'] = $this->orvanta($now);
        $facts['mail_proxy'] = $this->mailProxy($now);
        $facts['office'] = $this->office($now);
        $facts['office_backup'] = $this->officeBackup($now);
        $facts['storage'] = $this->storage($now);
        $facts['snapshots'] = $this->snapshots($now);
        $facts['tls'] = $this->tls($now);
        $facts['identity'] = $this->identity($now);
        $facts['mail_dispatch'] = $this->mailDispatch($now);
        $facts['alarm'] = $this->alarm($now);
        $facts['emergency'] = $this->emergency($now);
        $facts['monitoring'] = $this->monitoring($now);
        $facts['ai'] = $this->ai($now);
        $facts['optional'] = $this->optional($now);

        return $facts;
    }

    // ------------------------------------------------------------------ Bereiche

    /**
     * @return array<string,mixed>
     */
    /**
     * Laufzeitumgebung: PHP-Version sowie die Eigenschaften der Anfrage, die
     * gerade die Topologie abruft. Ein Proxy im Aufrufpfad (X-Forwarded-For)
     * und eine TLS-Terminierung (X-Forwarded-Proto) sind daraus ablesbar.
     */
    private function runtime(int $now): array
    {
        $request = Request::fromGlobals();
        $forwarded = trim((string) ($request->server['HTTP_X_FORWARDED_FOR'] ?? '')) !== '';

        return self::section('ok', 'Die Anwendung beantwortet Anfragen (PHP ' . PHP_VERSION . ').', $now, [
            'available' => true,
            'data' => [
                'php' => PHP_VERSION,
                'secure' => $request->isSecure(),
                'forwarded' => $forwarded,
            ],
        ]);
    }

    /**
     * Erreichbarkeit der Datenbank. Eine beantwortete Abfrage belegt die
     * Verbindung, nicht die Unversehrtheit der Daten.
     *
     * @return array<string,mixed>
     */
    private function database(int $now): array
    {
        try {
            Database::connection()->query('SELECT 1')->fetchColumn();

            return self::section('ok', 'Die Datenbank antwortet auf Abfragen. Über die Vollständigkeit der Daten sagt das nichts aus.', $now, [
                'available' => true,
            ]);
        } catch (Throwable $exception) {
            return self::section('error', 'Die Datenbank ist nicht erreichbar: ' . self::reason($exception), $now);
        }
    }

    /**
     * Kennzahlen der beobachteten Container (CPU, Arbeitsspeicher) aus der
     * Messreihe des monitor-Containers. Container ohne Probe bleiben "unbekannt".
     *
     * @return array<string,mixed>
     */
    private function containers(int $now): array
    {
        try {
            $dashboard = Container::containerMetrics()->dashboard();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Container-Kennzahlen sind nicht lesbar: ' . self::reason($exception), $now);
        }

        $cards = [];
        $worst = 'ok';
        $messages = [];
        foreach ((array) ($dashboard['cards'] ?? []) as $card) {
            $service = (string) ($card['service'] ?? '');
            if ($service === '') {
                continue;
            }
            $cards[$service] = $card;
            if (empty($card['available'])) {
                $worst = self::worse($worst, 'unknown');
                $messages[] = $service . ': keine Messung vorhanden';
                continue;
            }
            if (!empty($card['stale'])) {
                $worst = self::worse($worst, 'stale');
                $messages[] = $service . ': Messung veraltet';
                continue;
            }
            $state = ContainerMetricsService::state($card);
            $worst = self::worse($worst, $state === 'crit' ? 'critical' : $state);
        }

        $message = $messages === []
            ? 'Alle beobachteten Container melden Kennzahlen.'
            : 'Nicht bewertbar – ' . implode('; ', $messages) . '.';

        return self::section($worst, $message, $now, [
            'available' => $cards !== [],
            'data' => ['cards' => $cards],
        ]);
    }

    /**
     * Orvanta: Proxy-Pfad, Exchange-Hosts, Identitaetsquellen, Anwesenheit,
     * Zwischenspeicher und KI-Endpunkt. Die Werte stammen aus derselben
     * Erhebung wie das Nachrichtenfluss-Dashboard (begrenzte Live-Proben).
     *
     * @return array<string,mixed>
     */
    private function orvanta(int $now): array
    {
        try {
            $collected = Container::orvantaFlow()->collect();
            $flow = OrvantaFlowService::evaluate($collected);
        } catch (Throwable $exception) {
            return self::section('unknown', 'Orvanta ist nicht lesbar: ' . self::reason($exception), $now);
        }

        $enabled = Container::orvantaConfig()->isEnabled();
        $overall = (array) ($flow['overall'] ?? []);

        return self::section((string) ($overall['state'] ?? 'unknown'), (string) ($overall['message'] ?? ''), $now, [
            'available' => true,
            'data' => [
                'enabled' => $enabled,
                'demo' => Container::orvantaConfig()->isDemo(),
                'nodes' => (array) ($flow['nodes'] ?? []),
                'incidents' => (array) ($flow['incidents'] ?? []),
                'proxy' => (array) ($collected['proxy'] ?? []),
                'sources' => (array) ($collected['sources'] ?? []),
                'exchange' => (array) ($collected['exchange'] ?? []),
                'presence' => (array) ($collected['presence'] ?? []),
                'storage' => (array) ($collected['storage'] ?? []),
                'cache' => (array) ($collected['cache'] ?? []),
                'ai' => (array) ($collected['ai'] ?? []),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mailProxy(int $now): array
    {
        try {
            $diagnostics = Container::mailProxy()->diagnostics(true);
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der Mail-Proxy ist nicht lesbar: ' . self::reason($exception), $now);
        }

        $available = (bool) ($diagnostics['available'] ?? false);
        $service = (array) ($diagnostics['service'] ?? []);
        $servers = (int) (($diagnostics['counts'] ?? [])['servers'] ?? 0);

        if (!$available && $servers === 0) {
            return self::section('disabled', 'Kein Mail-Proxy eingerichtet.', $now, [
                'available' => false,
                'data' => ['servers' => 0],
            ]);
        }

        return self::section((string) ($service['ok'] ?? false ? 'ok' : 'error'), (string) ($service['message'] ?? ''), $now, [
            'available' => true,
            'data' => [
                'servers' => $servers,
                'counts' => (array) ($diagnostics['counts'] ?? []),
                'details' => (array) ($service['details'] ?? []),
            ],
        ]);
    }

    /**
     * Office: Nextcloud, Euro-Office und Konnektor.
     *
     * @return array<string,mixed>
     */
    private function office(int $now): array
    {
        try {
            $summary = Container::officeHealth()->publicSummary();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Office ist nicht lesbar: ' . self::reason($exception), $now);
        }

        return self::section((string) ($summary['state'] ?? 'unknown'), (string) ($summary['label'] ?? ''), $now, [
            'available' => (bool) ($summary['available'] ?? false),
            'data' => [
                'enabled' => Container::officeConfig()->isEnabled(),
                'summary' => $summary,
                'eurooffice_path' => Container::officeConfig()->euroOfficePublicPath(),
            ],
        ]);
    }

    /**
     * Sicherung des Office-Stapels (office-backup-Container).
     *
     * @return array<string,mixed>
     */
    private function officeBackup(int $now): array
    {
        try {
            $service = Container::officeBackup();
            $available = $service->isAgentAvailable();
            $status = $service->status();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Die Office-Sicherung ist nicht lesbar: ' . self::reason($exception), $now);
        }

        if (!$available) {
            return self::section('disabled', 'Die Office-Sicherung ist nicht eingerichtet (kein Status des Sicherungsagenten).', $now, [
                'available' => false,
            ]);
        }

        return self::section((string) ($status['state'] ?? 'unknown'), (string) ($status['message'] ?? ''), $now, [
            'available' => true,
            'data' => [
                'retention' => (int) ($status['retention'] ?? 0),
                'encryption' => (bool) ($status['encryption'] ?? false),
                'backups' => count((array) ($status['backups'] ?? [])),
                'updated_at' => (string) ($status['updated_at'] ?? ''),
                'pending' => (bool) ($status['pending'] ?? false),
                'pending_stale' => (bool) ($status['pending_stale'] ?? false),
            ],
        ]);
    }

    /**
     * Speicher-Tiering: Cold-Tier, Synchronisation und Fuellstand.
     *
     * @return array<string,mixed>
     */
    private function storage(int $now): array
    {
        try {
            $overview = Container::storage()->overview();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der Speicherzustand ist nicht lesbar: ' . self::reason($exception), $now);
        }

        $health = (array) ($overview['health'] ?? []);
        $ha = (array) ($health['ha'] ?? []);
        $sync = (array) ($health['sync'] ?? []);
        $enabled = (bool) ($overview['office_enabled'] ?? false);

        $state = (string) ($ha['state'] ?? 'unknown');
        $messages = [];
        if (trim((string) ($ha['message'] ?? '')) !== '') {
            $messages[] = (string) $ha['message'];
        }
        if (($sync['state'] ?? '') !== 'in_sync' && trim((string) ($sync['message'] ?? '')) !== '') {
            $messages[] = 'Synchronisation: ' . (string) $sync['message'];
        }

        return self::section($state, implode(' ', $messages), $now, [
            'available' => true,
            'data' => [
                'enabled' => $enabled,
                'health' => $health,
                'targets' => (array) ($overview['targets'] ?? []),
                'local' => (array) ($overview['local'] ?? []),
                'mode' => (string) ($overview['mode'] ?? ''),
                'mode_reason' => (string) ($overview['mode_reason'] ?? ''),
                'forecast_text' => (string) ($overview['forecast_text'] ?? ''),
                'incidents_open' => (int) ($overview['incidents_open'] ?? 0),
            ],
        ]);
    }

    /**
     * Snapshot-Speicher des Cold-Tiers.
     *
     * @return array<string,mixed>
     */
    private function snapshots(int $now): array
    {
        try {
            $status = Container::snapshots()->status();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der Snapshot-Speicher ist nicht lesbar: ' . self::reason($exception), $now);
        }

        return self::section((string) ($status['state'] ?? 'unknown'), (string) ($status['message'] ?? ''), $now, [
            'available' => true,
            'data' => [
                'enabled' => (bool) ($status['enabled'] ?? false),
                'configured' => (bool) ($status['configured'] ?? false),
                'unc_path' => (string) ($status['unc_path'] ?? ''),
                'total_bytes' => (int) ($status['total_bytes'] ?? 0),
                'free_bytes' => (int) ($status['free_bytes'] ?? 0),
                'snapshots_total' => (int) ($status['snapshots_total'] ?? 0),
                'pending' => (int) ($status['pending'] ?? 0),
                'failed' => (int) ($status['failed'] ?? 0),
                'last_snapshot_at' => (string) ($status['last_snapshot_at'] ?? ''),
                'retention_days' => (int) ($status['retention_days'] ?? 0),
                // Ein vorhandener Snapshot ist kein gepruefter Wiederherstellungspunkt.
                'restore_tested' => false,
            ],
        ]);
    }

    /**
     * Zertifikat des Reverse-Proxy.
     *
     * @return array<string,mixed>
     */
    private function tls(int $now): array
    {
        try {
            $state = Container::tlsCertificates()->state();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der Zertifikatszustand ist nicht lesbar: ' . self::reason($exception), $now);
        }

        $activeStatus = (string) ($state['active_status'] ?? 'none');
        $active = $state['active'] ?? null;
        $mode = (string) ($state['mode'] ?? '');
        $stale = (bool) ($state['sync_stale'] ?? true);

        $message = $activeStatus === 'valid'
            ? 'Ein gültiges Zertifikat ist hinterlegt' . ($stale ? ', die Auslieferung an den Proxy ist aber überfällig.' : '.')
            : 'Es ist kein gültiges Zertifikat hinterlegt; der Proxy verwendet das Notfall-Zertifikat.';

        // Ohne gueltiges Zertifikat liefert der Proxy das Notfall-Zertifikat
        // aus: Betrieb moeglich, aber eingeschraenkt – nicht "abgeschaltet".
        if ($activeStatus === 'valid') {
            $sectionState = $stale ? 'stale' : 'valid';
        } else {
            $sectionState = $activeStatus === 'none' || $activeStatus === '' ? 'warn' : $activeStatus;
        }

        return self::section(
            $sectionState,
            $message,
            $now,
            [
                'available' => true,
                'data' => [
                    'mode' => $mode,
                    'active_status' => $activeStatus,
                    'common_name' => is_array($active) ? (string) ($active['common_name'] ?? '') : '',
                    'days_left' => is_array($active) ? (int) ($active['days_left'] ?? 0) : null,
                    'sync_stale' => $stale,
                    'last_sync' => $state['last_sync'] ?? null,
                ],
            ]
        );
    }

    /**
     * Identitaetsquellen: Verzeichnisdienste und SSO-Arbeitsprozesse.
     *
     * @return array<string,mixed>
     */
    private function identity(int $now): array
    {
        try {
            $sources = Container::identitySources();
            $configured = $sources->isAnyConfigured();
            $configs = $sources->configs();
            $workers = $sources->ssoWorkers();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Die Identitätsquellen sind nicht lesbar: ' . self::reason($exception), $now);
        }

        $sync = ['available' => false, 'state' => 'unknown'];
        try {
            $sync = ['available' => true, 'state' => 'ok', 'sources' => count(Container::adSync()->sources())];
        } catch (Throwable) {
            $sync = ['available' => false, 'state' => 'unknown'];
        }

        return self::section($configured ? 'ok' : 'disabled', $configured
            ? sprintf('%d Identitätsquelle(n) konfiguriert.', count($configs))
            : 'Keine Identitätsquelle vollständig konfiguriert.', $now, [
            'available' => true,
            'data' => [
                'configured' => $configured,
                'count' => count($configs),
                'sso_workers' => count($workers),
                'ad_sync' => $sync,
            ],
        ]);
    }

    /**
     * Mailversand: SMTP-Konfiguration und Warteschlange.
     *
     * @return array<string,mixed>
     */
    private function mailDispatch(int $now): array
    {
        try {
            $config = Container::smtp()->config();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der Mailversand ist nicht lesbar: ' . self::reason($exception), $now);
        }

        $enabled = (string) ($config['smtp_enabled'] ?? '0') === '1';
        if (!$enabled) {
            return self::section('disabled', 'Der Mailversand ist nicht aktiviert.', $now, [
                'available' => false,
                'data' => ['host' => (string) ($config['smtp_host'] ?? '')],
            ]);
        }

        $queued = 0;
        $failed = 0;
        $total = 0;
        try {
            foreach (Container::mailQueue()->recent() as $mail) {
                $status = (string) ($mail['status'] ?? '');
                $total++;
                if ($status === 'queued' || $status === 'sending') {
                    $queued++;
                } elseif ($status === 'failed') {
                    $failed++;
                }
            }
        } catch (Throwable) {
            $queued = -1;
        }

        $state = $failed > 0 ? 'error' : ($queued > 0 ? 'queued' : 'ok');
        $message = $failed > 0
            ? sprintf('%d Nachricht(en) konnten nicht zugestellt werden.', $failed)
            : ($queued > 0 ? sprintf('%d Nachricht(en) warten auf Zustellung.', $queued) : 'Die Warteschlange ist leer.');

        return self::section($state, $message, $now, [
            'available' => true,
            'data' => [
                'host' => (string) ($config['smtp_host'] ?? ''),
                'from' => (string) ($config['smtp_from'] ?? ''),
                'queued' => max(0, $queued),
                'failed' => $failed,
                'recent' => $total,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function alarm(int $now): array
    {
        try {
            $configured = Container::settings()->isAlarmConfigured();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Die Alarmierung ist nicht lesbar: ' . self::reason($exception), $now);
        }

        return self::section($configured ? 'ok' : 'disabled', $configured
            ? 'Die Alarmierung ist konfiguriert.'
            : 'Die Alarmierung ist nicht konfiguriert.', $now, [
            'available' => true,
            'data' => ['configured' => $configured],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function emergency(int $now): array
    {
        try {
            $enabled = Container::settings()->bool('emergency_plan_enabled');
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der Notfallplan ist nicht lesbar: ' . self::reason($exception), $now);
        }

        return self::section($enabled ? 'ok' : 'disabled', $enabled
            ? 'Der Notfallplan ist aktiviert.'
            : 'Der Notfallplan ist nicht aktiviert.', $now, [
            'available' => true,
            'data' => ['enabled' => $enabled],
        ]);
    }

    /**
     * Betriebsueberwachung: SNMP-Dienst und Kennzahlenmelder.
     *
     * @return array<string,mixed>
     */
    private function monitoring(int $now): array
    {
        try {
            $snmp = Container::settings()->snmpConfig();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Die Betriebsüberwachung ist nicht lesbar: ' . self::reason($exception), $now);
        }

        $configured = trim((string) ($snmp['community'] ?? '')) !== '';

        return self::section($configured ? 'ok' : 'disabled', $configured
            ? 'Die SNMP-Auskunft ist konfiguriert.'
            : 'Die SNMP-Auskunft ist nicht konfiguriert.', $now, [
            'available' => true,
            'data' => [
                'snmp_configured' => $configured,
                'sys_location' => (string) ($snmp['sys_location'] ?? ''),
            ],
        ]);
    }

    /**
     * KI-Endpunkt der Office-Integration. Geprueft wird nur die
     * Konfiguration, nicht die Erreichbarkeit (keine Live-Probe).
     *
     * @return array<string,mixed>
     */
    private function ai(int $now): array
    {
        try {
            $service = Container::officeAi();
            $active = $service->isActive();
            $configured = $service->isConfigured();
        } catch (Throwable $exception) {
            return self::section('unknown', 'Der KI-Endpunkt ist nicht lesbar: ' . self::reason($exception), $now);
        }

        if (!$configured) {
            return self::section('disabled', 'Es ist kein KI-Endpunkt eingerichtet.', $now, [
                'available' => false,
            ]);
        }

        // Konfiguriert, aber nicht geprueft: ausdruecklich nicht "in Ordnung".
        return self::section($active ? 'unknown' : 'disabled', $active
            ? 'Der KI-Endpunkt ist eingerichtet; die Erreichbarkeit wird hier nicht geprüft.'
            : 'Die KI-Funktion ist nicht freigegeben.', $now, [
            'available' => true,
            'data' => ['configured' => $configured, 'active' => $active],
        ]);
    }

    /**
     * Optionale Zusatzdienste, die von der Anwendung aus nicht messbar sind.
     * Sie erscheinen bewusst als "unbekannt" und nie als "in Ordnung".
     *
     * @return array<string,mixed>
     */
    private function optional(int $now): array
    {
        return self::section('unknown', 'Optionale Zusatzdienste werden von der Anwendung aus nicht überwacht.', $now, [
            'available' => false,
        ]);
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private static function section(string $state, string $message, int $measuredAt, array $extra = []): array
    {
        return array_merge([
            'state' => $state,
            'message' => $message,
            'measured_at' => $measuredAt,
            'available' => true,
            'data' => [],
        ], $extra);
    }

    private static function reason(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message === '' ? $exception::class : $message;
    }

    /**
     * Schwererer der beiden Quellzustaende – nur fuer die Vorabbewertung der
     * Container-Kennzahlen, die Zuordnung zum Modell macht der Dienst.
     */
    private static function worse(string $a, string $b): string
    {
        $order = ['ok' => 0, 'warn' => 1, 'stale' => 2, 'unknown' => 3, 'critical' => 4];

        return ($order[$b] ?? 3) > ($order[$a] ?? 0) ? $b : $a;
    }
}
