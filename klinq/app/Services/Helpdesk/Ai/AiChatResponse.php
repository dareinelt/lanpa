<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Ai;

/**
 * Antwort einer OpenAI-kompatiblen Chat-Schnittstelle auswerten.
 *
 * Denkfähige Modelle (etwa Qwen3.5 oder DeepSeek-R1) legen ihre Überlegungen in
 * ein eigenes Feld (`reasoning`, `reasoning_content` oder `thinking`) und die
 * eigentliche Antwort in `content`. Reicht das Token-Budget nur für die Denkspur,
 * bleibt `content` leer – das erkennen die aufrufenden Dienste hieran.
 */
final class AiChatResponse
{
    /** Felder, in denen denkfähige Modelle ihre Denkspur ablegen. */
    private const REASONING_KEYS = ['reasoning_content', 'reasoning', 'thinking'];

    /**
     * Eigentliche Antwort des Modells (ohne Denkspur).
     * @param array<string,mixed>|null $decoded
     */
    public static function content(?array $decoded): string
    {
        if ($decoded === null) {
            return '';
        }
        $message = $decoded['choices'][0]['message'] ?? null;
        if (is_array($message)) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        // Ältere Schnittstellen (/v1/completions) liefern choices[].text
        return trim((string) ($decoded['choices'][0]['text'] ?? ''));
    }

    /**
     * Denkspur des Modells, sofern eine geliefert wurde.
     * @param array<string,mixed>|null $decoded
     */
    public static function reasoning(?array $decoded): string
    {
        if ($decoded === null) {
            return '';
        }
        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            return '';
        }
        foreach (self::REASONING_KEYS as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Das Modell hat nur gedacht und keine Antwort mehr ausgegeben.
     * @param array<string,mixed>|null $decoded
     */
    public static function reasoningOnly(?array $decoded): bool
    {
        return self::content($decoded) === '' && self::reasoning($decoded) !== '';
    }
}
