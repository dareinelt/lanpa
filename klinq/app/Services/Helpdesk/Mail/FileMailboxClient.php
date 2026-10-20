<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

/**
 * Datei-basierte Mailquelle: liest .eml-Dateien aus einem Verzeichnis (z. B. aus einem Postfix-Maildir-
 * Export, Fetchmail oder für Tests) und verschiebt sie nach Verarbeitung in processed/ bzw. failed/.
 */
final class FileMailboxClient implements MailboxClientInterface
{
    public function __construct(private readonly string $directory) {}

    public function describe(): string
    {
        return 'file://' . $this->directory;
    }

    public function fetchUnprocessed(int $limit): array
    {
        if (!is_dir($this->directory)) {
            throw new \RuntimeException('Mail-Verzeichnis nicht gefunden: ' . $this->directory);
        }
        $files = glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        sort($files, SORT_NATURAL);
        $messages = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $messages[basename($file)] = $raw;
            }
        }

        return $messages;
    }

    public function markProcessed(string $uid): void
    {
        $this->moveTo($uid, 'processed');
    }

    /** Unterverzeichnisse der Mailquelle (analog zu IMAP-Ordnern). @return list<string> */
    public function listMailboxes(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $folders = [];
        foreach (glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $folders[] = basename($dir);
        }
        sort($folders, SORT_NATURAL);

        return $folders;
    }

    public function markFailed(string $uid): void
    {
        $this->moveTo($uid, 'failed');
    }

    public function close(): void {}

    private function moveTo(string $uid, string $subdir): void
    {
        $uid = basename($uid);
        $source = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $uid;
        if (!is_file($source)) {
            return;
        }
        $target = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $subdir;
        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException('Zielverzeichnis kann nicht angelegt werden: ' . $target);
        }
        $destination = $target . DIRECTORY_SEPARATOR . $uid;
        if (is_file($destination)) {
            $destination = $target . DIRECTORY_SEPARATOR . pathinfo($uid, PATHINFO_FILENAME) . '-' . date('YmdHis') . '.eml';
        }
        if (!@rename($source, $destination)) {
            throw new \RuntimeException('Mail-Datei kann nicht verschoben werden: ' . $source);
        }
    }
}
