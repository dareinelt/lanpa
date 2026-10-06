<?php

declare(strict_types=1);

use App\Services\Orvanta\OrvantaSpellcheckCompiler;
use App\Services\Orvanta\OrvantaSpellcheckDictionary;
use App\Services\Orvanta\OrvantaSpellcheckService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Kleines Pruefwoerterbuch im Aufbau des deutschen Originalwoerterbuchs
 * (de_DE_frami). Es deckt die Mechanismen ab, die die Pruefung tragen:
 * Affixregeln mit Fortsetzungsflags, Wortzusammensetzungen, Umlaute und "ß",
 * Gross-/Kleinschreibung und Sonderflags.
 */
const SPELLCHECK_TEST_AFF = <<<'AFF'
SET UTF-8
TRY esijanrtolcdugmphbyfvkwqxz
CHECKSHARPS
COMPOUNDBEGIN x
COMPOUNDMIDDLE y
COMPOUNDEND z
FORBIDDENWORD d
COMPOUNDPERMITFLAG c
ONLYINCOMPOUND o
NEEDAFFIX h
KEEPCASE w
NOSUGGEST n
WORDCHARS ß-.
COMPOUNDMIN 2
BREAK 2
BREAK -
BREAK .
REP 2
REP f ph
REP ph f
MAP 2
MAP (ss)ß
MAP (a)ä
SFX S Y 2
SFX S 0 e .
SFX S 0 en .
SFX C Y 1
SFX C 0 er/S .
SFX j Y 1
SFX j 0 0/xoc .
PFX P Y 1
PFX P 0 un .
AFF;

/**
 * Haus/j           -> Fugen-s (SFX j) vergibt COMPOUNDBEGIN, Nur-im-Wort und
 *                     Erlaubnisflag; "Haus" allein bleibt gueltig.
 * dach/z, Dach/z   -> als Wortende zugelassen, Schreibweise wird unterschieden.
 * Quark/S          -> ohne Zusammensetzungsflags, kann also nicht Teil
 *                     einer Zusammensetzung sein.
 * Test/CSP         -> gueltige Affixketten (C erlaubt genau eine weitere Stufe),
 *                     PFX P fuer die Vorsilbe "un".
 * Rechnung/S       -> einfache Ableitung.
 * Straße/S         -> "ß" mit CHECKSHARPS.
 * Scheiss/d        -> FORBIDDENWORD sperrt die falsche Schreibweise.
 * eBay/w           -> KEEPCASE.
 * ACLs/S           -> Gross-/Kleinschreibung im Bestand (HUHINIT).
 * fug/xoz          -> nur innerhalb einer Zusammensetzung gueltig.
 * E, Mail, Adresse -> Zerlegung an Bindestrichen.
 */
const SPELLCHECK_TEST_DIC = <<<'DIC'
Haus/j
Dach/z
dach/z
Quark/S
Test/CSP
Rechnung/S
Straße/S
Scheiss/d
Scheiße/S
eBay/w
ACLs/S
fug/xoz
Boot/z
E
Mail/S
Adresse/S
DIC;

/** Verzeichnis mit aufbereitetem Pruefwoerterbuch (je Testlauf einmal). */
function spellcheckFixture(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $base = sys_get_temp_dir() . '/lanpa-spellcheck-' . bin2hex(random_bytes(6));
    mkdir($base, 0o700, true);
    file_put_contents($base . '/test.aff', SPELLCHECK_TEST_AFF);
    file_put_contents($base . '/test.dic', SPELLCHECK_TEST_DIC);
    (new OrvantaSpellcheckCompiler())->compile($base . '/test.aff', $base . '/test.dic', $base . '/out');
    register_shutdown_function(static function () use ($base): void {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($base);
    });
    $dir = $base . '/out';

    return $dir;
}

function spellcheckService(): OrvantaSpellcheckService
{
    return new OrvantaSpellcheckService(new OrvantaSpellcheckDictionary(spellcheckFixture()));
}

/**
 * @param array<string,bool> $cases
 */
function spellcheckAssertWords(array $cases, string $label): void
{
    $service = spellcheckService();
    foreach ($cases as $word => $expected) {
        // Numerische Schluessel werden von PHP in Ganzzahlen umgewandelt.
        $word = (string) $word;
        Assert::same(
            $expected,
            $service->check($word),
            $label . ': "' . $word . '" sollte ' . ($expected ? 'korrekt' : 'fehlerhaft') . ' sein.'
        );
    }
}

Runner::test('Rechtschreibung: Aufbereitung des Woerterbuchs', function (): void {
    $directory = spellcheckFixture();
    $dictionary = new OrvantaSpellcheckDictionary($directory);
    Assert::true($dictionary->isAvailable(), 'Das aufbereitete Woerterbuch sollte lesbar sein.');
    $meta = $dictionary->meta();
    Assert::same(16, $meta['words'], 'Alle Stammwoerter sollten uebernommen werden.');
    Assert::same(4, $meta['suffixes'], 'Die drei SFX-Regeln sollten uebernommen werden.');
    Assert::same(1, $meta['prefixes'], 'Die PFX-Regel sollte uebernommen werden.');
    Assert::same(['-', '.'], $dictionary->breaks(), 'Beide BREAK-Muster sollten uebernommen werden.');

    // Ein unvollstaendiger Datensatz (fehlendes meta.json) gilt als nicht verfuegbar.
    Assert::false((new OrvantaSpellcheckDictionary(sys_get_temp_dir()))->isAvailable(), 'Ohne meta.json ist die Pruefung nicht verfuegbar.');
});

Runner::test('Rechtschreibung: Stammwoerter, Affixe und Gross-/Kleinschreibung', function (): void {
    spellcheckAssertWords([
        'Haus' => true,
        'haus' => false,
        'Dach' => true,
        'dach' => true,
        'Test' => true,
        'Rechnung' => true,
        'Boot' => true,
        'Hausxxx' => false,
        'Rechnun' => false,
        // SFX S 0 e . / SFX S 0 en .
        'Teste' => true,
        'Testen' => true,
        'Testes' => false,
        // SFX C 0 er/S . und die darauf aufbauende zweite Stufe
        'Tester' => true,
        'Testere' => true,
        // Kein Fortsetzungsflag: eine zweite Stufe ist hier nicht erlaubt.
        'Testeen' => false,
        'Testenen' => false,
        // PFX P 0 un .
        'unTest' => true,
        'unTesten' => true,
        'unHaus' => false,
        // KEEPCASE: nur genau diese Schreibweise
        'eBay' => true,
        'Ebay' => false,
        'ebay' => false,
        'EBAY' => false,
        // Gross geschriebener Bestand wird auch in Versalien erkannt
        'ACLs' => true,
        'ACLS' => true,
        'acls' => false,
        // Zahlen
        '12345' => true,
        '12.5' => true,
    ], 'Wortpruefung');
});

Runner::test('Rechtschreibung: Umlaute, scharfes S und verbotene Schreibweisen', function (): void {
    spellcheckAssertWords([
        'Straße' => true,
        'Strasse' => false,
        // In Versalien ist die Ersatzschreibweise erlaubt (CHECKSHARPS).
        'STRASSE' => true,
        'Scheiße' => true,
        // FORBIDDENWORD sperrt die Ersatzschreibweise vollstaendig
        'Scheiss' => false,
        'Scheisse' => false,
        'Scheißen' => false,
    ], 'Sonderzeichen');
});

Runner::test('Rechtschreibung: Wortzusammensetzungen ueber Fortsetzungsflags', function (): void {
    spellcheckAssertWords([
        // "Haus" erhaelt ueber SFX j (0/xoc) die Erlaubnis, ein Wort zu beginnen.
        'Hausdach' => true,
        'HausBoot' => true,
        // "boot" gibt es nicht, deshalb greift hier keine Zusammensetzung.
        'Hausboot' => false,
        // "Quark" darf nicht am Wortende stehen.
        'Quarkhaus' => false,
        'Quarkdach' => false,
        // "fug" ist nur innerhalb einer Zusammensetzung gueltig.
        'fug' => false,
        'Fug' => false,
        'Hausfug' => true,
    ], 'Zusammensetzungen');
});

Runner::test('Rechtschreibung: Zerlegung an Bindestrichen', function (): void {
    spellcheckAssertWords([
        'Haus-Boot' => true,
        'Haus-Quarx' => false,
        'Haus-Test' => true,
        'E-Mail-Adresse' => true,
        'E-Mai-Adresse' => false,
    ], 'Bindestrich');
});

Runner::test('Rechtschreibung: Vorschlaege', function (): void {
    $service = spellcheckService();
    Assert::same(['Rechnung'], $service->suggest('Rechnun'), 'Der fehlende Buchstabe sollte ergaenzt werden.');
    Assert::same([], $service->suggest('Rechnung'), 'Fuer korrekte Woerter gibt es keine Vorschlaege.');

    $suggestions = $service->suggest('Strasse');
    Assert::same('Straße', $suggestions[0] ?? '', 'Die Ersatzschreibweise sollte ueber MAP gefunden werden.');

    $suggestions = $service->suggest('Testeer');
    Assert::contains('Tester', implode('|', $suggestions), '"Tester" sollte vorgeschlagen werden.');
});

Runner::test('Rechtschreibung: Abschalten ueber die Konfiguration', function (): void {
    $dictionary = new OrvantaSpellcheckDictionary(spellcheckFixture());
    $disabled = new OrvantaSpellcheckService($dictionary, false);
    Assert::false($disabled->isAvailable(), 'Ein abgeschalteter Dienst gilt als nicht verfuegbar.');
    Assert::true($disabled->check('Hausxxx'), 'Ohne Pruefung gilt jedes Wort als korrekt.');
    Assert::same([], $disabled->suggest('Rechnun'), 'Ohne Pruefung gibt es keine Vorschlaege.');
    Assert::true((new OrvantaSpellcheckService($dictionary, true))->isAvailable(), 'Eingeschaltet bleibt der Dienst verfuegbar.');
});

Runner::test('Rechtschreibung: Anfragegrenzen', function (): void {
    $service = spellcheckService();
    Assert::true($service->check(str_repeat('a', 65)), 'Sehr lange Woerter gelten als korrekt.');
    Assert::true($service->check(''), 'Leere Eingaben gelten als korrekt.');
    Assert::same(400, OrvantaSpellcheckService::MAX_WORDS_PER_REQUEST, 'Die Anfrageobergrenze der Oberflaeche passt zum Dienst.');
    Assert::same(64, OrvantaSpellcheckService::MAX_WORD_LENGTH, 'Die Wortlaengengrenze der Oberflaeche passt zum Dienst.');
});
