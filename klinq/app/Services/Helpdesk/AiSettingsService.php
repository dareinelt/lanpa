<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\Helpdesk\Ai\AiChatClient;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Lanpa\LanpaBridge;
use App\Services\SettingsService;
use App\Support\Secret;
use App\Support\Validator;

/**
 * Konfiguration der KI-Unterstützung im Help Desk („Auswertung & System → Administration → KI“).
 *
 * Angesprochen wird ein OpenAI-kompatibler Endpunkt (Ollama, vLLM, LocalAI, LM Studio, …) inklusive
 * `/v1`. Der API-Schlüssel wird verschlüsselt in `system_settings` abgelegt (siehe Support\Secret) und
 * nie an die Oberfläche zurückgegeben – dort ist nur sichtbar, ob einer hinterlegt ist.
 */
final class AiSettingsService
{
    /** Präfix aller Schlüssel in system_settings. */
    public const PREFIX = 'helpdesk.ai.';

    /** Vorgabe für den Test im Administrationsformular. */
    public const DEFAULT_TEST_PROMPT = 'Wer bist du?';

    private const BOOLEANS = ['enabled'];
    private const INTEGERS = ['timeout'];

    /** Obergrenze für die angezeigte Testantwort (Zeichen). */
    private const TEST_MAX_ANSWER = 4000;
    private const TEST_MAX_TOKENS = 400;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Secret $secret,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly AiTransportInterface $transport,
        private readonly ?LanpaBridge $lanpa = null
    ) {}

    /**
     * Effektive Konfiguration (Datenbank vor Umgebung), ohne Schlüssel.
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);
        $result = [];
        foreach ($this->defaults() as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($value === null || ($value === '' && !is_string($default))) {
                $result[$key] = $default;
                continue;
            }
            $result[$key] = match (true) {
                in_array($key, self::BOOLEANS, true) => $value === '1' || strtolower($value) === 'true',
                in_array($key, self::INTEGERS, true) => (int) $value,
                default => $value,
            };
        }

        $managed = $this->lanpaAi();
        if ($managed !== null) {
            $result['enabled'] = $managed['enabled'];
            $result['name'] = $managed['name'];
            $result['url'] = $managed['url'];
            $result['model'] = $managed['model'];
            $result['timeout'] = $managed['timeout'];
        }

        $result['api_key_set'] = $this->apiKey() !== '';
        $result['api_key_unreadable'] = $this->apiKeyUnreadable();
        $result['managed_by_intranet'] = $managed !== null;

        return $result;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', false);
    }

    public function name(): string
    {
        $name = trim((string) $this->get('name', ''));

        return $name !== '' ? $name : 'Lokale KI';
    }

    /** Endpunkt inklusive `/v1`, ohne abschließenden Schrägstrich. */
    public function url(): string
    {
        return rtrim((string) $this->get('url', ''), '/');
    }

    public function model(): string
    {
        return (string) $this->get('model', '');
    }

    public function timeout(): int
    {
        return max(5, (int) $this->get('timeout', 60));
    }

    /** API-Schlüssel im Klartext (leer, wenn keiner hinterlegt oder nicht entschlüsselbar). */
    public function apiKey(): string
    {
        $managed = $this->lanpaAi();
        if ($managed !== null) {
            return $managed['api_key'];
        }

        $stored = $this->settings->withPrefix(self::PREFIX)['api_key'] ?? '';
        if ($stored === '') {
            return (string) $this->config->get('helpdesk.ai.api_key', '');
        }

        return $this->secret->decrypt($stored) ?? '';
    }

    /** Das Intranet gibt Endpunkt, Modell und Schlüssel vor (Administration nur lesend). */
    public function managedByIntranet(): bool
    {
        return $this->lanpaAi() !== null;
    }

    /**
     * KI-Konfiguration aus dem Intranet, sofern die Anbindung aktiv ist und
     * das Intranet einen Endpunkt meldet.
     *
     * @return array{enabled:bool,name:string,url:string,model:string,timeout:int,api_key:string}|null
     */
    private function lanpaAi(): ?array
    {
        return $this->lanpa?->aiConfig();
    }

    /** Ein Schlüssel ist hinterlegt, lässt sich aber nicht entschlüsseln (APP_KEY geändert). */
    public function apiKeyUnreadable(): bool
    {
        $stored = $this->settings->withPrefix(self::PREFIX)['api_key'] ?? '';

        return $stored !== '' && $this->secret->decrypt($stored) === null;
    }

    /** Endpunkt und Modell sind gesetzt. */
    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->model() !== '';
    }

    /** KI-Unterstützung ist eingeschaltet und vollständig konfiguriert. */
    public function isActive(): bool
    {
        return $this->enabled() && $this->isConfigured();
    }

    /**
     * Konfiguration aus dem Formular speichern. Ein leeres Schlüsselfeld lässt den gespeicherten
     * Schlüssel unverändert.
     * @param array<string,mixed> $input
     * @return array<string,mixed> Neue effektive Konfiguration
     */
    public function save(array $input): array
    {
        $this->currentUser->require('settings.manage');
        $before = $this->all();
        $data = $this->validate($input, $before);

        $values = [];
        foreach ($data as $key => $value) {
            $values[self::PREFIX . $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        if (!empty($input['remove_api_key'])) {
            $values[self::PREFIX . 'api_key'] = '';
        } elseif ($apiKey !== '') {
            $values[self::PREFIX . 'api_key'] = $this->secret->encrypt($apiKey);
        }
        $this->settings->setMany($values, $this->currentUser->id());

        $after = $this->all();
        $this->audit->log('update', 'settings', null, 'Help-Desk-KI', $this->auditView($before), $this->auditView($after));

        return $after;
    }

    /**
     * Eingaben prüfen und in typisierte Werte überführen.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public function validate(array $input, array $current): array
    {
        $v = new Validator($input);
        $v->bool('enabled')
            ->string('name', 'Anzeigename', false, 60)
            ->string('url', 'Adresse des Endpunkts', false, 2048)
            ->string('model', 'Modell', false, 200)
            ->int('timeout', 'Zeitlimit', true, 5, 900);

        $enabled = (bool) $v->value('enabled');
        $url = (string) ($v->value('url') ?? '');
        if ($url !== '' && !self::isValidUrl($url)) {
            $v->addError('url', 'Bitte eine vollständige http(s)-Adresse ohne Leerzeichen, Zugangsdaten und Parameter angeben (z. B. http://ki-server:11434/v1).');
        }
        $model = (string) ($v->value('model') ?? '');
        if ($model !== '' && !self::isValidModel($model)) {
            $v->addError('model', 'Der Modellname enthält unzulässige Zeichen.');
        }
        if ($enabled) {
            if ($url === '') {
                $v->addError('url', 'Adresse des Endpunkts ist erforderlich, wenn die KI aktiv ist.');
            }
            if ($model === '') {
                $v->addError('model', 'Modell ist erforderlich, wenn die KI aktiv ist.');
            }
        }
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        if ($apiKey !== '' && (strlen($apiKey) > 4096 || preg_match('/[\s\x00-\x1F\x7F]/', $apiKey) === 1)) {
            $v->addError('api_key', 'Der API-Schlüssel darf keine Leerzeichen oder Steuerzeichen enthalten und höchstens 4096 Zeichen lang sein.');
        }

        $data = $v->validated();
        $clean = [];
        foreach ($this->defaults() as $key => $default) {
            $value = $data[$key] ?? null;
            $clean[$key] = match (true) {
                in_array($key, self::BOOLEANS, true) => (bool) $value,
                in_array($key, self::INTEGERS, true) => $value === null ? $default : (int) $value,
                default => $value === null ? '' : (string) $value,
            };
        }
        $clean['url'] = $clean['url'] !== '' ? rtrim($clean['url'], '/') : '';
        $clean['timeout'] = $clean['timeout'] > 0 ? $clean['timeout'] : (int) $this->defaults()['timeout'];

        return $clean;
    }

    // ------------------------------------------------------------------ Verbindungstest

    /**
     * Verbindung, Modell und Testnachricht prüfen.
     *
     * Getestet werden die übergebenen Formularwerte – so lässt sich die Verbindung prüfen, bevor
     * gespeichert wird. Ein bereits gespeicherter Schlüssel wird nur an die gespeicherte Adresse gesendet.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,steps:list<array{label:string,ok:bool,message:string}>,prompt:string,answer:string,model:string,duration_ms:int,tokens:?int}
     */
    public function runTest(array $input): array
    {
        $this->currentUser->require('settings.manage');

        $url = rtrim(trim((string) ($input['url'] ?? '')), '/');
        $model = trim((string) ($input['model'] ?? ''));
        $timeout = (int) ($input['timeout'] ?? $this->timeout());
        $timeout = max(5, min(900, $timeout));
        $prompt = trim((string) ($input['test_prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = self::DEFAULT_TEST_PROMPT;
        }

        $errors = [];
        $steps = [];
        if ($url === '' || !self::isValidUrl($url)) {
            $errors['url'] = 'Bitte eine vollständige http(s)-Adresse angeben (inkl. /v1).';
        }
        if ($model === '' || !self::isValidModel($model)) {
            $errors['model'] = 'Bitte das Modell angeben (Schaltfläche „Modell ermitteln“ hilft dabei).';
        }
        if ($prompt === '' || mb_strlen($prompt) > 1000) {
            $errors['test_prompt'] = 'Die Testfrage darf höchstens 1000 Zeichen lang sein.';
        }

        $typedKey = trim((string) ($input['api_key'] ?? ''));
        $apiKey = $typedKey;
        if ($typedKey === '') {
            if ($url === $this->url()) {
                $apiKey = $this->apiKey();
            } else {
                $steps[] = [
                    'label' => 'Zugangsdaten',
                    'ok' => true,
                    'message' => 'Der gespeicherte Schlüssel wird nur an die gespeicherte Adresse gesendet; getestet wird ohne Schlüssel.',
                ];
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'steps' => $steps,
                'prompt' => $prompt,
                'answer' => '',
                'model' => $model,
                'duration_ms' => 0,
                'tokens' => null,
            ];
        }

        $check = $this->checkModels($url, $model, $apiKey, min(30, $timeout));
        $steps[] = ['label' => 'Endpunkt und Modell', 'ok' => $check['ok'], 'message' => $check['message']];
        if (!$check['ok']) {
            return [
                'ok' => false,
                'errors' => [],
                'steps' => $steps,
                'prompt' => $prompt,
                'answer' => '',
                'model' => $model,
                'duration_ms' => 0,
                'tokens' => null,
            ];
        }

        $started = microtime(true);
        $result = $this->chat($url, $model, $apiKey, $prompt, $timeout);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $steps[] = ['label' => 'Testnachricht', 'ok' => $result['ok'], 'message' => $result['message']];

        return [
            'ok' => $result['ok'],
            'errors' => [],
            'steps' => $steps,
            'prompt' => $prompt,
            'answer' => $result['answer'],
            'model' => $model,
            'duration_ms' => $duration,
            'tokens' => $result['tokens'],
        ];
    }

    /**
     * Modelle des Endpunkts abrufen (`GET {url}/models`) – Grundlage für die Schaltfläche „Modell ermitteln“.
     * @return array{ok:bool,models:list<string>,message:string}
     */
    public function fetchModels(?string $url = null, ?string $apiKey = null, ?int $timeout = null): array
    {
        $url = $url !== null ? rtrim(trim($url), '/') : $this->url();
        $apiKey = $apiKey ?? $this->apiKey();
        $timeout = $timeout ?? min(30, $this->timeout());
        if ($url === '' || !self::isValidUrl($url)) {
            return ['ok' => false, 'models' => [], 'message' => 'Bitte zuerst die Adresse des Endpunkts angeben (inkl. /v1).'];
        }
        $response = $this->transport->request('GET', $url . '/models', $this->authHeaders($apiKey), null, max(5, $timeout));
        if ($response['status'] === 0) {
            return ['ok' => false, 'models' => [], 'message' => 'Der Endpunkt ist nicht erreichbar' . ($response['error'] !== null ? ': ' . $response['error'] : '.')];
        }
        if (in_array($response['status'], [401, 403], true)) {
            return ['ok' => false, 'models' => [], 'message' => 'Der Endpunkt verweigert den Zugriff (' . $response['status'] . '). Bitte den API-Schlüssel prüfen.'];
        }
        if ($response['status'] !== 200) {
            return ['ok' => false, 'models' => [], 'message' => 'Der Endpunkt antwortet mit Status ' . $response['status'] . '. Ist die Adresse inklusive /v1 angegeben?'];
        }
        $decoded = json_decode($response['body'], true);
        $items = [];
        if (is_array($decoded)) {
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $items = $decoded['data'];
            } elseif (isset($decoded['models']) && is_array($decoded['models'])) {
                $items = $decoded['models'];
            }
        }
        if ($items === []) {
            return ['ok' => false, 'models' => [], 'message' => 'Der Endpunkt liefert keine Modellliste.'];
        }
        $models = [];
        foreach ($items as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? $item['name'] ?? '') : (string) $item;
            $id = trim($id);
            if ($id !== '') {
                $models[] = $id;
            }
        }
        $models = array_values(array_unique($models));
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);
        if ($models === []) {
            return ['ok' => false, 'models' => [], 'message' => 'Der Endpunkt liefert keine Modellnamen.'];
        }

        return ['ok' => true, 'models' => $models, 'message' => sprintf('%d Modell(e) gefunden.', count($models))];
    }

    /**
     * Prüft, ob Endpunkt und Modell zusammenpassen.
     * @return array{ok:bool,reachable:bool,message:string}
     */
    public function checkModels(string $url, string $model, string $apiKey, int $timeout): array
    {
        $result = $this->fetchModels($url, $apiKey, $timeout);
        if (!$result['ok']) {
            return ['ok' => false, 'reachable' => false, 'message' => $result['message']];
        }
        if (!in_array($model, $result['models'], true)) {
            $shown = array_slice($result['models'], 0, 8);

            return [
                'ok' => false,
                'reachable' => true,
                'message' => sprintf(
                    'Der Endpunkt ist erreichbar, bietet das Modell „%s“ aber nicht an. Verfügbar: %s%s.',
                    $model,
                    implode(', ', $shown),
                    count($result['models']) > count($shown) ? ' …' : ''
                ),
            ];
        }

        return ['ok' => true, 'reachable' => true, 'message' => 'Endpunkt erreichbar, Modell „' . $model . '“ ist verfügbar.'];
    }

    /**
     * Testnachricht an das Modell senden.
     * @return array{ok:bool,message:string,answer:string,tokens:?int}
     */
    private function chat(string $url, string $model, string $apiKey, string $prompt, int $timeout): array
    {
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
            'max_tokens' => self::TEST_MAX_TOKENS,
            'stream' => false,
        ];
        $headers = $this->authHeaders($apiKey, true);
        $response = (new AiChatClient($this->transport))->complete(
            $url . '/chat/completions',
            $headers,
            $payload,
            max(5, $timeout)
        );
        if ($response['status'] === 0) {
            return ['ok' => false, 'message' => 'Der Endpunkt ist nicht erreichbar' . ($response['error'] !== null ? ': ' . $response['error'] : '.'), 'answer' => '', 'tokens' => null];
        }
        if (in_array($response['status'], [401, 403], true)) {
            return ['ok' => false, 'message' => 'Der Endpunkt verweigert den Zugriff (' . $response['status'] . '). Bitte den API-Schlüssel prüfen.', 'answer' => '', 'tokens' => null];
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['ok' => false, 'message' => 'Der Endpunkt konnte die Anfrage nicht verarbeiten (Status ' . $response['status'] . ').', 'answer' => '', 'tokens' => null];
        }
        $content = $response['content'];
        $decoded = $response['decoded'];
        if ($content === '') {
            $message = $response['reasoning_only']
                ? 'Das Modell hat nur eine Denkspur ohne Antwort geliefert. Bitte ein Modell ohne Denkmodus hinterlegen.'
                : 'Der Endpunkt hat eine unerwartete Antwort geliefert.';

            return ['ok' => false, 'message' => $message, 'answer' => '', 'tokens' => null];
        }
        $tokens = isset($decoded['usage']['completion_tokens']) ? (int) $decoded['usage']['completion_tokens'] : null;
        $answer = mb_substr($content, 0, self::TEST_MAX_ANSWER);

        return [
            'ok' => true,
            'message' => 'Antwort erhalten' . ($tokens !== null ? ' (' . $tokens . ' Token)' : '') . '.',
            'answer' => $answer,
            'tokens' => $tokens,
        ];
    }

    // ------------------------------------------------------------------ Prüfungen

    /** Nur http(s), Host erforderlich, keine Zugangsdaten/Parameter/Fragmente. */
    public static function isValidUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        if (preg_match('/[\s\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if (trim((string) ($parts['host'] ?? '')) === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return true;
    }

    /** Modellname, wie ihn der Endpunkt meldet (auch Pfade für llama.cpp). */
    public static function isValidModel(string $model): bool
    {
        return preg_match('#^[A-Za-z0-9/][A-Za-z0-9._:/@+-]{0,199}$#', $model) === 1;
    }

    // ------------------------------------------------------------------ Intern

    /** @return array<string,mixed> Startwerte aus der Umgebung (config/helpdesk.php) */
    private function defaults(): array
    {
        $env = (array) $this->config->get('helpdesk.ai', []);

        return [
            'enabled' => (bool) ($env['enabled'] ?? false),
            'name' => (string) ($env['name'] ?? ''),
            'url' => rtrim((string) ($env['url'] ?? ''), '/'),
            'model' => (string) ($env['model'] ?? ''),
            'timeout' => (int) ($env['timeout'] ?? 60),
        ];
    }

    /** @return array<string,string> */
    private function authHeaders(string $apiKey, bool $json = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> Werte für das Audit-Log (ohne Geheimnisse) */
    private function auditView(array $values): array
    {
        unset($values['api_key_unreadable']);
        $values['api_key_set'] = !empty($values['api_key_set']) ? 'ja' : 'nein';

        return array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? 'ja' : 'nein') : (string) $v, $values);
    }
}
