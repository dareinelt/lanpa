<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

use App\Core\Config;
use RuntimeException;

/**
 * Minimaler SMTP-Client für ausgehende Help-Desk-E-Mails. Es wird bewusst keine PHP-Erweiterung
 * benötigt: Verbindung, STARTTLS/TLS und Anmeldung laufen über TLS-Sockets.
 *
 * Alle Fehler werden als RuntimeException gemeldet; die Fassade `HelpdeskMailer` entscheidet, ob
 * daraus ein Protokolleintrag oder eine Meldung in der Administration wird.
 */
final class SmtpTransport
{
    /** Kopfzeilen, die aus dem Aufrufer durchgereicht werden (Schutz vor Header-Injection). */
    private const ALLOWED_HEADERS = ['Message-ID', 'In-Reply-To', 'References', 'Reply-To', 'Auto-Submitted', 'X-Ticket-Number'];

    public function __construct(private readonly Config $config) {}

    /**
     * Versendet eine E-Mail (HTML mit Text-Alternative und optionalen Anhängen).
     *
     * @param array<string,mixed> $options host, port, encryption (ssl|starttls|none), username, password,
     *                                     from_address, from_name, reply_to, timeout, verify_peer
     * @param list<array{filename:string,content:string,mime_type:string}> $attachments
     * @param array<string,string> $headers Zusätzliche Kopfzeilen (Message-ID, In-Reply-To, References, Reply-To, Auto-Submitted, X-Ticket-Number)
     */
    public function send(array $options, string $to, string $subject, string $html, array $attachments = [], array $headers = []): void
    {
        $to = trim($to);
        if (!self::isEmail($to)) {
            throw new RuntimeException('Ungültige Empfängeradresse.');
        }
        $from = self::fromAddress($options);
        $socket = $this->connect($options);
        try {
            $this->handshake($socket, $options);
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $message = $this->message($options, $to, $subject, $html, $attachments, $headers);
            $this->write($socket, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
            $this->reply($socket, [250]);
            // Nach DATA 250 ist die Nachricht angenommen; ein QUIT-Fehler darf keinen Neuversand auslösen.
            @fwrite($socket, "QUIT\r\n");
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * Verbindung, STARTTLS und Anmeldung prüfen, ohne eine Nachricht zu senden.
     * @param array<string,mixed> $options
     * @return string Begrüßung des Servers (für die Rückmeldung in der Administration)
     */
    public function probe(array $options): string
    {
        $socket = $this->connect($options);
        try {
            $banner = $this->handshake($socket, $options);
            @fwrite($socket, "QUIT\r\n");

            return $banner;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public static function isEmail(string $value): bool
    {
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/[\x00-\x20\x7F]/', $value) !== 1;
    }

    /** @param array<string,mixed> $options */
    private static function fromAddress(array $options): string
    {
        $from = trim((string) ($options['from_address'] ?? ''));
        if (!self::isEmail($from)) {
            throw new RuntimeException('SMTP: Absenderadresse fehlt oder ist ungültig (Administration → E-Mail-Versand).');
        }

        return $from;
    }

    /**
     * @param array<string,mixed> $options
     * @return resource
     */
    private function connect(array $options)
    {
        $host = trim((string) ($options['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('SMTP: kein Server konfiguriert (Administration → E-Mail-Versand).');
        }
        $encryption = strtolower((string) ($options['encryption'] ?? 'starttls'));
        if (!in_array($encryption, ['ssl', 'starttls', 'none'], true)) {
            throw new RuntimeException('SMTP: unbekannte Verschlüsselung.');
        }
        if ($encryption === 'none' && trim((string) ($options['username'] ?? '')) !== '') {
            throw new RuntimeException('SMTP: Anmeldung ohne TLS wird abgelehnt.');
        }
        $port = (int) ($options['port'] ?? ($encryption === 'ssl' ? 465 : 587));
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP: Port liegt außerhalb des gültigen Bereichs.');
        }
        $timeout = max(3, min(120, (int) ($options['timeout'] ?? 15)));
        $verify = (bool) ($options['verify_peer'] ?? true);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException(sprintf('SMTP: Verbindung zu %s fehlgeschlagen (%d %s).', $target, $errno, $errstr));
        }
        stream_set_timeout($socket, $timeout);

        return $socket;
    }

    /**
     * @param resource $socket
     * @param array<string,mixed> $options
     */
    private function handshake($socket, array $options): string
    {
        $banner = $this->reply($socket, [220]);
        $this->command($socket, 'EHLO ' . $this->heloName(), [250]);
        if (strtolower((string) ($options['encryption'] ?? '')) === 'starttls') {
            $this->command($socket, 'STARTTLS', [220]);
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('SMTP: STARTTLS-Aushandlung fehlgeschlagen.');
            }
            $this->command($socket, 'EHLO ' . $this->heloName(), [250]);
        }
        $username = trim((string) ($options['username'] ?? ''));
        if ($username !== '') {
            $password = (string) ($options['password'] ?? '');
            if ($password === '') {
                throw new RuntimeException('SMTP: Passwort fehlt oder kann nicht entschlüsselt werden.');
            }
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
        }

        return trim($banner);
    }

    /**
     * Baut die vollständige Nachricht (Kopfzeilen und Inhalt) für DATA.
     * @param array<string,mixed> $options
     * @param list<array{filename:string,content:string,mime_type:string}> $attachments
     * @param array<string,string> $headers
     */
    private function message(array $options, string $to, string $subject, string $html, array $attachments, array $headers): string
    {
        $from = self::fromAddress($options);
        $custom = $this->safeHeaders($headers);
        $lines = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $this->mailbox($from, (string) ($options['from_name'] ?? '')),
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
        ];
        $replyTo = trim((string) ($custom['Reply-To'] ?? ($options['reply_to'] ?? '')));
        if ($replyTo !== '' && self::isEmail($replyTo)) {
            $lines[] = 'Reply-To: <' . $replyTo . '>';
        }
        if (!isset($custom['Message-ID'])) {
            $custom['Message-ID'] = sprintf('<%s@%s>', bin2hex(random_bytes(12)), $this->domain($from));
        }
        foreach ($custom as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $alt = 'klinq-alt-' . bin2hex(random_bytes(12));
        $alternative = '--' . $alt . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $this->base64($this->plainText($html)) . "\r\n"
            . '--' . $alt . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $this->base64($html) . "\r\n"
            . '--' . $alt . "--\r\n";

        $lines[] = 'MIME-Version: 1.0';
        if ($attachments === []) {
            $lines[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
            $body = $alternative;
        } else {
            $mixed = 'klinq-mixed-' . bin2hex(random_bytes(12));
            $lines[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
            $body = '--' . $mixed . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $alt . "\"\r\n\r\n"
                . $alternative;
            foreach ($attachments as $attachment) {
                $filename = trim(str_replace(["\r", "\n", '"', '\\'], '', (string) ($attachment['filename'] ?? '')));
                $mime = (string) ($attachment['mime_type'] ?? '');
                if (preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime) !== 1) {
                    $mime = 'application/octet-stream';
                }
                $body .= '--' . $mixed . "\r\n"
                    . 'Content-Type: ' . $mime . '; name="' . $filename . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n"
                    . $this->base64((string) ($attachment['content'] ?? '')) . "\r\n";
            }
            $body .= '--' . $mixed . "--\r\n";
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    private function mailbox(string $address, string $name): string
    {
        $name = trim(str_replace(["\r", "\n"], ' ', $name));
        if ($name === '') {
            return '<' . $address . '>';
        }

        return $this->encodeHeader($name) . ' <' . $address . '>';
    }

    /** Kopfzeilen sind nur als ASCII/UTF-8-kodierte Wörter zulässig. */
    private function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    private function domain(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return 'helpdesk.local';
        }
        $domain = preg_replace('/[^a-z0-9.\-]/i', '', substr($address, $at + 1));

        return $domain !== null && $domain !== '' ? $domain : 'helpdesk.local';
    }

    private function heloName(): string
    {
        $host = parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST);
        $name = is_string($host) ? strtolower(preg_replace('/[^a-z0-9.\-]/i', '', $host) ?? '') : '';

        return $name !== '' ? $name : 'klinq.local';
    }

    private function plainText(string $html): string
    {
        $text = preg_replace('~<(br|/p|/div|/tr|/h[1-6])[^>]*>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function base64(string $content): string
    {
        return rtrim(chunk_split(base64_encode($content), 76, "\r\n"), "\r\n");
    }

    /**
     * Nur bekannte Kopfzeilen ohne Zeilenumbrüche durchreichen (Schutz vor Header-Injection).
     * @param array<string,string> $headers @return array<string,string>
     */
    private function safeHeaders(array $headers): array
    {
        $clean = [];
        foreach (self::ALLOWED_HEADERS as $name) {
            $value = trim((string) ($headers[$name] ?? ''));
            if ($value !== '' && preg_match('/[\r\n]/', $value) !== 1) {
                $clean[$name] = mb_substr($value, 0, 998);
            }
        }

        return $clean;
    }

    /**
     * @param resource $socket
     * @param array<int> $codes
     */
    private function command($socket, string $command, array $codes): void
    {
        $this->write($socket, $command . "\r\n");
        $this->reply($socket, $codes);
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP: Schreibfehler; Zustellung möglicherweise unklar.');
            }
            $data = substr($data, $written);
        }
    }

    /**
     * Liest eine (ggf. mehrzeilige) Serverantwort und prüft den Statuscode.
     * @param resource $socket
     * @param array<int> $codes
     */
    private function reply($socket, array $codes): string
    {
        $first = null;
        $last = '';
        for ($i = 0; $i < 100; $i++) {
            $line = @fgets($socket, 1024);
            if ($line === false || preg_match('/^(\d{3})([ -])/', $line, $match) !== 1 || !str_ends_with($line, "\n")) {
                throw new RuntimeException('SMTP: Antwort fehlt oder ist ungültig; Zustellung möglicherweise unklar.');
            }
            $code = (int) $match[1];
            $first ??= $code;
            if ($code !== $first || !in_array($code, $codes, true)) {
                throw new RuntimeException('SMTP: Der Server hat den Schritt abgelehnt (Status ' . $code . ' ' . trim(substr($line, 4)) . ').');
            }
            $last = $line;
            if ($match[2] === ' ') {
                return trim(substr($last, 4));
            }
        }

        throw new RuntimeException('SMTP: Antwort überschreitet das Limit.');
    }
}
