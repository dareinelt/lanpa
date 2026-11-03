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
    private const DROP = ['head', 'title', 'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'textarea', 'select', 'option', 'button', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'video', 'audio', 'canvas'];

    /** Elemente, die erhalten bleiben. */
    private const ALLOWED = ['p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'small', 'ul', 'ol', 'li', 'blockquote', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'code', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'img'];

    /** Zusätzliche Elemente für E-Mail-Dokumente (Anzeige im isolierten Rahmen). */
    private const DOCUMENT_ALLOWED = ['font', 'center', 'caption', 'colgroup', 'col'];

    /** Attribute, die an jedem erlaubten Element stehen dürfen. */
    private const GLOBAL_ATTRIBUTES = ['style', 'class', 'title', 'dir', 'lang'];

    /** Obergrenze für die Stylesheets eines E-Mail-Dokuments in Zeichen. */
    private const MAX_STYLESHEET_CHARS = 100000;

    /** Verbotene Muster in style-Attributen (Skripte, Fremdverweise, Überlagerung). */
    private const STYLE_DENY = '/expression|javascript:|vbscript:|behaviou?r\s*:|binding\s*:|@import|-moz-binding|position\s*:\s*fixed|url\s*\(\s*[\'"]?\s*(?!data:image\/)/i';

    /**
     * Bereinigt HTML und liefert die erlaubten Fragmente zurück.
     *
     * @param string $html Roh-HTML aus dem Editor oder einer E-Mail
     * @param int $maxLength Obergrenze für die Eingabelänge in Zeichen
     * @param bool $allowClasses class-Attribute behalten; für HTML fremder Absender (E-Mail) false,
     *                           damit es keine Klassen der Oberfläche (feste Overlays) nutzen kann
     */
    public static function clean(string $html, int $maxLength = 200000, bool $allowClasses = true): string
    {
        return self::sanitize($html, $maxLength, $allowClasses, false);
    }

    /**
     * Bereinigt eine eingehende HTML-E-Mail für die Anzeige im isolierten Rahmen
     * (`<iframe sandbox>` mit eigener CSP). Anders als {@see clean()} bleiben `<style>`-Blöcke
     * (gefiltert), `class`-Attribute und einige typische Mail-Elemente (`font`, `center`, `bgcolor` …)
     * erhalten, damit die Nachricht so aussieht wie im Mailprogramm. Das Ergebnis darf nur in
     * diesem Rahmen ausgegeben werden, nie direkt in einer Seite der Anwendung.
     */
    public static function mailDocument(string $html, int $maxLength = 200000): string
    {
        return self::sanitize($html, $maxLength, true, true);
    }

    private static function sanitize(string $html, int $maxLength, bool $allowClasses, bool $document): string
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
        $styles = '';
        if ($document) {
            [$html, $styles] = self::extractStyleSheets($html);
        }
        $html = self::bodyContent($html);
        // Nicht-ASCII als Zeichenreferenzen: libxml liest so auch dann richtig, wenn die Mail einen
        // anderen Zeichensatz (z. B. iso-8859-1) deklariert, ihr Inhalt aber bereits UTF-8 ist.
        $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        // Ein eigenes <body> als Wurzel: ein überzähliges </div> im Fragment kann es nicht schließen.
        $loaded = $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return self::fallback($html);
        }
        $root = $doc->getElementsByTagName('body')->item(0);
        if ($root === null) {
            return self::fallback($html);
        }

        self::walk($root, $allowClasses, $document);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $doc->saveHTML($child);
        }
        $out = trim($out);
        if ($styles !== '' && $out !== '') {
            $out = '<style>' . $styles . '</style>' . $out;
        }

        return $out;
    }

    /**
     * Entfernt alle `<style>`-Blöcke aus dem HTML und liefert ihren gefilterten Inhalt gesammelt zurück.
     *
     * @return array{0:string,1:string} HTML ohne Stylesheets, bereinigtes CSS
     */
    private static function extractStyleSheets(string $html): array
    {
        $css = [];
        $html = (string) preg_replace_callback('#<style\b[^>]*>(.*?)</style\s*>#is', static function (array $m) use (&$css): string {
            $css[] = $m[1];

            return '';
        }, $html);
        $styles = self::sanitizeStyleSheet(implode("\n", $css));

        return [$html, $styles];
    }

    /**
     * Filtert ein Stylesheet aus einer E-Mail: keine Fremdverweise (`@import`, `url()` außer
     * eingebetteten Rasterbildern), keine Skriptausdrücke und kein `<`, damit der Block nicht
     * vorzeitig geschlossen werden kann. Die CSP des Anzeigerahmens bleibt die zweite Schutzschicht.
     */
    private static function sanitizeStyleSheet(string $css): string
    {
        $css = str_replace(['<!--', '-->', '<', '\\'], '', $css);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('#/\*.*$#s', '', $css);
        $css = (string) preg_replace('/@import\b[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/url\s*\(\s*(?![\'"]?\s*data:image\/(?:png|jpe?g|gif|webp)[;,])[^)]*\)/i', 'none', $css);
        $css = (string) preg_replace('/expression\s*\(|javascript:|vbscript:|behaviou?r\s*:|-moz-binding|binding\s*:/i', '', $css);
        $css = trim($css);
        if (mb_strlen($css) > self::MAX_STYLESHEET_CHARS) {
            return '';
        }

        return $css;
    }

    /**
     * Inhalt des <body> eines vollständigen HTML-Dokuments; Kopfbereich, Doctype und
     * Zeichensatz-Angaben gehören nicht in den Ticketverlauf.
     */
    private static function bodyContent(string $html): string
    {
        // Kopfbereich samt Titel entfernen; Inhalt vor <body> (ohne Kopf) und nach </body>
        // – etwa ein angehängter Herkunftshinweis – bleibt erhalten.
        $html = (string) preg_replace('#<head\b[^>]*>.*?</head\s*>#is', '', $html);
        $html = (string) preg_replace('#<title\b[^>]*>.*?</title\s*>#is', '', $html);
        $html = (string) preg_replace('#<!DOCTYPE[^>]*>|</?html\b[^>]*>|</?body\b[^>]*>#i', '', $html);

        return (string) preg_replace('#<meta\b[^>]*>#i', '', $html);
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
    private static function walk(\DOMNode $node, bool $allowClasses, bool $document): void
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
            self::walk($child, $allowClasses, $document);
            if (!in_array($tag, self::ALLOWED, true) && !($document && in_array($tag, self::DOCUMENT_ALLOWED, true))) {
                self::unwrap($child);
                continue;
            }
            self::attributes($child, $tag, $allowClasses, $document);
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

    private static function attributes(\DOMElement $element, string $tag, bool $allowClasses, bool $document): void
    {
        $allowed = $allowClasses ? self::GLOBAL_ATTRIBUTES : array_values(array_diff(self::GLOBAL_ATTRIBUTES, ['class']));
        $allowed = match ($tag) {
            'a' => [...$allowed, 'href', 'target', 'rel'],
            'img' => [...$allowed, 'src', 'alt', 'width', 'height'],
            'td', 'th' => [...$allowed, 'colspan', 'rowspan', 'align', 'valign', 'width', 'height'],
            'table' => [...$allowed, 'cellpadding', 'cellspacing', 'border', 'width', 'align'],
            default => $allowed,
        };
        if ($document) {
            $allowed = match ($tag) {
                'table', 'tr', 'td', 'th' => [...$allowed, 'bgcolor', 'valign'],
                'font' => [...$allowed, 'color', 'face', 'size'],
                'col', 'colgroup' => [...$allowed, 'span', 'width', 'align', 'valign'],
                'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'caption' => [...$allowed, 'align'],
                default => $allowed,
            };
        }

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
            'width', 'height', 'colspan', 'rowspan', 'cellpadding', 'cellspacing', 'border', 'span' => preg_match('/^\d{1,4}%?$/', $value) === 1,
            'bgcolor', 'color' => preg_match('/^#?[A-Za-z0-9]{1,20}$/', $value) === 1,
            'face' => preg_match('/^[A-Za-z0-9 ,_\-]{1,120}$/', $value) === 1,
            'size' => preg_match('/^[+-]?[1-7]$/', $value) === 1,
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
