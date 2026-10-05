<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Services\SettingsService;
use App\Support\Validator;
use JsonException;

/**
 * Lokaler KI-Endpunkt fuer alle Benutzer von Nextcloud und Euro-Office.
 *
 * Die Einstellungen werden im Adminbereich gepflegt (Tabelle settings, der
 * API-Schluessel optional als Secret) und von hier aus weitergereicht:
 *
 *   Euro-Office  Laufzeitkonfiguration runtime.json (aiSettings) im
 *                gemeinsamen Volume office_ai. Der DocumentServer laedt sie
 *                ohne Neustart; das KI-Plugin ist damit fuer alle Benutzer
 *                vorkonfiguriert, der Schluessel bleibt auf dem Server
 *                (Anfragen laufen ueber dessen /ai-proxy).
 *   Nextcloud    signierte Uebergabe an die App intranet_integration, die
 *                integration_openai (Anbieter fuer Assistent, Text,
 *                Uebersetzung) als Admin-Konfiguration fuer alle Benutzer
 *                einrichtet. Audio- und Bildfunktionen (Schaltflaechen „Mit
 *                Audio arbeiten“/„Mit Bildern arbeiten“ im Assistant) gibt
 *                es nur, wenn sie hier freigegeben sind. Ein Fingerabdruck in der Diagnose zeigt, ob der
 *                aktuelle Stand angekommen ist; sonst wird erneut uebertragen.
 *
 * Erwartet wird ein OpenAI-kompatibler Endpunkt (Ollama, LM Studio, vLLM,
 * LocalAI, llama.cpp ...), angegeben mit Basisadresse inklusive /v1.
 */
final class OfficeAiService
{
    /** Name des Anbieters im Euro-Office-KI-Plugin (kein eingebauter Name). */
    public const EO_PROVIDER = 'Intranet-KI';

    public const RUNTIME_FILE = 'runtime.json';

    /** Testnachricht des Verbindungstests im Adminbereich. */
    public const TEST_PROMPT = 'Wer bist du?';
    private const TEST_MAX_TOKENS = 400;
    private const TEST_MAX_ANSWER = 4000;

    /** Aendern, wenn sich die Abbildung auf Nextcloud aendert (erzwingt Neuuebertragung). */
    private const PAYLOAD_VERSION = 2;

    /** Aktionen des Euro-Office-KI-Plugins, die das Textmodell nutzen. */
    private const EO_ACTIONS = [
        'Chat' => ['Chatbot', 'ask-ai'],
        'Summarization' => ['Summarization', 'summarization'],
        'Translation' => ['Translation', 'translation'],
        'TextAnalyze' => ['Text analysis', 'text-analysis-ai'],
    ];

    /** AI.CapabilitiesUI.Chat bzw. AI.Endpoints.Types.v1.Chat_Completions im Plugin. */
    private const EO_CAPABILITY_CHAT = 1;
    private const EO_ENDPOINT_CHAT = 1;

    public const SETTING_KEYS = [
        'office_ai_enabled',
        'office_ai_name',
        'office_ai_url',
        'office_ai_model',
        'office_ai_timeout',
        'office_ai_audio',
        'office_ai_images',
    ];

    /**
     * @param array<string,mixed> $config Werte aus config/office.php
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe,
        private readonly array $config
    ) {
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            // Standardmaessig freigegeben: sobald Adresse und Modell
            // hinterlegt sind, steht die KI allen Benutzern zur Verfuegung.
            'office_ai_enabled' => '1',
            'office_ai_name' => 'Lokale KI',
            'office_ai_url' => '',
            'office_ai_model' => '',
            'office_ai_timeout' => '120',
            // Ein lokales Textmodell kann meist weder Audio noch Bilder
            // verarbeiten; die Funktionen in Nextcloud sind deshalb aus.
            'office_ai_audio' => '0',
            'office_ai_images' => '0',
            'office_ai_api_key' => '',
        ];
    }

    public function enabled(): bool
    {
        return $this->settings->get('office_ai_enabled', '1') === '1';
    }

    public function name(): string
    {
        $name = Validator::cleanText($this->settings->get('office_ai_name', 'Lokale KI'), 60);

        return $name !== '' ? $name : 'Lokale KI';
    }

    public function url(): string
    {
        $url = rtrim(trim($this->settings->get('office_ai_url')), '/');

        return self::isValidUrl($url) ? $url : '';
    }

    public function model(): string
    {
        $model = trim($this->settings->get('office_ai_model'));

        return self::isValidModel($model) ? $model : '';
    }

    public function timeout(): int
    {
        $timeout = (int) $this->settings->get('office_ai_timeout', '120');

        return $timeout >= 10 && $timeout <= 900 ? $timeout : 120;
    }

    /**
     * Audiofunktionen (Transkription, Sprachausgabe, Audio-Chat) in Nextcloud.
     */
    public function audioEnabled(): bool
    {
        return $this->settings->get('office_ai_audio', '0') === '1';
    }

    /**
     * Bildfunktionen (Bilderzeugung, Bildanalyse, Sticker) in Nextcloud.
     */
    public function imagesEnabled(): bool
    {
        return $this->settings->get('office_ai_images', '0') === '1';
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

        return $secret !== '' ? $secret : trim($this->settings->get('office_ai_api_key'));
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

        $enabled = !empty($input['office_ai_enabled']);
        $values['office_ai_enabled'] = $enabled ? '1' : '0';

        $name = Validator::cleanText((string) ($input['office_ai_name'] ?? ''), 60);
        $values['office_ai_name'] = $name !== '' ? $name : 'Lokale KI';

        $url = rtrim(trim((string) ($input['office_ai_url'] ?? '')), '/');
        if ($url !== '' && !self::isValidUrl($url)) {
            $errors['office_ai_url'] = 'Bitte eine http(s)-Adresse ohne Zugangsdaten, Parameter oder Anker angeben (z. B. http://ki-server:11434/v1).';
        } elseif ($url === '' && $enabled) {
            $errors['office_ai_url'] = 'Bitte die Adresse des KI-Endpunkts angeben oder die KI deaktivieren.';
        }
        $values['office_ai_url'] = $url;

        $model = trim((string) ($input['office_ai_model'] ?? ''));
        if ($model !== '' && !self::isValidModel($model)) {
            $errors['office_ai_model'] = 'Ungültiger Modellname (erlaubt: Buchstaben, Ziffern und . _ - : / @ +).';
        } elseif ($model === '' && $enabled) {
            $errors['office_ai_model'] = 'Bitte das Modell angeben (z. B. llama3.1:8b).';
        }
        $values['office_ai_model'] = $model;

        $timeout = trim((string) ($input['office_ai_timeout'] ?? '120'));
        if (preg_match('/^\d{1,4}$/', $timeout) !== 1 || (int) $timeout < 10 || (int) $timeout > 900) {
            $errors['office_ai_timeout'] = 'Bitte einen Wert zwischen 10 und 900 Sekunden angeben.';
        }
        $values['office_ai_timeout'] = $timeout;

        $values['office_ai_audio'] = !empty($input['office_ai_audio']) ? '1' : '0';
        $values['office_ai_images'] = !empty($input['office_ai_images']) ? '1' : '0';

        $key = trim((string) ($input['office_ai_api_key'] ?? ''));
        if (!empty($input['office_ai_api_key_clear'])) {
            $values['office_ai_api_key'] = '';
        } elseif ($key !== '') {
            if (strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
                $errors['office_ai_api_key'] = 'Ungültiger API-Schlüssel.';
            } else {
                $values['office_ai_api_key'] = $key;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    public static function isValidUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    public static function isValidModel(string $model): bool
    {
        // Fuehrender "/" erlaubt: llama.cpp meldet den Dateipfad des Modells als ID.
        return preg_match('#^[A-Za-z0-9/][A-Za-z0-9._:/@+-]{0,199}$#', $model) === 1;
    }

    /**
     * Laufzeitkonfiguration des DocumentServers (Inhalt von runtime.json).
     * Ohne aktive KI leer: Das Plugin bleibt dann ohne Vorgabe.
     *
     * @return array<string,mixed>
     */
    public function euroOfficeRuntimeConfig(): array
    {
        if (!$this->isActive()) {
            return [];
        }

        $model = $this->model();
        $actions = [];
        foreach (self::EO_ACTIONS as $id => [$label, $icon]) {
            $actions[$id] = ['name' => $label, 'icon' => $icon, 'model' => $model, 'capabilities' => self::EO_CAPABILITY_CHAT];
        }

        return [
            'aiSettings' => [
                'version' => 3,
                'timeout' => $this->timeout() . 's',
                'actions' => $actions,
                'providers' => [
                    self::EO_PROVIDER => [
                        'name' => self::EO_PROVIDER,
                        'url' => $this->url(),
                        'key' => $this->apiKey(),
                        'models' => [[
                            'id' => $model,
                            'name' => $model,
                            'object' => 'model',
                            'owned_by' => 'intranet',
                            'endpoints' => [self::EO_ENDPOINT_CHAT],
                            'options' => new \stdClass(),
                        ]],
                    ],
                ],
                'models' => [[
                    'id' => $model,
                    'name' => $this->name() . ' [' . $model . ']',
                    'provider' => self::EO_PROVIDER,
                    'capabilities' => self::EO_CAPABILITY_CHAT,
                ]],
            ],
        ];
    }

    /**
     * Uebergabe an die Nextcloud-App intranet_integration.
     *
     * @return array{enabled:bool,name:string,url:string,model:string,api_key:string,timeout:int,audio:bool,images:bool,fingerprint:string}
     */
    public function nextcloudPayload(): array
    {
        $payload = [
            'enabled' => $this->isActive(),
            'name' => $this->name(),
            'url' => $this->isActive() ? $this->url() : '',
            'model' => $this->isActive() ? $this->model() : '',
            'api_key' => $this->isActive() ? $this->apiKey() : '',
            'timeout' => $this->timeout(),
            'audio' => $this->audioEnabled(),
            'images' => $this->imagesEnabled(),
        ];
        $payload['fingerprint'] = $this->fingerprint($payload);

        return $payload;
    }

    /**
     * Fingerabdruck des aktuellen Stands (HMAC, damit weder Schluessel noch
     * Einstellungen aus der Diagnose ableitbar sind).
     *
     * @param array<string,mixed>|null $payload
     */
    public function fingerprint(?array $payload = null): string
    {
        $payload ??= $this->nextcloudPayload();
        unset($payload['fingerprint']);
        $payload['v'] = self::PAYLOAD_VERSION;

        return hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->office->jwtSecret());
    }

    public function runtimeFile(): string
    {
        return rtrim((string) ($this->config['ai_config_dir'] ?? ''), '/') . '/' . self::RUNTIME_FILE;
    }

    /**
     * Schreibt runtime.json fuer den DocumentServer, falls sich der Inhalt
     * geaendert hat (atomar, damit der Dateiwaechter nie einen Teilstand liest).
     *
     * @return array{ok:bool,changed:bool,message:string}
     */
    public function writeEuroOfficeConfig(): array
    {
        $file = $this->runtimeFile();
        $dir = dirname($file);

        try {
            $json = json_encode($this->euroOfficeRuntimeConfig() ?: new \stdClass(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (JsonException) {
            return ['ok' => false, 'changed' => false, 'message' => 'Konfiguration konnte nicht erzeugt werden.'];
        }

        if (is_file($file) && (string) @file_get_contents($file) === $json) {
            return ['ok' => true, 'changed' => false, 'message' => 'Euro-Office ist auf dem aktuellen Stand.'];
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'changed' => false, 'message' => 'Austauschverzeichnis ' . $dir . ' fehlt (Volume office_ai).'];
        }

        $tmp = $dir . '/.' . self::RUNTIME_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return ['ok' => false, 'changed' => false, 'message' => 'Austauschverzeichnis ' . $dir . ' ist nicht beschreibbar.'];
        }
        // Der DocumentServer laeuft unter einem anderen Benutzer.
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return ['ok' => false, 'changed' => false, 'message' => 'runtime.json konnte nicht ersetzt werden.'];
        }

        return ['ok' => true, 'changed' => true, 'message' => 'An Euro-Office übergeben.'];
    }

    /**
     * Uebertraegt die Einstellungen an Nextcloud (signiert, an den Inhalt gebunden).
     *
     * @return array{ok:bool,message:string}
     */
    public function pushToNextcloud(): array
    {
        $secret = $this->office->jwtSecret();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).'];
        }

        try {
            $body = json_encode($this->nextcloudPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return ['ok' => false, 'message' => 'Konfiguration konnte nicht erzeugt werden.'];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/ai';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . OfficeJwt::aiConfigToken($secret, $body),
        ], $body, max(10, (int) ($infra['timeout'] ?? 4) * 5));

        if ($response['error'] !== null || $response['status'] === 0) {
            return ['ok' => false, 'message' => 'Nextcloud nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort')];
        }
        if ($response['status'] === 401) {
            return ['ok' => false, 'message' => 'Nextcloud hat die Übergabe abgelehnt (JWT-Secret abweichend).'];
        }
        if ($response['status'] === 404) {
            return ['ok' => false, 'message' => 'Nextcloud-App intranet_integration ist nicht aktiv oder veraltet.'];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Unerwartete Antwort von Nextcloud (HTTP ' . $response['status'] . ').'];
        }

        return [
            'ok' => !empty($data['ok']),
            'message' => Validator::cleanText((string) ($data['message'] ?? (!empty($data['ok']) ? 'An Nextcloud übergeben.' : 'Übergabe fehlgeschlagen.')), 300),
        ];
    }

    /**
     * Gibt den aktuellen Stand an Euro-Office und Nextcloud weiter.
     *
     * @return array{eurooffice:array{ok:bool,changed:bool,message:string},nextcloud:array{ok:bool,message:string}}
     */
    public function apply(): array
    {
        return [
            'eurooffice' => $this->writeEuroOfficeConfig(),
            'nextcloud' => $this->office->isEnabled()
                ? $this->pushToNextcloud()
                : ['ok' => false, 'message' => 'Office ist nicht aktiviert (OFFICE_ENABLED).'],
        ];
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

        $validated = self::validate(['office_ai_enabled' => '1'] + $input);
        $errors = array_intersect_key($validated['errors'], array_flip(['office_ai_url', 'office_ai_model', 'office_ai_timeout', 'office_ai_api_key']));
        if ($errors !== []) {
            $result['errors'] = $errors;

            return $result;
        }

        $values = $validated['values'];
        $url = $values['office_ai_url'];
        $model = $values['office_ai_model'];
        $result['model'] = $model;
        $timeout = max(5, min($maxTimeout, (int) $values['office_ai_timeout']));

        if (array_key_exists('office_ai_api_key', $values) && !$this->apiKeyFromSecret()) {
            $key = $values['office_ai_api_key'];
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
