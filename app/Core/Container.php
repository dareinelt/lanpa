<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\ActivationNumberRepository;
use App\Repositories\AdminUserRepository;
use App\Repositories\AlarmGroupRepository;
use App\Repositories\AlarmLogRepository;
use App\Repositories\AnnouncementRepository;
use App\Repositories\AdGroupRepository;
use App\Repositories\AdminGroupRepository;
use App\Repositories\ClickRepository;
use App\Repositories\EmergencyNumberRepository;
use App\Repositories\IdentitySourceRepository;
use App\Repositories\ImportantLinkRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\OfficeAppRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\NetworkDriveRepository;
use App\Repositories\StorageQuotaRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\StorageRepository;
use App\Repositories\SyncLogRepository;
use App\Repositories\TlsCertificateRepository;
use App\Security\Auth;
use App\Security\SecretBox;
use App\Security\SsoAuth;
use App\Services\AdSyncService;
use App\Services\ActivationNumberService;
use App\Services\AdminGroupService;
use App\Services\AdminUserService;
use App\Services\AlarmGroupService;
use App\Services\AlarmService;
use App\Services\AnnouncementService;
use App\Services\BackupService;
use App\Services\EmergencyNumberService;
use App\Services\FaviconService;
use App\Services\IdentitySourceService;
use App\Services\ImportantLinkService;
use App\Services\ImportService;
use App\Services\BackgroundImageService;
use App\Services\LdapClient;
use App\Services\LogoService;
use App\Services\NavigationService;
use App\Services\Office\OfficeAiService;
use App\Services\Office\OfficeAppService;
use App\Services\Office\OfficeBackupService;
use App\Services\Office\OfficeConfigService;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\NextcloudAdminService;
use App\Services\Office\NextcloudAppStoreService;
use App\Services\Office\OfficeTrustedDomainsService;
use App\Services\Office\NetworkDriveService;
use App\Services\Office\StorageQuotaService;
use App\Services\Office\StreamOfficeProbe;
use App\Services\PhonebookService;
use App\Services\SettingsService;
use App\Services\SmsCodeService;
use App\Services\StatisticsService;
use App\Services\Storage\IncidentService;
use App\Services\Storage\SnapshotService;
use App\Services\Storage\StorageService;
use App\Services\ThemeService;
use App\Services\Tls\TlsCertificateService;

/**
 * Sehr einfacher Service-Container (Singletons pro Request).
 */
final class Container
{
    /** @var array<string,object> */
    private static array $instances = [];

    public static function reset(): void
    {
        self::$instances = [];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param callable():T    $factory
     *
     * @return T
     */
    private static function make(string $id, callable $factory): object
    {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = $factory();
        }

        /** @var T $instance */
        $instance = self::$instances[$id];

        return $instance;
    }

    public static function settingsRepository(): SettingsRepository
    {
        return self::make(SettingsRepository::class, static fn (): SettingsRepository => new SettingsRepository());
    }

    public static function navigationRepository(): NavigationRepository
    {
        return self::make(NavigationRepository::class, static fn (): NavigationRepository => new NavigationRepository());
    }

    public static function phonebookRepository(): PhonebookRepository
    {
        return self::make(PhonebookRepository::class, static fn (): PhonebookRepository => new PhonebookRepository());
    }

    public static function adGroupRepository(): AdGroupRepository
    {
        return self::make(AdGroupRepository::class, static fn (): AdGroupRepository => new AdGroupRepository());
    }

    public static function identitySourceRepository(): IdentitySourceRepository
    {
        return self::make(IdentitySourceRepository::class, static fn (): IdentitySourceRepository => new IdentitySourceRepository());
    }

    public static function identitySources(): IdentitySourceService
    {
        return self::make(
            IdentitySourceService::class,
            static fn (): IdentitySourceService => new IdentitySourceService(self::identitySourceRepository(), self::settings(), self::secretBox())
        );
    }

    /**
     * Verschluesselung gespeicherter Zugangsdaten (Schluessel ausserhalb der
     * Datenbank, im Speicher-Volume).
     */
    public static function secretBox(): SecretBox
    {
        return self::make(
            SecretBox::class,
            static fn (): SecretBox => new SecretBox((string) Env::get('SECRETS_KEY_FILE', BASE_PATH . '/storage/keys/secrets.key'))
        );
    }

    public static function tlsCertificates(): TlsCertificateService
    {
        return self::make(
            TlsCertificateService::class,
            static fn (): TlsCertificateService => new TlsCertificateService(
                new TlsCertificateRepository(),
                self::settings(),
                self::secretBox(),
                (string) Config::get('app.url', 'http://localhost:8080')
            )
        );
    }

    public static function clickRepository(): ClickRepository
    {
        return self::make(ClickRepository::class, static fn (): ClickRepository => new ClickRepository());
    }

    public static function adminUserRepository(): AdminUserRepository
    {
        return self::make(AdminUserRepository::class, static fn (): AdminUserRepository => new AdminUserRepository());
    }

    public static function syncLogRepository(): SyncLogRepository
    {
        return self::make(SyncLogRepository::class, static fn (): SyncLogRepository => new SyncLogRepository());
    }

    public static function emergencyNumberRepository(): EmergencyNumberRepository
    {
        return self::make(
            EmergencyNumberRepository::class,
            static fn (): EmergencyNumberRepository => new EmergencyNumberRepository()
        );
    }

    public static function importantLinkRepository(): ImportantLinkRepository
    {
        return self::make(
            ImportantLinkRepository::class,
            static fn (): ImportantLinkRepository => new ImportantLinkRepository()
        );
    }

    public static function announcementRepository(): AnnouncementRepository
    {
        return self::make(
            AnnouncementRepository::class,
            static fn (): AnnouncementRepository => new AnnouncementRepository()
        );
    }

    public static function alarmGroupRepository(): AlarmGroupRepository
    {
        return self::make(
            AlarmGroupRepository::class,
            static fn (): AlarmGroupRepository => new AlarmGroupRepository()
        );
    }

    public static function activationNumberRepository(): ActivationNumberRepository
    {
        return self::make(
            ActivationNumberRepository::class,
            static fn (): ActivationNumberRepository => new ActivationNumberRepository()
        );
    }

    public static function activationNumbers(): ActivationNumberService
    {
        return self::make(
            ActivationNumberService::class,
            static fn (): ActivationNumberService => new ActivationNumberService(
                self::activationNumberRepository(),
                self::alarmGroupRepository()
            )
        );
    }

    public static function smsCode(): SmsCodeService
    {
        return self::make(
            SmsCodeService::class,
            static fn (): SmsCodeService => new SmsCodeService(
                self::navigationRepository(),
                self::activationNumberRepository(),
                self::settings()
            )
        );
    }

    public static function alarmLogRepository(): AlarmLogRepository
    {
        return self::make(
            AlarmLogRepository::class,
            static fn (): AlarmLogRepository => new AlarmLogRepository()
        );
    }

    public static function alarmGroups(): AlarmGroupService
    {
        return self::make(
            AlarmGroupService::class,
            static fn (): AlarmGroupService => new AlarmGroupService(self::alarmGroupRepository())
        );
    }

    public static function alarm(): AlarmService
    {
        return self::make(
            AlarmService::class,
            static fn (): AlarmService => new AlarmService(
                self::navigationRepository(),
                self::alarmLogRepository(),
                self::settings()
            )
        );
    }

    public static function emergencyPlanTransfer(): \App\Services\EmergencyPlanTransfer
    {
        return self::make(\App\Services\EmergencyPlanTransfer::class, static fn () => new \App\Services\EmergencyPlanTransfer(
            self::emergencyPlans(),
            (string) Config::get('app.emergency_transfer_path', BASE_PATH . '/storage/emergency-transfer')
        ));
    }

    public static function emergencyPlans(): \App\Services\EmergencyPlanService
    {
        return self::make(\App\Services\EmergencyPlanService::class, static fn () => new \App\Services\EmergencyPlanService(
            new \App\Repositories\EmergencyPlanRepository(), self::settings(), self::navigationRepository(),
            static function (array $user, string $password): bool {
                foreach (self::identitySources()->configs() as $config) {
                    if ((int) ($config['id'] ?? 0) === (int) $user['source_id']) {
                        return (new LdapClient($config, app_logger()))->verifyUserPassword($user['username'], $password);
                    }
                }
                throw new \RuntimeException('Identitätsquelle nicht verfügbar.');
            },
            static function (): array {
                $members = self::adminGroups()->members(AdminGroupService::TARGET_KAEP);
                foreach (self::adminUserRepository()->all() as $local) {
                    if ($local['role'] === Auth::ROLE_KAEP && (int) $local['active'] === 1) {
                        $members[] = $local;
                    }
                }
                $emails = [];
                $missing = 0;
                foreach ($members as $member) {
                    $email = trim((string) ($member['email'] ?? ''));
                    if (\App\Services\SmtpService::validEmail($email)) {
                        $emails[mb_strtolower($email)] = $email;
                    } else {
                        $missing++;
                    }
                }

                return ['emails' => array_values($emails), 'missing' => $missing];
            },
            static fn (array $alarm) => self::alarm()->triggerEmergency($alarm),
            (string) Config::get('app.url', '')
        ));
    }

    public static function smtp(): \App\Services\SmtpService
    {
        return self::make(\App\Services\SmtpService::class, static fn () => new \App\Services\SmtpService(self::settings(), self::secretBox()));
    }

    public static function mailQueue(): \App\Services\MailQueueService
    {
        return self::make(\App\Services\MailQueueService::class, static fn () => new \App\Services\MailQueueService(
            Database::connection(), self::smtp(), new \App\Repositories\EmergencyPlanRepository()
        ));
    }

    public static function announcements(): AnnouncementService
    {
        return self::make(
            AnnouncementService::class,
            static fn (): AnnouncementService => new AnnouncementService(self::announcementRepository())
        );
    }

    public static function settings(): SettingsService
    {
        return self::make(SettingsService::class, static fn (): SettingsService => new SettingsService(self::settingsRepository()));
    }

    public static function theme(): ThemeService
    {
        return self::make(ThemeService::class, static fn (): ThemeService => new ThemeService(self::settings()));
    }

    public static function navigation(): NavigationService
    {
        return self::make(
            NavigationService::class,
            static fn (): NavigationService => new NavigationService(self::navigationRepository(), self::alarmGroupRepository())
        );
    }

    public static function phonebook(): PhonebookService
    {
        return self::make(PhonebookService::class, static fn (): PhonebookService => new PhonebookService(self::phonebookRepository(), self::identitySources()));
    }

    public static function emergencyNumbers(): EmergencyNumberService
    {
        return self::make(
            EmergencyNumberService::class,
            static fn (): EmergencyNumberService => new EmergencyNumberService(self::emergencyNumberRepository())
        );
    }

    public static function favicons(): FaviconService
    {
        return self::make(FaviconService::class, static fn (): FaviconService => FaviconService::fromConfig());
    }

    public static function importantLinks(): ImportantLinkService
    {
        return self::make(
            ImportantLinkService::class,
            static fn (): ImportantLinkService => new ImportantLinkService(
                self::importantLinkRepository(),
                self::favicons()
            )
        );
    }

    public static function statistics(): StatisticsService
    {
        return self::make(
            StatisticsService::class,
            static fn (): StatisticsService => new StatisticsService(self::clickRepository(), self::navigationRepository())
        );
    }

    public static function auth(): Auth
    {
        return self::make(
            Auth::class,
            static fn (): Auth => new Auth(
                self::adminUserRepository(),
                (int) Config::get('app.session_idle_timeout', 3600),
                // AD-Administratoren: Windows-Anmeldung muss weiterhin zur
                // Sitzung passen und eine berechtigte AD-Gruppe enthalten.
                static function (array $identity): ?string {
                    $user = self::sso()->resolve(Request::fromGlobals());
                    if (
                        $user === null
                        || strcasecmp($user['username'], $identity['username']) !== 0
                        || strtoupper($user['source_key']) !== strtoupper($identity['source_key'])
                    ) {
                        return null;
                    }

                    return self::adminGroups()->intranetRole($user['groups']);
                }
            )
        );
    }

    public static function adminGroups(): AdminGroupService
    {
        return self::make(
            AdminGroupService::class,
            static fn (): AdminGroupService => new AdminGroupService(
                new AdminGroupRepository(),
                static fn (): array => self::identitySourceMap()
            )
        );
    }

    public static function nextcloudFiles(): \App\Services\Office\NextcloudFilesService
    {
        return self::make(
            \App\Services\Office\NextcloudFilesService::class,
            static fn (): \App\Services\Office\NextcloudFilesService => new \App\Services\Office\NextcloudFilesService(self::officeConfig(), new StreamOfficeProbe())
        );
    }

    // ------------------------------------------------------------------ Orvanta (Mail & Kalender)

    public static function orvantaRepository(): \App\Repositories\OrvantaRepository
    {
        return self::make(\App\Repositories\OrvantaRepository::class, static fn (): \App\Repositories\OrvantaRepository => new \App\Repositories\OrvantaRepository());
    }

    public static function orvantaConfig(): \App\Services\Orvanta\OrvantaConfigService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaConfigService::class,
            static fn (): \App\Services\Orvanta\OrvantaConfigService => new \App\Services\Orvanta\OrvantaConfigService(self::orvantaRepository(), self::secretBox())
        );
    }

    /**
     * EWS-Transport: cURL (Negotiate/NTLM/Basic) oder Demomodus mit
     * Beispieldaten (Exchange-Server „demo“, nicht im Produktionsmodus).
     */
    public static function exchangeTransport(): \App\Contracts\ExchangeTransportInterface
    {
        return self::make(\App\Contracts\ExchangeTransportInterface::class, static function (): \App\Contracts\ExchangeTransportInterface {
            if (self::orvantaConfig()->isDemo()) {
                return new \App\Services\Orvanta\DemoExchangeTransport();
            }

            return new \App\Services\Orvanta\CurlExchangeTransport();
        });
    }

    public static function orvantaExchange(): \App\Services\Orvanta\OrvantaExchangeService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaExchangeService::class,
            static fn (): \App\Services\Orvanta\OrvantaExchangeService => new \App\Services\Orvanta\OrvantaExchangeService(self::exchangeTransport(), self::orvantaConfig())
        );
    }

    public static function orvantaAttachments(): \App\Services\Orvanta\OrvantaAttachmentService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaAttachmentService::class,
            static fn (): \App\Services\Orvanta\OrvantaAttachmentService => new \App\Services\Orvanta\OrvantaAttachmentService(
                self::orvantaRepository(),
                self::orvantaConfig(),
                self::officeConfig(),
                self::nextcloudFiles(),
                self::orvantaExchange(),
                self::secretBox()
            )
        );
    }

    public static function orvantaNotifications(): \App\Services\Orvanta\OrvantaNotificationService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaNotificationService::class,
            static fn (): \App\Services\Orvanta\OrvantaNotificationService => new \App\Services\Orvanta\OrvantaNotificationService(
                self::orvantaRepository(),
                self::orvantaExchange(),
                self::orvantaConfig()
            )
        );
    }

    public static function nextcloudAdmins(): NextcloudAdminService
    {
        return self::make(
            NextcloudAdminService::class,
            static fn (): NextcloudAdminService => new NextcloudAdminService(
                self::adminGroups(),
                self::settings(),
                self::officeConfig(),
                new StreamOfficeProbe()
            )
        );
    }

    /**
     * Identitaetsquellen fuer Nextcloud-Kennungen (ID => Kennung klein,
     * Beschriftung); 0 = Hauptquelle.
     *
     * @return array<int,array{key:string,label:string}>
     */
    private static function identitySourceMap(): array
    {
        $sources = [0 => ['key' => '', 'label' => (string) (self::identitySources()->labels()[0] ?? '')]];
        foreach (self::identitySources()->additionalRows(false) as $row) {
            $sources[(int) $row['id']] = [
                'key' => strtolower((string) $row['source_key']),
                'label' => (string) ($row['label'] ?? $row['source_key']),
            ];
        }

        return $sources;
    }

    public static function sso(): SsoAuth
    {
        return self::make(
            SsoAuth::class,
            static fn (): SsoAuth => new SsoAuth(
                self::phonebookRepository(),
                self::ssoConfig(),
                self::adGroupRepository(),
                self::identitySourceRepository(),
                (string) self::settings()->ldapConfig()['label']
            )
        );
    }

    /**
     * SSO-Konfiguration; die auth-Instanzen weiterer Quellen mit aktiver
     * Windows-Anmeldung werden automatisch (an ihre Kennung gebunden) als
     * vertrauenswuerdige Proxys ergaenzt.
     *
     * @return array<string,mixed>
     */
    private static function ssoConfig(): array
    {
        $config = (array) Config::get('sso', []);
        if (empty($config['enabled'])) {
            return $config;
        }

        try {
            $workers = self::identitySources()->trustedWorkerProxies();
        } catch (\Throwable) {
            $workers = [];
        }
        if ($workers === []) {
            return $config;
        }

        // Mit weiteren Instanzen duerfen ungebundene Eintraege (z. B. "auth")
        // nur noch die Hauptquelle melden.
        $entries = [];
        foreach (explode(',', (string) ($config['trusted_proxy'] ?? '')) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $entries[] = str_contains($entry, '=') ? $entry : $entry . '=';
            }
        }
        $config['trusted_proxy'] = implode(',', array_values(array_unique(array_merge($entries, $workers))));

        return $config;
    }

    public static function adminUsers(): AdminUserService
    {
        return self::make(
            AdminUserService::class,
            static fn (): AdminUserService => new AdminUserService(self::adminUserRepository())
        );
    }

    public static function logo(): LogoService
    {
        return self::make(
            LogoService::class,
            static fn (): LogoService => new LogoService(
                self::settings(),
                (string) Config::get('app.upload_path', BASE_PATH . '/storage/uploads'),
                (int) Config::get('app.max_logo_bytes', 512 * 1024),
                (bool) Config::get('app.allow_svg_logo', true)
            )
        );
    }

    public static function backgroundImage(): BackgroundImageService
    {
        return self::make(
            BackgroundImageService::class,
            static fn (): BackgroundImageService => new BackgroundImageService(
                self::settings(),
                (string) Config::get('app.upload_path', BASE_PATH . '/storage/uploads'),
                (int) Config::get('app.max_background_bytes', 2 * 1024 * 1024)
            )
        );
    }

    public static function adSync(): AdSyncService
    {
        return self::make(
            AdSyncService::class,
            static function (): AdSyncService {
                // Alle aktiven, vollstaendig konfigurierten Identitaetsquellen.
                $sources = [];
                foreach (self::identitySources()->configs() as $config) {
                    if (IdentitySourceService::isConfigured($config)) {
                        $sources[] = [
                            'id' => (int) $config['id'],
                            'label' => (string) $config['label'],
                            'client' => new LdapClient($config, app_logger()),
                        ];
                    }
                }

                return new AdSyncService(
                    $sources,
                    self::phonebookRepository(),
                    self::syncLogRepository(),
                    app_logger(),
                    self::adGroupRepository()
                );
            }
        );
    }

    public static function backup(): BackupService
    {
        return self::make(
            BackupService::class,
            static fn (): BackupService => new BackupService(
                self::settingsRepository(),
                self::navigationRepository(),
                self::importantLinkRepository(),
                self::emergencyNumberRepository(),
                self::announcementRepository(),
                self::adminUserRepository(),
                self::phonebookRepository(),
                self::alarmGroupRepository(),
                self::activationNumberRepository(),
                self::logo(),
                self::backgroundImage(),
                self::favicons(),
                self::identitySourceRepository()
            )
        );
    }

    public static function import(): ImportService
    {
        return self::make(
            ImportService::class,
            static fn (): ImportService => new ImportService(
                self::settingsRepository(),
                self::settings(),
                self::navigationRepository(),
                self::importantLinkRepository(),
                self::emergencyNumberRepository(),
                self::announcementRepository(),
                self::adminUserRepository(),
                self::phonebookRepository(),
                self::alarmGroupRepository(),
                self::activationNumberRepository(),
                self::logo(),
                self::backgroundImage(),
                self::favicons(),
                self::identitySourceRepository()
            )
        );
    }

    public static function officeConfig(): OfficeConfigService
    {
        return self::make(
            OfficeConfigService::class,
            static fn (): OfficeConfigService => new OfficeConfigService(self::settings(), (array) Config::get('office', []))
        );
    }

    public static function officeHealth(): OfficeHealthService
    {
        return self::make(
            OfficeHealthService::class,
            static fn (): OfficeHealthService => new OfficeHealthService(
                self::officeConfig(),
                new StreamOfficeProbe(),
                (string) Config::get('office.health_cache_file', BASE_PATH . '/storage/cache/office_health.json'),
                (int) Config::get('office.health_cache_ttl', 30),
                self::officeAi(),
                self::officeTrustedDomains(),
                self::storageQuotas(),
                self::nextcloudAdmins(),
                self::networkDrives(),
                self::nextcloudAppStore()
            )
        );
    }

    public static function nextcloudAppStore(): NextcloudAppStoreService
    {
        return self::make(
            NextcloudAppStoreService::class,
            static fn (): NextcloudAppStoreService => new NextcloudAppStoreService(
                self::settings(),
                self::officeConfig(),
                new StreamOfficeProbe()
            )
        );
    }

    public static function officeTrustedDomains(): OfficeTrustedDomainsService
    {
        return self::make(
            OfficeTrustedDomainsService::class,
            static fn (): OfficeTrustedDomainsService => new OfficeTrustedDomainsService(
                self::officeConfig(),
                new StreamOfficeProbe(),
                [
                    'app_url' => (string) Config::get('app.url', 'http://localhost:8080'),
                    'extra_trusted_domains' => (string) Config::get('office.extra_trusted_domains', ''),
                    'spn_hosts' => (string) Config::get('sso.spn_hosts', ''),
                ],
                static function (): array {
                    $hosts = self::identitySources()->ssoHostnames((string) Config::get('sso.netbios_name', 'lanpa-sso'));
                    try {
                        $certificate = (new TlsCertificateRepository())->findActive();
                    } catch (\PDOException) {
                        $certificate = null;
                    }
                    if ($certificate !== null) {
                        $hosts[] = (string) ($certificate['common_name'] ?? '');
                        foreach (['san', 'cert_san'] as $key) {
                            foreach (preg_split('/\s+/', (string) ($certificate[$key] ?? '')) ?: [] as $name) {
                                $hosts[] = $name;
                            }
                        }
                    }

                    return $hosts;
                }
            )
        );
    }

    public static function storageQuotas(): StorageQuotaService
    {
        return self::make(
            StorageQuotaService::class,
            static fn (): StorageQuotaService => new StorageQuotaService(
                new StorageQuotaRepository(),
                self::settings(),
                self::officeConfig(),
                new StreamOfficeProbe(),
                static fn (): array => self::identitySourceMap()
            )
        );
    }

    public static function networkDrives(): NetworkDriveService
    {
        return self::make(
            NetworkDriveService::class,
            static fn (): NetworkDriveService => new NetworkDriveService(
                new NetworkDriveRepository(),
                self::settings(),
                self::officeConfig(),
                new StreamOfficeProbe()
            )
        );
    }

    public static function officeAi(): OfficeAiService
    {
        return self::make(
            OfficeAiService::class,
            static fn (): OfficeAiService => new OfficeAiService(
                self::settings(),
                self::officeConfig(),
                new StreamOfficeProbe(),
                (array) Config::get('office', [])
            )
        );
    }

    public static function officeBackup(): OfficeBackupService
    {
        return self::make(
            OfficeBackupService::class,
            static fn (): OfficeBackupService => new OfficeBackupService(
                (string) Config::get('office.backup_control_dir', BASE_PATH . '/storage/office-backup')
            )
        );
    }

    public static function storageRepository(): StorageRepository
    {
        return self::make(StorageRepository::class, static fn (): StorageRepository => new StorageRepository());
    }

    public static function storage(): StorageService
    {
        return self::make(
            StorageService::class,
            static fn (): StorageService => new StorageService(
                self::storageRepository(),
                self::settings(),
                self::secretBox(),
                self::officeConfig()->isEnabled(),
                self::incidentRepository()
            )
        );
    }

    public static function incidentRepository(): IncidentRepository
    {
        return self::make(IncidentRepository::class, static fn (): IncidentRepository => new IncidentRepository());
    }

    public static function snapshots(): SnapshotService
    {
        return self::make(
            SnapshotService::class,
            static fn (): SnapshotService => new SnapshotService(self::storageRepository(), self::settings(), self::secretBox())
        );
    }

    public static function incidents(): IncidentService
    {
        return self::make(
            IncidentService::class,
            static fn (): IncidentService => new IncidentService(
                self::incidentRepository(),
                self::storageRepository(),
                self::settings()
            )
        );
    }

    public static function officeApps(): OfficeAppService
    {
        return self::make(
            OfficeAppService::class,
            static fn (): OfficeAppService => new OfficeAppService(
                new OfficeAppRepository(),
                self::officeConfig(),
                self::settings(),
                static fn (): bool => self::orvantaConfig()->isEnabled()
            )
        );
    }
}
