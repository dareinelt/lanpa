<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\AiTransportInterface;
use App\Repositories\OrvantaRepository;
use App\Services\Office\OfficeAiService;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * KI-Textunterstuetzung in Orvanta (Verfassen, Antworten, Weiterleiten,
 * Termine, Erinnerungen).
 *
 * Das Modell wird NICHT in Orvanta konfiguriert, sondern aus den globalen
 * KI-Einstellungen des Adminbereichs (OfficeAiService, Tabelle settings)
 * uebernommen. Orvanta speichert keine Prompts und keine erzeugten Texte -
 * nur Zaehler (orvanta_ai_usage). Faellt der KI-Endpunkt aus, ist die
 * Funktion still inaktiv; der uebrige Betrieb bleibt unberuehrt.
 *
 * Der Browser markiert KI-Text im Editor mit einem Wrapper
 * (<span class="ov-ai-block" data-ov-ai-block="…">). Vor dem Versand entfernt
 * stripMarkers() alle diese Spuren serverseitig - der Empfaenger erfaehrt
 * nichts vom Workflow.
 */
final class OrvantaAiService
{
    public const MODES = ['mail_compose', 'mail_reply', 'mail_forward', 'event', 'reminder'];

    /** Grenzwerte (Client synchron halten, orvanta.js AI_MAX_*). */
    public const MAX_TEXT = 8000;
    public const MAX_PROMPT = 1000;
    public const MAX_CONTEXT = 300;
    public const MAX_OUTPUT_TOKENS = 1200;

    /** Marker im Editor-HTML (nur clientseitig, werden vor dem Versand entfernt). */
    public const MARKER_CLASS = 'ov-ai-block';
    public const MARKER_ATTR_PREFIX = 'data-ov-ai';

    /** Erreichbarkeit wird hoechstens so oft (Sekunden) neu geprueft. */
    public const AVAILABILITY_TTL = 60;
    public const AVAILABILITY_TIMEOUT = 3;

    private const MODE_LABELS = [
        'mail_compose' => 'eine neue E-Mail',
        'mail_reply' => 'eine Antwort auf eine E-Mail',
        'mail_forward' => 'den Begleittext einer weitergeleiteten E-Mail',
        'event' => 'die Beschreibung eines Kalendertermins',
        'reminder' => 'den Text einer Terminerinnerung',
    ];

    private ?bool $availableMemo = null;

    public function __construct(
        private readonly OfficeAiService $ai,
        private readonly AiTransportInterface $transport,
        private readonly OrvantaRepository $repository,
        private readonly ?string $cacheFile = null,
        private readonly int $timeout = 30
    ) {
    }

    /**
     * Modell konfiguriert und Endpunkt erreichbar (leichtes GET /models,
     * Ergebnis gecacht). Wirft nie - jeder Fehler bedeutet "nicht verfuegbar".
     */
    public function isAvailable(): bool
    {
        if ($this->availableMemo !== null) {
            return $this->availableMemo;
        }
        try {
            if (!$this->ai->isActive()) {
                return $this->availableMemo = false;
            }
            $cached = $this->readCache();
            if ($cached !== null) {
                return $this->availableMemo = $cached;
            }
            $response = $this->transport->request('GET', $this->ai->url() . '/models', $this->headers(false), null, self::AVAILABILITY_TIMEOUT);
            $ok = $response['error'] === null && $response['status'] === 200 && is_array(json_decode($response['body'], true));
            $this->writeCache($ok);

            return $this->availableMemo = $ok;
        } catch (Throwable) {
            return $this->availableMemo = false;
        }
    }

    /**
     * Setzt den Erreichbarkeits-Cache zurueck (z. B. nach geaenderten Einstellungen).
     */
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
     * @param array<string,mixed> $context nur minimale Felder (subject, recipients)
     *
     * @return array{text:string,usage:array{input_tokens:int,output_tokens:int},model:string}
     */
    public function improve(string $text, string $prompt, string $mode, ?string $previous = null, array $context = []): array
    {
        $text = self::normalize($text);
        $prompt = self::normalize($prompt);
        $previous = $previous !== null ? self::normalize($previous) : null;
        if ($text === '') {
            throw new OrvantaException('Bitte zuerst einen Text markieren.', 422);
        }
        if ($prompt === '') {
            throw new OrvantaException('Bitte eine Anweisung für die KI eingeben.', 422);
        }
        if (mb_strlen($text) > self::MAX_TEXT || ($previous !== null && mb_strlen($previous) > self::MAX_TEXT)) {
            throw new OrvantaException('Der markierte Text ist zu lang (höchstens ' . number_format(self::MAX_TEXT, 0, ',', '.') . ' Zeichen).', 422);
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            throw new OrvantaException('Die Anweisung ist zu lang (höchstens ' . number_format(self::MAX_PROMPT, 0, ',', '.') . ' Zeichen).', 422);
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new OrvantaException('Unbekannter Einsatzort der KI-Unterstützung.', 422);
        }
        if (!$this->ai->isActive()) {
            throw new OrvantaException('Die KI-Unterstützung ist derzeit nicht verfügbar.', 503);
        }

        $payload = [
            'model' => $this->ai->model(),
            'messages' => $this->messages($text, $prompt, $mode, $previous, $context),
            'temperature' => 0.3,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'stream' => false,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new OrvantaException('Die Anfrage an die KI konnte nicht erzeugt werden.', 422);
        }

        try {
            $response = $this->transport->request('POST', $this->ai->url() . '/chat/completions', $this->headers(true), $body, max(5, $this->timeout));
        } catch (Throwable $exception) {
            $this->writeCache(false);
            throw new OrvantaException('Die KI ist derzeit nicht erreichbar.', 502, $exception);
        }
        if ($response['error'] !== null || $response['status'] === 0) {
            $this->writeCache(false);
            throw new OrvantaException('Die KI ist derzeit nicht erreichbar.', 502);
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new OrvantaException('Die KI hat die Anfrage abgelehnt (Zugangsdaten prüfen).', 502);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new OrvantaException('Die KI konnte die Anfrage nicht verarbeiten.', 502);
        }
        $data = json_decode($response['body'], true);
        $content = is_array($data) ? ($data['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($content) || trim($content) === '') {
            throw new OrvantaException('Die KI hat eine ungültige Antwort geliefert.', 502);
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return [
            'text' => self::cleanOutput($content),
            'usage' => [
                'input_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['completion_tokens'] ?? 0)),
            ],
            'model' => $this->ai->model(),
        ];
    }

    /**
     * Nur Zaehler - keine Inhalte, keine IP-Adressen. Fehler werden
     * verschluckt (die Antwort an den Benutzer darf daran nicht scheitern).
     */
    public function recordUsage(string $uid, string $kind, int $inputTokens, int $outputTokens, string $model = ''): void
    {
        if (!in_array($kind, self::MODES, true)) {
            return;
        }
        try {
            $this->repository->recordAiUsage($uid, $kind, $inputTokens, $outputTokens, $model, date('Y-m-d H:i:s'));
        } catch (Throwable) {
            // bewusst ignoriert
        }
    }

    /**
     * Entfernt alle Orvanta-KI-Marker aus zu versendendem HTML: Wrapper-Elemente
     * werden ausgepackt, ov-ai-*-Klassen und data-ov-ai-*-Attribute sowie
     * Kommentare mit Bezug entfernt. Normales HTML bleibt unveraendert.
     */
    public static function stripMarkers(string $html): string
    {
        if ($html === '' || (stripos($html, 'ov-ai') === false)) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="orvanta-ai-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $dom->getElementById('orvanta-ai-root') : null;
        if ($root === null) {
            // Notfall: Marker per Textersetzung entfernen, Inhalt bleibt erhalten.
            return self::stripMarkersByRegex($html);
        }

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//comment()', $root) ?: [] as $comment) {
            if ($comment instanceof DOMComment && stripos($comment->data, 'ov-ai') !== false) {
                $comment->parentNode?->removeChild($comment);
            }
        }

        $elements = [];
        foreach ($xpath->query('.//*', $root) ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }
        // Von innen nach aussen, damit verschachtelte Wrapper sauber ausgepackt werden.
        foreach (array_reverse($elements) as $element) {
            self::cleanElement($element);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private static function cleanElement(DOMElement $element): void
    {
        $marked = false;
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (str_starts_with($name, self::MARKER_ATTR_PREFIX)) {
                $element->removeAttribute($attribute->nodeName);
                $marked = true;
            }
        }
        if ($element->hasAttribute('class')) {
            $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
            $kept = array_values(array_filter($classes, static fn (string $class): bool => $class !== '' && !str_starts_with($class, 'ov-ai')));
            if (count($kept) !== count(array_filter($classes, 'strlen'))) {
                $marked = true;
                if ($kept === []) {
                    $element->removeAttribute('class');
                } else {
                    $element->setAttribute('class', implode(' ', $kept));
                }
            }
        }
        if ($marked && $element->hasAttribute('title')) {
            $element->removeAttribute('title');
        }
        // Wrapper ohne weitere Bedeutung auspacken.
        if ($marked && strtolower($element->tagName) === 'span' && !$element->hasAttributes()) {
            $parent = $element->parentNode;
            if ($parent instanceof DOMNode) {
                while ($element->firstChild !== null) {
                    $parent->insertBefore($element->firstChild, $element);
                }
                $parent->removeChild($element);
            }
        }
    }

    private static function stripMarkersByRegex(string $html): string
    {
        $html = (string) preg_replace('/<!--[^>]*ov-ai[\s\S]*?-->/i', '', $html);
        $html = (string) preg_replace('/\s+data-ov-ai[a-z0-9_-]*(=("[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $html);
        $html = (string) preg_replace_callback('/\sclass=("([^"]*)"|\'([^\']*)\')/i', static function (array $match): string {
            $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $kept = array_filter(preg_split('/\s+/', trim($value)) ?: [], static fn (string $class): bool => $class !== '' && !str_starts_with($class, 'ov-ai'));

            return $kept === [] ? '' : ' class="' . implode(' ', $kept) . '"';
        }, $html);

        return $html;
    }

    // ------------------------------------------------------------------ intern

    /**
     * @param array<string,mixed> $context
     *
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
        $recipients = (int) ($context['recipients'] ?? 0);
        if ($recipients > 0) {
            $hints[] = 'Anzahl der Empfänger: ' . min(999, $recipients);
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
     * @return array<string,string>
     */
    private function headers(bool $json): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->ai->hasApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $this->ai->apiKey();
        }

        return $headers;
    }

    private static function normalize(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim($value);
    }

    /**
     * Antworttext von Umrahmungen befreien, die kleine Modelle gern anhaengen.
     */
    private static function cleanOutput(string $content): string
    {
        $content = self::normalize($content);
        $content = (string) preg_replace('/^```[a-z]*\n?|\n?```$/i', '', $content);
        $content = (string) preg_replace('/^(Überarbeiteter Text|Markierter Text|Text|Antwort)\s*:\s*/iu', '', $content);
        $content = self::peelQuotes($content);
        // Kleine Modelle wiederholen gern das Prompt-Geruest: alles ab einer
        // eigenen """-Zeile oder einer "Anweisung:"-Zeile nach dem Text abschneiden.
        $cut = preg_split('/\n\s*(?:"""|Anweisung\s*:)/u', $content, 2);
        if (is_array($cut) && trim($cut[0]) !== '') {
            $content = self::peelQuotes($cut[0]);
        }

        return trim($content);
    }

    /**
     * Umschliessende Anfuehrungszeichen und leere Anfuehrungszeilen ("" ... "")
     * schrittweise abschaelen.
     */
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
        if (!is_array($data) || !isset($data['ok'], $data['checked']) || !isset($data['fingerprint'])) {
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
        @file_put_contents($this->cacheFile, (string) json_encode(['ok' => $ok, 'checked' => time(), 'fingerprint' => $this->fingerprint()]), LOCK_EX);
    }

    /**
     * Bindet den Cache an Adresse und Modell (ohne Schluessel preiszugeben).
     */
    private function fingerprint(): string
    {
        return substr(sha1($this->ai->url() . '|' . $this->ai->model()), 0, 16);
    }
}
