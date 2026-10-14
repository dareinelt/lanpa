<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Bestaetigungen in Orvanta laufen ueber den eigenen Overlay-Dialog und nicht
 * mehr ueber die Browser-Meldung window.confirm.
 */
Runner::test('Orvanta: Bestaetigungsdialog ist im App-Geruest vorhanden', static function (): void {
    $view = (string) file_get_contents(BASE_PATH . '/views/orvanta/index.php');

    Assert::contains('data-ov-dialog="confirm"', $view);
    Assert::contains('data-ov-confirm-title', $view);
    Assert::contains('data-ov-confirm-message', $view);
    Assert::contains('data-ov-confirm-accept', $view);
    Assert::contains('data-ov-dialog-close', $view);
    Assert::contains('button button--danger', $view);
});

Runner::test('Orvanta: confirmAction nutzt den Overlay statt window.confirm', static function (): void {
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/orvanta.js');

    Assert::false(str_contains($js, 'window.confirm('), 'Die Browser-Meldung wird noch verwendet');
    Assert::contains('function confirmAction(message, options)', $js);
    Assert::contains('data-ov-dialog="confirm"', $js);
    Assert::contains("openDialog('confirm');", $js);
});

Runner::test('Orvanta: jede Bestaetigung wartet das Ergebnis des Dialogs ab', static function (): void {
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/orvanta.js');

    $definitions = substr_count($js, 'function confirmAction(');
    $calls = substr_count($js, 'confirmAction(') - $definitions;
    $awaited = substr_count($js, ').then(function (confirmed) {');

    Assert::same(1, $definitions, 'confirmAction muss genau einmal definiert sein');
    Assert::true($calls >= 6, 'Die bekannten Aufrufe fehlen');
    Assert::same($calls, $awaited, 'Jeder Aufruf muss das Ergebnis des Dialogs auswerten');
});
