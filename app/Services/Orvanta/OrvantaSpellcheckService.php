<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

/**
 * Deutsche Rechtschreibpruefung fuer Orvanta.
 *
 * Die Pruefung folgt dem Verfahren von Hunspell (Stammwoerterbuch mit
 * Affixregeln und Wortzusammensetzungen) und wurde gegen die Referenz-
 * implementierung "spylls" abgeglichen. Unterstuetzt werden die Anweisungen,
 * die das deutsche Woerterbuch (igerman98/de_DE_frami) verwendet: SFX, PFX,
 * BREAK, REP, MAP, COMPOUNDMIN, CHECKSHARPS, FORBIDDENWORD, NEEDAFFIX,
 * NOSUGGEST, KEEPCASE, CIRCUMFIX, ONLYINCOMPOUND, COMPOUNDBEGIN,
 * COMPOUNDMIDDLE, COMPOUNDEND, COMPOUNDPERMITFLAG und COMPOUNDFORBIDFLAG.
 *
 * Alle Laengen- und Teilzeichenkettenoperationen arbeiten auf Zeichenebene
 * (mb_*), damit Umlaute und "ß" korrekt behandelt werden.
 */
final class OrvantaSpellcheckService
{
    /** Obergrenze fuer Woerter je Anfrage (Schutz vor zu grossen Anfragen). */
    public const MAX_WORDS_PER_REQUEST = 400;

    /** Laengere Woerter werden nicht geprueft (gelten als korrekt). */
    public const MAX_WORD_LENGTH = 64;

    /** Anzahl der Vorschlaege, die hoechstens zurueckgegeben werden. */
    public const MAX_SUGGESTIONS = 8;

    /** Position eines Wortteils innerhalb einer Zusammensetzung. */
    public const POS_BEGIN = 'begin';
    public const POS_MIDDLE = 'middle';
    public const POS_END = 'end';

    /** Laengste Woerter, fuer die Vorschlaege erzeugt werden. */
    private const SUGGEST_MAX_LENGTH = 32;

    /** Obergrenze fuer den zusaetzlichen Woerterbuchdurchlauf bei Vorschlaegen. */
    private const SUGGEST_SCAN_LIMIT = 600;

    /**
     * Obergrenze fuer die guenstige Affixform-Vorpruefung je Vorschlagsanfrage.
     */
    private const SUGGEST_FILTER_LIMIT = 160;

    /**
     * Obergrenze fuer die teuren Zusammensetzungspruefungen je Vorschlagsanfrage.
     */
    private const SUGGEST_COMPOUND_LIMIT = 40;

    /** Obergrenze fuer die Anzahl zwischengespeicherter Pruefungen. */
    private const MEMO_LIMIT = 20000;

    /** Obergrenze fuer die Anzahl zwischengespeicherter Vorschlagslisten. */
    private const SUGGEST_MEMO_LIMIT = 2000;

    /** Rekursionstiefe beim Zerlegen an Trennzeichen (wie in Hunspell). */
    private const BREAK_DEPTH = 10;

    /** Hoechste Anzahl Wortteile einer Zusammensetzung. */
    private const COMPOUND_DEPTH = 8;

    /** Groesste Editierdistanz fuer Vorschlaege aus dem Woerterbuchdurchlauf. */
    private const MAX_SCAN_DISTANCE = 2;

    /** @var array<string,bool> Zwischenspeicher fuer Pruefungen ("1"/"0" + Wort) */
    private array $memo = [];

    /** @var array<string,list<string>> Zwischenspeicher fuer Vorschlaege */
    private array $suggestMemo = [];

    /** @var array<string,string>|null Sonderflags aus der .aff-Datei */
    private ?array $flags = null;

    /** @var list<string>|null Bevorzugte Buchstabenfolge (TRY) */
    private ?array $tryLetters = null;

    /**
     * @param bool $enabled Abschalter aus der Konfiguration
     *                      (office.spellcheck_enabled); ist er aus, verhaelt
     *                      sich die Pruefung wie ohne Woerterbuch.
     */
    public function __construct(
        private readonly OrvantaSpellcheckDictionary $dictionary,
        private readonly bool $enabled = true
    ) {
    }

    /**
     * Ist die Rechtschreibpruefung einsatzbereit (aktiv und Woerterbuch
     * uebersetzt)?
     */
    public function isAvailable(): bool
    {
        return $this->enabled && $this->dictionary->isAvailable();
    }

    /**
     * Prueft ein einzelnes Wort. Fehlt das Woerterbuch, gilt jedes Wort als
     * korrekt, damit die Oberflaeche nichts faelschlich markiert.
     */
    public function check(string $word): bool
    {
        if ($word === '' || mb_strlen($word, 'UTF-8') > self::MAX_WORD_LENGTH) {
            return true;
        }
        if (!$this->isAvailable()) {
            return true;
        }

        return $this->isCorrect($word, true);
    }

    /**
     * Verbesserungsvorschlaege fuer ein falsch geschriebenes Wort.
     *
     * @return list<string> Leere Liste, wenn das Wort korrekt oder zu lang ist
     */
    public function suggest(string $word): array
    {
        if ($word === '' || !$this->isAvailable()) {
            return [];
        }
        if (array_key_exists($word, $this->suggestMemo)) {
            return $this->suggestMemo[$word];
        }
        if (count($this->suggestMemo) >= self::SUGGEST_MEMO_LIMIT) {
            $this->suggestMemo = [];
        }
        $length = mb_strlen($word, 'UTF-8');
        if ($length > self::SUGGEST_MAX_LENGTH || $this->isCorrect($word, true)) {
            return $this->suggestMemo[$word] = [];
        }

        $candidates = $this->collectCandidates($word);
        uasort($candidates, static fn (array $a, array $b): int => $a <=> $b);

        // Stufe 1: Kandidaten, die direkt als Stamm im Woerterbuch stehen, sind
        // die wahrscheinlichsten Treffer. Der Stammnachweis kostet nur einen
        // Bruchteil einer vollstaendigen Pruefung, deshalb werden zuerst alle
        // Kandidaten danach gefiltert und nur die Treffer geprueft.
        $suggestions = [];
        $remaining = [];
        foreach ($candidates as $candidate => $rank) {
            if ($this->dictionary->flagsOf($candidate) === null) {
                $remaining[] = $candidate;

                continue;
            }
            if ($this->isCorrect($candidate, false)) {
                $suggestions[$candidate] = $rank;
            }
        }

        // Stufe 2: gueltige Affixformen sind teurer, die Vorpruefung lohnt sich
        // aber gegenueber einer vollstaendigen Pruefung. Nur die ersten
        // Kandidaten der Rangfolge werden dafuer betrachtet.
        $deferred = [];
        $filtered = 0;
        foreach ($remaining as $candidate) {
            if (count($suggestions) >= self::MAX_SUGGESTIONS || $filtered >= self::SUGGEST_FILTER_LIMIT) {
                $deferred[] = $candidate;

                continue;
            }
            $filtered++;
            if ($this->hasAnyAffixForm($candidate, OrvantaSpellcheckCasing::guess($candidate))
                && $this->isCorrect($candidate, false)) {
                $suggestions[$candidate] = $candidates[$candidate];

                continue;
            }
            $deferred[] = $candidate;
        }

        // Stufe 3: Zusammensetzungen und Sonderfaelle sind am teuersten und
        // werden nur so weit geprueft, wie es die Rangfolge erlaubt.
        $checked = 0;
        foreach ($deferred as $candidate) {
            if (count($suggestions) >= self::MAX_SUGGESTIONS || $checked >= self::SUGGEST_COMPOUND_LIMIT) {
                break;
            }
            $checked++;
            if ($this->isCorrect($candidate, false)) {
                $suggestions[$candidate] = $candidates[$candidate];
            }
        }

        uasort($suggestions, static fn (array $a, array $b): int => $a <=> $b);
        $result = array_slice(array_keys($suggestions), 0, self::MAX_SUGGESTIONS);

        return $this->suggestMemo[$word] = $result;
    }

    /**
     * Sammelt Vorschlagskandidaten mit ihrer Rangfolge.
     *
     * Der Rang ist ein Feld aus Klasse, Editierdistanz, Buchstabenposition in
     * TRY und Laengendifferenz; kleine Werte sind besser. Die Klassen sind
     * 0 (Gross-/Kleinschreibung, REP, MAP), 1-6 (Tippfehler in der Reihenfolge
     * von Hunspell) und 7 (Woerterbuchdurchlauf).
     *
     * @return array<string,array{0:int,1:int,2:int,3:int}>
     */
    private function collectCandidates(string $word): array
    {
        $candidates = [];
        $add = static function (string $candidate, int $class, int $distance, int $tryIndex, int $lengthDiff) use (&$candidates): void {
            if ($candidate === '' || isset($candidates[$candidate])) {
                return;
            }
            $candidates[$candidate] = [$class, $distance, $tryIndex, $lengthDiff];
        };

        $length = mb_strlen($word, 'UTF-8');
        foreach (OrvantaSpellcheckCasing::variants($word) as $variant) {
            $add($variant, 0, 1, 0, 0);
        }
        foreach ($this->replacementCandidates($word) as $candidate) {
            $add($candidate, 0, 1, 0, 0);
        }
        foreach ($this->mapCandidates($word) as $candidate) {
            $add($candidate, 0, 2, 0, 0);
        }
        $letters = $this->tryLetterList();
        foreach ($this->editCandidates($word, $letters) as $candidate) {
            $add($candidate[0], $candidate[1], $candidate[2], $candidate[3], $candidate[4]);
        }
        if ($length >= 5) {
            $prefix = mb_substr($word, 0, 3, 'UTF-8');
            foreach ($this->dictionary->wordsWithPrefix($prefix, self::SUGGEST_SCAN_LIMIT) as $entry) {
                if (isset($candidates[$entry['word']])) {
                    continue;
                }
                $distance = $this->distance($word, $entry['word'], self::MAX_SCAN_DISTANCE);
                if ($distance <= self::MAX_SCAN_DISTANCE) {
                    $add($entry['word'], 7, $distance, 0, abs(mb_strlen($entry['word'], 'UTF-8') - $length));
                }
            }
        }

        unset($candidates[$word]);

        return $candidates;
    }

    /**
     * Kandidaten aus der REP-Tabelle (haeufige Verwechslungen, etwa "ss"/"ß").
     *
     * @return list<string>
     */
    private function replacementCandidates(string $word): array
    {
        $result = [];
        foreach ($this->dictionary->replacements() as $rule) {
            $from = $rule['from'] ?? '';
            $to = $rule['to'] ?? '';
            if ($from === '' || $from === $to) {
                continue;
            }
            $offset = 0;
            while (($position = mb_strpos($word, $from, $offset, 'UTF-8')) !== false) {
                $result[] = mb_substr($word, 0, $position, 'UTF-8') . $to
                    . mb_substr($word, $position + mb_strlen($from, 'UTF-8'), null, 'UTF-8');
                $offset = $position + mb_strlen($from, 'UTF-8');
            }
            if ($offset > 0) {
                $result[] = str_replace($from, $to, $word);
            }
        }

        return $result;
    }

    /**
     * Kandidaten aus den MAP-Gruppen (aehnliche Zeichen, etwa "ue"/"ü").
     *
     * @return list<string>
     */
    private function mapCandidates(string $word): array
    {
        $result = [];
        foreach ($this->dictionary->maps() as $group) {
            foreach ($group as $member) {
                if ($member === '') {
                    continue;
                }
                $offset = 0;
                while (($position = mb_strpos($word, $member, $offset, 'UTF-8')) !== false) {
                    $rest = mb_substr($word, $position + mb_strlen($member, 'UTF-8'), null, 'UTF-8');
                    $head = mb_substr($word, 0, $position, 'UTF-8');
                    foreach ($group as $other) {
                        if ($other !== $member && $other !== '') {
                            $result[] = $head . $other . $rest;
                        }
                    }
                    $offset = $position + mb_strlen($member, 'UTF-8');
                }
            }
        }

        return $result;
    }

    /**
     * Kandidaten mit einem Tippfehler, in der Reihenfolge, in der Hunspell sie
     * prueft: vertauschte Nachbarn, weite Vertauschungen, zu viel eingegebene
     * Buchstaben, fehlende Buchstaben, verschobene und falsche Buchstaben.
     *
     * @param list<string> $letters
     *
     * @return list<array{0:string,1:int,2:int,3:int,4:int}> Wort, Klasse, Abstand, Position, Laengendifferenz
     */
    private function editCandidates(string $word, array $letters): array
    {
        $chars = mb_str_split($word, 1, 'UTF-8');
        $count = count($chars);
        $letterCount = count($letters);
        $result = [];

        for ($i = 0; $i + 1 < $count; $i++) {
            $copy = $chars;
            $swap = $copy[$i];
            $copy[$i] = $copy[$i + 1];
            $copy[$i + 1] = $swap;
            $result[] = [implode('', $copy), 1, 1, $i, 0];
        }
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 2; $j < $count; $j++) {
                $copy = $chars;
                $swap = $copy[$i];
                $copy[$i] = $copy[$j];
                $copy[$j] = $swap;
                $result[] = [implode('', $copy), 2, 2, $i * $count + $j, 0];
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                implode('', array_merge(array_slice($chars, 0, $i), array_slice($chars, $i + 1))),
                3,
                1,
                $i,
                -1,
            ];
        }
        for ($letter = 0; $letter < $letterCount; $letter++) {
            for ($i = 0; $i <= $count; $i++) {
                $copy = $chars;
                array_splice($copy, $i, 0, [$letters[$letter]]);
                $result[] = [implode('', $copy), 4, 1, $letter * ($count + 1) + $i, 1];
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $copy = $chars;
            $moved = array_splice($copy, $i, 1);
            for ($j = 0; $j < $count; $j++) {
                if ($j === $i) {
                    continue;
                }
                $target = $copy;
                array_splice($target, $j, 0, $moved);
                $result[] = [implode('', $target), 5, 2, $i * $count + $j, 0];
            }
        }
        for ($letter = 0; $letter < $letterCount; $letter++) {
            for ($i = 0; $i < $count; $i++) {
                if ($chars[$i] === $letters[$letter]) {
                    continue;
                }
                $copy = $chars;
                $copy[$i] = $letters[$letter];
                $result[] = [implode('', $copy), 6, 1, $letter * $count + $i, 0];
            }
        }

        return $result;
    }

    /**
     * Abstand zweier Woerter (Damerau-Levenshtein, ohne Vertauschungsketten).
     * Liefert $max + 1, sobald der Abstand groesser als $max ist.
     */
    private function distance(string $left, string $right, int $max): int
    {
        if ($left === $right) {
            return 0;
        }
        $a = mb_str_split($left, 1, 'UTF-8');
        $b = mb_str_split($right, 1, 'UTF-8');
        $n = count($a);
        $m = count($b);
        if (abs($n - $m) > $max) {
            return $max + 1;
        }
        $beforePrevious = null;
        $previous = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            $best = $i;
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $value = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
                if ($beforePrevious !== null && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $value = min($value, $beforePrevious[$j - 2] + 1);
                }
                $current[$j] = $value;
                if ($value < $best) {
                    $best = $value;
                }
            }
            if ($best > $max) {
                return $max + 1;
            }
            $beforePrevious = $previous;
            $previous = $current;
        }

        return $previous[$m] > $max ? $max + 1 : $previous[$m];
    }

    /**
     * Ist das Wort korrekt geschrieben?
     */
    private function isCorrect(string $word, bool $allowNosuggest): bool
    {
        if ($word === '') {
            return false;
        }
        $key = ($allowNosuggest ? '1' : '0') . $word;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        if (count($this->memo) >= self::MEMO_LIMIT) {
            $this->memo = [];
        }

        return $this->memo[$key] = $this->computeCorrect($word, $allowNosuggest);
    }

    /**
     * Vollstaendige Pruefung eines Wortes (ohne Zwischenspeicher).
     */
    private function computeCorrect(string $word, bool $allowNosuggest): bool
    {
        $forbidden = $this->flags()['forbidden'];
        if ($forbidden !== '' && $this->hasFlag($word, $forbidden, true)) {
            return false;
        }
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $word) === 1) {
            return true;
        }
        foreach ($this->breakWord($word) as $parts) {
            $correct = true;
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                if (!$this->anyGoodForm($part, true, $allowNosuggest)) {
                    $correct = false;
                    break;
                }
            }
            if ($correct) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zerlegt ein Wort an den Trennzeichen (BREAK) in alle moeglichen Varianten.
     * Das ganze Wort steht immer an erster Stelle.
     *
     * @return list<list<string>>
     */
    private function breakWord(string $text, int $depth = 0): array
    {
        $result = [[$text]];
        if ($depth >= self::BREAK_DEPTH) {
            return $result;
        }
        $length = mb_strlen($text, 'UTF-8');
        foreach ($this->dictionary->breaks() as $break) {
            $breakLength = mb_strlen($break, 'UTF-8');
            $offset = 0;
            while (($position = mb_strpos($text, $break, $offset, 'UTF-8')) !== false) {
                $offset = $position + $breakLength;
                // Wie in Hunspell muss vor und nach dem Trennzeichen ein Zeichen stehen.
                if ($position === 0 || $position + $breakLength >= $length) {
                    continue;
                }
                $head = mb_substr($text, 0, $position, 'UTF-8');
                $rest = mb_substr($text, $position + $breakLength, null, 'UTF-8');
                foreach ($this->breakWord($rest, $depth + 1) as $breaking) {
                    $result[] = array_merge([$head], $breaking);
                }
            }
        }

        return $result;
    }

    /**
     * Gibt es zu einer der Schreibweisen des Wortes eine gueltige Form?
     */
    private function anyGoodForm(string $word, bool $capitalization, bool $allowNosuggest): bool
    {
        if ($capitalization) {
            $captype = OrvantaSpellcheckCasing::guess($word);
            $variants = OrvantaSpellcheckCasing::variants($word);
        } else {
            $captype = OrvantaSpellcheckCasing::guess($word);
            $variants = [$word];
        }
        foreach ($variants as $variant) {
            foreach ($this->goodForms($variant, $captype, $allowNosuggest) as $ignored) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alle gueltigen Formen einer Schreibweise (Affixe und Zusammensetzungen).
     *
     * @return \Generator<array<string,mixed>>
     */
    private function goodForms(string $variant, int $captype, bool $allowNosuggest): \Generator
    {
        $checkSharps = $this->dictionary->checkSharps();
        $keepcase = $this->flags()['keepcase'];
        foreach ($this->affixForms($variant, $captype, $allowNosuggest) as $form) {
            if ($checkSharps && $keepcase !== ''
                && str_contains((string) $form['entry_word'], 'ß')
                && str_contains($this->formFlags($form), $keepcase)
                && $captype === OrvantaSpellcheckCasing::ALL
                && str_contains($variant, 'ß')) {
                continue;
            }
            yield $form;
        }
        yield from $this->compoundForms($variant, $captype, $allowNosuggest);
    }

    /**
     * Alle gueltigen Formen aus Stamm + Affixen.
     *
     * @param list<string> $prefixFlags Flags, die ein Prefix tragen muss
     * @param list<string> $suffixFlags Flags, die ein Suffix tragen muss
     * @param list<string> $forbiddenFlags Flags, die Affixe nicht tragen duerfen
     *
     * @return \Generator<array<string,mixed>>
     */
    private function affixForms(
        string $word,
        int $captype,
        bool $allowNosuggest,
        string $compoundPos = '',
        array $prefixFlags = [],
        array $suffixFlags = [],
        array $forbiddenFlags = [],
        bool $withForbidden = false
    ): \Generator {
        $forbidden = $this->flags()['forbidden'];
        foreach ($this->produceAffixForms($word, $prefixFlags, $suffixFlags, $forbiddenFlags, $compoundPos) as $form) {
            $homonyms = $this->dictionary->flagsOf($form['stem']) ?? [];
            // Ein gesperrter Stamm (FORBIDDENWORD) verhindert Affixe und
            // Zusammensetzungen vollstaendig, erlaubt aber das Wort selbst.
            if (!$withForbidden && $forbidden !== '' && ($compoundPos !== '' || $this->hasAffixes($form))) {
                foreach ($homonyms as $entryFlags) {
                    if (str_contains($entryFlags, $forbidden)) {
                        return;
                    }
                }
            }
            $found = false;
            foreach ($homonyms as $entryFlags) {
                $candidate = $form;
                $candidate['entry_word'] = $form['stem'];
                $candidate['entry_flags'] = $entryFlags;
                $candidate['entry_captype'] = OrvantaSpellcheckCasing::guess($form['stem']);
                if ($this->isGoodForm($candidate, $captype, $compoundPos, $allowNosuggest)) {
                    $found = true;
                    yield $candidate;
                }
            }
            // Fuer durchgaengig gross geschriebene Woerter zusaetzlich ohne
            // Ruecksicht auf die Schreibung im Woerterbuch suchen.
            if ($found || $compoundPos !== '' || $captype !== OrvantaSpellcheckCasing::ALL
                || OrvantaSpellcheckCasing::guess($word) !== OrvantaSpellcheckCasing::NO) {
                continue;
            }
            foreach ($this->dictionary->caseEntries($form['stem']) as $entry) {
                $candidate = $form;
                $candidate['entry_word'] = $entry['word'];
                $candidate['entry_flags'] = $entry['flags'];
                $candidate['entry_captype'] = OrvantaSpellcheckCasing::guess($entry['word']);
                if ($this->isGoodForm($candidate, $captype, $compoundPos, $allowNosuggest)) {
                    yield $candidate;
                }
            }
        }
    }

    /**
     * Zerlegt ein Wort in Stamm, Prefixe und Suffixe (ohne Pruefung der Flags).
     *
     * @param list<string> $prefixFlags
     * @param list<string> $suffixFlags
     * @param list<string> $forbiddenFlags
     *
     * @return \Generator<array<string,mixed>>
     */
    private function produceAffixForms(
        string $word,
        array $prefixFlags,
        array $suffixFlags,
        array $forbiddenFlags,
        string $compoundPos
    ): \Generator {
        yield ['text' => $word, 'stem' => $word, 'prefix' => null, 'suffix' => null, 'prefix2' => null, 'suffix2' => null];

        $suffixAllowed = $compoundPos === '' || $compoundPos === self::POS_END || $suffixFlags !== [];
        $prefixAllowed = $compoundPos === '' || $compoundPos === self::POS_BEGIN || $prefixFlags !== [];

        if ($suffixAllowed) {
            yield from $this->desuffix($word, $suffixFlags, $forbiddenFlags);
        }
        if ($prefixAllowed) {
            foreach ($this->deprefix($word, $prefixFlags, $forbiddenFlags) as $form) {
                yield $form;
                if ($suffixAllowed && $form['prefix'] !== null && $form['prefix']['cross'] === true) {
                    foreach ($this->desuffix($form['stem'], $suffixFlags, $forbiddenFlags, false, true) as $form2) {
                        $form2['text'] = $form['text'];
                        $form2['prefix'] = $form['prefix'];
                        yield $form2;
                    }
                }
            }
        }
    }

    /**
     * Zerlegt ein Wort in Stamm und Suffix(e) (hoechstens zwei).
     *
     * @param list<string> $requiredFlags
     * @param list<string> $forbiddenFlags
     *
     * @return \Generator<array<string,mixed>>
     */
    private function desuffix(
        string $word,
        array $requiredFlags,
        array $forbiddenFlags,
        bool $nested = false,
        bool $crossproduct = false
    ): \Generator {
        $wordLength = mb_strlen($word, 'UTF-8');
        foreach ($this->dictionary->suffixLengths() as $length) {
            if ($length > $wordLength) {
                continue;
            }
            $add = $length === 0 ? '' : mb_substr($word, $wordLength - $length, null, 'UTF-8');
            foreach ($this->dictionary->suffixRules($add) as $rule) {
                if (!$this->isUsableAffix($rule, $requiredFlags, $forbiddenFlags, $crossproduct)) {
                    continue;
                }
                $stem = $add === '' ? $word : mb_substr($word, 0, $wordLength - $length, 'UTF-8');
                $stem .= $rule['strip'];
                $condition = (string) $rule['cond'];
                if ($condition !== '' && preg_match($condition, $stem) !== 1) {
                    continue;
                }
                yield ['text' => $word, 'stem' => $stem, 'prefix' => null, 'suffix' => $rule, 'prefix2' => null, 'suffix2' => null];
                if ($nested) {
                    continue;
                }
                foreach ($this->desuffix($stem, array_merge([(string) $rule['flag']], $requiredFlags), $forbiddenFlags, true, $crossproduct) as $form2) {
                    $form2['text'] = $word;
                    $form2['suffix2'] = $rule;
                    yield $form2;
                }
            }
        }
    }

    /**
     * Zerlegt ein Wort in Prefix und Stamm.
     *
     * @param list<string> $requiredFlags
     * @param list<string> $forbiddenFlags
     *
     * @return \Generator<array<string,mixed>>
     */
    private function deprefix(string $word, array $requiredFlags, array $forbiddenFlags): \Generator
    {
        $wordLength = mb_strlen($word, 'UTF-8');
        foreach ($this->dictionary->prefixLengths() as $length) {
            if ($length > $wordLength) {
                continue;
            }
            $add = $length === 0 ? '' : mb_substr($word, 0, $length, 'UTF-8');
            foreach ($this->dictionary->prefixRules($add) as $rule) {
                if (!$this->isUsableAffix($rule, $requiredFlags, $forbiddenFlags, false)) {
                    continue;
                }
                $stem = $rule['strip'] . mb_substr($word, $length, null, 'UTF-8');
                $condition = (string) $rule['cond'];
                if ($condition !== '' && preg_match($condition, $stem) !== 1) {
                    continue;
                }
                yield ['text' => $word, 'stem' => $stem, 'prefix' => $rule, 'suffix' => null, 'prefix2' => null, 'suffix2' => null];
            }
        }
    }

    /**
     * Erfuellt eine Affixregel die geforderten und verbotenen Flags?
     *
     * @param array<string,mixed> $rule
     * @param list<string> $requiredFlags
     * @param list<string> $forbiddenFlags
     */
    private function isUsableAffix(array $rule, array $requiredFlags, array $forbiddenFlags, bool $crossproduct): bool
    {
        if ($crossproduct && $rule['cross'] !== true) {
            return false;
        }
        // Fortsetzungsflags der Regel (nur der Teil nach "/"), nicht das
        // eigene Flag der Regel - siehe spylls data/aff.py: Affix.flags.
        $flags = (string) $rule['cont'];
        foreach ($requiredFlags as $flag) {
            if ($flag !== '' && !str_contains($flags, $flag)) {
                return false;
            }
        }
        foreach ($forbiddenFlags as $flag) {
            if ($flag !== '' && str_contains($flags, $flag)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Alle gueltigen Zusammensetzungen eines Wortes.
     *
     * @return \Generator<list<array<string,mixed>>>
     */
    private function compoundForms(string $word, int $captype, bool $allowNosuggest): \Generator
    {
        $forbidden = $this->flags()['forbidden'];
        if ($forbidden !== '') {
            foreach ($this->affixForms($word, $captype, $allowNosuggest, '', [], [], [], true) as $form) {
                if (str_contains($this->formFlags($form), $forbidden)) {
                    return;
                }
            }
        }
        if ($this->flags()['compound_begin'] === '' && $this->flags()['compound'] === '') {
            return;
        }
        foreach ($this->compoundsByFlags($word, $captype, 0, $allowNosuggest) as $parts) {
            if (!$this->isBadCompound($parts, $captype)) {
                yield $parts;
            }
        }
    }

    /**
     * Zerlegt ein Wort in alle moeglichen Folgen von Wortteilen.
     *
     * @return \Generator<list<array<string,mixed>>>
     */
    private function compoundsByFlags(string $wordRest, int $captype, int $depth, bool $allowNosuggest): \Generator
    {
        $permit = $this->flags()['compound_permit'];
        $forbid = $this->flags()['compound_forbid'];
        $permitFlags = $permit === '' ? [] : [$permit];
        $forbiddenFlags = $forbid === '' ? [] : [$forbid];

        if ($depth > 0) {
            foreach ($this->affixForms($wordRest, $captype, $allowNosuggest, self::POS_END, $permitFlags, [], $forbiddenFlags) as $form) {
                yield [$form];
            }
        }
        if ($depth >= self::COMPOUND_DEPTH) {
            return;
        }
        $length = mb_strlen($wordRest, 'UTF-8');
        $min = $this->dictionary->compoundMin();
        $max = $this->dictionary->compoundMax();
        if ($length < $min * 2 || ($max > 0 && $depth >= $max)) {
            return;
        }
        $position = $depth === 0 ? self::POS_BEGIN : self::POS_MIDDLE;
        $prefixFlags = $position === self::POS_BEGIN ? [] : $permitFlags;
        for ($split = $min; $split <= $length - $min; $split++) {
            $head = mb_substr($wordRest, 0, $split, 'UTF-8');
            $rest = mb_substr($wordRest, $split, null, 'UTF-8');
            foreach ($this->affixForms($head, $captype, $allowNosuggest, $position, $prefixFlags, $permitFlags, $forbiddenFlags) as $form) {
                foreach ($this->compoundsByFlags($rest, $captype, $depth + 1, $allowNosuggest) as $partial) {
                    yield array_merge([$form], $partial);
                }
            }
        }
    }

    /**
     * Ist die Zusammensetzung trotz gueltiger Teile unzulaessig?
     *
     * @param list<array<string,mixed>> $parts
     */
    private function isBadCompound(array $parts, int $captype): bool
    {
        $forbid = $this->flags()['compound_forbid'];
        $count = count($parts);
        for ($index = 0; $index + 1 < $count; $index++) {
            $left = (string) $parts[$index]['text'];
            $right = (string) $parts[$index + 1]['text'];
            if ($forbid !== '' && $this->hasFlag($left, $forbid, false)) {
                return true;
            }
            // Steht "linker Teil rechter Teil" als eigener Eintrag im
            // Woerterbuch, ist die Zusammensetzung nicht zulaessig.
            if ($this->hasAnyAffixForm($left . ' ' . $right, $captype)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gibt es zu diesem Wort eine gueltige Affixform?
     */
    private function hasAnyAffixForm(string $word, int $captype): bool
    {
        foreach ($this->affixForms($word, $captype, true) as $ignored) {
            return true;
        }

        return false;
    }

    /**
     * Erfuellt die Form alle Bedingungen von Hunspell?
     *
     * @param array<string,mixed> $form
     */
    private function isGoodForm(array $form, int $captype, string $compoundPos, bool $allowNosuggest): bool
    {
        $rootFlags = (string) $form['entry_flags'];
        $allFlags = $this->formFlags($form);
        $flags = $this->flags();

        if (!$allowNosuggest && $flags['nosuggest'] !== '' && str_contains($rootFlags, $flags['nosuggest'])) {
            return false;
        }
        if ($captype !== (int) $form['entry_captype'] && $flags['keepcase'] !== '' && str_contains($rootFlags, $flags['keepcase'])) {
            if (!($this->dictionary->checkSharps() && str_contains((string) $form['entry_word'], 'ß'))) {
                return false;
            }
        }
        if ($flags['need_affix'] !== '') {
            if (str_contains($rootFlags, $flags['need_affix']) && !$this->hasAffixes($form)) {
                return false;
            }
            if ($this->hasAffixes($form)) {
                $all = true;
                foreach ($this->allAffixes($form) as $affix) {
                    if (!str_contains((string) $affix['cont'], $flags['need_affix'])) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    return false;
                }
            }
        }
        if ($form['prefix'] !== null && !str_contains($allFlags, (string) $form['prefix']['flag'])) {
            return false;
        }
        if ($form['suffix'] !== null && !str_contains($allFlags, (string) $form['suffix']['flag'])) {
            return false;
        }
        if ($flags['circumfix'] !== '') {
            $suffixHas = $form['suffix'] !== null && str_contains((string) $form['suffix']['cont'], $flags['circumfix']);
            $prefixHas = $form['prefix'] !== null && str_contains((string) $form['prefix']['cont'], $flags['circumfix']);
            if ($prefixHas !== $suffixHas) {
                return false;
            }
        }
        if ($compoundPos === '') {
            return $flags['only_in_compound'] === '' || !str_contains($allFlags, $flags['only_in_compound']);
        }
        if ($flags['compound'] !== '' && str_contains($allFlags, $flags['compound'])) {
            return true;
        }
        $required = match ($compoundPos) {
            self::POS_BEGIN => $flags['compound_begin'],
            self::POS_MIDDLE => $flags['compound_middle'],
            self::POS_END => $flags['compound_end'],
            default => '',
        };

        return $required !== '' && str_contains($allFlags, $required);
    }

    /**
     * Alle Flags einer Form (Stamm, Prefix, Suffix).
     *
     * @param array<string,mixed> $form
     */
    private function formFlags(array $form): string
    {
        $flags = (string) $form['entry_flags'];
        if ($form['prefix'] !== null) {
            $flags .= (string) $form['prefix']['cont'];
        }
        if ($form['suffix'] !== null) {
            $flags .= (string) $form['suffix']['cont'];
        }

        return $flags;
    }

    /**
     * @param array<string,mixed> $form
     */
    private function hasAffixes(array $form): bool
    {
        return $form['prefix'] !== null || $form['suffix'] !== null;
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return list<array<string,mixed>>
     */
    private function allAffixes(array $form): array
    {
        $affixes = [];
        foreach (['prefix2', 'prefix', 'suffix', 'suffix2'] as $key) {
            if ($form[$key] !== null) {
                $affixes[] = $form[$key];
            }
        }

        return $affixes;
    }

    /**
     * Traegt der Stamm das Flag? Bei $forAll muessen es alle Homonyme tragen.
     */
    private function hasFlag(string $word, string $flag, bool $forAll): bool
    {
        $homonyms = $this->dictionary->flagsOf($word);
        if ($homonyms === null || $homonyms === []) {
            return false;
        }
        foreach ($homonyms as $flags) {
            $has = str_contains($flags, $flag);
            if ($forAll && !$has) {
                return false;
            }
            if (!$forAll && $has) {
                return true;
            }
        }

        return $forAll;
    }

    /**
     * Sonderflags aus der .aff-Datei (einmalig gelesen).
     *
     * @return array<string,string>
     */
    private function flags(): array
    {
        if ($this->flags === null) {
            $this->flags = [
                'forbidden' => $this->dictionary->flag('forbidden'),
                'need_affix' => $this->dictionary->flag('need_affix'),
                'nosuggest' => $this->dictionary->flag('nosuggest'),
                'keepcase' => $this->dictionary->flag('keepcase'),
                'circumfix' => $this->dictionary->flag('circumfix'),
                'only_in_compound' => $this->dictionary->flag('only_in_compound'),
                'compound' => $this->dictionary->flag('compound'),
                'compound_begin' => $this->dictionary->flag('compound_begin'),
                'compound_middle' => $this->dictionary->flag('compound_middle'),
                'compound_end' => $this->dictionary->flag('compound_end'),
                'compound_permit' => $this->dictionary->flag('compound_permit'),
                'compound_forbid' => $this->dictionary->flag('compound_forbid'),
            ];
        }

        return $this->flags;
    }

    /**
     * Buchstaben der TRY-Anweisung als Liste (Reihenfolge = Haeufigkeit).
     *
     * @return list<string>
     */
    private function tryLetterList(): array
    {
        if ($this->tryLetters === null) {
            $try = $this->dictionary->tryLetters();
            $letters = $try === '' ? [] : mb_str_split($try, 1, 'UTF-8');
            $this->tryLetters = array_values(array_filter($letters, static fn (string $letter): bool => $letter !== '' && $letter !== '-' && $letter !== '.'));
        }

        return $this->tryLetters;
    }
}
