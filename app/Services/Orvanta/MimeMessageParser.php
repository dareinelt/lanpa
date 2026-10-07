<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

/**
 * Minimaler MIME-Parser fuer die Anzeige archivierter E-Mails: Das Archiv
 * speichert die unveraenderten Original-MIME-Bytes; erst beim Oeffnen werden
 * Kopfzeilen, Text-/HTML-Rumpf und Anhaenge extrahiert. Der HTML-Rumpf wird
 * vom Aufrufer (OrvantaArchiveService) vor der Auslieferung saniert.
 *
 * Unterstuetzt: Header-Unfolding, multipart/* (rekursiv), base64 und
 * quoted-printable, Zeichensatz-Konvertierung nach UTF-8, RFC-2047-Betreff,
 * Anhaenge mit Name/Content-Type/Content-ID (inline-Erkennung).
 *
 * S/MIME-signierte Nachrichten (multipart/signed bzw. application/pkcs7-mime
 * mit smime-type=signed-data) werden wie normale Nachrichten zerlegt: Der
 * signierte Inhalt liefert Text und Anhaenge, die Signatur selbst erscheint
 * nicht als Anhang, sondern nur als Kennzeichen 'signed'. Die Signatur wird
 * dabei nicht kryptografisch geprueft. Verschluesselte Nachrichten
 * (enveloped-data) bleiben unveraendert ein Anhang.
 */
final class MimeMessageParser
{
    private const MAX_DEPTH = 10;
    private const MAX_PARTS = 200;

    /**
     * @return array{
     *     headers: array<string,string>,
     *     subject: string,
     *     from: string,
     *     to: string,
     *     date: string,
     *     text: string,
     *     html: string,
     *     signed: bool,
     *     attachments: list<array{name:string,content_type:string,content:string,content_id:string,inline:bool}>
     * }
     */
    public function parse(string $raw): array
    {
        [$headerBlock, $body] = $this->splitHeaderBody($raw);
        $headers = $this->parseHeaders($headerBlock);
        $result = [
            'headers' => $headers,
            'subject' => $this->decodeWord($headers['subject'] ?? ''),
            'from' => $this->decodeWord($headers['from'] ?? ''),
            'to' => $this->decodeWord($headers['to'] ?? ''),
            'date' => $headers['date'] ?? '',
            'text' => '',
            'html' => '',
            'signed' => false,
            'attachments' => [],
        ];
        $parts = 0;
        $this->walkPart($headers, $body, $result, 0, $parts);

        return $result;
    }

    /**
     * Reiner Textauszug (fuer den Suchindex): bevorzugt text/plain, sonst
     * HTML ohne Tags.
     */
    public function searchText(string $raw, int $maxLength = 20000): string
    {
        $parsed = $this->parse($raw);
        $text = trim($parsed['text']) !== '' ? $parsed['text'] : strip_tags($parsed['html']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, $maxLength);
    }

    /**
     * @return array{0:string,1:string} Header-Block und Rumpf
     */
    private function splitHeaderBody(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /**
     * @return array<string,string> Headername (klein) => entfalteter Wert
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;
        foreach (explode("\n", $block) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($current !== null) {
                    $headers[$current] .= ' ' . trim($line);
                }
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $current = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            // Nur das erste Vorkommen behalten (ausreichend fuer die Anzeige).
            if (!isset($headers[$current])) {
                $headers[$current] = $value;
            } else {
                $current = null;
            }
        }

        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @param array{headers:array<string,string>,subject:string,from:string,to:string,date:string,text:string,html:string,signed:bool,attachments:list<array{name:string,content_type:string,content:string,content_id:string,inline:bool}>} $result
     */
    private function walkPart(array $headers, string $body, array &$result, int $depth, int &$parts): void
    {
        if ($depth > self::MAX_DEPTH || $parts > self::MAX_PARTS) {
            return;
        }
        $parts++;
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim((string) strtok($contentType, ';')));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $this->parameter($contentType, 'boundary');
            if ($boundary === '') {
                return;
            }
            $sections = $this->splitMultipart($body, $boundary);
            if ($type === 'multipart/signed') {
                // RFC 1847: erster Teil = signierter Inhalt, zweiter = Signatur.
                $result['signed'] = true;
                $sections = array_slice($sections, 0, 1);
            }
            foreach ($sections as $partRaw) {
                [$partHeaderBlock, $partBody] = $this->splitHeaderBody($partRaw);
                $this->walkPart($this->parseHeaders($partHeaderBlock), $partBody, $result, $depth + 1, $parts);
            }

            return;
        }

        $decoded = $this->decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''));

        if (in_array($type, ['application/pkcs7-mime', 'application/x-pkcs7-mime'], true)) {
            $inner = $this->signedDataContent($contentType, $decoded);
            if ($inner !== null) {
                $result['signed'] = true;
                [$innerHeaderBlock, $innerBody] = $this->splitHeaderBody($inner);
                $this->walkPart($this->parseHeaders($innerHeaderBlock), $innerBody, $result, $depth + 1, $parts);

                return;
            }
        }
        $disposition = strtolower(trim((string) strtok($headers['content-disposition'] ?? '', ';')));
        $name = $this->partName($headers);
        $contentId = trim($headers['content-id'] ?? '', " \t<>");
        $isAttachment = $disposition === 'attachment' || ($name !== '' && !in_array($type, ['text/plain', 'text/html'], true));

        if ($isAttachment || ($disposition === 'inline' && $contentId !== '' && !in_array($type, ['text/plain', 'text/html'], true))) {
            $result['attachments'][] = [
                'name' => $name !== '' ? $name : 'anhang-' . (count($result['attachments']) + 1) . '.bin',
                'content_type' => $type !== '' ? $type : 'application/octet-stream',
                'content' => $decoded,
                'content_id' => $contentId,
                'inline' => $disposition === 'inline' && $contentId !== '',
            ];

            return;
        }

        $charset = $this->parameter($contentType, 'charset');
        $text = $this->toUtf8($decoded, $charset);
        if ($type === 'text/html') {
            if ($result['html'] === '') {
                $result['html'] = $text;
            }
        } elseif ($type === 'text/plain' || $type === '') {
            if ($result['text'] === '') {
                $result['text'] = $text;
            }
        }
    }

    /**
     * Signierten Inhalt aus einer opak signierten S/MIME-Struktur
     * (application/pkcs7-mime, smime-type=signed-data) lesen. Die Signatur
     * wird nicht geprueft (keine Vertrauenskette verfuegbar). Liefert null
     * bei verschluesselten Nachrichten oder wenn OpenSSL fehlt bzw. die
     * Struktur nicht lesbar ist.
     */
    private function signedDataContent(string $contentType, string $der): ?string
    {
        $smimeType = strtolower($this->parameter($contentType, 'smime-type'));
        if (($smimeType !== '' && $smimeType !== 'signed-data') || $der === '' || !function_exists('openssl_pkcs7_verify')) {
            return null;
        }
        $input = tempnam(sys_get_temp_dir(), 'ovp7i');
        $output = tempnam(sys_get_temp_dir(), 'ovp7o');
        if ($input === false || $output === false) {
            return null;
        }
        try {
            $smime = "MIME-Version: 1.0\r\nContent-Type: application/pkcs7-mime; smime-type=signed-data; name=\"smime.p7m\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($der), 64, "\r\n");
            if (file_put_contents($input, $smime) === false) {
                return null;
            }
            $ok = @openssl_pkcs7_verify($input, PKCS7_NOVERIFY | PKCS7_NOSIGS, null, [], null, $output);
            $content = $ok === true ? file_get_contents($output) : false;

            return is_string($content) && $content !== '' ? $content : null;
        } finally {
            @unlink($input);
            @unlink($output);
            while (openssl_error_string() !== false) {
                // OpenSSL-Fehlerpuffer leeren.
            }
        }
    }

    /**
     * @return list<string>
     */
    private function splitMultipart(string $body, string $boundary): array
    {
        $sections = explode("\n--" . $boundary, "\n" . $body);
        array_shift($sections); // Preamble
        $parts = [];
        foreach ($sections as $section) {
            if (str_starts_with($section, '--')) {
                break; // Abschluss-Boundary
            }
            $parts[] = ltrim($section, "\n");
        }

        return $parts;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \t\"'"));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $this->sanitizeUtf8($text);
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

        return is_string($converted) ? $converted : $this->sanitizeUtf8($text);
    }

    private function sanitizeUtf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : (mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1') ?: '');
    }

    /** RFC-2047-kodierte Header-Woerter (=?charset?B/Q?...?=) dekodieren. */
    private function decodeWord(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @mb_decode_mimeheader($value);

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }

    /** Parameter (boundary, charset, name, filename) aus einem Headerwert lesen. */
    private function parameter(string $headerValue, string $name): string
    {
        if (preg_match('/' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $headerValue, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/' . preg_quote($name, '/') . '\s*=\s*([^;\s]+)/i', $headerValue, $m) === 1) {
            return trim($m[1], "\"'");
        }

        return '';
    }

    /**
     * @param array<string,string> $headers
     */
    private function partName(array $headers): string
    {
        $name = $this->parameter($headers['content-disposition'] ?? '', 'filename');
        if ($name === '') {
            $name = $this->parameter($headers['content-type'] ?? '', 'name');
        }
        $name = $this->decodeWord($name);
        // Pfadanteile entfernen (Anzeige/Dateiname).
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr($name, 0, 255);
    }
}
