<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Spellcheck;

use App\Exceptions\HttpException;

/**
 * Deterministische Basis-Korrektur ohne KI: bereinigt Leerzeichen- und
 * Interpunktionsfehler, doppelte Füllwörter, typische deutsche Tippfehler,
 * eindeutige Hunspell-Ein-Zeichen-Vertipper sowie kleingeschriebene Satzanfänge.
 *
 * Eingebettete URLs, E-Mail-Adressen, Dateipfade, Code, @Erwähnungen,
 * Ticketnummern sowie Platzhalter ([Bild N], {name}) bleiben garantiert
 * unangetastet.
 */
final class SpellcheckProofreader
{
    public const MAX_TEXT = 8000;

    /** Eindeutige Substantiv-Tippfehler (immer großgeschrieben). */
    private const FIXED_NOUNS = [
        'standart' => 'Standard',
        'standarts' => 'Standards',
        'reperatur' => 'Reparatur',
        'reperaturen' => 'Reparaturen',
        'lizens' => 'Lizenz',
        'lizensen' => 'Lizenzen',
        'addresse' => 'Adresse',
        'addressen' => 'Adressen',
        'emailladresse' => 'E-Mail-Adresse',
        'emailadresse' => 'E-Mail-Adresse',
        'passowrt' => 'Passwort',
        'passwort' => 'Passwort',
        'kennowrt' => 'Kennwort',
        'kennwort' => 'Kennwort',
        'mitarbieter' => 'Mitarbeiter',
        'mitarbieterin' => 'Mitarbeiterin',
        'mitarbietern' => 'Mitarbeitern',
        'abteilug' => 'Abteilung',
        'abteilugn' => 'Abteilung',
        'probelm' => 'Problem',
        'probelme' => 'Probleme',
        'probelmen' => 'Problemen',
        'drukker' => 'Drucker',
        'drucker' => 'Drucker',
        'tastattur' => 'Tastatur',
        'bildschrim' => 'Bildschirm',
        'monitro' => 'Monitor',
        'netzewrk' => 'Netzwerk',
        'datenbnak' => 'Datenbank',
        'fehlermedlung' => 'Fehlermeldung',
        'fehlermeldung' => 'Fehlermeldung',
        'rückmedlung' => 'Rückmeldung',
        'rueckmeldung' => 'Rückmeldung',
        'rückmeldung' => 'Rückmeldung',
        'beshcreibung' => 'Beschreibung',
        'nachrihct' => 'Nachricht',
        'anhnag' => 'Anhang',
        'weiterleitnug' => 'Weiterleitung',
        'terminkalneder' => 'Terminkalender',
        'telefno' => 'Telefon',
        'vorraussetzung' => 'Voraussetzung',
        'vorraussetzungen' => 'Voraussetzungen',
        'berechtigugn' => 'Berechtigung',
        'berechtigugnen' => 'Berechtigungen',
        'einstellugn' => 'Einstellung',
        'einstellugnen' => 'Einstellungen',
        'verbindugn' => 'Verbindung',
        'anmeldnug' => 'Anmeldung',
        'störugn' => 'Störung',
    ];

    /** Eindeutige Kleinschreibungs-Tippfehler (Satzanfangs-Großschreibung bleibt erhalten). */
    private const FIXED_WORDS = [
        'garnicht' => 'gar nicht',
        'garkein' => 'gar kein',
        'garkeine' => 'gar keine',
        'garkeinen' => 'gar keinen',
        'garkeinem' => 'gar keinem',
        'garkeiner' => 'gar keiner',
        'standartmäßig' => 'standardmäßig',
        'standartmässig' => 'standardmäßig',
        'reperieren' => 'reparieren',
        'reperiert' => 'repariert',
        'lizensiert' => 'lizenziert',
        'lizensieren' => 'lizenzieren',
        'wiederspiegeln' => 'widerspiegeln',
        'wiederspiegelt' => 'widerspiegelt',
        'ebend' => 'eben',
        'vorraus' => 'voraus',
        'weitergeleitetet' => 'weitergeleitet',
        'gelösst' => 'gelöst',
        'funktioneirt' => 'funktioniert',
        'funktionert' => 'funktioniert',
        'instalieren' => 'installieren',
        'instaliert' => 'installiert',
        'aktualiseren' => 'aktualisieren',
        'aktualisert' => 'aktualisiert',
        'konfigureiren' => 'konfigurieren',
        'konfigureirt' => 'konfiguriert',
        'erhatlen' => 'erhalten',
        'keinene' => 'keinen',
        'keien' => 'keine',
        'wurdne' => 'wurden',
        'habne' => 'haben',
        'köännen' => 'können',
        'koennen' => 'können',
        'moechte' => 'möchte',
        'moechten' => 'möchten',
        'fuer' => 'für',
        'ueber' => 'über',
    ];

    /** Gängige Abkürzungen, nach deren Punkt kein neuer Satz erzwungen wird. */
    private const ABBREVIATIONS = [
        'z', 'b', 'u', 'a', 'd', 'h', 's', 'o',
        'bzw', 'ca', 'inkl', 'ggf', 'evtl', 'usw', 'etc',
        'nr', 'dr', 'prof', 'tel', 'str', 'abs', 'abb',
        'min', 'std', 'sek', 'vgl', 'bsp', 'sog', 'fa', 'hr', 'fr',
    ];

    /**
     * @return array{text:string,changed:bool,replacements:int}
     */
    public function proofread(string $text, ?SpellcheckService $engine = null): array
    {
        $source = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        if (trim($source) === '') {
            throw new HttpException('Es ist kein Text zum Korrigieren vorhanden.', 422);
        }
        if (mb_strlen($source, 'UTF-8') > self::MAX_TEXT) {
            throw new HttpException('Der Text ist zu lang (höchstens ' . number_format(self::MAX_TEXT, 0, ',', '.') . ' Zeichen).', 422);
        }

        $usableEngine = $engine !== null && $engine->isUsable() ? $engine : null;
        $protected = [];
        $working = self::maskProtected($source, $protected);
        $replacements = 0;

        $lines = explode("\n", $working);
        foreach ($lines as $index => $line) {
            $lines[$index] = $this->processLine($line, $usableEngine, $replacements);
        }

        $result = self::unmaskProtected(implode("\n", $lines), $protected);

        return [
            'text' => $result,
            'changed' => $result !== $source,
            'replacements' => $replacements,
        ];
    }

    private function processLine(string $line, ?SpellcheckService $engine, int &$replacements): string
    {
        if (trim($line) === '') {
            return $line;
        }

        $beforeTypo = $line;
        // Doppelte Leerzeichen innerhalb der Zeile reduzieren (führende Einrückung bleibt erhalten)
        $line = (string) preg_replace('/(?<=\S)[ \t]{2,}/u', ' ', $line);
        // Leerzeichen vor Satzzeichen entfernen ("Danke !" -> "Danke!")
        $line = (string) preg_replace('/(?<=[\p{L}\p{N}])[ \t]+([,;!?])/u', '$1', $line);
        $line = (string) preg_replace('/(?<=[\p{L}])[ \t]+\.(?=\s|$)/u', '.', $line);
        // Fehlendes Leerzeichen nach Komma zwischen Buchstaben ergänzen ("Hallo,bitte" -> "Hallo, bitte")
        $line = (string) preg_replace('/(?<=[\p{L}]),(?=[\p{L}])/u', ', ', $line);
        if ($line !== $beforeTypo) {
            $replacements++;
        }

        // Unbeabsichtigte Wortwiederholungen ("bitte bitte", "und und", "die die" ohne Komma davor) bereinigen
        $line = (string) preg_replace_callback(
            '/\b(und|oder|aber|bitte|nicht|auch|noch|schon|ist|sind|wird|wurde|kann|zu|im|am|für|mit|von|bei|nach|ein|eine|einen|einem|einer)[ \t]+\1\b/iu',
            static function (array $m) use (&$replacements): string {
                $replacements++;
                return $m[1];
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/(?<![,;:])(?<![,;:] )(?<![,;:]  )\b(der|die|das|den|dem|des)[ \t]+\1\b/iu',
            static function (array $m) use (&$replacements): string {
                $replacements++;
                return $m[1];
            },
            $line
        );

        // Häufige Gruß-, Anrede- und Dankesformeln sowie Kontextverwechslungen korrigieren
        $line = (string) preg_replace_callback(
            '/\b(guten)[ \t]+(morgen|tag|abend)\b/iu',
            static function (array $m) use (&$replacements): string {
                $candidate = (self::startsUppercase($m[1]) ? 'Guten' : 'guten') . ' ' . self::ucFirst(mb_strtolower($m[2], 'UTF-8'));
                if ($candidate !== $m[0]) {
                    $replacements++;
                }
                return $candidate;
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/\b(vielen|besten|herzlichen|tausend)[ \t]+dank\b/iu',
            static function (array $m) use (&$replacements): string {
                $candidate = $m[1] . ' Dank';
                if ($candidate !== $m[0]) {
                    $replacements++;
                }
                return $candidate;
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/\b(mit[ \t]+(?:freundlichen|besten|herzlichen))[ \t]+gr(?:ü|ue)ßen\b/iu',
            static function (array $m) use (&$replacements): string {
                $candidate = preg_replace('/[ \t]+/', ' ', $m[1]) . ' Grüßen';
                if ($candidate !== $m[0]) {
                    $replacements++;
                }
                return $candidate;
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/\b(viele|beste|liebe|schöne|schoene|freundliche|herzliche)[ \t]+gr(?:ü|ue)ße\b/iu',
            static function (array $m) use (&$replacements): string {
                $adj = preg_replace('/oe/i', 'ö', $m[1]) ?? $m[1];
                $candidate = $adj . ' Grüße';
                if ($candidate !== $m[0]) {
                    $replacements++;
                }
                return $candidate;
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/\b(seid)(?=[ \t]+(?:\d+\s*(?:Uhr|Min|Minuten|Std|Stunden|Tagen?|Wochen?|Monaten?|Jahren?)\b|(?:dem|gestern|heute|vorgestern|kurzem|langem|längerem|einigen|einem|einer|zwei|drei|vier|fünf|sechs|sieben|acht|neun|zehn|mehreren|vielen|wann|Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag|Wochen|Tagen|Stunden|Minuten|Monaten|Jahren|Beginn|Anfang|Ende|Update|Upgrade|Neustart|Austausch|Wechsel|Umstellung)\b))/iu',
            static function (array $m) use (&$replacements): string {
                $replacements++;
                return self::startsUppercase($m[1]) ? 'Seit' : 'seit';
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/\b(wider)(?=[ \t]+(?:einwandfrei|erwartungsgemäß|normal|erreichbar|verfügbar|funktioniert|funktionieren|ok|gut|da|online|aktiv|hergestellt|starten|gestartet|anmelden|angemeldet|drucken|möglich|offen|geschlossen|zurück|abholen|frei|stabil|schnell|problemlos|korrekt|ordnungsgemäß|wie)\b)/iu',
            static function (array $m) use (&$replacements): string {
                $replacements++;
                return self::startsUppercase($m[1]) ? 'Wieder' : 'wieder';
            },
            $line
        );
        $line = (string) preg_replace_callback(
            '/(?:^|(?<=[.!?][ \t]))mfg[ \t]*\.?[ \t]*$/iu',
            static function () use (&$replacements): string {
                $replacements++;
                return 'Mit freundlichen Grüßen';
            },
            $line
        );

        // Wortweise Korrektur (feste Tippfehlerliste + eindeutige Hunspell-Treffer)
        $line = $this->correctWords($line, $engine, $replacements);

        // Satzanfänge großschreiben
        $line = $this->capitalizeSentenceStarts($line, $engine, $replacements);

        return $line;
    }

    private function correctWords(string $line, ?SpellcheckService $engine, int &$replacements): string
    {
        if (preg_match_all('/[\p{L}\p{M}0-9]+(?:[.\-\'\x{2019}]?[\p{L}\p{M}0-9]+)*/u', $line, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            return $line;
        }

        $out = '';
        $cursor = 0;
        $lineLen = strlen($line);
        $prevWordLower = '';

        foreach ($matches[0] as [$word, $byteOffset]) {
            $gap = substr($line, $cursor, $byteOffset - $cursor);
            $out .= $gap;
            if ($cursor > 0 && preg_match('/^[ \t]+$/', $gap) !== 1) {
                $prevWordLower = '';
            }
            $endByte = $byteOffset + strlen($word);
            $followedByDot = $endByte < $lineLen && $line[$endByte] === '.';
            $replacement = $this->correctSingleToken($word, $followedByDot, $prevWordLower, $engine);
            if ($replacement !== $word) {
                $replacements++;
            }
            $out .= $replacement;
            $prevWordLower = mb_strtolower($replacement, 'UTF-8');
            $cursor = $endByte;
        }

        $out .= substr($line, $cursor);

        return $out;
    }

    private function correctSingleToken(string $word, bool $followedByDot, string $prevWordLower, ?SpellcheckService $engine): string
    {
        $len = mb_strlen($word, 'UTF-8');
        if ($len < 2 || $len > SpellcheckService::MAX_WORD_LENGTH) {
            return $word;
        }
        // Wörter mit Ziffern oder komplett in Großbuchstaben ("EDV", "VLAN") überspringen
        if (preg_match('/[0-9]/', $word) === 1) {
            return $word;
        }
        if ($word === mb_strtoupper($word, 'UTF-8') && $word !== mb_strtolower($word, 'UTF-8')) {
            return $word;
        }

        $lower = mb_strtolower($word, 'UTF-8');
        if (isset(self::FIXED_NOUNS[$lower])) {
            return self::FIXED_NOUNS[$lower];
        }
        if (isset(self::FIXED_WORDS[$lower])) {
            $fixed = self::FIXED_WORDS[$lower];
            return self::startsUppercase($word) ? self::ucFirst($fixed) : $fixed;
        }

        if ($engine === null) {
            return $word;
        }

        $key = $followedByDot ? $word . '.' : $word;
        if ($engine->check($key) || ($followedByDot && $engine->check($word))) {
            return $word;
        }

        // 1. Kleingeschriebenes Substantiv direkt prüfen ("netzteil" -> "Netzteil", < 0,1 ms statt teurem suggest())
        if ($word === $lower) {
            $capitalized = self::ucFirst($word);
            if ($capitalized !== $word && $engine->check($capitalized)) {
                return $capitalized;
            }
        }

        // 2. Eindeutige ASCII-Umlautschreibung ("geprueft" -> "geprüft", "das geraet" -> "das Gerät")
        $umlaut = self::restoreAsciiUmlauts($word);
        if ($umlaut !== $word) {
            $afterArticle = in_array($prevWordLower, [
                'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einem', 'einen', 'einer',
                'kein', 'keine', 'keinem', 'keinen', 'ihr', 'sein', 'unser', 'dieses', 'diesem', 'welches',
                'am', 'im', 'zum', 'zur', 'vom', 'beim',
            ], true);
            $umlautCap = self::ucFirst($umlaut);
            if ($afterArticle && $umlaut === mb_strtolower($umlaut, 'UTF-8') && $engine->check($umlautCap)) {
                return $umlautCap;
            }
            if ($engine->check($umlaut)) {
                return $umlaut;
            }
            if ($umlaut === mb_strtolower($umlaut, 'UTF-8') && $engine->check($umlautCap)) {
                return $umlautCap;
            }
        }

        $suggestions = $engine->suggest($word);
        if ($suggestions === []) {
            return $word;
        }

        return self::pickSafeSuggestion($word, $lower, $suggestions) ?? $word;
    }

    private static function restoreAsciiUmlauts(string $word): string
    {
        if (preg_match('/(?:ae|oe|ue)/i', $word) !== 1) {
            return $word;
        }
        return (string) preg_replace_callback(
            '/(?<![qQaAeEiIoOuUyYäöüÄÖÜ])(Ae|Oe|Ue|ae|oe|ue)/u',
            static fn (array $m): string => match ($m[1]) {
                'Ae' => 'Ä',
                'Oe' => 'Ö',
                'Ue' => 'Ü',
                'ae' => 'ä',
                'oe' => 'ö',
                'ue' => 'ü',
                default => $m[1],
            },
            $word
        );
    }

    /**
     * Übernimmt einen Hunspell-Vorschlag nur bei hoher Sicherheit:
     * 1. Reine Groß-/Kleinschreibungs-Korrektur (z. B. kleingeschriebenes Substantiv "drucker" -> "Drucker"), oder
     * 2. Wortlänge >= 5 und eindeutiger Damerau-Levenshtein-Abstand 1 ohne konkurrierenden Zweittreffer.
     *
     * @param list<string> $suggestions
     */
    private static function pickSafeSuggestion(string $word, string $lowerWord, array $suggestions): ?string
    {
        $top = $suggestions[0];
        if ($top === '' || str_contains($top, ' ')) {
            return null;
        }

        // Reine Großschreibung eines Substantivs ("drucker" -> "Drucker")
        if (mb_strtolower($top, 'UTF-8') === $lowerWord && $top === self::ucFirst($word)) {
            return $top;
        }

        if (mb_strlen($word, 'UTF-8') < 5) {
            return null;
        }

        $topLower = mb_strtolower($top, 'UTF-8');
        if (self::damerauLevenshtein($lowerWord, $topLower) !== 1) {
            return null;
        }

        // Kein zweiter Vorschlag darf ebenfalls Abstand <= 1 haben (Mehrdeutigkeit vermeiden)
        for ($i = 1, $count = min(count($suggestions), 6); $i < $count; $i++) {
            $otherLower = mb_strtolower($suggestions[$i], 'UTF-8');
            if ($otherLower === $topLower) {
                continue;
            }
            if (self::damerauLevenshtein($lowerWord, $otherLower) <= 1) {
                return null;
            }
        }

        return $top;
    }

    private function capitalizeSentenceStarts(string $line, ?SpellcheckService $engine, int &$replacements): string
    {
        // 1. Zeilenanfang (optional nach Aufzählungszeichen "- ", "* ", "• ")
        // Wörter mit Binnen-Großbuchstaben ("iPhone", "macOS", "vLLM") bleiben unangetastet.
        $line = (string) preg_replace_callback(
            '/^([ \t]*(?:[-*•][ \t]+)?)(\p{Ll}+(?:\-[\p{L}\p{M}]+)?)(?![\p{L}\p{M}])/u',
            function (array $m) use (&$replacements): string {
                $candidate = self::ucFirst($m[2]);
                $replacements++;
                return $m[1] . $candidate;
            },
            $line
        );

        // 2. Nach Satzende (. ! ?), auch nach Klammern, Anführungszeichen oder maskierten Platzhaltern,
        // sofern direkt vor dem Punkt keine gängige Abkürzung oder Ordnungszahl steht.
        $line = (string) preg_replace_callback(
            '/([\p{L}\p{N}]*)([.!?][ \t]+)(\p{Ll}+(?:\-[\p{L}\p{M}]+)?)(?![\p{L}\p{M}])/u',
            function (array $m) use (&$replacements): string {
                $prevToken = $m[1];
                $punct = $m[2];
                $word = $m[3];
                if (str_starts_with($punct, '.') && $prevToken !== '') {
                    if (ctype_digit($prevToken) || in_array(mb_strtolower($prevToken, 'UTF-8'), self::ABBREVIATIONS, true)) {
                        return $m[0];
                    }
                }
                $candidate = self::ucFirst($word);
                $replacements++;
                return $prevToken . $punct . $candidate;
            },
            $line
        );

        return $line;
    }

    /**
     * Maskiert schützenswerte Bereiche durch buchstabenfreie Platzhalter (\x00#0#\x00).
     *
     * @param list<string> $protected
     */
    private static function maskProtected(string $text, array &$protected): string
    {
        $pattern = '/'
            . '```[\s\S]*?```'
            . '|`[^`\n]+`'
            . '|\[Bild\s+\d+\]'
            . '|\{[a-zA-Z0-9_.]+\}'
            . '|(?:https?:\/\/|ftp:\/\/|mailto:|www\.)[^\s<>"\')]+(?<![.,;!?])'
            . '|[^\s<>"\'@()\[\]]+@[^\s<>"\'@()\[\]]+\.[^\s<>"\'@()\[\].,;!?]+'
            . '|(?:[A-Za-z]:\\\\|\\\\[A-Za-z0-9_.\-]+\\\\|\/(?:etc|var|usr|opt|home|tmp|srv|mnt)\/)[^\s<>"\']+'
            . '|(?<=^|[\s(])@[A-Za-z0-9_.\-]+'
            . '|\b[A-Z]{2,6}-\d{4}-\d+\b'
            . '/u';

        return (string) preg_replace_callback($pattern, static function (array $m) use (&$protected): string {
            $id = count($protected);
            $protected[] = $m[0];
            return "\x00#" . $id . "#\x00";
        }, $text);
    }

    /**
     * @param list<string> $protected
     */
    private static function unmaskProtected(string $text, array $protected): string
    {
        foreach ($protected as $id => $original) {
            $text = str_replace("\x00#" . $id . "#\x00", $original, $text);
        }

        return $text;
    }

    private static function startsUppercase(string $word): bool
    {
        $first = mb_substr($word, 0, 1, 'UTF-8');

        return $first !== '' && $first === mb_strtoupper($first, 'UTF-8') && $first !== mb_strtolower($first, 'UTF-8');
    }

    private static function ucFirst(string $word): string
    {
        $first = mb_substr($word, 0, 1, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }

    /** UTF-8-fähige Damerau-Levenshtein-Distanz (inkl. Buchstabendreher als Abstand 1). */
    private static function damerauLevenshtein(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $charsA = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $charsB = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lenA = count($charsA);
        $lenB = count($charsB);
        if (abs($lenA - $lenB) > 2) {
            return abs($lenA - $lenB);
        }

        $d = [];
        for ($i = 0; $i <= $lenA; $i++) {
            $d[$i] = array_fill(0, $lenB + 1, 0);
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lenB; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $lenA; $i++) {
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = $charsA[$i - 1] === $charsB[$j - 1] ? 0 : 1;
                $best = min(
                    $d[$i - 1][$j] + 1,
                    $d[$i][$j - 1] + 1,
                    $d[$i - 1][$j - 1] + $cost
                );
                if ($i > 1 && $j > 1 && $charsA[$i - 1] === $charsB[$j - 2] && $charsA[$i - 2] === $charsB[$j - 1]) {
                    $best = min($best, $d[$i - 2][$j - 2] + 1);
                }
                $d[$i][$j] = $best;
            }
        }

        return $d[$lenA][$lenB];
    }
}
