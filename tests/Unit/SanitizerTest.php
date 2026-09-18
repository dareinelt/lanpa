<?php

declare(strict_types=1);

use App\Support\Sanitizer;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('Erlaubte Formatierung bleibt erhalten', static function (): void {
    $html = '<h2>Überschrift</h2><p>Hallo <strong>Welt</strong> und <em>kursiv</em>.</p>'
        . '<ul><li>eins</li><li>zwei</li></ul><blockquote>Zitat</blockquote>';

    $result = Sanitizer::html($html);

    Assert::true(str_contains($result, '<h2>Überschrift</h2>'));
    Assert::true(str_contains($result, '<strong>Welt</strong>'));
    Assert::true(str_contains($result, '<em>kursiv</em>'));
    Assert::true(str_contains($result, '<ul><li>eins</li><li>zwei</li></ul>'));
    Assert::true(str_contains($result, '<blockquote>Zitat</blockquote>'));
});

Runner::test('Gefährliche Elemente und Attribute werden entfernt', static function (): void {
    $result = Sanitizer::html('<script>alert(1)</script><p onclick="x()">Text</p>'
        . '<img src=x onerror="alert(2)"><style>p{}</style><p>Rest</p>');

    Assert::false(str_contains($result, 'script'));
    Assert::false(str_contains($result, 'onclick'));
    Assert::false(str_contains($result, 'onerror'));
    Assert::false(str_contains($result, '<img'));
    Assert::false(str_contains($result, '<style'));
    Assert::true(str_contains($result, '<p>Text</p>'));
    Assert::true(str_contains($result, '<p>Rest</p>'));
});

Runner::test('Link-Ziele werden gefiltert', static function (): void {
    $external = Sanitizer::html('<a href="https://example.com">ok</a>');
    Assert::true(str_contains($external, 'target="_blank"'));
    Assert::true(str_contains($external, 'rel="noopener noreferrer"'));

    $internal = Sanitizer::html('<a href="/telefonliste">intern</a>');
    Assert::true(str_contains($internal, 'href="/telefonliste"'));

    $javascript = Sanitizer::html('<a href="javascript:alert(1)">böse</a>');
    Assert::false(str_contains($javascript, 'href='));

    $protocolRelative = Sanitizer::html('<a href="//evil.example.com">proto</a>');
    Assert::false(str_contains($protocolRelative, 'href='));
});

Runner::test('Leere Eingaben ergeben leere Ausgabe', static function (): void {
    Assert::same('', Sanitizer::html(''));
    Assert::same('', Sanitizer::html('   '));
});
