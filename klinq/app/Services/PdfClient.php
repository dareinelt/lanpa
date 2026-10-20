<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/** Client für den HTML→PDF-Dienst (Container "pdf", Python/WeasyPrint). */
final class PdfClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function enabled(): bool
    {
        return trim((string) $this->config->get('app.pdf_service_url', '')) !== '';
    }

    /** Erzeugt ein PDF aus vollständigem HTML; null bei deaktiviertem oder nicht erreichbarem Dienst. */
    public function render(string $html): ?string
    {
        if (!$this->enabled() || !function_exists('curl_init')) {
            return null;
        }
        $url = rtrim((string) $this->config->get('app.pdf_service_url'), '/') . '/render';
        $timeout = max(5, (int) $this->config->get('app.pdf_service_timeout', 30));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $html,
            CURLOPT_HTTPHEADER => ['Content-Type: text/html; charset=utf-8', 'Accept: application/pdf'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $status !== 200 || !str_starts_with($body, '%PDF')) {
            $this->logger->warning('PDF-Dienst nicht verfügbar oder Fehler', [
                'url' => $url,
                'status' => $status,
                'error' => $error !== '' ? $error : (is_string($body) ? mb_substr($body, 0, 300) : ''),
            ]);

            return null;
        }

        return $body;
    }

    /** Erreichbarkeitsprüfung für die Admin-Oberfläche. */
    public function healthy(): bool
    {
        if (!$this->enabled() || !function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init(rtrim((string) $this->config->get('app.pdf_service_url'), '/') . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status === 200;
    }
}
