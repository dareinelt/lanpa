<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Zitierter Verlauf eingehender E-Mails (weitergeleitete oder beantwortete Nachrichten).
 *
 * Der Verlauf bleibt vollständig im Ticket erhalten, wird aber platzsparend eingeklappt:
 * Im Klartext steht er hinter der Markierungszeile {@see MARKER} ({@see RichText::toHtml()}
 * macht daraus einen aufklappbaren Block), im HTML der Mail wird er in ein `<details>`
 * eingeschlossen ({@see collapseHtml()}). Beides kommt ohne JavaScript aus.
 */
final class MailHistory
{
    public const LABEL = 'Bisheriger E-Mail-Verlauf';

    /** Markierungszeile im Klartext; alles danach ist zitierter Verlauf. */
    public const MARKER = '——— ' . self::LABEL . ' ———';

    /** Klasse des aufklappbaren Blocks (Seiten-CSS und Stylesheet des Mail-Rahmens). */
    public const CSS_CLASS = 'mail-history';

    /** Textanfang eines Trenners vor zitierten Nachrichten (Outlook, Thunderbird, Gmail). */
    private const SEPARATOR_TEXT = '/^\s*-{2,}\s*(Original Message|Ursprüngliche Nachricht|Originalnachricht|Original-Nachricht|Forwarded message|Weitergeleitete Nachricht)\s*-{2,}/iu';

    /** Outlook-Kopfblock „Von: … Gesendet: …“ als Textanfang eines Elements. */
    private const HEADER_TEXT = '/^\s*(Von|From)\s*:.{1,400}?(Gesendet|Sent|Datum|Date)\s*:/isu';

    /** Klartext mit Verlauf hinter der Markierungszeile. */
    public static function appendText(string $text, string $history): string
    {
        $history = trim($history);

        return $history === '' ? $text : rtrim($text) . "\n\n" . self::MARKER . "\n" . $history;
    }

    /**
     * Klartext an der Markierungszeile trennen.
     *
     * @return array{0:string,1:?string} [Text davor, Verlauf oder null ohne Markierung]
     */
    public static function splitText(string $text): array
    {
        $parts = preg_split('/^[ \t]*' . preg_quote(self::MARKER, '/') . '[ \t]*$/mu', $text, 2);
        if ($parts === false || count($parts) < 2) {
            return [$text, null];
        }

        return [rtrim($parts[0]), trim($parts[1], "\n")];
    }

    /**
     * Zitierten Verlauf im HTML einer E-Mail in ein aufklappbares `<details>` einschließen.
     * Erkannt werden die üblichen Antwort-/Weiterleitungsblöcke von Outlook, Gmail und Thunderbird;
     * ab dort wandern das Element und seine folgenden Geschwister in den Block. Ohne erkannten
     * Verlauf oder wenn davor kein eigener Text steht, bleibt das HTML unverändert.
     */
    public static function collapseHtml(string $html): string
    {
        if (trim($html) === '' || !class_exists(\DOMDocument::class) || stripos($html, '<details class="' . self::CSS_CLASS . '"') !== false) {
            return $html;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'),
            LIBXML_NONET | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return $html;
        }
        $start = self::findStart($doc);
        if ($start === null) {
            return $html;
        }

        $details = $doc->createElement('details');
        $details->setAttribute('class', self::CSS_CLASS);
        $summary = $doc->createElement('summary', self::LABEL);
        $details->appendChild($summary);
        $parent = $start->parentNode;
        if ($parent === null) {
            return $html;
        }
        $parent->insertBefore($details, $start);
        $node = $start;
        while ($node !== null) {
            $next = $node->nextSibling;
            $details->appendChild($node);
            $node = $next;
        }

        $out = '';
        foreach ($doc->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $out .= (string) $doc->saveHTML($child);
        }

        return $out !== '' ? $out : $html;
    }

    /** Erstes Element des zitierten Verlaufs (in Dokumentreihenfolge), dem eigener Text vorausgeht. */
    private static function findStart(\DOMDocument $doc): ?\DOMElement
    {
        $xpath = new \DOMXPath($doc);
        $candidates = $xpath->query('//body//*[self::p or self::div or self::blockquote]');
        if ($candidates === false) {
            return null;
        }
        foreach ($candidates as $node) {
            if (!$node instanceof \DOMElement || !self::isHistoryStart($node)) {
                continue;
            }
            $node = self::outermost($node);
            if (self::hasTextBefore($xpath, $node)) {
                return $node;
            }

            return null;
        }

        return null;
    }

    private static function isHistoryStart(\DOMElement $node): bool
    {
        $tag = strtolower($node->nodeName);
        $id = $node->getAttribute('id');
        $class = ' ' . strtolower((string) preg_replace('/\s+/', ' ', $node->getAttribute('class'))) . ' ';
        if (in_array($id, ['divRplyFwdMsg', 'appendonsend'], true)
            || str_contains($class, ' gmail_quote ')
            || str_contains($class, ' moz-cite-prefix ')
            || str_contains($class, ' moz-forward-container ')
            || ($tag === 'blockquote' && strtolower($node->getAttribute('type')) === 'cite')) {
            return true;
        }
        $style = strtolower((string) preg_replace('/\s+/', '', $node->getAttribute('style')));
        if ($tag === 'div' && str_contains($style, 'border-top:solid') && preg_match(self::HEADER_TEXT, $node->textContent) === 1) {
            return true;
        }
        if ($tag !== 'p' && $tag !== 'div') {
            return false;
        }
        $text = $node->textContent;

        return preg_match(self::SEPARATOR_TEXT, $text) === 1
            || ($tag === 'p' && preg_match(self::HEADER_TEXT, $text) === 1);
    }

    /**
     * Outlook schließt den Kopfblock oft in ein weiteres `<div>` ein; der Verlauf folgt dann als
     * Geschwister dieses äußeren Elements. Deshalb so weit nach oben gehen, wie das Element
     * einziger Inhalt seines Elternelements ist.
     */
    private static function outermost(\DOMElement $node): \DOMElement
    {
        while (($parent = $node->parentNode) instanceof \DOMElement && strtolower($parent->nodeName) !== 'body' && self::isOnlyContent($node, $parent)) {
            $node = $parent;
        }
        // Eine Trennlinie (<hr>) direkt davor gehört noch zum Verlauf
        $prev = $node->previousSibling;
        while ($prev instanceof \DOMText && trim($prev->textContent) === '') {
            $prev = $prev->previousSibling;
        }

        return $prev instanceof \DOMElement && strtolower($prev->nodeName) === 'hr' ? $prev : $node;
    }

    private static function isOnlyContent(\DOMElement $node, \DOMElement $parent): bool
    {
        foreach ($parent->childNodes as $sibling) {
            if ($sibling === $node) {
                continue;
            }
            if ($sibling instanceof \DOMElement || ($sibling instanceof \DOMText && trim(str_replace("\u{a0}", ' ', $sibling->textContent)) !== '')) {
                return false;
            }
        }

        return true;
    }

    private static function hasTextBefore(\DOMXPath $xpath, \DOMElement $node): bool
    {
        $texts = $xpath->query('preceding::text()[not(ancestor::head) and not(ancestor::style) and not(ancestor::script)]', $node);
        if ($texts === false) {
            return false;
        }
        foreach ($texts as $text) {
            if (trim(str_replace("\u{a0}", ' ', $text->textContent)) !== '') {
                return true;
            }
        }

        return false;
    }
}
