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
     * @param list<array{filename:string,mime_type:string,content:string}> $attachments
     * @param array<string,string> $headers Kleingeschriebene Kopfzeilen (letzter Wert je Name)
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
        public readonly string $uid = ''
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

    /**
     * Automatisch erzeugte Nachrichten (Abwesenheitsnotizen, Bounces, Newsletter) erkennen –
     * sie dürfen weder Tickets eröffnen noch Antwortschleifen auslösen.
     */
    public function isAutomatic(): bool
    {
        $auto = strtolower($this->headers['auto-submitted'] ?? 'no');
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (isset($this->headers['x-auto-response-suppress']) || isset($this->headers['x-autoreply']) || isset($this->headers['x-autorespond'])) {
            return true;
        }
        $precedence = strtolower($this->headers['precedence'] ?? '');
        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }
        if (isset($this->headers['list-id']) || isset($this->headers['list-unsubscribe'])) {
            return true;
        }
        $from = strtolower($this->fromAddress);
        if ($from === '' || str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@') || str_starts_with($from, 'noreply@') || str_starts_with($from, 'no-reply@')) {
            return true;
        }
        $contentType = strtolower($this->headers['content-type'] ?? '');

        return str_contains($contentType, 'multipart/report');
    }

    public function withUid(string $uid): self
    {
        return new self($this->messageId, $this->fromAddress, $this->fromName, $this->subject, $this->text, $this->date, $this->inReplyTo, $this->references, $this->attachments, $this->headers, $uid);
    }
}
