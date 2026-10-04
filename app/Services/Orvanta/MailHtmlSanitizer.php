<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Bereinigt HTML-Nachrichtentexte aus Exchange fuer die Anzeige in Orvanta.
 * Im Unterschied zum Rich-Text-Sanitizer bleiben Tabellen und einfache
 * Layoutattribute erhalten; Skripte, Formulare, Styles mit Ausdruecken,
 * externe Bilder (Tracking) und Event-Handler werden entfernt.
 */
final class MailHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'div', 'span', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins',
        'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'sub', 'sup',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        'font', 'center', 'small', 'big', 'dl', 'dt', 'dd', 'abbr', 'cite', 'q', 'img',
    ];

    /** @var list<string> */
    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'link', 'meta',
        'title', 'base', 'form', 'input', 'button', 'textarea', 'select', 'option', 'noscript', 'head', 'template', 'audio', 'video',
    ];

    /** @var list<string> */
    private const ALLOWED_ATTRIBUTES = [
        'class', 'title', 'dir', 'lang', 'align', 'valign', 'width', 'height', 'colspan', 'rowspan',
        'cellpadding', 'cellspacing', 'border', 'bgcolor', 'color', 'size', 'face', 'style', 'alt',
    ];

    /**
     * @return array{html:string,blocked_images:int}
     */
    public static function clean(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['html' => '', 'blocked_images' => 0];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="orvanta-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return ['html' => '', 'blocked_images' => 0];
        }

        $root = $dom->getElementById('orvanta-root');
        if ($root === null) {
            return ['html' => '', 'blocked_images' => 0];
        }
        $blocked = 0;
        self::walk($root, $blocked);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return ['html' => $out, 'blocked_images' => $blocked];
    }

    private static function walk(DOMNode $node, int &$blocked): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            self::cleanChild($node, $child, $blocked);
        }
    }

    /**
     * Bereinigt ein Kind von $parent: verbotene Elemente entfernen, unbekannte
     * Elemente auspacken (die ausgepackten Kinder werden erneut geprueft),
     * erlaubte Elemente von gefaehrlichen Attributen befreien.
     */
    private static function cleanChild(DOMNode $parent, DOMNode $child, int &$blocked): void
    {
        if (!$child instanceof DOMElement) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $parent->removeChild($child);
            }
            return;
        }
        $tag = strtolower($child->tagName);
        if (in_array($tag, self::DROP_TAGS, true)) {
            $parent->removeChild($child);
            return;
        }
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            $moved = [];
            while ($child->firstChild !== null) {
                $moved[] = $parent->insertBefore($child->firstChild, $child);
            }
            $parent->removeChild($child);
            foreach ($moved as $node) {
                self::cleanChild($parent, $node, $blocked);
            }
            return;
        }
        self::cleanAttributes($child, $tag, $blocked);
        if ($tag === 'img' && !$child->hasAttribute('src')) {
            $placeholder = $child->ownerDocument?->createElement('span');
            if ($placeholder !== null) {
                $placeholder->setAttribute('class', 'orv-img-blocked');
                $placeholder->setAttribute('title', 'Externes Bild blockiert');
                $placeholder->appendChild($child->ownerDocument->createTextNode('🖼'));
                $parent->replaceChild($placeholder, $child);
            }
            return;
        }
        self::walk($child, $blocked);
    }

    private static function cleanAttributes(DOMElement $element, string $tag, int &$blocked): void
    {
        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue ?? '');
            if ($tag === 'a' && $name === 'href') {
                if (!self::isSafeLink($value)) {
                    $remove[] = $attribute->nodeName;
                }
                continue;
            }
            if ($tag === 'img' && $name === 'src') {
                if (!str_starts_with(strtolower($value), 'data:image/')) {
                    $remove[] = $attribute->nodeName;
                    $blocked++;
                }
                continue;
            }
            if (!in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                $remove[] = $attribute->nodeName;
                continue;
            }
            if ($name === 'style' && preg_match('/expression|url\s*\(|javascript|behavior|@import|position\s*:\s*fixed/i', $value) === 1) {
                $remove[] = $attribute->nodeName;
            }
        }
        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private static function isSafeLink(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }
}
