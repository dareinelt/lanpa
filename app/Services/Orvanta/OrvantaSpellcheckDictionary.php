<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

/**
 * Lesezugriff auf das aufbereitete Woerterbuch der Orvanta-Rechtschreibpruefung.
 *
 * Die Dateien entstehen einmalig beim Containerstart ueber
 * {@see OrvantaSpellcheckCompiler} (siehe scripts/spellcheck_dictionary.php).
 * Sie werden erst beim ersten Zugriff geladen; die Wortliste wird ueber einen
 * binaer durchsuchten Offset-Index seitenweise gelesen, damit der Speicherbedarf
 * je Apache-Prozess klein bleibt.
 *
 * Aufbau von words.dat (je Zeile "Wort\tFlags", Flags mehrerer Homonyme mit \n):
 * der Index enthaelt je Eintrag den 32-Bit-Offset; die Laenge eines Eintrags
 * ergibt sich aus dem Offset des naechsten Eintrags.
 */
final class OrvantaSpellcheckDictionary
{
    /** Groesse eines Indexeintrags: 32-Bit-Offset in words.dat. */
    private const INDEX_ENTRY_SIZE = 4;

    /** Obergrenze fuer den Fallback "Woerter mit gleichem Anfang" (Vorschlaege). */
    private const MAX_PREFIX_RESULTS = 2000;

    private bool $loaded = false;

    /** @var array<string,mixed>|null */
    private ?array $meta = null;

    /** @var array<string,mixed> */
    private array $aff = [];

    private ?string $data = null;

    private ?string $index = null;

    private int $count = 0;

    /**
     * Suffixregeln, gruppiert nach Laenge der angehaengten Zeichen und Zeichenfolge.
     *
     * @var array<int,array<string,list<array<string,mixed>>>>
     */
    private array $suffixRules = [];

    /**
     * Prefixregeln, gruppiert nach Laenge der angehaengten Zeichen und Zeichenfolge.
     *
     * @var array<int,array<string,list<array<string,mixed>>>>
     */
    private array $prefixRules = [];

    /** @var list<int> */
    private array $suffixLengths = [];

    /** @var list<int> */
    private array $prefixLengths = [];

    /** @var array<string,list<string>|null> */
    private array $wordMemo = [];

    /**
     * Suche ohne Ruecksicht auf Gross-/Kleinschreibung (words.case), erst bei
     * Bedarf geladen.
     *
     * @var array<string,list<array{word:string,flags:string}>>|null
     */
    private ?array $caseIndex = null;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Ist ein vollstaendiger, zum Format passender Datensatz vorhanden?
     */
    public function isAvailable(): bool
    {
        return $this->meta() !== null;
    }

    /**
     * Kennzahlen des uebersetzten Woerterbuchs (meta.json).
     *
     * @return array<string,mixed>|null
     */
    public function meta(): ?array
    {
        if ($this->loaded) {
            return $this->meta;
        }
        $this->load();

        return $this->meta;
    }

    /**
     * Einzelzeichen-Flag zu einem Schluessel der .aff-Datei ("" wenn nicht gesetzt).
     */
    public function flag(string $name): string
    {
        $this->load();
        $flags = $this->aff['flags'] ?? [];

        return is_array($flags) && isset($flags[$name]) ? (string) $flags[$name] : '';
    }

    public function compoundMin(): int
    {
        $this->load();

        return max(1, (int) ($this->aff['compound_min'] ?? 2));
    }

    public function compoundMax(): int
    {
        $this->load();

        return max(0, (int) ($this->aff['compound_max'] ?? 0));
    }

    public function checkSharps(): bool
    {
        $this->load();

        return (bool) ($this->aff['checksharps'] ?? false);
    }

    /**
     * Trennzeichen, an denen ein Wort zerlegt werden darf (BREAK).
     *
     * @return list<string>
     */
    public function breaks(): array
    {
        $this->load();

        return array_values(array_filter(array_map('strval', $this->aff['breaks'] ?? []), static fn (string $b): bool => $b !== ''));
    }

    /**
     * Haeufige Verwechslungen fuer Vorschlaege (REP).
     *
     * @return list<array{from:string,to:string}>
     */
    public function replacements(): array
    {
        $this->load();

        return array_values($this->aff['rep'] ?? []);
    }

    /**
     * Gruppen aehnlicher Zeichen fuer Vorschlaege (MAP).
     *
     * @return list<list<string>>
     */
    public function maps(): array
    {
        $this->load();

        return array_values($this->aff['map'] ?? []);
    }

    /**
     * Bevorzugte Buchstabenfolge fuer Vorschlaege (TRY).
     */
    public function tryLetters(): string
    {
        $this->load();

        return (string) ($this->aff['try'] ?? '');
    }

    public function wordCount(): int
    {
        $this->load();

        return $this->count;
    }

    /**
     * Suffixregeln mit dieser angehaengten Zeichenfolge.
     *
     * @return list<array<string,mixed>>
     */
    public function suffixRules(string $add): array
    {
        $this->load();

        return $this->suffixRules[mb_strlen($add, 'UTF-8')][$add] ?? [];
    }

    /**
     * Laengen der angehaengten Zeichenfolgen, absteigend.
     *
     * @return list<int>
     */
    public function suffixLengths(): array
    {
        $this->load();

        return $this->suffixLengths;
    }

    /**
     * Prefixregeln mit dieser vorangestellten Zeichenfolge.
     *
     * @return list<array<string,mixed>>
     */
    public function prefixRules(string $add): array
    {
        $this->load();

        return $this->prefixRules[mb_strlen($add, 'UTF-8')][$add] ?? [];
    }

    /**
     * Laengen der vorangestellten Zeichenfolgen, absteigend.
     *
     * @return list<int>
     */
    public function prefixLengths(): array
    {
        $this->load();

        return $this->prefixLengths;
    }

    /**
     * Flag-Zeichenketten aller Homonyme eines Stamms.
     *
     * @return list<string>|null null, wenn der Stamm nicht im Woerterbuch steht
     */
    public function flagsOf(string $word): ?array
    {
        $this->load();
        if ($this->data === null || $this->index === null || $word === '') {
            return null;
        }
        if (array_key_exists($word, $this->wordMemo)) {
            return $this->wordMemo[$word];
        }
        if (count($this->wordMemo) > 5000) {
            $this->wordMemo = [];
        }
        $position = $this->search($word);
        if ($position === null) {
            return $this->wordMemo[$word] = null;
        }
        [$start, $length] = $this->entryAt($position);
        $line = substr($this->data, $start, $length);
        $parts = explode("\t", rtrim($line, "\n"), 2);

        return $this->wordMemo[$word] = explode("\n", $parts[1] ?? '');
    }

    /**
     * Woerter mit gemischter oder durchgaengiger Grossschreibung zu einem
     * kleingeschriebenen Schluessel (Suche ohne Ruecksicht auf die Schreibung).
     *
     * @return list<array{word:string,flags:string}>
     */
    public function caseEntries(string $key): array
    {
        $this->loadCase();

        return $this->caseIndex[$key] ?? [];
    }

    /**
     * Woerter, die mit der angegebenen Zeichenfolge beginnen (fuer Vorschlaege).
     *
     * @return list<array{word:string,flags:string}>
     */
    public function wordsWithPrefix(string $prefix, int $limit = self::MAX_PREFIX_RESULTS): array
    {
        $this->load();
        if ($this->data === null || $this->index === null || $prefix === '') {
            return [];
        }
        $result = [];
        $position = $this->lowerBound($prefix);
        $length = mb_strlen($prefix, 'UTF-8');
        for (; $position < $this->count && count($result) < $limit; $position++) {
            [$start, $size] = $this->entryAt($position);
            $line = rtrim(substr($this->data, $start, $size), "\n");
            $parts = explode("\t", $line, 2);
            if (mb_substr($parts[0], 0, $length, 'UTF-8') !== $prefix) {
                break;
            }
            $result[] = ['word' => $parts[0], 'flags' => $parts[1] ?? ''];
        }

        return $result;
    }

    /**
     * Laedt Regelwerk und Wortliste. Fehlende oder unpassende Dateien lassen
     * das Woerterbuch unbrauchbar werden, ohne eine Ausnahme auszuloesen.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $raw = @file_get_contents($this->path(OrvantaSpellcheckCompiler::META_FILE));
        $meta = $raw === false ? null : json_decode($raw, true);
        if (!is_array($meta) || (int) ($meta['version'] ?? 0) !== OrvantaSpellcheckCompiler::FORMAT_VERSION) {
            return;
        }

        $affRaw = @file_get_contents($this->path(OrvantaSpellcheckCompiler::AFF_FILE));
        $aff = $affRaw === false ? false : @unserialize($affRaw, ['allowed_classes' => false]);
        if (!is_array($aff)) {
            return;
        }

        $data = @file_get_contents($this->path(OrvantaSpellcheckCompiler::WORDS_DATA_FILE));
        $index = @file_get_contents($this->path(OrvantaSpellcheckCompiler::WORDS_INDEX_FILE));
        $count = (int) ($meta['words'] ?? 0);
        if ($data === false || $index === false || $count < 1 || strlen($index) !== $count * self::INDEX_ENTRY_SIZE) {
            return;
        }

        $this->aff = $aff;
        $this->data = $data;
        $this->index = $index;
        $this->count = $count;
        $this->meta = $meta;
        $this->buildRuleIndex();
    }

    /**
     * Laedt den Zusatzindex fuer die Suche ohne Ruecksicht auf die Schreibung.
     */
    private function loadCase(): void
    {
        if ($this->caseIndex !== null) {
            return;
        }
        $this->caseIndex = [];
        if (!$this->isAvailable()) {
            return;
        }
        $raw = @file_get_contents($this->path(OrvantaSpellcheckCompiler::WORDS_CASE_FILE));
        if ($raw === false) {
            return;
        }
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $this->caseIndex[$parts[0]][] = ['word' => $parts[1], 'flags' => $parts[2]];
        }
    }

    /**
     * Baut die nach Laenge und Zeichenfolge gruppierten Regelverzeichnisse auf.
     */
    private function buildRuleIndex(): void
    {
        foreach ($this->aff['suffixes'] ?? [] as $rule) {
            $add = (string) ($rule['add'] ?? '');
            $this->suffixRules[mb_strlen($add, 'UTF-8')][$add][] = $rule;
        }
        foreach ($this->aff['prefixes'] ?? [] as $rule) {
            $add = (string) ($rule['add'] ?? '');
            $this->prefixRules[mb_strlen($add, 'UTF-8')][$add][] = $rule;
        }
        $this->suffixLengths = array_keys($this->suffixRules);
        rsort($this->suffixLengths);
        $this->prefixLengths = array_keys($this->prefixRules);
        rsort($this->prefixLengths);
    }

    /**
     * Binaersuche im Index (Wortliste ist nach SORT_STRING sortiert).
     */
    private function search(string $word): ?int
    {
        $position = $this->lowerBound($word);

        return $position < $this->count && $this->wordAt($position) === $word ? $position : null;
    }

    /**
     * Erste Position mit einem Wort >= $word (Binaersuche).
     */
    private function lowerBound(string $word): int
    {
        $low = 0;
        $high = $this->count;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (strcmp($this->wordAt($middle), $word) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    private function wordAt(int $position): string
    {
        [$start, $length] = $this->entryAt($position);
        $line = substr((string) $this->data, $start, $length);
        $tab = strpos($line, "\t");

        return rtrim($tab === false ? $line : substr($line, 0, $tab), "\n");
    }

    /**
     * @return array{0:int,1:int} Offset und Laenge eines Eintrags in words.dat
     */
    private function entryAt(int $position): array
    {
        $start = $this->offsetAt($position);
        $end = $position + 1 < $this->count ? $this->offsetAt($position + 1) : strlen((string) $this->data);

        return [$start, $end - $start];
    }

    private function offsetAt(int $position): int
    {
        $values = unpack('V', substr((string) $this->index, $position * self::INDEX_ENTRY_SIZE, self::INDEX_ENTRY_SIZE));

        return (int) $values[1];
    }

    private function path(string $file): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $file;
    }
}
