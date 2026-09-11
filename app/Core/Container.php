<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\AdminUserRepository;
use App\Repositories\AnnouncementRepository;
use App\Repositories\ClickRepository;
use App\Repositories\EmergencyNumberRepository;
use App\Repositories\ImportantLinkRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SyncLogRepository;
use App\Security\Auth;
use App\Services\AdSyncService;
use App\Services\AdminUserService;
use App\Services\AnnouncementService;
use App\Services\EmergencyNumberService;
use App\Services\FaviconService;
use App\Services\ImportantLinkService;
use App\Services\LdapClient;
use App\Services\LogoService;
use App\Services\NavigationService;
use App\Services\PhonebookService;
use App\Services\SettingsService;
use App\Services\StatisticsService;
use App\Services\ThemeService;

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
        return self::make(NavigationService::class, static fn (): NavigationService => new NavigationService(self::navigationRepository()));
    }

    public static function phonebook(): PhonebookService
    {
        return self::make(PhonebookService::class, static fn (): PhonebookService => new PhonebookService(self::phonebookRepository()));
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
            static fn (): Auth => new Auth(self::adminUserRepository(), (int) Config::get('app.session_idle_timeout', 3600))
        );
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

    public static function adSync(): AdSyncService
    {
        return self::make(
            AdSyncService::class,
            static fn (): AdSyncService => new AdSyncService(
                new LdapClient(self::settings()->ldapConfig()),
                self::phonebookRepository(),
                self::syncLogRepository(),
                app_logger()
            )
        );
    }
}
