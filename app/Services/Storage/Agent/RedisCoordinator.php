<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use RuntimeException;

/**
 * Koordinator ueber Redis (Container storage-sync-redis, nur im internen Netz
 * storage_catalog erreichbar). Schlanker RESP-Client ohne PHP-Erweiterung.
 *
 * - exclusive(): Lease-Sperre (SET NX PX + Freigabe per Lua nur mit eigenem
 *   Token). Laeuft eine Transaktion laenger als die Lease, gibt Redis die
 *   Sperre frei - schlimmstenfalls bricht MySQL dann einen Deadlock ab; die
 *   Daten bleiben konsistent.
 * - Zaehler: HINCRBY auf einem Hash (atomar, ohne Zeilensperren in MySQL).
 *
 * Ist Redis nicht erreichbar, arbeitet der Koordinator 30 s lang ohne Redis
 * weiter (keine Sperre, Zaehler verworfen) und versucht es dann erneut.
 */
final class RedisCoordinator implements CatalogCoordinator
{
    private const PREFIX = 'storage-sync:';
    private const COUNTERS = self::PREFIX . 'counters';
    private const FIELDS = ['rb' => 'read_bytes', 'wb' => 'write_bytes', 'ro' => 'read_ops', 'wo' => 'write_ops'];
    private const RELEASE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
    private const RETRY_AFTER = 30.0;

    /** @var resource|null */
    private $socket = null;

    private float $downUntil = 0.0;

    /** @var array<string,int> Sperrname => Verschachtelungstiefe */
    private array $held = [];

    /**
     * @param int $leaseMs Haltedauer einer Sperre (danach gibt Redis sie frei)
     * @param int $waitMs Hoechstens so lange auf eine Sperre warten, danach ohne Sperre fortfahren
     * @param (callable(string):void)|null $log
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 6379,
        private readonly string $password = '',
        private readonly int $leaseMs = 300000,
        private readonly int $waitMs = 120000,
        private $log = null,
    ) {
    }

    public function exclusive(string $name, callable $callback): mixed
    {
        if (isset($this->held[$name])) {
            $this->held[$name]++;
            try {
                return $callback();
            } finally {
                $this->held[$name]--;
            }
        }
        $key = self::PREFIX . 'lock:' . $name;
        $token = bin2hex(random_bytes(16));
        $locked = $this->acquire($key, $token);
        $this->held[$name] = 1;
        try {
            return $callback();
        } finally {
            unset($this->held[$name]);
            if ($locked) {
                $this->call(['EVAL', self::RELEASE, '1', $key, $token]);
            }
        }
    }

    public function addCounters(int $targetId, int $readBytes, int $writeBytes, int $readOps, int $writeOps): void
    {
        $commands = [];
        foreach (['rb' => $readBytes, 'wb' => $writeBytes, 'ro' => $readOps, 'wo' => $writeOps] as $field => $value) {
            if ($value !== 0) {
                $commands[] = ['HINCRBY', self::COUNTERS, $targetId . ':' . $field, (string) $value];
            }
        }
        if ($commands !== []) {
            $this->pipeline($commands);
        }
    }

    public function counters(): array
    {
        $reply = $this->call(['HGETALL', self::COUNTERS]);
        $result = [];
        if (!is_array($reply)) {
            return $result;
        }
        for ($i = 0; $i + 1 < count($reply); $i += 2) {
            [$id, $field] = explode(':', (string) $reply[$i], 2) + [1 => ''];
            if (!isset(self::FIELDS[$field])) {
                continue;
            }
            $result[(int) $id] ??= ['read_bytes' => 0, 'write_bytes' => 0, 'read_ops' => 0, 'write_ops' => 0];
            $result[(int) $id][self::FIELDS[$field]] = (int) $reply[$i + 1];
        }
        ksort($result);

        return $result;
    }

    public function forgetCounters(array $keep): void
    {
        $fields = $this->call(['HKEYS', self::COUNTERS]);
        if (!is_array($fields)) {
            return;
        }
        $drop = [];
        foreach ($fields as $field) {
            $id = (int) explode(':', (string) $field, 2)[0];
            if ($id > 0 && !in_array($id, $keep, true)) {
                $drop[] = (string) $field;
            }
        }
        if ($drop !== []) {
            $this->call(array_merge(['HDEL', self::COUNTERS], $drop));
        }
    }

    /**
     * Erreichbarkeit (Einzelbefehl catalog-status, Entrypoint).
     */
    public function ping(): bool
    {
        return $this->call(['PING']) === 'PONG';
    }

    private function acquire(string $key, string $token): bool
    {
        $deadline = microtime(true) + $this->waitMs / 1000;
        $sleep = 1000;
        while (true) {
            $reply = $this->call(['SET', $key, $token, 'NX', 'PX', (string) $this->leaseMs]);
            if ($reply === 'OK') {
                return true;
            }
            if ($reply === null && $this->socket === null) {
                // Redis nicht erreichbar: ohne Sperre weiter (MySQL bleibt konsistent).
                return false;
            }
            if (microtime(true) >= $deadline) {
                $this->warn('Katalogsperre „' . $key . '“ nach ' . intdiv($this->waitMs, 1000) . ' s nicht frei - fahre ohne Sperre fort.');

                return false;
            }
            usleep($sleep);
            $sleep = min(50000, $sleep * 2);
        }
    }

    /**
     * @param list<string> $command
     */
    private function call(array $command): mixed
    {
        $replies = $this->pipeline([$command]);

        return $replies[0] ?? null;
    }

    /**
     * @param list<list<string>> $commands
     *
     * @return list<mixed>
     */
    private function pipeline(array $commands): array
    {
        if (!$this->connect()) {
            return [];
        }
        try {
            $payload = '';
            foreach ($commands as $command) {
                $payload .= self::encode($command);
            }
            $this->write($payload);
            $replies = [];
            foreach ($commands as $_) {
                $replies[] = $this->read();
            }

            return $replies;
        } catch (RuntimeException $exception) {
            $this->down($exception->getMessage());

            return [];
        }
    }

    private function connect(): bool
    {
        if ($this->socket !== null) {
            return true;
        }
        if (microtime(true) < $this->downUntil) {
            return false;
        }
        $socket = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $error, 2.0);
        if ($socket === false) {
            $this->down('Redis ' . $this->host . ':' . $this->port . ' nicht erreichbar (' . $error . ')');

            return false;
        }
        stream_set_timeout($socket, 10);
        $this->socket = $socket;
        if ($this->password !== '') {
            try {
                $this->write(self::encode(['AUTH', $this->password]));
                $this->read();
            } catch (RuntimeException $exception) {
                $this->down($exception->getMessage());

                return false;
            }
        }

        return true;
    }

    private function down(string $reason): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        if (microtime(true) >= $this->downUntil) {
            $this->warn($reason . ' - Katalog arbeitet vorerst ohne Redis-Helfer.');
        }
        $this->downUntil = microtime(true) + self::RETRY_AFTER;
    }

    private function warn(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }

    /**
     * @param list<string> $command
     */
    private static function encode(array $command): string
    {
        $out = '*' . count($command) . "\r\n";
        foreach ($command as $part) {
            $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        return $out;
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($this->socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Schreiben an Redis fehlgeschlagen');
            }
            $data = substr($data, $written);
        }
    }

    private function line(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException('Keine Antwort von Redis');
        }

        return substr($line, 0, -2);
    }

    private function read(): mixed
    {
        $line = $this->line();
        $type = $line[0] ?? '';
        $rest = substr($line, 1);

        switch ($type) {
            case '+':
                return $rest;
            case '-':
                throw new RuntimeException('Redis: ' . $rest);
            case ':':
                return (int) $rest;
            case '$':
                $length = (int) $rest;
                if ($length < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $chunk = fread($this->socket, $length + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Antwort von Redis unvollstaendig');
                    }
                    $data .= $chunk;
                }

                return substr($data, 0, $length);
            case '*':
                $count = (int) $rest;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->read();
                }

                return $items;
            default:
                throw new RuntimeException('Unbekannte Antwort von Redis');
        }
    }
}
