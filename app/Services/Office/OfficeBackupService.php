<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Anbindung an den Sicherungs-Agenten (Container office-backup).
 *
 * Die Anwendung erhaelt bewusst keinen Zugriff auf docker.sock oder die
 * Office-Datenbanken. Stattdessen legt sie im gemeinsamen Speicher einen
 * Auftrag (request.json) ab; der Agent fuehrt die Sicherung aus und meldet den
 * Stand ueber status.json zurueck.
 */
final class OfficeBackupService
{
    public const ACTIONS = ['backup', 'refresh'];

    /** Ohne Rueckmeldung gilt der Agent nach dieser Zeit als nicht aktiv. */
    private const STALE_REQUEST_SECONDS = 60;

    public function __construct(private readonly string $controlDir)
    {
    }

    public function isAgentAvailable(): bool
    {
        return is_dir($this->controlDir) && is_file($this->controlDir . '/status.json');
    }

    /**
     * @return array{available:bool,state:string,action:string,message:string,request_id:string,updated_at:string,retention:int,encryption:bool,backups:list<array{name:string,size:int,created:string,encrypted:bool}>,pending:bool,pending_stale:bool}
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
            'retention' => 0,
            'encryption' => false,
            'backups' => [],
            'pending' => false,
            'pending_stale' => false,
        ];

        $file = $this->controlDir . '/status.json';
        if (is_readable($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $status['available'] = true;
                foreach (['state', 'action', 'message', 'request_id', 'updated_at'] as $key) {
                    $status[$key] = is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
                }
                $status['retention'] = (int) ($data['retention'] ?? 0);
                $status['encryption'] = (bool) ($data['encryption'] ?? false);
                foreach ((array) ($data['backups'] ?? []) as $backup) {
                    if (!is_array($backup) || !is_string($backup['name'] ?? null)) {
                        continue;
                    }
                    $status['backups'][] = [
                        'name' => $backup['name'],
                        'size' => (int) ($backup['size'] ?? 0),
                        'created' => (string) ($backup['created'] ?? ''),
                        'encrypted' => (bool) ($backup['encrypted'] ?? false),
                    ];
                }
                usort($status['backups'], static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
            }
        }

        $request = $this->controlDir . '/request.json';
        if (is_file($request)) {
            $status['pending'] = true;
            $status['pending_stale'] = time() - (int) filemtime($request) > self::STALE_REQUEST_SECONDS;
        }

        return $status;
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
            throw new \RuntimeException('Der Sicherungsdienst (office-backup) ist nicht aktiv.');
        }

        $status = $this->status();
        if ($status['state'] === 'running') {
            throw new \RuntimeException('Es läuft bereits eine Sicherung.');
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
