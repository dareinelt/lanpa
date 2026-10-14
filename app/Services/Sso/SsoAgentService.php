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
 */
final class SsoAgentService
{
    public const ACTIONS = ['apply', 'refresh'];

    /** Ohne Rueckmeldung gilt der Agent nach dieser Zeit als nicht aktiv. */
    private const STALE_REQUEST_SECONDS = 120;

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
}
