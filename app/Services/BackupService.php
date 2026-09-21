<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ActivationNumberRepository;
use App\Repositories\AdminUserRepository;
use App\Repositories\AlarmGroupRepository;
use App\Repositories\AnnouncementRepository;
use App\Repositories\EmergencyNumberRepository;
use App\Repositories\ImportantLinkRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use RuntimeException;
use ZipArchive;

/**
 * Erstellt die Sicherungsdatei (ZIP) mit allen Einstellungen und Inhalten.
 *
 * Enthalten sind saemtliche im Adminbereich gepflegten Daten sowie die
 * hochgeladenen Dateien (Logo, Hintergrundbild, Favicons). Die .env-Datei
 * wird bewusst nicht exportiert; darin liegende Werte (z. B. das LDAP-
 * Bind-Passwort) verbleiben in der jeweiligen Installation.
 */
final class BackupService
{
    private const VERSION = 1;

    private const APP = 'lanpa';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
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

    /**
     * Exportiert die Einstellungen ohne das SMS-Gateway-Passwort. Das Passwort
     * ist ein Secret und darf weder angezeigt noch in einer Sicherung landen.
     *
     * @return array<string,string>
     */
    private function exportSettings(): array
    {
        $settings = $this->settingsRepository->all();
        unset($settings['alarm_password'], $settings['sms_code_secret']);

        return $settings;
    }

    /**
     * Erstellt die Sicherungsdatei und gibt deren Inhalt zurueck.
     */
    public function create(): string
    {
        $manifest = [
            'version' => self::VERSION,
            'app' => self::APP,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => [
                'settings' => $this->exportSettings(),
                'navigation_items' => $this->navigationRepository->all(),
                'important_links' => $this->importantLinkRepository->all(),
                'emergency_numbers' => $this->emergencyNumberRepository->all(),
                'announcements' => $this->announcementRepository->all(),
                'admin_users' => $this->adminUserRepository->allWithPasswordHash(),
                'phonebook' => $this->phonebookRepository->all(),
                'alarm_groups' => $this->alarmGroupRepository->all(),
                'activation_numbers' => $this->activationNumberRepository->all(),
            ],
            'files' => [],
        ];

        /** @var array<string,string> $files */
        $files = [];

        $logo = $this->logo->current();
        if ($logo !== null) {
            $contents = @file_get_contents($logo['path']);
            if (is_string($contents)) {
                $name = basename($logo['path']);
                $manifest['files']['logo'] = ['filename' => $name, 'mime' => $logo['mime']];
                $files[$name] = $contents;
            }
        }

        $background = $this->backgroundImage->current();
        if ($background !== null) {
            $contents = @file_get_contents($background['path']);
            if (is_string($contents)) {
                $name = basename($background['path']);
                $manifest['files']['background'] = ['filename' => $name, 'mime' => $background['mime']];
                $files[$name] = $contents;
            }
        }

        $favicons = [];
        foreach ($manifest['data']['important_links'] as $link) {
            $iconFile = (string) ($link['icon_file'] ?? '');
            $iconMime = (string) ($link['icon_mime'] ?? '');

            if ($iconFile === '' || $iconMime === '' || isset($files[$iconFile])) {
                continue;
            }

            $path = $this->favicons->iconPath($iconFile);
            if ($path === null) {
                continue;
            }

            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }

            $favicons[] = ['filename' => $iconFile, 'mime' => $iconMime];
            $files[$iconFile] = $contents;
        }

        if ($favicons !== []) {
            $manifest['files']['favicons'] = $favicons;
        }

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->buildZip($json, $files);
    }

    /**
     * @param array<string,string> $files
     */
    private function buildZip(string $manifestJson, array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lanpa-backup-');
        if ($tmp === false) {
            throw new RuntimeException('Temporäre Datei konnte nicht erstellt werden.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Das Archiv konnte nicht erstellt werden.');
            }

            $zip->addFromString('manifest.json', $manifestJson);
            foreach ($files as $name => $contents) {
                $zip->addFromString('files/' . $name, $contents);
            }

            $zip->close();

            $result = file_get_contents($tmp);
            if ($result === false) {
                throw new RuntimeException('Das Archiv konnte nicht gelesen werden.');
            }

            return $result;
        } finally {
            @unlink($tmp);
        }
    }
}
