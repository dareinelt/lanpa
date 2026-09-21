<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AlarmLogRepository;
use App\Repositories\NavigationRepository;
use App\Support\Validator;

/**
 * Löst eine Alarmierung aus: baut die Gateway-URL serverseitig zusammen, sendet
 * sie per GET und protokolliert das Ergebnis. Das Gateway-Passwort wird niemals
 * zurückgegeben, gerendert oder geloggt.
 */
final class AlarmService
{
    public function __construct(
        private readonly NavigationRepository $navigation,
        private readonly AlarmLogRepository $log,
        private readonly SettingsService $settings
    ) {
    }

    /**
     * @return array{status:string,message:string}
     */
    public function trigger(int $navigationId, string $additionalText = ''): array
    {
        $item = $this->navigation->find($navigationId);

        if ($item === null || (string) $item['type'] !== 'alarm' || (int) $item['active'] !== 1) {
            return ['status' => 'error', 'message' => 'Alarmierung nicht gefunden oder nicht aktiv.'];
        }

        $title = (string) ($item['title'] ?? '');
        $text = (string) ($item['alarm_text'] ?? '');
        $target = (string) ($item['alarm_group_number'] ?? '');
        $description = (string) ($item['alarm_group_description'] ?? '');
        $mode = (string) ($item['alarm_group_type'] ?? 'group');
        if (!in_array($mode, ['group', 'number'], true)) {
            $mode = 'group';
        }

        if ($text === '') {
            $this->log($navigationId, $title, $text, $target, $description, $mode, 'error', 'Alarmierungstext fehlt.');

            return ['status' => 'error', 'message' => 'Die Alarmierung ist unvollständig konfiguriert.'];
        }

        // Das Ziel (Gruppe oder Rufnummer) muss hinterlegt sein, damit eine
        // Meldung ausgelöst wird.
        if ($target === '') {
            $this->log($navigationId, $title, $text, $target, $description, $mode, 'error', $mode === 'number' ? 'Rufnummer fehlt.' : 'Gruppe fehlt.');

            return ['status' => 'error', 'message' => 'Die Alarmierung ist unvollständig konfiguriert.'];
        }

        // Optionaler Freitext wird an die Vorlage angehängt. Das Gesamtlimit von
        // 255 Zeichen gilt für die vollständige Meldung (Vorlage + Freitext).
        $additional = Validator::cleanText($additionalText, 255);
        if ($additional !== '') {
            $text = rtrim($text) . ' ' . $additional;
        }

        if (mb_strlen($text) > 255) {
            $this->log($navigationId, $title, mb_substr($text, 0, 255), $target, $description, $mode, 'error', 'Die Meldung überschreitet das Limit von 255 Zeichen.');

            return ['status' => 'error', 'message' => 'Die Meldung ist zu lang (max. 255 Zeichen).'];
        }

        $config = $mode === 'number' ? $this->settings->alarmSingleConfig() : $this->settings->alarmConfig();
        if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
            $this->log($navigationId, $title, $text, $target, $description, $mode, 'error', 'SMS-Gateway ist nicht vollständig konfiguriert.');

            return ['status' => 'error', 'message' => 'Das SMS-Gateway ist nicht vollständig konfiguriert.'];
        }

        $url = $this->buildUrl($config['host'], $text, $target, $config['username'], $config['password'], $mode);
        [$status, $message] = $this->send($url);

        $this->log($navigationId, $title, $text, $target, $description, $mode, $status, $message);

        app_logger()->info('Alarmierung ausgelöst.', [
            'navigation_id' => $navigationId,
            'target' => $target,
            'mode' => $mode,
            'status' => $status,
        ]);

        return [
            'status' => $status,
            'message' => $status === 'success' ? 'Die Alarmierung wurde ausgelöst.' : $message,
        ];
    }

    private function buildUrl(string $host, string $text, string $target, string $username, string $password, string $mode): string
    {
        $host = rtrim(trim($host), '/');

        return 'http://' . $host . '/api.php?' . http_build_query([
            'text' => $text,
            'to' => $target,
            'username' => $username,
            'password' => $password,
            'mode' => $mode,
        ]);
    }

    /**
     * @return array{0:string,1:string} [status, message]
     */
    private function send(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'user_agent' => 'lanpa-alarm/1.0',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        $statusLine = '';
        if (isset($http_response_header) && is_array($http_response_header)) {
            $statusLine = (string) ($http_response_header[0] ?? '');
        }

        $code = 0;
        if (preg_match('#\s(\d{3})\s#', $statusLine, $matches) === 1) {
            $code = (int) $matches[1];
        }

        if ($body === false) {
            return ['error', 'Verbindung zum SMS-Gateway fehlgeschlagen.'];
        }

        if ($code < 200 || $code >= 300) {
            return ['error', 'Das SMS-Gateway antwortete mit Status ' . $code . '.'];
        }

        return ['success', 'OK'];
    }

    private function log(
        int $navigationId,
        string $title,
        string $text,
        string $target,
        string $description,
        string $mode,
        string $status,
        string $message
    ): void {
        $this->log->create([
            'navigation_id' => $navigationId,
            'title' => $title,
            'alarm_text' => $text,
            'group_number' => $target,
            'group_description' => $description,
            'mode' => $mode,
            'status' => $status,
            'message' => $message,
            'triggered_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
