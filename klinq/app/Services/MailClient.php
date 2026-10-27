<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Services\Helpdesk\Mail\SmtpTransport;
use App\Services\Helpdesk\OutboundMailSettingsService;
use RuntimeException;

/**
 * Client für den E-Mail-Versand: reicht Entnahme-/Retourennachweise, Übergabeprotokolle und
 * Help-Desk-Nachrichten als PDF-Anhang an einen Mailserver weiter.
 *
 * Bevorzugt wird das in der Administration gepflegte Versandkonto (Administration → E-Mail-Versand);
 * ist dort kein Konto aktiv, geht der Versand wie bisher über den Container "mail".
 * Der Versand ist rein informativ (Opt-in); Fehler dürfen den eigentlichen Workflow nie stoppen.
 */
final class MailClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly OutboundMailSettingsService $outbound,
        private readonly SmtpTransport $smtp,
    ) {
    }

    /** Versand möglich: entweder über das gepflegte Konto oder über den Mail-Container. */
    public function enabled(): bool
    {
        return $this->outbound->enabled() || $this->containerEnabled();
    }

    /** Der Versand läuft über das in der Administration gepflegte Konto (nicht über den Container). */
    public function usesAccount(): bool
    {
        return $this->outbound->enabled();
    }

    private function containerEnabled(): bool
    {
        return trim((string) $this->config->get('mail.service_url', '')) !== '';
    }

    /**
     * Sendet eine E-Mail mit optionalen Anhängen. Liefert true bei Erfolg, false bei
     * deaktiviertem/nicht erreichbarem Dienst oder einem Fehler (wird geloggt, nicht geworfen).
     *
     * @param list<array{filename:string,content:string,mime_type:string}> $attachments
     * @param array<string,string> $headers Zusätzliche Kopfzeilen (Message-ID, In-Reply-To, References, Reply-To, Auto-Submitted)
     */
    public function send(string $to, string $subject, string $html, array $attachments = [], array $headers = []): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if (trim($to) === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('E-Mail-Versand übersprungen: ungültige Empfängeradresse', ['to' => $to]);

            return false;
        }
        if ($this->outbound->enabled()) {
            return $this->sendViaAccount($to, $subject, $html, $attachments, $headers);
        }

        return $this->sendViaContainer($to, $subject, $html, $attachments, $headers);
    }

    /**
     * Versand über das in der Administration gepflegte Konto (SMTP).
     * @param list<array{filename:string,content:string,mime_type:string}> $attachments
     * @param array<string,string> $headers
     */
    private function sendViaAccount(string $to, string $subject, string $html, array $attachments, array $headers): bool
    {
        try {
            $this->smtp->send($this->outbound->mailerOptions(), $to, $subject, $html, $attachments, $headers);

            return true;
        } catch (RuntimeException $e) {
            $this->logger->error('E-Mail-Versand über das Help-Desk-Versandkonto fehlgeschlagen', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Versand über den Container "mail" (Rückfall, wenn kein Konto konfiguriert ist).
     * @param list<array{filename:string,content:string,mime_type:string}> $attachments
     * @param array<string,string> $headers
     */
    private function sendViaContainer(string $to, string $subject, string $html, array $attachments, array $headers): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $payload = json_encode([
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'headers' => $this->safeHeaders($headers),
            'attachments' => array_map(static fn (array $a): array => [
                'filename' => $a['filename'],
                'mime_type' => $a['mime_type'],
                'content_base64' => base64_encode($a['content']),
            ], $attachments),
        ], JSON_THROW_ON_ERROR);

        $url = rtrim((string) $this->config->get('mail.service_url'), '/') . '/send';
        $timeout = max(5, (int) $this->config->get('mail.service_timeout', 15));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status !== 200) {
            $this->logger->warning('E-Mail-Dienst meldet Fehler oder ist nicht erreichbar', [
                'url' => $url,
                'status' => $status,
                'error' => $error !== '' ? $error : (is_string($body) ? mb_substr($body, 0, 300) : ''),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Nur bekannte Kopfzeilen ohne Zeilenumbrüche durchreichen (Schutz vor Header-Injection).
     * @param array<string,string> $headers @return array<string,string>
     */
    private function safeHeaders(array $headers): array
    {
        $allowed = ['Message-ID', 'In-Reply-To', 'References', 'Reply-To', 'Auto-Submitted', 'X-Ticket-Number'];
        $clean = [];
        foreach ($allowed as $name) {
            $value = trim((string) ($headers[$name] ?? ''));
            if ($value !== '' && preg_match('/[\r\n]/', $value) !== 1) {
                $clean[$name] = mb_substr($value, 0, 998);
            }
        }

        return $clean;
    }

    /** Erreichbarkeitsprüfung des Mail-Containers für die Admin-Oberfläche. */
    public function healthy(): bool
    {
        if (!$this->containerEnabled() || !function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init(rtrim((string) $this->config->get('mail.service_url'), '/') . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status === 200;
    }
}
