<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

/**
 * Normalisierte eingehende E-Mail (Ergebnis des MIME-Parsers), unabhängig von der Abholquelle.
 */
final class InboundMail
{
    /**
     * @param array<int,string> $references Message-IDs aus „References“ (in Reihenfolge)
     * @param list<array{filename:string,mime_type:string,content:string,content_id:string}> $attachments
     * @param array<string,string> $headers Kleingeschriebene Kopfzeilen (letzter Wert je Name)
     * @param string $html HTML-Teil der Nachricht, wie versendet (leer = nur Text vorhanden)
     * @param string $quotedText Zitierter Verlauf, der aus `$text` herausgetrennt wurde (leer = keiner)
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?\DateTimeImmutable $date,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $attachments,
        public readonly array $headers,
        public readonly string $uid = '',
        public readonly string $html = '',
        public readonly string $quotedText = ''
    ) {}

    /** Alle referenzierten Message-IDs (In-Reply-To zuerst, dann References von neu nach alt). @return array<int,string> */
    public function referencedIds(): array
    {
        $ids = [];
        if ($this->inReplyTo !== null && $this->inReplyTo !== '') {
            $ids[] = $this->inReplyTo;
        }
        foreach (array_reverse($this->references) as $ref) {
            $ids[] = $ref;
        }

        return array_values(array_unique($ids));
    }

    /** Kopfzeilen, die für die Erkennung automatischer Nachrichten relevant sind (Anzeige im Mail-Protokoll). */
    public const DIAGNOSTIC_HEADERS = [
        'auto-submitted', 'precedence', 'list-id', 'list-unsubscribe', 'x-autoreply', 'x-autorespond',
        'x-auto-response-suppress', 'content-type', 'return-path', 'sender', 'reply-to',
    ];

    /**
     * Automatisch erzeugte Nachrichten (Abwesenheitsnotizen, Bounces, Newsletter) erkennen –
     * sie dürfen weder Tickets eröffnen noch Antwortschleifen auslösen.
     */
    public function isAutomatic(): bool
    {
        return $this->automaticReason() !== null;
    }

    /**
     * Grund, warum die Nachricht als automatisch gilt (null = persönliche Nachricht).
     *
     * `X-Auto-Response-Suppress` wird bewusst nicht ausgewertet: Outlook/Exchange setzen die Kopfzeile
     * auch bei normalen, von Menschen geschriebenen Mails – sie bittet nur darum, selbst keine
     * Auto-Antwort zu senden, und kennzeichnet die Nachricht nicht als automatisch erzeugt.
     */
    public function automaticReason(): ?string
    {
        $auto = strtolower(trim($this->headers['auto-submitted'] ?? 'no'));
        if ($auto !== '' && $auto !== 'no') {
            return 'Kopfzeile „Auto-Submitted: ' . $auto . '“';
        }
        foreach (['x-autoreply' => 'X-Autoreply', 'x-autorespond' => 'X-Autorespond'] as $key => $label) {
            if (isset($this->headers[$key])) {
                return 'Kopfzeile „' . $label . '“';
            }
        }
        $precedence = strtolower(trim($this->headers['precedence'] ?? ''));
        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return 'Kopfzeile „Precedence: ' . $precedence . '“';
        }
        if (isset($this->headers['list-id'])) {
            return 'Verteiler/Newsletter (Kopfzeile „List-Id“)';
        }
        if (isset($this->headers['list-unsubscribe'])) {
            return 'Verteiler/Newsletter (Kopfzeile „List-Unsubscribe“)';
        }
        $from = strtolower($this->fromAddress);
        if ($from === '') {
            return 'Kein Absender';
        }
        if (str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@')) {
            return 'Unzustellbarkeitsmeldung (Absender ' . $from . ')';
        }
        if (str_starts_with($from, 'noreply@') || str_starts_with($from, 'no-reply@')) {
            return 'Absender ohne Antwortmöglichkeit (' . $from . ')';
        }
        if (str_contains(strtolower($this->headers['content-type'] ?? ''), 'multipart/report')) {
            return 'Zustell- oder Lesebestätigung (multipart/report)';
        }

        return null;
    }

    /** Vorhandene diagnostische Kopfzeilen (gekürzt) für das Mail-Protokoll. @return array<string,string> */
    public function diagnosticHeaders(): array
    {
        $result = [];
        foreach (self::DIAGNOSTIC_HEADERS as $key) {
            if (isset($this->headers[$key]) && $this->headers[$key] !== '') {
                $result[$key] = mb_substr($this->headers[$key], 0, 300);
            }
        }

        return $result;
    }

    public function withUid(string $uid): self
    {
        return new self($this->messageId, $this->fromAddress, $this->fromName, $this->subject, $this->text, $this->date, $this->inReplyTo, $this->references, $this->attachments, $this->headers, $uid, $this->html, $this->quotedText);
    }
}
