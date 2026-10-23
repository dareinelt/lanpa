<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bereinigt HTML aus dem E-Mail-Editor auf eine feste Liste erlaubter Elemente
 * und Attribute. Grundlage ist DOMDocument; Skripte, Ereignisattribute und
 * fremde Verweise werden entfernt, unbekannte Elemente auf ihren Inhalt
 * reduziert. So kann der Editor formatierte Nachrichten liefern, ohne dass
 * fremdes Markup in ausgehende E-Mails oder in den Ticketverlauf gelangt.
 */
final class HtmlSanitizer
{
    /** Elemente, die samt Inhalt entfernt werden. */
    private const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'textarea', 'select', 'option', 'button', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'video', 'audio', 'canvas'];

    /** Elemente, die erhalten bleiben. */
    private const ALLOWED = ['p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'small', 'ul', 'ol', 'li', 'blockquote', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'code', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'img'];

    /** Attribute, die an jedem erlaubten Element stehen dürfen. */
    private const GLOBAL_ATTRIBUTES = ['style', 'class', 'title', 'dir', 'lang'];

    /** Verbotene Muster in style-Attributen (Skripte, Fremdverweise, Überlagerung). */
    private const STYLE_DENY = '/expression|javascript:|vbscript:|behaviou?r\s*:|binding\s*:|@import|-moz-binding|position\s*:\s*fixed|url\s*\(\s*[\'"]?\s*(?!data:image\/)/i';

    /**
     * Bereinigt HTML und liefert die erlaubten Fragmente zurück.
     *
     * @param string $html Roh-HTML aus dem Editor
     * @param int $maxLength Obergrenze für die Eingabelänge in Zeichen
     */
    public static function clean(string $html, int $maxLength = 200000): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if ($maxLength > 0 && mb_strlen($html) > $maxLength) {
            $html = mb_substr($html, 0, $maxLength);
        }
        if (!class_exists(\DOMDocument::class)) {
            return self::fallback($html);
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div data-sanitize-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return self::fallback($html);
        }

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $candidate) {
            if ($candidate->getAttribute('data-sanitize-root') === '1') {
                $root = $candidate;
                break;
            }
        }
        if ($root === null) {
            return self::fallback($html);
        }

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** Klartextfassung eines (bereinigten) HTML-Fragments für den Ticketverlauf. */
    public static function plain(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $text = (string) preg_replace('/<(br|hr)\s*\/?>/i', "\n", $html);
        $text = (string) preg_replace('/<\/(p|div|li|tr|h[1-6]|blockquote|pre)>/i', "\n", $text);
        $text = (string) preg_replace('/<li[^>]*>/i', '– ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /** Reduziert unbekannte Elemente auf ihren Inhalt und bereinigt Attribute. */
    private static function walk(\DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (!in_array($tag, self::ALLOWED, true)) {
                self::unwrap($child);
                continue;
            }
            self::attributes($child, $tag);
        }
    }

    /** Entfernt das Element, behält aber seinen Inhalt. */
    private static function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private static function attributes(\DOMElement $element, string $tag): void
    {
        $allowed = self::GLOBAL_ATTRIBUTES;
        $allowed = match ($tag) {
            'a' => [...$allowed, 'href', 'target', 'rel'],
            'img' => [...$allowed, 'src', 'alt', 'width', 'height'],
            'td', 'th' => [...$allowed, 'colspan', 'rowspan', 'align', 'valign', 'width', 'height'],
            'table' => [...$allowed, 'cellpadding', 'cellspacing', 'border', 'width', 'align'],
            default => $allowed,
        };

        $drop = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, $allowed, true)) {
                $drop[] = $name;
                continue;
            }
            $value = trim($attribute->nodeValue ?? '');
            if ($value === '') {
                if ($name !== 'alt') {
                    $drop[] = $name;
                }
                continue;
            }
            if (!self::attributeAllowed($name, $value)) {
                $drop[] = $name;
            }
        }
        foreach ($drop as $name) {
            $element->removeAttribute($name);
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'img' && !$element->hasAttribute('src')) {
            $element->parentNode?->removeChild($element);
        }
    }

    private static function attributeAllowed(string $name, string $value): bool
    {
        return match ($name) {
            'style' => preg_match(self::STYLE_DENY, $value) !== 1 && mb_strlen($value) <= 1000,
            'class' => preg_match('/^[A-Za-z0-9 _\-]{1,120}$/', $value) === 1,
            'href' => preg_match('#^(https?://|mailto:|tel:)#i', $value) === 1,
            'src' => preg_match('#^(https?://|data:image/(png|jpe?g|gif|webp);base64,)#i', $value) === 1,
            'target', 'rel', 'dir', 'lang', 'align', 'valign', 'alt', 'title' => mb_strlen($value) <= 255,
            'width', 'height', 'colspan', 'rowspan', 'cellpadding', 'cellspacing', 'border' => preg_match('/^\d{1,4}%?$/', $value) === 1,
            default => false,
        };
    }

    /** Notlösung ohne DOM-Erweiterung: nur Text und einfache Auszeichnung behalten. */
    private static function fallback(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><blockquote><a><div><span>');
        $html = (string) preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        return trim((string) preg_replace('/<a\b[^>]*>/i', '<a>', $html));
    }
}
