<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function tileTargetItem(array $overrides = []): array
{
    return $overrides + [
        'id' => 11,
        'type' => 'internal',
        'title' => 'KLINQ',
        'url' => '/klinq-starten',
        'icon' => 'wrench',
        'description' => '',
        'short_description' => '',
        'protected_access' => 0,
        'open_in_new_tab' => 0,
        'override_background' => 0,
        'background_color' => '',
        'background_opacity' => null,
    ];
}

/**
 * @param list<array<string,mixed>> $items
 */
function tileTargetRender(array $items): string
{
    $GLOBALS['csp_nonce'] = 'test-nonce';
    $descriptionMode = 'both';
    ob_start();
    require BASE_PATH . '/views/partials/tiles.php';

    return (string) ob_get_clean();
}

Runner::test('Kacheln oeffnen interne Ziele standardmaessig im selben Tab', static function (): void {
    $html = tileTargetRender([tileTargetItem()]);

    Assert::false(str_contains($html, 'target="_blank"'), 'Interne Kachel oeffnet unerwartet einen neuen Tab');
});

Runner::test('Interne Ziele oeffnen mit Option einen neuen Tab', static function (): void {
    $html = tileTargetRender([tileTargetItem(['open_in_new_tab' => 1])]);

    Assert::true(str_contains($html, 'target="_blank"'));
    Assert::true(str_contains($html, 'rel="noopener"'));
});

Runner::test('Externe Ziele oeffnen weiterhin immer einen neuen Tab', static function (): void {
    $html = tileTargetRender([tileTargetItem([
        'type' => 'external',
        'url' => 'https://example.invalid/',
        'open_in_new_tab' => 0,
    ])]);

    Assert::true(str_contains($html, 'target="_blank"'));
    Assert::true(str_contains($html, 'rel="noopener noreferrer"'));
});

Runner::test('Geschuetzte Kacheln geben den neuen Tab an das Skript weiter', static function (): void {
    $newTab = tileTargetRender([tileTargetItem(['protected_access' => 1, 'open_in_new_tab' => 1])]);
    $sameTab = tileTargetRender([tileTargetItem(['protected_access' => 1])]);

    Assert::true(str_contains($newTab, 'data-nav-new-tab="1"'));
    Assert::true(str_contains($sameTab, 'data-nav-new-tab="0"'));
});
