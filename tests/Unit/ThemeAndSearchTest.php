<?php

declare(strict_types=1);

use App\Repositories\PhonebookRepository;
use App\Services\PhonebookService;
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
    Assert::same('% 100\%\_a%', $params['w0_0']);
});

Runner::test('Suchbegriff trifft den Anfang eines Namensbestandteils', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql, $params] = $repository->buildSearchCondition('Ulf');

    Assert::contains('display_name LIKE :w0_0', $sql);
    Assert::same('% Ulf%', $params['w0_0']);
    Assert::same('% Ulf%', $params['w0_3']);
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

Runner::test('Leerer Suchbegriff liefert nur den Aktiv- und E-Mail-Filter', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql, $params] = $repository->buildSearchCondition('   ');

    Assert::contains('active = 1', $sql);
    Assert::contains("email <> ''", $sql);
    Assert::same(0, count($params));
});

Runner::test('Eintraege ohne E-Mail-Adresse werden fuer Nutzer ausgeblendet', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql] = $repository->buildSearchCondition('Muster');

    Assert::contains("(email IS NOT NULL AND email <> '')", $sql);
});

Runner::test('Administratoren sehen auch Eintraege ohne E-Mail-Adresse', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql] = $repository->buildSearchCondition('Muster', true);

    Assert::false(str_contains($sql, 'email IS NOT NULL'));
    Assert::contains('active = 1', $sql);
});

Runner::test('Ausgeblendete Eintraege bleiben in der oeffentlichen Suche ausgeschlossen', static function (): void {
    /** @var PhonebookRepository $repository */
    $repository = (new ReflectionClass(PhonebookRepository::class))->newInstanceWithoutConstructor();

    [$sql] = $repository->buildSearchCondition('Muster');

    Assert::contains('visible = 1', $sql);
});

function searchTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE phonebook (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identity_source_id INTEGER NOT NULL DEFAULT 0,
            display_name VARCHAR(120) NOT NULL DEFAULT \'\',
            first_name VARCHAR(64) NULL,
            last_name VARCHAR(64) NULL,
            phone VARCHAR(40) NULL,
            phone_digits VARCHAR(40) NULL,
            mobile VARCHAR(40) NULL,
            email VARCHAR(120) NULL,
            department VARCHAR(120) NULL,
            ad_modified VARCHAR(32) NULL,
            synced_at TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            visible INTEGER NOT NULL DEFAULT 1
        )'
    );

    return $pdo;
}

Runner::test('Vorname wird auch gefunden, wenn er nur im Anzeigenamen steht', static function (): void {
    $pdo = searchTestPdo();
    $pdo->exec("INSERT INTO phonebook (display_name, first_name, last_name, department, phone, phone_digits, email, active, visible) VALUES
        ('Harding, Ulf', NULL, 'Harding', 'Zentrum fuer Notfallmedizin', '3809', '3809', 'ulf@example.de', 1, 1),
        ('Muster, Erika', 'Erika', 'Muster', 'Verwaltung', '1234', '1234', 'erika@example.de', 1, 1)");

    $service = new PhonebookService(new PhonebookRepository($pdo));

    Assert::same(1, $service->search('Ulf')['total'], 'Vorname aus dem Anzeigenamen ("Nachname, Vorname") muss auffindbar sein.');
    Assert::same(1, $service->search('Harding Ulf')['total'], 'Nach- und Vorname kombiniert muessen den Eintrag finden.');
    Assert::same(1, $service->search('Notfallmedizin')['total'], 'Auch mehrteilige Bereiche werden am Wortanfang gefunden.');
    Assert::same(1, $service->search('Erika')['total'], 'Ein gefuelltes Feld first_name wird weiterhin per Praefix gefunden.');
    Assert::same(0, $service->search('ding')['total'], 'Teilwoerter innerhalb eines Namensbestandteils treffen nicht.');
});
