<?php

declare(strict_types=1);

namespace App\Services\Topology;

/**
 * Baut den Gesamtgraphen der Anwendung aus den erhobenen Tatsachen und
 * liefert den JSON-Datenvertrag der Topologie-Ansicht.
 *
 * Verantwortlichkeiten (siehe docs/admin-topologie-referenz.md):
 * - Gruppen (fachliche Module) und Knoten anlegen
 * - Kanten mit Beziehungstyp, Belegstatus und Transportweg anlegen
 * - Zustaende auf das sechsstufige Modell abbilden
 * - Vorfälle und Kennzahlen ableiten
 * - Datenaktualitaet von der Erstellungszeit trennen
 *
 * Der Dienst fuehrt selbst keine Netzwerk- oder Datenbankabfragen aus; er
 * arbeitet ausschliesslich auf der Struktur aus {@see TopologyCollector}.
 */
final class LanpaTopologyService
{
    public const SCHEMA_VERSION = '1.0';

    /** Zentraler Knoten der logischen Anwendung. */
    public const ROOT = 'core:lanpa';

    /** Zustaende, die als Stoerung oder Einschraenkung gelten. */
    private const INCIDENT_STATES = [
        TopologyStatus::ERROR,
        TopologyStatus::WARN,
    ];

    /** Zustaende, die keine Aussage ueber die Gesundheit erlauben. */
    private const GAP_STATES = [
        TopologyStatus::UNKNOWN,
        TopologyStatus::STALE,
    ];

    /** Anzeigenamen der Knotenarten. */
    public const KIND_LABELS = [
        'core' => 'Anwendung',
        'users' => 'Benutzer',
        'external' => 'Externes System',
        'web' => 'Webzugriff',
        'identity' => 'Identität',
        'module' => 'Modul',
        'service' => 'Dienst',
        'database' => 'Datenbank',
        'cache' => 'Zwischenspeicher',
        'storage' => 'Speicher',
        'volume' => 'Persistenter Speicher',
        'backup' => 'Sicherung',
        'snapshot' => 'Snapshot',
        'monitor' => 'Überwachung',
        'mail' => 'Mail',
        'certificate' => 'Zertifikat',
        'network' => 'Netzwerk',
    ];

    /** Anzeigenamen der Beziehungstypen. */
    public const EDGE_LABELS = [
        'depends' => 'Abhängigkeit',
        'routes' => 'Weiterleitung',
        'stores' => 'Speicherung',
        'backs_up' => 'Sicherung',
        'restores' => 'Wiederherstellung',
        'monitors' => 'Überwachung',
        'contains' => 'Zugehörigkeit',
        'authenticates' => 'Authentifizierung',
        'replicates' => 'Synchronisation',
    ];

    /** Anzeigenamen des Belegstatus. */
    public const EVIDENCE_LABELS = [
        'proven' => 'Nachgewiesen',
        'derived' => 'Abgeleitet',
        'suspected' => 'Vermutet',
        'unwatched' => 'Nicht überwacht',
    ];

    /** Netzwerke aus docker-compose.yml (Kennung => Anzeigename). */
    private const NETWORKS = [
        'intranet' => 'Netz intranet',
        'office' => 'Netz office',
        'office_backend' => 'Netz office_backend (intern)',
        'mail_proxy' => 'Netz mail_proxy (intern)',
        'mail_egress' => 'Netz mail_egress',
        'storage_catalog' => 'Netz storage_catalog (intern)',
    ];

    /** Container aus docker-compose.yml => Netzwerke. */
    private const CONTAINER_NETWORKS = [
        'app' => ['intranet', 'office', 'office_backend', 'mail_proxy'],
        'db' => ['intranet'],
        'mail' => ['intranet'],
        'mail-archive' => ['intranet', 'office'],
        'mail-proxy' => ['mail_proxy', 'mail_egress'],
        'auth' => ['intranet', 'office'],
        'sync' => ['intranet'],
        'phpmyadmin' => ['intranet'],
        'snmp' => ['intranet', 'office'],
        'monitor' => ['intranet'],
        'nextcloud' => ['office', 'office_backend'],
        'nextcloud-db' => ['office_backend'],
        'nextcloud-redis' => ['office_backend'],
        'nextcloud-cron' => ['office', 'office_backend'],
        'nextcloud-ai-worker' => ['office', 'office_backend'],
        'eurooffice' => ['office'],
        'office-backup' => ['intranet', 'office_backend'],
        'storage-sync' => ['intranet', 'office_backend', 'storage_catalog'],
        'storage-sync-catalog' => ['storage_catalog'],
        'storage-sync-redis' => ['storage_catalog'],
    ];

    /** Aktualisierungsintervall der Ansicht in Sekunden (60 bis 600). */
    private int $refreshInterval = 120;

    public function __construct(private readonly TopologyCollector $collector = new TopologyCollector())
    {
    }

    /**
     * Intervall begrenzen und setzen.
     */
    public function setRefreshInterval(int $seconds): void
    {
        $this->refreshInterval = max(60, min(600, $seconds));
    }

    /**
     * Vollstaendiger Datenvertrag der Topologie.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $facts = $this->collector->collect();
        $graph = $this->build($facts);

        return $this->contract($graph, $facts);
    }

    /**
     * Aufbau des Graphen aus den Tatsachen.
     *
     * @param array<string,mixed> $facts
     */
    public function build(array $facts): TopologyGraph
    {
        $graph = new TopologyGraph();
        $now = (int) ($facts['now'] ?? time());

        $this->groups($graph);
        $this->clientsAndAccess($graph, $facts, $now);
        $this->identity($graph, $facts);
        $this->data($graph, $facts);
        $this->mail($graph, $facts);
        $this->orvanta($graph, $facts);
        $this->office($graph, $facts);
        $this->storage($graph, $facts);
        $this->protection($graph, $facts);
        $this->monitoring($graph, $facts);
        $this->optional($graph, $facts);
        $this->networks($graph);
        $this->baseEdges($graph);
        $this->applyFreshness($graph, $now);

        return $graph;
    }

    // ------------------------------------------------------------------ Gruppen

    private function groups(TopologyGraph $graph): void
    {
        $graph->group('group:access', 'Anwendung, Zugriff und Anmeldung', null, [
            'subtitle' => 'Clients, Reverse-Proxy und Zertifikat',
        ]);
        $graph->group('group:identity', 'Identitätsverwaltung', null, [
            'subtitle' => 'Verzeichnisdienst und Synchronisation',
        ]);
        $graph->group('group:data', 'Datenhaltung', null, [
            'subtitle' => 'Datenbank und persistente Anwendungsdaten',
        ]);
        $graph->group('group:mail', 'E-Mail', null, [
            'subtitle' => 'Versand, Warteschlange und Archivierung',
        ]);
        $graph->group('group:orvanta', 'Orvanta', null, [
            'subtitle' => 'Mail und Kalender',
            'module' => 'orvanta',
        ]);
        $graph->group('group:orvanta.exchange', 'Exchange-Anbindung (EWS)', 'group:orvanta', [
            'subtitle' => 'Host-Pool und Sitzungsaffinität',
        ]);
        $graph->group('group:orvanta.proxy', 'SMTP-/IMAP-Proxy', 'group:orvanta', [
            'subtitle' => 'Pfad für Benutzer ohne Exchange-Postfach',
        ]);
        $graph->group('group:orvanta.archive', 'Langzeitarchiv', 'group:orvanta', [
            'subtitle' => 'Archivierung und Suchindex',
        ]);
        $graph->group('group:office', 'Office', null, [
            'subtitle' => 'Nextcloud und Euro-Office',
            'module' => 'office',
        ]);
        $graph->group('group:office.nextcloud', 'Nextcloud', 'group:office', [
            'subtitle' => 'Webanwendung, Datenbank, Zwischenspeicher',
        ]);
        $graph->group('group:office.eurooffice', 'Euro-Office', 'group:office', [
            'subtitle' => 'DocumentServer',
        ]);
        $graph->group('group:ai', 'KI-Endpunkte', null, [
            'subtitle' => 'Textunterstützung und KI-Arbeitsprozess',
        ]);
        $graph->group('group:storage', 'Speicher und Tiering', null, [
            'subtitle' => 'Hot-Tier, Cold-Tier und Katalog',
        ]);
        $graph->group('group:storage.tiers', 'Speicherstufen', 'group:storage', [
            'subtitle' => 'Hot-Tier, Cold-Tier und Snapshot-Speicher',
        ]);
        $graph->group('group:protect', 'Sicherung und Wiederherstellung', null, [
            'subtitle' => 'Office-Sicherung, Ziel und Rückweg',
        ]);
        $graph->group('group:monitor', 'Überwachung und Betrieb', null, [
            'subtitle' => 'Kennzahlen, SNMP, Alarmierung, Notfallplan',
        ]);
        $graph->group('group:network', 'Netzwerke', null, [
            'subtitle' => 'Compose-Netze',
        ]);
        $graph->group('group:optional', 'Optionale Dienste', null, [
            'subtitle' => 'Nur vorhanden, wenn eingerichtet',
            'optional' => true,
        ]);
    }

    // ------------------------------------------------------------------ Zugriff

    /**
     * Benutzerseite, Reverse-Proxy, Zertifikat und die Anwendung selbst.
     *
     * @param array<string,mixed> $facts
     */
    private function clientsAndAccess(TopologyGraph $graph, array $facts, int $now): void
    {
        $runtime = self::sec($facts, 'runtime');
        $tls = self::sec($facts, 'tls');
        $tlsData = (array) ($tls['data'] ?? []);
        $secure = (bool) ($runtime['data']['secure'] ?? false);
        $forwarded = (bool) ($runtime['data']['forwarded'] ?? false);

        $graph->node('users:clients', [
            'title' => 'Clients und Windows-Anmeldung',
            'subtitle' => 'Browser der Beschäftigten im Intranet',
            'kind' => 'users',
            'group' => 'group:access',
            'layer' => 0,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Die Clients werden nicht überwacht; die Anmeldung erfolgt über die Domäne.',
            'evidence' => 'unwatched',
            'measured_at' => null,
            'facts' => [
                ['label' => 'Anmeldeverfahren', 'value' => 'Windows-Anmeldung über den Auth-Proxy'],
            ],
        ]);

        $graph->node('access:proxy', [
            'title' => 'Auth- und Reverse-Proxy',
            'subtitle' => 'Container auth – Windows-Anmeldung, TLS-Terminierung, Weiterleitung',
            'kind' => 'web',
            'group' => 'group:access',
            'layer' => 1,
            'state' => TopologyStatus::UNKNOWN,
            'message' => $forwarded
                ? 'Diese Anfrage kam über einen vorgeschalteten Proxy; der Proxy selbst wird nicht aktiv geprüft.'
                : 'Der Proxy wird von der Anwendung aus nicht aktiv geprüft.',
            'evidence' => $forwarded ? 'derived' : 'unwatched',
            'measured_at' => null,
            'link' => null,
            'containers' => ['auth'],
            'facts' => array_values(array_filter([
                ['label' => 'Konten', 'value' => 'Windows-Anmeldung (Kerberos/NTLM)'],
                ['label' => 'Ports', 'value' => '8080 und 8443'],
                ['label' => 'Weiterleitungen', 'value' => 'Anwendung, /office, /ki'],
                $forwarded ? ['label' => 'Diese Anfrage', 'value' => $secure ? 'über Proxy, verschlüsselt' : 'über Proxy'] : null,
            ])),
        ]);

        $graph->node('access:tls', [
            'title' => 'TLS-Zertifikat',
            'subtitle' => 'Zertifikat des HTTPS-Endpunkts',
            'kind' => 'certificate',
            'group' => 'group:access',
            'layer' => 1,
            'state' => (string) ($tls['state'] ?? 'unknown'),
            'message' => (string) ($tls['message'] ?? ''),
            'evidence' => (bool) ($tls['available'] ?? false) ? 'proven' : 'unwatched',
            'measured_at' => $tls['measured_at'] ?? null,
            'link' => '/admin/zertifikate',
            'facts' => array_values(array_filter([
                ['label' => 'Modus', 'value' => (string) ($tlsData['mode'] ?? '')],
                ['label' => 'Zustand', 'value' => (string) ($tlsData['active_status'] ?? '')],
                isset($tlsData['common_name']) && $tlsData['common_name'] !== ''
                    ? ['label' => 'Name', 'value' => (string) $tlsData['common_name']] : null,
                isset($tlsData['days_left']) && $tlsData['days_left'] !== null && $tlsData['days_left'] !== 0
                    ? ['label' => 'Restlaufzeit', 'value' => (int) $tlsData['days_left'] . ' Tage'] : null,
                !empty($tlsData['sync_stale'])
                    ? ['label' => 'Auslieferung', 'value' => 'überfällig'] : null,
            ])),
        ]);

        $graph->node(self::ROOT, [
            'title' => 'lanpa',
            'subtitle' => 'Intranet-Anwendung – PHP, PDO/MySQL, Vanilla-JS',
            'kind' => 'core',
            'group' => 'group:access',
            'layer' => 2,
            'state' => (string) ($runtime['state'] ?? 'ok'),
            'message' => (string) ($runtime['message'] ?? ''),
            'evidence' => 'proven',
            'measured_at' => $runtime['measured_at'] ?? $now,
            'link' => '/admin',
            'containers' => ['app'],
            'facts' => [
                ['label' => 'PHP', 'value' => (string) ($runtime['data']['php'] ?? PHP_VERSION)],
                ['label' => 'Zugriff', 'value' => $secure ? 'verschlüsselt' : 'unverschlüsselt'],
            ],
        ]);
    }

    // ------------------------------------------------------------------ Identitaet

    /**
     * @param array<string,mixed> $facts
     */
    private function identity(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'identity');
        $data = (array) ($section['data'] ?? []);
        $orvanta = self::sec($facts, 'orvanta');
        $orvantaData = (array) ($orvanta['data'] ?? []);
        $sources = (array) ($orvantaData['sources'] ?? []);

        $configured = (bool) ($data['configured'] ?? false);
        $adSync = (array) ($data['ad_sync'] ?? []);

        $graph->node('identity:directory', [
            'title' => 'Verzeichnisdienst',
            'subtitle' => 'LDAP beziehungsweise Active Directory',
            'kind' => 'identity',
            'group' => 'group:identity',
            'layer' => 3,
            'state' => $configured ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $configured
                ? 'Der Verzeichnisdienst ist konfiguriert; die Erreichbarkeit wird von der Anwendung nicht dauerhaft geprüft.'
                : 'Es ist kein Verzeichnisdienst konfiguriert.',
            'evidence' => $configured ? 'derived' : 'proven',
            'measured_at' => $section['measured_at'] ?? null,
            'link' => '/admin/ad',
            'facts' => [
                ['label' => 'Quellen', 'value' => (string) ($data['count'] ?? 0)],
            ],
        ]);

        $graph->node('identity:ad-sync', [
            'title' => 'AD-Synchronisation',
            'subtitle' => 'Container sync – scripts/sync_worker.php',
            'kind' => 'service',
            'group' => 'group:identity',
            'layer' => 4,
            'state' => (bool) ($adSync['available'] ?? false) ? 'ok' : 'unknown',
            'message' => (bool) ($adSync['available'] ?? false)
                ? 'Der Synchronisationsdienst ist erreichbar; der Verlauf wird hier nicht bewertet.'
                : 'Der Zustand des Synchronisationsdienstes ist nicht lesbar.',
            'evidence' => 'derived',
            'measured_at' => $section['measured_at'] ?? null,
            'containers' => ['sync'],
            'facts' => array_values(array_filter([
                (bool) ($adSync['available'] ?? false)
                    ? ['label' => 'Quellen', 'value' => (string) ($adSync['sources'] ?? 0)] : null,
            ])),
        ]);

        $ssoWorkers = (int) ($data['sso_workers'] ?? 0);
        if ($ssoWorkers > 0) {
            $graph->node('identity:sso', [
                'title' => 'SSO-Arbeitsprozesse',
                'subtitle' => 'Hintergrundprozesse der Anmeldung',
                'kind' => 'service',
                'group' => 'group:identity',
                'layer' => 4,
                'state' => TopologyStatus::UNKNOWN,
                'message' => 'Die SSO-Arbeitsprozesse werden nicht überwacht.',
                'evidence' => 'unwatched',
                'measured_at' => $section['measured_at'] ?? null,
                'facts' => [['label' => 'Prozesse', 'value' => (string) $ssoWorkers]],
            ]);
        }

        $graph->node('identity:sso-volume', [
            'title' => 'Volume sso_token',
            'subtitle' => 'Gemeinsamer Token-Speicher von Anwendung, Proxy und Überwachung',
            'kind' => 'volume',
            'group' => 'group:identity',
            'layer' => 5,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'sso_token']],
        ]);

        // Identitaetsquellen einzeln, weil der Transportweg je Quelle verschieden ist.
        foreach ($sources as $source) {
            $id = (int) ($source['id'] ?? 0);
            $label = (string) ($source['label'] ?? '');
            $transport = (string) ($source['transport'] ?? '');
            $state = (array) ($source['state'] ?? []);
            $counts = (array) ($source['counts'] ?? []);
            $active = (bool) ($source['active'] ?? false);
            $failures = (int) ($state['failures'] ?? 0);
            $lastError = trim((string) ($state['last_error'] ?? ''));

            $graph->node('identity:source:' . $id, [
                'title' => $label !== '' ? $label : 'Identitätsquelle ' . $id,
                'subtitle' => $id === 0 ? 'Primäre Identitätsquelle' : 'Zusätzliche Identitätsquelle',
                'kind' => 'identity',
                'group' => 'group:identity',
                'layer' => 3,
                'optional' => !$active,
                'state' => !$active ? TopologyStatus::OFF : ($lastError !== '' ? TopologyStatus::WARN : TopologyStatus::UNKNOWN),
                'message' => !$active
                    ? 'Die Identitätsquelle ist nicht aktiv.'
                    : ($lastError !== '' ? 'Letzter Fehler: ' . $lastError : 'Kein Fehler vermerkt; die Erreichbarkeit wird hier nicht geprüft.'),
                'evidence' => 'derived',
                'measured_at' => $section['measured_at'] ?? null,
                'link' => '/admin/ad',
                'facts' => array_values(array_filter([
                    ['label' => 'Domäne', 'value' => (string) ($source['domain'] ?? '')],
                    ['label' => 'Transportweg', 'value' => $transport === 'exchange' ? 'Exchange (EWS)' : 'SMTP-/IMAP-Proxy'],
                    ['label' => 'Postfächer', 'value' => (string) ($counts['mailboxes'] ?? 0)],
                    $failures > 0 ? ['label' => 'Fehler', 'value' => (string) $failures] : null,
                ])),
            ]);
        }
    }

    // ------------------------------------------------------------------ Daten

    /**
     * @param array<string,mixed> $facts
     */
    private function data(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'database');

        $graph->node('data:mysql', [
            'title' => 'MySQL-Datenbank',
            'subtitle' => 'Container db – alle Anwendungsdaten',
            'kind' => 'database',
            'group' => 'group:data',
            'layer' => 5,
            'state' => (string) ($section['state'] ?? 'unknown'),
            'message' => (string) ($section['message'] ?? ''),
            'evidence' => (bool) ($section['available'] ?? false) ? 'proven' : 'derived',
            'measured_at' => $section['measured_at'] ?? null,
            'containers' => ['db'],
            'facts' => [['label' => 'Zugriff', 'value' => 'PDO, ausschließlich vorbereitete Abfragen']],
        ]);

        $graph->node('data:mysql-volume', [
            'title' => 'Volume db_data',
            'subtitle' => 'Persistente Datenbankdateien',
            'kind' => 'volume',
            'group' => 'group:data',
            'layer' => 6,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'db_data']],
        ]);
    }

    // ------------------------------------------------------------------ Mail

    /**
     * @param array<string,mixed> $facts
     */
    private function mail(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'mail_dispatch');
        $data = (array) ($section['data'] ?? []);
        $orvanta = self::sec($facts, 'orvanta');
        $orvantaData = (array) ($orvanta['data'] ?? []);
        $archiveEnabled = (bool) ($orvantaData['enabled'] ?? false);

        $graph->node('mail:dispatch', [
            'title' => 'Mailversand (SMTP)',
            'subtitle' => 'Versand von Benachrichtigungen und Alarmen',
            'kind' => 'mail',
            'group' => 'group:mail',
            'layer' => 4,
            'state' => (string) ($section['state'] ?? 'unknown'),
            'message' => (string) ($section['message'] ?? ''),
            'evidence' => (bool) ($section['available'] ?? false) ? 'derived'
                : (TopologyStatus::fromSource((string) ($section['state'] ?? '')) === TopologyStatus::OFF ? 'proven' : 'unwatched'),
            'measured_at' => $section['measured_at'] ?? null,
            'link' => '/admin/smtp',
            'facts' => array_values(array_filter([
                (string) ($data['host'] ?? '') !== '' ? ['label' => 'Server', 'value' => (string) $data['host']] : null,
                (string) ($data['from'] ?? '') !== '' ? ['label' => 'Absender', 'value' => (string) $data['from']] : null,
            ])),
        ]);

        $graph->node('mail:queue', [
            'title' => 'Versandwarteschlange',
            'subtitle' => 'Tabelle mail_outbox',
            'kind' => 'service',
            'group' => 'group:mail',
            'layer' => 5,
            'state' => (string) ($section['state'] ?? 'unknown'),
            'message' => (string) ($section['message'] ?? ''),
            'evidence' => 'derived',
            'measured_at' => $section['measured_at'] ?? null,
            'facts' => array_values(array_filter([
                ['label' => 'Wartend', 'value' => (string) ($data['queued'] ?? 0)],
                ['label' => 'Fehlgeschlagen', 'value' => (string) ($data['failed'] ?? 0)],
            ])),
        ]);

        $graph->node('mail:worker', [
            'title' => 'Mail-Arbeitsprozess',
            'subtitle' => 'Container mail – scripts/mail_worker.php',
            'kind' => 'service',
            'group' => 'group:mail',
            'layer' => 4,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Der Arbeitsprozess wird über die Warteschlange mittelbar sichtbar, aber nicht direkt überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['mail'],
            'facts' => [['label' => 'Aufgabe', 'value' => 'Leert die Versandwarteschlange']],
        ]);

        $graph->node('mail:archive', [
            'title' => 'Mail-Archivierung',
            'subtitle' => 'Container mail-archive – Archivierungs- und Suchdienst',
            'kind' => 'service',
            'group' => 'group:mail',
            'layer' => 4,
            'state' => $archiveEnabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $archiveEnabled
                ? 'Die Archivierung ist eingerichtet; der Archivdienst wird hier nicht geprüft.'
                : 'Die Archivierung ist nicht eingerichtet.',
            'evidence' => $archiveEnabled ? 'derived' : 'proven',
            'measured_at' => $orvanta['measured_at'] ?? null,
            'containers' => ['mail-archive'],
            'facts' => [['label' => 'Ablage', 'value' => 'Archivdaten und Suchindex']],
        ]);
    }

    // ------------------------------------------------------------------ Orvanta

    /**
     * Orvanta als vollstaendiger Teilgraph: Exchange-Hosts, Proxy-Pfad,
     * Zwischenspeicher, Anwesenheit und KI einzeln.
     *
     * @param array<string,mixed> $facts
     */
    private function orvanta(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'orvanta');
        $data = (array) ($section['data'] ?? []);
        $enabled = (bool) ($data['enabled'] ?? false);
        $nodes = (array) ($data['nodes'] ?? []);
        $exchange = (array) ($data['exchange'] ?? []);
        $presence = (array) ($data['presence'] ?? []);
        $cache = (array) ($data['cache'] ?? []);
        $ai = (array) ($data['ai'] ?? []);
        $measuredAt = $section['measured_at'] ?? null;
        $limitations = self::flowLimitations($nodes);
        $sectionState = (string) ($section['state'] ?? 'unknown');
        $sectionMessage = (string) ($section['message'] ?? '');

        // Ein nicht aktiviertes Modul ist abgeschaltet, auch wenn die
        // Nachrichtenfluss-Auswertung Konfigurationshinweise meldet.
        if (!$enabled && ($section['available'] ?? false)) {
            $coreState = TopologyStatus::OFF;
            $coreMessage = 'Orvanta ist nicht aktiviert.';
        } else {
            $coreState = $sectionState;
            $coreMessage = $sectionMessage;
            if ($limitations !== [] && TopologyStatus::isProblem(TopologyStatus::fromSource($sectionState))) {
                $coreMessage = ($coreMessage !== '' ? $coreMessage . ': ' : '') . implode('; ', $limitations);
            }
        }

        $graph->node('orvanta:core', [
            'title' => 'Orvanta',
            'subtitle' => 'Mail und Kalender über Exchange und den SMTP-/IMAP-Proxy',
            'kind' => 'module',
            'group' => 'group:orvanta',
            'layer' => 3,
            'optional' => !$enabled,
            'state' => $coreState,
            'message' => $coreMessage,
            'evidence' => 'proven',
            'measured_at' => $measuredAt,
            'link' => '/admin/office/orvanta',
            'facts' => array_values(array_filter([
                ($section['available'] ?? false) ? ['label' => 'Zustand', 'value' => $enabled ? 'Aktiviert' : 'Nicht aktiviert'] : null,
                !empty($data['demo']) ? ['label' => 'Betriebsart', 'value' => 'Demo-Daten'] : null,
                !$enabled && $limitations !== [] ? ['label' => 'Offene Hinweise', 'value' => implode('; ', $limitations)] : null,
            ])),
        ]);

        $graph->node('orvanta:exchange-pool', [
            'title' => 'Exchange-Host-Pool (EWS)',
            'subtitle' => 'Auswahl und Failover der Exchange-Hosts',
            'kind' => 'service',
            'group' => 'group:orvanta.exchange',
            'layer' => 4,
            'state' => $enabled
                ? TopologyStatus::fromSource((string) (($nodes['exchange'] ?? [])['state'] ?? 'unknown'))
                : TopologyStatus::OFF,
            'message' => $enabled
                ? 'Der Poolzustand ergibt sich aus den einzelnen Hosts.'
                : 'Orvanta ist nicht aktiviert; der Host-Pool wird nicht genutzt.',
            'evidence' => (bool) ($exchange['available'] ?? false) || !$enabled ? 'proven' : 'unwatched',
            'measured_at' => $measuredAt,
            'link' => '/admin/office/orvanta/hosts',
            'facts' => array_values(array_filter([
                (bool) ($exchange['available'] ?? false)
                    ? ['label' => 'Hosts', 'value' => (string) ((($exchange['totals'] ?? [])['hosts']) ?? 0)] : null,
                (bool) ($exchange['available'] ?? false)
                    ? ['label' => 'Erreichbar', 'value' => (string) ((($exchange['totals'] ?? [])['online']) ?? 0)] : null,
            ])),
        ]);

        foreach ((array) ($exchange['hosts'] ?? []) as $host) {
            $hostId = (int) ($host['id'] ?? 0);
            $name = (string) ($host['host'] ?? '');
            $status = (string) ($host['status'] ?? 'unknown');
            $active = (int) ($host['active'] ?? 0) === 1;
            $graph->node('orvanta:exchange-host:' . $hostId, [
                'title' => $name,
                'subtitle' => 'Exchange-Host' . ($active ? '' : ' (nicht aktiv)'),
                'kind' => 'external',
                'group' => 'group:orvanta.exchange',
                'layer' => 5,
                'optional' => !$active,
                'state' => $active ? TopologyStatus::fromSource($status) : TopologyStatus::OFF,
                'message' => $active
                    ? 'Status: ' . (string) ($host['status_label'] ?? $status)
                    : 'Der Host ist im Pool nicht aktiv.',
                'evidence' => 'proven',
                'measured_at' => $measuredAt,
                'facts' => array_values(array_filter([
                    ['label' => 'Sitzungen', 'value' => (string) ($host['sessions'] ?? 0)],
                    isset($host['latency_label']) ? ['label' => 'Antwortzeit', 'value' => (string) $host['latency_label']] : null,
                    isset($host['last_check_label']) ? ['label' => 'Letzte Prüfung', 'value' => (string) $host['last_check_label']] : null,
                ])),
            ]);
        }

        $proxyState = (string) (($nodes['proxy'] ?? [])['state'] ?? 'unknown');
        $graph->node('orvanta:proxy', [
            'title' => 'SMTP-/IMAP-Proxy',
            'subtitle' => 'Container mail-proxy – eigener Transportweg',
            'kind' => 'service',
            'group' => 'group:orvanta.proxy',
            'layer' => 4,
            'state' => $proxyState === 'off' ? TopologyStatus::OFF : TopologyStatus::fromSource($proxyState),
            'message' => (string) (($nodes['proxy'] ?? [])['message'] ?? ''),
            'evidence' => 'proven',
            'measured_at' => $measuredAt,
            'containers' => ['mail-proxy'],
            'facts' => array_values(array_filter([
                isset($nodes['proxy']['state_label']) ? ['label' => 'Zustand', 'value' => (string) $nodes['proxy']['state_label']] : null,
            ])),
        ]);

        foreach ((array) ($data['proxy']['servers'] ?? []) as $server) {
            $serverId = (int) ($server['id'] ?? 0);
            $name = (string) ($server['label'] ?? $server['host'] ?? '');
            if ($serverId === 0 && $name === '') {
                continue;
            }
            $graph->node('orvanta:proxy-server:' . $serverId, [
                'title' => $name,
                'subtitle' => 'Mailserver des Proxy-Pfads',
                'kind' => 'mail',
                'group' => 'group:orvanta.proxy',
                'layer' => 5,
                'state' => TopologyStatus::UNKNOWN,
                'message' => 'Der Mailserver wird über den Proxy erreicht; ein eigener Zustand liegt nicht vor.',
                'evidence' => 'derived',
                'measured_at' => $measuredAt,
                'facts' => array_values(array_filter([
                    isset($server['host']) ? ['label' => 'Server', 'value' => (string) $server['host']] : null,
                    isset($server['port']) ? ['label' => 'Port', 'value' => (string) $server['port']] : null,
                ])),
            ]);
        }

        $cacheNode = (array) ($nodes['cache'] ?? []);
        $cacheEnabled = $enabled && ($cache !== [] || $cacheNode !== []);
        $graph->node('orvanta:attachment-cache', [
            'title' => 'Anhang-Zwischenspeicher',
            'subtitle' => 'Abgelegte Anhänge und Dateispeicher',
            'kind' => 'cache',
            'group' => 'group:orvanta',
            'layer' => 4,
            'state' => $cacheEnabled ? TopologyStatus::fromSource((string) ($cacheNode['state'] ?? 'unknown')) : TopologyStatus::OFF,
            'message' => $cacheEnabled
                ? ((string) ($cacheNode['message'] ?? '') !== '' ? (string) $cacheNode['message'] : 'Belegung des Anhang-Zwischenspeichers.')
                : 'Orvanta ist nicht aktiviert; der Zwischenspeicher wird nicht genutzt.',
            'evidence' => $cacheEnabled ? 'derived' : 'proven',
            'measured_at' => $measuredAt,
            'facts' => array_values(array_filter([
                isset($cache['items']) ? ['label' => 'Einträge', 'value' => (string) $cache['items']] : null,
                isset($cache['percent']) ? ['label' => 'Belegt', 'value' => (string) $cache['percent'] . ' %'] : null,
            ])),
        ]);

        $presenceAvailable = $enabled && $presence !== [];
        $graph->node('orvanta:presence', [
            'title' => 'Anwesenheit und Erinnerungen',
            'subtitle' => 'Sitzungs- und Erinnerungsdienst',
            'kind' => 'service',
            'group' => 'group:orvanta',
            'layer' => 4,
            'state' => $presenceAvailable
                ? ((int) ($presence['samples'] ?? 0) > 0 ? TopologyStatus::OK : TopologyStatus::UNKNOWN)
                : TopologyStatus::OFF,
            'message' => $presenceAvailable
                ? ((int) ($presence['samples'] ?? 0) > 0
                    ? 'Es liegen aktuelle Anwesenheitsmessungen vor.'
                    : 'Noch keine Anwesenheitsmessung im Beobachtungsfenster.')
                : 'Orvanta ist nicht aktiviert; Anwesenheit wird nicht erfasst.',
            'evidence' => $presenceAvailable ? 'derived' : 'proven',
            'measured_at' => $measuredAt,
            'facts' => array_values(array_filter([
                isset($presence['current']) ? ['label' => 'Angemeldet', 'value' => (string) $presence['current']] : null,
                isset($presence['max']) ? ['label' => 'Höchstwert', 'value' => (string) $presence['max']] : null,
            ])),
        ]);

        $aiConfigured = (bool) ($ai['configured'] ?? false);
        $graph->node('orvanta:ai', [
            'title' => 'KI-Textunterstützung',
            'subtitle' => 'OpenAI-kompatibler Endpunkt',
            'kind' => 'service',
            'group' => 'group:ai',
            'layer' => 4,
            'optional' => !$aiConfigured,
            'state' => $aiConfigured ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $aiConfigured
                ? 'Der Endpunkt ist eingerichtet; die Erreichbarkeit wird auf dieser Seite nicht geprüft.'
                : 'Es ist kein KI-Endpunkt eingerichtet.',
            'evidence' => $aiConfigured ? 'derived' : 'proven',
            'measured_at' => $measuredAt,
            'facts' => array_values(array_filter([
                isset($ai['model']) ? ['label' => 'Modell', 'value' => (string) $ai['model']] : null,
            ])),
        ]);

        $graph->node('orvanta:archive-store', [
            'title' => 'Archivdaten und Suchindex',
            'subtitle' => 'Langzeitablage der archivierten Nachrichten',
            'kind' => 'storage',
            'group' => 'group:orvanta.archive',
            'layer' => 5,
            'state' => $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => 'Der Archivbestand wird nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => $measuredAt,
            'facts' => [['label' => 'Bestandteile', 'value' => 'Nachrichten, Anhänge, Suchindex']],
        ]);
    }

    // ------------------------------------------------------------------ Office

    /**
     * @param array<string,mixed> $facts
     */
    private function office(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'office');
        $data = (array) ($section['data'] ?? []);
        $enabled = (bool) ($data['enabled'] ?? false);
        $measuredAt = $section['measured_at'] ?? null;
        $ai = self::sec($facts, 'ai');
        $aiConfigured = (bool) (($ai['data'] ?? [])['configured'] ?? false);

        $graph->node('office:connector', [
            'title' => 'Office-Integration',
            'subtitle' => 'Konnektor zwischen Anwendung, Nextcloud und Euro-Office',
            'kind' => 'module',
            'group' => 'group:office',
            'layer' => 3,
            'optional' => !$enabled,
            'state' => (string) ($section['state'] ?? 'unknown'),
            'message' => (string) ($section['message'] ?? ''),
            'evidence' => (bool) ($section['available'] ?? false) ? 'proven' : 'derived',
            'measured_at' => $measuredAt,
            'link' => '/admin/office',
            'facts' => array_values(array_filter([
                ['label' => 'Status', 'value' => (string) (($data['summary'] ?? [])['label'] ?? '')],
                ['label' => 'Euro-Office', 'value' => (string) ($data['eurooffice_path'] ?? '')],
            ])),
        ]);

        $graph->node('office:nextcloud', [
            'title' => 'Nextcloud',
            'subtitle' => 'Container nextcloud – Dateien und Webanwendung',
            'kind' => 'web',
            'group' => 'group:office.nextcloud',
            'layer' => 4,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::fromSource((string) ($section['state'] ?? 'unknown')) : TopologyStatus::OFF,
            'message' => $enabled ? 'Zustand aus der Office-Gesundheitsprüfung.' : 'Nextcloud ist nicht aktiviert.',
            'evidence' => $enabled ? 'proven' : 'derived',
            'measured_at' => $measuredAt,
            'containers' => ['nextcloud'],
            'facts' => [['label' => 'Aufgabe', 'value' => 'Dateispeicher, Office-Weboberfläche']],
        ]);

        $graph->node('office:nextcloud-db', [
            'title' => 'Nextcloud-Datenbank',
            'subtitle' => 'Container nextcloud-db – PostgreSQL',
            'kind' => 'database',
            'group' => 'group:office.nextcloud',
            'layer' => 5,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $enabled ? 'Die Datenbank wird nicht einzeln überwacht.' : 'Nextcloud ist nicht aktiviert.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['nextcloud-db'],
            'facts' => [['label' => 'Volume', 'value' => 'nextcloud_db']],
        ]);

        $graph->node('office:nextcloud-redis', [
            'title' => 'Nextcloud-Zwischenspeicher',
            'subtitle' => 'Container nextcloud-redis',
            'kind' => 'cache',
            'group' => 'group:office.nextcloud',
            'layer' => 5,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $enabled ? 'Der Zwischenspeicher wird nicht einzeln überwacht.' : 'Nextcloud ist nicht aktiviert.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['nextcloud-redis'],
        ]);

        $graph->node('office:nextcloud-cron', [
            'title' => 'Nextcloud-Cron',
            'subtitle' => 'Container nextcloud-cron – Hintergrundaufgaben',
            'kind' => 'service',
            'group' => 'group:office.nextcloud',
            'layer' => 4,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $enabled ? 'Der Cron läuft im eigenen Container; ein Zustand wird nicht erfasst.' : 'Nextcloud ist nicht aktiviert.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['nextcloud-cron'],
        ]);

        $graph->node('office:ai-worker', [
            'title' => 'Nextcloud-KI-Arbeitsprozess',
            'subtitle' => 'Container nextcloud-ai-worker',
            'kind' => 'service',
            'group' => 'group:ai',
            'layer' => 4,
            'optional' => !$aiConfigured,
            'state' => !$aiConfigured ? TopologyStatus::OFF : TopologyStatus::UNKNOWN,
            'message' => $aiConfigured
                ? 'Der Arbeitsprozess läuft im eigenen Container; ein Zustand wird nicht erfasst.'
                : 'Es ist kein KI-Endpunkt eingerichtet.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['nextcloud-ai-worker'],
        ]);

        $graph->node('office:eurooffice', [
            'title' => 'Euro-Office DocumentServer',
            'subtitle' => 'Container eurooffice – Dokumentbearbeitung',
            'kind' => 'service',
            'group' => 'group:office.eurooffice',
            'layer' => 4,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::fromSource((string) ($section['state'] ?? 'unknown')) : TopologyStatus::OFF,
            'message' => $enabled ? 'Zustand aus der Office-Gesundheitsprüfung.' : 'Euro-Office ist nicht aktiviert.',
            'evidence' => $enabled ? 'proven' : 'derived',
            'measured_at' => $measuredAt,
            'containers' => ['eurooffice'],
            'facts' => array_values(array_filter([
                ['label' => 'Zugang', 'value' => 'über Nextcloud, gesichert per Token'],
            ])),
        ]);

        $graph->node('office:eurooffice-data', [
            'title' => 'Volume eurooffice_data',
            'subtitle' => 'Dokumente und Zwischenstände des DocumentServers',
            'kind' => 'volume',
            'group' => 'group:office.eurooffice',
            'layer' => 6,
            'optional' => !$enabled,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'eurooffice_data']],
        ]);

        $graph->node('office:files', [
            'title' => 'Volume nextcloud_data',
            'subtitle' => 'Dateien der Beschäftigten',
            'kind' => 'volume',
            'group' => 'group:office.nextcloud',
            'layer' => 6,
            'optional' => !$enabled,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'nextcloud_data']],
        ]);

        $graph->node('office:app-volume', [
            'title' => 'Volume office_ai',
            'subtitle' => 'Gemeinsamer KI-Arbeitsbereich von Anwendung und Euro-Office',
            'kind' => 'volume',
            'group' => 'group:ai',
            'layer' => 6,
            'optional' => !$aiConfigured,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'office_ai']],
        ]);

        $graph->node('office:app-storage', [
            'title' => 'Volume app_storage',
            'subtitle' => 'Laufzeitdaten der Anwendung',
            'kind' => 'volume',
            'group' => 'group:access',
            'layer' => 6,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'app_storage']],
        ]);
    }

    // ------------------------------------------------------------------ Speicher

    /**
     * @param array<string,mixed> $facts
     */
    private function storage(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'storage');
        $data = (array) ($section['data'] ?? []);
        $health = (array) ($data['health'] ?? []);
        $sync = (array) ($health['sync'] ?? []);
        $local = (array) ($data['local'] ?? []);
        $enabled = (bool) ($data['enabled'] ?? false);
        $measuredAt = $section['measured_at'] ?? null;
        $available = (bool) ($section['available'] ?? false);

        $graph->node('storage:service', [
            'title' => 'Speicher-Tiering',
            'subtitle' => 'Container storage-sync – Auslagerung und Rückholung',
            'kind' => 'module',
            'group' => 'group:storage',
            'layer' => 3,
            'optional' => !$enabled,
            'state' => (string) ($section['state'] ?? 'unknown'),
            'message' => (string) ($section['message'] ?? ''),
            'evidence' => $available ? 'proven' : 'unwatched',
            'measured_at' => $measuredAt,
            'link' => '/admin/speicher-ha',
            'containers' => ['storage-sync'],
            'facts' => array_values(array_filter([
                ['label' => 'Betriebsart', 'value' => (string) ($data['mode'] ?? '')],
                ['label' => 'Synchronisation', 'value' => (string) ($sync['state'] ?? '')],
                (string) ($data['forecast_text'] ?? '') !== ''
                    ? ['label' => 'Prognose', 'value' => (string) $data['forecast_text']] : null,
            ])),
        ]);

        // Ohne Messwert ist der Hot-Tier nicht "abgeschaltet", sondern
        // unbewertet – ausser das Tiering ist insgesamt nicht aktiv.
        $hotFill = self::fillLabel($local);
        if (!$available) {
            $hotState = TopologyStatus::UNKNOWN;
            $hotMessage = 'Der Füllstand ist nicht lesbar.';
        } elseif ($hotFill === '') {
            $hotState = $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF;
            $hotMessage = $enabled
                ? 'Noch keine Messwerte des lokalen Speichers; der Speicher-Worker liefert sie im Betrieb.'
                : 'Speicher-Tiering ist nicht aktiv; der Füllstand wird nicht gemessen.';
        } else {
            $hotState = TopologyStatus::fromSource(self::fillState($local));
            $hotMessage = 'Füllstand des lokalen Speichers: ' . $hotFill . '.';
        }

        $graph->node('storage:hot', [
            'title' => 'Lokaler Hot-Tier',
            'subtitle' => 'Schneller Speicher der Anwendung',
            'kind' => 'storage',
            'group' => 'group:storage.tiers',
            'layer' => 4,
            'state' => $hotState,
            'message' => $hotMessage,
            'evidence' => $available && $hotFill !== '' ? 'proven' : ($hotState === TopologyStatus::OFF ? 'proven' : 'unwatched'),
            'measured_at' => $available && $hotFill !== '' ? $measuredAt : null,
            'facts' => array_values(array_filter([
                $hotFill !== '' ? ['label' => 'Füllstand', 'value' => $hotFill] : null,
                (string) ($local['metrics_source'] ?? '') !== '' ? ['label' => 'Messquelle', 'value' => (string) $local['metrics_source']] : null,
            ])),
        ]);

        foreach ((array) ($data['targets'] ?? []) as $tier) {
            $tierId = (string) ($tier['id'] ?? '');
            if ($tierId === '') {
                continue;
            }
            $graph->node('storage:cold:' . $tierId, [
                'title' => (string) ($tier['label'] ?? 'Cold-Tier'),
                'subtitle' => 'Cold-Tier (' . (string) ($tier['kind'] ?? '') . ')',
                'kind' => 'storage',
                'group' => 'group:storage.tiers',
                'layer' => 5,
                'optional' => !((bool) ($tier['active'] ?? true)),
                'state' => TopologyStatus::fromSource((string) ($tier['state'] ?? 'unknown')),
                'message' => (string) ($tier['message'] ?? ''),
                'evidence' => 'proven',
                'measured_at' => $measuredAt,
                'facts' => array_values(array_filter([
                    self::fillLabel($tier) !== '' ? ['label' => 'Füllstand', 'value' => self::fillLabel($tier)] : null,
                    isset($tier['role']) ? ['label' => 'Rolle', 'value' => (string) $tier['role']] : null,
                    !empty($tier['is_primary']) ? ['label' => 'Rolle', 'value' => 'primäres Ziel'] : null,
                ])),
            ]);
        }

        $graph->node('storage:catalog', [
            'title' => 'Synchronisationskatalog',
            'subtitle' => 'Container storage-sync-catalog – MySQL',
            'kind' => 'database',
            'group' => 'group:storage',
            'layer' => 5,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $enabled ? 'Der Katalog wird nicht einzeln überwacht.' : 'Das Speicher-Tiering ist nicht aktiv.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['storage-sync-catalog'],
            'facts' => [['label' => 'Volume', 'value' => 'storage_sync_catalog_data']],
        ]);

        $graph->node('storage:redis', [
            'title' => 'Synchronisations-Zwischenspeicher',
            'subtitle' => 'Container storage-sync-redis',
            'kind' => 'cache',
            'group' => 'group:storage',
            'layer' => 5,
            'optional' => !$enabled,
            'state' => $enabled ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $enabled ? 'Der Zwischenspeicher wird nicht einzeln überwacht.' : 'Das Speicher-Tiering ist nicht aktiv.',
            'evidence' => 'derived',
            'measured_at' => null,
            'containers' => ['storage-sync-redis'],
        ]);

        $graph->node('storage:tiering-volume', [
            'title' => 'Volume storage_tiering',
            'subtitle' => 'Ausgelagerte Dateien und Platzhalter',
            'kind' => 'volume',
            'group' => 'group:storage',
            'layer' => 6,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'storage_tiering']],
        ]);

        $graph->node('storage:state-volume', [
            'title' => 'Volume storage_sync_state',
            'subtitle' => 'Zustand des Auslagerungsdienstes',
            'kind' => 'volume',
            'group' => 'group:storage',
            'layer' => 6,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Persistente Speicher werden nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Volume', 'value' => 'storage_sync_state']],
        ]);

        $snapshotSection = self::sec($facts, 'snapshots');
        $snapshotData = (array) ($snapshotSection['data'] ?? []);
        $snapshotEnabled = (bool) ($snapshotData['enabled'] ?? false);

        $graph->node('storage:snapshots', [
            'title' => 'Snapshot-Speicher',
            'subtitle' => 'Zeitpunktaufnahmen des Cold-Tiers',
            'kind' => 'snapshot',
            'group' => 'group:storage.tiers',
            'layer' => 5,
            'optional' => !$snapshotEnabled,
            'state' => (string) ($snapshotSection['state'] ?? 'unknown'),
            'message' => trim((string) ($snapshotSection['message'] ?? '')) !== ''
                ? (string) $snapshotSection['message']
                : ($snapshotEnabled ? 'Der Snapshot-Dienst liefert keine Meldung.' : 'Snapshots sind nicht eingerichtet.'),
            'evidence' => (bool) ($snapshotSection['available'] ?? false) ? 'proven' : 'unwatched',
            'measured_at' => $snapshotSection['measured_at'] ?? null,
            'link' => '/admin/speicher-ha/dateiversionen',
            'facts' => array_values(array_filter([
                ['label' => 'Aufnahmen', 'value' => (string) ($snapshotData['snapshots_total'] ?? 0)],
                (int) ($snapshotData['failed'] ?? 0) > 0
                    ? ['label' => 'Fehlgeschlagen', 'value' => (string) $snapshotData['failed']] : null,
                (int) ($snapshotData['pending'] ?? 0) > 0
                    ? ['label' => 'Offen', 'value' => (string) $snapshotData['pending']] : null,
                // Ein vorhandener Snapshot ist kein gepruefter Wiederherstellungspunkt.
                ['label' => 'Wiederherstellung', 'value' => 'nicht geprüft'],
            ])),
        ]);
    }

    // ------------------------------------------------------------------ Sicherung

    /**
     * @param array<string,mixed> $facts
     */
    private function protection(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'office_backup');
        $data = (array) ($section['data'] ?? []);
        $available = (bool) ($section['available'] ?? false);
        $measuredAt = $section['measured_at'] ?? null;

        $graph->node('protect:office-backup', [
            'title' => 'Office-Sicherung',
            'subtitle' => 'Container office-backup – Dateien, Datenbanken und Metadaten',
            'kind' => 'backup',
            'group' => 'group:protect',
            'layer' => 4,
            'optional' => !$available,
            'state' => (string) ($section['state'] ?? 'unknown'),
            'message' => (string) ($section['message'] ?? ''),
            // "Nicht eingerichtet" ist eine belegte Konfiguration, keine fehlende Messung.
            'evidence' => $available || TopologyStatus::fromSource((string) ($section['state'] ?? '')) === TopologyStatus::OFF ? 'proven' : 'unwatched',
            'measured_at' => $measuredAt,
            'containers' => ['office-backup'],
            'facts' => array_values(array_filter([
                (int) ($data['retention'] ?? 0) > 0
                    ? ['label' => 'Aufbewahrung', 'value' => (int) $data['retention'] . ' Generationen'] : null,
                ['label' => 'Verschlüsselung', 'value' => !empty($data['encryption']) ? 'aktiv' : 'nicht bestätigt'],
                (int) ($data['backups'] ?? 0) > 0
                    ? ['label' => 'Vorhandene Sicherungen', 'value' => (string) $data['backups']] : null,
                !empty($data['pending']) ? ['label' => 'Laufend', 'value' => 'Sicherung angefordert'] : null,
                !empty($data['pending_stale']) ? ['label' => 'Laufend', 'value' => 'seit längerem ohne Rückmeldung'] : null,
            ])),
        ]);

        $graph->node('protect:target', [
            'title' => 'Sicherungsziel',
            'subtitle' => 'Verzeichnis auf dem Host',
            'kind' => 'storage',
            'group' => 'group:protect',
            'layer' => 5,
            'optional' => !$available,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Das Sicherungsziel wird nicht überwacht.',
            'evidence' => 'derived',
            'measured_at' => null,
            'facts' => [['label' => 'Ablage', 'value' => 'Verzeichnis außerhalb der Container']],
        ]);

        // Der Rückweg ist eine eigene Betriebsart und kein normaler Datenfluss.
        $graph->node('protect:restore', [
            'title' => 'Wiederherstellung und Rückholung',
            'subtitle' => 'Rückweg aus Sicherung und Cold-Tier',
            'kind' => 'service',
            'group' => 'group:protect',
            'layer' => 5,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Eine Wiederherstellung wurde nicht geprüft; aus einer vorhandenen Sicherung folgt kein geprüfter Wiederherstellungspunkt.',
            'evidence' => 'suspected',
            'measured_at' => null,
            'link' => '/admin/office/sicherung',
            'facts' => [['label' => 'Stand', 'value' => 'nicht geprüft']],
        ]);
    }

    // ------------------------------------------------------------------ Ueberwachung

    /**
     * @param array<string,mixed> $facts
     */
    private function monitoring(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'monitoring');
        $data = (array) ($section['data'] ?? []);
        $configured = (bool) ($data['snmp_configured'] ?? false);
        $measuredAt = $section['measured_at'] ?? null;

        $containers = self::sec($facts, 'containers');
        $cards = (array) ($containers['data']['cards'] ?? []);
        $observed = [];
        foreach ($cards as $service => $card) {
            if (!empty($card['available'])) {
                $observed[] = (string) $service;
            }
        }

        $graph->node('monitor:container-metrics', [
            'title' => 'Container-Kennzahlen',
            'subtitle' => 'Container monitor – CPU und Arbeitsspeicher',
            'kind' => 'monitor',
            'group' => 'group:monitor',
            'layer' => 3,
            'state' => (string) ($containers['state'] ?? 'unknown'),
            'message' => (string) ($containers['message'] ?? ''),
            'evidence' => (bool) ($containers['available'] ?? false) ? 'proven' : 'unwatched',
            'measured_at' => $containers['measured_at'] ?? null,
            'link' => '/admin',
            'containers' => ['monitor'],
            'facts' => array_values(array_filter([
                $observed !== [] ? ['label' => 'Beobachtet', 'value' => implode(', ', $observed)] : null,
            ])),
        ]);

        $graph->node('monitor:snmp', [
            'title' => 'SNMP-Auskunft',
            'subtitle' => 'Container snmp – Auskunft über den Betriebszustand',
            'kind' => 'monitor',
            'group' => 'group:monitor',
            'layer' => 3,
            'optional' => !$configured,
            'state' => $configured ? TopologyStatus::UNKNOWN : TopologyStatus::OFF,
            'message' => $configured
                ? 'Die Auskunft ist eingerichtet; ob sie abgefragt wird, ist hier nicht sichtbar.'
                : 'Die Auskunft ist nicht konfiguriert.',
            'evidence' => $configured ? 'derived' : 'proven',
            'measured_at' => $measuredAt,
            'link' => '/admin/snmp',
            'containers' => ['snmp'],
            'facts' => array_values(array_filter([
                (string) ($data['sys_location'] ?? '') !== ''
                    ? ['label' => 'Standort', 'value' => (string) $data['sys_location']] : null,
            ])),
        ]);

        $alarm = self::sec($facts, 'alarm');
        $alarmConfigured = (bool) ($alarm['data']['configured'] ?? false);
        $graph->node('monitor:alarm', [
            'title' => 'Alarmierung',
            'subtitle' => 'Versand von Alarmen bei Vorfällen',
            'kind' => 'service',
            'group' => 'group:monitor',
            'layer' => 4,
            'optional' => !$alarmConfigured,
            'state' => (string) ($alarm['state'] ?? 'unknown'),
            'message' => (string) ($alarm['message'] ?? ''),
            'evidence' => 'derived',
            'measured_at' => $alarm['measured_at'] ?? null,
            'link' => '/admin/alarmierung',
        ]);

        $emergency = self::sec($facts, 'emergency');
        $emergencyEnabled = (bool) ($emergency['data']['enabled'] ?? false);
        $graph->node('monitor:emergency', [
            'title' => 'Notfallplan und KAEP',
            'subtitle' => 'Handlungsanweisungen für den Störungsfall',
            'kind' => 'module',
            'group' => 'group:monitor',
            'layer' => 4,
            'optional' => !$emergencyEnabled,
            'state' => (string) ($emergency['state'] ?? 'unknown'),
            'message' => (string) ($emergency['message'] ?? ''),
            'evidence' => 'derived',
            'measured_at' => $emergency['measured_at'] ?? null,
            'link' => '/admin/notfallplan',
        ]);
    }

    // ------------------------------------------------------------------ Optionales

    /**
     * @param array<string,mixed> $facts
     */
    private function optional(TopologyGraph $graph, array $facts): void
    {
        $section = self::sec($facts, 'optional');

        $graph->node('optional:phpmyadmin', [
            'title' => 'phpMyAdmin',
            'subtitle' => 'Optionales Verwaltungswerkzeug (Compose-Profil tools)',
            'kind' => 'web',
            'group' => 'group:optional',
            'layer' => 4,
            'optional' => true,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Der Dienst ist optional; sein Zustand wird von der Anwendung nicht erfasst.',
            'evidence' => 'unwatched',
            'measured_at' => null,
            'facts' => [['label' => 'Start', 'value' => 'docker compose --profile tools up -d']],
        ]);

        $graph->node('optional:llmint', [
            'title' => 'LLMInt',
            'subtitle' => 'Optionale KI-Oberfläche hinter dem Auth-Proxy',
            'kind' => 'service',
            'group' => 'group:optional',
            'layer' => 4,
            'optional' => true,
            'state' => TopologyStatus::UNKNOWN,
            'message' => 'Der Stack ist optional; sein Zustand wird von der Anwendung nicht erfasst.',
            'evidence' => 'unwatched',
            'measured_at' => null,
            'facts' => [['label' => 'Zugang', 'value' => 'über den Auth-Proxy unter /ki/']],
        ]);
    }

    // ------------------------------------------------------------------ Netzwerke

    private function networks(TopologyGraph $graph): void
    {
        foreach (self::NETWORKS as $id => $title) {
            $graph->node('network:' . $id, [
                'title' => $title,
                'subtitle' => 'Compose-Netzwerk',
                'kind' => 'network',
                'group' => 'group:network',
                'layer' => 7,
                'state' => TopologyStatus::UNKNOWN,
                'message' => 'Netzwerke werden nicht überwacht; die Zugehörigkeit ist aus der Compose-Datei abgeleitet.',
                'evidence' => 'derived',
                'measured_at' => null,
                'facts' => [['label' => 'Netz', 'value' => $id]],
            ]);
        }
    }

    // ------------------------------------------------------------------ Kanten

    /**
     * Grundgeruest aus Beziehungen, die aus dem Aufbau der Anwendung folgen.
     * Der Zustand einer Kante wird nie aus dem Zustand ihrer Endknoten
     * abgeleitet; ohne eigene Messung bleibt sie "unbekannt".
     */
    private function baseEdges(TopologyGraph $graph): void
    {
        $proven = ['evidence' => 'proven'];
        $derived = ['evidence' => 'derived'];
        $suspected = ['evidence' => 'suspected'];

        // Zugriff
        $graph->edge('e:clients-proxy', 'users:clients', 'access:proxy', $derived + ['type' => 'routes', 'protocol' => 'HTTPS']);
        $graph->edge('e:proxy-app', 'access:proxy', self::ROOT, $proven + ['type' => 'routes', 'protocol' => 'HTTP']);
        $graph->edge('e:tls-proxy', 'access:tls', 'access:proxy', $derived + ['type' => 'depends', 'protocol' => 'TLS']);
        $graph->edge('e:proxy-office', 'access:proxy', 'office:nextcloud', $derived + ['type' => 'routes', 'protocol' => 'HTTPS']);
        $graph->edge('e:proxy-llmint', 'access:proxy', 'optional:llmint', $derived + ['type' => 'routes', 'protocol' => 'HTTPS']);
        $graph->edge('e:app-appvolume', self::ROOT, 'office:app-storage', $proven + ['type' => 'stores', 'protocol' => 'Dateisystem']);

        // Anmeldung
        $graph->edge('e:proxy-directory', 'access:proxy', 'identity:directory', $derived + ['type' => 'authenticates', 'protocol' => 'LDAP/Kerberos']);
        $graph->edge('e:app-directory', self::ROOT, 'identity:directory', $derived + ['type' => 'authenticates', 'protocol' => 'LDAP']);
        $graph->edge('e:adsync-directory', 'identity:ad-sync', 'identity:directory', $derived + ['type' => 'depends', 'protocol' => 'LDAP']);
        $graph->edge('e:adsync-db', 'identity:ad-sync', 'data:mysql', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        if ($graph->hasNode('identity:sso')) {
            $graph->edge('e:proxy-sso', 'access:proxy', 'identity:sso', $derived + ['type' => 'authenticates']);
            $graph->edge('e:sso-volume', 'identity:sso', 'identity:sso-volume', $derived + ['type' => 'stores', 'protocol' => 'Dateisystem']);
        }
        $graph->edge('e:proxy-ssovolume', 'access:proxy', 'identity:sso-volume', $derived + ['type' => 'stores', 'protocol' => 'Dateisystem']);

        // Datenhaltung
        $graph->edge('e:app-db', self::ROOT, 'data:mysql', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:db-volume', 'data:mysql', 'data:mysql-volume', $proven + ['type' => 'stores', 'protocol' => 'Dateisystem']);

        // Mailversand
        $graph->edge('e:app-mail', self::ROOT, 'mail:dispatch', $derived + ['type' => 'depends', 'protocol' => 'SMTP']);
        $graph->edge('e:mail-queue', 'mail:dispatch', 'mail:queue', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:queue-db', 'mail:queue', 'data:mysql', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:worker-queue', 'mail:worker', 'mail:queue', $proven + ['type' => 'depends', 'protocol' => 'SQL']);
        $graph->edge('e:archive-db', 'mail:archive', 'data:mysql', $derived + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:alarm-mail', 'monitor:alarm', 'mail:dispatch', $derived + ['type' => 'depends', 'protocol' => 'SMTP']);

        // Orvanta
        $graph->edge('e:app-orvanta', self::ROOT, 'orvanta:core', $proven + ['type' => 'depends', 'protocol' => 'HTTP']);
        $graph->edge('e:orvanta-db', 'orvanta:core', 'data:mysql', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:orvanta-pool', 'orvanta:core', 'orvanta:exchange-pool', $proven + ['type' => 'depends', 'protocol' => 'EWS']);
        $graph->edge('e:orvanta-proxy', 'orvanta:core', 'orvanta:proxy', $proven + ['type' => 'depends', 'protocol' => 'IMAP/SMTP']);
        $graph->edge('e:orvanta-cache', 'orvanta:core', 'orvanta:attachment-cache', $derived + ['type' => 'stores']);
        $graph->edge('e:orvanta-presence', 'orvanta:core', 'orvanta:presence', $derived + ['type' => 'depends']);
        $graph->edge('e:orvanta-ai', 'orvanta:core', 'orvanta:ai', $derived + ['type' => 'depends', 'protocol' => 'HTTPS']);
        $graph->edge('e:cache-nextcloud', 'orvanta:attachment-cache', 'office:nextcloud', $derived + ['type' => 'stores', 'protocol' => 'HTTP']);
        $graph->edge('e:cache-storage', 'orvanta:attachment-cache', 'storage:service', $derived + ['type' => 'depends']);
        $graph->edge('e:presence-db', 'orvanta:presence', 'data:mysql', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:orvanta-archive', 'orvanta:core', 'orvanta:archive-store', $derived + ['type' => 'stores']);
        $graph->edge('e:archive-store-mail', 'orvanta:archive-store', 'mail:archive', $proven + ['type' => 'depends']);
        $graph->edge('e:mail-archive-store', 'mail:archive', 'orvanta:archive-store', $proven + ['type' => 'stores']);
        $graph->edge('e:orvanta-storage', 'orvanta:core', 'storage:service', $derived + ['type' => 'depends']);

        // Office
        $graph->edge('e:app-office', self::ROOT, 'office:connector', $proven + ['type' => 'depends', 'protocol' => 'HTTP']);
        $graph->edge('e:office-nextcloud', 'office:connector', 'office:nextcloud', $proven + ['type' => 'depends', 'protocol' => 'HTTP']);
        $graph->edge('e:office-eurooffice', 'office:connector', 'office:eurooffice', $proven + ['type' => 'depends', 'protocol' => 'HTTP']);
        $graph->edge('e:office-auth', 'office:connector', 'access:proxy', $derived + ['type' => 'authenticates']);
        $graph->edge('e:nextcloud-db', 'office:nextcloud', 'office:nextcloud-db', $proven + ['type' => 'stores', 'protocol' => 'PostgreSQL']);
        $graph->edge('e:nextcloud-redis', 'office:nextcloud', 'office:nextcloud-redis', $derived + ['type' => 'depends', 'protocol' => 'Redis']);
        $graph->edge('e:cron-nextcloud', 'office:nextcloud-cron', 'office:nextcloud', $proven + ['type' => 'depends', 'protocol' => 'HTTP']);
        $graph->edge('e:aiworker-nextcloud', 'office:ai-worker', 'office:nextcloud', $proven + ['type' => 'depends', 'protocol' => 'HTTP']);
        $graph->edge('e:aiworker-ai', 'office:ai-worker', 'orvanta:ai', $derived + ['type' => 'depends', 'protocol' => 'HTTPS']);
        $graph->edge('e:nextcloud-files', 'office:nextcloud', 'office:files', $proven + ['type' => 'stores', 'protocol' => 'Dateisystem']);
        $graph->edge('e:nextcloud-storage', 'office:nextcloud', 'storage:service', $derived + ['type' => 'depends']);
        $graph->edge('e:eurooffice-data', 'office:eurooffice', 'office:eurooffice-data', $proven + ['type' => 'stores', 'protocol' => 'Dateisystem']);
        $graph->edge('e:eurooffice-ai', 'office:eurooffice', 'office:app-volume', $derived + ['type' => 'depends', 'protocol' => 'Dateisystem']);

        // Speicher
        $graph->edge('e:app-storage', self::ROOT, 'storage:service', $proven + ['type' => 'depends']);
        $graph->edge('e:storage-catalog', 'storage:service', 'storage:catalog', $proven + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:storage-redis', 'storage:service', 'storage:redis', $proven + ['type' => 'depends', 'protocol' => 'Redis']);
        $graph->edge('e:storage-hot', 'storage:service', 'storage:hot', $proven + ['type' => 'stores']);
        $graph->edge('e:storage-state', 'storage:service', 'storage:state-volume', $proven + ['type' => 'stores', 'protocol' => 'Dateisystem']);
        $graph->edge('e:storage-tiering', 'storage:service', 'storage:tiering-volume', $proven + ['type' => 'stores', 'protocol' => 'Dateisystem']);
        $graph->edge('e:hot-tiering', 'storage:hot', 'storage:tiering-volume', $derived + ['type' => 'stores', 'protocol' => 'Dateisystem']);
        $graph->edge('e:storage-snapshot', 'storage:hot', 'storage:snapshots', $derived + ['type' => 'backs_up']);
        $graph->edge('e:snapshot-restore', 'storage:snapshots', 'storage:hot', $derived + ['type' => 'restores']);

        // Sicherung
        $graph->edge('e:backup-target', 'protect:office-backup', 'protect:target', $proven + ['type' => 'backs_up', 'protocol' => 'Dateisystem']);
        $graph->edge('e:backup-db', 'protect:office-backup', 'office:nextcloud-db', $proven + ['type' => 'backs_up', 'protocol' => 'PostgreSQL']);
        $graph->edge('e:backup-files', 'protect:office-backup', 'office:files', $proven + ['type' => 'backs_up', 'protocol' => 'Dateisystem']);
        $graph->edge('e:backup-eurooffice', 'protect:office-backup', 'office:eurooffice-data', $proven + ['type' => 'backs_up', 'protocol' => 'Dateisystem']);
        $graph->edge('e:backup-tiering', 'protect:office-backup', 'storage:tiering-volume', $proven + ['type' => 'backs_up', 'protocol' => 'Dateisystem']);
        $graph->edge('e:backup-ai', 'protect:office-backup', 'office:app-volume', $proven + ['type' => 'backs_up', 'protocol' => 'Dateisystem']);
        $graph->edge('e:backup-appstorage', 'protect:office-backup', 'office:app-storage', $proven + ['type' => 'backs_up', 'protocol' => 'Dateisystem']);
        $graph->edge('e:restore-target', 'protect:target', 'protect:restore', $suspected + ['type' => 'restores']);
        $graph->edge('e:restore-db', 'protect:restore', 'office:nextcloud-db', $suspected + ['type' => 'restores', 'protocol' => 'PostgreSQL']);
        $graph->edge('e:restore-files', 'protect:restore', 'office:files', $suspected + ['type' => 'restores', 'protocol' => 'Dateisystem']);

        // Ueberwachung
        $graph->edge('e:monitor-app', 'monitor:container-metrics', self::ROOT, $proven + ['type' => 'monitors', 'protocol' => 'HTTP']);
        $graph->edge('e:monitor-db', 'monitor:container-metrics', 'data:mysql', $derived + ['type' => 'monitors']);
        $graph->edge('e:monitor-proxy', 'monitor:container-metrics', 'orvanta:proxy', $derived + ['type' => 'monitors']);
        $graph->edge('e:monitor-nextcloud', 'monitor:container-metrics', 'office:nextcloud', $derived + ['type' => 'monitors']);
        $graph->edge('e:monitor-eurooffice', 'monitor:container-metrics', 'office:eurooffice', $derived + ['type' => 'monitors']);
        $graph->edge('e:snmp-app', 'monitor:snmp', self::ROOT, $derived + ['type' => 'monitors', 'protocol' => 'SNMP']);
        $graph->edge('e:snmp-db', 'monitor:snmp', 'data:mysql', $derived + ['type' => 'monitors']);
        $graph->edge('e:app-emergency', self::ROOT, 'monitor:emergency', $derived + ['type' => 'depends']);
        $graph->edge('e:emergency-db', 'monitor:emergency', 'data:mysql', $derived + ['type' => 'stores', 'protocol' => 'SQL']);
        $graph->edge('e:app-alarm', self::ROOT, 'monitor:alarm', $derived + ['type' => 'depends']);
        $graph->edge('e:phpmyadmin-db', 'optional:phpmyadmin', 'data:mysql', $derived + ['type' => 'stores', 'protocol' => 'MySQL']);
    }

    // ------------------------------------------------------------------ Nachbearbeitung

    /**
     * Kanten zu dynamischen Knoten (Exchange-Hosts, Proxy-Mailserver,
     * Cold-Tiers) sowie die Netzwerkzugehoerigkeit ergaenzen.
     *
     * @param array<string,mixed> $facts
     */
    private function dynamicEdges(TopologyGraph $graph, array $facts): void
    {
        $orvanta = self::sec($facts, 'orvanta');
        $data = (array) ($orvanta['data'] ?? []);

        foreach ((array) ($data['exchange']['hosts'] ?? []) as $host) {
            $hostId = (int) ($host['id'] ?? 0);
            $graph->edge('e:pool-host-' . $hostId, 'orvanta:exchange-pool', 'orvanta:exchange-host:' . $hostId, [
                'type' => 'depends',
                'evidence' => 'proven',
                'protocol' => 'EWS',
                'state' => TopologyStatus::fromSource((string) ($host['status'] ?? 'unknown')),
                'message' => 'Host des Exchange-Pools.',
            ]);
        }

        foreach ((array) ($data['proxy']['servers'] ?? []) as $server) {
            $serverId = (int) ($server['id'] ?? 0);
            if (!$graph->hasNode('orvanta:proxy-server:' . $serverId)) {
                continue;
            }
            $graph->edge('e:proxy-server-' . $serverId, 'orvanta:proxy', 'orvanta:proxy-server:' . $serverId, [
                'type' => 'depends',
                'evidence' => 'proven',
                'protocol' => 'IMAP/SMTP',
            ]);
        }

        $storage = self::sec($facts, 'storage');
        foreach ((array) ($storage['data']['targets'] ?? []) as $tier) {
            $tierId = (string) ($tier['id'] ?? '');
            if ($tierId === '' || !$graph->hasNode('storage:cold:' . $tierId)) {
                continue;
            }
            $graph->edge('e:hot-cold-' . $tierId, 'storage:hot', 'storage:cold:' . $tierId, [
                'type' => 'replicates',
                'evidence' => 'proven',
                'protocol' => (string) ($tier['kind'] ?? ''),
                'state' => TopologyStatus::fromSource((string) ($tier['state'] ?? 'unknown')),
                'message' => 'Auslagerung in das Cold-Tier.',
            ]);
            $graph->edge('e:cold-hot-' . $tierId, 'storage:cold:' . $tierId, 'storage:hot', [
                'type' => 'restores',
                'evidence' => 'derived',
                'protocol' => (string) ($tier['kind'] ?? ''),
                'message' => 'Rückholung aus dem Cold-Tier in den Hot-Tier.',
            ]);
        }

        foreach ((array) ($data['sources'] ?? []) as $source) {
            $sourceId = (int) ($source['id'] ?? 0);
            $nodeId = 'identity:source:' . $sourceId;
            if (!$graph->hasNode($nodeId)) {
                continue;
            }
            $graph->edge('e:app-source-' . $sourceId, self::ROOT, $nodeId, [
                'type' => 'depends',
                'evidence' => 'proven',
                'protocol' => 'LDAP',
            ]);
            $graph->edge('e:source-directory-' . $sourceId, $nodeId, 'identity:directory', [
                'type' => 'authenticates',
                'evidence' => 'derived',
                'protocol' => 'LDAP',
            ]);
            $target = (string) ($source['transport'] ?? '') === 'exchange'
                ? 'orvanta:exchange-pool'
                : 'orvanta:proxy';
            $graph->edge('e:source-transport-' . $sourceId, $nodeId, $target, [
                'type' => 'routes',
                'evidence' => 'derived',
                'protocol' => (string) ($source['transport'] ?? '') === 'exchange' ? 'EWS' : 'IMAP/SMTP',
                'message' => 'Transportweg der Identitätsquelle.',
            ]);
        }

        foreach (self::CONTAINER_NETWORKS as $container => $networks) {
            $node = $this->containerNode($graph, $container);
            if ($node === null) {
                continue;
            }
            foreach ($networks as $network) {
                $graph->edge('e:net-' . $network . '-' . $container, 'network:' . $network, $node, [
                    'type' => 'contains',
                    'evidence' => 'derived',
                    'protocol' => '',
                ]);
            }
        }
    }

    /** Knoten, der einen Container repraesentiert. */
    private function containerNode(TopologyGraph $graph, string $container): ?string
    {
        foreach ($graph->nodes() as $id => $node) {
            if (in_array($container, (array) ($node['containers'] ?? []), true)) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Messungen, die zu alt sind, werden auf "veraltet" gesetzt.
     */
    private function applyFreshness(TopologyGraph $graph, int $now): void
    {
        foreach ($graph->nodes() as $id => $node) {
            $measuredAt = $node['measured_at'] ?? null;
            if (!is_int($measuredAt)) {
                continue;
            }
            $state = TopologyStatus::fresh((string) $node['state'], $measuredAt, $now, TopologyCollector::STALE_SECONDS);
            if ($state !== $node['state']) {
                $graph->setState((string) $id, $state, 'Die letzte Messung ist älter als ' . TopologyCollector::STALE_SECONDS . ' Sekunden.');
            }
        }
    }

    // ------------------------------------------------------------------ Vertrag

    /**
     * JSON-Datenvertrag der Topologie.
     *
     * @param array<string,mixed> $facts
     *
     * @return array<string,mixed>
     */
    public function contract(TopologyGraph $graph, array $facts): array
    {
        $this->dynamicEdges($graph, $facts);
        $issues = $graph->validate();

        $nodes = [];
        foreach ($graph->nodes() as $id => $node) {
            $state = (string) $node['state'];
            $kind = (string) ($node['kind'] ?? 'module');
            $measuredAt = $node['measured_at'] ?? null;
            // Ohne eigenen Messzeitpunkt existiert keine Messung: der Knoten ist
            // vorhanden, aber nicht ueberwacht. Das ist etwas anderes als ein
            // gemessener, aber unklarer Zustand und darf die Gesamtbewertung
            // nicht dauerhaft auf "unbekannt" ziehen.
            $unwatched = $state === TopologyStatus::UNKNOWN && !is_int($measuredAt);
            $evidence = $unwatched ? 'unwatched' : (string) ($node['evidence'] ?? 'suspected');
            $nodes[$id] = [
                'id' => $id,
                'title' => (string) ($node['title'] ?? $id),
                'subtitle' => (string) ($node['subtitle'] ?? ''),
                'kind' => $kind,
                'kind_label' => self::KIND_LABELS[$kind] ?? $kind,
                'group' => (string) ($node['group'] ?? ''),
                'parent' => $node['parent'] ?? null,
                'layer' => (int) ($node['layer'] ?? 4),
                'optional' => (bool) ($node['optional'] ?? false),
                'state' => $state,
                'state_label' => TopologyStatus::label($state),
                'severity' => TopologyStatus::severity($state),
                'message' => (string) ($node['message'] ?? ''),
                'evidence' => $evidence,
                'evidence_label' => self::EVIDENCE_LABELS[$evidence] ?? '',
                'unwatched' => $unwatched,
                'measured_at' => $measuredAt,
                'facts' => array_values((array) ($node['facts'] ?? [])),
                'containers' => array_values((array) ($node['containers'] ?? [])),
                'link' => self::safeLink($node['link'] ?? null),
                'children' => array_values($graph->childrenOf((string) $id)),
            ];
        }

        $edges = [];
        foreach ($graph->edges() as $id => $edge) {
            $type = (string) ($edge['type'] ?? 'depends');
            $state = (string) ($edge['state'] ?? TopologyStatus::UNKNOWN);
            $edges[] = [
                'id' => $id,
                'from' => (string) $edge['from'],
                'to' => (string) $edge['to'],
                'type' => $type,
                'type_label' => self::EDGE_LABELS[$type] ?? $type,
                'label' => (string) ($edge['label'] ?? ''),
                'state' => $state,
                'state_label' => TopologyStatus::label($state),
                'message' => (string) ($edge['message'] ?? ''),
                'evidence' => (string) ($edge['evidence'] ?? 'suspected'),
                'evidence_label' => self::EVIDENCE_LABELS[(string) ($edge['evidence'] ?? 'suspected')] ?? '',
                'protocol' => (string) ($edge['protocol'] ?? ''),
                'measured_at' => $edge['measured_at'] ?? null,
            ];
        }

        $groups = [];
        foreach ($graph->groups() as $id => $group) {
            $members = [];
            foreach ($nodes as $nodeId => $node) {
                if ($node['group'] === $id) {
                    $members[] = $nodeId;
                }
            }
            $state = TopologyStatus::worst(array_map(
                static fn (string $member): string => (string) $nodes[$member]['state'],
                $members
            ));
            $groups[] = [
                'id' => $id,
                'title' => (string) ($group['title'] ?? $id),
                'subtitle' => (string) ($group['subtitle'] ?? ''),
                'parent' => $group['parent'] ?? null,
                'optional' => (bool) ($group['optional'] ?? false),
                'nodes' => $members,
                'node_count' => count($members),
                'state' => $state,
                'state_label' => TopologyStatus::label($state),
            ];
        }

        $incidents = $this->flagged($nodes, $groups, self::INCIDENT_STATES);
        $gaps = $this->flagged($nodes, $groups, self::GAP_STATES);
        $overall = $this->overall($nodes, $incidents);
        $freshness = $this->freshness($facts);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => date('d.m.Y H:i:s', (int) ($facts['now'] ?? time())),
            'generated_iso' => date('Y-m-d H:i:s', (int) ($facts['now'] ?? time())),
            'refresh_interval' => $this->refreshInterval,
            'overall' => $overall,
            'groups' => $groups,
            'nodes' => $nodes,
            'edges' => $edges,
            'incidents' => $incidents,
            'gaps' => $gaps,
            'freshness' => $freshness,
            'kpis' => $this->kpis($nodes, $edges, $groups, $facts),
            'validation' => $issues,
            'validation_ok' => $issues === [],
        ];
    }

    /**
     * Knoten mit einem der uebergebenen Zustaende, absteigend nach Schwere
     * sortiert. Die Liste ist bewusst eine reine Zusammenfassung bereits
     * erhobener Zustaende und loest keine eigenen Pruefungen aus.
     *
     * @param array<string,array<string,mixed>> $nodes
     * @param list<array<string,mixed>> $groups
     * @param list<string> $states
     *
     * @return list<array<string,mixed>>
     */
    private function flagged(array $nodes, array $groups, array $states): array
    {
        $groupTitles = [];
        foreach ($groups as $group) {
            $groupTitles[(string) $group['id']] = (string) $group['title'];
        }

        $flagged = [];
        foreach ($nodes as $id => $node) {
            $state = (string) $node['state'];
            if (!in_array($state, $states, true)) {
                continue;
            }
            if ($state === TopologyStatus::UNKNOWN && ($node['unwatched'] ?? false)) {
                continue;
            }
            $flagged[] = [
                'id' => (string) $id,
                'node' => (string) $id,
                'title' => (string) $node['title'],
                'group' => (string) $node['group'],
                'group_title' => $groupTitles[(string) $node['group']] ?? '',
                'state' => $state,
                'state_label' => TopologyStatus::label($state),
                'severity' => TopologyStatus::severity($state),
                'message' => (string) $node['message'],
                'optional' => (bool) $node['optional'],
                'measured_at' => $node['measured_at'],
            ];
        }

        usort($flagged, static function (array $a, array $b): int {
            return $b['severity'] <=> $a['severity'] ?: strcmp((string) $a['title'], (string) $b['title']);
        });

        return $flagged;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @param list<array<string,mixed>> $incidents
     *
     * @return array<string,mixed>
     */
    private function overall(array $nodes, array $incidents): array
    {
        $counts = array_fill_keys(TopologyStatus::STATES, 0);
        $unwatched = 0;
        foreach ($nodes as $node) {
            $state = (string) $node['state'];
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            if ($state === TopologyStatus::UNKNOWN && ($node['unwatched'] ?? false)) {
                $unwatched++;
            }
        }

        $errors = $counts[TopologyStatus::ERROR];
        $warnings = $counts[TopologyStatus::WARN];
        $unrated = $counts[TopologyStatus::UNKNOWN] + $counts[TopologyStatus::STALE] - $unwatched;

        $state = match (true) {
            $errors > 0 => TopologyStatus::ERROR,
            $warnings > 0 => TopologyStatus::WARN,
            $unrated > 0 => TopologyStatus::UNKNOWN,
            default => TopologyStatus::OK,
        };

        $errorNames = [];
        foreach ($nodes as $node) {
            if ($node['state'] === TopologyStatus::ERROR) {
                $errorNames[] = (string) $node['title'];
            }
        }

        $message = match ($state) {
            TopologyStatus::ERROR => $errors . ' Störung' . ($errors === 1 ? '' : 'en') . ': ' . implode(', ', $errorNames),
            TopologyStatus::WARN => $warnings . ' Einschränkung' . ($warnings === 1 ? '' : 'en'),
            TopologyStatus::UNKNOWN => $unrated . ' Baustein' . ($unrated === 1 ? '' : 'e') . ' ohne belastbare Messung',
            default => 'Alle erfassten Bausteine sind in Ordnung.',
        };

        return [
            'state' => $state,
            'label' => TopologyStatus::label($state),
            'message' => $message,
            'errors' => $errors,
            'warnings' => $warnings,
            'unrated' => $unrated,
            'unwatched' => $unwatched,
            'counts' => $counts,
            'incidents' => count($incidents),
            'nodes' => count($nodes),
        ];
    }

    /**
     * @param array<string,mixed> $facts
     *
     * @return array<string,mixed>
     */
    private function freshness(array $facts): array
    {
        $now = (int) ($facts['now'] ?? time());
        $sources = [];
        $oldest = null;
        $newest = null;

        foreach ($facts as $key => $section) {
            if ($key === 'now' || !is_array($section)) {
                continue;
            }
            $measuredAt = $section['measured_at'] ?? null;
            if (!is_int($measuredAt)) {
                continue;
            }
            $age = max(0, $now - $measuredAt);
            $sources[(string) $key] = [
                'state' => (string) ($section['state'] ?? 'unknown'),
                'measured_at' => $measuredAt,
                'age_seconds' => $age,
                'stale' => $age > TopologyCollector::STALE_SECONDS,
            ];
            $oldest = $oldest === null ? $age : max($oldest, $age);
            $newest = $newest === null ? $age : min($newest, $age);
        }

        return [
            'generated_ts' => $now,
            'measured_ts' => $newest === null ? null : $now - $newest,
            'age_seconds' => $oldest,
            'stale_after' => TopologyCollector::STALE_SECONDS,
            'fresh' => $oldest !== null && $oldest <= TopologyCollector::STALE_SECONDS,
            'sources' => $sources,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @param list<array<string,mixed>> $edges
     * @param list<array<string,mixed>> $groups
     * @param array<string,mixed> $facts
     *
     * @return list<array<string,mixed>>
     */
    private function kpis(array $nodes, array $edges, array $groups, array $facts): array
    {
        $count = static function (string $kind) use ($nodes): int {
            $total = 0;
            foreach ($nodes as $node) {
                if ($node['kind'] === $kind) {
                    $total++;
                }
            }

            return $total;
        };

        $stateCount = static function (string $state) use ($nodes): int {
            $total = 0;
            foreach ($nodes as $node) {
                if ($node['state'] === $state) {
                    $total++;
                }
            }

            return $total;
        };

        $storage = self::sec($facts, 'storage');
        $snapshots = self::sec($facts, 'snapshots');
        $mail = self::sec($facts, 'mail_dispatch');
        $containers = self::sec($facts, 'containers');
        $cards = (array) ($containers['data']['cards'] ?? []);
        $measured = 0;
        foreach ($cards as $card) {
            if (!empty($card['available']) && empty($card['stale'])) {
                $measured++;
            }
        }

        return [
            ['key' => 'nodes', 'label' => 'Bausteine', 'value' => (string) count($nodes)],
            ['key' => 'groups', 'label' => 'Module', 'value' => (string) count($groups)],
            ['key' => 'edges', 'label' => 'Beziehungen', 'value' => (string) count($edges)],
            ['key' => 'errors', 'label' => 'Störungen', 'value' => (string) $stateCount(TopologyStatus::ERROR), 'state' => TopologyStatus::ERROR],
            ['key' => 'warnings', 'label' => 'Einschränkungen', 'value' => (string) $stateCount(TopologyStatus::WARN), 'state' => TopologyStatus::WARN],
            ['key' => 'unrated', 'label' => 'Ohne Messung', 'value' => (string) ($stateCount(TopologyStatus::UNKNOWN) + $stateCount(TopologyStatus::STALE)), 'state' => TopologyStatus::UNKNOWN],
            ['key' => 'containers', 'label' => 'Container mit Kennzahlen', 'value' => $measured . ' von ' . count($cards)],
            ['key' => 'exchange', 'label' => 'Exchange-Hosts', 'value' => (string) $count('external')],
            ['key' => 'identity', 'label' => 'Identitätsquellen', 'value' => (string) $count('identity')],
            ['key' => 'tiers', 'label' => 'Cold-Tier-Ziele', 'value' => (string) (count((array) ($storage['data']['targets'] ?? [])))],
            ['key' => 'snapshots', 'label' => 'Snapshots', 'value' => (string) ($snapshots['data']['snapshots_total'] ?? 0)],
            ['key' => 'mail', 'label' => 'Mails wartend', 'value' => (string) ($mail['data']['queued'] ?? 0)],
        ];
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * @param array<string,mixed> $facts
     *
     * @return array<string,mixed>
     */
    private static function sec(array $facts, string $key): array
    {
        return (array) ($facts[$key] ?? []);
    }

    /**
     * Zustand des Füllstands aus der Speicherüberwachung
     * ({@see \App\Services\Storage\StorageHealth::fill()}).
     *
     * @param array<string,mixed> $source
     */
    private static function fillState(array $source): string
    {
        $fill = $source['fill'] ?? null;
        if (is_array($fill)) {
            return (string) ($fill['state'] ?? 'unknown');
        }

        return is_string($fill) ? $fill : 'unknown';
    }

    /**
     * Füllstand als lesbarer Text („42,5 % belegt“); leer, wenn kein Wert
     * vorliegt. Die Überwachung liefert ein Feld aus Prozentwert und Zustand.
     *
     * @param array<string,mixed> $source
     */
    private static function fillLabel(array $source): string
    {
        $fill = $source['fill'] ?? null;
        $percent = is_array($fill) ? ($fill['percent'] ?? null) : (is_numeric($fill) ? $fill : null);
        if ($percent === null) {
            return '';
        }

        return number_format((float) $percent, 1, ',', '.') . ' % belegt';
    }

    /**
     * Eingeschraenkte oder gestoerte Bausteine der Nachrichtenfluss-Auswertung
     * als lesbare Ursachen ("Titel: Meldung").
     *
     * @param array<string,mixed> $nodes
     * @return list<string>
     */
    private static function flowLimitations(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $state = TopologyStatus::fromSource((string) ($node['state'] ?? ''));
            if ($state !== TopologyStatus::WARN && $state !== TopologyStatus::ERROR) {
                continue;
            }
            $title = trim((string) ($node['title'] ?? ''));
            $message = trim((string) ($node['message'] ?? ''));
            $out[] = $title !== '' && $message !== '' ? $title . ': ' . $message : ($title !== '' ? $title : $message);
        }

        return array_values(array_filter($out, static fn (string $line): bool => $line !== ''));
    }

    /**
     * Nur sichere interne Verwaltungslinks weitergeben.
     */
    private static function safeLink(mixed $link): ?string
    {
        if (!is_string($link) || $link === '') {
            return null;
        }
        if (!preg_match('#^/[a-z0-9/_-]*$#i', $link)) {
            return null;
        }

        return $link;
    }
}
