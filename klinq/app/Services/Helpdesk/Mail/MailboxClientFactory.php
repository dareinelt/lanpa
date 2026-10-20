<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

/**
 * Erzeugt den passenden Postfach-Client zu einer Konfiguration aus der Administration.
 * Wird sowohl vom Container (gespeicherte Einstellungen) als auch vom Verbindungstest
 * (noch nicht gespeicherte Formularwerte) genutzt.
 */
final class MailboxClientFactory
{
    /** @param array<string,mixed> $options */
    public static function create(array $options, string $basePath): MailboxClientInterface
    {
        if ((string) ($options['driver'] ?? 'imap') === 'file') {
            return new FileMailboxClient(self::resolvePath((string) ($options['file_path'] ?? ''), $basePath));
        }

        return new ImapMailboxClient($options);
    }

    /** Relative Pfade beziehen sich auf das Projektverzeichnis. */
    public static function resolvePath(string $path, string $basePath): string
    {
        $path = trim($path) !== '' ? trim($path) : 'storage/mail-inbox';
        $isAbsolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;

        return $isAbsolute ? $path : rtrim($basePath, '/\\') . '/' . ltrim($path, '/\\');
    }
}
