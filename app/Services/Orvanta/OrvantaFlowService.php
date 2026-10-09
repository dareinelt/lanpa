<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\MailProxyRepository;
use App\Repositories\OrvantaRepository;
use App\Services\MailProxy\MailProxyService;
use App\Services\Office\OfficeAiService;
use App\Services\Storage\StorageHealth;
use App\Services\Storage\StorageService;

/**
 * Aggregation des Nachrichtenfluss-Dashboards.
 *
 * Der Dienst sammelt die Werte der beteiligten Bausteine (Proxy, Identitaets-
 * quellen, Exchange-Hosts, Nutzerpraesenz, Speicher, KI) und formt daraus
 * Knoten, Kanten, Kennzahlen und den Gesamtstatus. Die eigentliche Logik steckt
 * in evaluate(): Sie arbeitet ausschliesslich auf einem einfachen Datenfeld,
 * damit sie ohne Datenbank und ohne fremde Dienste geprueft werden kann.
 * collect() ist nur die Verdrahtung zu den vorhandenen Diensten.
 *
 * Zustandssemantik (siehe docs/orvanta-nachrichtenfluss.md):
 * ok = in Ordnung, warn = eingeschraenkt, error = ausgefallen (rotes
 * Ausrufezeichen), off = nicht konfiguriert oder deaktiviert. Faellt der Proxy
 * aus, werden alle Identitaetsquellen gedaempft; faellt eine Quelle aus, deren
 * Postfaecher; faellt ein Exchange-Host aus, dessen Clients.
 */
final class OrvantaFlowService
{
    /** Zwischenspeicher: gelb ab diesem Prozentwert. */
    public const CACHE_WARN_PERCENT = 75;

    /** Zwischenspeicher: rot ab diesem Prozentwert. */
    public const CACHE_CRIT_PERCENT = 90;

    /** Zeitraum der KI-Auswertung (Tage). */
    public const AI_PERIOD_DAYS = 30;

    /** Anzahl der genannten KI-Nutzer (Top-N). */
    public const AI_TOP_USERS = 10;

    /** Bezeichnung der Zustaende fuer die Anzeige. */
    public const STATE_LABELS = [
        'ok' => 'In Ordnung',
        'warn' => 'Eingeschränkt',
        'error' => 'Störung',
        'off' => 'Nicht aktiv',
    ];

    /** Anzahl der in der Zwischenspeicher-Wolke genannten Nutzer. */
    public const CACHE_TOP_USERS = 10;

    /** Transportweg einer Identitaetsquelle: ueber den IMAP-/SMTP-Proxy. */
    public const TRANSPORT_PROXY = 'proxy';

    /** Transportweg einer Identitaetsquelle: direkt ueber Exchange (EWS). */
    public const TRANSPORT_EXCHANGE = 'exchange';

    public function __construct(
        private readonly OrvantaPresenceService $presence,
        private readonly MailProxyService $mailProxy,
        private readonly MailProxyRepository $mailProxyRepository,
        private readonly OrvantaExchangePool $exchange,
        private readonly StorageService $storage,
        private readonly OfficeAiService $ai,
        private readonly OrvantaRepository $orvanta,
        private readonly OrvantaConfigService $config,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * Kennzahlen, Knoten, Kanten und Gesamtstatus des Dashboards.
     *
     * @return array<string,mixed>
     */
    public function overview(bool $withHistory = true): array
    {
        $flow = self::evaluate($this->collect());
        if (!$withHistory) {
            unset($flow['history']);
        }

        return $flow;
    }

    // ------------------------------------------------------------------ Sammeln

    /**
     * Werte der beteiligten Dienste einsammeln. Fehlende Tabellen (Migration
     * nicht ausgefuehrt) duerfen das Dashboard nicht verhindern: der jeweilige
     * Bereich bleibt dann leer und meldet das im Knoten.
     *
     * @return array<string,mixed>
     */
    public function collect(): array
    {
        $now = $this->now();
        $exchange = $this->collectExchange();

        return [
            'now' => $now,
            'proxy' => $this->collectProxy(),
            'sources' => $this->collectSources((array) $exchange['hosts']),
            'exchange' => $exchange,
            'presence' => $this->presence->stats(),
            'history' => $this->presence->history(),
            'storage' => $this->collectStorage(),
            'cache' => $this->collectCache(),
            'ai' => $this->collectAi($now),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectProxy(): array
    {
        $proxy = [
            'available' => false,
            'configured' => false,
            'ok' => false,
            'message' => 'Nicht geprüft (keine Proxy-Konfiguration).',
            'details' => [],
            'counts' => [],
            'state' => [],
            'cache_ttl' => 0,
            'servers' => [],
        ];
        try {
            $diagnostics = $this->mailProxy->diagnostics(true);
            $proxy['available'] = (bool) ($diagnostics['available'] ?? false);
            $proxy['counts'] = (array) ($diagnostics['counts'] ?? []);
            $proxy['state'] = (array) ($diagnostics['state'] ?? []);
            $proxy['cache_ttl'] = (int) ($diagnostics['cache_ttl'] ?? 0);
            $proxy['configured'] = ((int) ($proxy['counts']['servers'] ?? 0)) > 0;
            $proxy['ok'] = (bool) ($diagnostics['service']['ok'] ?? false);
            $proxy['message'] = (string) ($diagnostics['service']['message'] ?? '');
            $proxy['details'] = (array) ($diagnostics['service']['details'] ?? []);
            $proxy['servers'] = $this->mailProxy->overview(null)['servers'];
        } catch (\PDOException) {
            $proxy['message'] = 'Tabellen fehlen (Migration 039 ausführen).';
        }

        return $proxy;
    }

    /**
     * Identitaetsquellen mit Postfachzahlen und gespeichertem Zustand.
     * `transport` nennt den Weg der Quelle (siehe transportFor()): 'exchange'
     * nur, wenn ihre Verzeichnisserver zur Domaene der Exchange-Hosts
     * gehoeren, sonst 'proxy'. `proxied` sagt, ob im Proxy ein Mailserver
     * fuer die Quelle hinterlegt ist.
     *
     * @param list<array<string,mixed>> $exchangeHosts Hosts aus collectExchange()
     *
     * @return list<array<string,mixed>>
     */
    private function collectSources(array $exchangeHosts = []): array
    {
        try {
            $counts = $this->mailProxyRepository->sourceCounts();
            $states = $this->mailProxyRepository->sourceStates();
            $proxied = [];
            foreach ($this->mailProxyRepository->servers() as $server) {
                $proxied[(int) $server['identity_source_id']] = true;
            }
        } catch (\PDOException) {
            return [];
        }

        $exchangeDomains = self::hostDomains(array_map(static fn (array $host): string => (string) ($host['host'] ?? ''), $exchangeHosts));

        $sources = [];
        foreach ($this->mailProxy->sources() as $source) {
            $id = (int) $source['id'];
            $hosts = array_values(array_map('strval', (array) ($source['hosts'] ?? [])));
            $sources[] = [
                'id' => $id,
                'label' => (string) $source['label'],
                'domain' => (string) $source['domain'],
                'hosts' => $hosts,
                'active' => (bool) $source['active'],
                'primary' => $id === 0,
                'proxied' => isset($proxied[$id]),
                'transport' => self::transportFor($hosts, $exchangeDomains, isset($proxied[$id])),
                'counts' => $counts[$id] ?? [
                    'mailboxes' => 0,
                    'active_mailboxes' => 0,
                    'mapped_mailboxes' => 0,
                    'active_mapped_mailboxes' => 0,
                    'free_mailboxes' => 0,
                    'mappings' => 0,
                ],
                'state' => $states[$id] ?? [
                    'last_success_at' => '',
                    'last_error_at' => '',
                    'last_error' => '',
                    'failures' => 0,
                    'checked_at' => '',
                ],
            ];
        }

        return $sources;
    }

    /**
     * Transportweg einer Identitaetsquelle anhand ihrer Verzeichnisserver.
     *
     * Zusammengehoerigkeit wird ueber den FQDN festgemacht: Liegt mindestens
     * ein Server der Quelle in derselben Domaene wie ein Exchange-Host
     * (dc01.khwf.de zu exchange01.khwf.de), gehoert die Quelle zum
     * Exchange-Ast. IP-Adressen, kurze Hostnamen und fremde Domaenen
     * (dc01.mvzintsz.local) liegen hinter dem IMAP-/SMTP-Proxy, auch wenn dort
     * noch kein Mailserver eingetragen ist. Ein eingetragener Mailserver
     * (`mail_proxy_servers`) legt den Proxy-Weg immer fest.
     *
     * @param list<string> $hosts           Server der Quelle (FQDN oder IP)
     * @param list<string> $exchangeDomains Domaenen der Exchange-Hosts (siehe hostDomains())
     * @param bool         $proxied         Mailserver fuer die Quelle im Proxy hinterlegt
     */
    public static function transportFor(array $hosts, array $exchangeDomains, bool $proxied): string
    {
        if ($proxied || $exchangeDomains === []) {
            return self::TRANSPORT_PROXY;
        }

        return array_intersect(self::hostDomains($hosts), $exchangeDomains) !== []
            ? self::TRANSPORT_EXCHANGE
            : self::TRANSPORT_PROXY;
    }

    /**
     * Domaenen (alles nach dem ersten Label, kleingeschrieben) der FQDNs einer
     * Hostliste; IP-Adressen und Namen ohne Punkt liefern keine Domaene.
     *
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    public static function hostDomains(array $hosts): array
    {
        $domains = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host, " \t\n\r\0\x0B.[]"));
            if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            $dot = strpos($host, '.');
            if ($dot === false || $dot === strlen($host) - 1) {
                continue;
            }
            $domains[substr($host, $dot + 1)] = true;
        }

        return array_keys($domains);
    }

    /**
     * @return array<string,mixed>
     */
    private function collectExchange(): array
    {
        $exchange = ['available' => false, 'configured' => false, 'hosts' => [], 'sessions' => [], 'totals' => []];
        try {
            $overview = $this->exchange->overview();
            $exchange['available'] = true;
            $exchange['hosts'] = $overview['hosts'];
            $exchange['sessions'] = $overview['sessions'];
            $exchange['totals'] = $overview['totals'];
            $exchange['configured'] = $overview['hosts'] !== [];
        } catch (\PDOException) {
            // Tabellen fehlen: Exchange gilt als nicht eingerichtet.
        }

        return $exchange;
    }

    /**
     * @return array<string,mixed>
     */
    private function collectStorage(): array
    {
        $storage = [
            'available' => false,
            'enabled' => false,
            'targets' => [],
            'local' => [],
            'snapshot' => [],
            'mode' => '',
            'forecast_text' => '',
            'health' => [],
        ];
        try {
            $overview = $this->storage->overview();
            $settings = $overview['settings'];
            $storage['available'] = true;
            $storage['enabled'] = is_object($settings) && method_exists($settings, 'enabled')
                ? (bool) $settings->enabled()
                : (bool) ($overview['office_enabled'] ?? false);
            $storage['targets'] = $overview['targets'];
            $storage['local'] = $overview['local'];
            $storage['snapshot'] = (array) ($overview['snapshot'] ?? []);
            $storage['mode'] = (string) $overview['mode'];
            $storage['forecast_text'] = (string) $overview['forecast_text'];
            $storage['health'] = $overview['health'];
        } catch (\Throwable) {
            // Speicher-Tiering ist optional: ohne Konfiguration bleibt es leer.
        }

        return $storage;
    }

    /**
     * @return array<string,mixed>
     */
    private function collectCache(): array
    {
        $totals = ['items' => 0, 'bytes' => 0, 'users' => 0];
        $top = [];
        try {
            $totals = $this->orvanta->cacheTotals();
            $top = $this->orvanta->cacheUsagePerUser(self::CACHE_TOP_USERS);
        } catch (\PDOException) {
            // Zwischenspeicher ist optional.
        }
        $quota = $this->config->cacheQuotaBytes();
        $used = (int) $totals['bytes'];

        return [
            'used' => $used,
            'quota' => $quota,
            'items' => (int) $totals['items'],
            'users' => (int) $totals['users'],
            'percent' => $quota > 0 ? (int) min(100, (int) round($used * 100 / $quota)) : 0,
            'top' => $top,
        ];
    }

    /**
     * KI-Nutzung der letzten AI_PERIOD_DAYS Tage. Die Wolke zeigt die
     * AI_TOP_USERS-Nutzer mit den meisten Anfragen; Namen nur, wenn die
     * Einstellung flow_ai_user_names gesetzt ist, sonst Pseudonyme nach Rang.
     *
     * @param int $now
     *
     * @return array<string,mixed>
     */
    private function collectAi(int $now): array
    {
        $to = date('Y-m-d 23:59:59', $now);
        $from = date('Y-m-d 00:00:00', $now - (self::AI_PERIOD_DAYS - 1) * 86400);

        $top = [];
        $totals = ['requests' => 0, 'users' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
        $activeUsers = 0;
        $dayUsers = 0;
        try {
            $rows = $this->orvanta->aiUsageTopUsers($from, $to, self::AI_TOP_USERS);
            $totals = $this->orvanta->aiTokenTotals($from, $to);
            $stamp = static fn (int $time): string => date('Y-m-d H:i:s', $time);
            // Sitzungen: Nutzer mit KI-Anfragen im Aktivitaetsfenster bzw. in 24 Stunden.
            $activeUsers = (int) $this->orvanta->aiTokenTotals($stamp($now - OrvantaPresenceService::ACTIVE_WINDOW), $stamp($now + 1))['users'];
            $dayUsers = (int) $this->orvanta->aiTokenTotals($stamp($now - 86400), $stamp($now + 1))['users'];
        } catch (\PDOException) {
            $rows = [];
        }

        $names = $this->config->get('flow_ai_user_names') === '1';
        foreach ($rows as $index => $row) {
            $top[] = [
                'label' => $names ? $row['user_uid'] : 'Benutzer ' . ($index + 1),
                'value' => (int) $row['requests'],
                'title' => $names
                    ? $row['user_uid'] . ': ' . (int) $row['requests'] . ' Anfragen'
                    : 'Benutzer ' . ($index + 1) . ': ' . (int) $row['requests'] . ' Anfragen',
            ];
        }

        return [
            'enabled' => $this->ai->enabled(),
            'configured' => $this->ai->isConfigured(),
            'active' => $this->ai->isActive(),
            'has_key' => $this->ai->hasApiKey(),
            'name' => $this->ai->name(),
            'url' => $this->ai->url(),
            'model' => $this->ai->model(),
            'audio' => $this->ai->audioEnabled(),
            'images' => $this->ai->imagesEnabled(),
            'names' => $names,
            'period_days' => self::AI_PERIOD_DAYS,
            'from' => substr($from, 0, 10),
            'to' => substr($to, 0, 10),
            'top' => $top,
            'remaining' => max(0, (int) $totals['users'] - count($top)),
            'requests' => (int) $totals['requests'],
            'users' => (int) $totals['users'],
            'active_users' => $activeUsers,
            'day_users' => $dayUsers,
            'window' => OrvantaPresenceService::ACTIVE_WINDOW,
            'input_tokens' => (int) $totals['input_tokens'],
            'output_tokens' => (int) $totals['output_tokens'],
        ];
    }

    // ------------------------------------------------------------------ Auswerten

    /**
     * Knoten, Kanten, Kennzahlen und Gesamtstatus aus den gesammelten Werten.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public static function evaluate(array $input): array
    {
        $now = (int) ($input['now'] ?? time());
        $proxy = (array) ($input['proxy'] ?? []);
        $sources = (array) ($input['sources'] ?? []);
        $exchange = (array) ($input['exchange'] ?? []);
        $presence = (array) ($input['presence'] ?? []);
        $storage = (array) ($input['storage'] ?? []);
        $cache = (array) ($input['cache'] ?? []);
        $ai = (array) ($input['ai'] ?? []);

        $proxyNode = self::proxyNode($proxy);
        $proxyDown = $proxyNode['state'] === 'error';

        $nodes = ['proxy' => $proxyNode];
        $lanes = [
            'proxy' => ['key' => 'proxy', 'title' => 'Proxy-Pfad (IMAP/SMTP)', 'nodes' => ['proxy']],
            'exchange' => ['key' => 'exchange', 'title' => 'Exchange-Pfad (EWS)', 'nodes' => []],
        ];
        $edges = [];

        // Quellen ohne Proxy-Konfiguration haengen nicht hinter dem Proxy,
        // sondern vor den Exchange-Hosts; sie folgen weiter unten.
        $presenceBySource = (array) ($presence['sources'] ?? []);
        $exchangeSources = [];
        foreach ($sources as $source) {
            if ((string) ($source['transport'] ?? self::TRANSPORT_PROXY) === self::TRANSPORT_EXCHANGE) {
                $exchangeSources[] = $source;
                continue;
            }
            $node = self::sourceNode($source, $proxyDown, (array) ($presenceBySource[(int) $source['id']] ?? []));
            $key = 'source-' . (int) $source['id'];
            $nodes[$key] = $node;
            array_unshift($lanes['proxy']['nodes'], $key);
            $edges[] = self::edge($key, 'proxy', $node['state']);
        }

        $hostKeys = [];
        $hostNodes = [];
        foreach ((array) ($exchange['hosts'] ?? []) as $host) {
            $node = self::hostNode($host, (array) ($exchange['sessions'] ?? []), (bool) ($exchange['configured'] ?? false));
            $key = 'host-' . (int) $host['id'];
            $hostNodes[$key] = $node;
            $hostKeys[] = $key;
        }
        $exchangeDown = $hostKeys !== [] && array_filter($hostNodes, static fn (array $node): bool => $node['state'] !== 'error') === [];

        foreach ($exchangeSources as $source) {
            $node = self::exchangeSourceNode($source, $exchange, $exchangeDown, (array) ($presenceBySource[(int) $source['id']] ?? []));
            $key = 'source-' . (int) $source['id'];
            $nodes[$key] = $node;
            $lanes['exchange']['nodes'][] = $key;
            foreach ($hostKeys === [] ? ['users'] : $hostKeys as $target) {
                $edges[] = self::edge($key, $target, $node['state']);
            }
        }

        foreach ($hostNodes as $key => $node) {
            $nodes[$key] = $node;
            $lanes['exchange']['nodes'][] = $key;
            $edges[] = self::edge($key, 'users', $node['state']);
        }

        $nodes['users'] = self::usersNode($presence, (array) ($input['history'] ?? []), $proxyDown);
        $nodes['ai'] = self::aiNode($ai);
        $nodes['cache'] = self::cacheNode($cache);
        $lanes['exchange']['nodes'][] = 'users';
        $edges[] = self::edge('proxy', 'users', $nodes['users']['state']);
        $edges[] = self::edge('ai', 'users', $nodes['ai']['state']);
        $edges[] = self::edge('cache', 'users', $nodes['cache']['state']);

        // Speicher: der lokale Speicher der VM ist immer sichtbar, sobald der
        // Speicherdienst antwortet; Cold-Tiers und Snapshot-Speicher haengen
        // als Kinder daran.
        $tiers = [];
        $hasLocal = (bool) ($storage['available'] ?? false);
        if ($hasLocal) {
            $nodes['tier-local'] = self::localTierNode($storage);
            $tiers[] = 'tier-local';
            $edges[] = self::edge('tier-local', 'cache', $nodes['tier-local']['state']);
        }
        foreach ((array) ($storage['targets'] ?? []) as $tier) {
            $node = self::tierNode($tier);
            $key = 'tier-' . (int) $tier['id'];
            $nodes[$key] = $node;
            $tiers[] = $key;
            $edges[] = self::edge($key, $hasLocal ? 'tier-local' : 'cache', $node['state']);
        }
        $snapshot = (array) ($storage['snapshot'] ?? []);
        if ($hasLocal && (bool) ($snapshot['enabled'] ?? false)) {
            $nodes['tier-snapshot'] = self::snapshotTierNode($snapshot);
            $tiers[] = 'tier-snapshot';
            $edges[] = self::edge('tier-snapshot', 'tier-local', $nodes['tier-snapshot']['state']);
        }

        foreach ($lanes as $laneKey => $lane) {
            $state = self::laneState($lane['nodes'], $nodes);
            $lanes[$laneKey]['state'] = $state;
            $lanes[$laneKey]['state_label'] = self::STATE_LABELS[$state] ?? $state;
        }

        $kpis = self::kpis($proxy, $sources, $exchange, $presence, $storage, $cache, $ai);
        $incidents = self::incidents($nodes);
        $overall = self::overall($nodes, $incidents);

        return [
            'generated_at' => date('d.m.Y H:i:s', $now),
            'generated_iso' => date('Y-m-d H:i:s', $now),
            'overall' => $overall,
            'kpis' => $kpis,
            'lanes' => array_values($lanes),
            'nodes' => $nodes,
            'edges' => $edges,
            'incidents' => $incidents,
            'tiers' => $tiers,
            'history' => (array) ($input['history'] ?? []),
            'ai' => $ai,
            'presence' => $presence,
        ];
    }

    // ------------------------------------------------------------------ Knoten

    /**
     * Zustand des Proxy-Knotens: aus ohne Konfiguration, gestoert ohne
     * Antwort des Dienstes, eingeschraenkt bei inaktiven Mailservern.
     *
     * @param array<string,mixed> $proxy
     */
    private static function proxyState(array $proxy): string
    {
        if (!(bool) ($proxy['configured'] ?? false)) {
            return 'off';
        }
        if (!(bool) ($proxy['ok'] ?? false)) {
            return 'error';
        }
        $counts = (array) ($proxy['counts'] ?? []);

        return (int) ($counts['active_servers'] ?? 0) < (int) ($counts['servers'] ?? 0) ? 'warn' : 'ok';
    }

    /**
     * @param array<string,mixed> $proxy
     *
     * @return array<string,mixed>
     */
    private static function proxyNode(array $proxy): array
    {
        $configured = (bool) ($proxy['configured'] ?? false);
        $counts = (array) ($proxy['counts'] ?? []);
        $details = (array) ($proxy['details'] ?? []);
        $state = (array) ($proxy['state'] ?? []);
        $facts = [];

        if (!$configured) {
            return self::node('proxy', 'proxy', 'IMAP-/SMTP-Proxy', 'Kein Mailserver konfiguriert', 'off', [
                'message' => 'Ohne Proxy-Konfiguration läuft der Nachrichtenfluss ausschließlich über Exchange.',
                'link' => ['url' => '/admin/office/mail-proxy', 'label' => 'Proxy einrichten'],
            ]);
        }

        $stateKey = self::proxyState($proxy);

        $servers = (array) ($proxy['servers'] ?? []);
        $endpoints = [];
        foreach ($servers as $server) {
            $endpoints[] = (string) $server['smtp_host'] . ':' . (int) $server['smtp_port']
                . ' / ' . (string) $server['imap_host'] . ':' . (int) $server['imap_port'];
        }

        $facts[] = self::fact('Mailserver', (int) ($counts['active_servers'] ?? 0) . ' von ' . (int) ($counts['servers'] ?? 0) . ' aktiv', $stateKey === 'warn' ? 'warn' : '');
        $facts[] = self::fact('Postfächer', (int) ($counts['active_mailboxes'] ?? 0) . ' von ' . (int) ($counts['mailboxes'] ?? 0) . ' aktiv');
        $facts[] = self::fact('Zuordnungen', (string) (int) ($counts['mappings'] ?? 0));

        if ($details !== []) {
            $connections = (int) ($details['connections'] ?? 0);
            $max = (int) ($details['max_connections'] ?? 0);
            $facts[] = self::fact('Verbindungen', $max > 0 ? $connections . ' / ' . $max : (string) $connections);
            $facts[] = self::fact('Gepoolt', (string) (int) ($details['pooled'] ?? 0));
            $facts[] = self::fact('Anfragen', (string) (int) ($details['requests'] ?? 0));
            $facts[] = self::fact('Fehler', (string) (int) ($details['errors'] ?? 0), (int) ($details['errors'] ?? 0) > 0 ? 'warn' : '');
            if (isset($details['uptime'])) {
                $facts[] = self::fact('Laufzeit', self::uptimeLabel((int) $details['uptime']));
            }
            if (isset($details['version']) && (string) $details['version'] !== '') {
                $facts[] = self::fact('Version', (string) $details['version']);
            }
        }

        $facts[] = self::fact('Letzter Erfolg', self::timeLabel((string) ($state['last_success_at'] ?? '')));
        $facts[] = self::fact(
            'Letzter Fehler',
            self::timeLabel((string) ($state['last_error_at'] ?? '')),
            (string) ($state['last_error_at'] ?? '') !== '' && (int) ($state['failures'] ?? 0) > 0 ? 'warn' : ''
        );
        $facts[] = self::fact('Konfigurationsstand', 'Generation ' . (int) ($state['generation'] ?? 0));
        $facts[] = self::fact('Zuordnungs-Cache', self::durationLabel((int) ($proxy['cache_ttl'] ?? 0)));

        $message = (string) ($proxy['message'] ?? '');
        if ($stateKey === 'error' && (string) ($state['last_error'] ?? '') !== '') {
            $message = trim($message . ' ' . (string) $state['last_error']);
        }
        if ($stateKey === 'error') {
            $message = trim($message . ' Der Transportweg ist unterbrochen: alle Identitätsquellen und deren Postfächer sind nicht erreichbar.');
        }

        return self::node('proxy', 'proxy', 'IMAP-/SMTP-Proxy', implode(' · ', array_unique($endpoints)), $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'link' => ['url' => '/admin/office/mail-proxy', 'label' => 'Proxy verwalten'],
        ]);
    }

    /**
     * Identitaetsquelle mit Postfachwolke.
     *
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed>
     */
    private static function sourceNode(array $source, bool $proxyDown, array $users = []): array
    {
        $counts = (array) ($source['counts'] ?? []);
        $state = (array) ($source['state'] ?? []);
        $id = (int) ($source['id'] ?? 0);
        $active = (bool) ($source['active'] ?? false);
        $stateKey = 'ok';
        $message = '';
        $usersNow = (int) ($users['current'] ?? 0);
        $usersPeak = (int) ($users['peak'] ?? 0);

        if (!$active) {
            $stateKey = 'off';
            $message = 'Die Identitätsquelle ist deaktiviert.';
        } elseif ($proxyDown) {
            $stateKey = 'error';
            $message = 'Der Proxy ist nicht erreichbar; die Postfächer dieser Quelle sind nicht verfügbar.';
        } else {
            $lastError = (string) ($state['last_error_at'] ?? '');
            $lastSuccess = (string) ($state['last_success_at'] ?? '');
            $failures = (int) ($state['failures'] ?? 0);
            if ($lastError !== '' && $lastError >= $lastSuccess) {
                $stateKey = 'error';
                $message = (string) ($state['last_error'] ?? 'Die Identitätsquelle ist nicht erreichbar.');
            } elseif ($lastSuccess === '') {
                $stateKey = 'warn';
                $message = (bool) ($source['proxied'] ?? true)
                    ? 'Die Identitätsquelle wurde noch nicht geprüft.'
                    : 'Für diese Identitätsquelle ist im Proxy noch kein Mailserver hinterlegt.';
            }
        }

        $mailboxes = (int) ($counts['mailboxes'] ?? 0);
        $activeMailboxes = (int) ($counts['active_mailboxes'] ?? 0);
        $mapped = (int) ($counts['active_mapped_mailboxes'] ?? 0);
        $free = (int) ($counts['free_mailboxes'] ?? 0);

        $cloud = [
            ['label' => 'vorhanden', 'value' => $mailboxes, 'title' => 'Vorhandene Postfächer: ' . $mailboxes],
            ['label' => 'aktiv', 'value' => $activeMailboxes, 'title' => 'Aktive Postfächer: ' . $activeMailboxes],
            ['label' => 'verbunden', 'value' => $mapped, 'title' => 'Verbundene Postfächer: ' . $mapped],
            ['label' => 'frei', 'value' => $free, 'title' => 'Aktive Postfächer ohne Zuordnung: ' . $free],
        ];

        $facts = [
            self::fact('Aktive Nutzer', (string) $usersNow),
            self::fact('Maximum 24 h', (string) $usersPeak),
            self::fact('Postfächer', $mailboxes . ' vorhanden, ' . $activeMailboxes . ' aktiv'),
            self::fact('Verbunden', $mapped . ' zugeordnet, ' . $free . ' frei'),
            self::fact('Zuordnungen', (string) (int) ($counts['mappings'] ?? 0)),
            self::fact('Letzter Erfolg', self::timeLabel((string) ($state['last_success_at'] ?? ''))),
            self::fact(
                'Letzter Fehler',
                self::timeLabel((string) ($state['last_error_at'] ?? '')),
                $stateKey === 'error' ? 'error' : ''
            ),
            self::fact('Geprüft', self::timeLabel((string) ($state['checked_at'] ?? ''))),
        ];

        return self::node('source-' . $id, 'source', (string) ($source['label'] ?? 'Quelle'), (string) ($source['domain'] ?? ''), $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'counters' => self::userCounters($usersNow, $usersPeak),
            'cloud' => $cloud,
            'cloud_title' => 'Postfächer der Identitätsquelle',
            'cloud_muted' => $proxyDown || $stateKey === 'error',
            'primary' => (bool) ($source['primary'] ?? false),
            'muted' => $proxyDown,
            'muted_reason' => $proxyDown ? 'Proxy nicht erreichbar' : '',
            'transport' => self::TRANSPORT_PROXY,
            'link' => ['url' => '/admin/office/mail-proxy?source=' . $id, 'label' => 'Identitätsquelle verwalten'],
        ]);
    }

    /**
     * Identitaetsquelle ohne Proxy-Konfiguration: ihre Benutzer sprechen direkt
     * mit Exchange. Die Quelle selbst wird nicht geprueft; ihr Zustand folgt
     * den Exchange-Hosts, vor denen sie in der Topologie steht.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $exchange
     * @param array<string,mixed> $users   Praesenz der Quelle (current/peak)
     *
     * @return array<string,mixed>
     */
    private static function exchangeSourceNode(array $source, array $exchange, bool $exchangeDown, array $users = []): array
    {
        $id = (int) ($source['id'] ?? 0);
        $active = (bool) ($source['active'] ?? false);
        $configured = (bool) ($exchange['configured'] ?? false);
        $totals = (array) ($exchange['totals'] ?? []);
        $hosts = count((array) ($exchange['hosts'] ?? []));
        $stateKey = 'ok';
        $message = '';
        $usersNow = (int) ($users['current'] ?? 0);
        $usersPeak = (int) ($users['peak'] ?? 0);

        if (!$active) {
            $stateKey = 'off';
            $message = 'Die Identitätsquelle ist deaktiviert.';
        } elseif (!$configured) {
            $stateKey = 'off';
            $message = 'Exchange ist nicht konfiguriert; für diese Identitätsquelle ist kein Transportweg eingerichtet.';
        } elseif ($exchangeDown) {
            $stateKey = 'error';
            $message = 'Kein Exchange-Host ist erreichbar; die Benutzer dieser Quelle sind nicht verbunden.';
        }

        $facts = [
            self::fact('Aktive Nutzer', (string) $usersNow),
            self::fact('Maximum 24 h', (string) $usersPeak),
            self::fact('Transportweg', 'Exchange (EWS)'),
            self::fact('Exchange-Hosts', $hosts > 0 ? (int) ($totals['online'] ?? 0) . ' von ' . $hosts . ' online' : '–', $exchangeDown ? 'error' : ''),
            self::fact('Proxy', 'nicht konfiguriert'),
            self::fact('Prüfung', 'über die Exchange-Hosts'),
        ];

        return self::node('source-' . $id, 'source', (string) ($source['label'] ?? 'Quelle'), (string) ($source['domain'] ?? ''), $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'counters' => self::userCounters($usersNow, $usersPeak),
            'primary' => (bool) ($source['primary'] ?? false),
            'muted' => $exchangeDown,
            'muted_reason' => $exchangeDown ? 'Exchange nicht erreichbar' : '',
            'transport' => self::TRANSPORT_EXCHANGE,
            'link' => ['url' => '/admin/office/orvanta/hosts', 'label' => 'Exchange-Hosts verwalten'],
        ]);
    }

    /**
     * Exchange-Host mit der Wolke der verbundenen Clients.
     *
     * @param array<string,mixed> $host
     * @param list<array<string,mixed>> $sessions
     *
     * @return array<string,mixed>
     */
    private static function hostNode(array $host, array $sessions, bool $configured): array
    {
        $status = (string) ($host['status'] ?? 'unknown');
        $stateKey = match ($status) {
            'online' => 'ok',
            'maintenance' => 'warn',
            'offline' => 'error',
            default => 'warn',
        };
        if (!$configured) {
            $stateKey = 'off';
        }

        $clients = [];
        foreach ($sessions as $session) {
            if ((string) $session['host'] !== (string) $host['host']) {
                continue;
            }
            $name = trim((string) ($session['client_host'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($session['client_ip'] ?? ''));
            }
            if ($name === '') {
                $name = 'Unbekannter Client';
            }
            $clients[$name] = ($clients[$name] ?? 0) + 1;
        }
        arsort($clients);

        $cloud = [];
        foreach ($clients as $name => $count) {
            $cloud[] = ['label' => $name, 'value' => $count, 'title' => $name . ': ' . $count . ' Sitzung(en)'];
        }

        $failovers = 0;
        foreach ($sessions as $session) {
            if ((string) $session['host'] === (string) $host['host']) {
                $failovers += (int) ($session['failovers'] ?? 0);
            }
        }

        $facts = [
            self::fact('Status', (string) ($host['status_label'] ?? $status), $stateKey === 'error' ? 'error' : ''),
            self::fact('Sitzungen', (string) (int) ($host['sessions'] ?? 0)),
            self::fact('Antwortzeit', (string) ($host['latency_label'] ?? '–')),
            self::fact('Letzte Antwort', (string) ($host['last_session_label'] ?? '–')),
            self::fact('Letzte Prüfung', (string) ($host['last_check_label'] ?? '–')),
            self::fact('Umleitungen', (string) $failovers),
        ];
        if ((string) ($host['last_error'] ?? '') !== '') {
            $facts[] = self::fact('Letzter Fehler', (string) $host['last_error'], $stateKey === 'error' ? 'error' : 'warn');
        }

        $message = '';
        if (!$configured) {
            $message = 'Proxy-Betrieb: Exchange ist nicht konfiguriert.';
        } elseif ($stateKey === 'error') {
            $message = (string) ($host['last_error'] ?? '') !== ''
                ? (string) $host['last_error']
                : 'Der Host ist gestört; seine Clients sind nicht erreichbar.';
        }

        return self::node('host-' . (int) $host['id'], 'host', (string) $host['host'], (string) ($host['ews_url'] ?? ''), $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'counters' => [
                'current' => self::counter((int) ($host['sessions'] ?? 0), 'Verbundene Sitzungen'),
            ],
            'cloud' => $cloud,
            'cloud_title' => 'Verbundene Clients',
            'cloud_empty' => 'Keine Clients verbunden.',
            'cloud_muted' => $stateKey === 'error',
            'muted' => !$configured,
            'muted_reason' => !$configured ? 'Proxy-Betrieb' : '',
            'primary' => (bool) ($host['is_primary'] ?? false),
            'link' => ['url' => '/admin/office/orvanta/hosts', 'label' => 'DAG-Hosts verwalten'],
        ]);
    }

    /**
     * Nutzerknoten: aktuell, min/max 24 h und Verweis auf die Verlaufsgrafik.
     *
     * @param array<string,mixed> $presence
     * @param array<string,mixed> $history
     *
     * @return array<string,mixed>
     */
    private static function usersNode(array $presence, array $history, bool $proxyDown): array
    {
        $current = (int) ($presence['current'] ?? 0);
        $min = (int) ($presence['min'] ?? 0);
        $max = (int) ($presence['max'] ?? 0);
        $samples = (int) ($presence['samples'] ?? 0);
        $stateKey = $samples > 0 || $current > 0 ? 'ok' : 'warn';
        $message = $stateKey === 'warn' ? 'Noch keine Messwerte; der Verlauf füllt sich im Betrieb.' : '';

        $facts = [
            self::fact('Aktuell', (string) $current, ''),
            self::fact('Minimum 24 h', (string) $min),
            self::fact('Maximum 24 h', (string) $max),
            self::fact('Mittelwert 24 h', number_format((float) ($presence['avg'] ?? 0), 1, ',', '.')),
            self::fact('Über Exchange', (string) (int) ($presence['exchange'] ?? 0)),
            self::fact('Über Proxy', (string) (int) ($presence['proxy'] ?? 0)),
            self::fact('Messwerte 24 h', (string) $samples),
            self::fact('Aktivitätsfenster', self::durationLabel((int) ($presence['window'] ?? OrvantaPresenceService::ACTIVE_WINDOW))),
        ];

        return self::node('users', 'users', 'Orvanta-Nutzer', 'Aktivität aus Exchange und Proxy', $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'counters' => self::userCounters($current, $max),
            'muted' => $proxyDown && (int) ($presence['exchange'] ?? 0) === 0,
            'muted_reason' => $proxyDown ? 'Nur Proxy-Nutzer betroffen' : '',
            'chart' => (array) $history,
            'link' => ['url' => '/admin/office/orvanta/ki', 'label' => 'KI-Statistik öffnen'],
        ]);
    }

    /**
     * @param array<string,mixed> $ai
     *
     * @return array<string,mixed>
     */
    private static function aiNode(array $ai): array
    {
        $enabled = (bool) ($ai['enabled'] ?? false);
        $configured = (bool) ($ai['configured'] ?? false);
        $active = (bool) ($ai['active'] ?? false);
        $hasKey = (bool) ($ai['has_key'] ?? false);
        $top = (array) ($ai['top'] ?? []);

        $stateKey = 'ok';
        $message = '';
        if (!$enabled) {
            $stateKey = 'off';
            $message = 'Der KI-Endpunkt ist nicht freigegeben.';
        } elseif (!$configured) {
            $stateKey = 'warn';
            $message = 'Der KI-Endpunkt ist freigegeben, aber nicht vollständig konfiguriert (Adresse und Modell fehlen).';
        } elseif (!$hasKey && !$active) {
            $stateKey = 'warn';
            $message = 'Für den KI-Endpunkt ist kein Zugangsschlüssel hinterlegt.';
        }

        $days = (int) ($ai['period_days'] ?? self::AI_PERIOD_DAYS);
        $remaining = (int) ($ai['remaining'] ?? 0);
        $sessionsNow = (int) ($ai['active_users'] ?? 0);
        $sessionsDay = (int) ($ai['day_users'] ?? 0);

        $facts = [
            self::fact('Zustand', $active ? 'aktiv' : ($configured ? 'konfiguriert' : 'nicht konfiguriert'), $stateKey === 'warn' ? 'warn' : ''),
            self::fact('Sitzungen aktuell', (string) $sessionsNow),
            self::fact('Sitzungen 24 h', (string) $sessionsDay),
            self::fact('Adresse', (string) ($ai['url'] ?? '') !== '' ? (string) $ai['url'] : '–'),
            self::fact('Modell', (string) ($ai['model'] ?? '') !== '' ? (string) $ai['model'] : '–'),
            self::fact('Zugangsschlüssel', $hasKey ? 'hinterlegt' : 'fehlt', $hasKey ? '' : 'warn'),
            self::fact('Audio / Bilder', ($ai['audio'] ?? false ? 'Audio' : 'kein Audio') . ' / ' . ($ai['images'] ?? false ? 'Bilder' : 'keine Bilder')),
            self::fact('Anfragen ' . $days . ' Tage', (string) (int) ($ai['requests'] ?? 0)),
            self::fact('Nutzer ' . $days . ' Tage', (string) (int) ($ai['users'] ?? 0)),
            self::fact('Token', (string) (int) ($ai['input_tokens'] ?? 0) . ' ein / ' . (string) (int) ($ai['output_tokens'] ?? 0) . ' aus'),
        ];

        return self::node('ai', 'ai', (string) ($ai['name'] ?? 'KI-Endpunkt'), (string) ($ai['url'] ?? ''), $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'counters' => [
                'current' => self::counter($sessionsNow, 'Sitzungen aktuell'),
                'peak' => self::counter($sessionsDay, 'Sitzungen in 24 h'),
            ],
            'cloud' => $top,
            'cloud_title' => 'Top-' . self::AI_TOP_USERS . ' Nutzer (' . $days . ' Tage)',
            'cloud_empty' => 'Keine Anfragen im Zeitraum.',
            'cloud_more' => $remaining > 0 ? $remaining . ' weitere Nutzer in den letzten ' . $days . ' Tagen' : '',
            'link' => ['url' => '/admin/office/orvanta/ki', 'label' => 'KI-Statistik öffnen'],
        ]);
    }

    /**
     * @param array<string,mixed> $cache
     *
     * @return array<string,mixed>
     */
    private static function cacheNode(array $cache): array
    {
        $quota = (int) ($cache['quota'] ?? 0);
        $used = (int) ($cache['used'] ?? 0);
        $percent = (int) ($cache['percent'] ?? 0);

        if ($quota <= 0) {
            return self::node('cache', 'cache', 'Orvanta-Zwischenspeicher', 'Ohne feste Grenze', 'off', [
                'message' => 'Es ist keine Quota für den Zwischenspeicher hinterlegt.',
                'facts' => [
                    self::fact('Belegt', StorageHealth::formatBytes($used)),
                    self::fact('Objekte', (string) (int) ($cache['items'] ?? 0)),
                    self::fact('Nutzer', (string) (int) ($cache['users'] ?? 0)),
                ],
                'cloud' => (array) ($cache['top'] ?? []),
                'cloud_title' => 'Größte Zwischenspeicher je Nutzer',
                'link' => ['url' => '/admin/office/orvanta', 'label' => 'Quota setzen'],
            ]);
        }

        $stateKey = $percent >= self::CACHE_CRIT_PERCENT ? 'error' : ($percent >= self::CACHE_WARN_PERCENT ? 'warn' : 'ok');
        $message = match ($stateKey) {
            'error' => 'Der Zwischenspeicher ist fast voll. Bitte Zwischenspeicher leeren.',
            'warn' => 'Der Zwischenspeicher füllt sich.',
            default => '',
        };

        $cloud = [];
        foreach ((array) ($cache['top'] ?? []) as $row) {
            $cloud[] = [
                'label' => (string) $row['user_uid'],
                'value' => (int) $row['bytes'],
                'title' => (string) $row['user_uid'] . ': ' . StorageHealth::formatBytes((int) $row['bytes']) . ' in ' . (int) $row['items'] . ' Objekt(en)',
                'display' => StorageHealth::formatBytes((int) $row['bytes']),
            ];
        }

        return self::node('cache', 'cache', 'Orvanta-Zwischenspeicher', $percent . ' % belegt', $stateKey, [
            'message' => $message,
            'facts' => [
                self::fact('Belegt', StorageHealth::formatBytes($used) . ' von ' . StorageHealth::formatBytes($quota), $stateKey),
                self::fact('Belegung', $percent . ' %', $stateKey),
                self::fact('Warnschwelle', self::CACHE_WARN_PERCENT . ' %'),
                self::fact('Kritisch ab', self::CACHE_CRIT_PERCENT . ' %'),
                self::fact('Objekte', (string) (int) ($cache['items'] ?? 0)),
                self::fact('Nutzer', (string) (int) ($cache['users'] ?? 0)),
            ],
            'cloud' => $cloud,
            'cloud_title' => 'Größte Zwischenspeicher je Nutzer',
            'cloud_empty' => 'Der Zwischenspeicher ist leer.',
            'link' => ['url' => '/admin/office/orvanta', 'label' => 'Zwischenspeicher verwalten'],
        ]);
    }

    /**
     * @param array<string,mixed> $tier
     *
     * @return array<string,mixed>
     */
    private static function tierNode(array $tier): array
    {
        $state = (string) ($tier['state'] ?? 'unknown');
        $fill = (array) ($tier['fill'] ?? ['percent' => null, 'state' => 'disabled']);
        $unbounded = (bool) ($tier['unbounded'] ?? false);

        $stateKey = match ($state) {
            'online' => 'ok',
            'unknown' => 'warn',
            'disabled' => 'off',
            default => 'error',
        };
        if ($stateKey === 'ok' && ($fill['state'] ?? '') === 'critical') {
            $stateKey = 'error';
        } elseif ($stateKey === 'ok' && ($fill['state'] ?? '') === 'degraded') {
            $stateKey = 'warn';
        }

        $used = max(0, (int) ($tier['total_bytes'] ?? 0) - (int) ($tier['free_bytes'] ?? 0));
        $capacity = $unbounded
            ? 'ohne feste Kapazität'
            : StorageHealth::formatBytes($used) . ' von ' . StorageHealth::formatBytes((int) ($tier['total_bytes'] ?? 0));

        $message = '';
        if ($stateKey === 'error') {
            $message = (string) ($tier['message'] ?? '') !== ''
                ? (string) $tier['message']
                : ($state === 'online' ? 'Der Tier ist fast voll.' : 'Der Tier ist nicht erreichbar.');
        } elseif ($stateKey === 'warn') {
            $message = (string) ($tier['message'] ?? '');
        }

        $members = [];
        foreach ((array) ($tier['members'] ?? []) as $member) {
            $memberState = match ((string) ($member['state'] ?? 'unknown')) {
                'online' => 'ok',
                'disabled' => 'off',
                'unknown' => 'warn',
                default => 'error',
            };
            $members[] = [
                'label' => (string) $member['label'] . (($member['role'] ?? '') === 'extension' ? ' (Erweiterung)' : ''),
                'value' => self::STATE_LABELS[$memberState],
                'state' => $memberState,
                'title' => (string) $member['label'] . ': ' . self::STATE_LABELS[$memberState],
            ];
        }

        return self::node('tier-' . (int) $tier['id'], 'tier', (string) $tier['label'], strtoupper((string) ($tier['kind'] ?? 'smb')), $stateKey, [
            'message' => $message,
            'facts' => [
                self::fact('Zustand', self::STATE_LABELS[$stateKey], $stateKey),
                self::fact('Belegt', $capacity, $stateKey === 'ok' ? '' : $stateKey),
                self::fact('Belegung', $unbounded ? '–' : (string) ($fill['percent'] ?? '–') . ' %'),
                self::fact('Synchron', ($tier['in_sync'] ?? false) ? 'ja' : 'nein', ($tier['in_sync'] ?? false) ? '' : 'warn'),
                self::fact('Rückstand', self::durationLabel((int) ($tier['lag_seconds'] ?? 0))),
                self::fact('Datenrate', StorageHealth::formatRate((int) ($tier['read_bps'] ?? 0)) . ' lesen / ' . StorageHealth::formatRate((int) ($tier['write_bps'] ?? 0)) . ' schreiben'),
                self::fact('Erweiterungen', (string) max(0, count((array) ($tier['members'] ?? [])) - 1)),
            ],
            'members' => $members,
            'muted' => in_array($state, ['offline', 'disabled'], true),
            'muted_reason' => $state === 'disabled' ? 'deaktiviert' : 'nicht erreichbar',
            'link' => ['url' => '/admin/storage', 'label' => 'Speicher verwalten'],
        ]);
    }

    /**
     * Lokaler Speicher der lanpa-VM (Hot-Tier). Er ist immer vorhanden und
     * damit auch ohne eingerichtete Speicherziele sichtbar; Cold-Tiers und
     * der Snapshot-Speicher haengen in der Topologie an ihm.
     *
     * @param array<string,mixed> $storage
     *
     * @return array<string,mixed>
     */
    private static function localTierNode(array $storage): array
    {
        $local = (array) ($storage['local'] ?? []);
        $fill = (array) ($local['fill'] ?? ['percent' => null, 'state' => 'disabled']);
        $total = (int) ($local['total_bytes'] ?? 0);
        $free = (int) ($local['free_bytes'] ?? 0);
        $used = (int) ($local['used_bytes'] ?? max(0, $total - $free));
        $mode = (string) ($storage['mode'] ?? 'normal');
        $targets = count((array) ($storage['targets'] ?? []));

        $stateKey = 'ok';
        $message = '';
        if ($total <= 0) {
            $stateKey = 'warn';
            $message = 'Noch keine Messwerte des lokalen Speichers; der Speicher-Worker liefert sie im Betrieb.';
        } elseif (($fill['state'] ?? '') === 'critical') {
            $stateKey = 'error';
            $message = 'Der lokale Speicher ist fast voll; Anhänge können nicht mehr abgelegt werden.';
        } elseif (($fill['state'] ?? '') === 'degraded') {
            $stateKey = 'warn';
            $message = 'Der lokale Speicher füllt sich.';
        } elseif ($mode !== '' && $mode !== 'normal') {
            $stateKey = 'warn';
            $message = (string) ($storage['forecast_text'] ?? '') !== ''
                ? (string) $storage['forecast_text']
                : 'Der Speicher läuft im Modus „' . $mode . '“.';
        }

        $limit = (int) ($local['limit_bytes'] ?? 0);
        $facts = [
            self::fact('Zustand', self::STATE_LABELS[$stateKey], $stateKey),
            self::fact('Belegt', $total > 0 ? StorageHealth::formatBytes($used) . ' von ' . StorageHealth::formatBytes($total) : '–', $stateKey === 'ok' ? '' : $stateKey),
            self::fact('Belegung', $total > 0 ? (string) ($fill['percent'] ?? '–') . ' %' : '–'),
            self::fact('Orvanta-Daten lokal', StorageHealth::formatBytes((int) ($local['bytes_local'] ?? 0))),
            self::fact('Grenze', $limit > 0 ? StorageHealth::formatBytes($limit) . (($local['limit_auto'] ?? false) ? ' (automatisch)' : '') : 'keine'),
            self::fact('Datenrate', StorageHealth::formatRate((int) ($local['read_bps'] ?? 0)) . ' lesen / ' . StorageHealth::formatRate((int) ($local['write_bps'] ?? 0)) . ' schreiben'),
            self::fact('Modus', $mode !== '' ? $mode : 'normal', $mode !== '' && $mode !== 'normal' ? 'warn' : ''),
            self::fact('Cold-Tiers', (string) $targets),
        ];
        if ((string) ($storage['forecast_text'] ?? '') !== '') {
            $facts[] = self::fact('Prognose', (string) $storage['forecast_text']);
        }

        return self::node('tier-local', 'tier', 'Lokaler Speicher (VM)', 'Hot-Tier', $stateKey, [
            'message' => $message,
            'facts' => $facts,
            'primary' => true,
            'link' => ['url' => '/admin/storage', 'label' => 'Speicher verwalten'],
        ]);
    }

    /**
     * Snapshot-Speicher (Versionen) als Kind des lokalen Speichers.
     *
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>
     */
    private static function snapshotTierNode(array $snapshot): array
    {
        $state = (string) ($snapshot['state'] ?? 'unknown');
        $fill = (array) ($snapshot['fill'] ?? ['percent' => null, 'state' => 'disabled']);
        $stateKey = match ($state) {
            'online' => 'ok',
            'unknown' => 'warn',
            'disabled' => 'off',
            default => 'error',
        };
        if ($stateKey === 'ok' && ($fill['state'] ?? '') === 'critical') {
            $stateKey = 'error';
        } elseif ($stateKey === 'ok' && ($fill['state'] ?? '') === 'degraded') {
            $stateKey = 'warn';
        }

        $total = (int) ($snapshot['total_bytes'] ?? 0);
        $used = (int) ($snapshot['used_bytes'] ?? 0);
        $message = '';
        if ($stateKey === 'error') {
            $message = (string) ($snapshot['message'] ?? '') !== ''
                ? (string) $snapshot['message']
                : ($state === 'online' ? 'Der Snapshot-Speicher ist fast voll.' : 'Der Snapshot-Speicher ist nicht erreichbar.');
        } elseif ($stateKey === 'warn') {
            $message = (string) ($snapshot['message'] ?? '');
        }
        $failed = (int) ($snapshot['failed'] ?? 0);

        return self::node('tier-snapshot', 'tier', 'Snapshot-Speicher', (string) ($snapshot['unc_path'] ?? '') !== '' ? (string) $snapshot['unc_path'] : 'Versionen', $stateKey, [
            'message' => $message,
            'facts' => [
                self::fact('Zustand', (string) ($snapshot['state_label'] ?? self::STATE_LABELS[$stateKey]), $stateKey),
                self::fact('Belegt', $total > 0 ? StorageHealth::formatBytes($used) . ' von ' . StorageHealth::formatBytes($total) : '–', $stateKey === 'ok' ? '' : $stateKey),
                self::fact('Belegung', $total > 0 ? (string) ($fill['percent'] ?? '–') . ' %' : '–'),
                self::fact('Versionen', (string) (int) ($snapshot['snapshots_total'] ?? 0) . ' (' . StorageHealth::formatBytes((int) ($snapshot['snapshots_bytes'] ?? 0)) . ')'),
                self::fact('Vorgemerkt / fehlgeschlagen', (string) (int) ($snapshot['pending'] ?? 0) . ' / ' . $failed, $failed > 0 ? 'warn' : ''),
                self::fact('Letzter Snapshot', self::timeLabel((string) ($snapshot['last_snapshot_at'] ?? ''))),
                self::fact('Aufbewahrung', (string) (int) ($snapshot['retention_days'] ?? 0) . ' Tage, höchstens ' . (string) (int) ($snapshot['max_versions'] ?? 0) . ' Versionen'),
            ],
            'muted' => in_array($state, ['offline', 'invalid', 'disabled'], true),
            'muted_reason' => $state === 'disabled' ? 'deaktiviert' : 'nicht erreichbar',
            'link' => ['url' => '/admin/storage', 'label' => 'Speicher verwalten'],
        ]);
    }

    // ------------------------------------------------------------------ Kennzahlen

    /**
     * @param array<string,mixed> $proxy
     * @param list<array<string,mixed>> $sources
     * @param array<string,mixed> $exchange
     * @param array<string,mixed> $presence
     * @param array<string,mixed> $storage
     * @param array<string,mixed> $cache
     * @param array<string,mixed> $ai
     *
     * @return list<array{key:string,label:string,value:string,hint:string,state:string,anchor:string}>
     */
    private static function kpis(array $proxy, array $sources, array $exchange, array $presence, array $storage, array $cache, array $ai): array
    {
        $activeSources = 0;
        $mailboxes = 0;
        $activeMailboxes = 0;
        $mapped = 0;
        foreach ($sources as $source) {
            if ((bool) ($source['active'] ?? false)) {
                $activeSources++;
            }
            $counts = (array) ($source['counts'] ?? []);
            $mailboxes += (int) ($counts['mailboxes'] ?? 0);
            $activeMailboxes += (int) ($counts['active_mailboxes'] ?? 0);
            $mapped += (int) ($counts['active_mapped_mailboxes'] ?? 0);
        }
        $quota = $activeMailboxes > 0 ? (int) round($mapped * 100 / $activeMailboxes) : 0;

        $counts = (array) ($proxy['counts'] ?? []);
        $proxyState = self::proxyState($proxy);

        $totals = (array) ($exchange['totals'] ?? []);
        $hosts = (array) ($exchange['hosts'] ?? []);
        $exchangeState = 'off';
        foreach ($hosts as $host) {
            $status = (string) ($host['status'] ?? 'unknown');
            if ($status === 'offline') {
                $exchangeState = 'error';
                break;
            }
            if (in_array($status, ['maintenance', 'unknown'], true)) {
                $exchangeState = 'warn';
            } elseif ($exchangeState === 'off') {
                $exchangeState = 'ok';
            }
        }

        $tierTotal = 0;
        $tierUsed = 0;
        $tierOnline = 0;
        $tierState = 'off';
        foreach ((array) ($storage['targets'] ?? []) as $tier) {
            $state = (string) ($tier['state'] ?? 'unknown');
            if ($state === 'online') {
                $tierOnline++;
            }
            if (!(bool) ($tier['unbounded'] ?? false)) {
                $tierTotal += (int) ($tier['total_bytes'] ?? 0);
                $tierUsed += max(0, (int) ($tier['total_bytes'] ?? 0) - (int) ($tier['free_bytes'] ?? 0));
            }
            if (in_array($state, ['offline', 'invalid'], true)) {
                $tierState = 'error';
            } elseif ($state === 'unknown' && $tierState !== 'error') {
                $tierState = 'warn';
            } elseif ($state === 'online' && $tierState === 'off') {
                $tierState = 'ok';
            }
        }
        $tierCount = count((array) ($storage['targets'] ?? []));

        $cachePercent = (int) ($cache['percent'] ?? 0);
        $cacheState = (int) ($cache['quota'] ?? 0) <= 0
            ? 'off'
            : ($cachePercent >= self::CACHE_CRIT_PERCENT ? 'error' : ($cachePercent >= self::CACHE_WARN_PERCENT ? 'warn' : 'ok'));

        $aiActive = (bool) ($ai['active'] ?? false);
        $aiState = !(bool) ($ai['enabled'] ?? false) ? 'off' : ($aiActive ? 'ok' : 'warn');

        return [
            [
                'key' => 'users',
                'label' => 'Orvanta-Nutzer jetzt',
                'value' => (string) (int) ($presence['current'] ?? 0),
                'hint' => '24 h: min ' . (int) ($presence['min'] ?? 0) . ' / max ' . (int) ($presence['max'] ?? 0),
                'state' => 'ok',
                'anchor' => 'flow-users',
            ],
            [
                'key' => 'sources',
                'label' => 'Identitätsquellen',
                'value' => $activeSources . ' von ' . count($sources),
                'hint' => $mailboxes . ' Postfächer, ' . $activeMailboxes . ' aktiv',
                'state' => $proxyState,
                'anchor' => 'flow-proxy',
            ],
            [
                'key' => 'mapping',
                'label' => 'Zuordnungsquote',
                'value' => $quota . ' %',
                'hint' => $mapped . ' von ' . $activeMailboxes . ' aktiven Postfächern zugeordnet',
                'state' => $activeMailboxes === 0 ? 'off' : ($quota >= 95 ? 'ok' : 'warn'),
                'anchor' => 'flow-proxy',
            ],
            [
                'key' => 'proxy',
                'label' => 'IMAP-/SMTP-Proxy',
                'value' => (string) (int) ($counts['active_servers'] ?? 0) . ' von ' . (string) (int) ($counts['servers'] ?? 0),
                'hint' => (bool) ($proxy['ok'] ?? false) ? 'Dienst erreichbar' : (string) ($proxy['message'] ?? 'nicht geprüft'),
                'state' => $proxyState,
                'anchor' => 'flow-proxy',
            ],
            [
                'key' => 'exchange',
                'label' => 'Exchange-Hosts',
                'value' => (int) ($totals['online'] ?? 0) . ' von ' . (int) ($totals['hosts'] ?? 0),
                'hint' => (int) ($totals['sessions'] ?? 0) . ' verbundene Sitzungen',
                'state' => $exchangeState,
                'anchor' => 'flow-exchange',
            ],
            [
                'key' => 'storage',
                'label' => 'Speicher-Tiers',
                'value' => $tierOnline . ' von ' . $tierCount,
                'hint' => $tierTotal > 0
                    ? StorageHealth::formatBytes($tierUsed) . ' von ' . StorageHealth::formatBytes($tierTotal) . ' belegt'
                    : 'Keine Kapazität hinterlegt',
                'state' => $tierState,
                'anchor' => 'flow-storage',
            ],
            [
                'key' => 'cache',
                'label' => 'Orvanta-Zwischenspeicher',
                'value' => (int) ($cache['quota'] ?? 0) > 0 ? $cachePercent . ' %' : '–',
                'hint' => (int) ($cache['quota'] ?? 0) > 0
                    ? StorageHealth::formatBytes((int) ($cache['used'] ?? 0)) . ' von ' . StorageHealth::formatBytes((int) $cache['quota'])
                    : 'Keine Quota hinterlegt',
                'state' => $cacheState,
                'anchor' => 'flow-cache',
            ],
            [
                'key' => 'ai',
                'label' => 'KI-Anfragen ' . (int) ($ai['period_days'] ?? self::AI_PERIOD_DAYS) . ' Tage',
                'value' => (string) (int) ($ai['requests'] ?? 0),
                'hint' => (int) ($ai['users'] ?? 0) . ' Nutzer, Top-' . self::AI_TOP_USERS . ' in der Wolke',
                'state' => $aiState,
                'anchor' => 'flow-ai',
            ],
        ];
    }

    // ------------------------------------------------------------------ Zustand

    /**
     * Gesamtstatus: error schlaegt warn, warn schlaegt ok.
     *
     * @param array<string,array<string,mixed>> $nodes
     * @param list<array<string,mixed>> $incidents
     *
     * @return array{state:string,label:string,message:string,errors:int,warnings:int}
     */
    private static function overall(array $nodes, array $incidents): array
    {
        $errors = 0;
        $warnings = 0;
        $errorNames = [];
        foreach ($nodes as $node) {
            if (($node['state'] ?? '') === 'error') {
                $errors++;
                $errorNames[] = (string) $node['title'];
            } elseif (($node['state'] ?? '') === 'warn') {
                $warnings++;
            }
        }

        $state = $errors > 0 ? 'error' : ($warnings > 0 ? 'warn' : 'ok');
        $message = match ($state) {
            'error' => $errors . ' Störung' . ($errors === 1 ? '' : 'en') . ': ' . implode(', ', $errorNames),
            'warn' => $warnings . ' Einschränkung' . ($warnings === 1 ? '' : 'en'),
            default => 'Alle Bausteine sind in Ordnung.',
        };
        if ($incidents !== []) {
            $message .= ' Offene Vorfälle: ' . count($incidents) . '.';
        }

        return [
            'state' => $state,
            'label' => self::STATE_LABELS[$state],
            'message' => $message,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Stoerungen fuer das Stoerungsband: alle Knoten im Zustand error.
     *
     * @param array<string,array<string,mixed>> $nodes
     *
     * @return list<array{key:string,title:string,message:string,url:string}>
     */
    private static function incidents(array $nodes): array
    {
        $incidents = [];
        foreach ($nodes as $key => $node) {
            if (($node['state'] ?? '') !== 'error') {
                continue;
            }
            $link = (array) ($node['link'] ?? []);
            $incidents[] = [
                'key' => (string) $key,
                'title' => (string) $node['title'],
                'message' => (string) ($node['message'] ?? ''),
                'url' => (string) ($link['url'] ?? ''),
            ];
        }

        return $incidents;
    }

    /**
     * Zustand einer Spur: der schlechteste Zustand ihrer Knoten.
     *
     * @param list<string> $keys
     * @param array<string,array<string,mixed>> $nodes
     */
    private static function laneState(array $keys, array $nodes): string
    {
        $state = 'off';
        foreach ($keys as $key) {
            $nodeState = (string) ($nodes[$key]['state'] ?? 'off');
            if ($nodeState === 'error') {
                return 'error';
            }
            if ($nodeState === 'warn' && $state !== 'error') {
                $state = 'warn';
            } elseif ($nodeState === 'ok' && $state === 'off') {
                $state = 'ok';
            }
        }

        return $state;
    }

    /**
     * @return array{from:string,to:string,state:string,label:string}
     */
    private static function edge(string $from, string $to, string $state): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'state' => $state === 'error' ? 'error' : 'ok',
            'label' => '',
        ];
    }

    // ------------------------------------------------------------------ Hilfen

    /**
     * Einheitlicher Knotenaufbau. Kanten und Ansicht lesen nur diese Felder.
     *
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private static function node(string $key, string $kind, string $title, string $subtitle, string $state, array $extra = []): array
    {
        $defaults = [
            'key' => $key,
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $subtitle,
            'state' => $state,
            'state_label' => self::STATE_LABELS[$state] ?? $state,
            'alert' => $state === 'error',
            'muted' => false,
            'muted_reason' => '',
            'muted_label' => '',
            'message' => '',
            'facts' => [],
            'cloud' => [],
            'cloud_title' => '',
            'cloud_empty' => 'Keine Einträge.',
            'cloud_more' => '',
            'cloud_muted' => false,
            'link' => null,
            'primary' => false,
            'transport' => '',
            'chart' => [],
            'members' => [],
            'counters' => [],
        ];

        $node = array_merge($defaults, $extra);
        $node['muted_label'] = $node['muted'] === true
            ? 'Werte ausgegraut (' . ($node['muted_reason'] !== '' ? (string) $node['muted_reason'] : 'nicht erreichbar') . ')'
            : '';

        return $node;
    }

    /**
     * @return array{label:string,value:string,state:string}
     */
    private static function fact(string $label, string $value, string $state = ''): array
    {
        return ['label' => $label, 'value' => $value, 'state' => $state];
    }

    /**
     * Zaehler am Knotenrand der Topologie. `current` erscheint oben rechts,
     * `peak` oben links des Badges.
     *
     * @return array{value:int,label:string}
     */
    private static function counter(int $value, string $label): array
    {
        return ['value' => max(0, $value), 'label' => $label];
    }

    /**
     * Zaehlerpaar fuer Nutzerknoten: aktuell verbundene Nutzer und das
     * 24-h-Maximum. Die Werte der Identitaetsquellen summieren sich exakt zu
     * denen des Knotens "Orvanta-Nutzer" (siehe OrvantaPresenceService::stats()).
     *
     * @return array{current:array{value:int,label:string},peak:array{value:int,label:string}}
     */
    private static function userCounters(int $current, int $peak): array
    {
        return [
            'current' => self::counter($current, 'Aktive Nutzer'),
            'peak' => self::counter($peak, 'Maximum 24 h'),
        ];
    }

    private static function timeLabel(string $stamp): string
    {
        if (trim($stamp) === '') {
            return '–';
        }
        $time = strtotime($stamp);

        return $time === false ? $stamp : date('d.m.Y H:i:s', $time);
    }

    private static function durationLabel(int $seconds): string
    {
        return StorageHealth::formatDuration($seconds);
    }

    private static function uptimeLabel(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(0, $seconds) . ' s';
        }
        if ($seconds < 86400) {
            return (int) round($seconds / 60) . ' min';
        }

        return (int) round($seconds / 3600) . ' h';
    }

    private function now(): int
    {
        if ($this->clock !== null) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
