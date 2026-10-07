<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\OrvantaExchangeHostRepository;
use App\Support\Validator;
use Closure;

/**
 * Lastverteilung und Ausfallsicherung fuer die Hosts einer Exchange-Database
 * Availability Group (DAG).
 *
 * Eine Orvanta-Sitzung bleibt moeglichst auf ihrem Host (Sitzungsaffinitaet).
 * Neue Sitzungen verteilt die Prioritaetenfolge
 *   1. Fair-use: der Host, der am laengsten keine Sitzung mehr erhalten hat,
 *   2. der Host mit den wenigsten verbundenen Sitzungen,
 *   3. der Host mit der geringsten mittleren Antwortzeit.
 * Faellt ein Host aus, wird die Sitzung ohne Zutun des Benutzers auf den
 * naechsten Host umgeleitet (failover()).
 *
 * Fehlen die Tabellen der Migration 043, arbeitet die Verteilung mit dem
 * allein aus den Orvanta-Einstellungen bekannten Server (Orvanta bleibt
 * benutzbar).
 */
final class OrvantaExchangePool
{
    /** Ohne Aktivitaet gilt eine Sitzung nach dieser Zeit als beendet (Sekunden). */
    public const SESSION_TTL = 300;

    /** Aufraeumgrenze fuer beendete Sitzungszeilen (Sekunden). */
    public const PURGE_AFTER = 86400;

    /** Gewichtung des gleitenden Mittels der Antwortzeit. */
    public const LATENCY_SAMPLES = 200;

    /** So lange gilt ein gestoerter Host fuer neue Sitzungen als nachrangig (Sekunden). */
    public const FAILURE_COOLDOWN = 60;

    /** Hoechstzahl der Hosts einer DAG (Exchange erlaubt bis zu 16 Mitglieder). */
    public const MAX_HOSTS = 16;

    /** @var array<string,string> */
    public const STATUS_LABELS = [
        'online' => 'Online',
        'offline' => 'Gestört',
        'maintenance' => 'Wartung',
        'unknown' => 'Ungeprüft',
    ];

    /** @var list<array<string,mixed>>|null */
    private ?array $hosts = null;

    /**
     * @param Closure():string $sessionKey Kennung der aktuellen Orvanta-Sitzung
     *                                     ('' ausserhalb einer HTTP-Sitzung, z. B. CLI)
     */
    public function __construct(
        private readonly OrvantaExchangeHostRepository $repository,
        private readonly OrvantaConfigService $config,
        private readonly Closure $sessionKey
    ) {
    }

    /**
     * Kennung der aktuellen Sitzung; '' ohne Sitzung (CLI, Archivierungs-Worker).
     */
    public function sessionKey(): string
    {
        return (string) ($this->sessionKey)();
    }

    /**
     * Hosts in Auswahlreihenfolge (primaerer Host zuerst). Ohne
     * $includeInactive bleiben Hosts in Wartung aussen vor; ist kein Host
     * aktiv, gilt der primaere Host weiter (Orvanta bleibt benutzbar).
     *
     * @return list<array<string,mixed>>
     */
    public function hosts(bool $includeInactive = false): array
    {
        $hosts = $this->all();
        if ($includeInactive) {
            return $hosts;
        }
        $active = array_values(array_filter($hosts, static fn (array $host): bool => (int) $host['active'] === 1));
        if ($active !== []) {
            return $active;
        }
        foreach ($hosts as $host) {
            if ((int) $host['is_primary'] === 1) {
                return [$host];
            }
        }

        return [];
    }

    /**
     * Host fuer diese Sitzung: eine bereits zugeordnete Sitzung bleibt auf
     * ihrem Host, sonst wird nach den Prioritaeten neu verteilt. Ist der
     * zugeordnete Host nicht mehr verfuegbar (Ausfall, Wartung, entfernt),
     * wird die Sitzung sofort umgeleitet.
     *
     * @param list<string> $exclude Hosts, die im laufenden Aufruf bereits ausgefallen sind
     *
     * @return array<string,mixed>|null
     */
    public function session(string $key, string $identity = '', array $exclude = []): ?array
    {
        $hosts = $this->selectable($exclude);
        if ($hosts === []) {
            return null;
        }
        if ($key === '') {
            return $this->best($hosts, $this->sessionCounts());
        }
        $hash = self::hash($key);
        $row = $this->findSession($hash);
        if ($row !== null) {
            foreach ($hosts as $host) {
                if (strcasecmp((string) $host['host'], (string) $row['host']) === 0) {
                    return $host;
                }
            }

            return $this->move($hash, $hosts);
        }

        return $this->start($hash, $hosts, $identity);
    }

    /**
     * Host, auf dem die aktuelle Sitzung laeuft (Anzeige im Fussbereich der
     * App). Ohne Sitzung (CLI) gilt der zuerst gewaehlte Host.
     *
     * @return array<string,mixed>|null
     */
    public function currentHost(): ?array
    {
        $key = $this->sessionKey();
        if ($key !== '') {
            return $this->session($key);
        }
        $hosts = $this->hosts();

        return $hosts[0] ?? null;
    }

    /**
     * Naechster Host fuer eine ausgefallene Sitzung. Die Zuordnung wird sofort
     * umgeschrieben, damit auch der naechste Aufruf den neuen Host nutzt.
     *
     * @param list<string> $exclude
     *
     * @return array<string,mixed>|null
     */
    public function failover(string $key, array $exclude): ?array
    {
        $hosts = $this->selectable($exclude);
        if ($hosts === []) {
            return null;
        }
        $host = $this->best($hosts, $this->sessionCounts());
        if ($key !== '') {
            try {
                $this->repository->moveSession(self::hash($key), (string) $host['host'], $this->now());
            } catch (\PDOException) {
                // Zaehler sind Beiwerk: die Umleitung funktioniert auch ohne sie.
            }
        }
        $this->touchHost($host);

        return $host;
    }

    /**
     * Erfolgreiche Antwort: Antwortzeit in das gleitende Mittel uebernehmen.
     *
     * @param array<string,mixed> $host
     */
    public function recordSuccess(array $host, int $durationMs): void
    {
        $id = (int) $host['id'];
        if ($id <= 0) {
            return;
        }
        $durationMs = max(0, $durationMs);
        $samples = min(self::LATENCY_SAMPLES, (int) $host['latency_samples'] + 1);
        $previous = (int) $host['latency_samples'] > 0 ? (int) $host['latency_ms'] : $durationMs;
        $average = (int) round(($previous * ($samples - 1) + $durationMs) / $samples);
        try {
            $this->repository->recordLatency($id, $average, $samples, $durationMs, $this->now());
        } catch (\PDOException) {
            return;
        }
        $this->apply($id, ['latency_ms' => $average, 'latency_samples' => $samples, 'last_latency_ms' => $durationMs, 'last_ok' => 1, 'last_error' => '', 'failures' => 0, 'last_check_at' => $this->now()]);
    }

    /**
     * Fehlgeschlagene Antwort: Host als gestoert markieren.
     *
     * @param array<string,mixed> $host
     */
    public function recordFailure(array $host, string $error): void
    {
        $id = (int) $host['id'];
        if ($id <= 0) {
            return;
        }
        $now = $this->now();
        try {
            $this->repository->recordFailure($id, $error, $now);
        } catch (\PDOException) {
            return;
        }
        $this->apply($id, ['last_ok' => 0, 'last_error' => mb_substr($error, 0, 500), 'last_check_at' => $now, 'failures' => (int) $host['failures'] + 1]);
    }

    /**
     * Kachelwerte des Host-Dashboards (Office -> Orvanta - DAG-Hosts).
     *
     * @return array{hosts:list<array<string,mixed>>,sessions:list<array<string,mixed>>,totals:array<string,int>,generated_at:string}
     */
    public function overview(): array
    {
        $hosts = $this->hosts(true);
        $counts = $this->sessionCounts();
        $sessions = [];
        try {
            $sessions = $this->repository->activeSessions($this->since());
        } catch (\PDOException) {
            $sessions = [];
        }
        $totals = ['hosts' => count($hosts), 'active' => 0, 'online' => 0, 'sessions' => 0];
        $tiles = [];
        foreach ($hosts as $host) {
            $status = self::status($host);
            $sessionsOnHost = $counts[(string) $host['host']] ?? 0;
            $totals['active'] += (int) $host['active'] === 1 ? 1 : 0;
            $totals['online'] += $status === 'online' ? 1 : 0;
            $totals['sessions'] += $sessionsOnHost;
            $tiles[] = $host + [
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status],
                'sessions' => $sessionsOnHost,
                'latency_label' => self::latencyLabel($host),
                'last_latency_label' => (int) $host['latency_samples'] > 0 ? (int) $host['last_latency_ms'] . ' ms' : '–',
                'last_session_label' => self::timeLabel($host['last_session_at']),
                'last_check_label' => self::timeLabel($host['last_check_at']),
                'url' => self::hostUrl($host),
            ];
        }

        return [
            'hosts' => $tiles,
            'sessions' => array_map(static fn (array $row): array => [
                'user' => (string) $row['user_uid'],
                'host' => (string) $row['host'],
                'failovers' => (int) $row['failovers'],
                'requests' => (int) $row['requests'],
                'started_label' => self::timeLabel((string) $row['started_at']),
                'last_seen_label' => self::timeLabel((string) $row['last_seen_at']),
            ], $sessions),
            'totals' => $totals,
            'generated_at' => date('d.m.Y H:i:s'),
        ];
    }

    /**
     * Zerlegt die Eingabe des Adminformulars (ein Host je Zeile, optional mit
     * eigener EWS-Adresse) und prueft die Werte.
     *
     * @return array{hosts:list<array{host:string,url:string}>,errors:list<string>}
     */
    public static function parseHostList(string $raw, int $max = self::MAX_HOSTS): array
    {
        $hosts = [];
        $errors = [];
        $seen = [];
        $gemeldet = [];
        foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/[\s=]+/', $line, 2) ?: [];
            $host = strtolower(trim((string) ($parts[0] ?? ''), " \t\"'"));
            $url = trim((string) ($parts[1] ?? ''), " \t\"'");
            if (!Validator::isHostname($host)) {
                $errors[] = 'Der Eintrag „' . $line . '“ ist kein gültiger Hostname.';
                continue;
            }
            if ($url !== '' && !OrvantaConfigService::isHttpsUrl($url)) {
                $errors[] = 'Die EWS-Adresse zu „' . $host . '“ muss eine vollständige http(s)-Adresse sein.';
                continue;
            }
            if (isset($seen[$host])) {
                if (!isset($gemeldet[$host])) {
                    $errors[] = 'Der Host „' . $host . '“ wurde mehrfach angegeben und nur einmal übernommen.';
                    $gemeldet[$host] = true;
                }
                continue;
            }
            $seen[$host] = true;
            $hosts[] = ['host' => $host, 'url' => $url];
            if (count($hosts) > $max) {
                $errors[] = 'Es lassen sich höchstens ' . $max . ' Hosts einer DAG verwalten.';

                return ['hosts' => array_slice($hosts, 0, $max), 'errors' => $errors];
            }
        }

        return ['hosts' => $hosts, 'errors' => $errors];
    }

    /** Entfernt beendete Sitzungszeilen (Aufraeumen durch den Archivierungs-Worker). */
    public function purge(): int
    {
        try {
            return $this->repository->purgeSessions(date('Y-m-d H:i:s', time() - self::PURGE_AFTER));
        } catch (\PDOException) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ Intern

    /**
     * Alle Hosts einschliesslich derer in Wartung. Fehlt der konfigurierte
     * Server in der Hostliste, wird er einmalig ergaenzt; fehlt die Tabelle
     * (Migration 043 noch nicht ausgefuehrt), gilt nur der konfigurierte Server.
     *
     * @return list<array<string,mixed>>
     */
    private function all(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }
        try {
            $hosts = $this->repository->hosts();
        } catch (\PDOException) {
            return $this->hosts = $this->configuredHost();
        }
        $primary = strtolower(trim($this->config->get('exchange_host')));
        if ($primary !== '') {
            $known = false;
            foreach ($hosts as $host) {
                if (strcasecmp((string) $host['host'], $primary) === 0) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                try {
                    $this->repository->insert($primary, $this->config->ewsUrl(), true, 0);
                    $hosts = $this->repository->hosts();
                } catch (\PDOException) {
                    return $this->hosts = $this->configuredHost();
                }
            }
        }

        return $this->hosts = $hosts;
    }

    /**
     * Der allein aus den Orvanta-Einstellungen bekannte Server (vor der
     * Migration 043 bzw. ohne gepflegte Hostliste).
     *
     * @return list<array<string,mixed>>
     */
    private function configuredHost(): array
    {
        $url = $this->config->ewsUrl();
        $host = strtolower(trim($this->config->get('exchange_host')));
        if ($host === '') {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        }
        if ($host === '' || $url === '') {
            return [];
        }

        return [[
            'id' => 0,
            'host' => $host,
            'ews_url' => $url,
            'is_primary' => 1,
            'active' => 1,
            'sort_order' => 0,
            'latency_ms' => 0,
            'latency_samples' => 0,
            'last_latency_ms' => 0,
            'last_session_at' => null,
            'last_check_at' => null,
            'last_ok' => 1,
            'last_error' => '',
            'failures' => 0,
        ]];
    }

    /**
     * @param list<string> $exclude
     *
     * @return list<array<string,mixed>>
     */
    private function selectable(array $exclude): array
    {
        $skip = array_map('strtolower', $exclude);

        return array_values(array_filter(
            $this->hosts(),
            static fn (array $host): bool => !in_array(strtolower((string) $host['host']), $skip, true)
        ));
    }

    /**
     * Prioritaetenfolge der Verteilung; gestoerte Hosts sind nachrangig und
     * werden nach Ablauf der Wartezeit wieder geprueft (Selbstheilung).
     *
     * @param list<array<string,mixed>> $hosts
     * @param array<string,int> $counts
     *
     * @return array<string,mixed>
     */
    private function best(array $hosts, array $counts): array
    {
        usort($hosts, function (array $a, array $b) use ($counts): int {
            $left = [self::disturbed($a), self::fairUseRank($a), $counts[(string) $a['host']] ?? 0, self::latencyRank($a), (int) $a['sort_order'], (string) $a['host']];
            $right = [self::disturbed($b), self::fairUseRank($b), $counts[(string) $b['host']] ?? 0, self::latencyRank($b), (int) $b['sort_order'], (string) $b['host']];

            return $left <=> $right;
        });

        return $hosts[0];
    }

    /**
     * 1 = vor kurzem gestoert (fuer neue Sitzungen nachrangig), 0 = unauffaellig.
     *
     * @param array<string,mixed> $host
     */
    private static function disturbed(array $host): int
    {
        if ((int) $host['last_ok'] === 1 || $host['last_check_at'] === null) {
            return 0;
        }
        $checked = strtotime((string) $host['last_check_at']);

        return $checked !== false && $checked > time() - self::FAILURE_COOLDOWN ? 1 : 0;
    }

    /**
     * Prioritaet 1 (Fair-use): Zeitpunkt der letzten zugeteilten Sitzung;
     * 0 = hat noch keine Sitzung erhalten und ist damit zuerst an der Reihe.
     *
     * @param array<string,mixed> $host
     */
    private static function fairUseRank(array $host): int
    {
        $last = $host['last_session_at'];
        if (!is_string($last) || $last === '') {
            return 0;
        }

        return (int) strtotime($last);
    }

    /**
     * Prioritaet 3: mittlere Antwortzeit; 0 = noch keine Messung und damit der
     * beste Wert, damit ein neuer Host geprueft wird.
     *
     * @param array<string,mixed> $host
     */
    private static function latencyRank(array $host): int
    {
        return (int) $host['latency_ms'];
    }

    /**
     * @param list<array<string,mixed>> $hosts
     *
     * @return array<string,mixed>
     */
    private function start(string $hash, array $hosts, string $identity): array
    {
        $host = $this->best($hosts, $this->sessionCounts());
        try {
            $this->repository->startSession($hash, $identity, (string) $host['host'], $this->now());
        } catch (\PDOException) {
            return $host;
        }
        $this->touchHost($host);

        return $host;
    }

    /**
     * Leitet eine bestehende Zuordnung auf einen anderen Host um (Host in
     * Wartung, entfernt oder nicht mehr erreichbar).
     *
     * @param list<array<string,mixed>> $hosts
     *
     * @return array<string,mixed>
     */
    private function move(string $hash, array $hosts): array
    {
        $host = $this->best($hosts, $this->sessionCounts());
        try {
            $this->repository->moveSession($hash, (string) $host['host'], $this->now());
        } catch (\PDOException) {
            return $host;
        }
        $this->touchHost($host);

        return $host;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findSession(string $hash): ?array
    {
        try {
            return $this->repository->findSession($hash);
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * @return array<string,int>
     */
    private function sessionCounts(): array
    {
        try {
            return $this->repository->sessionCounts($this->since());
        } catch (\PDOException) {
            return [];
        }
    }

    /** Zeitpunkt der letzten Sitzungszuweisung im lokalen Abbild nachziehen. */
    private function touchHost(array $host): void
    {
        $id = (int) $host['id'];
        if ($id <= 0) {
            return;
        }
        $now = $this->now();
        try {
            $this->repository->touchHostSession($id, $now);
        } catch (\PDOException) {
            return;
        }
        $this->apply($id, ['last_session_at' => $now]);
    }

    /**
     * Uebernimmt geaenderte Kennzahlen in das Abbild der laufenden Anfrage,
     * damit mehrere Aufrufe innerhalb einer Anfrage denselben Stand sehen.
     *
     * @param array<string,mixed> $values
     */
    private function apply(int $id, array $values): void
    {
        foreach ($this->hosts ?? [] as $index => $host) {
            if ((int) $host['id'] === $id) {
                $this->hosts[$index] = array_merge($host, $values);
            }
        }
    }

    /**
     * @param array<string,mixed> $host
     */
    private static function status(array $host): string
    {
        if ((int) $host['active'] !== 1) {
            return 'maintenance';
        }
        if ($host['last_check_at'] === null) {
            return 'unknown';
        }

        return (int) $host['last_ok'] === 1 ? 'online' : 'offline';
    }

    /**
     * @param array<string,mixed> $host
     */
    private static function latencyLabel(array $host): string
    {
        if ((int) $host['latency_samples'] === 0) {
            return '–';
        }

        return 'Ø ' . (int) $host['latency_ms'] . ' ms';
    }

    /**
     * @param array<string,mixed> $host
     */
    private static function hostUrl(array $host): string
    {
        $url = trim((string) $host['ews_url']);

        return $url !== '' ? $url : 'https://' . (string) $host['host'] . '/EWS/Exchange.asmx';
    }

    private static function timeLabel(?string $value): string
    {
        $timestamp = $value !== null && $value !== '' ? strtotime($value) : false;

        return $timestamp === false ? '–' : date('d.m.Y H:i', $timestamp);
    }

    private static function hash(string $key): string
    {
        return sha1('orvanta-dag:' . $key);
    }

    private function since(): string
    {
        return date('Y-m-d H:i:s', time() - self::SESSION_TTL);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
