<?php
/**
 * Mobile Layout (/m): schlanke Kopfzeile, große Touch-Ziele, Bottom-Navigation.
 * Erwartet: $innerContent, $title; optional $activeNav (scan|open|desktop), $backHref, $scripts
 */
require_once __DIR__ . '/helpers.php';
$can = $can ?? static fn (string $p): bool => false;
$activeNav = $activeNav ?? 'scan';
$tab = static fn (string $key, string $href, string $iconName, string $label): string =>
    '<a href="' . e($href) . '" class="' . ($activeNav === $key ? 'is-active' : '') . '"' . ($activeNav === $key ? ' aria-current="page"' : '') . '>' . icon($iconName) . '<span>' . e($label) . '</span></a>';
ob_start();
?>
<div class="m-shell">
    <header class="m-topbar">
        <?php if (!empty($backHref)): ?>
            <a class="m-topbar-btn" href="<?= e($backHref) ?>" aria-label="Zurück"><?= icon('arrow-left') ?></a>
        <?php else: ?>
            <a class="m-topbar-btn" href="/dashboard" aria-label="Zum Dashboard"><?= icon('home') ?></a>
        <?php endif; ?>
        <h1 class="m-topbar-title"><?= e($title ?? 'Erfassung') ?></h1>
        <span class="offline-indicator m-offline" id="offline-indicator" hidden><?= icon('offline') ?> Offline</span>
        <a class="m-topbar-btn m-queue-badge" href="/m#offline-queue" title="Ausstehende Vorgänge" aria-label="Ausstehende Vorgänge" data-queue-count hidden>0</a>
        <span class="m-topbar-user" title="<?= e($user['display_name'] ?? '') ?>"><?= e(mb_substr((string) ($user['display_name'] ?? $user['username'] ?? '?'), 0, 1)) ?></span>
    </header>
    <main class="m-content" id="main">
        <?php include __DIR__ . '/flash.php'; ?>
        <?= $innerContent ?? '' ?>
    </main>
    <nav class="m-tabbar" aria-label="Mobile Navigation">
        <?= $tab('scan', '/m', 'qr', 'Scannen') ?>
        <?php if ($can('movements.view')): ?><?= $tab('open', '/m/open', 'clock', 'Offen') ?><?php endif; ?>
        <?php if ($can('handover.sign')): ?><?= $tab('handover', '/m/handover', 'signature', 'Protokolle') ?><?php endif; ?>
        <?= $tab('desktop', '/dashboard', 'laptop', 'Desktop') ?>
    </nav>
</div>
<?php
unset($_SESSION['_old_input'], $_SESSION['_errors']);
$content = ob_get_clean();
$moduleTitle = \App\Support\ModuleNavigation::label(\App\Support\ModuleNavigation::ASSETS);
$bodyClass = trim(($bodyClass ?? '') . ' m-body');
$scripts = array_merge(['/js/offline.js', '/js/pwa.js'], $scripts ?? []);
include __DIR__ . '/layout.php';
