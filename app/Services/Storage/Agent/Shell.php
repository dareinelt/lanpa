<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Fuehrt externe Programme ohne Shell aus (Argumente als Liste) und bricht
 * sie nach einer Zeitgrenze ab – haengende SMB-Verbindungen duerfen den
 * Dienst nicht blockieren.
 */
final class Shell
{
    /**
     * @param list<string> $command
     * @param array<string,string>|null $env
     *
     * @return array{code:int,out:string,err:string}
     */
    public static function run(array $command, int $timeout = 30, ?array $env = null): array
    {
        if ($timeout > 0 && is_executable('/usr/bin/timeout')) {
            $command = array_merge(['/usr/bin/timeout', '-k', '5', (string) $timeout], $command);
        }
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'Programm konnte nicht gestartet werden: ' . $command[0]];
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'out' => $out, 'err' => trim($err)];
    }
}
