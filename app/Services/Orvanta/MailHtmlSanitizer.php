<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Bereinigt HTML-Nachrichtentexte aus Exchange fuer die Anzeige in Orvanta.
 * Im Unterschied zum Rich-Text-Sanitizer bleiben Tabellen und einfache
 * Layoutattribute erhalten; Skripte, Formulare, Styles mit Ausdruecken und
 * Event-Handler werden entfernt.
 *
 * Bilder: Externe Quellen (Tracking) wandern fuer die Anzeige in
 * data-blocked-src (der Client laedt sie erst auf Wunsch), eingebettete
 * cid:-Bilder werden mit data-cid markiert und vom Anhangsdienst aufgeloest.
 * Fuer ausgehende Nachrichten (clean(..., false)) bleiben Quellen erhalten
 * bzw. werden aus diesen Markierungen zurueckgewonnen.
 */
final class MailHtmlSanitizer
{
    public const ATTR_BLOCKED = 'data-blocked-src';
    public const ATTR_CID = 'data-cid';
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
     * @param bool $blockExternal true = Anzeige (externe Bilder blockieren), false = Versand
     * @return array{html:string,blocked_images:int}
     */
    public static function clean(string $html, bool $blockExternal = true): array
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
        self::walk($root, $blocked, $blockExternal);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return ['html' => $out, 'blocked_images' => $blocked];
    }

    private static function walk(DOMNode $node, int &$blocked, bool $blockExternal): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            self::cleanChild($node, $child, $blocked, $blockExternal);
        }
    }

    /**
     * Bereinigt ein Kind von $parent: verbotene Elemente entfernen, unbekannte
     * Elemente auspacken (die ausgepackten Kinder werden erneut geprueft),
     * erlaubte Elemente von gefaehrlichen Attributen befreien.
     */
    private static function cleanChild(DOMNode $parent, DOMNode $child, int &$blocked, bool $blockExternal): void
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
                self::cleanChild($parent, $node, $blocked, $blockExternal);
            }
            return;
        }
        self::cleanAttributes($child, $tag, $blocked, $blockExternal);
        if ($tag === 'img' && !$child->hasAttribute('src') && !$child->hasAttribute(self::ATTR_BLOCKED) && !$child->hasAttribute(self::ATTR_CID)) {
            $placeholder = $child->ownerDocument?->createElement('span');
            if ($placeholder !== null) {
                $placeholder->setAttribute('class', 'orv-img-blocked');
                $placeholder->setAttribute('title', 'Bild entfernt');
                $placeholder->appendChild($child->ownerDocument->createTextNode('🖼'));
                $parent->replaceChild($placeholder, $child);
            }
            return;
        }
        self::walk($child, $blocked, $blockExternal);
    }

    private static function cleanAttributes(DOMElement $element, string $tag, int &$blocked, bool $blockExternal): void
    {
        $remove = [];
        $source = null;
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue ?? '');
            if ($tag === 'a' && $name === 'href') {
                if (!self::isSafeLink($value)) {
                    $remove[] = $attribute->nodeName;
                }
                continue;
            }
            if ($tag === 'img' && ($name === 'src' || $name === self::ATTR_BLOCKED || $name === self::ATTR_CID)) {
                // Markierungen aus einer frueheren Bereinigung (zitierte Mail) wieder als Quelle lesen.
                if ($name === self::ATTR_CID) {
                    $value = 'cid:' . $value;
                }
                if ($source === null || $name === self::ATTR_CID) {
                    $source = $value;
                }
                $remove[] = $attribute->nodeName;
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
        if ($tag === 'img' && $source !== null) {
            self::applyImageSource($element, $source, $blocked, $blockExternal);
        }
    }

    /**
     * Bildquelle einordnen: data:image bleibt, cid: wird markiert, http(s)
     * wird fuer die Anzeige blockiert (data-blocked-src) oder fuer den
     * Versand uebernommen; alles andere wird verworfen.
     */
    private static function applyImageSource(DOMElement $element, string $source, int &$blocked, bool $blockExternal): void
    {
        $lower = strtolower($source);
        if (str_starts_with($lower, 'data:image/')) {
            $element->setAttribute('src', $source);
            return;
        }
        if (str_starts_with($lower, 'cid:')) {
            $cid = trim(substr($source, 4), " \t<>");
            if ($cid === '') {
                return;
            }
            if ($blockExternal) {
                $element->setAttribute(self::ATTR_CID, $cid);
            } else {
                $element->setAttribute('src', 'cid:' . $cid);
            }
            return;
        }
        $scheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return;
        }
        if ($blockExternal) {
            $element->setAttribute(self::ATTR_BLOCKED, $source);
            $blocked++;
        } else {
            $element->setAttribute('src', $source);
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
