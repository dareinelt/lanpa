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
            static fn (): \App\Services\Orvanta\OrvantaConfigService => new \App\Services\Orvanta\OrvantaConfigService(
                self::orvantaRepository(),
                self::secretBox(),
                self::orvantaExchangeHostRepository()
            )
        );
    }

    public static function orvantaExchangeHostRepository(): \App\Repositories\OrvantaExchangeHostRepository
    {
        return self::make(
            \App\Repositories\OrvantaExchangeHostRepository::class,
            static fn (): \App\Repositories\OrvantaExchangeHostRepository => new \App\Repositories\OrvantaExchangeHostRepository()
        );
    }

    /**
     * Lastverteilung und Failover ueber die Hosts der Exchange-DAG. Ohne
     * gepflegte Hostliste wirkt nur der konfigurierte Exchange-Server.
     * Die Sitzungsaffinitaet gilt je Benutzer und Client (nicht je PHP-
     * Sitzung), damit ein Postfach nie auf mehrere Hosts verteilt wird.
     */
    public static function orvantaExchangePool(): \App\Services\Orvanta\OrvantaExchangePool
    {
        return self::make(
            \App\Services\Orvanta\OrvantaExchangePool::class,
            static fn (): \App\Services\Orvanta\OrvantaExchangePool => new \App\Services\Orvanta\OrvantaExchangePool(
                self::orvantaExchangeHostRepository(),
                self::orvantaConfig(),
                static function (): string {
                    static $key = null;
                    if ($key !== null) {
                        return $key;
                    }
                    $sessionId = \App\Security\Session::id();
                    if ($sessionId === '') {
                        return '';
                    }
                    $request = Request::fromGlobals();
                    try {
                        $user = self::sso()->resolve($request);
                    } catch (\Throwable) {
                        $user = null;
                    }

                    return $key = \App\Services\Orvanta\OrvantaExchangePool::affinityKey($user, $request->clientIp(), $sessionId);
                },
                static fn (): array => \App\Support\ClientAddress::from(\App\Core\Request::fromGlobals())
            )
        );
    }

    public static function authMetricsRepository(): \App\Repositories\AuthMetricsRepository
    {
        return self::make(
            \App\Repositories\AuthMetricsRepository::class,
            static fn (): \App\Repositories\AuthMetricsRepository => new \App\Repositories\AuthMetricsRepository()
        );
    }

    /**
     * Kennzahlen des auth-Containers (Einstieg/Reverse-Proxy) fuer die Karte
     * auf dem Admin-Dashboard: der Container meldet sie ueber
     * POST /internal/auth-metrics, die Anzeige entsteht aus den Proben.
     */
    public static function authMetrics(): \App\Services\Auth\AuthMetricsService
    {
        return self::make(
            \App\Services\Auth\AuthMetricsService::class,
            static fn (): \App\Services\Auth\AuthMetricsService => new \App\Services\Auth\AuthMetricsService(
                self::authMetricsRepository(),
                null,
                self::settings()
            )
        );
    }

    public static function containerMetricsRepository(): \App\Repositories\ContainerMetricsRepository
    {
        return self::make(
            \App\Repositories\ContainerMetricsRepository::class,
            static fn (): \App\Repositories\ContainerMetricsRepository => new \App\Repositories\ContainerMetricsRepository()
        );
    }

    /**
     * Kennzahlen der uebrigen Container (CPU und Arbeitsspeicher) fuer die
     * Kacheln auf dem Admin-Dashboard: der Sammel-Container meldet sie ueber
     * POST /internal/container-metrics (docker/monitor/metrics.py), die
     * Anzeige entsteht aus den Proben.
     */
    public static function containerMetrics(): \App\Services\Monitoring\ContainerMetricsService
    {
        return self::make(
            \App\Services\Monitoring\ContainerMetricsService::class,
            static fn (): \App\Services\Monitoring\ContainerMetricsService => new \App\Services\Monitoring\ContainerMetricsService(
                self::containerMetricsRepository()
            )
        );
    }

    public static function orvantaFlowRepository(): \App\Repositories\OrvantaFlowRepository
    {
        return self::make(
            \App\Repositories\OrvantaFlowRepository::class,
            static fn (): \App\Repositories\OrvantaFlowRepository => new \App\Repositories\OrvantaFlowRepository()
        );
    }

    /**
     * Praesenz der Orvanta-Benutzer (Nachrichtenfluss-Dashboard): erfasst die
     * Aktivitaet an den Eintrittstellen der App (Seitenaufruf und
     * JSON-Schnittstelle) und schreibt Proben der aktiven Nutzer.
     */
    public static function orvantaPresence(): \App\Services\Orvanta\OrvantaPresenceService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaPresenceService::class,
            static fn (): \App\Services\Orvanta\OrvantaPresenceService => new \App\Services\Orvanta\OrvantaPresenceService(
                self::orvantaFlowRepository(),
                self::orvantaRepository()
            )
        );
    }

    /**
     * Nachrichtenfluss-Dashboard: Knoten, Kanten, Kennzahlen und Gesamtstatus
     * der am Mail- und Kalenderfluss beteiligten Bausteine.
     */
    public static function orvantaFlow(): \App\Services\Orvanta\OrvantaFlowService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaFlowService::class,
            static fn (): \App\Services\Orvanta\OrvantaFlowService => new \App\Services\Orvanta\OrvantaFlowService(
                self::orvantaPresence(),
                self::mailProxy(),
                self::mailProxyRepository(),
                self::orvantaExchangePool(),
                self::storage(),
                self::officeAi(),
                self::orvantaRepository(),
                self::orvantaConfig(),
                self::orvantaHostHealth()
            )
        );
    }

    /**
     * Selbstheilung des Host-Status der Exchange-DAG: prueft gestoerte Hosts
     * mit veraltetem Zustand nach, damit die Ansichten den aktuellen Stand
     * zeigen, ohne dass ein Administrator „Verbindung testen“ ausloesen muss.
     */
    public static function orvantaHostHealth(): \App\Services\Orvanta\OrvantaHostHealthService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaHostHealthService::class,
            static fn (): \App\Services\Orvanta\OrvantaHostHealthService => new \App\Services\Orvanta\OrvantaHostHealthService(
                self::orvantaExchangePool(),
                self::orvantaExchange(),
                self::orvantaConfig()
            )
        );
    }

    public static function orvantaMailboxResolver(): \App\Services\Orvanta\OrvantaMailboxResolver
    {
        return self::make(
            \App\Services\Orvanta\OrvantaMailboxResolver::class,
            static fn (): \App\Services\Orvanta\OrvantaMailboxResolver => new \App\Services\Orvanta\OrvantaMailboxResolver(
                self::orvantaConfig(),
                self::identitySources(),
                app_logger()
            )
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
            static fn (): \App\Services\Orvanta\OrvantaExchangeService => new \App\Services\Orvanta\OrvantaExchangeService(
                self::exchangeTransport(),
                self::orvantaConfig(),
                BASE_PATH . '/storage/cache/orvanta_primary_smtp.json',
                self::orvantaExchangePool()
            )
        );
    }

    /**
     * Zusaetzlich berechtigte Postfaecher (Vollzugriff / "Senden als"). Die
     * Zuordnung pflegt der Adminbereich; erreichbar ist nur, was EWS mit dem
     * Dienstkonto bestaetigt.
     */
    public static function orvantaSharedMailboxRepository(): \App\Repositories\OrvantaSharedMailboxRepository
    {
        return self::make(
            \App\Repositories\OrvantaSharedMailboxRepository::class,
            static fn (): \App\Repositories\OrvantaSharedMailboxRepository => new \App\Repositories\OrvantaSharedMailboxRepository()
        );
    }

    public static function orvantaSharedMailboxes(): \App\Services\Orvanta\OrvantaSharedMailboxService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaSharedMailboxService::class,
            static fn (): \App\Services\Orvanta\OrvantaSharedMailboxService => new \App\Services\Orvanta\OrvantaSharedMailboxService(
                self::orvantaSharedMailboxRepository(),
                self::orvantaExchange(),
                app_logger(),
                self::orvantaDelegateDirectory()
            )
        );
    }

    public static function orvantaDelegateDirectory(): \App\Services\Orvanta\OrvantaDelegateDirectory
    {
        return self::make(
            \App\Services\Orvanta\OrvantaDelegateDirectory::class,
            static fn (): \App\Services\Orvanta\OrvantaDelegateDirectory => new \App\Services\Orvanta\OrvantaDelegateDirectory(
                self::orvantaConfig(),
                self::identitySources(),
                app_logger()
            )
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
                self::secretBox(),
                self::orvantaArchive(),
                self::orvantaMail()
            )
        );
    }

    // ------------------------------------------------------------------ SMTP-/IMAP-Proxy (Orvanta ohne Exchange)

    public static function mailProxyRepository(): \App\Repositories\MailProxyRepository
    {
        return self::make(\App\Repositories\MailProxyRepository::class, static fn (): \App\Repositories\MailProxyRepository => new \App\Repositories\MailProxyRepository());
    }

    public static function mailProxyCache(): \App\Services\MailProxy\MailProxyCache
    {
        return self::make(
            \App\Services\MailProxy\MailProxyCache::class,
            static fn (): \App\Services\MailProxy\MailProxyCache => new \App\Services\MailProxy\MailProxyCache(
                BASE_PATH . '/storage/cache/mail-proxy',
                max(0, min(3600, (int) Env::get('MAIL_PROXY_CACHE_TTL', '300')))
            )
        );
    }

    public static function mailProxyResolver(): \App\Services\MailProxy\MailProxyResolver
    {
        return self::make(
            \App\Services\MailProxy\MailProxyResolver::class,
            static fn (): \App\Services\MailProxy\MailProxyResolver => new \App\Services\MailProxy\MailProxyResolver(
                self::mailProxyRepository(),
                self::mailProxyCache(),
                self::secretBox(),
                app_logger()
            )
        );
    }

    /**
     * Interner HTTP-Transport zum Container mail-proxy (MAIL_PROXY_URL,
     * nur internes Docker-Netz; Anfragen HMAC-signiert).
     */
    public static function mailProxyTransport(): \App\Contracts\MailProxyTransportInterface
    {
        return self::make(
            \App\Contracts\MailProxyTransportInterface::class,
            static fn (): \App\Contracts\MailProxyTransportInterface => new \App\Services\MailProxy\HttpMailProxyTransport(
                (string) Env::get('MAIL_PROXY_URL', 'http://mail-proxy:8025'),
                self::secretBox()
            )
        );
    }

    public static function mailProxy(): \App\Services\MailProxy\MailProxyService
    {
        return self::make(
            \App\Services\MailProxy\MailProxyService::class,
            static fn (): \App\Services\MailProxy\MailProxyService => new \App\Services\MailProxy\MailProxyService(
                self::mailProxyRepository(),
                self::mailProxyCache(),
                self::secretBox(),
                self::mailProxyTransport(),
                self::mailProxyResolver(),
                app_logger(),
                static function (): array {
                    $ldap = self::settings()->ldapConfig();

                    return [
                        'label' => (string) ($ldap['label'] ?? ''),
                        'base_dn' => (string) ($ldap['base_dn'] ?? ''),
                        'hosts' => array_values((array) ($ldap['hosts'] ?? [])),
                    ];
                }
            )
        );
    }

    /**
     * Auswahl des Orvanta-Mail-Backends je Benutzer (Exchange oder Proxy).
     */
    public static function orvantaMail(): \App\Services\MailProxy\OrvantaMailRouter
    {
        return self::make(
            \App\Services\MailProxy\OrvantaMailRouter::class,
            static fn (): \App\Services\MailProxy\OrvantaMailRouter => new \App\Services\MailProxy\OrvantaMailRouter(
                self::mailProxyResolver(),
                self::mailProxyTransport(),
                static fn (): \App\Contracts\OrvantaMailBackendInterface => self::orvantaExchange(),
                self::mailProxyRepository(),
                app_logger()
            )
        );
    }

    /**
     * Gibt es mindestens eine aktive Proxy-Konfiguration? (Orvanta ist dann
     * auch ohne Exchange-Anbindung fuer zugeordnete Benutzer nutzbar.)
     */
    public static function mailProxyActive(): bool
    {
        try {
            return self::mailProxyRepository()->hasActiveServers();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function orvantaSignatureRepository(): \App\Repositories\OrvantaSignatureRepository
    {
        return self::make(\App\Repositories\OrvantaSignatureRepository::class, static fn (): \App\Repositories\OrvantaSignatureRepository => new \App\Repositories\OrvantaSignatureRepository());
    }

    public static function orvantaArchiveRepository(): \App\Repositories\OrvantaArchiveRepository
    {
        return self::make(\App\Repositories\OrvantaArchiveRepository::class, static fn (): \App\Repositories\OrvantaArchiveRepository => new \App\Repositories\OrvantaArchiveRepository());
    }

    public static function orvantaOofRepository(): \App\Repositories\OrvantaOofRepository
    {
        return self::make(\App\Repositories\OrvantaOofRepository::class, static fn (): \App\Repositories\OrvantaOofRepository => new \App\Repositories\OrvantaOofRepository());
    }

    public static function orvantaArchive(): \App\Services\Orvanta\OrvantaArchiveService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaArchiveService::class,
            static fn (): \App\Services\Orvanta\OrvantaArchiveService => new \App\Services\Orvanta\OrvantaArchiveService(
                self::orvantaArchiveRepository(),
                self::orvantaConfig(),
                self::orvantaExchange(),
                new \App\Services\Orvanta\NextcloudArchiveStorage(self::nextcloudFiles()),
                new \App\Services\Orvanta\MimeMessageParser(),
                null,
                static fn (): array => self::identitySourceMap()
            )
        );
    }

    /**
     * Signaturvorlagen: Zuordnung per AD-Gruppe, AD-Daten aus der Telefonliste,
     * Farben/Logo aus den Designeinstellungen.
     */
    public static function orvantaSignatures(): \App\Services\Orvanta\OrvantaSignatureService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaSignatureService::class,
            static fn (): \App\Services\Orvanta\OrvantaSignatureService => new \App\Services\Orvanta\OrvantaSignatureService(
                self::orvantaSignatureRepository(),
                self::phonebookRepository(),
                self::settings(),
                static fn (): ?array => self::logo()->current()
            )
        );
    }

    /**
     * Abwesenheitsnotizen: Zuordnung per AD-Gruppe, Text und Zeitraum je
     * Benutzer; uebertragen wird die Notiz auf den Exchange-Server.
     */
    public static function orvantaOof(): \App\Services\Orvanta\OrvantaOofService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaOofService::class,
            static fn (): \App\Services\Orvanta\OrvantaOofService => new \App\Services\Orvanta\OrvantaOofService(
                self::orvantaOofRepository(),
                self::orvantaSignatures(),
                self::orvantaConfig()
            )
        );
    }

    /**
     * Empfaenger-Vorschlaege: Telefonliste plus Verlauf gesendeter Adressen
     * (versteckte Datei in der Nextcloud des Benutzers, kurz in der Sitzung gehalten).
     */
    public static function orvantaRecipients(): \App\Services\Orvanta\OrvantaRecipientService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaRecipientService::class,
            static fn (): \App\Services\Orvanta\OrvantaRecipientService => new \App\Services\Orvanta\OrvantaRecipientService(
                self::orvantaConfig(),
                self::nextcloudFiles(),
                self::phonebookRepository(),
                static fn (string $key): mixed => \App\Security\Session::get($key),
                static function (string $key, mixed $value): void {
                    \App\Security\Session::put($key, $value);
                }
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

    public static function orvantaAiCharts(): \App\Services\Orvanta\OrvantaAiCharts
    {
        return self::make(
            \App\Services\Orvanta\OrvantaAiCharts::class,
            static fn (): \App\Services\Orvanta\OrvantaAiCharts => new \App\Services\Orvanta\OrvantaAiCharts(self::orvantaRepository())
        );
    }

    /**
     * Transport zum KI-Endpunkt (OpenAI-kompatibel, HTTP oder HTTPS wie in den
     * globalen KI-Einstellungen hinterlegt).
     */
    public static function aiTransport(): \App\Contracts\AiTransportInterface
    {
        return self::make(
            \App\Contracts\AiTransportInterface::class,
            static fn (): \App\Contracts\AiTransportInterface => new \App\Services\Orvanta\CurlAiTransport()
        );
    }

    /**
     * KI-Textunterstuetzung in Orvanta; das Modell stammt aus den globalen
     * KI-Einstellungen (officeAi()), Orvanta selbst haelt keine Modellwerte.
     */
    public static function orvantaAi(): \App\Services\Orvanta\OrvantaAiService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaAiService::class,
            static fn (): \App\Services\Orvanta\OrvantaAiService => new \App\Services\Orvanta\OrvantaAiService(
                self::officeAi(),
                self::aiTransport(),
                self::orvantaRepository(),
                (string) Config::get('office.ai_availability_cache_file', BASE_PATH . '/storage/cache/orvanta_ai.json'),
                min(120, max(5, (int) Config::get('office.ai_request_timeout', 30)))
            )
        );
    }

    /**
     * Deutsche Rechtschreibpruefung in Orvanta. Das aufbereitete Woerterbuch
     * entsteht beim Containerstart (scripts/spellcheck_dictionary.php) und wird
     * erst beim ersten Zugriff geladen; fehlt es, meldet isAvailable() false.
     * Fuer jeden Seitenaufbau genuegt isAvailable() (liest nur meta.json);
     * isUsable() laedt zusaetzlich Index und Regelwerk.
     */
    public static function orvantaSpellcheck(): \App\Services\Orvanta\OrvantaSpellcheckService
    {
        return self::make(
            \App\Services\Orvanta\OrvantaSpellcheckService::class,
            static fn (): \App\Services\Orvanta\OrvantaSpellcheckService => new \App\Services\Orvanta\OrvantaSpellcheckService(
                self::orvantaSpellcheckDictionary(),
                Config::get('office.spellcheck_enabled', true) !== false
            )
        );
    }

    public static function orvantaSpellcheckDictionary(): \App\Services\Orvanta\OrvantaSpellcheckDictionary
    {
        return self::make(
            \App\Services\Orvanta\OrvantaSpellcheckDictionary::class,
            static fn (): \App\Services\Orvanta\OrvantaSpellcheckDictionary => new \App\Services\Orvanta\OrvantaSpellcheckDictionary(
                (string) Config::get('office.spellcheck_dir', BASE_PATH . '/storage/dictionaries/de_DE')
            )
        );
    }

    /**
     * Persoenliche Woerterbuecher der Rechtschreibpruefung (Datenbank).
     */
    public static function orvantaSpellcheckUserWords(): \App\Services\Orvanta\OrvantaSpellcheckUserWords
    {
        return self::make(
            \App\Services\Orvanta\OrvantaSpellcheckUserWords::class,
            static fn (): \App\Services\Orvanta\OrvantaSpellcheckUserWords => new \App\Services\Orvanta\OrvantaSpellcheckUserWords(
                new \App\Repositories\OrvantaSpellcheckWordRepository()
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
                    self::adGroupRepository(),
                    static function (int $deactivated): void {
                        self::mailProxy()->invalidate('ad sync deactivated users', ['deactivated' => $deactivated]);
                    }
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
                static fn (): bool => self::orvantaConfig()->isEnabled() || self::mailProxyActive(),
                static function (array $groups): bool {
                    try {
                        return self::adminGroups()->isKaepMember($groups);
                    } catch (\Throwable) {
                        // Ohne Gruppentabelle (Migration ausstehend) keine Kachel.
                        return false;
                    }
                }
            )
        );
    }
}
