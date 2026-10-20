<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

/**
 * Schlanker MIME-Parser für eingehende E-Mails (RFC 5322/2045–2047) ohne ext-imap/mailparse:
 * Kopfzeilen entfalten und dekodieren, Multipart rekursiv auflösen, Text bevorzugt aus text/plain
 * (sonst HTML zu Text), Transfer-Encodings und Zeichensätze nach UTF-8, Anhänge extrahieren,
 * zitierte Vorgängernachrichten abschneiden.
 */
final class MimeMessageParser
{
    private const MAX_ATTACHMENTS = 20;

    public function parse(string $raw): InboundMail
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$headers, $body] = $this->splitHeaders($raw);

        $parts = $this->collectParts($headers, $body, 0);
        $text = '';
        $html = '';
        $attachments = [];
        foreach ($parts as $part) {
            if ($part['kind'] === 'text' && $text === '') {
                $text = $part['content'];
            } elseif ($part['kind'] === 'html' && $html === '') {
                $html = $part['content'];
            } elseif ($part['kind'] === 'attachment' && count($attachments) < self::MAX_ATTACHMENTS) {
                $attachments[] = ['filename' => $part['filename'], 'mime_type' => $part['mime_type'], 'content' => $part['content']];
            }
        }
        if (trim($text) === '' && $html !== '') {
            $text = self::htmlToText($html);
        }

        [$fromAddress, $fromName] = self::parseAddress($headers['from'] ?? ($headers['sender'] ?? ($headers['reply-to'] ?? '')));
        $messageId = self::normalizeId($headers['message-id'] ?? '');
        if ($messageId === '') {
            // Ohne Message-ID: deterministische Ersatz-ID, damit Duplikate trotzdem erkannt werden
            $messageId = '<' . hash('sha256', ($headers['from'] ?? '') . '|' . ($headers['date'] ?? '') . '|' . ($headers['subject'] ?? '') . '|' . substr($text, 0, 500)) . '@generated.local>';
        }

        return new InboundMail(
            $messageId,
            $fromAddress,
            $fromName,
            self::normalizeWhitespace(self::decodeHeader($headers['subject'] ?? '')),
            self::stripQuotedReply(self::normalizeNewlines($text)),
            self::parseDate($headers['date'] ?? ''),
            self::normalizeId($headers['in-reply-to'] ?? '') ?: null,
            self::parseIdList($headers['references'] ?? ''),
            $attachments,
            $headers
        );
    }

    // ------------------------------------------------------------------ Kopfzeilen

    /** @return array{0:array<string,string>,1:string} */
    private function splitHeaders(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        $headerBlock = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        // Entfalten (Fortsetzungszeilen beginnen mit Leerzeichen/Tab)
        $headerBlock = preg_replace("/\n[ \t]+/", ' ', $headerBlock) ?? $headerBlock;
        $headers = [];
        foreach (explode("\n", $headerBlock) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }

        return [$headers, $body];
    }

    /** RFC-2047-kodierte Wörter (=?utf-8?Q?...?=) dekodieren. */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::toUtf8($value, 'UTF-8');
        }
        // Leerraum zwischen benachbarten kodierten Wörtern ist laut RFC zu ignorieren
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;
        $decoded = preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', static function (array $m): string {
            $charset = $m[1];
            $payload = strtoupper($m[2]) === 'B' ? (string) base64_decode($m[3], true) : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::toUtf8($payload, $charset);
        }, $value);

        return $decoded ?? $value;
    }

    /** @return array{0:string,1:string} [Adresse (klein), Anzeigename] */
    public static function parseAddress(string $header): array
    {
        $header = trim(self::decodeHeader($header));
        if ($header === '') {
            return ['', ''];
        }
        // Nur die erste Adresse zählt; der Anzeigename steht davor
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $header, $m, PREG_OFFSET_CAPTURE) === 1) {
            $address = $m[1][0];
            $name = trim(substr($header, 0, (int) $m[0][1]));
        } elseif (preg_match('/([^<>@\s"]+@[^<>\s"]+)/', $header, $m, PREG_OFFSET_CAPTURE) === 1) {
            $address = $m[1][0];
            $name = trim(substr($header, 0, (int) $m[0][1]));
        } else {
            return ['', $header];
        }
        $name = trim($name, " \t\"'(),;");
        $address = rtrim($address, '.,;');

        return [mb_strtolower($address), $name];
    }

    public static function normalizeId(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/<[^<>]+>/', $value, $m) === 1) {
            return $m[0];
        }

        return '<' . trim($value, '<> ') . '>';
    }

    /** @return array<int,string> */
    public static function parseIdList(string $value): array
    {
        preg_match_all('/<[^<>\s]+>/', $value, $m);

        return array_values(array_unique($m[0]));
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Parameter eines strukturierten Headers (z. B. charset, boundary, name) – inkl. RFC-2231-Fortsetzungen. @return array{0:string,1:array<string,string>} */
    private static function parseParams(string $header): array
    {
        $segments = array_map('trim', explode(';', $header));
        $main = strtolower((string) array_shift($segments));
        $params = [];
        $continued = [];
        foreach ($segments as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $segment, 2);
            $key = strtolower(trim($key));
            $val = trim($val);
            if ($val !== '' && $val[0] === '"') {
                $val = stripslashes(trim($val, '"'));
            }
            if (preg_match('/^([^*]+)\*(\d*)(\*?)$/', $key, $m) === 1) {
                // RFC 2231: name*=utf-8''..., name*0*=utf-8''..., name*1*=...
                $base = $m[1];
                $index = $m[2] !== '' ? (int) $m[2] : 0;
                $encoded = $m[3] === '*' || $m[2] === '';
                if ($encoded && $index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $cm) === 1) {
                    $continued[$base]['charset'] = $cm[1] !== '' ? $cm[1] : 'UTF-8';
                    $val = $cm[2];
                }
                $continued[$base]['parts'][$index] = $encoded ? rawurldecode($val) : $val;
                continue;
            }
            $params[$key] = $val;
        }
        foreach ($continued as $base => $info) {
            ksort($info['parts']);
            $params[$base] = self::toUtf8(implode('', $info['parts']), $info['charset'] ?? 'UTF-8');
        }

        return [$main, $params];
    }

    // ------------------------------------------------------------------ Körper

    /**
     * @param array<string,string> $headers
     * @return list<array{kind:string,content:string,filename:string,mime_type:string}>
     */
    private function collectParts(array $headers, string $body, int $depth): array
    {
        [$mime, $params] = self::parseParams($headers['content-type'] ?? 'text/plain; charset=us-ascii');
        if ($mime === '') {
            $mime = 'text/plain';
        }
        if (str_starts_with($mime, 'multipart/') && isset($params['boundary']) && $depth < 10) {
            $parts = [];
            foreach ($this->splitMultipart($body, $params['boundary']) as $rawPart) {
                [$partHeaders, $partBody] = $this->splitHeaders($rawPart);
                foreach ($this->collectParts($partHeaders, $partBody, $depth + 1) as $part) {
                    $parts[] = $part;
                }
            }
            if ($mime === 'multipart/alternative') {
                // Bei Alternativen: höchstens einen Text- und einen HTML-Teil übernehmen; Anhänge behalten
                $text = null;
                $html = null;
                $rest = [];
                foreach ($parts as $part) {
                    if ($part['kind'] === 'text') {
                        $text ??= $part;
                    } elseif ($part['kind'] === 'html') {
                        $html ??= $part;
                    } else {
                        $rest[] = $part;
                    }
                }

                return array_values(array_filter([$text, $html, ...$rest]));
            }

            return $parts;
        }

        $content = self::decodeTransfer($body, strtolower(trim($headers['content-transfer-encoding'] ?? '7bit')));
        [$dispositionType, $dispositionParams] = self::parseParams($headers['content-disposition'] ?? '');
        $filename = self::decodeHeader($dispositionParams['filename'] ?? ($params['name'] ?? ''));
        $isAttachment = $dispositionType === 'attachment' || ($filename !== '' && $dispositionType !== 'inline' && !str_starts_with($mime, 'text/'));

        if ($mime === 'message/rfc822' && !$isAttachment && $depth < 10) {
            // Weitergeleitete Nachricht als Text einbetten
            $inner = $this->parse($content);
            $summary = "Weitergeleitete Nachricht von {$inner->fromAddress}: {$inner->subject}\n\n{$inner->text}";

            return [['kind' => 'text', 'content' => $summary, 'filename' => '', 'mime_type' => 'text/plain']];
        }
        if ($isAttachment || (!str_starts_with($mime, 'text/') && $mime !== '')) {
            if ($content === '') {
                return [];
            }

            return [['kind' => 'attachment', 'content' => $content, 'filename' => self::safeFilename($filename, $mime), 'mime_type' => $mime ?: 'application/octet-stream']];
        }

        $charset = $params['charset'] ?? 'UTF-8';
        $text = self::toUtf8($content, $charset);
        if ($mime === 'text/html') {
            return [['kind' => 'html', 'content' => $text, 'filename' => '', 'mime_type' => $mime]];
        }
        if ($mime === 'text/calendar' || $mime === 'text/x-vcard') {
            return [['kind' => 'attachment', 'content' => $content, 'filename' => self::safeFilename($filename, $mime), 'mime_type' => $mime]];
        }

        return [['kind' => 'text', 'content' => $text, 'filename' => '', 'mime_type' => $mime]];
    }

    /** @return array<int,string> */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $chunks = explode("\n" . $delimiter, "\n" . $body);
        array_shift($chunks); // Präambel
        $parts = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break; // Abschluss-Delimiter
            }
            $parts[] = ltrim($chunk, "\n");
        }

        return $parts;
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    public static function toUtf8(string $value, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'")) ?: 'UTF-8';
        $charset = match ($charset) {
            'UTF8' => 'UTF-8',
            'ISO-8859-1', 'LATIN1', 'US-ASCII', 'ASCII' => 'ISO-8859-1',
            'CP1252', 'WINDOWS-1252', 'WIN-1252' => 'Windows-1252',
            'ISO-8859-15', 'LATIN9' => 'ISO-8859-15',
            default => $charset,
        };
        if ($charset === 'UTF-8') {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        try {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
        } catch (\Throwable) {
            $converted = false;
        }
        if ($converted === false || $converted === '') {
            $converted = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return (string) $converted;
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote|pre|table)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Zitierte Vorgängernachricht abschneiden (Outlook-/Gmail-/Thunderbird-Trenner, „>“-Zeilen am Ende). */
    public static function stripQuotedReply(string $text): string
    {
        $lines = explode("\n", $text);
        $cut = count($lines);
        $separators = [
            '/^\s*-{2,}\s*(Original Message|Ursprüngliche Nachricht|Originalnachricht|Original-Nachricht|Forwarded message|Weitergeleitete Nachricht)\s*-{2,}\s*$/iu',
            '/^\s*_{5,}\s*$/',
            '/^\s*(Am|On)\s.{4,120}\s(schrieb|wrote)\b.*:\s*$/iu',
            '/^\s*Sent from my (iPhone|iPad|Android)/i',
        ];
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            foreach ($separators as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $cut = $i;
                    break 2;
                }
            }
            // Outlook-Kopfblock: „Von:“/„From:“ gefolgt von Gesendet:/An:/Betreff:
            if (preg_match('/^\s*(Von|From):\s.+$/u', $line) === 1) {
                $next = trim($lines[$i + 1] ?? '') . ' ' . trim($lines[$i + 2] ?? '');
                if (preg_match('/^(Gesendet|Sent|An|To|Betreff|Subject|Datum|Date):/iu', $next) === 1) {
                    $cut = $i;
                    break;
                }
            }
        }
        $kept = array_slice($lines, 0, $cut);
        // Reine Zitatzeilen („> ...“) am Ende entfernen
        while ($kept !== [] && (trim((string) end($kept)) === '' || str_starts_with(ltrim((string) end($kept)), '>'))) {
            array_pop($kept);
        }
        $result = trim(implode("\n", $kept));

        // Falls alles Zitat war (z. B. Inline-Antwort), lieber den Originaltext ohne Zitatzeichen behalten
        if ($result === '') {
            $plain = array_filter($lines, static fn (string $l): bool => !str_starts_with(ltrim($l), '>'));
            $result = trim(implode("\n", $plain));
        }

        return $result !== '' ? $result : trim($text);
    }

    private static function safeFilename(string $name, string $mime): string
    {
        $name = trim(basename(str_replace('\\', '/', self::normalizeWhitespace($name))));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? $name;
        if ($name === '' || $name === '.' || $name === '..') {
            $ext = match ($mime) {
                'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'text/calendar' => 'ics',
                default => 'bin',
            };
            $name = 'anhang.' . $ext;
        }

        return mb_substr($name, 0, 200);
    }

    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
