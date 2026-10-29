<?php

declare(strict_types=1);

namespace App\Support;

use ArrayAccess;

/**
 * Modulstruktur der Anwendung.
 *
 * Die Oberfläche ist in eigenständige Module aufgeteilt (Assetverwaltung, Help Desk,
 * Auswertung & System). Jedes Modul besitzt eine eigene Navigation; in der Topbar wird
 * über ein Dropdown zwischen den Modulen gewechselt. Das Modul "Auswertung & System"
 * (Berichte, Import, Audit-Log, Administration) ist dort nur für Admins sichtbar (siehe
 * modules()). Diese Klasse ist die einzige Quelle für Modulzuordnung und
 * Navigationseinträge (siehe partials/app_layout.php).
 *
 * Zusätzlich wird je Benutzer das zuletzt geöffnete Modul gemerkt: moduleForPath()
 * ordnet einen Anfragepfad zu, startUrl() liefert daraus die Startseite beim nächsten
 * Öffnen der Anwendung.
 */
final class ModuleNavigation
{
    public const ASSETS = 'assets';
    public const HELPDESK = 'helpdesk';
    public const SYSTEM = 'system';

    /** Navigationsschlüssel, die zum Help-Desk-Modul gehören. */
    private const HELPDESK_PREFIXES = ['helpdesk', 'portal'];

    /** Navigationsschlüssel, die zum Modul "Auswertung & System" gehören (nur für Admins). */
    private const SYSTEM_KEYS = ['reports', 'imports', 'audit', 'admin'];

    /**
     * Rechte, die den Zugang zur Assetverwaltung freigeben.
     *
     * Die Rolle "Serviceportal" enthält bewusst keines dieser Rechte: Mitarbeitende ohne
     * ausdrückliche KLINQ-Berechtigung sehen ausschließlich ihren eigenen Help-Desk-Bereich
     * und dürfen die Assetverwaltung auch nicht lesend öffnen.
     */
    private const ASSET_PERMISSIONS = [
        'dashboard.view',
        'assets.view',
        'movements.view',
        'handover.view',
        'licenses.view',
        'orders.view',
        'suppliers.view',
        'employees.view',
        'locations.view',
        'costcenters.view',
        'manufacturers.view',
        'articles.view',
        'documents.view',
    ];

    /**
     * Pfadpräfixe der beiden merkbaren Module (Assetverwaltung, Help Desk).
     *
     * Nur eindeutig zuzuordnende Seiten stehen hier; neutrale Pfade (Profil, globale Suche,
     * JSON-Endpunkte) und der Systembereich (Berichte, Import, Audit, Administration) ändern
     * die Auswahl bewusst nicht.
     */
    private const MODULE_PATHS = [
        '/helpdesk' => self::HELPDESK,
        '/portal' => self::HELPDESK,
        '/dashboard' => self::ASSETS,
        '/m' => self::ASSETS,
        '/assets' => self::ASSETS,
        '/movements' => self::ASSETS,
        '/handover' => self::ASSETS,
        '/documents' => self::ASSETS,
        '/labels' => self::ASSETS,
        '/licenses' => self::ASSETS,
        '/orders' => self::ASSETS,
        '/suppliers' => self::ASSETS,
        '/employees' => self::ASSETS,
        '/locations' => self::ASSETS,
        '/cost-centers' => self::ASSETS,
        '/manufacturers' => self::ASSETS,
        '/articles' => self::ASSETS,
    ];

    /** Ermittelt das Modul zu einem Navigationsschlüssel ($activeNav). */
    public static function moduleFor(string $activeNav): string
    {
        foreach (self::HELPDESK_PREFIXES as $prefix) {
            if ($activeNav === $prefix || str_starts_with($activeNav, $prefix . '-')) {
                return self::HELPDESK;
            }
        }

        if (in_array($activeNav, self::SYSTEM_KEYS, true)) {
            return self::SYSTEM;
        }

        return self::ASSETS;
    }

    /**
     * Modul zu einem Anfragepfad; null, wenn der Pfad zu keinem Modul gehört.
     *
     * Grundlage für das Merken des zuletzt geöffneten Moduls je Benutzer.
     */
    public static function moduleForPath(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return null;
        }

        foreach (self::MODULE_PATHS as $prefix => $module) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Startseite beim nächsten Öffnen der Anwendung.
     *
     * Das zuletzt geöffnete Modul gewinnt, sofern es für den Benutzer verfügbar ist
     * (Modulschalter und Berechtigung); sonst greift das erste verfügbare Modul.
     * Ohne jedes Modulrecht bleibt Assetverwaltung der Standard (Anmeldung ohne Rechte).
     */
    public static function startUrl(string $lastModule, callable $can, bool $helpdeskEnabled): string
    {
        return self::entryUrl($lastModule, $can, $helpdeskEnabled) ?? self::home(self::ASSETS, $can);
    }

    /**
     * Erstes für den Benutzer erreichbares Ziel; null, wenn kein Modul verfügbar ist.
     *
     * Grundlage für Weiterleitungen nach der Anmeldung und für den Rückweg auf
     * Fehlerseiten (z. B. "Zum Help-Desk-Portal" statt "Zum Dashboard").
     */
    public static function entryUrl(string $lastModule, callable $can, bool $helpdeskEnabled): ?string
    {
        if ($lastModule !== '' && self::canEnter($lastModule, $can, $helpdeskEnabled)) {
            return self::home($lastModule, $can);
        }

        foreach ([self::ASSETS, self::HELPDESK, self::SYSTEM] as $module) {
            if (self::canEnter($module, $can, $helpdeskEnabled)) {
                return self::home($module, $can);
            }
        }

        return null;
    }

    /**
     * Darf der Benutzer das Modul betreten?
     *
     * Die Assetverwaltung ist an ausdrückliche Leserechte gebunden (siehe
     * ASSET_PERMISSIONS); ein Portalbenutzer ohne KLINQ-Berechtigung erhält sie nicht.
     */
    public static function canEnter(string $module, callable $can, bool $helpdeskEnabled = true): bool
    {
        return match ($module) {
            self::ASSETS => self::canEnterAssets($can),
            self::HELPDESK => $helpdeskEnabled && ($can('helpdesk.view') || $can('portal.view')),
            self::SYSTEM => $can('settings.manage'),
            default => false,
        };
    }

    /** Mindestens ein Leserecht der Assetverwaltung. */
    public static function canEnterAssets(callable $can): bool
    {
        foreach (self::ASSET_PERMISSIONS as $permission) {
            if ($can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function label(string $module): string
    {
        return match ($module) {
            self::HELPDESK => 'Help Desk',
            self::SYSTEM => 'Auswertung & System',
            default => 'Assetverwaltung',
        };
    }

    /** Startseite (Dashboard) eines Moduls für den aktuellen Benutzer. */
    public static function home(string $module, callable $can): string
    {
        if ($module === self::HELPDESK) {
            return $can('helpdesk.view') ? '/helpdesk' : '/portal';
        }

        if ($module === self::SYSTEM) {
            return match (true) {
                $can('reports.view') => '/reports',
                $can('imports.manage') => '/imports',
                $can('audit.view') => '/audit',
                default => '/admin',
            };
        }

        return '/dashboard';
    }

    /**
     * Module, die der aktuelle Benutzer betreten darf.
     *
     * @return list<array{key:string,label:string,icon:string,href:string,description:string}>
     */
    public static function modules(callable $can, bool $helpdeskEnabled): array
    {
        $modules = [];

        // Nur mit ausdrücklichem Leserecht: das Serviceportal sieht keine Assetverwaltung.
        if (self::canEnterAssets($can)) {
            $modules[] = [
                'key' => self::ASSETS,
                'label' => self::label(self::ASSETS),
                'icon' => 'box',
                'href' => self::home(self::ASSETS, $can),
                'description' => 'Assets, Bewegungen und Stammdaten',
            ];
        }

        if ($helpdeskEnabled && ($can('helpdesk.view') || $can('portal.view'))) {
            $modules[] = [
                'key' => self::HELPDESK,
                'label' => self::label(self::HELPDESK),
                'icon' => 'lifebuoy',
                'href' => self::home(self::HELPDESK, $can),
                'description' => $can('helpdesk.view') ? 'Tickets, Wissensdatenbank und Berichte' : 'Eigene Tickets und Anleitungen',
            ];
        }

        // Nur für Admins sichtbar (settings.manage wird ausschließlich der Rolle "admin" zugewiesen).
        if ($can('settings.manage')) {
            $modules[] = [
                'key' => self::SYSTEM,
                'label' => self::label(self::SYSTEM),
                'icon' => 'chart',
                'href' => self::home(self::SYSTEM, $can),
                'description' => 'Berichte, Import, Audit-Log und Administration',
            ];
        }

        return $modules;
    }

    /**
     * Navigationseinträge eines Moduls.
     *
     * Rückgabe ist eine flache Liste aus Abschnittsüberschriften und Links:
     *   ['type' => 'section', 'label' => string]
     *   ['type' => 'link', 'key' => string, 'href' => string, 'icon' => string, 'label' => string, 'badge' => int|null]
     *
     * @param ArrayAccess<string,int>|array<string,int> $openCounts
     * @return list<array<string,mixed>>
     */
    public static function items(string $module, callable $can, ArrayAccess|array $openCounts = [], bool $helpdeskEnabled = true): array
    {
        $count = static fn (string $key): int => (int) ($openCounts[$key] ?? 0);

        return match ($module) {
            self::HELPDESK => self::helpdeskItems($can, $count, $helpdeskEnabled),
            self::SYSTEM => self::systemItems($can),
            default => self::assetItems($can, $count),
        };
    }

    /** @return list<array<string,mixed>> */
    private static function assetItems(callable $can, callable $count): array
    {
        $items = [];

        if ($can('dashboard.view')) {
            $items[] = self::link('dashboard', '/dashboard', 'home', 'Dashboard');
        }
        if ($can('movements.view')) {
            $items[] = self::link('scan', '/m', 'qr', 'Scannen');
        }

        if ($can('assets.view')) {
            $items[] = self::section('Bestand');
            $items[] = self::link('assets', '/assets', 'laptop', 'Assets');
        }
        if ($can('movements.view')) {
            $items[] = self::link('open-checkouts', '/movements/open', 'warning', 'Offene Vorgänge', $count('checkouts') + $count('returns'));
            $items[] = self::link('movements', '/movements', 'swap', 'Bewegungen');
        }
        if ($can('handover.view')) {
            $items[] = self::link('handover', '/handover', 'signature', 'Übergabeprotokolle');
        }
        if ($can('licenses.view')) {
            $items[] = self::link('licenses', '/licenses', 'key', 'Lizenzen');
        }

        if ($can('orders.view') || $can('suppliers.view')) {
            $items[] = self::section('Einkauf');
            if ($can('orders.view')) {
                $items[] = self::link('orders', '/orders', 'cart', 'Bestellungen');
            }
            if ($can('suppliers.view')) {
                $items[] = self::link('suppliers', '/suppliers', 'truck', 'Lieferanten');
            }
        }

        $items[] = self::section('Stammdaten');
        if ($can('employees.view')) {
            $items[] = self::link('employees', '/employees', 'users', 'Mitarbeiter');
        }
        if ($can('locations.view')) {
            $items[] = self::link('locations', '/locations', 'map', 'Standorte');
        }
        if ($can('costcenters.view')) {
            $items[] = self::link('costcenters', '/cost-centers', 'hash', 'Kostenstellen');
        }
        if ($can('manufacturers.view')) {
            $items[] = self::link('manufacturers', '/manufacturers', 'factory', 'Hersteller');
        }
        if ($can('articles.view')) {
            $items[] = self::link('articles', '/articles', 'tag', 'Artikel');
        }

        return $items;
    }

    /**
     * Navigationseinträge des Moduls "Auswertung & System" (nur für Admins über das
     * Kopfzeilen-Dropdown erreichbar, siehe modules()).
     *
     * @return list<array<string,mixed>>
     */
    private static function systemItems(callable $can): array
    {
        $items = [];

        if ($can('reports.view')) {
            $items[] = self::link('reports', '/reports', 'chart', 'Berichte');
        }
        if ($can('imports.manage')) {
            $items[] = self::link('imports', '/imports', 'import', 'Import');
        }
        if ($can('audit.view')) {
            $items[] = self::link('audit', '/audit', 'shield', 'Audit-Log');
        }
        if ($can('settings.manage')) {
            $items[] = self::link('admin', '/admin', 'settings', 'Administration');
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private static function helpdeskItems(callable $can, callable $count, bool $helpdeskEnabled): array
    {
        if (!$helpdeskEnabled) {
            return [];
        }

        if ($can('helpdesk.view')) {
            $items = [
                self::link('helpdesk', '/helpdesk', 'home', 'Dashboard'),
                self::section('Tickets'),
                self::link('helpdesk-tickets', '/helpdesk/tickets', 'ticket', 'Tickets', $count('tickets')),
            ];
            if ($can('knowledgebase.view')) {
                $items[] = self::link('helpdesk-knowledge', '/helpdesk/knowledge', 'book', 'Wissensdatenbank');
            }
            if ($can('helpdesk.tasks')) {
                $items[] = self::section('Aufgaben');
                $items[] = self::link('helpdesk-aufgaben', '/helpdesk/aufgaben', 'inbox', 'Alle Aufgaben');
                $items[] = self::link('helpdesk-aufgaben-meine', '/helpdesk/aufgaben/meine', 'user', 'Meine Aufgaben', $count('tasks'));
            }
            if ($can('helpdesk.reports') || $can('helpdesk.categories') || $can('helpdesk.admin')) {
                $items[] = self::section('Auswertung & System');
                if ($can('helpdesk.reports')) {
                    $items[] = self::link('helpdesk-reports', '/helpdesk/reports', 'chart', 'Ticket-Berichte');
                }
                if ($can('helpdesk.categories') || $can('helpdesk.admin')) {
                    $items[] = self::link('helpdesk-admin', '/helpdesk/admin', 'settings', 'Help-Desk-Einstellungen');
                }
            }
            if ($can('portal.view')) {
                $items[] = self::section('Support');
                $items[] = self::link('portal', '/portal', 'lifebuoy', 'Meine Tickets', $count('portal_tickets'));
            }

            return $items;
        }

        if (!$can('portal.view')) {
            return [];
        }

        $items = [
            self::link('portal', '/portal', 'home', 'Dashboard'),
            self::link('portal-tickets', '/portal/tickets', 'ticket', 'Meine Tickets', $count('portal_tickets')),
        ];
        if ($can('knowledgebase.view')) {
            $items[] = self::link('portal-knowledge', '/portal/knowledge', 'book', 'Hilfe & Anleitungen');
        }

        return $items;
    }

    /** @return array<string,mixed> */
    private static function section(string $label): array
    {
        return ['type' => 'section', 'label' => $label];
    }

    /** @return array<string,mixed> */
    private static function link(string $key, string $href, string $icon, string $label, ?int $badge = null): array
    {
        return ['type' => 'link', 'key' => $key, 'href' => $href, 'icon' => $icon, 'label' => $label, 'badge' => $badge];
    }
}
