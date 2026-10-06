<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

/**
 * Datei-Cache fuer aufgeloeste Postfach-Zuordnungen (SSO-Benutzer ->
 * Identitaetsquelle -> Zuordnung -> Postfach). Gespeichert werden nur
 * Kennungen und Zustaende, niemals Zugangsdaten.
 *
 * Gueltigkeit: Ein Eintrag gilt nur, solange (1) die TTL nicht abgelaufen
 * ist und (2) die beim Schreiben gemerkte Konfigurations-Generation der
 * aktuellen entspricht (mail_proxy_state.generation). Jede Aenderung im
 * Adminbereich erhoeht die Generation und leert zusaetzlich das Verzeichnis;
 * selbst ein nicht loeschbarer Eintrag wird damit nie wieder verwendet.
 */
final class MailProxyCache
{
    public const DEFAULT_TTL = 300;

    /** @var \Closure():int */
    private readonly \Closure $clock;

    /** @var array<string,array{generation:int,expires:int,value:array<string,mixed>}> Pro Request */
    private array $memory = [];

    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = self::DEFAULT_TTL,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $key, int $generation): ?array
    {
        if ($this->ttl <= 0 || $generation <= 0) {
            return null;
        }
        $entry = $this->memory[$key] ?? $this->read($key);
        if ($entry === null || $entry['generation'] !== $generation || $entry['expires'] <= ($this->clock)()) {
            unset($this->memory[$key]);

            return null;
        }
        $this->memory[$key] = $entry;

        return $entry['value'];
    }

    /**
     * @param array<string,mixed> $value
     */
    public function put(string $key, int $generation, array $value): void
    {
        if ($this->ttl <= 0 || $generation <= 0) {
            return;
        }
        $entry = ['generation' => $generation, 'expires' => ($this->clock)() + $this->ttl, 'value' => $value];
        $this->memory[$key] = $entry;
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            return;
        }
        $file = $this->file($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($entry, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @chmod($tmp, 0o660);
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Entfernt alle Eintraege (nach Aenderungen im Adminbereich).
     */
    public function clear(): int
    {
        $this->memory = [];
        $removed = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * @return array{generation:int,expires:int,value:array<string,mixed>}|null
     */
    private function read(string $key): ?array
    {
        $raw = @file_get_contents($this->file($key));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_int($data['generation'] ?? null) || !is_int($data['expires'] ?? null) || !is_array($data['value'] ?? null)) {
            return null;
        }

        return ['generation' => $data['generation'], 'expires' => $data['expires'], 'value' => $data['value']];
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.json';
    }
}
