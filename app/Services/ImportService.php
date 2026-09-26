<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\ActivationNumberRepository;
use App\Repositories\AdminUserRepository;
use App\Repositories\AlarmGroupRepository;
use App\Repositories\AnnouncementRepository;
use App\Repositories\EmergencyNumberRepository;
use App\Repositories\ImportantLinkRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use JsonException;
use ZipArchive;

/**
 * Spielt eine Sicherungsdatei (ZIP) ein.
 *
 * Der Import ersetzt den gesamten gepflegten Datenbestand (replace-all), damit
 * die Zielinstallation anschliessend exakt der Quellinstallation entspricht.
 * Maschinell erzeugte Daten (Klick-Statistik, Sync- und Audit-Log) bleiben
 * unberuehrt. Die .env-Datei wird weder gelesen noch geschrieben.
 */
final class ImportService
{
    private const VERSION = 1;

    private const APP = 'lanpa';

    /** @var list<string> */
    private const DATA_KEYS = [
        'settings',
        'navigation_items',
        'important_links',
        'emergency_numbers',
        'announcements',
        'admin_users',
        'phonebook',
        'alarm_groups',
        'activation_numbers',
    ];

    /** @var array<int,int> */
    private array $alarmGroupIdMap = [];

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly SettingsService $settings,
        private readonly NavigationRepository $navigationRepository,
        private readonly ImportantLinkRepository $importantLinkRepository,
        private readonly EmergencyNumberRepository $emergencyNumberRepository,
        private readonly AnnouncementRepository $announcementRepository,
        private readonly AdminUserRepository $adminUserRepository,
        private readonly PhonebookRepository $phonebookRepository,
        private readonly AlarmGroupRepository $alarmGroupRepository,
        private readonly ActivationNumberRepository $activationNumberRepository,
        private readonly LogoService $logo,
        private readonly BackgroundImageService $backgroundImage,
        private readonly FaviconService $favicons
    ) {
    }

    public function import(string $zipContents): void
    {
        [$manifest, $files] = $this->parse($zipContents);
        $this->validateManifest($manifest, $files);

        $data = $manifest['data'];

        $pdo = $this->settingsRepository->pdo();
        $pdo->beginTransaction();

        try {
            $this->applySettings($data['settings']);
            $this->applyAlarmGroups($data['alarm_groups']);
            $this->applyActivationNumbers($data['activation_numbers']);
            $this->applyNavigation($data['navigation_items']);
            $this->applyImportantLinks($data['important_links']);
            $this->applyEmergencyNumbers($data['emergency_numbers']);
            $this->applyAnnouncements($data['announcements']);
            $this->applyAdminUsers($data['admin_users']);
            $this->applyPhonebook($data['phonebook']);

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $this->applyFiles($manifest['files'] ?? [], $files);
        $this->settings->resetCache();
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function parse(string $zipContents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lanpa-import-');
        if ($tmp === false || @file_put_contents($tmp, $zipContents) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }

            throw new ValidationException(['backup' => 'Die Datei konnte nicht gelesen werden.']);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmp) === true;

        try {
            if (!$opened) {
                throw new ValidationException(['backup' => 'Die Datei ist kein gültiges ZIP-Archiv.']);
            }

            $json = $zip->getFromName('manifest.json');
            if ($json === false) {
                throw new ValidationException(['backup' => 'Das Archiv enthält keine manifest.json.']);
            }

            try {
                $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ValidationException(['backup' => 'Die manifest.json ist ungültig.']);
            }

            if (!is_array($manifest)) {
                throw new ValidationException(['backup' => 'Die manifest.json ist ungültig.']);
            }

            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || !str_starts_with($name, 'files/')) {
                    continue;
                }

                $base = substr($name, 6);
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    $files[$base] = $content;
                }
            }

            return [$manifest, $files];
        } finally {
            if ($opened) {
                $zip->close();
            }

            @unlink($tmp);
        }
    }

    /**
     * @param array<string,mixed>   $manifest
     * @param array<string,string>  $files
     */
    private function validateManifest(array $manifest, array $files): void
    {
        if (($manifest['version'] ?? null) !== self::VERSION) {
            throw new ValidationException(['backup' => 'Nicht unterstützte Archiv-Version.']);
        }

        if (($manifest['app'] ?? '') !== self::APP) {
            throw new ValidationException(['backup' => 'Die Datei ist keine Sicherung dieser Anwendung.']);
        }

        $data = $manifest['data'] ?? null;
        if (!is_array($data)) {
            throw new ValidationException(['backup' => 'Die Sicherung enthält keine Daten.']);
        }

        foreach (self::DATA_KEYS as $key) {
            if (!is_array($data[$key] ?? null)) {
                throw new ValidationException(['backup' => 'Ungültige Sicherungsdaten (' . $key . ').']);
            }
        }

        foreach ($data['admin_users'] as $user) {
            if (!is_array($user)) {
                throw new ValidationException(['backup' => 'Ungültiger Benutzerdatensatz.']);
            }

            $role = (string) ($user['role'] ?? '');
            if (!in_array($role, AdminUserRepository::ROLES, true)) {
                throw new ValidationException(['backup' => 'Ungültige Benutzerrolle in der Sicherung.']);
            }
        }

        if (!$this->hasActiveAdmin($data['admin_users'])) {
            throw new ValidationException(['backup' => 'Die Sicherung enthält kein aktives Administratorkonto.']);
        }

        $filesMeta = $manifest['files'] ?? [];
        if (!is_array($filesMeta)) {
            throw new ValidationException(['backup' => 'Ungültige Dateiangaben in der Sicherung.']);
        }

        if (isset($filesMeta['logo'])) {
            $this->assertFilePresent($files, $filesMeta['logo'], '/^logo-[a-f0-9]{16}\.(png|jpg|svg|webp)$/');
        }

        if (isset($filesMeta['background'])) {
            $this->assertFilePresent($files, $filesMeta['background'], '/^background-[a-f0-9]{16}\.png$/');
        }

        $favicons = $filesMeta['favicons'] ?? [];
        if (!is_array($favicons)) {
            throw new ValidationException(['backup' => 'Ungültige Dateiangaben in der Sicherung.']);
        }

        foreach ($favicons as $icon) {
            $this->assertFilePresent($files, $icon, '/^favicon-[a-f0-9]{16}\.(png|jpg|webp|ico)$/');
        }
    }

    /**
     * @param array<string,mixed> $users
     */
    private function hasActiveAdmin(array $users): bool
    {
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            if (($user['role'] ?? '') === AdminUserRepository::ROLE_ADMIN && !empty($user['active'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $files
     * @param mixed                $meta
     */
    private function assertFilePresent(array $files, mixed $meta, string $pattern): void
    {
        if (!is_array($meta)) {
            throw new ValidationException(['backup' => 'Ungültige Dateiangaben in der Sicherung.']);
        }

        $filename = (string) ($meta['filename'] ?? '');
        if (preg_match($pattern, $filename) !== 1) {
            throw new ValidationException(['backup' => 'Ungültiger Dateiname in der Sicherung.']);
        }

        if (!isset($files[$filename])) {
            throw new ValidationException(['backup' => 'In der Sicherung fehlt eine Datei.']);
        }
    }

    /**
     * @param array<string,string> $settings
     */
    private function applySettings(array $settings): void
    {
        // Secrets (SMS-Gateway-Passwort und SMS-Code-Schluessel) werden nicht
        // exportiert und duerfen durch einen Import nicht überschrieben werden.
        $existingPassword = $this->settingsRepository->get('alarm_password');
        $existingSinglePassword = $this->settingsRepository->get('alarm_single_password');
        $existingSecret = $this->settingsRepository->get('sms_code_secret');
        $existingAiKey = $this->settingsRepository->get('office_ai_api_key');

        $this->settingsRepository->deleteAll();

        foreach ($settings as $key => $value) {
            if (in_array($key, ['alarm_password', 'alarm_single_password', 'sms_code_secret', 'office_ai_api_key'], true)) {
                continue;
            }
            $this->settingsRepository->insert((string) $key, (string) $value);
        }

        if (is_string($existingPassword) && $existingPassword !== '') {
            $this->settingsRepository->insert('alarm_password', $existingPassword);
        }

        if (is_string($existingSinglePassword) && $existingSinglePassword !== '') {
            $this->settingsRepository->insert('alarm_single_password', $existingSinglePassword);
        }

        if (is_string($existingSecret) && $existingSecret !== '') {
            $this->settingsRepository->insert('sms_code_secret', $existingSecret);
        }

        if (is_string($existingAiKey) && $existingAiKey !== '') {
            $this->settingsRepository->insert('office_ai_api_key', $existingAiKey);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyAlarmGroups(array $rows): void
    {
        $this->alarmGroupRepository->deleteAll();
        $this->alarmGroupIdMap = [];

        foreach ($rows as $row) {
            $oldId = (int) $row['id'];
            $this->alarmGroupIdMap[$oldId] = $this->alarmGroupRepository->create([
                'group_number' => (string) ($row['group_number'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'type' => (string) ($row['type'] ?? 'group'),
                'sort_order' => (int) ($row['sort_order'] ?? 1),
                'active' => !empty($row['active']),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyActivationNumbers(array $rows): void
    {
        $this->activationNumberRepository->deleteAll();

        foreach ($rows as $row) {
            $this->activationNumberRepository->create([
                'phone' => (string) ($row['phone'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 1),
                'active' => !empty($row['active']),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyNavigation(array $rows): void
    {
        $this->navigationRepository->deleteAll();

        $idMap = [];
        foreach ($rows as $row) {
            $oldId = (int) $row['id'];
            $idMap[$oldId] = $this->navigationRepository->create([
                'title' => (string) ($row['title'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'type' => (string) ($row['type'] ?? 'external'),
                'parent_id' => null,
                'icon' => $row['icon'] ?? null,
                'background_color' => $row['background_color'] ?? null,
                'background_opacity' => $row['background_opacity'] ?? null,
                'override_background' => !empty($row['override_background']),
                'short_description' => (string) ($row['short_description'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'content' => $row['content'] ?? null,
                'alarm_text' => $row['alarm_text'] ?? null,
                'alarm_group_id' => $this->remapAlarmGroupId($row['alarm_group_id'] ?? null),
                'protected_access' => !empty($row['protected_access']),
                'sort_order' => (int) ($row['sort_order'] ?? 1),
                'active' => !empty($row['active']),
            ]);
        }

        foreach ($rows as $row) {
            $oldId = (int) $row['id'];
            $oldParent = $row['parent_id'] ?? null;
            $parentId = ($oldParent === null || $oldParent === '') ? null : ($idMap[(int) $oldParent] ?? null);

            $this->navigationRepository->setParentId($idMap[$oldId], $parentId);
        }
    }

    private function remapAlarmGroupId(mixed $oldId): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }

        return $this->alarmGroupIdMap[(int) $oldId] ?? null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyImportantLinks(array $rows): void
    {
        $this->importantLinkRepository->deleteAll();

        foreach ($rows as $row) {
            $this->importantLinkRepository->create([
                'title' => (string) ($row['title'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'icon_file' => $row['icon_file'] ?? null,
                'icon_mime' => $row['icon_mime'] ?? null,
                'active' => !empty($row['active']),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyEmergencyNumbers(array $rows): void
    {
        $this->emergencyNumberRepository->deleteAll();

        foreach ($rows as $row) {
            $this->emergencyNumberRepository->create([
                'label' => (string) ($row['label'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 1),
                'active' => !empty($row['active']),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyAnnouncements(array $rows): void
    {
        $this->announcementRepository->deleteAll();

        foreach ($rows as $row) {
            $this->announcementRepository->create([
                'title' => (string) ($row['title'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'active' => !empty($row['active']),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyAdminUsers(array $rows): void
    {
        $this->adminUserRepository->deleteAll();

        foreach ($rows as $row) {
            $id = $this->adminUserRepository->create(
                (string) ($row['username'] ?? ''),
                (string) ($row['password_hash'] ?? ''),
                (string) ($row['role'] ?? AdminUserRepository::ROLE_ADMIN)
            );

            $this->adminUserRepository->setActive($id, !empty($row['active']));
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function applyPhonebook(array $rows): void
    {
        $this->phonebookRepository->deleteAll();

        foreach ($rows as $row) {
            $this->phonebookRepository->insert($row);
        }
    }

    /**
     * @param array<string,mixed>  $filesMeta
     * @param array<string,string> $files
     */
    private function applyFiles(array $filesMeta, array $files): void
    {
        if (isset($filesMeta['logo'])) {
            $filename = (string) ($filesMeta['logo']['filename'] ?? '');
            $mime = (string) ($filesMeta['logo']['mime'] ?? '');
            $this->logo->writeFromImport($filename, $files[$filename], $mime);
        }

        if (isset($filesMeta['background'])) {
            $filename = (string) ($filesMeta['background']['filename'] ?? '');
            $mime = (string) ($filesMeta['background']['mime'] ?? '');
            $this->backgroundImage->writeFromImport($filename, $files[$filename], $mime);
        }

        $favicons = $filesMeta['favicons'] ?? [];
        if (!is_array($favicons)) {
            return;
        }

        foreach ($favicons as $icon) {
            if (!is_array($icon)) {
                continue;
            }

            $filename = (string) ($icon['filename'] ?? '');
            $mime = (string) ($icon['mime'] ?? '');
            $this->favicons->writeFromImport($filename, $files[$filename], $mime);
        }
    }
}
