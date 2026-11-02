#!/usr/bin/env php
<?php
/**
 * Minimaler IMAP-Server für Tests (eigener Prozess, damit der blockierende Client laufen kann):
 * nimmt genau eine Verbindung an, beantwortet SEARCH/FETCH/STORE und protokolliert alle Kommandos.
 *
 * Aufruf: php fake-imap-server.php <logdatei> <nachrichtendatei> [--reject-keywords]
 */
declare(strict_types=1);

$logFile = (string) ($argv[1] ?? '');
$messageFile = (string) ($argv[2] ?? '');
$rejectKeywords = in_array('--reject-keywords', $argv, true);
$raw = $messageFile !== '' && is_file($messageFile) ? (string) file_get_contents($messageFile) : '';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, sprintf("Socket-Fehler: %d %s\n", $errno, $errstr));
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
$port = (int) substr((string) strrchr($name, ':'), 1);
fwrite(STDOUT, 'PORT ' . $port . "\n");
fflush(STDOUT);

$deadline = time() + 15;
$client = @stream_socket_accept($server, 10);
if ($client === false) {
    fclose($server);
    exit(1);
}
stream_set_timeout($client, 10);
fwrite($client, "* OK fake IMAP4rev1 ready\r\n");

while (!feof($client) && time() < $deadline) {
    $line = fgets($client);
    if ($line === false) {
        break;
    }
    $line = rtrim($line, "\r\n");
    if ($line === '' || preg_match('/^(\S+)\s+(.*)$/', $line, $m) !== 1) {
        continue;
    }
    [, $tag, $command] = $m;
    if ($logFile !== '') {
        file_put_contents($logFile, $line . "\n", FILE_APPEND);
    }
    $upper = strtoupper($command);
    if (str_starts_with($upper, 'LOGOUT')) {
        fwrite($client, "* BYE\r\n{$tag} OK LOGOUT\r\n");
        break;
    }
    if (str_starts_with($upper, 'UID FETCH')) {
        fwrite($client, "* 1 FETCH (UID 7 BODY[] {" . strlen($raw) . "}\r\n" . $raw . ")\r\n{$tag} OK FETCH\r\n");
        continue;
    }
    if (str_starts_with($upper, 'UID SEARCH')) {
        if ($rejectKeywords && str_contains($upper, 'UNKEYWORD')) {
            fwrite($client, "{$tag} BAD Command Argument Error. 11\r\n");
            continue;
        }
        fwrite($client, "* SEARCH 7\r\n{$tag} OK SEARCH\r\n");
        continue;
    }
    if (str_starts_with($upper, 'UID STORE') && $rejectKeywords && str_contains($command, '$HelpdeskFailed')) {
        fwrite($client, "{$tag} BAD Command Argument Error. 11\r\n");
        continue;
    }
    if (str_starts_with($upper, 'CAPABILITY')) {
        fwrite($client, "* CAPABILITY IMAP4rev1\r\n{$tag} OK CAPABILITY\r\n");
        continue;
    }
    if (str_starts_with($upper, 'SELECT')) {
        fwrite($client, "* 1 EXISTS\r\n{$tag} OK SELECT\r\n");
        continue;
    }
    fwrite($client, "{$tag} OK\r\n");
}

fclose($client);
fclose($server);
