<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\HttpException;
use App\Services\Helpdesk\Ai\AiChatClient;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use Throwable;

/**
 * KI-Textunterstützung im Help Desk („Mit KI verbessern“).
 *
 * Der Anwender markiert einen Textabschnitt in einem bearbeitbaren Feld und
 * lässt ihn nach einer Anweisung umformulieren. Die Konfiguration stammt aus
 * {@see AiSettingsService}; gespeichert werden weder Anweisungen noch erzeugte
 * Texte. Ist der Endpunkt nicht erreichbar, bleibt die Funktion still inaktiv,
 * der übrige Betrieb ist nicht betroffen.
 */
final class HelpdeskAiService
{
    public const MODES = [
        'ticket_comment',
        'ticket_resolution',
        'internal_note',
        'second_level_note',
        'ticket_description',
        'ticket_subject',
        'generic',
    ];

    /** Grenzwerte – im Client (public/js/helpdesk-ai.js) identisch halten. */
    public const MAX_TEXT = 8000;
    public const MAX_PROMPT = 1000;
    public const MAX_CONTEXT = 300;
    public const MAX_OUTPUT_TOKENS = 1200;

    /** Erreichbarkeit wird höchstens so oft (Sekunden) neu geprüft. */
    public const AVAILABILITY_TTL = 60;
    public const AVAILABILITY_TIMEOUT = 3;

    private const MODE_LABELS = [
        'ticket_comment' => 'eine Antwort an den Kunden in einem Ticketsystem',
        'ticket_resolution' => 'die Lösungsbeschreibung eines Tickets',
        'internal_note' => 'eine interne Notiz zu einem Ticket',
        'second_level_note' => 'eine Notiz zur Übergabe an die zweite Ebene',
        'ticket_description' => 'die Beschreibung eines Tickets',
        'ticket_subject' => 'den Betreff eines Tickets',
        'generic' => 'einen Text in einem Ticketsystem',
    ];

    private ?bool $availableMemo = null;

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly AiTransportInterface $transport,
        private readonly ?string $cacheFile = null
    ) {}

    /** Auswahlliste für die Oberfläche (Wert => Bezeichnung). @return array<string,string> */
    public static function modeLabels(): array
    {
        return self::MODE_LABELS;
    }

    /**
     * Endpunkt konfiguriert und erreichbar (leichtes GET /models, gecacht).
     * Wirft nie – jeder Fehler bedeutet „nicht verfügbar“.
     */
    /** KI-Unterstützung ist eingeschaltet und vollständig konfiguriert (ohne Netzwerkprüfung). */
    public function isEnabled(): bool
    {
        return $this->settings->isActive();
    }

    public function isAvailable(): bool
    {
        if ($this->availableMemo !== null) {
            return $this->availableMemo;
        }
        try {
            if (!$this->settings->isActive()) {
                return $this->availableMemo = false;
            }
            $cached = $this->readCache();
            if ($cached !== null) {
                return $this->availableMemo = $cached;
            }
            $response = $this->transport->request(
                'GET',
                $this->settings->url() . '/models',
                $this->headers(false),
                null,
                self::AVAILABILITY_TIMEOUT
            );
            $ok = $response['error'] === null
                && $response['status'] === 200
                && is_array(json_decode($response['body'], true));
            $this->writeCache($ok);

            return $this->availableMemo = $ok;
        } catch (Throwable) {
            return $this->availableMemo = false;
        }
    }

    /** Erreichbarkeits-Cache verwerfen (nach geänderten Einstellungen). */
    public function resetAvailability(): void
    {
        $this->availableMemo = null;
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    /**
     * Markierten Text nach Anweisung umformulieren. Mit $previous wird eine
     * bereits erzeugte Fassung verfeinert (der Originaltext bleibt Bezug).
     *
     * @param array<string,mixed> $context nur minimale Felder (subject, ticket)
     * @return array{text:string,usage:array{input_tokens:int,output_tokens:int},model:string}
     */
    public function improve(string $text, string $prompt, string $mode = 'generic', ?string $previous = null, array $context = []): array
    {
        $text = self::normalize($text);
        $prompt = self::normalize($prompt);
        $previous = $previous !== null ? self::normalize($previous) : null;

        if ($text === '') {
            throw new HttpException('Bitte zuerst einen Text markieren.', 422);
        }
        if ($prompt === '') {
            throw new HttpException('Bitte eine Anweisung für die KI eingeben.', 422);
        }
        if (mb_strlen($text) > self::MAX_TEXT || ($previous !== null && mb_strlen($previous) > self::MAX_TEXT)) {
            throw new HttpException('Der markierte Text ist zu lang (höchstens ' . number_format(self::MAX_TEXT, 0, ',', '.') . ' Zeichen).', 422);
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            throw new HttpException('Die Anweisung ist zu lang (höchstens ' . number_format(self::MAX_PROMPT, 0, ',', '.') . ' Zeichen).', 422);
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new HttpException('Unbekannter Einsatzort der KI-Unterstützung.', 422);
        }
        if (!$this->settings->isActive()) {
            throw new HttpException('Die KI-Unterstützung ist derzeit nicht verfügbar.', 503);
        }

        $payload = [
            'model' => $this->settings->model(),
            'messages' => $this->messages($text, $prompt, $mode, $previous, $context),
            'temperature' => 0.3,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'stream' => false,
        ];

        try {
            $response = (new AiChatClient($this->transport))->complete(
                $this->settings->url() . '/chat/completions',
                $this->headers(true),
                $payload,
                max(5, $this->settings->timeout())
            );
        } catch (Throwable) {
            $this->writeCache(false);
            throw new HttpException('Die KI ist derzeit nicht erreichbar.', 502);
        }
        if ($response['error'] !== null || $response['status'] === 0) {
            $this->writeCache(false);
            throw new HttpException('Die KI ist derzeit nicht erreichbar.', 502);
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new HttpException('Die KI hat die Anfrage abgelehnt (Zugangsdaten prüfen).', 502);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpException('Die KI konnte die Anfrage nicht verarbeiten.', 502);
        }

        $data = $response['decoded'];
        $content = $response['content'];
        if ($content === '') {
            throw new HttpException(
                $response['reasoning_only']
                    ? 'Das Modell hat nur eine Denkspur ohne Antwort geliefert. Bitte in der Administration ein Modell ohne Denkmodus hinterlegen.'
                    : 'Die KI hat eine ungültige Antwort geliefert.',
                502
            );
        }
        $usage = is_array($data) && is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $cleaned = self::cleanOutput($content);
        if ($cleaned === '') {
            throw new HttpException('Die KI hat eine ungültige Antwort geliefert.', 502);
        }

        return [
            'text' => $cleaned,
            'usage' => [
                'input_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['completion_tokens'] ?? 0)),
            ],
            'model' => $this->settings->model(),
        ];
    }

    // ------------------------------------------------------------------ intern

    /**
     * @param array<string,mixed> $context
     * @return list<array{role:string,content:string}>
     */
    private function messages(string $text, string $prompt, string $mode, ?string $previous, array $context): array
    {
        $system = 'Du bist ein Schreibassistent für ' . (self::MODE_LABELS[$mode] ?? 'einen Text') . ' in einem Unternehmen. '
            . 'Du erhältst einen vom Benutzer markierten Textabschnitt und eine Anweisung. '
            . 'Überarbeite ausschließlich diesen Abschnitt gemäß der Anweisung. '
            . 'Antworte nur mit dem überarbeiteten Text – ohne Anrede, Erklärung, Anführungszeichen, Markdown oder Hinweise. '
            . 'Behalte Sprache (in der Regel Deutsch), Bedeutung, Namen, Zahlen und Fakten bei; erfinde nichts hinzu.';

        $hints = [];
        $subject = self::normalize((string) ($context['subject'] ?? ''));
        if ($subject !== '') {
            $hints[] = 'Betreff: ' . mb_substr($subject, 0, self::MAX_CONTEXT);
        }
        $ticket = self::normalize((string) ($context['ticket'] ?? ''));
        if ($ticket !== '') {
            $hints[] = 'Ticket: ' . mb_substr($ticket, 0, 60);
        }
        if ($hints !== []) {
            $system .= ' Kontext: ' . implode('; ', $hints) . '.';
        }

        $messages = [['role' => 'system', 'content' => $system]];
        $messages[] = ['role' => 'user', 'content' => "Markierter Text:\n\"\"\"\n" . $text . "\n\"\"\"\n\nAnweisung: " . $prompt];
        if ($previous !== null && $previous !== '') {
            // Verfeinern: die vorige Fassung ist die letzte Antwort des Assistenten.
            $messages[1]['content'] = "Markierter Text:\n\"\"\"\n" . $text . "\n\"\"\"\n\nAnweisung: Überarbeite den Text.";
            $messages[] = ['role' => 'assistant', 'content' => $previous];
            $messages[] = ['role' => 'user', 'content' => 'Verfeinere deine Fassung: ' . $prompt . "\nAntworte wieder nur mit dem überarbeiteten Text."];
        }

        return $messages;
    }

    /** @return array<string,string> */
    private function headers(bool $json): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        $apiKey = $this->settings->apiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
    }

    private static function normalize(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim($value);
    }

    /** Antworttext von Umrahmungen befreien, die kleine Modelle gern anhängen. */
    private static function cleanOutput(string $content): string
    {
        $content = self::normalize($content);
        $content = (string) preg_replace('/^```[a-z]*\n?|\n?```$/i', '', $content);
        $content = (string) preg_replace('/^(Überarbeiteter Text|Markierter Text|Text|Antwort)\s*:\s*/iu', '', $content);
        $content = self::peelQuotes($content);
        // Kleine Modelle wiederholen gern das Prompt-Gerüst: alles ab einer
        // eigenen """-Zeile oder einer „Anweisung:“-Zeile nach dem Text abschneiden.
        $cut = preg_split('/\n\s*(?:"""|Anweisung\s*:)/u', $content, 2);
        if (is_array($cut) && trim($cut[0]) !== '') {
            $content = self::peelQuotes($cut[0]);
        }

        return trim($content);
    }

    /** Umschließende Anführungszeichen und leere Anführungszeilen schrittweise abschälen. */
    private static function peelQuotes(string $content): string
    {
        for ($i = 0; $i < 3; $i++) {
            $before = $content;
            $content = trim((string) preg_replace('/^(?:["„“‚‘\']{1,3}\s*\n)+|(?:\n\s*["“”‘’\']{1,3})+$/u', '', trim($content)));
            if (mb_strlen($content) >= 2 && preg_match('/^(["„“‚‘\'])(.*)(["“”‘’\'])$/su', $content, $m) === 1) {
                $content = trim($m[2]);
            }
            if ($content === $before) {
                break;
            }
        }

        return $content;
    }

    private function readCache(): ?bool
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (!is_array($data) || !isset($data['ok'], $data['checked'], $data['fingerprint'])) {
            return null;
        }
        if ($data['fingerprint'] !== $this->fingerprint() || time() - (int) $data['checked'] > self::AVAILABILITY_TTL) {
            return null;
        }

        return (bool) $data['ok'];
    }

    private function writeCache(bool $ok): void
    {
        if ($this->cacheFile === null) {
            return;
        }
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $this->cacheFile,
            (string) json_encode(['ok' => $ok, 'checked' => time(), 'fingerprint' => $this->fingerprint()]),
            LOCK_EX
        );
    }

    /** Bindet den Cache an Adresse und Modell (ohne Schlüssel preiszugeben). */
    private function fingerprint(): string
    {
        return substr(sha1($this->settings->url() . '|' . $this->settings->model()), 0, 16);
    }
}
