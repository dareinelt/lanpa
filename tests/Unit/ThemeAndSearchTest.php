<?php

declare(strict_types=1);

use App\Repositories\PhonebookRepository;
use App\Services\ThemeService;
use App\Support\Html;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('Farbmischung und Aufhellung liefern gültige Hexwerte', static function (): void {
    /** @var ThemeService $theme */
    $theme = (new ReflectionClass(ThemeService::class))->newInstanceWithoutConstructor();

    Assert::same('#808080', $theme->mix('#000000', '#ffffff', 0.5));
    Assert::same('#000000', $theme->mix('#000000', '#ffffff', -1.0));
    Assert::same('#ffffff', $theme->mix('#000000', '#ffffff', 2.0));
    Assert::same('#ffffff', $theme->lighten('#000000', 1.0));
});

Runner::test('Textfarbe folgt der Leuchtdichte (Kontrast)', static function (): void {
    /** @var ThemeService $theme */
    $theme = (new ReflectionClass(ThemeService::class))->newInstanceWithoutConstructor();

    Assert::same('#000000', $theme->readableTextColor('#ffffff'));
    Assert::same('#ffffff', $theme->readableTextColor('#1f4e79'));
    Assert::same('#ffffff', $theme->readableTextColor('unsinn'));
});

Runner::test('HTML-Ausgabe wird maskiert', static function (): void {
    Assert::same('&lt;script&gt;alert(1)&lt;/script&gt;', Html::e('<script>alert(1)</script>'));
    Assert::contains('&quot;a&quot;', Html::e('"a" & \'b\''));
    Assert::contains('&amp;', Html::e('"a" & \'b\''));
    Assert::false(str_contains(Html::e('"a" & \'b\''), "'"));
});

Runner::test('Suchbedingung nutzt Platzhalter statt eingebetteter Werte', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql, $params] = $repository->buildSearchCondition('Muster');

    Assert::contains('active = 1', $sql);
    Assert::contains(':t0_0', $sql);
    Assert::false(str_contains($sql, 'Muster'));
    Assert::same('Muster%', $params['t0_0']);
    Assert::same('Muster%', $params['t0_3']);
});

Runner::test('Suche nach Ziffern durchsucht die Telefonnummern', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql, $params] = $repository->buildSearchCondition('123');

    Assert::contains('phone_digits LIKE :d0', $sql);
    Assert::same('%123%', $params['d0']);
});

Runner::test('LIKE-Platzhalter in der Eingabe werden maskiert', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [, $params] = $repository->buildSearchCondition('100%_a');

    Assert::same('100\%\_a%', $params['t0_0']);
});

Runner::test('Jeder Platzhalter kommt nur einmal in der Bedingung vor', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql, $params] = $repository->buildSearchCondition('Muster 123');

    Assert::same(count($params), substr_count($sql, ' LIKE :'));
});

Runner::test('Suchbegriffe werden auf fünf Tokens begrenzt', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [, $params] = $repository->buildSearchCondition('a b c d e f g');

    Assert::same(20, count(array_filter(array_keys($params), static fn (string $k): bool => str_starts_with($k, 't'))));
});

Runner::test('Leerer Suchbegriff liefert nur den Aktiv- und Telefonfilter', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql, $params] = $repository->buildSearchCondition('   ');

    Assert::contains('active = 1', $sql);
    Assert::contains("phone <> ''", $sql);
    Assert::same(0, count($params));
});

Runner::test('Eintraege ohne Telefonnummer werden fuer Nutzer ausgeblendet', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql] = $repository->buildSearchCondition('Muster');

    Assert::contains("(phone IS NOT NULL AND phone <> '')", $sql);
    Assert::contains("(mobile IS NOT NULL AND mobile <> '')", $sql);
});

Runner::test('Administratoren sehen auch Eintraege ohne Telefonnummer', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql] = $repository->buildSearchCondition('Muster', true);

    Assert::false(str_contains($sql, 'phone IS NOT NULL'));
    Assert::contains('active = 1', $sql);
});
