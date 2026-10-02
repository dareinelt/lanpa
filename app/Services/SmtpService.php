<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Security\SecretBox;
use App\Support\Validator;
use RuntimeException;

final class SmtpService
{
    public const DEFAULTS = [
        'smtp_enabled' => '0', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_security' => 'starttls',
        'smtp_username' => '', 'smtp_from' => '', 'smtp_name' => 'Intranet Notfallplan', 'smtp_timeout' => '10',
    ];

    public function __construct(private readonly SettingsService $settings, private readonly SecretBox $secrets)
    {
    }

    public function config(): array
    {
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->settings->get($key, $default);
        }

        return $values;
    }

    public function save(array $input): void
    {
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : $default;
        }
        $values['smtp_enabled'] = isset($input['smtp_enabled']) ? '1' : '0';
        $errors = [];
        if (!Validator::isHostname($values['smtp_host'])) {
            $errors['smtp_host'] = 'Bitte einen gültigen SMTP-Host angeben (ohne Protokoll).';
        }
        if (!ctype_digit($values['smtp_port']) || !Validator::isPort((int) $values['smtp_port'])) {
            $errors['smtp_port'] = 'Port muss zwischen 1 und 65535 liegen.';
        }
        if (!in_array($values['smtp_security'], ['starttls', 'tls', 'none'], true)) {
            $errors['smtp_security'] = 'Ungültige Transportverschlüsselung.';
        }
        if (!self::validEmail($values['smtp_from'])) {
            $errors['smtp_from'] = 'Bitte eine gültige Absenderadresse angeben.';
        }
        if (!ctype_digit($values['smtp_timeout']) || (int) $values['smtp_timeout'] < 1 || (int) $values['smtp_timeout'] > 30) {
            $errors['smtp_timeout'] = 'Zeitlimit: 1 bis 30 Sekunden.';
        }
        foreach (['smtp_username', 'smtp_name'] as $key) {
            if (strlen($values[$key]) > 190 || preg_match('/[\x00-\x1F\x7F]/', $values[$key])) {
                $errors[$key] = 'Ungültiger Name (max. 190 Zeichen, keine Steuerzeichen).';
            }
        }
        if ($values['smtp_security'] === 'none' && $values['smtp_username'] !== '') {
            $errors['smtp_security'] = 'SMTP-Anmeldung benötigt TLS. Ohne TLS sind nur interne Relays ohne Anmeldung zulässig.';
        }
        $password = is_string($input['smtp_password'] ?? null) ? $input['smtp_password'] : '';
        if (strlen($password) > 4096 || preg_match('/[\r\n\x00]/', $password)) {
            $errors['smtp_password'] = 'Ungültiges SMTP-Passwort.';
        }
        $stored = $this->settings->get('smtp_password');
        if ($values['smtp_username'] !== '' && $password === '' && ($stored === '' || isset($input['smtp_clear_password']))) {
            $errors['smtp_password'] = 'Bitte ein Passwort für die SMTP-Anmeldung hinterlegen.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($password !== '') {
            $values['smtp_password'] = $this->secrets->encrypt($password);
        } elseif (isset($input['smtp_clear_password'])) {
            $values['smtp_password'] = '';
        }
        $this->settings->update($values);
    }

    public static function validEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && !preg_match('/[\x00-\x20\x7F]/', $email);
    }

    public function send(string $recipient, string $subject, string $body, string $messageId): void
    {
        $c = $this->config();
        if ($c['smtp_enabled'] !== '1') {
            throw new RuntimeException('SMTP ist deaktiviert. KAEP-Team anderweitig informieren.');
        }
        if (!self::validEmail($recipient) || !self::validEmail($c['smtp_from']) || !Validator::isHostname($c['smtp_host'])
            || !ctype_digit($c['smtp_port']) || !Validator::isPort((int) $c['smtp_port'])
            || !in_array($c['smtp_security'], ['none', 'tls', 'starttls'], true)
            || preg_match('/^[a-zA-Z0-9.-]+$/D', $messageId) !== 1) {
            throw new RuntimeException('SMTP-Konfiguration oder Empfänger ungültig.');
        }
        $password = '';
        if ($c['smtp_username'] !== '') {
            if ($c['smtp_security'] === 'none') {
                throw new RuntimeException('SMTP-Anmeldung ohne TLS wird abgelehnt.');
            }
            $password = $this->secrets->decrypt($this->settings->get('smtp_password')) ?? '';
            if ($password === '') {
                throw new RuntimeException('SMTP-Passwort fehlt oder kann nicht entschlüsselt werden.');
            }
        }
        $timeout = max(1, min(30, (int) $c['smtp_timeout']));
        $host = str_contains($c['smtp_host'], ':') ? '[' . $c['smtp_host'] . ']' : $c['smtp_host'];
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['smtp_host']]]);
        $socket = @stream_socket_client(($c['smtp_security'] === 'tls' ? 'tls' : 'tcp') . '://' . $host . ':' . $c['smtp_port'], $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException('SMTP-Verbindung fehlgeschlagen. Host, Port und Zertifikat prüfen.');
        }
        stream_set_timeout($socket, $timeout);
        try {
            $this->reply($socket, [220]);
            $this->command($socket, 'EHLO intranet.local', [250]);
            if ($c['smtp_security'] === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('SMTP-TLS-Verbindung fehlgeschlagen.');
                }
                $this->command($socket, 'EHLO intranet.local', [250]);
            }
            if ($c['smtp_username'] !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($c['smtp_username']), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $c['smtp_from'] . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $encode = static fn (string $text): string => mb_encode_mimeheader(str_replace(["\r", "\n"], ' ', $text), 'UTF-8', 'B', "\r\n");
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . $encode($c['smtp_name']) . ' <' . $c['smtp_from'] . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $encode($subject),
                'Message-ID: <' . $messageId . '@intranet.local>',
                'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
            ];
            $this->write($socket, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n");
            $this->reply($socket, [250]);
            // Nach DATA 250 ist die Nachricht angenommen; ein QUIT-Fehler darf keinen Neuversand auslösen.
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, array $codes): void
    {
        $this->write($socket, $command . "\r\n");
        $this->reply($socket, $codes);
    }

    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP-Schreibfehler; Zustellung möglicherweise unklar.');
            }
            $data = substr($data, $written);
        }
    }

    private function reply($socket, array $codes): void
    {
        $first = null;
        for ($i = 0; $i < 100; $i++) {
            $line = @fgets($socket, 1024);
            if ($line === false || preg_match('/^(\d{3})([ -])/', $line, $match) !== 1 || !str_ends_with($line, "\n")) {
                throw new RuntimeException('SMTP-Antwort fehlt oder ist ungültig; Zustellung möglicherweise unklar.');
            }
            $code = (int) $match[1];
            $first ??= $code;
            if ($code !== $first || !in_array($code, $codes, true)) {
                throw new RuntimeException('SMTP hat den Schritt abgelehnt (Status ' . $code . ').');
            }
            if ($match[2] === ' ') {
                return;
            }
        }
        throw new RuntimeException('SMTP-Antwort überschreitet das Limit.');
    }
}
