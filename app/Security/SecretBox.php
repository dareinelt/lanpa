<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Verschluesselung von Zugangsdaten, die in der Datenbank gespeichert werden
 * (AD-Bind-Passwoerter, Konten fuer den Domaenenbeitritt).
 *
 * Verfahren: libsodium secretbox (XSalsa20-Poly1305, authentifiziert). Der
 * Schluessel liegt ausserhalb der Datenbank in storage/keys/secrets.key und
 * wird beim ersten Gebrauch automatisch erzeugt (0600). Eine Datenbank-
 * Sicherung allein gibt die Zugangsdaten damit nicht preis.
 *
 * Format: "enc:v1:" . base64(nonce . ciphertext)
 */
final class SecretBox
{
    public const PREFIX = 'enc:v1:';

    private ?string $key = null;

    public function __construct(private readonly string $keyFile)
    {
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Entschluesselt einen Wert; null, wenn er leer, beschaedigt oder mit einem
     * anderen Schluessel verschluesselt ist (z. B. Sicherung einer anderen
     * Installation).
     */
    public function decrypt(?string $value): ?string
    {
        $value = (string) $value;
        if (!self::isEncrypted($value)) {
            return null;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key()
            );
        } catch (\SodiumException) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /**
     * Stellt sicher, dass der Schluessel existiert (z. B. beim Containerstart).
     */
    public function ensureKey(): void
    {
        $this->key();
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        if (!is_file($this->keyFile)) {
            $this->createKey();
        }

        $encoded = @file_get_contents($this->keyFile);
        $key = $encoded === false ? false : base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Der Schlüssel für Zugangsdaten ist ungültig oder nicht lesbar: ' . $this->keyFile);
        }

        return $this->key = $key;
    }

    private function createKey(): void
    {
        $dir = dirname($this->keyFile);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Schlüsselverzeichnis kann nicht angelegt werden: ' . $dir);
        }

        // Erst vollstaendig in eine temporaere Datei schreiben, dann atomar
        // verlinken – laufen app und sync gleichzeitig an, gewinnt einer.
        $tmp = $this->keyFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, base64_encode(sodium_crypto_secretbox_keygen()) . "\n") === false) {
            throw new RuntimeException('Schlüsseldatei kann nicht angelegt werden: ' . $this->keyFile);
        }
        @chmod($tmp, 0600);
        // Als root (z. B. "docker compose exec app ...") angelegt: dem Eigentuemer
        // des Speicherverzeichnisses (www-data) uebergeben, damit der Webserver
        // den Schluessel lesen kann.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $owner = @fileowner(dirname($dir));
            $group = @filegroup(dirname($dir));
            if ($owner !== false && $group !== false) {
                @chown($tmp, $owner);
                @chgrp($tmp, $group);
                @chown($dir, $owner);
                @chgrp($dir, $group);
            }
        }
        $linked = @link($tmp, $this->keyFile);
        @unlink($tmp);
        if (!$linked && !is_file($this->keyFile)) {
            throw new RuntimeException('Schlüsseldatei kann nicht angelegt werden: ' . $this->keyFile);
        }
    }
}
