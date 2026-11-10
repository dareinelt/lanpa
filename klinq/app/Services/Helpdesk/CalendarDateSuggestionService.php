<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Services\Helpdesk\Ai\AiChatClient;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use Throwable;

/**
 * Datumsvorschläge für einen Kalendereintrag aus einem Ticket („Termin anlegen“).
 *
 * Betreff und Beschreibung des Tickets gehen an den konfigurierten KI-Endpunkt
 * ({@see AiSettingsService}, derselbe Endpunkt wie „Mit KI verbessern“). Das Modell liefert die im
 * Text genannten Termine als JSON; relative Angaben („morgen“, „nächsten Montag“) bezieht es auf das
 * Erstellungsdatum des Tickets. Die Antwort wird tolerant ausgewertet (Codeblöcke, Begleittext),
 * jedes Datum mit checkdate() geprüft und doppelte Angaben werden zusammengefasst.
 * Gespeichert wird nichts; ist die KI nicht aktiv oder nicht erreichbar, bleibt nur die manuelle Eingabe.
 */
final class CalendarDateSuggestionService
{
    /** Höchstlänge des an das Modell gesendeten Tickettexts (Zeichen). */
    public const MAX_TEXT = 6000;
    public const MAX_SUGGESTIONS = 10;
    private const MAX_OUTPUT_TOKENS = 600;

    private const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly AiTransportInterface $transport,
        private readonly string $timezone = 'Europe/Berlin'
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->isActive();
    }

    /**
     * Erkannte Termine eines Tickets.
     *
     * @param array<string,mixed> $ticket Ticketzeile (subject, description, created_at in UTC)
     * @return array{available:bool,message:string,suggestions:list<array{date:string,start:?string,end:?string,label:string,excerpt:string}>}
     */
    public function suggest(array $ticket): array
    {
        if (!$this->settings->isActive()) {
            return ['available' => false, 'message' => 'Die KI-Analyse ist nicht eingerichtet. Bitte das Datum manuell eintragen.', 'suggestions' => []];
        }
        $text = self::prepareText((string) ($ticket['subject'] ?? ''), (string) ($ticket['description'] ?? ''));
        if ($text === '') {
            return ['available' => true, 'message' => 'Der Tickettext enthält keinen auswertbaren Inhalt.', 'suggestions' => []];
        }
        $reference = $this->reference((string) ($ticket['created_at'] ?? ''));

        $payload = [
            'model' => $this->settings->model(),
            'messages' => self::messages($text, $reference),
            'temperature' => 0,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'stream' => false,
        ];
        try {
            $response = (new AiChatClient($this->transport))->complete(
                $this->settings->url() . '/chat/completions',
                $this->headers(),
                $payload,
                max(5, $this->settings->timeout())
            );
        } catch (Throwable) {
            return ['available' => false, 'message' => 'Die KI ist derzeit nicht erreichbar. Bitte das Datum manuell eintragen.', 'suggestions' => []];
        }
        if ($response['error'] !== null || $response['status'] < 200 || $response['status'] >= 300) {
            return ['available' => false, 'message' => 'Die KI ist derzeit nicht erreichbar. Bitte das Datum manuell eintragen.', 'suggestions' => []];
        }

        $suggestions = self::parse($response['content']);
        $count = count($suggestions);

        return [
            'available' => true,
            'message' => match (true) {
                $count === 0 => 'Im Tickettext wurde kein Datum erkannt. Bitte das Datum manuell eintragen.',
                $count === 1 => 'Im Tickettext wurde ein Datum erkannt.',
                default => 'Im Tickettext wurden ' . $count . ' Daten erkannt. Bitte das zutreffende Datum wählen.',
            },
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Tickettext für das Modell: eingebettete Bilder (Base64) und Steuerzeichen entfernen, kürzen.
     */
    public static function prepareText(string $subject, string $description): string
    {
        $description = (string) preg_replace('/!\[[^\]]*\]\(data:[^)]*\)/u', '[Bild]', $description);
        $description = (string) preg_replace('/data:[a-z]+\/[a-z0-9.+-]+;base64,[A-Za-z0-9+\/=\s]+/iu', '[Bild]', $description);
        $text = trim($subject) !== '' ? 'Betreff: ' . trim($subject) . "\n\n" . $description : $description;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);

        return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT) : $text;
    }

    /**
     * Antwort des Modells auswerten. Erwartet {"termine":[{"datum":"JJJJ-MM-TT","beginn":"HH:MM"|null,
     * "ende":"HH:MM"|null,"beschreibung":"…","textstelle":"…"}]}, akzeptiert aber auch eine bloße Liste,
     * englische Schlüssel, Codeblöcke und Begleittext.
     *
     * @return list<array{date:string,start:?string,end:?string,label:string,excerpt:string}>
     */
    public static function parse(string $content): array
    {
        $decoded = self::decodeJson($content);
        if (!is_array($decoded)) {
            return [];
        }
        if (!array_is_list($decoded) && (isset($decoded['datum']) || isset($decoded['date']))) {
            $decoded = [$decoded]; // einzelner Termin ohne Liste
        }
        $items = array_is_list($decoded) ? $decoded : ($decoded['termine'] ?? $decoded['dates'] ?? $decoded['events'] ?? $decoded['daten'] ?? []);
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $item = ['datum' => $item];
            }
            if (!is_array($item)) {
                continue;
            }
            $date = self::normalizeDate((string) ($item['datum'] ?? $item['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $start = self::normalizeTime($item['beginn'] ?? $item['start'] ?? $item['uhrzeit'] ?? $item['time'] ?? null);
            $end = self::normalizeTime($item['ende'] ?? $item['end'] ?? null);
            if ($start === null || ($end !== null && $end <= $start)) {
                $end = null;
            }
            $key = $date . '|' . ($start ?? '');
            if (isset($result[$key])) {
                continue;
            }
            $result[$key] = [
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'label' => self::clip((string) ($item['beschreibung'] ?? $item['label'] ?? $item['description'] ?? ''), 120),
                'excerpt' => self::clip((string) ($item['textstelle'] ?? $item['excerpt'] ?? $item['quote'] ?? ''), 200),
            ];
            if (count($result) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }
        $list = array_values($result);
        usort($list, static fn (array $a, array $b): int => [$a['date'], $a['start'] ?? ''] <=> [$b['date'], $b['start'] ?? '']);

        return $list;
    }

    // ------------------------------------------------------------------ intern

    /** @return list<array{role:string,content:string}> */
    private static function messages(string $text, \DateTimeImmutable $reference): array
    {
        $system = 'Du analysierst Tickettexte eines IT-Help-Desks und findest darin Termine und Datumsangaben '
            . '(z. B. Vor-Ort-Termine, Einsätze, Fristen, Rückrufe, Umzüge, Schulungen). '
            . 'Antworte ausschließlich mit JSON in der Form '
            . '{"termine":[{"datum":"JJJJ-MM-TT","beginn":"HH:MM oder null","ende":"HH:MM oder null","beschreibung":"kurze Bezeichnung","textstelle":"wörtliche Stelle im Text"}]}. '
            . 'Relative Angaben wie „morgen“ oder „nächsten Montag“ beziehst du auf das Referenzdatum. '
            . 'Fehlt das Jahr, wähle das nächste passende Datum ab dem Referenzdatum. '
            . 'Erfinde keine Daten; enthält der Text keine Datumsangabe, antworte mit {"termine":[]}. '
            . 'Keine Erklärungen, kein Markdown.';
        $weekday = self::WEEKDAYS[(int) $reference->format('N') - 1];
        $user = 'Referenzdatum (Erstellung des Tickets): ' . $weekday . ', ' . $reference->format('Y-m-d') . "\n\n"
            . "Tickettext:\n\"\"\"\n" . $text . "\n\"\"\"";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    private function reference(string $createdAtUtc): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($this->timezone);
        try {
            $created = $createdAtUtc !== '' ? new \DateTimeImmutable($createdAtUtc, new \DateTimeZone('UTC')) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (Throwable) {
            $created = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        return $created->setTimezone($tz);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        $apiKey = $this->settings->apiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
    }

    private static function decodeJson(string $content): mixed
    {
        $content = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($content)));
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Begleittext: das zuerst beginnende JSON-Objekt bzw. die zuerst beginnende Liste herauslösen.
        $candidates = [];
        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($content, $open);
            $end = strrpos($content, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $candidates[$start] = substr($content, $start, $end - $start + 1);
            }
        }
        ksort($candidates);
        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return null;
        }
        if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    private static function normalizeTime(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d{1,2})(?:[:.](\d{2}))?(?:\s*Uhr)?$/iu', $value, $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $min = isset($m[2]) ? (int) $m[2] : 0;
        if ($h > 23 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $min);
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)) . '…' : $value;
    }
}
