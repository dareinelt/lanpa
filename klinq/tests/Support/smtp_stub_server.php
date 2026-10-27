<?php

declare(strict_types=1);

/**
 * Minimaler SMTP-Server für Tests (Aufruf: php smtp_stub_server.php <logdatei> <port>).
 *
 * Nimmt beliebig viele Verbindungen an, beantwortet EHLO, AUTH LOGIN, MAIL FROM, RCPT TO, DATA
 * und QUIT und schreibt den Inhalt jeder angenommenen Nachricht in die Logdatei. STARTTLS wird
 * bewusst abgelehnt, damit Tests den unverschlüsselten Weg (internes Relay) prüfen können.
 */
$logFile = (string) ($argv[1] ?? '');
$port = (int) ($argv[2] ?? 0);
if ($logFile === '' || $port < 1) {
    fwrite(STDERR, "Aufruf: smtp_stub_server.php <logdatei> <port>\n");
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    fwrite(STDERR, 'Server konnte nicht gestartet werden: ' . $error . "\n");
    exit(1);
}
fwrite(STDOUT, "bereit\n");
fflush(STDOUT);

while (true) {
    $client = @stream_socket_accept($server, 60);
    if ($client === false) {
        continue;
    }
    fwrite($client, "220 stub.test ESMTP\r\n");
    $collecting = false;
    $buffer = '';
    while (($line = fgets($client, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($collecting) {
            if ($line === '.') {
                file_put_contents($logFile, $buffer, FILE_APPEND);
                $collecting = false;
                $buffer = '';
                fwrite($client, "250 2.0.0 Ok: queued as STUB\r\n");
                continue;
            }
            $buffer .= (str_starts_with($line, '..') ? substr($line, 1) : $line) . "\n";
            continue;
        }
        $upper = strtoupper($line);
        if (str_starts_with($upper, 'EHLO')) {
            fwrite($client, "250-stub.test\r\n250-AUTH LOGIN\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n");
        } elseif (str_starts_with($upper, 'HELO')) {
            fwrite($client, "250 stub.test\r\n");
        } elseif ($upper === 'AUTH LOGIN') {
            fwrite($client, "334 VXNlcm5hbWU6\r\n");
            fgets($client, 4096);
            fwrite($client, "334 UGFzc3dvcmQ6\r\n");
            fgets($client, 4096);
            fwrite($client, "235 2.7.0 Authentication successful\r\n");
        } elseif (str_starts_with($upper, 'MAIL FROM') || str_starts_with($upper, 'RCPT TO')) {
            fwrite($client, "250 2.1.0 Ok\r\n");
        } elseif ($upper === 'DATA') {
            $collecting = true;
            fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
        } elseif ($upper === 'RSET') {
            fwrite($client, "250 2.0.0 Ok\r\n");
        } elseif ($upper === 'QUIT') {
            fwrite($client, "221 2.0.0 Bye\r\n");
            break;
        } elseif ($upper === 'STARTTLS') {
            fwrite($client, "454 4.7.0 TLS not available\r\n");
        } else {
            fwrite($client, "502 5.5.2 Command not implemented\r\n");
        }
    }
    @fclose($client);
}
