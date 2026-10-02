<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Liefert Aenderungshinweise per inotifywait, damit neue und geaenderte
 * Dateien sofort (statt erst beim vollstaendigen Abgleich) synchronisiert
 * werden. Faellt inotify aus (z. B. zu wenige Watches), arbeitet storage-sync
 * mit haeufigeren vollstaendigen Abgleichen weiter.
 */
final class InotifyWatcher
{
    private const EXCLUDE = '(/appdata_[^/]+/(preview|css|js)/|/\.lanpa-recall/|\.lanpa-tmp$|\.part$|/nextcloud\.log$)';

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private string $buffer = '';

    private string $error = '';

    /**
     * @param array<string,string> $sources Quelle => Verzeichnis
     */
    public function __construct(private readonly array $sources)
    {
    }

    public function start(): bool
    {
        $this->stop();
        $dirs = array_values(array_filter($this->sources, 'is_dir'));
        if ($dirs === [] || !is_executable('/usr/bin/inotifywait')) {
            $this->error = 'inotifywait nicht verfügbar.';

            return false;
        }
        $command = array_merge(
            ['/usr/bin/inotifywait', '-m', '-r', '-q', '--format', '%e|%w%f',
                '-e', 'close_write,moved_to,moved_from,delete,create,attrib', '--exclude', self::EXCLUDE],
            $dirs
        );
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $this->error = 'inotifywait konnte nicht gestartet werden.';

            return false;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->process = $process;
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        $this->error = '';

        return true;
    }

    public function running(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->error = trim((string) @stream_get_contents($this->stderr)) ?: 'inotifywait wurde beendet.';

            return false;
        }

        return true;
    }

    public function error(): string
    {
        return $this->error;
    }

    /**
     * Wartet bis zu $timeout Sekunden auf Ereignisse und liefert die
     * betroffenen Pfade je Quelle.
     *
     * @return array<string,list<string>>
     */
    public function collect(float $timeout): array
    {
        if ($this->stdout === null) {
            usleep((int) ($timeout * 1e6));

            return [];
        }
        $read = [$this->stdout];
        $write = $except = null;
        $seconds = (int) $timeout;
        if (@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1e6)) > 0) {
            // Kurz weitere Ereignisse sammeln (z. B. viele Dateien eines Uploads).
            usleep(300000);
            $this->buffer .= (string) @stream_get_contents($this->stdout);
        }
        if ($this->stderr !== null) {
            @stream_get_contents($this->stderr);
        }

        $hints = [];
        $lines = explode("\n", $this->buffer);
        $this->buffer = (string) array_pop($lines);
        foreach ($lines as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            foreach ($this->sources as $source => $root) {
                $rel = PathRules::relative($root, $parts[1]);
                if ($rel !== null) {
                    $hints[$source][] = $rel;
                    break;
                }
            }
        }

        return array_map(static fn (array $paths): array => array_values(array_unique($paths)), $hints);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = $this->stdout = $this->stderr = null;
        $this->buffer = '';
    }
}
