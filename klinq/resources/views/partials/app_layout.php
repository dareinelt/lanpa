<?php
/**
 * App-Layout (Desktop): Sidebar + Topbar.
 *
 * Die Oberfläche ist in eigenständige Module aufgeteilt (Assetverwaltung, Help Desk). Das aktive
 * Modul ergibt sich aus $activeNav (oder wird per $activeModule überschrieben) und bestimmt, welche
 * Navigationseinträge sichtbar sind. Gewechselt wird über das Modul-Dropdown in der Topbar.
 *
 * Erwartet: $innerContent, $title, $activeNav, $user, $can (callable), optional $scripts, $areaLabel
 */
require_once __DIR__ . '/helpers.php';

$user = $user ?? [];
$activeNav = $activeNav ?? '';
$appName = $appName ?? 'KLINQ';
$can = $can ?? static fn (string $p): bool => false;
$roleLabels = $roleLabels ?? [];
$openCounts = $openCounts ?? ['checkouts' => 0, 'returns' => 0];
$helpdeskEnabled = $helpdeskEnabled ?? false;
$helpdeskNotifications = $helpdeskNotifications ?? null;
$showNotifications = $helpdeskNotifications !== null && $helpdeskNotifications->visible();
$notificationItems = $showNotifications ? $helpdeskNotifications->all() : [];

$modules = \App\Support\ModuleNavigation::modules($can, $helpdeskEnabled);
$activeModule = $activeModule ?? \App\Support\ModuleNavigation::moduleFor($activeNav);
if (!in_array($activeModule, array_column($modules, 'key'), true)) {
    $activeModule = \App\Support\ModuleNavigation::ASSETS;
}
$moduleLabel = \App\Support\ModuleNavigation::label($activeModule);
$moduleIcon = match ($activeModule) {
    \App\Support\ModuleNavigation::HELPDESK => 'lifebuoy',
    \App\Support\ModuleNavigation::SYSTEM => 'chart',
    default => 'box',
};
$moduleHome = \App\Support\ModuleNavigation::home($activeModule, $can);
$navItems = \App\Support\ModuleNavigation::items($activeModule, $can, $openCounts, $helpdeskEnabled);

$areaClass = match (true) {
    $activeNav === 'admin' => 'is-admin',
    str_starts_with($activeNav, 'helpdesk') => 'is-helpdesk',
    str_starts_with($activeNav, 'portal') => 'is-portal',
    default => '',
};
// Der Modulname steht bereits im Dropdown – nur davon abweichende Bereiche zusätzlich anzeigen.
$showAreaLabel = !empty($areaLabel) && $areaLabel !== $moduleLabel;

ob_start();
?>
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="sidebar-brand" href="<?= e($moduleHome) ?>">
            <span class="brand-logo" aria-hidden="true"><?= icon($moduleIcon) ?></span>
            <span><?= e($moduleLabel) ?></span>
        </a>
        <nav class="sidebar-nav" aria-label="Hauptnavigation">
            <?php foreach ($navItems as $item): ?>
                <?php if ($item['type'] === 'section'): ?>
                    <span class="sidebar-section-label"><?= e($item['label']) ?></span>
                <?php else: ?>
                    <?php $isActive = $activeNav === $item['key']; ?>
                    <a href="<?= e($item['href']) ?>" class="<?= $isActive ? 'is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                        <?= icon($item['icon']) ?><span><?= e($item['label']) ?></span>
                        <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-block">
                    <?= icon('logout') ?>
                    Abmelden
                </button>
            </form>
        </div>
    </aside>
    <header class="topbar">
        <button type="button" class="topbar-menu-toggle" id="sidebar-toggle" aria-label="Navigation öffnen" aria-expanded="false" aria-controls="sidebar">
            <?= icon('menu') ?>
        </button>
        <?php if (!empty($showAreaLabel)): ?>
            <span class="topbar-area-label <?= $areaClass ?>"><?= e($areaLabel) ?></span>
        <?php endif; ?>
        <div class="search-box topbar-search" id="global-search">
            <?= icon('search') ?>
            <label for="global-search-input" class="visually-hidden">Globale Suche</label>
            <input id="global-search-input" type="search" placeholder="Inventarnr., Seriennr., Mitarbeiter, Standort …" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="global-search-results" enterkeyhint="search">
            <ul class="search-results" id="global-search-results" hidden></ul>
        </div>
        <div class="topbar-spacer"></div>
        <?php if (count($modules) > 1): ?>
            <details class="module-switcher" data-module-switcher>
                <summary class="module-switcher-toggle" title="Bereich wechseln" aria-label="Bereich wechseln">
                    <span class="module-switcher-icon is-<?= e($activeModule) ?>" aria-hidden="true"><?= icon($moduleIcon) ?></span>
                    <span class="module-switcher-current">
                        <span class="module-switcher-hint">Bereich</span>
                        <span class="module-switcher-name"><?= e($moduleLabel) ?></span>
                    </span>
                    <?= icon('chevron-down', 'icon module-switcher-caret') ?>
                </summary>
                <div class="module-switcher-menu" role="menu">
                    <?php foreach ($modules as $module): ?>
                        <?php $isCurrent = $module['key'] === $activeModule; ?>
                        <a href="<?= e($module['href']) ?>" role="menuitem" class="module-switcher-item <?= $isCurrent ? 'is-active' : '' ?>"<?= $isCurrent ? ' aria-current="true"' : '' ?>>
                            <span class="module-switcher-icon is-<?= e($module['key']) ?>" aria-hidden="true"><?= icon($module['icon']) ?></span>
                            <span class="module-switcher-text">
                                <span class="module-switcher-name"><?= e($module['label']) ?></span>
                                <span class="module-switcher-description"><?= e($module['description']) ?></span>
                            </span>
                            <?php if ($isCurrent): ?><?= icon('check', 'icon module-switcher-check') ?><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <div class="topbar-spacer"></div>
        <span class="offline-indicator" id="offline-indicator" hidden><?= icon('offline') ?> Offline</span>
        <?php if ($showNotifications): ?>
        <details class="notification-bell" data-notification-bell>
            <summary class="notification-bell-toggle" title="Benachrichtigungen" aria-label="Benachrichtigungen">
                <?= icon('bell') ?>
                <?php if ($notificationItems !== []): ?><span class="notification-badge"><?= count($notificationItems) ?></span><?php endif; ?>
            </summary>
            <div class="notification-menu" role="menu" aria-label="Offene Tickets (2nd Level)">
                <div class="notification-menu-header">Offene Tickets (2nd Level)</div>
                <?php if ($notificationItems === []): ?>
                    <div class="notification-empty">Keine offenen Tickets.</div>
                <?php else: ?>
                    <?php foreach ($notificationItems as $n): ?>
                        <a class="notification-item" href="<?= e($n['url']) ?>" role="menuitem">
                            <span class="notification-number"><?= e($n['number']) ?></span>
                            <span class="notification-subject"><?= e($n['subject']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
        <a class="topbar-user" href="/profile/password" title="Passwort ändern">
            <span class="topbar-user-name"><?= e($user['display_name'] ?? $user['username'] ?? '') ?> · <?= e($roleLabels[$user['role'] ?? ''] ?? ($user['role'] ?? '')) ?></span>
            <span class="avatar" aria-hidden="true"><?= e(mb_substr((string) ($user['display_name'] ?? $user['username'] ?? '?'), 0, 1)) ?></span>
        </a>
    </header>
    <main class="main-content" id="main">
        <?php include __DIR__ . '/flash.php'; ?>
        <?= $innerContent ?? '' ?>
    </main>
</div>
<?php
unset($_SESSION['_old_input'], $_SESSION['_errors']);
$content = ob_get_clean();
$moduleTitle = $moduleLabel;
$scripts = array_merge(['/js/search.js', '/js/pwa.js'], $scripts ?? []);
include __DIR__ . '/layout.php';
