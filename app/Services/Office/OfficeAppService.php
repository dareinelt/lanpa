<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Exceptions\ValidationException;
use App\Repositories\OfficeAppRepository;
use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Office-Apps unter der Office-Kachel: Sichtbarkeit je Benutzer (AD-Gruppen,
 * direkt oder ueber App-Pakete), Startziele und Pflege im Adminbereich.
 *
 * Regeln:
 *   - Nicht angemeldete Nutzer (kein SSO-Benutzer) erhalten keine Apps.
 *   - Eine App ist nur fuer Mitglieder der ihr (direkt oder ueber ein Paket)
 *     zugeordneten AD-Gruppen sichtbar. Ohne Zuordnung sieht sie niemand.
 *   - "Outlook Web App" erscheint nur, wenn ein Link hinterlegt ist.
 */
final class OfficeAppService
{
    public const OWA_SETTING = 'office_owa_url';

    /** Ziel der Office-Kachel (Uebersicht der Apps). */
    public const ENTRY_PATH = '/office-starten';

    /** Start einer einzelnen App (?app=<Schluessel>). */
    public const LAUNCH_PATH = '/office-app';

    public function __construct(
        private readonly OfficeAppRepository $repository,
        private readonly OfficeConfigService $config,
        private readonly SettingsService $settings
    ) {
    }

    /**
     * Alle Apps mit Startziel und Bereitstellungsstatus (fuer Admin und Filter).
     *
     * @return list<array{key:string,title:string,short_description:string,icon:string,kind:string,webapp:string,configured:bool,target:string,external:bool}>
     */
    public function catalog(): array
    {
        $apps = [];
        foreach (OfficeAppCatalog::APPS as $key => $app) {
            $target = $this->targetFor($key);
            $apps[] = [
                'key' => $key,
                'title' => $app['title'],
                'short_description' => $app['short_description'],
                'icon' => $app['icon'],
                'kind' => $app['kind'],
                'webapp' => (string) ($app['webapp'] ?? ''),
                'configured' => $target !== '',
                'target' => $target,
                'external' => $app['kind'] === 'external',
            ];
        }

        return $apps;
    }

    /**
     * Fuer den Benutzer freigegebene und bereitgestellte Apps (Katalogreihenfolge).
     *
     * @param array{id:int,username:string,display_name:string,groups:list<string>}|null $ssoUser
     *
     * @return list<array{key:string,title:string,short_description:string,icon:string,kind:string,webapp:string,configured:bool,target:string,external:bool}>
     */
    public function allowedFor(?array $ssoUser): array
    {
        // Nur erkannte Benutzer (SSO bzw. simulierte Anmeldung im Testmodus).
        if ($ssoUser === null || trim((string) ($ssoUser['username'] ?? '')) === '') {
            return [];
        }

        $groups = is_array($ssoUser['groups'] ?? null) ? $ssoUser['groups'] : [];
        $groups = array_values(array_filter(array_map(static fn (mixed $g): string => trim((string) $g), $groups), static fn (string $g): bool => $g !== ''));
        if ($groups === []) {
            return [];
        }

        $allowed = array_fill_keys($this->repository->appKeysForGroups($groups), true);

        return array_values(array_filter(
            $this->catalog(),
            static fn (array $app): bool => $app['configured'] && isset($allowed[$app['key']])
        ));
    }

    /**
     * @param array{id:int,username:string,display_name:string,groups:list<string>}|null $ssoUser
     *
     * @return array{key:string,title:string,short_description:string,icon:string,kind:string,webapp:string,configured:bool,target:string,external:bool}|null
     */
    public function findAllowed(string $key, ?array $ssoUser): ?array
    {
        foreach ($this->allowedFor($ssoUser) as $app) {
            if ($app['key'] === $key) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Entfernt die Office-Kachel aus einer Kachelliste, wenn der Benutzer
     * keine Office-App erhaelt (insbesondere nicht angemeldete Nutzer).
     *
     * @param list<array<string,mixed>> $items
     * @param array{id:int,username:string,display_name:string,groups:list<string>}|null $ssoUser
     *
     * @return list<array<string,mixed>>
     */
    public function filterNavigation(array $items, ?array $ssoUser): array
    {
        $hasApps = null;
        $result = [];
        foreach ($items as $item) {
            if (self::isOfficeTile($item)) {
                $hasApps ??= $this->config->isEnabled() && $this->allowedFor($ssoUser) !== [];
                if (!$hasApps) {
                    continue;
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $item
     */
    public static function isOfficeTile(array $item): bool
    {
        return (string) ($item['type'] ?? '') === 'internal'
            && rtrim((string) ($item['url'] ?? ''), '/') === self::ENTRY_PATH;
    }

    /**
     * Startziel einer App ('' = nicht bereitgestellt).
     */
    public function targetFor(string $key): string
    {
        $app = OfficeAppCatalog::APPS[$key] ?? null;
        if ($app === null) {
            return '';
        }

        $base = $this->config->publicPath();

        return match ($app['kind']) {
            // Der Connector legt die leere Datei in den eigenen Dateien an und
            // oeffnet sie direkt in der passenden Euro-Office-Webapp.
            'editor' => $base . 'index.php/apps/eurooffice/new?' . http_build_query(
                ['name' => (string) $app['file_name'], 'dir' => '/'],
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            'files' => $base . 'index.php/apps/files/',
            'external' => $key === 'owa' ? $this->owaUrl() : '',
            default => '',
        };
    }

    public function owaUrl(): string
    {
        $url = trim($this->settings->get(self::OWA_SETTING));

        return self::isValidOwaUrl($url) ? $url : '';
    }

    public static function isValidOwaUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * Speichert den Link zur Outlook Web App ('' entfernt die Kachel).
     */
    public function saveOwaUrl(string $url): void
    {
        $url = trim($url);
        if ($url !== '' && !self::isValidOwaUrl($url)) {
            throw new ValidationException([self::OWA_SETTING => 'Bitte eine vollständige http(s)-Adresse angeben, z. B. https://mail.example.com/owa/.']);
        }

        $this->settings->update([self::OWA_SETTING => $url]);
    }

    /**
     * @return array<string,list<string>>
     */
    public function directGroups(): array
    {
        return $this->repository->directGroupsByApp();
    }

    /**
     * Setzt die direkten Freigaben aller Apps.
     *
     * @param array<mixed> $input app_key => kommagetrennte Gruppennamen
     */
    public function saveDirectGroups(array $input): void
    {
        foreach (OfficeAppCatalog::keys() as $key) {
            $raw = $input[$key] ?? '';
            $this->repository->replaceAppGroups($key, self::splitGroups(is_string($raw) ? $raw : ''));
        }
    }

    /**
     * @return list<array{id:int,name:string,description:string,apps:list<string>,groups:list<string>}>
     */
    public function packages(): array
    {
        return array_map(self::orderPackageApps(...), $this->repository->packages());
    }

    /**
     * @return array{id:int,name:string,description:string,apps:list<string>,groups:list<string>}|null
     */
    public function findPackage(int $id): ?array
    {
        $package = $this->repository->findPackage($id);

        return $package === null ? null : self::orderPackageApps($package);
    }

    /**
     * @param array{id:int,name:string,description:string,apps:list<string>,groups:list<string>} $package
     *
     * @return array{id:int,name:string,description:string,apps:list<string>,groups:list<string>}
     */
    private static function orderPackageApps(array $package): array
    {
        $package['apps'] = OfficeAppCatalog::filterKeys($package['apps']);

        return $package;
    }

    /**
     * Prueft und speichert ein App-Paket.
     *
     * @param array<string,mixed> $input name, description, apps[], groups
     */
    public function savePackage(?int $id, array $input): int
    {
        if ($id !== null && $this->repository->findPackage($id) === null) {
            throw new ValidationException(['id' => 'Das Paket wurde nicht gefunden.']);
        }

        $errors = [];
        $name = Validator::cleanText((string) ($input['name'] ?? ''), 120);
        if (!Validator::isNotEmpty($name, 120)) {
            $errors['name'] = 'Bitte einen Namen angeben (max. 120 Zeichen).';
        } elseif ($this->repository->packageNameExists($name, $id)) {
            $errors['name'] = 'Ein Paket mit diesem Namen existiert bereits.';
        }

        $description = Validator::cleanText((string) ($input['description'] ?? ''), 255);

        $apps = OfficeAppCatalog::filterKeys(is_array($input['apps'] ?? null) ? $input['apps'] : []);
        if ($apps === []) {
            $errors['apps'] = 'Bitte mindestens eine App auswählen.';
        }

        $groups = self::splitGroups(is_string($input['groups'] ?? null) ? $input['groups'] : '');

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->repository->savePackage($id, $name, $description, $apps, $groups);
    }

    public function deletePackage(int $id): void
    {
        $this->repository->deletePackage($id);
    }

    /**
     * Gruppen je App inkl. Paketen (Uebersicht im Adminbereich).
     *
     * @return array<string,list<string>>
     */
    public function effectiveGroups(): array
    {
        $map = array_fill_keys(OfficeAppCatalog::keys(), []);
        foreach ($this->directGroups() as $key => $groups) {
            if (isset($map[$key])) {
                $map[$key] = array_merge($map[$key], $groups);
            }
        }
        foreach ($this->packages() as $package) {
            foreach ($package['apps'] as $key) {
                if (isset($map[$key])) {
                    $map[$key] = array_merge($map[$key], $package['groups']);
                }
            }
        }

        foreach ($map as $key => $groups) {
            $unique = [];
            foreach ($groups as $group) {
                $unique[mb_strtolower($group)] ??= $group;
            }
            $map[$key] = array_values($unique);
        }

        return $map;
    }

    /**
     * Kommagetrennte Gruppennamen -> bereinigte, eindeutige Liste.
     *
     * @return list<string>
     */
    public static function splitGroups(string $raw): array
    {
        $groups = [];
        foreach (preg_split('/[,;\r\n]+/', $raw) ?: [] as $group) {
            $group = Validator::cleanText($group, 190);
            if ($group !== '' && !isset($groups[mb_strtolower($group)])) {
                $groups[mb_strtolower($group)] = $group;
            }
        }

        return array_values($groups);
    }
}
