<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Verschlüsselung für Zugangsdaten, die in der Datenbank abgelegt werden (z. B. das Passwort des
 * Help-Desk-Postfachs, das komplett in der Anwendung konfiguriert wird).
 *
 * Verfahren: AES-256-GCM mit zufälligem IV; gespeichert wird „enc:v1:base64(iv|tag|ciphertext)“.
 * Der Schlüssel stammt aus APP_KEY; ist die Variable leer, wird er aus stabilen Umgebungswerten
 * abgeleitet (DB-Passwort, App-URL). Ändern sich diese Werte ohne gesetztes APP_KEY, lässt sich ein
 * gespeichertes Geheimnis nicht mehr entschlüsseln – der Wert muss dann in der Oberfläche neu erfasst
 * werden (decrypt() liefert in diesem Fall null statt eines unbrauchbaren Klartexts).
 */
final class Secret
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $keyMaterial) {}

    /** Schlüsselmaterial aus der Umgebung (APP_KEY bevorzugt). */
    public static function fromEnvironment(string $appKey, string $fallback): self
    {
        $material = trim($appKey) !== '' ? trim($appKey) : $fallback;

        return new self($material !== '' ? $material : 'assets-default-key');
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Geheimnis konnte nicht verschlüsselt werden.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Entschlüsselt einen gespeicherten Wert; null, wenn er nicht (mehr) lesbar ist. */
    public function decrypt(string $value): ?string
    {
        if ($value === '') {
            return '';
        }
        if (!self::isEncrypted($value)) {
            // Altbestand aus einer Zeit ohne Verschlüsselung bzw. manuell gesetzter Wert
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private function key(): string
    {
        return hash('sha256', 'assets:' . $this->keyMaterial, true);
    }
}
