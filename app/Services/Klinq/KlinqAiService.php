<?php

declare(strict_types=1);

namespace App\Services\Klinq;

use App\Contracts\OfficeProbeInterface;
use App\Services\Office\OfficeAiService;
use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Eigener KI-Endpunkt der KLINQ-Integration.
 *
 * KLINQ bringt eine KI-Unterstuetzung fuer das Help Desk mit (Textvorschlaege,
 * Zusammenfassungen, Auswertung von Tickets). Angesprochen wird ein
 * OpenAI-kompatibler Endpunkt (Ollama, vLLM, LocalAI, LM Studio ...), angegeben
 * mit Basisadresse inklusive /v1.
 *
 * Die Einstellungen werden ausschliesslich im Adminbereich des Intranets
 * gepflegt. KLINQ selbst kennt keinen eigenen KI-Endpunkt mehr: es holt den
 * Stand ueber die interne Schnittstelle /internal/klinq/ki ab (Abschnitt
 * "KI-Endpunkt" der KLINQ-Administration zeigt ihn nur an). Der API-Schluessel
 * bleibt damit im Intranet und wird nur an den KLINQ-Container uebergeben.
 */
final class KlinqAiService
{
    /** Testnachricht des Verbindungstests im Adminbereich. */
    public const TEST_PROMPT = 'Wer bist du?';

    private const TEST_MAX_TOKENS = 400;
    private const TEST_MAX_ANSWER = 4000;

    public const SETTING_KEYS = [
        'klinq_ai_enabled',
        'klinq_ai_name',
        'klinq_ai_url',
        'klinq_ai_model',
        'klinq_ai_timeout',
    ];

    /**
     * @param array<string,mixed> $config Werte aus config/klinq.php
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OfficeProbeInterface $probe,
        private readonly array $config = []
    ) {
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'klinq_ai_enabled' => '1',
            'klinq_ai_name' => 'KLINQ-KI',
            'klinq_ai_url' => '',
            'klinq_ai_model' => '',
            'klinq_ai_timeout' => '60',
            'klinq_ai_api_key' => '',
        ];
    }

    public function enabled(): bool
    {
        return $this->settings->get('klinq_ai_enabled', '1') === '1';
    }

    public function name(): string
    {
        $name = Validator::cleanText($this->settings->get('klinq_ai_name', 'KLINQ-KI'), 60);

        return $name !== '' ? $name : 'KLINQ-KI';
    }

    /**
     * Endpunkt inklusive /v1, ohne abschliessenden Schraegstrich.
     */
    public function url(): string
    {
        $url = rtrim(trim($this->settings->get('klinq_ai_url')), '/');

        return self::isValidUrl($url) ? $url : '';
    }

    public function model(): string
    {
        $model = trim($this->settings->get('klinq_ai_model'));

        return self::isValidModel($model) ? $model : '';
    }

    public function timeout(): int
    {
        $timeout = (int) $this->settings->get('klinq_ai_timeout', '60');

        return $timeout >= 5 && $timeout <= 900 ? $timeout : 60;
    }

    public function apiKeyFromSecret(): bool
    {
        return trim((string) ($this->config['ai_api_key'] ?? '')) !== '';
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        $secret = trim((string) ($this->config['ai_api_key'] ?? ''));

        return $secret !== '' ? $secret : trim($this->settings->get('klinq_ai_api_key'));
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->model() !== '';
    }

    /**
     * Freigegeben und vollstaendig konfiguriert.
     */
    public function isActive(): bool
    {
        return $this->enabled() && $this->isConfigured();
    }

    /**
     * @return array<string,string>
     */
    public function formValues(): array
    {
        $values = [];
        foreach (self::SETTING_KEYS as $key) {
            $values[$key] = $this->settings->get($key, self::defaults()[$key]);
        }

        return $values;
    }

    /**
     * Stand fuer den KLINQ-Container (interne Schnittstelle /internal/klinq/ki).
     *
     * @return array{enabled:bool,name:string,url:string,model:string,timeout:int,api_key:string}
     */
    public function payload(): array
    {
        return [
            'enabled' => $this->isActive(),
            'name' => $this->name(),
            'url' => $this->url(),
            'model' => $this->model(),
            'timeout' => $this->timeout(),
            'api_key' => $this->apiKey(),
        ];
    }

    /**
     * Prueft die Eingaben des Admin-Formulars. Der API-Schluessel wird nur
     * uebernommen, wenn einer eingegeben oder das Entfernen gewaehlt wurde.
     *
     * @param array<string,mixed> $input
     *
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $values = [];

        $enabled = !empty($input['klinq_ai_enabled']);
        $values['klinq_ai_enabled'] = $enabled ? '1' : '0';

        $name = Validator::cleanText((string) ($input['klinq_ai_name'] ?? ''), 60);
        $values['klinq_ai_name'] = $name !== '' ? $name : 'KLINQ-KI';

        $url = rtrim(trim((string) ($input['klinq_ai_url'] ?? '')), '/');
        if ($url !== '' && !self::isValidUrl($url)) {
            $errors['klinq_ai_url'] = 'Bitte eine http(s)-Adresse ohne Zugangsdaten, Parameter oder Anker angeben (z. B. http://ki-server:11434/v1).';
        } elseif ($url === '' && $enabled) {
            $errors['klinq_ai_url'] = 'Bitte die Adresse des KI-Endpunkts angeben oder die KI deaktivieren.';
        }
        $values['klinq_ai_url'] = $url;

        $model = trim((string) ($input['klinq_ai_model'] ?? ''));
        if ($model !== '' && !self::isValidModel($model)) {
            $errors['klinq_ai_model'] = 'Ungültiger Modellname (erlaubt: Buchstaben, Ziffern und . _ - : / @ +).';
        } elseif ($model === '' && $enabled) {
            $errors['klinq_ai_model'] = 'Bitte das Modell angeben (z. B. llama3.1:8b).';
        }
        $values['klinq_ai_model'] = $model;

        $timeout = trim((string) ($input['klinq_ai_timeout'] ?? '60'));
        if (preg_match('/^\d{1,4}$/', $timeout) !== 1 || (int) $timeout < 5 || (int) $timeout > 900) {
            $errors['klinq_ai_timeout'] = 'Bitte einen Wert zwischen 5 und 900 Sekunden angeben.';
        }
        $values['klinq_ai_timeout'] = $timeout;

        $key = trim((string) ($input['klinq_ai_api_key'] ?? ''));
        if (!empty($input['klinq_ai_api_key_clear'])) {
            $values['klinq_ai_api_key'] = '';
        } elseif ($key !== '') {
            if (strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
                $errors['klinq_ai_api_key'] = 'Ungültiger API-Schlüssel.';
            } else {
                $values['klinq_ai_api_key'] = $key;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    public static function isValidUrl(string $url): bool
    {
        return OfficeAiService::isValidUrl($url);
    }

    public static function isValidModel(string $model): bool
    {
        return OfficeAiService::isValidModel($model);
    }

    /**
     * Prueft, ob der Endpunkt antwortet und das Modell anbietet (GET /models).
     *
     * @return array{ok:bool,message:string}
     */
    public function testEndpoint(int $timeout = 8): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Adresse oder Modell fehlen.'];
        }

        $result = $this->checkModels($this->url(), $this->model(), $this->apiKey(), $timeout);

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }

    /**
     * Test aus dem Adminbereich mit den (auch ungespeicherten) Formularwerten:
     * prueft /models und stellt dem Modell die Frage TEST_PROMPT.
     *
     * Ein gespeicherter Schluessel (Datenbank oder Secret) wird nur an die
     * gespeicherte Adresse gesendet, nie an eine im Formular geaenderte.
     *
     * @param array<string,mixed> $input Formularwerte
     *
     * @return array{ok:bool,errors:array<string,string>,steps:list<array{label:string,ok:bool,message:string}>,prompt:string,answer:string,model:string,duration_ms:int,tokens:int}
     */
    public function runTest(array $input, int $maxTimeout = 120): array
    {
        $result = ['ok' => false, 'errors' => [], 'steps' => [], 'prompt' => self::TEST_PROMPT, 'answer' => '', 'model' => '', 'duration_ms' => 0, 'tokens' => 0];

        $validated = self::validate(['klinq_ai_enabled' => '1'] + $input);
        $errors = array_intersect_key($validated['errors'], array_flip(['klinq_ai_url', 'klinq_ai_model', 'klinq_ai_timeout', 'klinq_ai_api_key']));
        if ($errors !== []) {
            $result['errors'] = $errors;

            return $result;
        }

        $values = $validated['values'];
        $url = $values['klinq_ai_url'];
        $model = $values['klinq_ai_model'];
        $result['model'] = $model;
        $timeout = max(5, min($maxTimeout, (int) $values['klinq_ai_timeout']));

        if (array_key_exists('klinq_ai_api_key', $values) && !$this->apiKeyFromSecret()) {
            $key = $values['klinq_ai_api_key'];
        } elseif ($this->hasApiKey() && $url !== $this->url()) {
            $key = '';
            $result['steps'][] = ['label' => 'API-Schlüssel', 'ok' => true, 'message' => 'Der gespeicherte Schlüssel wird nur an die gespeicherte Adresse gesendet; getestet wird ohne Schlüssel.'];
        } else {
            $key = $this->apiKey();
        }

        $models = $this->checkModels($url, $model, $key, min($timeout, 15));
        $result['steps'][] = ['label' => 'Endpunkt und Modell', 'ok' => $models['ok'], 'message' => $models['message']];
        if (!$models['reachable']) {
            return $result;
        }

        $chat = $this->chat($url, $model, $key, self::TEST_PROMPT, $timeout);
        $result['steps'][] = ['label' => 'Testnachricht', 'ok' => $chat['ok'], 'message' => $chat['message']];
        $result['answer'] = $chat['answer'];
        $result['duration_ms'] = $chat['duration_ms'];
        $result['tokens'] = $chat['tokens'];
        $result['ok'] = $models['ok'] && $chat['ok'];

        return $result;
    }

    /**
     * GET /models: Endpunkt erreichbar und Modell angeboten?
     *
     * @return array{ok:bool,reachable:bool,message:string}
     */
    private function checkModels(string $url, string $model, string $key, int $timeout): array
    {
        $response = $this->probe->request('GET', $url . '/models', self::authHeaders($key), null, $timeout);
        if ($response['error'] !== null || $response['status'] === 0) {
            return ['ok' => false, 'reachable' => false, 'message' => 'Endpunkt nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort')];
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            return ['ok' => false, 'reachable' => false, 'message' => 'Endpunkt verweigert den Zugriff (HTTP ' . $response['status'] . ') – API-Schlüssel prüfen.'];
        }
        if ($response['status'] !== 200) {
            return ['ok' => false, 'reachable' => false, 'message' => 'Endpunkt antwortet mit HTTP ' . $response['status'] . ' – ist die Adresse inklusive /v1 angegeben?'];
        }

        $data = json_decode($response['body'], true);
        $list = is_array($data) ? ($data['data'] ?? $data['models'] ?? null) : null;
        if (!is_array($list)) {
            return ['ok' => false, 'reachable' => false, 'message' => 'Unerwartete Antwort auf /models – kein OpenAI-kompatibler Endpunkt?'];
        }

        $ids = [];
        foreach ($list as $entry) {
            if (is_array($entry)) {
                $ids[] = (string) ($entry['id'] ?? $entry['name'] ?? '');
            }
        }
        if (!in_array($model, $ids, true)) {
            $known = array_slice(array_filter($ids, 'strlen'), 0, 8);

            return ['ok' => false, 'reachable' => true, 'message' => 'Modell „' . $model . '“ wird nicht angeboten'
                . ($known !== [] ? ' (verfügbar: ' . implode(', ', $known) . ')' : '') . '.'];
        }

        return ['ok' => true, 'reachable' => true, 'message' => 'Endpunkt erreichbar, Modell „' . $model . '“ verfügbar.'];
    }

    /**
     * POST /chat/completions mit einer einzelnen Benutzernachricht.
     *
     * @return array{ok:bool,message:string,answer:string,duration_ms:int,tokens:int}
     */
    private function chat(string $url, string $model, string $key, string $prompt, int $timeout): array
    {
        $body = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => self::TEST_MAX_TOKENS,
            'temperature' => 0.7,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $started = hrtime(true);
        $response = $this->probe->request('POST', $url . '/chat/completions', ['Content-Type' => 'application/json'] + self::authHeaders($key), (string) $body, $timeout);
        $duration = (int) round((hrtime(true) - $started) / 1e6);
        $fail = static fn (string $message): array => ['ok' => false, 'message' => $message, 'answer' => '', 'duration_ms' => $duration, 'tokens' => 0];

        if ($response['error'] !== null || $response['status'] === 0) {
            return $fail('Keine Antwort: ' . ($response['error'] ?? 'Verbindung abgebrochen') . ' (Zeitlimit ' . $timeout . ' s).');
        }
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? $data['message'] ?? '') : '';
            $detail = is_string($detail) ? Validator::cleanText($detail, 300) : '';

            return $fail('Anfrage abgelehnt (HTTP ' . $response['status'] . ')' . ($detail !== '' ? ': ' . $detail : '.'));
        }

        $message = is_array($data) ? ($data['choices'][0]['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        // Denkende Modelle liefern bei knappem Tokenbudget nur reasoning_content.
        if ((!is_string($content) || trim($content) === '') && is_array($message) && is_string($message['reasoning_content'] ?? null)) {
            $content = $message['reasoning_content'];
        }
        if (!is_string($content) || trim($content) === '') {
            return $fail('Das Modell hat keine Textantwort geliefert.');
        }

        $answer = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $content)));
        if (mb_strlen($answer) > self::TEST_MAX_ANSWER) {
            $answer = mb_substr($answer, 0, self::TEST_MAX_ANSWER) . ' …';
        }
        $tokens = max(0, (int) ($data['usage']['completion_tokens'] ?? 0));

        return [
            'ok' => true,
            'message' => 'Antwort erhalten nach ' . number_format($duration / 1000, 1, ',', '.') . ' s.',
            'answer' => $answer,
            'duration_ms' => $duration,
            'tokens' => $tokens,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function authHeaders(string $key): array
    {
        return $key !== '' ? ['Authorization' => 'Bearer ' . $key] : [];
    }
}
