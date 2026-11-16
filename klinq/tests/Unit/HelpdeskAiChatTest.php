<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Exceptions\HttpException;
use App\Repositories\AuditLogRepository;
use App\Repositories\SystemSettingRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\AuditLogService;
use App\Services\Helpdesk\Ai\AiChatClient;
use App\Services\Helpdesk\Ai\AiChatResponse;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Helpdesk\AiSettingsService;
use App\Services\Helpdesk\HelpdeskAiService;
use App\Services\Lanpa\LanpaBridge;
use App\Services\SettingsService;
use App\Support\Secret;
use PDO;
use Tests\Support\TestCase;

/**
 * Auswertung OpenAI-kompatibler Antworten: denkfähige Modelle legen ihre
 * Überlegungen in `reasoning`/`reasoning_content`/`thinking` ab und lassen
 * `content` leer, wenn das Token-Budget für die Antwort nicht mehr reicht.
 */
final class HelpdeskAiChatTest extends TestCase
{
    public function testContentWinsOverReasoning(): void
    {
        $decoded = [
            'choices' => [[
                'message' => ['content' => '  Hallo Welt  ', 'reasoning' => 'erst überlegen'],
            ]],
        ];

        $this->assertSame('Hallo Welt', AiChatResponse::content($decoded));
        $this->assertSame('erst überlegen', AiChatResponse::reasoning($decoded));
        $this->assertFalse(AiChatResponse::reasoningOnly($decoded));
    }

    public function testReasoningOnlyIsDetected(): void
    {
        foreach (['reasoning', 'reasoning_content', 'thinking'] as $key) {
            $decoded = ['choices' => [['message' => ['content' => '', $key => 'Denkspur']]]];

            $this->assertSame('', AiChatResponse::content($decoded), $key);
            $this->assertTrue(AiChatResponse::reasoningOnly($decoded), $key);
        }
    }

    public function testLegacyCompletionTextIsUsed(): void
    {
        $decoded = ['choices' => [['text' => ' Alter Stil ']]];

        $this->assertSame('Alter Stil', AiChatResponse::content($decoded));
        $this->assertFalse(AiChatResponse::reasoningOnly($decoded));
    }

    public function testEmptyOrBrokenAnswersAreHandled(): void
    {
        $this->assertSame('', AiChatResponse::content(null));
        $this->assertSame('', AiChatResponse::content([]));
        $this->assertFalse(AiChatResponse::reasoningOnly([]));
    }

    public function testRequestDisablesReasoning(): void
    {
        $transport = new class implements AiTransportInterface {
            /** @var list<array<string,mixed>> */
            public array $payloads = [];

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                $this->payloads[] = (array) json_decode((string) $body, true);

                return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"ok"}}]}', 'error' => null];
            }
        };

        $result = (new AiChatClient($transport))->complete('http://ki/v1/chat/completions', [], ['model' => 'm'], 10);

        $this->assertSame('ok', $result['content']);
        $this->assertCount(1, $transport->payloads);
        $this->assertSame('none', $transport->payloads[0]['reasoning_effort'] ?? null);
        $this->assertSame('m', $transport->payloads[0]['model'] ?? null);
    }

    public function testUnknownParameterIsRetriedWithoutIt(): void
    {
        $transport = new class implements AiTransportInterface {
            /** @var list<array<string,mixed>> */
            public array $payloads = [];

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                $payload = (array) json_decode((string) $body, true);
                $this->payloads[] = $payload;
                if (array_key_exists('reasoning_effort', $payload)) {
                    return ['status' => 400, 'body' => '{"error":"unknown parameter"}', 'error' => null];
                }

                return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"zweiter Versuch"}}]}', 'error' => null];
            }
        };

        $result = (new AiChatClient($transport))->complete('http://ki/v1/chat/completions', [], ['model' => 'm'], 10);

        $this->assertCount(2, $transport->payloads);
        $this->assertSame('zweiter Versuch', $result['content']);
        $this->assertFalse(array_key_exists('reasoning_effort', $transport->payloads[1]));
    }

    public function testReasoningOnlyResponseIsReported(): void
    {
        $transport = new class implements AiTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                return [
                    'status' => 200,
                    'body' => '{"choices":[{"message":{"content":"","reasoning":"nur gedacht"}}]}',
                    'error' => null,
                ];
            }
        };

        $result = (new AiChatClient($transport))->complete('http://ki/v1/chat/completions', [], ['model' => 'm'], 10);

        $this->assertSame('', $result['content']);
        $this->assertTrue($result['reasoning_only']);
    }

    public function testSuggestWordsParsesJsonAndFiltersOriginalWord(): void
    {
        $transport = $this->fakeAiTransport(static function (string $method, string $url): array {
            if ($method === 'GET') {
                return ['status' => 200, 'body' => '{"data":[{"id":"gemma4:e4b"}]}', 'error' => null];
            }

            return [
                'status' => 200,
                'body' => (string) json_encode([
                    'choices' => [[
                        'message' => [
                            'content' => "```json\n{\"valid\":false,\"suggestions\":[\"Rückmeldung\",\"Rueckmeldung\",\"Rückmeldung\",\"Rückfrage\"]}\n```",
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 18],
                ], JSON_UNESCAPED_UNICODE),
                'error' => null,
            ];
        });
        $ai = $this->makeHelpdeskAi($transport);

        $result = $ai->suggestWords('Rueckmeldung', 'Vielen Dank für Ihre Rueckmeldung.', 'ticket_comment', false, [
            'subject' => 'Drucker defekt',
            'ticket' => 'HD-2026-0042',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame(['Rückmeldung', 'Rückfrage'], $result['suggestions']);
        $this->assertSame('gemma4:e4b', $result['model']);
        $this->assertSame(42, $result['usage']['input_tokens']);
        $this->assertSame(18, $result['usage']['output_tokens']);

        // Mitgelieferte Nachbarwörter ("seit heute") werden verworfen, echte Getrenntschreibung ("gar nicht") bleibt.
        $neighborTransport = $this->fakeAiTransport(static fn (): array => [
            'status' => 200,
            'body' => '{"choices":[{"message":{"content":"{\"valid\":false,\"suggestions\":[\"seit\",\"seit heute\",\"seit heute Morgen\",\"gar nicht\"]}"}}]}',
            'error' => null,
        ]);
        $neighborAi = $this->makeHelpdeskAi($neighborTransport);
        $filtered = $neighborAi->suggestWords('seid', 'Der Drucker streikt seid heute Morgen.', 'ticket_comment');
        $this->assertSame(['seit', 'gar nicht'], $filtered['suggestions']);
    }

    public function testProofreadRejectsCorruptedPlaceholdersAndDetectsChanges(): void
    {
        $call = 0;
        $transport = $this->fakeAiTransport(static function (string $method) use (&$call): array {
            $call++;
            if ($call === 1) {
                return [
                    'status' => 200,
                    'body' => '{"choices":[{"message":{"content":"Hallo Herr Müller, siehe Bild 1."}}]}',
                    'error' => null,
                ];
            }

            return [
                'status' => 200,
                'body' => '{"choices":[{"message":{"content":"Hallo Herr Müller, siehe [Bild 1] und {name}."}}]}',
                'error' => null,
            ];
        });
        $ai = $this->makeHelpdeskAi($transport);

        $threw = false;
        try {
            $ai->proofread('hallo Herr Müller, siehe [Bild 1] und {name}.', 'ticket_mail');
        } catch (HttpException $exception) {
            $threw = true;
            $this->assertSame(502, $exception->statusCode());
        }
        $this->assertTrue($threw, 'Verlorener Bildplatzhalter muss abgefangen werden.');

        $ok = $ai->proofread('hallo Herr Müller, siehe [Bild 1] und {name}.', 'ticket_mail');
        $this->assertTrue($ok['changed']);
        $this->assertSame('Hallo Herr Müller, siehe [Bild 1] und {name}.', $ok['text']);
    }

    public function testAvailabilityCacheInvalidatesOnApiKeyChangeAnd401(): void
    {
        $cacheFile = sys_get_temp_dir() . '/klinq-ai-cache-' . bin2hex(random_bytes(4)) . '.json';
        $apiKey = 'schluessel-eins';
        $modelsCalls = 0;
        $failAuth = false;

        $transport = $this->fakeAiTransport(static function (string $method, string $url) use (&$modelsCalls, &$failAuth): array {
            if ($method === 'GET' && str_ends_with($url, '/models')) {
                $modelsCalls++;

                return ['status' => 200, 'body' => '{"data":[{"id":"gemma4:e4b"}]}', 'error' => null];
            }
            if ($failAuth) {
                return ['status' => 401, 'body' => '{"error":"unauthorized"}', 'error' => null];
            }

            return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"Korrigiert."}}]}', 'error' => null];
        });

        try {
            $aiOne = $this->makeHelpdeskAi($transport, $apiKey, $cacheFile);
            $this->assertTrue($aiOne->isAvailable());
            $this->assertSame(1, $modelsCalls);

            // Gleicher Schlüssel liest aus der Cache-Datei ohne zweiten /models-Aufruf.
            $aiSameKey = $this->makeHelpdeskAi($transport, $apiKey, $cacheFile);
            $this->assertTrue($aiSameKey->isAvailable());
            $this->assertSame(1, $modelsCalls);

            // Geänderter API-Schlüssel ändert den Fingerprint und erzwingt neue Prüfung.
            $aiNewKey = $this->makeHelpdeskAi($transport, 'schluessel-zwei', $cacheFile);
            $this->assertTrue($aiNewKey->isAvailable());
            $this->assertSame(2, $modelsCalls);

            // Ein 401 bei der Anfrage setzt den Verfügbarkeits-Cache auf false.
            $failAuth = true;
            try {
                $aiNewKey->proofread('Testtext.', 'generic');
            } catch (HttpException $exception) {
                $this->assertSame(502, $exception->statusCode());
            }
            $this->assertFalse($aiNewKey->isAvailable());
        } finally {
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        }
    }

    public function testManagedAiSettingsRunTestUsesIntranetUrlAndModelWhenInputEmpty(): void
    {
        $bridgeTransport = $this->fakeAiTransport(static function (string $method, string $url): array {
            if (str_ends_with($url, '/internal/klinq/ki')) {
                return [
                    'status' => 200,
                    'body' => (string) json_encode([
                        'managed' => true,
                        'enabled' => true,
                        'name' => 'Intranet-KI',
                        'url' => 'http://ollama:11434/v1',
                        'model' => 'gemma4:e4b',
                        'timeout' => 45,
                        'api_key' => 'intranet-geheim',
                    ]),
                    'error' => null,
                ];
            }
            if ($method === 'GET' && $url === 'http://ollama:11434/v1/models') {
                return ['status' => 200, 'body' => '{"data":[{"id":"gemma4:e4b"}]}', 'error' => null];
            }

            return [
                'status' => 200,
                'body' => '{"choices":[{"message":{"content":"Bereit."}}],"usage":{"completion_tokens":3}}',
                'error' => null,
            ];
        });

        $settings = $this->makeAiSettings($bridgeTransport, '', true);
        $this->assertTrue($settings->managedByIntranet());

        // Im verwalteten Modus sind die Formularfelder disabled und leer -> Rückgriff auf Intranet-Werte.
        $result = $settings->runTest(['url' => '', 'model' => '', 'prompt' => 'Ping']);
        $this->assertTrue($result['ok']);
        $this->assertSame('Bereit.', $result['answer']);
    }

    public function testSuggestWordsFiltersSelfEchoWithSentenceDotInSynonymsMode(): void
    {
        $transport = $this->fakeAiTransport(static fn (): array => [
            'status' => 200,
            'body' => '{"choices":[{"message":{"content":"{\"suggestions\":[\"tadellos.\",\"einwandfrei\",\"einwandfrei.\",\"fehlerfrei.\"]}"}}]}',
            'error' => null,
        ]);
        $service = $this->makeHelpdeskAi($transport);
        $res = $service->suggestWords('einwandfrei.', 'Das System läuft einwandfrei.', 'ticket_comment', true);
        $this->assertSame(['tadellos.', 'fehlerfrei.'], $res['suggestions']);
    }

    /**
     * @param callable(string,string,array<string,string>,?string,int):array{status:int,body:string,error:?string} $handler
     */
    private function fakeAiTransport(callable $handler): AiTransportInterface
    {
        return new class ($handler) implements AiTransportInterface {
            /** @var callable */
            private $handler;

            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                return ($this->handler)($method, $url, $headers, $body, $timeout);
            }
        };
    }

    private function makeHelpdeskAi(AiTransportInterface $transport, string $apiKey = 'test-key', ?string $cacheFile = null): HelpdeskAiService
    {
        return new HelpdeskAiService($this->makeAiSettings($transport, $apiKey, false), $transport, $cacheFile);
    }

    private function makeAiSettings(AiTransportInterface $transport, string $apiKey, bool $withLanpa): AiSettingsService
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE system_settings (`key` TEXT PRIMARY KEY, value TEXT, updated_by INTEGER)');
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

        $config = Config::fromArray([
            'helpdesk' => [
                'ai' => [
                    'enabled' => true,
                    'name' => 'Test-KI',
                    'url' => 'http://ki:11434/v1',
                    'model' => 'gemma4:e4b',
                    'timeout' => 30,
                    'api_key' => $apiKey,
                ],
            ],
            'lanpa' => [
                'enabled' => $withLanpa,
                'internal_url' => 'http://intranet-app',
                'sso_secret' => 'dienst-token-lang-genug',
            ],
        ]);
        $settingsService = new SettingsService(new SystemSettingRepository($pdo), $config);
        $realPermissions = new Permissions(new Config(dirname(__DIR__, 2) . '/config'));
        $currentUser = new CurrentUser($realPermissions);
        $currentUser->login(['id' => 1, 'username' => 'admin', 'display_name' => 'Admin', 'role' => 'admin']);
        $audit = new AuditLogService(new AuditLogRepository($pdo), $currentUser);
        $lanpa = $withLanpa ? new LanpaBridge($config, $transport) : null;

        return new AiSettingsService($settingsService, $config, new Secret('unit-test-secret'), $audit, $currentUser, $transport, $lanpa);
    }
}
