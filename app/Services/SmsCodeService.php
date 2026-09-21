<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ActivationNumberRepository;
use App\Repositories\NavigationRepository;
use App\Security\Session;
use App\Support\Validator;

/**
 * Erzeugt und verifiziert den taeglich wechselnden sechsstelligen SMS-Code
 * fuer den geschuetzten Zugriffsmodus von Navigationselementen.
 */
final class SmsCodeService
{
    private const SESSION_KEY = 'sms_code';

    private const VERIFIED_KEY = 'sms_verified';

    private const MAX_ATTEMPTS = 5;

    private const CODE_LENGTH = 6;

    public function __construct(
        private readonly NavigationRepository $navigation,
        private readonly ActivationNumberRepository $activationNumbers,
        private readonly SettingsService $settings
    ) {
    }

    /**
     * Sechsstelliger Tagescode. Wechsel taeglich um 00:01 Uhr – die 60
     * Sekunden nach Mitternacht zaehlen deshalb noch zum Vortag.
     */
    public function currentCode(): string
    {
        $day = date('Y-m-d', time() - 60);
        $digest = hash_hmac('sha256', 'sms-code:' . $day, $this->secret());
        $value = hexdec(substr($digest, 0, 6));

        return str_pad((string) ($value % 1000000), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Fordert einen Code an. Ist die Rufnummer nicht hinterlegt, erscheint
     * bewusst keine Fehlermeldung – der Ablauf verhaelt sich dann identisch,
     * es wird lediglich keine SMS versendet.
     *
     * @return array<string,mixed>
     */
    public function requestCode(int $navigationId, string $phone): array
    {
        $item = $this->navigation->find($navigationId);
        if ($item === null || (int) $item['active'] !== 1 || (int) ($item['protected_access'] ?? 0) !== 1) {
            return ['status' => 'error', 'message' => 'Das Element ist nicht geschützt oder nicht verfügbar.'];
        }

        $digits = Validator::normalizePhone($phone);
        if ($digits === '') {
            return ['status' => 'error', 'message' => 'Bitte eine Rufnummer angeben.'];
        }

        $code = $this->currentCode();
        $this->persistRequest($navigationId, $digits, $code);

        $entry = $this->activationNumbers->findByPhoneDigits($digits);
        if ($entry !== null && (int) $entry['active'] === 1) {
            $phone = (string) ($entry['phone'] ?? '');
            if ($phone !== '') {
                $text = $this->buildMessage($code, (string) ($item['title'] ?? ''));
                [$status, $message] = $this->sendSms($text, $phone);

                if ($status !== 'success') {
                    app_logger()->warning('SMS-Code-Versand fehlgeschlagen.', [
                        'navigation_id' => $navigationId,
                        'status' => $status,
                        'message' => $message,
                    ]);
                }
            }
        }

        return ['status' => 'success', 'timeout' => $this->timeout()];
    }

    /**
     * @return array<string,mixed>
     */
    public function verify(int $navigationId, string $phone, string $code): array
    {
        $item = $this->navigation->find($navigationId);
        if ($item === null || (int) $item['active'] !== 1 || (int) ($item['protected_access'] ?? 0) !== 1) {
            return ['status' => 'error', 'message' => 'Das Element ist nicht geschützt oder nicht verfügbar.'];
        }

        $state = Session::get(self::SESSION_KEY);
        if (!is_array($state)) {
            return ['status' => 'error', 'message' => 'Bitte fordern Sie zuerst einen Code an.'];
        }

        $digits = Validator::normalizePhone($phone);
        if ((int) ($state['navigation_id'] ?? 0) !== $navigationId || ($state['phone'] ?? '') !== $digits) {
            return ['status' => 'error', 'message' => 'Bitte fordern Sie zuerst einen Code an.'];
        }

        $attempts = (int) ($state['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return ['status' => 'error', 'message' => 'Zu viele Fehlversuche. Bitte fordern Sie einen neuen Code an.'];
        }

        if (time() - (int) ($state['sent_at'] ?? 0) > $this->timeout()) {
            return ['status' => 'error', 'message' => 'Der Code ist abgelaufen. Bitte fordern Sie einen neuen Code an.'];
        }

        $entered = preg_replace('/\D+/', '', (string) $code) ?? '';
        $storedHash = (string) ($state['code_hash'] ?? '');
        if ($storedHash === '' || $entered === '' || !hash_equals($storedHash, hash('sha256', $entered))) {
            $state['attempts'] = $attempts + 1;
            Session::put(self::SESSION_KEY, $state);

            return ['status' => 'error', 'message' => 'Der Code ist ungültig.'];
        }

        Session::forget(self::SESSION_KEY);
        $this->markVerified($navigationId);

        return [
            'status' => 'success',
            'href' => $this->targetUrl($item),
            'external' => ((string) $item['type']) === 'external',
        ];
    }

    /**
     * Merkt sich fuer die aktuelle Sitzung, dass der Zugriff auf dieses
     * Element per SMS-Code freigeschaltet wurde.
     */
    public function markVerified(int $navigationId): void
    {
        $verified = Session::get(self::VERIFIED_KEY);
        if (!is_array($verified)) {
            $verified = [];
        }

        $verified[$navigationId] = time();
        Session::put(self::VERIFIED_KEY, $verified);
    }

    /**
     * Prueft, ob der Zugriff auf dieses Element in der aktuellen Sitzung
     * bereits per SMS-Code freigeschaltet wurde.
     */
    public function isVerified(int $navigationId): bool
    {
        $verified = Session::get(self::VERIFIED_KEY);

        return is_array($verified) && isset($verified[$navigationId]);
    }

    /**
     * Liefert das geschuetzte interne Navigationselement, dessen Pfad mit dem
     * angeforderten Pfad uebereinstimmt, oder null. Dient der serverseitigen
     * Zugriffssperre fuer interne Elemente (z. B. /telefonliste).
     *
     * @return array<string,mixed>|null
     */
    public function findProtectedInternal(string $path): ?array
    {
        $item = $this->navigation->findActiveInternalByUrl($path);
        if ($item === null || (int) ($item['protected_access'] ?? 0) !== 1) {
            return null;
        }

        return $item;
    }

    /**
     * Ersetzt die Platzhalter {code} und {title} in der SMS-Vorlage.
     */
    public function buildMessage(string $code, string $title): string
    {
        $template = trim($this->settings->get('sms_code_template'));
        if ($template === '') {
            $template = 'Ihr Zugangscode: {code}';
        }

        return Validator::cleanText(str_replace(['{code}', '{title}'], [$code, $title], $template), 255);
    }

    /**
     * Zeitfenster in Sekunden, in dem der Code eingegeben werden muss.
     */
    public function timeout(): int
    {
        $timeout = $this->settings->int('sms_code_timeout', 120);
        if ($timeout < 30 || $timeout > 600) {
            return 120;
        }

        return $timeout;
    }

    private function secret(): string
    {
        $secret = $this->settings->get('sms_code_secret');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->settings->update(['sms_code_secret' => $secret]);
        }

        return $secret;
    }

    /**
     * Ziel-URL fuer ein Navigationselement nach erfolgreicher Freischaltung.
     *
     * @param array<string,mixed> $item
     */
    public function targetUrl(array $item): string
    {
        $type = (string) $item['type'];
        if ($type === 'subpage') {
            return '/unterseite?id=' . (int) $item['id'];
        }
        if ($type === 'page') {
            return '/seite?id=' . (int) $item['id'];
        }

        return (string) ($item['url'] ?? '');
    }

    private function persistRequest(int $navigationId, string $digits, string $code): void
    {
        Session::put(self::SESSION_KEY, [
            'navigation_id' => $navigationId,
            'phone' => $digits,
            'code_hash' => hash('sha256', $code),
            'sent_at' => time(),
            'attempts' => 0,
        ]);
    }

    /**
     * @return array{0:string,1:string} [status, message]
     */
    private function sendSms(string $text, string $phone): array
    {
        $config = $this->settings->alarmSingleConfig();
        if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
            return ['error', 'SMS-Gateway ist nicht vollständig konfiguriert.'];
        }

        $host = rtrim(trim($config['host']), '/');
        $url = 'http://' . $host . '/api.php?' . http_build_query([
            'text' => $text,
            'to' => $phone,
            'username' => $config['username'],
            'password' => $config['password'],
            'mode' => 'number',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'user_agent' => 'lanpa-smscode/1.0',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        $statusLine = '';
        if (isset($http_response_header) && is_array($http_response_header)) {
            $statusLine = (string) ($http_response_header[0] ?? '');
        }

        $statusCode = 0;
        if (preg_match('#\s(\d{3})\s#', $statusLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }

        if ($body === false) {
            return ['error', 'Verbindung zum SMS-Gateway fehlgeschlagen.'];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return ['error', 'Das SMS-Gateway antwortete mit Status ' . $statusCode . '.'];
        }

        return ['success', 'OK'];
    }
}
