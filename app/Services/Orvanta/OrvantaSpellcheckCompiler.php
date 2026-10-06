<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use RuntimeException;

/**
 * Uebersetzt ein Hunspell-Woerterbuch (hier: de_DE_frami aus LibreOffice,
 * Grundlage igerman98) in die Datenstruktur der Orvanta-Rechtschreibpruefung.
 *
 * Der Lauf erfolgt einmalig beim Containerstart (siehe
 * scripts/spellcheck_dictionary.php und docker/php/entrypoint.sh). Das
 * Ergebnis sind vier Dateien im Zielverzeichnis:
 *
 *  - aff.ser      Regeln und Sonderflags (serialisiert, siehe OrvantaSpellcheckDictionary)
 *  - words.dat    Woerterbuchzeilen "Wort\tFlags" (Flags = Homonyme, \n-getrennt)
 *  - words.idx    32-Bit-Offsets in words.dat, aufsteigend nach Wort sortiert
 *  - meta.json    Formatversion und Kennzahlen (wird zuletzt geschrieben)
 *
 * Die Aufteilung erlaubt ein Laden zur Laufzeit ohne Auswertung der grossen
 * Quelldatei: der Index wird binaer durchsucht, die Wortliste nur bei Bedarf
 * gelesen. Der Parser folgt der Referenzimplementierung spylls (readers/aff.py,
 * readers/dic.py), damit sich die Pruefung genauso verhaelt wie LibreOffice.
 */
final class OrvantaSpellcheckCompiler
{
    /** Erhoehen, sobald sich das Ausgabeformat aendert. */
    public const FORMAT_VERSION = 1;

    public const AFF_FILE = 'aff.ser';
    public const WORDS_DATA_FILE = 'words.dat';
    public const WORDS_INDEX_FILE = 'words.idx';

    /**
     * Zusaetzlicher Index fuer die Suche ohne Ruecksicht auf Gross-/Kleinschreibung.
     *
     * Er enthaelt nur Woerter mit gemischter oder durchgaengiger Grossschreibung
     * (etwa "ACLs", "STRASSE"); Woerter mit kleinem Anfangsbuchstaben sind auch
     * ohne ihn erreichbar.
     */
    public const WORDS_CASE_FILE = 'words.case';

    public const META_FILE = 'meta.json';
    public const NOTICE_FILE = 'QUELLE.txt';

    /**
     * Uebersetzt Woerterbuchdateien in das Zielverzeichnis.
     *
     * @param string $affPath Pfad zur .aff-Datei
     * @param string $dicPath Pfad zur .dic-Datei
     * @param string $targetDir Zielverzeichnis (wird angelegt)
     *
     * @return array<string,int> Kennzahlen fuer die Protokollausgabe
     *
     * @throws RuntimeException wenn eine Quelldatei fehlt oder unlesbar ist
     */
    public function compile(string $affPath, string $dicPath, string $targetDir): array
    {
        $affRaw = $this->readFile($affPath);
        $charset = $this->detectCharset($affRaw);
        $aff = $this->parseAff($this->toUtf8($affRaw, $charset));
        $words = $this->parseDic($this->toUtf8($this->readFile($dicPath), $charset));

        ksort($words, SORT_STRING);

        $data = '';
        $index = '';
        $offsets = [];
        $caseLines = [];
        foreach ($words as $word => $flagLists) {
            $offsets[] = strlen($data);
            $data .= $word . "\t" . implode("\n", $flagLists) . "\n";
            $captype = OrvantaSpellcheckCasing::guess($word);
            if ($captype === OrvantaSpellcheckCasing::NO || $captype === OrvantaSpellcheckCasing::INIT) {
                continue;
            }
            $lower = OrvantaSpellcheckCasing::lower($word);
            if ($lower === []) {
                continue;
            }
            foreach ($flagLists as $flags) {
                $caseLines[] = $lower[0] . "\t" . $word . "\t" . $flags;
            }
        }
        foreach (array_chunk($offsets, 512) as $chunk) {
            $index .= pack('V*', ...$chunk);
        }
        sort($caseLines, SORT_STRING);
        $case = $caseLines === [] ? '' : implode("\n", $caseLines) . "\n";

        $this->ensureDirectory($targetDir);
        // meta.json wird zuletzt geschrieben: sein Vorhandensein zeigt einen
        // vollstaendigen Datensatz an.
        $this->writeFile($targetDir . '/' . self::AFF_FILE, serialize($aff));
        $this->writeFile($targetDir . '/' . self::WORDS_DATA_FILE, $data);
        $this->writeFile($targetDir . '/' . self::WORDS_INDEX_FILE, $index);
        $this->writeFile($targetDir . '/' . self::WORDS_CASE_FILE, $case);
        $this->writeFile($targetDir . '/' . self::NOTICE_FILE, $this->notice($affPath, $dicPath, $charset));
        $this->writeFile($targetDir . '/' . self::META_FILE, json_encode([
            'version' => self::FORMAT_VERSION,
            'generated' => date('c'),
            'source' => basename($affPath) . ' / ' . basename($dicPath),
            'charset' => $charset,
            'words' => count($words),
            'entries' => array_sum(array_map('count', $words)),
            'case' => count($caseLines),
            'suffixes' => count($aff['suffixes']),
            'prefixes' => count($aff['prefixes']),
            'breaks' => count($aff['breaks']),
            'rep' => count($aff['rep']),
            'map' => count($aff['map']),
            'bytes' => strlen($data),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        return [
            'words' => count($words),
            'case' => count($caseLines),
            'suffixes' => count($aff['suffixes']),
            'prefixes' => count($aff['prefixes']),
            'bytes' => strlen($data),
        ];
    }

    /**
     * Liest die Regeln und Sonderflags aus einer .aff-Datei.
     *
     * @param string $raw Inhalt der .aff-Datei (UTF-8)
     *
     * @return array<string,mixed> Regelwerk fuer OrvantaSpellcheckDictionary
     */
    public function parseAff(string $raw): array
    {
        $aff = [
            'compound_min' => 3,
            'compound_max' => 0,
            'checksharps' => false,
            'try' => '',
            'flags' => [],
            'breaks' => [],
            'rep' => [],
            'map' => [],
            'suffixes' => [],
            'prefixes' => [],
        ];

        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $total = count($lines);
        for ($i = 0; $i < $total; $i++) {
            $line = $lines[$i];
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/[ \t]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) {
                continue;
            }
            $directive = $parts[0];
            $value = $parts[1] ?? '';

            switch ($directive) {
                case 'SFX':
                case 'PFX':
                    $cross = $parts[2] ?? '';
                    if ($cross !== 'Y' && $cross !== 'N') {
                        break;
                    }
                    [$rules, $i] = $this->readAffixRules($lines, $i + 1, (int) ($parts[3] ?? 0), $directive === 'SFX', $value, $cross === 'Y');
                    if ($directive === 'SFX') {
                        $aff['suffixes'] = array_merge($aff['suffixes'], $rules);
                    } else {
                        $aff['prefixes'] = array_merge($aff['prefixes'], $rules);
                    }
                    break;
                case 'BREAK':
                    [$values, $i] = $this->readTable($lines, $i + 1, (int) $value, 'BREAK');
                    $aff['breaks'] = array_merge($aff['breaks'], $values);
                    break;
                case 'REP':
                    [$values, $i] = $this->readTable($lines, $i + 1, (int) $value, 'REP');
                    foreach ($values as $entry) {
                        $pair = preg_split('/[ \t]+/', trim($entry), 2) ?: [];
                        if (count($pair) === 2) {
                            $aff['rep'][] = ['from' => $pair[0], 'to' => rtrim($pair[1])];
                        }
                    }
                    break;
                case 'MAP':
                    [$values, $i] = $this->readTable($lines, $i + 1, (int) $value, 'MAP');
                    foreach ($values as $entry) {
                        $group = $this->parseMapGroup($entry);
                        if ($group !== []) {
                            $aff['map'][] = $group;
                        }
                    }
                    break;
                case 'TRY':
                    $aff['try'] = $value;
                    break;
                case 'CHECKSHARPS':
                    $aff['checksharps'] = true;
                    break;
                case 'COMPOUNDMIN':
                    $aff['compound_min'] = max(1, (int) $value);
                    break;
                case 'COMPOUNDWORDMAX':
                    $aff['compound_max'] = (int) $value;
                    break;
                default:
                    // Sonderflags sind Einzelzeichen (FLAG short); Synonyme wie
                    // PSEUDOROOT/COMPOUNDLAST werden wie in Hunspell abgebildet.
                    if (isset(self::FLAG_DIRECTIVES[$directive]) && $value !== '') {
                        $aff['flags'][self::FLAG_DIRECTIVES[$directive]] = $value[0];
                    }
                    break;
            }
        }

        return $aff;
    }

    /**
     * Einzelzeichen-Flags, die die Pruefung auswertet.
     *
     * @var array<string,string>
     */
    private const FLAG_DIRECTIVES = [
        'FORBIDDENWORD' => 'forbidden',
        'NEEDAFFIX' => 'need_affix',
        'PSEUDOROOT' => 'need_affix',
        'NOSUGGEST' => 'nosuggest',
        'KEEPCASE' => 'keepcase',
        'CIRCUMFIX' => 'circumfix',
        'ONLYINCOMPOUND' => 'only_in_compound',
        'COMPOUNDFLAG' => 'compound',
        'COMPOUNDBEGIN' => 'compound_begin',
        'COMPOUNDMIDDLE' => 'compound_middle',
        'COMPOUNDEND' => 'compound_end',
        'COMPOUNDLAST' => 'compound_end',
        'COMPOUNDPERMITFLAG' => 'compound_permit',
        'COMPOUNDFORBIDFLAG' => 'compound_forbid',
    ];

    /**
     * Liest die Regeln eines SFX-/PFX-Blocks.
     *
     * @param list<string> $lines
     *
     * @return array{0:list<array<string,mixed>>,1:int} Regeln und Zeilenindex der letzten Regel
     */
    private function readAffixRules(array $lines, int $start, int $count, bool $suffix, string $flag, bool $cross): array
    {
        $rules = [];
        $total = count($lines);
        $index = $start;
        $read = 0;
        while ($index < $total && $read < $count) {
            $line = $lines[$index];
            $index++;
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/[ \t]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 4 || ($parts[0] !== 'SFX' && $parts[0] !== 'PFX')) {
                continue;
            }
            $add = $parts[3];
            $continuation = '';
            $slash = strpos($add, '/');
            if ($slash !== false) {
                $continuation = substr($add, $slash + 1);
                $add = substr($add, 0, $slash);
            }
            if ($add === '0') {
                $add = '';
            }
            $rules[] = [
                'flag' => $flag,
                'cross' => $cross,
                'strip' => $parts[2] === '0' ? '' : $parts[2],
                'add' => $add,
                'cont' => $continuation,
                'cond' => $this->compileCondition($parts[4] ?? '.', $suffix),
            ];
            $read++;
        }

        return [$rules, $index - 1];
    }

    /**
     * Uebersetzt eine Hunspell-Bedingung in einen PCRE-Ausdruck. Hunspell
     * erlaubt nur Zeichenklassen und Literale; wie in spylls wird nur "-"
     * maskiert.
     */
    private function compileCondition(string $condition, bool $suffix): string
    {
        if ($condition === '' || $condition === '.') {
            return '';
        }
        $pattern = str_replace(['#', '-'], ['\\#', '\\-'], $condition);

        return $suffix ? '#' . $pattern . '\z#u' : '#\A' . $pattern . '#u';
    }

    /**
     * Liest die Wertzeilen einer Tabellenanweisung (BREAK, REP, MAP, ...).
     *
     * @param list<string> $lines
     *
     * @return array{0:list<string>,1:int}
     */
    private function readTable(array $lines, int $start, int $count, string $directive): array
    {
        $values = [];
        $total = count($lines);
        $index = $start;
        $read = 0;
        while ($index < $total && $read < $count) {
            $line = $lines[$index];
            $index++;
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/[ \t]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (($parts[0] ?? '') !== $directive) {
                continue;
            }
            $values[] = substr(trim($line), strlen($directive) + 1);
            $read++;
        }

        return [$values, $index - 1];
    }

    /**
     * Zerlegt eine MAP-Zeile in Einzelzeichen; "(ss)" gilt als ein Zeichen.
     *
     * @return list<string>
     */
    private function parseMapGroup(string $line): array
    {
        $group = [];
        $length = mb_strlen($line, 'UTF-8');
        for ($i = 0; $i < $length;) {
            $char = mb_substr($line, $i, 1, 'UTF-8');
            if ($char === '(') {
                $end = mb_strpos($line, ')', $i);
                if ($end === false) {
                    break;
                }
                $group[] = mb_substr($line, $i + 1, $end - $i - 1, 'UTF-8');
                $i = $end + 1;
                continue;
            }
            if (trim($char) !== '') {
                $group[] = $char;
            }
            $i++;
        }

        return $group;
    }

    /**
     * Liest die Woerterbuchzeilen.
     *
     * @param string $raw Inhalt der .dic-Datei (UTF-8)
     *
     * @return array<string,list<string>> Wort => Flag-Zeichenketten (Homonyme)
     */
    public function parseDic(string $raw): array
    {
        $words = [];
        $first = true;
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if ($first) {
                $first = false;
                if (preg_match('/^\d+(\s+|$)/', $line) === 1) {
                    continue;
                }
            }
            if ($line[0] === '#' || $line[0] === "\t" || $line[0] === ' ') {
                continue;
            }
            $cut = $this->dataTagOffset($line);
            if ($cut !== null) {
                $line = substr($line, 0, $cut);
                if ($line === '') {
                    continue;
                }
            }

            $slash = $this->flagSeparator($line);
            if ($slash === 0) {
                $word = $line;
                $flags = '';
            } elseif ($slash === null) {
                $word = $line;
                $flags = '';
            } else {
                $word = substr($line, 0, $slash);
                $flags = substr($line, $slash + 1);
            }
            if ($word === '') {
                continue;
            }
            $word = str_replace('\\/', '/', $word);
            if (!isset($words[$word])) {
                $words[$word] = [];
            }
            if (!in_array($flags, $words[$word], true)) {
                $words[$word][] = $flags;
            }
        }

        return $words;
    }

    /**
     * Position des ersten ungeschuetzten "/" (Trenner zwischen Wort und Flags).
     */
    private function flagSeparator(string $line): ?int
    {
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '/' && ($i === 0 || $line[$i - 1] !== '\\')) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Position eines Datenanhangs ("ph:", "st:", ...) oder eines Tabs.
     */
    private function dataTagOffset(string $line): ?int
    {
        $offset = null;
        if (preg_match('/[ \t]\w{2}:/u', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $match[0][1];
        }
        $tab = strpos($line, "\t");
        if ($tab !== false && ($offset === null || $tab < $offset)) {
            $offset = $tab;
        }

        return $offset;
    }

    /**
     * Zeichensatz aus der SET-Anweisung der .aff-Datei.
     */
    private function detectCharset(string $raw): string
    {
        if (preg_match('/^SET[ \t]+(\S+)/m', $raw, $match) === 1) {
            return $match[1];
        }

        return 'UTF-8';
    }

    /**
     * Wandelt den Zeichensatz des Woerterbuchs nach UTF-8 (de_DE_frami: ISO-8859-1).
     */
    private function toUtf8(string $raw, string $charset): string
    {
        $normalized = strtoupper(str_replace(['-', '_'], '', $charset));
        if ($normalized === 'UTF8') {
            return $raw;
        }
        $from = match ($normalized) {
            'ISO88591', 'LATIN1', 'ISOIR100' => 'ISO-8859-1',
            'ISO885915', 'LATIN9' => 'ISO-8859-15',
            'ISO88592', 'LATIN2' => 'ISO-8859-2',
            'CP1252', 'WINDOWS1252' => 'Windows-1252',
            default => $charset,
        };
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = iconv($from, 'UTF-8', $raw);
            if ($converted === false) {
                throw new RuntimeException('Der Zeichensatz ' . $charset . ' konnte nicht nach UTF-8 gewandelt werden.');
            }

            return $converted;
        }

        return $raw;
    }

    /**
     * @throws RuntimeException
     */
    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Datei nicht gefunden: ' . $path);
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new RuntimeException('Datei ist leer oder unlesbar: ' . $path);
        }

        return $content;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $directory);
        }
    }

    /**
     * Schreibt eine Datei ueber eine temporaere Datei, damit ein Abbruch
     * keinen halben Datensatz hinterlaesst.
     */
    private function writeFile(string $path, string $content): void
    {
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Datei konnte nicht geschrieben werden: ' . $path);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Datei konnte nicht umbenannt werden: ' . $path);
        }
        chmod($path, 0640);
    }

    /**
     * Lizenz- und Herkunftshinweis zum mitgelieferten Woerterbuch.
     */
    private function notice(string $affPath, string $dicPath, string $charset): string
    {
        return "Rechtschreibpruefung Orvanta - Woerterbuch\n"
            . "=========================================\n\n"
            . "Quelle:      " . basename($affPath) . " / " . basename($dicPath) . "\n"
            . "Bezug:       LibreOffice dictionaries (de_DE_frami), Grundlage igerman98\n"
            . "             https://github.com/LibreOffice/dictionaries/tree/master/de\n"
            . "Urheber:     Björn Jacke (igerman98), Ergaenzungen von Franz Michael Baumann (frami)\n"
            . "Version:     20161207+frami20170109\n"
            . "Zeichensatz: " . $charset . " (beim Uebersetzen nach UTF-8 gewandelt)\n"
            . "Lizenz:      GPL v2 oder spaeter, siehe COPYING der Quelle\n\n"
            . "Die Datei wurde unveraendert uebernommen und nur in das interne\n"
            . "Datenformat der Rechtschreibpruefung uebersetzt.\n";
    }
}
