<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Security\Permissions;
use App\Support\ModuleNavigation;
use Tests\Support\TestCase;

final class ModuleNavigationTest extends TestCase
{
    /** @param list<string> $permissions */
    private function can(array $permissions): callable
    {
        return static fn (string $permission): bool => in_array($permission, $permissions, true);
    }

    /** @return list<string> Navigationsschlüssel (ohne Abschnittsüberschriften) */
    private function keys(array $items): array
    {
        return array_values(array_map(
            static fn (array $item): string => (string) $item['key'],
            array_filter($items, static fn (array $item): bool => $item['type'] === 'link')
        ));
    }

    public function testMapsNavKeysToModules(): void
    {
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleFor('dashboard'));
        $this->assertSame(ModuleNavigation::SYSTEM, ModuleNavigation::moduleFor('admin'));
        $this->assertSame(ModuleNavigation::SYSTEM, ModuleNavigation::moduleFor('reports'));
        $this->assertSame(ModuleNavigation::SYSTEM, ModuleNavigation::moduleFor('imports'));
        $this->assertSame(ModuleNavigation::SYSTEM, ModuleNavigation::moduleFor('audit'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleFor(''));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleFor('helpdesk'));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleFor('helpdesk-tickets'));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleFor('portal'));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleFor('portal-knowledge'));
    }

    public function testHelpdeskModuleOnlyWhenEnabledAndPermitted(): void
    {
        $assetAgent = $this->can(['dashboard.view', 'helpdesk.view']);
        $this->assertSame([ModuleNavigation::ASSETS, ModuleNavigation::HELPDESK], array_column(ModuleNavigation::modules($assetAgent, true), 'key'));
        $this->assertSame([ModuleNavigation::ASSETS], array_column(ModuleNavigation::modules($assetAgent, false), 'key'));
        $this->assertSame([ModuleNavigation::ASSETS], array_column(ModuleNavigation::modules($this->can(['assets.view']), true), 'key'));

        // Ohne KLINQ-Berechtigung (Rolle "portal") ausschließlich der eigene Help-Desk-Bereich.
        $this->assertSame([ModuleNavigation::HELPDESK], array_column(ModuleNavigation::modules($this->can(['portal.view']), true), 'key'));
        $this->assertSame([], array_column(ModuleNavigation::modules($this->can(['portal.view']), false), 'key'));
        $this->assertSame([], array_column(ModuleNavigation::modules($this->can([]), true), 'key'));
    }

    public function testPortalOnlyUserCannotEnterAssetModule(): void
    {
        $portal = $this->can(['portal.view', 'portal.create', 'knowledgebase.view']);

        $this->assertFalse(ModuleNavigation::canEnterAssets($portal));
        $this->assertFalse(ModuleNavigation::canEnter(ModuleNavigation::ASSETS, $portal));
        $this->assertTrue(ModuleNavigation::canEnter(ModuleNavigation::HELPDESK, $portal));
        $this->assertSame('/portal', ModuleNavigation::entryUrl('', $portal, true));
        $this->assertSame('/portal', ModuleNavigation::entryUrl(ModuleNavigation::ASSETS, $portal, true));
        $this->assertSame('/portal', ModuleNavigation::startUrl(ModuleNavigation::ASSETS, $portal, true));

        $assetUser = $this->can(['dashboard.view']);
        $this->assertTrue(ModuleNavigation::canEnterAssets($assetUser));
        $this->assertSame('/dashboard', ModuleNavigation::entryUrl('', $assetUser, true));
        $this->assertNull(ModuleNavigation::entryUrl('', $this->can([]), true));
    }

    public function testReadonlyRoleCannotReachTheAssetModule(): void
    {
        $permissions = new Permissions(new Config(dirname(__DIR__, 2) . '/config'));
        $can = static fn (string $permission): bool => $permissions->hasEffective('readonly', [], $permission);

        $this->assertFalse(ModuleNavigation::canEnterAssets($can), '„Nur lesen“ darf die Bereichsauswahl nicht in die Assetverwaltung öffnen');
        $this->assertSame([ModuleNavigation::HELPDESK], array_column(ModuleNavigation::modules($can, true), 'key'));
        $this->assertSame('/portal', ModuleNavigation::startUrl(ModuleNavigation::ASSETS, $can, true));
        $this->assertSame('/portal', ModuleNavigation::home(ModuleNavigation::HELPDESK, $can));
    }

    public function testSystemModuleOnlyVisibleForAdmins(): void
    {
        $admin = $this->can(['assets.view', 'settings.manage', 'reports.view', 'imports.manage', 'audit.view']);
        $this->assertSame(
            [ModuleNavigation::ASSETS, ModuleNavigation::SYSTEM],
            array_column(ModuleNavigation::modules($admin, false), 'key')
        );

        $nonAdmin = $this->can(['assets.view', 'reports.view', 'imports.manage', 'audit.view']);
        $this->assertSame([ModuleNavigation::ASSETS], array_column(ModuleNavigation::modules($nonAdmin, false), 'key'));
    }

    public function testEachModuleHasItsOwnDashboard(): void
    {
        $agent = $this->can(['dashboard.view', 'helpdesk.view']);
        $this->assertSame('/dashboard', ModuleNavigation::home(ModuleNavigation::ASSETS, $agent));
        $this->assertSame('/helpdesk', ModuleNavigation::home(ModuleNavigation::HELPDESK, $agent));
        $this->assertSame('/portal', ModuleNavigation::home(ModuleNavigation::HELPDESK, $this->can(['portal.view'])));
        $this->assertSame('/reports', ModuleNavigation::home(ModuleNavigation::SYSTEM, $this->can(['reports.view', 'settings.manage'])));
        $this->assertSame('/admin', ModuleNavigation::home(ModuleNavigation::SYSTEM, $this->can(['settings.manage'])));

        $assetItems = ModuleNavigation::items(ModuleNavigation::ASSETS, $agent);
        $this->assertSame('dashboard', $this->keys($assetItems)[0]);
        $helpdeskItems = ModuleNavigation::items(ModuleNavigation::HELPDESK, $agent);
        $this->assertSame('helpdesk', $this->keys($helpdeskItems)[0]);
        $this->assertSame('Dashboard', $helpdeskItems[0]['label']);
    }

    public function testAssetNavigationHidesEntriesWithoutPermission(): void
    {
        $keys = $this->keys(ModuleNavigation::items(ModuleNavigation::ASSETS, $this->can(['assets.view'])));

        $this->assertFalse(in_array('dashboard', $keys, true));
        $this->assertFalse(in_array('scan', $keys, true));
        $this->assertContains('assets', $keys);
    }

    public function testModulesShowOnlyTheirOwnNavigation(): void
    {
        $can = $this->can(['assets.view', 'movements.view', 'settings.manage', 'reports.view', 'imports.manage', 'audit.view', 'helpdesk.view', 'knowledgebase.view', 'helpdesk.reports', 'portal.view']);

        $assetKeys = $this->keys(ModuleNavigation::items(ModuleNavigation::ASSETS, $can));
        $this->assertContains('assets', $assetKeys);
        $this->assertFalse(in_array('admin', $assetKeys, true));
        $this->assertFalse(in_array('reports', $assetKeys, true));
        foreach ($assetKeys as $key) {
            $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleFor($key), 'Assetmodul enthält Fremdeintrag: ' . $key);
        }

        $helpdeskKeys = $this->keys(ModuleNavigation::items(ModuleNavigation::HELPDESK, $can));
        $this->assertContains('helpdesk-tickets', $helpdeskKeys);
        $this->assertContains('helpdesk-knowledge', $helpdeskKeys);
        foreach ($helpdeskKeys as $key) {
            $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleFor($key), 'Help Desk enthält Fremdeintrag: ' . $key);
        }

        $systemKeys = $this->keys(ModuleNavigation::items(ModuleNavigation::SYSTEM, $can));
        $this->assertSame(['reports', 'imports', 'audit', 'admin'], $systemKeys);
        foreach ($systemKeys as $key) {
            $this->assertSame(ModuleNavigation::SYSTEM, ModuleNavigation::moduleFor($key), 'Auswertung & System enthält Fremdeintrag: ' . $key);
        }
    }

    public function testPortalOnlyUserSeesPortalNavigation(): void
    {
        $keys = $this->keys(ModuleNavigation::items(ModuleNavigation::HELPDESK, $this->can(['portal.view', 'knowledgebase.view'])));
        $this->assertSame(['portal', 'portal-tickets', 'portal-knowledge'], $keys);
    }

    public function testBadgesComeFromOpenCounts(): void
    {
        $items = ModuleNavigation::items(ModuleNavigation::ASSETS, $this->can(['movements.view']), ['checkouts' => 2, 'returns' => 3]);
        $open = array_values(array_filter($items, static fn (array $i): bool => ($i['key'] ?? '') === 'open-checkouts'));
        $this->assertSame(5, $open[0]['badge']);

        $helpdesk = ModuleNavigation::items(ModuleNavigation::HELPDESK, $this->can(['helpdesk.view']), ['tickets' => 7]);
        $tickets = array_values(array_filter($helpdesk, static fn (array $i): bool => ($i['key'] ?? '') === 'helpdesk-tickets'));
        $this->assertSame(7, $tickets[0]['badge']);
    }

    public function testResolvesModuleFromRequestPath(): void
    {
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/dashboard'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/assets'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/assets/42/edit'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/m'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/movements/open'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/cost-centers/3'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/labels'));
        $this->assertSame(ModuleNavigation::ASSETS, ModuleNavigation::moduleForPath('/documents/12'));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleForPath('/helpdesk'));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleForPath('/helpdesk/tickets/7'));
        $this->assertSame(ModuleNavigation::HELPDESK, ModuleNavigation::moduleForPath('/portal/knowledge'));

        // Der Systembereich und neutrale Pfade (Profil, globale Suche, JSON-Endpunkte) ändern
        // die gemerkte Auswahl nicht.
        foreach (['/', '/profile', '/search', '/api/helpdesk/tickets/search', '/login', '/stoerung', '/reports', '/admin/users'] as $path) {
            $this->assertNull(ModuleNavigation::moduleForPath($path), 'Pfad sollte neutral sein: ' . $path);
        }
    }

    public function testStartUrlUsesLastModuleWhenAvailable(): void
    {
        $agent = $this->can(['dashboard.view', 'helpdesk.view']);
        $portal = $this->can(['portal.view']);

        $this->assertSame('/helpdesk', ModuleNavigation::startUrl(ModuleNavigation::HELPDESK, $agent, true));
        $this->assertSame('/portal', ModuleNavigation::startUrl(ModuleNavigation::HELPDESK, $portal, true));
        $this->assertSame('/dashboard', ModuleNavigation::startUrl(ModuleNavigation::HELPDESK, $agent, false));
        $this->assertSame('/dashboard', ModuleNavigation::startUrl(ModuleNavigation::HELPDESK, $this->can([]), true));
        $this->assertSame('/dashboard', ModuleNavigation::startUrl(ModuleNavigation::ASSETS, $agent, true));
        $this->assertSame('/dashboard', ModuleNavigation::startUrl(ModuleNavigation::SYSTEM, $agent, true));
        $this->assertSame('/dashboard', ModuleNavigation::startUrl('', $agent, true));
        // Ohne Assetrechte bleibt das Portal das Ziel, auch wenn das zuletzt geöffnete Modul
        // die Assetverwaltung war.
        $this->assertSame('/portal', ModuleNavigation::startUrl(ModuleNavigation::ASSETS, $portal, true));
    }

    public function testDisabledHelpdeskHasNoItems(): void
    {
        $this->assertSame([], ModuleNavigation::items(ModuleNavigation::HELPDESK, $this->can(['helpdesk.view']), [], false));
    }
}
