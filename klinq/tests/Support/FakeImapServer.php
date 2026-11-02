<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Startet fake-imap-server.php als eigenen Prozess (ohne pcntl, auch unter Windows) und stellt
 * Port sowie das Kommando-Protokoll bereit.
 */
final class FakeImapServer
{
    /** @var resource */
    private $process;
    /** @var array<int,resource> */
    private array $pipes = [];
    private string $logFile;
    private string $messageFile;
    public readonly int $port;

    public function __construct(string $message, bool $rejectKeywords = false)
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'klinq-imap-log-');
        $this->messageFile = (string) tempnam(sys_get_temp_dir(), 'klinq-imap-msg-');
        file_put_contents($this->messageFile, $message);

        $command = [PHP_BINARY, __DIR__ . '/fake-imap-server.php', $this->logFile, $this->messageFile];
        if ($rejectKeywords) {
            $command[] = '--reject-keywords';
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Fake-IMAP-Server konnte nicht gestartet werden.');
        }
        $this->process = $process;
        $this->pipes = $pipes;

        $line = trim((string) fgets($pipes[1]));
        if (preg_match('/^PORT (\d+)$/', $line, $m) !== 1) {
            $error = trim((string) stream_get_contents($pipes[2]));
            $this->stop();
            throw new \RuntimeException('Fake-IMAP-Server nicht bereit: ' . $line . ' ' . $error);
        }
        $this->port = (int) $m[1];
    }

    /** @return list<string> Empfangene Kommandos ohne Tag */
    public function commands(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }
        $commands = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^A\d{4}\s+(.*)$/', $line, $m) === 1) {
                $commands[] = $m[1];
            }
        }

        return $commands;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (isset($this->process) && is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->logFile);
        @unlink($this->messageFile);
    }
}
