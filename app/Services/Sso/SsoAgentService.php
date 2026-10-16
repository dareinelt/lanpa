<?php

declare(strict_types=1);

namespace App\Services\Sso;

/**
 * Anbindung an den Dienst sso-agent (Container mit Docker-Socket).
 *
 * Die Anwendung selbst hat keinen Zugriff auf docker.sock oder das
 * Projektverzeichnis. Deshalb legt sie im gemeinsamen Speicher einen Auftrag
 * (request.json) ab; der Agent erzeugt daraus docker-compose.sso.yml, ergaenzt
 * COMPOSE_FILE in der .env, baut und startet die auth-Instanzen und meldet den
 * Zustand jeder Instanz ueber status.json zurueck.
 *
 * Damit laesst sich eine neue Identitaetsquelle vollstaendig im Adminbereich
 * anwenden - ohne Shell auf dem Server.
 *
 * Dasselbe gilt fuer die Protokolle der Anmelde-Instanzen: der Auftrag steht in
 * logs-request.json, der Agent legt die letzten Eintraege (Overlay) und die
 * letzten 48 Stunden (Download) unter logs/ ab.
 */
final class SsoAgentService
{
    public const ACTIONS = ['apply', 'refresh'];

    /** Ohne Rueckmeldung gilt der Agent nach dieser Zeit als nicht aktiv. */
    private const STALE_REQUEST_SECONDS = 120;

    /** Ohne Protokoll gilt ein Protokollauftrag danach als liegen geblieben. */
    private const LOG_TIMEOUT_SECONDS = 90;

    /** Ohne Meldung gilt der Dienst danach als nicht erreichbar. */
    private const AGENT_SILENT_SECONDS = 300;

    /** So lange laesst sich ein erzeugtes Protokoll ohne neuen Auftrag laden. */
    private const LOG_FRESH_SECONDS = 600;

    /** Eintraege im Overlay (die letzten). */
    public const LOG_TAIL_LINES = 100;

    /** Zeitraum des Downloads in Stunden. */
    public const LOG_DOWNLOAD_HOURS = 48;

    public function __construct(private readonly string $controlDir)
    {
    }

    public function isAgentAvailable(): bool
    {
        return is_dir($this->controlDir) && is_file($this->controlDir . '/status.json');
    }

    /**
     * Zustand des Agenten und aller auth-Instanzen.
     *
     * @return array{available:bool,state:string,action:string,message:string,request_id:string,updated_at:string,project:string,compose_file:string,instances:array<string,array{service:string,state:string,health:string,domain:string,active:bool,enabled:bool|null,kerberos:bool,join:string,ntp:string,notes:list<string>}>,pending:bool,pending_stale:bool,running:bool}
     */
    public function status(): array
    {
        $status = [
            'available' => false,
            'state' => 'unknown',
            'action' => '',
            'message' => '',
            'request_id' => '',
            'updated_at' => '',
            'project' => '',
            'compose_file' => '',
            'instances' => [],
            'pending' => false,
            'pending_stale' => false,
            'running' => false,
        ];

        $file = $this->controlDir . '/status.json';
        if (is_readable($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $status['available'] = true;
                foreach (['state', 'action', 'message', 'request_id', 'updated_at', 'project', 'compose_file'] as $key) {
                    $status[$key] = is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
                }
                foreach ((array) ($data['instances'] ?? []) as $instance) {
                    if (!is_array($instance) || !is_string($instance['service'] ?? null)) {
                        continue;
                    }
                    $notes = [];
                    foreach ((array) ($instance['notes'] ?? []) as $note) {
                        if (is_scalar($note)) {
                            $notes[] = (string) $note;
                        }
                    }
                    $status['instances'][$instance['service']] = [
                        'service' => $instance['service'],
                        'state' => (string) ($instance['state'] ?? 'unknown'),
                        'health' => (string) ($instance['health'] ?? ''),
                        'domain' => (string) ($instance['domain'] ?? ''),
                        'active' => (bool) ($instance['active'] ?? false),
                        'enabled' => is_bool($instance['enabled'] ?? null) ? $instance['enabled'] : null,
                        'kerberos' => (bool) ($instance['kerberos'] ?? false),
                        'join' => (string) ($instance['join'] ?? 'unknown'),
                        'ntp' => (string) ($instance['ntp'] ?? ''),
                        'notes' => $notes,
                    ];
                }
            }
        }

        $status['running'] = $status['state'] === 'running';
        $request = $this->controlDir . '/request.json';
        if (is_file($request)) {
            $status['pending'] = true;
            $status['pending_stale'] = time() - (int) filemtime($request) > self::STALE_REQUEST_SECONDS;
        }

        return $status;
    }

    /**
     * Zustand einer einzelnen Instanz (Dienstname, z. B. "auth" oder
     * "auth-hamburg").
     *
     * @return array{service:string,state:string,health:string,domain:string,active:bool,enabled:bool|null,kerberos:bool,join:string,ntp:string,notes:list<string>}|null
     */
    public function instanceStatus(string $service): ?array
    {
        return $this->status()['instances'][$service] ?? null;
    }

    /**
     * Legt einen Auftrag fuer den Agenten ab.
     *
     * @throws \RuntimeException wenn kein Auftrag angenommen werden kann
     */
    public function request(string $action): string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \RuntimeException('Unbekannter Auftrag.');
        }
        if (!$this->isAgentAvailable()) {
            throw new \RuntimeException('Der Dienst für die Windows-Anmeldung (sso-agent) ist nicht aktiv. Bitte den Container starten (docker compose up -d sso-agent) oder die Instanzen auf dem Server erneuern (./scripts/sso-domains.sh).');
        }

        $status = $this->status();
        if ($status['running']) {
            throw new \RuntimeException('Die Windows-Anmeldung wird bereits angewendet.');
        }
        if ($status['pending'] && !$status['pending_stale']) {
            throw new \RuntimeException('Es liegt bereits ein Auftrag vor.');
        }

        $id = bin2hex(random_bytes(8));
        $json = json_encode(['action' => $action, 'id' => $id, 'requested_at' => gmdate('c')], JSON_THROW_ON_ERROR);

        $tmp = $this->controlDir . '/.request.' . $id . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->controlDir . '/request.json')) {
            @unlink($tmp);
            throw new \RuntimeException('Der Auftrag konnte nicht abgelegt werden (Schreibrechte?).');
        }

        return $id;
    }

    /**
     * Ob ein Dienstname zu den Anmelde-Instanzen gehoert ("auth" oder
     * "auth-<kennung>"). Der Name geht in Auftrag und Dateinamen ein.
     */
    public function isAuthService(string $service): bool
    {
        return preg_match('/^auth(-[a-z0-9]+)*$/', $service) === 1;
    }

    /**
     * Fordert das Protokoll einer Anmelde-Instanz an: die letzten 100 Eintraege
     * fuer das Overlay und die letzten 48 Stunden fuer den Download. Der Agent
     * liest sie ueber den Docker-Socket und legt sie im Austauschverzeichnis ab.
     *
     * Ein laufendes "anwenden" blockiert nicht: die Instanz laeuft dabei ohnehin,
     * und gerade dann hilft das Protokoll.
     *
     * @throws \RuntimeException wenn kein Auftrag angenommen werden kann
     */
    public function requestLogs(string $service): string
    {
        if (!$this->isAuthService($service)) {
            throw new \RuntimeException('Unbekannte Anmelde-Instanz.');
        }
        if (!$this->isAgentAvailable()) {
            throw new \RuntimeException('Der Dienst für die Windows-Anmeldung (sso-agent) ist nicht aktiv. Ohne ihn lassen sich die Protokolle der Anmelde-Instanzen nicht lesen.');
        }

        $id = bin2hex(random_bytes(8));
        $json = json_encode([
            'action' => 'logs',
            'service' => $service,
            'id' => $id,
            'requested_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR);

        $tmp = $this->controlDir . '/.logs-request.' . $id . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false
            || !@rename($tmp, $this->controlDir . '/logs-request.json')) {
            @unlink($tmp);
            throw new \RuntimeException('Der Auftrag konnte nicht abgelegt werden (Schreibrechte?).');
        }

        return $id;
    }

    /**
     * Ob bereits ein Protokollauftrag auf seine Abholung wartet.
     */
    public function hasPendingLogsRequest(): bool
    {
        $mtime = @filemtime($this->controlDir . '/logs-request.json');

        return $mtime !== false && time() - $mtime <= self::LOG_TIMEOUT_SECONDS;
    }

    /**
     * Ergebnis eines Protokollauftrags. Ohne Auftragskennung genuegt ein
     * frisches Ergebnis (Download-Link auf der Uebersicht).
     *
     * @return array{state:string,message:string,generated_at:string,lines:int,tail:string,download_ready:bool,download_name:string,download_bytes:int}
     */
    public function logsResult(string $service, string $requestId = ''): array
    {
        $result = [
            'state' => 'pending',
            'message' => '',
            'generated_at' => '',
            'lines' => 0,
            'tail' => '',
            'download_ready' => false,
            'download_name' => '',
            'download_bytes' => 0,
        ];
        if (!$this->isAuthService($service)) {
            $result['state'] = 'error';
            $result['message'] = 'Unbekannte Anmelde-Instanz.';

            return $result;
        }

        $meta = $this->logsMeta($service);
        $matches = $meta !== null && ($requestId !== ''
            ? (string) ($meta['request_id'] ?? '') === $requestId
            : $this->isFresh((string) ($meta['generated_at'] ?? ''), self::LOG_FRESH_SECONDS));

        if ($matches) {
            $generatedAt = (string) ($meta['generated_at'] ?? '');
            $result['generated_at'] = $generatedAt;
            $error = (string) ($meta['error'] ?? '');
            if ($error !== '') {
                $result['state'] = 'error';
                $result['message'] = $error;

                return $result;
            }
            $result['state'] = 'ready';
            $result['lines'] = (int) ($meta['lines'] ?? 0);
            $result['download_bytes'] = (int) ($meta['download_bytes'] ?? 0);
            $result['tail'] = (string) @file_get_contents($this->logPath($service, 'tail'));
            $result['download_ready'] = is_file($this->logPath($service, 'download'));
            $result['download_name'] = $this->logDownloadName($service, $generatedAt);

            return $result;
        }

        if ($this->isAgentSilent() || $this->isLogsRequestStale()) {
            $result['state'] = 'error';
            $result['message'] = 'Der Dienst sso-agent hat innerhalb von ' . self::LOG_TIMEOUT_SECONDS
                . ' Sekunden kein Protokoll geliefert. Läuft der Dienst und ist der Docker-Socket durchgereicht?';
        }

        return $result;
    }

    /**
     * Inhalt des Downloads (letzte 48 Stunden) oder null, wenn keiner vorliegt.
     */
    public function logDownload(string $service): ?string
    {
        if (!$this->isAuthService($service)) {
            return null;
        }
        $path = $this->logPath($service, 'download');
        if (!is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function logsMeta(string $service): ?array
    {
        $path = $this->logPath($service, 'meta');
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function logPath(string $service, string $kind): string
    {
        $suffix = match ($kind) {
            'tail' => '.tail.log',
            'download' => '.download.log',
            default => '.json',
        };

        return $this->controlDir . '/logs/' . $service . $suffix;
    }

    private function logDownloadName(string $service, string $generatedAt): string
    {
        $time = strtotime($generatedAt);

        return $service . '-protokoll-' . gmdate('Y-m-d', $time === false ? time() : $time) . '.log';
    }

    private function isFresh(string $timestamp, int $seconds): bool
    {
        $time = strtotime($timestamp);

        return $time !== false && time() - $time <= $seconds;
    }

    /**
     * Ob der Dienst laenger nicht gemeldet hat (Schleife steht oder der
     * Docker-Socket fehlt). Waehrend eines Auftrags meldet er bewusst nicht.
     */
    private function isAgentSilent(): bool
    {
        $mtime = @filemtime($this->controlDir . '/status.json');
        if ($mtime === false) {
            return true;
        }
        if ($this->status()['running']) {
            return false;
        }

        return time() - $mtime > self::AGENT_SILENT_SECONDS;
    }

    /**
     * Ob ein Protokollauftrag liegen geblieben ist (der Dienst hat ihn nicht
     * abgeholt).
     */
    private function isLogsRequestStale(): bool
    {
        $mtime = @filemtime($this->controlDir . '/logs-request.json');

        return $mtime !== false && time() - $mtime > self::LOG_TIMEOUT_SECONDS;
    }
}
