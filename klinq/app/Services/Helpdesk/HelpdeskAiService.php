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
        'ticket_mail',
        'generic',
    ];

    /** Grenzwerte – im Client (public/js/helpdesk-ai.js) identisch halten. */
    public const MAX_TEXT = 8000;
    public const MAX_PROMPT = 1000;
    public const MAX_CONTEXT = 300;
    public const MAX_WORD = 64;
    public const MAX_SENTENCE = 500;
    public const MAX_WORD_SUGGESTIONS = 5;
    public const MAX_OUTPUT_TOKENS = 1200;
    public const MAX_WORD_TOKENS = 200;
    public const WORD_SUGGEST_TIMEOUT = 20;

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
        'ticket_mail' => 'eine E-Mail eines Help-Desk-Agenten an die meldende Person',
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

        $completion = $this->requestCompletion([
            'model' => $this->settings->model(),
            'messages' => $this->messages($text, $prompt, $mode, $previous, $context),
            'temperature' => 0.3,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'stream' => false,
        ]);
        $cleaned = self::cleanOutput($completion['content']);
        if ($cleaned === '') {
            throw new HttpException('Die KI hat eine ungültige Antwort geliefert.', 502);
        }

        return [
            'text' => $cleaned,
            'usage' => $completion['usage'],
            'model' => $completion['model'],
        ];
    }

    /**
     * Kontextbezogene KI-Wortvorschläge (Korrekturen im Satzkontext oder passende
     * Synonyme/Formulierungsvarianten für ein einzelnes Wort).
     *
     * @param array<string,mixed> $context optionale Felder (subject, ticket)
     * @return array{valid:bool,suggestions:list<string>,usage:array{input_tokens:int,output_tokens:int},model:string}
     */
    public function suggestWords(
        string $word,
        string $sentence = '',
        string $mode = 'generic',
        bool $synonyms = false,
        array $context = []
    ): array {
        $word = self::normalize($word);
        $sentence = self::normalize($sentence);

        if ($word === '') {
            throw new HttpException('Bitte ein Wort angeben.', 422);
        }
        if (mb_strlen($word) > self::MAX_WORD) {
            throw new HttpException('Das Wort ist zu lang (höchstens ' . self::MAX_WORD . ' Zeichen).', 422);
        }
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'generic';
        }
        if (mb_strlen($sentence) > self::MAX_SENTENCE) {
            $sentence = mb_substr($sentence, 0, self::MAX_SENTENCE);
        }

        $completion = $this->requestCompletion([
            'model' => $this->settings->model(),
            'messages' => $this->wordMessages($word, $sentence, $mode, $synonyms, $context),
            'temperature' => $synonyms ? 0.35 : 0.1,
            'max_tokens' => self::MAX_WORD_TOKENS,
            'stream' => false,
        ], self::WORD_SUGGEST_TIMEOUT);

        $parsed = self::parseWordSuggestions($completion['content'], $word, $sentence);

        return [
            'valid' => $synonyms ? true : $parsed['valid'],
            'suggestions' => $parsed['suggestions'],
            'usage' => $completion['usage'],
            'model' => $completion['model'],
        ];
    }

    /**
     * 1-Klick-Korrektur für Rechtschreibung, Grammatik und Zeichensetzung unter
     * striktem Erhalt von Tonfall, Zeilenumbrüchen, @Erwähnungen und Platzhaltern.
     *
     * @param array<string,mixed> $context optionale Felder (subject, ticket)
     * @return array{text:string,changed:bool,usage:array{input_tokens:int,output_tokens:int},model:string}
     */
    public function proofread(string $text, string $mode = 'generic', array $context = []): array
    {
        $text = self::normalize($text);
        if ($text === '') {
            throw new HttpException('Es ist kein Text zum Korrigieren vorhanden.', 422);
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new HttpException('Der Text ist zu lang (höchstens ' . number_format(self::MAX_TEXT, 0, ',', '.') . ' Zeichen).', 422);
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new HttpException('Unbekannter Einsatzort der KI-Unterstützung.', 422);
        }

        $completion = $this->requestCompletion([
            'model' => $this->settings->model(),
            'messages' => $this->proofreadMessages($text, $mode, $context),
            'temperature' => 0.1,
            'max_tokens' => max(self::MAX_OUTPUT_TOKENS, min(2400, (int) ceil(mb_strlen($text) * 0.6) + 200)),
            'stream' => false,
        ]);

        $cleaned = self::cleanOutput($completion['content']);
        if ($cleaned === '') {
            throw new HttpException('Die KI hat eine ungültige Antwort geliefert.', 502);
        }
        if (!self::preservesProtectedTokens($text, $cleaned)) {
            throw new HttpException('Die KI hat eingebettete Platzhalter verändert – der Text blieb unverändert.', 502);
        }

        return [
            'text' => $cleaned,
            'changed' => $cleaned !== $text,
            'usage' => $completion['usage'],
            'model' => $completion['model'],
        ];
    }

    // ------------------------------------------------------------------ intern

    /**
     * Führt einen Chat-Completions-Aufruf gegen den konfigurierten Endpunkt aus.
     *
     * @param array<string,mixed> $payload
     * @return array{content:string,usage:array{input_tokens:int,output_tokens:int},model:string}
     */
    private function requestCompletion(array $payload, ?int $timeoutCap = null): array
    {
        if (!$this->settings->isActive()) {
            throw new HttpException('Die KI-Unterstützung ist derzeit nicht verfügbar.', 503);
        }

        $timeout = max(5, $this->settings->timeout());
        if ($timeoutCap !== null) {
            $timeout = max(5, min($timeout, $timeoutCap));
        }

        try {
            $response = (new AiChatClient($this->transport))->complete(
                $this->settings->url() . '/chat/completions',
                $this->headers(true),
                $payload,
                $timeout
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
            $this->writeCache(false);
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

        return [
            'content' => $content,
            'usage' => [
                'input_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['completion_tokens'] ?? 0)),
            ],
            'model' => $this->settings->model(),
        ];
    }

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

    /**
     * @param array<string,mixed> $context
     * @return list<array{role:string,content:string}>
     */
    private function wordMessages(string $word, string $sentence, string $mode, bool $synonyms, array $context): array
    {
        $contextHint = $this->contextHint($context);
        if ($synonyms) {
            $system = 'Du bist ein Sprachassistent für ' . (self::MODE_LABELS[$mode] ?? 'einen Text') . ' im IT- und Klinik-Help-Desk. '
                . 'Liefere bis zu 5 präzisere, stilistisch passende deutsche Wortalternativen oder Synonyme für das gegebene Zielwort, '
                . 'die grammatikalisch (Kasus, Numerus, Groß-/Kleinschreibung) direkt an genau dieser Stelle in den Satz passen. '
                . 'Jeder Vorschlag ersetzt ausschließlich das einzelne Zielwort – wiederhole KEINE Nachbarwörter aus dem Satzkontext. '
                . 'Antworte ausschließlich mit gültigem JSON im Format {"valid":true,"suggestions":["Wort1","Wort2"]} ohne Markdown oder Erklärungen.'
                . $contextHint;
        } else {
            $system = 'Du bist eine intelligente Rechtschreib- und Kontextprüfung für ' . (self::MODE_LABELS[$mode] ?? 'einen Text') . ' im IT- und Klinik-Help-Desk. '
                . 'Prüfe das Zielwort im gegebenen Satzkontext: Beachte deutsche Rechtschreibung, Grammatik/Beugung, Groß-/Kleinschreibung, '
                . 'Verwechslungswörter (z. B. das/dass, seit/seid, Standart/Standard), zusammengesetzte Begriffe mit Bindestrich sowie gängige IT- und Klinik-Fachbegriffe. '
                . 'Ist das Zielwort im Kontext bereits ein korrekt geschriebener Fachbegriff oder Eigenname, setze "valid":true und gib optional bessere Schreibweisen an (z. B. mit Bindestrich). '
                . 'Ist es fehlerhaft oder unpassend gebeugt, setze "valid":false und liefere bis zu 5 beste Korrekturvorschläge in absteigender Wahrscheinlichkeit, passend zum Satzbau. '
                . 'Jeder Vorschlag ersetzt ausschließlich das einzelne Zielwort (Ausnahme: echte Getrenntschreibung wie "gar nicht") – wiederhole KEINE Nachbarwörter aus dem Satzkontext. '
                . 'Antworte ausschließlich mit gültigem JSON im Format {"valid":false,"suggestions":["Korrektur1","Korrektur2"]} ohne Markdown oder Erklärungen.'
                . $contextHint;
        }

        $user = 'Zielwort: ' . $word;
        if ($sentence !== '') {
            $user .= "\nSatzkontext: " . $sentence;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return list<array{role:string,content:string}>
     */
    private function proofreadMessages(string $text, string $mode, array $context): array
    {
        $system = 'Du bist ein präziser Korrektor für ' . (self::MODE_LABELS[$mode] ?? 'einen Text') . ' in einem Unternehmen. '
            . 'Korrigiere ausschließlich Rechtschreibung, Grammatik, Groß-/Kleinschreibung, Getrennt-/Zusammenschreibung und Zeichensetzung. '
            . 'Verändere NICHT den Stil, Tonfall, Satzbau oder Inhalt und kürze oder erweitere nichts. '
            . 'Bewahre Absätze, Zeilenumbrüche, Aufzählungen, Platzhalter in geschweiften oder eckigen Klammern (z. B. {vorname}, [Bild 1]), '
            . '@Erwähnungen, Ticketnummern, E-Mail-Adressen, URLs, Pfade und Fachbegriffe exakt. '
            . 'Ist der Text bereits fehlerfrei, gib ihn exakt unverändert zurück. '
            . 'Antworte ausschließlich mit dem korrigierten Text – ohne Einleitung, Erklärung, Markdown-Codeblöcke oder umschließende Anführungszeichen.'
            . $this->contextHint($context);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Text:\n\"\"\"\n" . $text . "\n\"\"\""],
        ];
    }

    /** @param array<string,mixed> $context */
    private function contextHint(array $context): string
    {
        $hints = [];
        $subject = self::normalize((string) ($context['subject'] ?? ''));
        if ($subject !== '') {
            $hints[] = 'Betreff: ' . mb_substr($subject, 0, self::MAX_CONTEXT);
        }
        $ticket = self::normalize((string) ($context['ticket'] ?? ''));
        if ($ticket !== '') {
            $hints[] = 'Ticket: ' . mb_substr($ticket, 0, 60);
        }

        return $hints !== [] ? ' Kontext: ' . implode('; ', $hints) . '.' : '';
    }

    /**
     * Liest Wortvorschläge fehlertolerant aus der Modellantwort (JSON-Objekt,
     * JSON-Array oder einfache Aufzählung kleinerer Modelle).
     *
     * @return array{valid:bool,suggestions:list<string>}
     */
    private static function parseWordSuggestions(string $raw, string $originalWord, string $sentence = ''): array
    {
        $clean = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', self::normalize($raw)));
        $valid = false;
        $candidates = [];

        $decoded = json_decode($clean, true);
        if (!is_array($decoded) && preg_match('/\{.*\}/su', $clean, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded) && preg_match('/\[.*\]/su', $clean, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }

        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                $candidates = $decoded;
            } else {
                $valid = !empty($decoded['valid']) || !empty($decoded['known']) || !empty($decoded['known_in_context']);
                $list = $decoded['suggestions'] ?? $decoded['words'] ?? $decoded['alternatives'] ?? [];
                if (is_array($list)) {
                    $candidates = $list;
                }
            }
        } else {
            foreach (preg_split('/[\n,;]+/u', $clean) ?: [] as $part) {
                $part = trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $part));
                if ($part !== '') {
                    $candidates[] = $part;
                }
            }
        }

        $neighbors = self::neighborWords($originalWord, $sentence);
        $keepDot = str_ends_with($originalWord, '.');
        $plainOriginal = rtrim($originalWord, '.');
        $suggestions = [];
        foreach ($candidates as $item) {
            if (!is_string($item)) {
                continue;
            }
            $entry = self::peelQuotes(self::normalize($item));
            if (!$keepDot) {
                $entry = rtrim($entry, '.,;:!?');
            }
            $entry = trim((string) preg_replace('/\s+/u', ' ', $entry));
            if ($entry === '' || $entry === $originalWord || rtrim($entry, '.') === $plainOriginal) {
                continue;
            }
            if (mb_strlen($entry) > 80 || str_contains($entry, "\n")) {
                continue;
            }
            if (str_contains($entry, ' ')) {
                $parts = explode(' ', $entry);
                // Mehr als zwei Teilwörter oder mitgelieferte Nachbarwörter aus dem Satz ausschließen
                if (count($parts) > 2) {
                    continue;
                }
                if ($neighbors['prev'] !== '' && mb_strtolower($parts[0], 'UTF-8') === $neighbors['prev']) {
                    continue;
                }
                if ($neighbors['next'] !== '' && mb_strtolower($parts[count($parts) - 1], 'UTF-8') === $neighbors['next']) {
                    continue;
                }
            }
            if (!in_array($entry, $suggestions, true)) {
                $suggestions[] = $entry;
                if (count($suggestions) >= self::MAX_WORD_SUGGESTIONS) {
                    break;
                }
            }
        }

        return ['valid' => $valid, 'suggestions' => $suggestions];
    }

    /**
     * Ermittelt das unmittelbar vorangehende und nachfolgende Wort im Satzkontext,
     * damit mehrteilige KI-Vorschläge keine bereits vorhandenen Nachbarwörter verdoppeln.
     *
     * @return array{prev:string,next:string}
     */
    private static function neighborWords(string $word, string $sentence): array
    {
        $target = rtrim($word, '.');
        if ($target === '' || $sentence === '') {
            return ['prev' => '', 'next' => ''];
        }
        $quoted = preg_quote($target, '/');
        $prev = '';
        $next = '';
        if (preg_match('/([\p{L}\p{M}0-9\-]+)\s+' . $quoted . '(?![\p{L}\p{M}0-9])/iu', $sentence, $m) === 1) {
            $prev = mb_strtolower($m[1], 'UTF-8');
        }
        if (preg_match('/(?<![\p{L}\p{M}0-9])' . $quoted . '\.?\s+([\p{L}\p{M}0-9\-]+)/iu', $sentence, $m) === 1) {
            $next = mb_strtolower($m[1], 'UTF-8');
        }

        return ['prev' => $prev, 'next' => $next];
    }

    /** Prüft, dass eingebettete Bildmarken ([Bild N]) und Vorlagenplatzhalter ({name}) erhalten blieben. */
    private static function preservesProtectedTokens(string $source, string $result): bool
    {
        if (preg_match_all('/\[Bild\s+\d+\]|\{[a-zA-Z0-9_]+\}/u', $source, $matches) > 0) {
            foreach (array_unique($matches[0]) as $token) {
                if (!str_contains($result, $token)) {
                    return false;
                }
            }
        }

        return true;
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
        $this->availableMemo = $ok;
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

    /** Bindet den Cache an Schalter, Adresse, Modell und Schlüssel-Prüfwert (ohne Schlüssel preiszugeben). */
    private function fingerprint(): string
    {
        $keyTag = $this->settings->apiKey() !== '' ? hash('sha256', $this->settings->apiKey()) : '';

        return substr(sha1(($this->settings->enabled() ? '1' : '0') . '|' . $this->settings->url() . '|' . $this->settings->model() . '|' . $keyTag), 0, 16);
    }
}
