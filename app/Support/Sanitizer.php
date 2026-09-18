<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Bereinigt Rich-Text-HTML (Textseiten) anhand einer strikten Erlaubnisliste.
 *
 * Es werden ausschliesslich Formatierungs-Tags und sichere Link-Ziele
 * zugelassen. Skripte, Event-Handler, Styles und gefaehrliche Elemente werden
 * entfernt, unbekannte Huellelemente werden aufgeloest (Inhalt bleibt erhalten).
 */
final class Sanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins',
        'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'code', 'pre', 'sub', 'sup', 'mark', 'span',
    ];

    /** @var list<string> */
    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'link', 'meta',
        'title', 'base', 'form', 'input', 'button', 'textarea', 'select', 'option', 'noscript',
    ];

    /** @var list<string> */
    private const ALLOWED_ATTRIBUTES = ['class', 'title', 'dir', 'lang'];

    /** @var list<string> */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function html(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="sanitize-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        $root = $dom->getElementById('sanitize-root');
        if ($root === null) {
            return '';
        }

        self::clean($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child) ?: '';
        }

        return trim($output);
    }

    private static function clean(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (!$child instanceof DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unbekannte Huelle: erst Inhalt bereinigen, dann aufloesen.
                self::clean($child);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            self::cleanAttributes($child, $tag);
            self::clean($child);
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $names = [];
        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);

            if ($tag === 'a' && $lower === 'href') {
                $href = self::sanitizeUrl($element->getAttribute($name));
                if ($href === null) {
                    $element->removeAttribute($name);
                } else {
                    $element->setAttribute('href', $href);
                }

                continue;
            }

            if ($tag === 'a' && ($lower === 'target' || $lower === 'rel')) {
                // Wird weiter unten anhand des Ziels gesetzt.
                $element->removeAttribute($name);

                continue;
            }

            if (!in_array($lower, self::ALLOWED_ATTRIBUTES, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $isExternal = preg_match('#^https?://#i', $element->getAttribute('href')) === 1;
            if ($isExternal) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            } else {
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
            }
        }
    }

    private static function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, self::LINK_SCHEMES, true)) {
            return null;
        }

        if ($scheme === 'http' || $scheme === 'https') {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return null;
            }

            return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
        }

        return $url;
    }
}
