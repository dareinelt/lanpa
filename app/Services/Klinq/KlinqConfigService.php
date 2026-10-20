<?php

declare(strict_types=1);

namespace App\Services\Klinq;

use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Konfiguration und Freigaben der KLINQ-Integration.
 *
 * Die Zuweisung erfolgt ueber AD-Gruppen im Adminbereich. KLINQ kennt eigene
 * Rollen und Berechtigungsgruppen; diese werden aus den AD-Gruppen abgeleitet
 * und beim Einstieg an KLINQ uebergeben.
 *
 * Der Help-Desk-Schnellbericht (/stoerung) ist bewusst fuer jede ueber SSO
 * angemeldete Person freigegeben – ohne Gruppenzuordnung.
 */
final class KlinqConfigService
{
    public const DEFAULT_PATH = '/klinq';
    public const ENTRY_PATH = '/klinq-starten';
    public const REPORT_PATH = '/stoerung';
    public const SSO_ROUTE = '/sso/lanpa';

    /** Rolle => Gewicht; die hoechste zutreffende Rolle gewinnt. */
    public const ROLES = [
        'admin' => 70,
        'helpdesk_admin' => 60,
        'helpdesk_lead' => 50,
        'helpdesk_agent' => 40,
        'assetmanagement' => 30,
        'readonly' => 10,
    ];

    /** @var array<string,string> */
    public const ROLE_LABELS = [
        'admin' => 'Administrator',
        'helpdesk_admin' => 'Help-Desk-Administration',
        'helpdesk_lead' => 'Help-Desk-Leitung',
        'helpdesk_agent' => 'Help-Desk-Agent',
        'assetmanagement' => 'Asset-Management',
        'readonly' => 'Nur Lesen',
    ];

    /** @var array<string,string> */
    public const AREA_LABELS = [
        'helpdesk' => 'Help Desk',
        'assets' => 'Asset-Management',
        'statistics' => 'Statistiken',
        'tickets' => 'Ticketaufruf',
        'admin' => 'Administration',
    ];

    /**
     * Einstellungsschluessel => Rolle, Berechtigungsgruppe und Bereich.
     *
     * @var array<string,array{label:string,role:string,permission:string,area:string}>
     */
    public const GROUP_KEYS = [
        'klinq_admin_groups' => [
            'label' => 'Administration (Vollzugriff)',
            'role' => 'admin',
            'permission' => '',
            'area' => 'admin',
        ],
        'klinq_helpdesk_admin_groups' => [
            'label' => 'Help-Desk-Administration',
            'role' => 'helpdesk_admin',
            'permission' => '',
            'area' => 'helpdesk',
        ],
        'klinq_helpdesk_lead_groups' => [
            'label' => 'Help-Desk-Leitung',
            'role' => 'helpdesk_lead',
            'permission' => '',
            'area' => 'helpdesk',
        ],
        'klinq_helpdesk_agent_groups' => [
            'label' => 'Help-Desk-Agenten',
            'role' => 'helpdesk_agent',
            'permission' => '',
            'area' => 'helpdesk',
        ],
        'klinq_asset_groups' => [
            'label' => 'Asset-Management',
            'role' => 'assetmanagement',
            'permission' => '',
            'area' => 'assets',
        ],
        'klinq_statistics_groups' => [
            'label' => 'Statistiken (Auswertungen)',
            'role' => '',
            'permission' => 'statistik',
            'area' => 'statistics',
        ],
        'klinq_ticket_history_groups' => [
            'label' => 'Ticketaufruf (Verlauf)',
            'role' => '',
            'permission' => 'ticketaufruf',
            'area' => 'tickets',
        ],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        /** @var array<string,mixed> */
        private readonly array $config = []
    ) {
    }

    /**
     * Vorgabewerte der KLINQ-Einstellungen (werden von SettingsService::defaults() uebernommen).
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = [
            'klinq_default_role' => 'readonly',
            'klinq_quick_report_open' => '1',
        ];

        foreach (array_keys(self::GROUP_KEYS) as $key) {
            $defaults[$key] = '';
        }

        return $defaults + KlinqAiService::defaults();
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function publicPath(): string
    {
        return self::normalizePath((string) ($this->config['public_path'] ?? self::DEFAULT_PATH));
    }

    /**
     * Ziel des signierten Einstiegs im KLINQ-Container.
     */
    public function entryUrl(): string
    {
        return $this->publicUrl() . self::SSO_ROUTE;
    }

    /**
     * Oeffentliche Basisadresse von KLINQ.
     *
     * Bevorzugt KLINQ_PUBLIC_URL (eigener Hostname hinter dem auth-Container);
     * ohne Angabe wird der Unterpfad verwendet.
     */
    public function publicUrl(): string
    {
        $url = trim((string) ($this->config['public_url'] ?? ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        return $this->publicPath();
    }

    /**
     * Hostname aus KLINQ_PUBLIC_URL (fuer die Diagnose).
     */
    public function publicHost(): string
    {
        $host = parse_url($this->publicUrl(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    public function upstream(): string
    {
        return rtrim((string) ($this->config['upstream'] ?? ''), '/');
    }

    /**
     * Bekannte interne Ziele fuer die Auswahl im Navigationsformular
     * (Pfad => Bezeichnung). Nur verfuegbar, wenn KLINQ aktiviert ist.
     *
     * @return array<string,string>
     */
    public function internalTargets(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        return [
            self::REPORT_PATH => 'KLINQ-Meldeseite (Störmeldung IT)',
        ];
    }

    public function healthUrl(): string
    {
        return (string) ($this->config['health_url'] ?? '');
    }

    public function healthTimeout(): int
    {
        return max(1, (int) ($this->config['health_timeout'] ?? 4));
    }

    public function healthCacheTtl(): int
    {
        return max(5, (int) ($this->config['health_cache_ttl'] ?? 30));
    }

    public function healthCacheFile(): string
    {
        return (string) ($this->config['health_cache_file'] ?? '');
    }

    public function secret(): string
    {
        return (string) ($this->config['sso_secret'] ?? '');
    }

    public function hasSecret(): bool
    {
        return strlen($this->secret()) >= 32;
    }

    public function entryLifetime(): int
    {
        return max(30, (int) ($this->config['entry_lifetime'] ?? 300));
    }

    public function quickReportOpen(): bool
    {
        return $this->settings->bool('klinq_quick_report_open', true);
    }

    public function defaultRole(): string
    {
        $role = (string) $this->settings->get('klinq_default_role', 'readonly');

        return isset(self::ROLES[$role]) ? $role : 'readonly';
    }

    /**
     * Zugewiesene AD-Gruppen einer Einstellung.
     *
     * @return list<string>
     */
    public function groups(string $key): array
    {
        if (!isset(self::GROUP_KEYS[$key])) {
            return [];
        }

        return self::parseGroups((string) $this->settings->get($key, ''));
    }

    /**
     * @return array<string,list<string>>
     */
    public function allGroups(): array
    {
        $groups = [];
        foreach (array_keys(self::GROUP_KEYS) as $key) {
            $groups[$key] = $this->groups($key);
        }

        return $groups;
    }

    /**
     * Leitet Rolle, Berechtigungsgruppen und Bereiche aus den AD-Gruppen ab.
     *
     * @param list<mixed> $adGroups
     * @return array{
     *     role:string,
     *     role_label:string,
     *     permission_groups:list<string>,
     *     areas:list<string>,
     *     area_labels:list<string>,
     *     matched:list<string>,
     *     elevated:bool
     * }
     */
    public function resolve(array $adGroups): array
    {
        $normalized = [];
        foreach ($adGroups as $group) {
            $name = self::normalizeGroup((string) $group);
            if ($name !== '') {
                $normalized[strtolower($name)] = true;
            }
        }

        $role = $this->defaultRole();
        $permissions = [];
        $areas = [];
        $matched = [];

        foreach (self::GROUP_KEYS as $key => $definition) {
            $configured = $this->groups($key);
            if ($configured === []) {
                continue;
            }

            foreach ($configured as $group) {
                if (!isset($normalized[strtolower($group)])) {
                    continue;
                }

                $matched[] = $key;

                if ($definition['role'] !== ''
                    && (self::ROLES[$definition['role']] ?? 0) > (self::ROLES[$role] ?? 0)
                ) {
                    $role = $definition['role'];
                }

                if ($definition['permission'] !== '' && !in_array($definition['permission'], $permissions, true)) {
                    $permissions[] = $definition['permission'];
                }

                if (!in_array($definition['area'], $areas, true)) {
                    $areas[] = $definition['area'];
                }

                break;
            }
        }

        // Help Desk und Asset-Management ergeben sich auch aus der Rolle.
        $roleAreas = match ($role) {
            'admin' => ['admin', 'helpdesk', 'assets', 'statistics', 'tickets'],
            'helpdesk_admin', 'helpdesk_lead', 'helpdesk_agent' => ['helpdesk'],
            'assetmanagement' => ['assets'],
            default => [],
        };

        foreach ($roleAreas as $area) {
            if (!in_array($area, $areas, true)) {
                $areas[] = $area;
            }
        }

        $areaLabels = [];
        foreach (self::AREA_LABELS as $area => $label) {
            if (in_array($area, $areas, true)) {
                $areaLabels[] = $label;
            }
        }

        return [
            'role' => $role,
            'role_label' => self::ROLE_LABELS[$role] ?? $role,
            'permission_groups' => $permissions,
            'areas' => $areas,
            'area_labels' => $areaLabels,
            'matched' => array_values(array_unique($matched)),
            'elevated' => $role !== 'readonly' || $permissions !== [],
        ];
    }

    /**
     * Prueft und normalisiert die Eingaben der Berechtigungsseite.
     *
     * @param array<string,mixed> $post
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public function validate(array $post): array
    {
        $values = [];
        $errors = [];

        foreach (array_keys(self::GROUP_KEYS) as $key) {
            $groups = self::parseGroups($post[$key] ?? '');
            if (count($groups) > 100) {
                $errors[$key] = 'Bitte hoechstens 100 Gruppen angeben.';
            }

            foreach ($groups as $group) {
                if (mb_strlen($group) > 256) {
                    $errors[$key] = 'Ein Gruppenname ist zu lang (hoechstens 256 Zeichen).';
                    break;
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $group) === 1) {
                    $errors[$key] = 'Der Gruppenname enthaelt unzulaessige Zeichen.';
                    break;
                }
            }

            $values[$key] = implode(', ', $groups);
        }

        $role = trim((string) ($post['klinq_default_role'] ?? 'readonly'));
        if (!isset(self::ROLES[$role])) {
            $errors['klinq_default_role'] = 'Bitte eine gueltige Rolle waehlen.';
            $role = 'readonly';
        }
        $values['klinq_default_role'] = $role;

        $values['klinq_quick_report_open'] = !empty($post['klinq_quick_report_open']) ? '1' : '0';

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Oeffentlicher Pfad: fuehrender Slash, kein abschliessender Slash, keine
     * Pfadwechsel. Ungueltige Angaben fallen auf den Standard zurueck.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return self::DEFAULT_PATH;
        }

        $path = '/' . trim($path, '/');
        if (preg_match('#^/[A-Za-z0-9._\-/]+$#', $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            return self::DEFAULT_PATH;
        }

        return $path;
    }

    /**
     * Normalisiert einen AD-Gruppennamen (ohne Domaenenpraefix).
     */
    public static function normalizeGroup(string $group): string
    {
        $group = trim($group, " \t\n\r\0\x0B\"'");
        $group = (string) preg_replace('/^[^\\\\]*\\\\/', '', $group);
        $group = (string) preg_replace('/\s+/', ' ', $group);

        return Validator::cleanText($group, 256);
    }

    /**
     * Liest Gruppen aus Textfeld oder Array (Komma, Semikolon, Zeilenumbruch).
     *
     * @return list<string>
     */
    public static function parseGroups(mixed $value): array
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $parts[] = $entry;
                }
            }
        } else {
            $parts = preg_split('/[,;\r\n]+/', (string) $value) ?: [];
        }

        $groups = [];
        foreach ($parts as $part) {
            $name = self::normalizeGroup((string) $part);
            if ($name === '') {
                continue;
            }

            $key = strtolower($name);
            if (!isset($groups[$key])) {
                $groups[$key] = $name;
            }
        }

        return array_values($groups);
    }
}
