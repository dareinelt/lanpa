<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

/**
 * Qualitätsprüfung für Meldungen aus dem öffentlichen Störungsformular (`/stoerung`).
 *
 * Das Formular ist bewusst kurz. Erfahrungsgemäß entstehen daraus aber Meldungen, mit denen
 * der Help Desk nichts anfangen kann: ein halber Aufsatz im Betreff, eine Beschreibung, die
 * nur aus einem Screenshot besteht, Floskeln wie „siehe unten“, die bloße Inventarnummer des
 * Geräts („PC24001“) oder die wörtliche Wiederholung des Betreffs. Diese Prüfung läuft vor dem
 * Anlegen und liefert Fehlermeldungen je Formularfeld.
 *
 * Die Prüfung ist bewusst nur für das Störungsformular gedacht; Agenten- und Portalformular
 * haben eigene, umfangreichere Pflichtfelder.
 */
final class QuickReportContentValidator
{
    /** Der Betreff ist eine Überschrift, keine Beschreibung. */
    public const MAX_SUBJECT_CHARS = 120;

    /** Mindestumfang der Beschreibung in Buchstaben/Ziffern (Bilder und Floskeln zählen nicht). */
    public const MIN_DESCRIPTION_CHARS = 15;

    /** Ab so vielen echten Zeichen gilt eine Beschreibung nach Abzug der Floskeln als inhaltlich. */
    private const MIN_REMAINING_CHARS = 12;

    /**
     * Inventar-/Gerätenummern wie „PC24001“, „DR19015“, „NB-0815“ oder „24001“. Sie benennen
     * das Gerät, sagen aber nichts über die Störung aus – allein im Betreff reicht das nicht.
     */
    private const INVENTORY_NUMBER_PATTERN = '/(?<![a-z0-9])[a-z]{0,4}[- .\/]?\d{3,}(?![a-z0-9])/u';

    /**
     * Verweise auf einen anderen Text. Sie sagen nichts über die Störung aus und sind deshalb
     * im Betreff immer und in der Beschreibung zumindest dann unbrauchbar, wenn sonst nichts
     * stehen bleibt. Vergleich auf normalisierten Wortfolgen (klein, ohne Umlaute/Satzzeichen).
     */
    private const REFERENCE_PHRASES = 'siehe(?: auch| dazu| bitte| mal)? (?:unten|oben|weiter unten|unten stehend|'
        . 'untenstehend|nachfolgend|nachstehend|beschreibung|anhang|anlage|anlagen|bild|bilder|screenshot|'
        . 'screenshots|foto|fotos|datei|dateien|mail|e mail|email|nachricht|kommentar|ticket|betreff|titel|'
        . 'beigefuegt[a-z]*|angehaengt[a-z]*|angefuegt[a-z]*)'
        . '|s u|s o|s (?:unten|oben|beschreibung|anhang|anlage|bild|screenshot)'
        . '|vgl (?:unten|oben|beschreibung|anhang|bild|screenshot)'
        . '|wie (?:besprochen|bereits besprochen|telefonisch besprochen|bereits mitgeteilt|bereits erwaehnt|'
        . 'bereits bekannt|unten beschrieben|oben beschrieben|im bild|im screenshot|im anhang|'
        . 'in der beschreibung|immer|ueblich|betreff)'
        . '|naeheres siehe unten|details siehe unten|mehr siehe unten|steht (?:unten|im betreff|im titel)'
        . '|betreff sagt alles';

    private const SUBJECT_FILLER_PATTERN = '/(?:^| )(?:' . self::REFERENCE_PHRASES . ')(?= |$)/u';

    /**
     * Für die Beschreibung zusätzlich reine Medienverweise („der Screenshot“, „im Anhang“).
     * Sie werden nur abgezogen; verworfen wird die Beschreibung erst, wenn danach zu wenig
     * eigentlicher Text übrig bleibt – „Der Screenshot zeigt den Fehler 0x800“ bleibt gültig.
     */
    private const DESCRIPTION_FILLER_PATTERN = '/(?:^| )(?:' . self::REFERENCE_PHRASES
        . '|im anhang|anbei|im bild|im screenshot|im foto|der screenshot|das bild|die bilder|der anhang|'
        . 'die anlage|die datei|das foto)(?= |$)/u';

    /** Betreff/Beschreibung, die für sich genommen keine Aussage über die Störung treffen. */
    private const GENERIC_ONLY = [
        'hilfe', 'problem', 'fehler', 'stoerung', 'frage', 'dringend', 'wichtig',
        'kaputt', 'defekt', 'geht nicht', 'geht nicht mehr', 'funktioniert nicht',
        'funktioniert nicht mehr', 'keine ahnung', 'weiss nicht', 'unbekannt', 'sonstiges',
        'diverses', 'keine angabe', 'ohne beschreibung', 'keine beschreibung',
        'beschreibung fehlt', 'bitte helfen', 'bitte um hilfe', 'bitte helfen sie mir',
        'ich brauche hilfe', 'hilfe benoetigt', 'dringend hilfe', 'hilfe dringend',
        'eilt', 'asap', 'sofort', 'notfall', 'kein plan', 'keine info', 'bitte pruefen',
        'bitte anschauen', 'bitte ansehen', 'check', 'test', 'n a', 'k a', 'kein text',
        'siehe betreff', 'betreff sagt alles', 'screenshot', 'screenshots', 'bild', 'bilder',
        'foto', 'fotos', 'anhang', 'anlage', 'datei',
    ];

    /** Tastatur- und Platzhaltereingaben, die kein Betreff sind. */
    private const PLACEHOLDERS = [
        'asdf', 'asdfasdf', 'asd', 'dfg', 'hjkl', 'qwer', 'qwertz', 'yxcv', 'test',
        'testtest', 'blabla', 'bla', 'foo', 'bar', 'abc', 'xyz', 'xxx', 'yyy', 'zzz',
        '123', '1234', '12345',
    ];

    /**
     * Prüft Betreff und Beschreibung einer Störungsmeldung.
     *
     * Leere Pflichtfelder werden hier nicht gemeldet – die übernimmt der TicketService mit
     * seinen Standardmeldungen. So entstehen keine doppelten oder widersprüchlichen Fehler.
     *
     * @return array<string,string> Feldname => Meldung; leer, wenn die Meldung in Ordnung ist
     */
    public static function validate(string $subject, string $description): array
    {
        $subject = trim($subject);
        $description = trim($description);
        if ($subject === '' || $description === '') {
            return [];
        }

        $errors = [];
        $subjectKey = self::normalize($subject);

        if (mb_strlen($subject, 'UTF-8') > self::MAX_SUBJECT_CHARS) {
            $errors['subject'] = 'Der Betreff ist zu lang (max. ' . self::MAX_SUBJECT_CHARS
                . ' Zeichen). Bitte fassen Sie sich kurz und beschreiben Sie die Störung im Feld „Was ist passiert?“.';
        } elseif (self::containsFiller($subjectKey)) {
            $errors['subject'] = 'Der Betreff verweist nur auf einen anderen Text (z. B. „siehe unten“). '
                . 'Bitte nennen Sie im Betreff konkret, worum es geht.';
        } elseif (self::isGenericOnly($subjectKey) || self::isPlaceholder($subjectKey)) {
            $errors['subject'] = 'Bitte nennen Sie im Betreff konkret, was nicht funktioniert.';
        } elseif (self::isInventoryNumberOnly($subjectKey)) {
            $errors['subject'] = 'Der Betreff besteht nur aus einer Inventar- oder Gerätenummer. '
                . 'Bitte beschreiben Sie zusätzlich, was an diesem Gerät nicht funktioniert.';
        }

        $plain = self::stripMedia($description);
        if (self::meaningfulLength($plain) < self::MIN_DESCRIPTION_CHARS) {
            $errors['description'] = 'Bitte beschreiben Sie die Störung in eigenen Worten (mindestens '
                . self::MIN_DESCRIPTION_CHARS . ' Zeichen). Ein Screenshot allein genügt nicht.';

            return $errors;
        }

        $descriptionKey = self::normalize($plain);
        if (self::isGenericOnly($descriptionKey) || self::isPlaceholder($descriptionKey)) {
            $errors['description'] = 'Bitte beschreiben Sie die Störung konkret. Floskeln wie „Hilfe“ oder „siehe Bild“ reichen nicht aus.';

            return $errors;
        }
        if (self::isFillerOnly($descriptionKey)) {
            $errors['description'] = 'Bitte beschreiben Sie die Störung konkret. Ein Verweis wie „siehe Bild“ oder „siehe Anhang“ reicht nicht aus.';

            return $errors;
        }
        if (self::isInventoryNumberOnly($descriptionKey)) {
            $errors['description'] = 'Die Beschreibung besteht nur aus einer Inventar- oder Gerätenummer. '
                . 'Bitte beschreiben Sie in eigenen Worten, was nicht funktioniert.';

            return $errors;
        }
        if (self::repeatsSubject($subjectKey, $descriptionKey)) {
            $errors['description'] = 'Bitte beschreiben Sie die Störung im Feld „Was ist passiert?“ und wiederholen Sie nicht nur den Betreff.';
        }

        return $errors;
    }

    /**
     * Entfernt Bilder aus der Beschreibung. Der Editor legt sie als Marke `[Bild 1]` ab und
     * ersetzt diese beim Absenden durch `![Bild](data:image/…)`; zusätzlich werden rohe
     * Daten-URIs und Base64-Reste entfernt, damit ein Screenshot nicht als Text zählt.
     */
    private static function stripMedia(string $text): string
    {
        $patterns = [
            '/!\[[^\]\r\n]{0,300}\]\(\s*data:image\/[^)]*\)/iu',
            '/!\[[^\]\r\n]{0,300}\]\([^)]*\)/u',
            '/<img\b[^>]*>/iu',
            '/data:image\/[a-z0-9.+-]+(?:;[a-z0-9=._-]+)*;base64,[A-Za-z0-9+\/=]+/iu',
            '/(?:iVBORw0KGgo|\/9j\/|R0lGOD|UklGR)[A-Za-z0-9+\/=]{100,}/u',
            '/(?<![A-Za-z0-9+\/=])[A-Za-z0-9+\/=]{60,}(?![A-Za-z0-9+\/=])/u',
            '/\[Bild\s+\d+\]/iu',
            '/<[^>]{1,200}>/u',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        return $text;
    }

    /** Kleinschreibung, Umlaute aufgelöst, Satzzeichen zu Leerzeichen, Mehrfach-Leerzeichen zusammengefasst. */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private static function meaningfulLength(string $text): int
    {
        return mb_strlen(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '', 'UTF-8');
    }

    private static function containsFiller(string $normalized): bool
    {
        return preg_match(self::SUBJECT_FILLER_PATTERN, $normalized) === 1;
    }

    private static function isGenericOnly(string $normalized): bool
    {
        return in_array($normalized, self::GENERIC_ONLY, true);
    }

    private static function isPlaceholder(string $normalized): bool
    {
        $compact = str_replace(' ', '', $normalized);
        if ($compact === '') {
            return true;
        }
        if (preg_match('/^(.)\1{2,}$/u', $compact) === 1) {
            return true;
        }

        return in_array($compact, self::PLACEHOLDERS, true);
    }

    /** Nach Abzug der Inventarnummern bleibt kein inhaltlicher Text übrig („PC24001 kaputt“). */
    private static function isInventoryNumberOnly(string $normalized): bool
    {
        $rest = preg_replace(self::INVENTORY_NUMBER_PATTERN, ' ', $normalized) ?? '';
        $rest = trim(preg_replace('/\s+/', ' ', $rest) ?? '');
        if ($rest === '' || self::meaningfulLength($rest) < 5) {
            return true;
        }

        return self::isGenericOnly($rest);
    }

    /** Nach Abzug aller Verweise und Medienbegriffe bleibt zu wenig eigener Text übrig. */
    private static function isFillerOnly(string $descriptionKey): bool
    {
        $rest = preg_replace(self::DESCRIPTION_FILLER_PATTERN, ' ', $descriptionKey) ?? '';

        return self::meaningfulLength($rest) < self::MIN_REMAINING_CHARS;
    }

    /** Die Beschreibung besteht im Kern nur aus dem Betreff – höchstens ergänzt um Floskeln. */
    private static function repeatsSubject(string $subjectKey, string $descriptionKey): bool
    {
        if ($subjectKey === '' || !str_contains(' ' . $descriptionKey . ' ', ' ' . $subjectKey . ' ')) {
            return false;
        }
        $rest = trim(str_replace(' ' . $subjectKey . ' ', ' ', ' ' . $descriptionKey . ' '));
        $rest = preg_replace(self::DESCRIPTION_FILLER_PATTERN, ' ', $rest) ?? '';

        return self::meaningfulLength($rest) < self::MIN_REMAINING_CHARS;
    }
}
