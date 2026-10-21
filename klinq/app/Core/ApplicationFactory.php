<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Core\Config;
use App\Middleware\AuthorizationMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequestLogMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\SystemSettingRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Security\PasswordHasher;
use App\Security\Permissions;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\DashboardService;
use App\Services\Helpdesk\SecondLevelReminderService;
use App\Services\Ldap\LdapAuthenticator;
use App\Services\Ldap\FakeLdapClient;
use App\Services\Ldap\LdapClient;
use App\Services\Ldap\LdapClientInterface;
use App\Services\SettingsService;
use PDO;

/**
 * Baut den Dependency-Container auf und verdrahtet Middleware, Routen und Views.
 * Weitere Module registrieren ihre Dienste in Providern (app/Core/Providers/*.php).
 */
final class ApplicationFactory
{
    public static function create(string $basePath, ?Container $container = null): Application
    {
        $container = $container ?? self::container($basePath);
        /** @var Config $config */
        $config = $container->get(Config::class);

        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Berlin'));

        /** @var SessionManager $session */
        $session = $container->get(SessionManager::class);
        $session->start();

        /** @var View $view */
        $view = $container->get(View::class);
        /** @var CurrentUser $currentUser */
        $currentUser = $container->get(CurrentUser::class);
        /** @var Permissions $permissions */
        $permissions = $container->get(Permissions::class);
        /** @var CsrfTokenManager $csrf */
        $csrf = $container->get(CsrfTokenManager::class);

        $view->share('appName', (string) $config->get('app.name'));
        $view->share('companyName', (string) $config->get('app.company_name'));
        $view->share('csrf', $csrf->token());
        $view->share('user', $currentUser->user());
        $view->share('can', static fn (string $permission): bool => $currentUser->can($permission));
        $view->share('roleLabels', $permissions->roleLabels());
        $view->share('openCounts', self::lazyOpenCounts($container, $currentUser));
        $view->share('helpdeskEnabled', (bool) $config->get('helpdesk.enabled', true));
        $view->share('helpdeskNotifications', self::lazyHelpdeskNotifications($container, $currentUser));
        $view->share('lanpaDesignCss', $container->get(\App\Services\Lanpa\LanpaBridge::class)->designCssUrl());

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $container->get(AuditLogService::class)->setIpAddress((string) $_SERVER['REMOTE_ADDR']);
        }

        /** @var Router $router */
        $router = $container->get(Router::class);
        $router->addMiddleware($container->get(SecurityHeadersMiddleware::class));
        $router->addMiddleware($container->get(RequestLogMiddleware::class));
        $router->addMiddleware($container->get(CsrfMiddleware::class));
        $router->addMiddleware($container->get(AuthorizationMiddleware::class));

        $registerRoutes = require $basePath . '/routes/web.php';
        $registerRoutes($router, $container);

        return new Application($router, $view, $container->get(Logger::class), (bool) $config->get('app.debug'));
    }

    /** Erstellt den Container mit allen Kern-Diensten (auch für CLI/Tests nutzbar). */
    public static function container(string $basePath, ?string $databaseOverride = null): Container
    {
        Env::load($basePath . '/.env');

        $c = new Container();
        $c->instance('basePath', $basePath);
        $c->singleton(Config::class, static fn (): Config => new Config($basePath . '/config'));
        $c->singleton(Logger::class, static function (Container $c): Logger {
            $config = $c->get(Config::class);
            $dir = $c->get('basePath') . '/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            return new Logger($dir . '/app-' . date('Y-m-d') . '.log', (string) $config->get('app.log_level', 'info'));
        });
        $c->singleton(PDO::class, static fn (Container $c): PDO => Database::connect($c->get(Config::class), $databaseOverride));
        $c->singleton(View::class, static fn (Container $c): View => new View($c->get('basePath') . '/resources/views'));
        $c->singleton(Router::class, static fn (): Router => new Router());
        $c->singleton(SessionManager::class, static fn (Container $c): SessionManager => new SessionManager($c->get(Config::class)));
        $c->singleton(CsrfTokenManager::class, static fn (): CsrfTokenManager => new CsrfTokenManager());
        $c->singleton(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());
        $c->singleton(Permissions::class, static fn (Container $c): Permissions => new Permissions($c->get(Config::class)));
        $c->singleton(CurrentUser::class, static fn (Container $c): CurrentUser => new CurrentUser($c->get(Permissions::class)));

        // Middleware
        $c->singleton(SecurityHeadersMiddleware::class, static fn (Container $c): SecurityHeadersMiddleware => new SecurityHeadersMiddleware($c->get(Config::class)));
        $c->singleton(RequestLogMiddleware::class, static fn (Container $c): RequestLogMiddleware => new RequestLogMiddleware($c->get(Logger::class)));
        $c->singleton(CsrfMiddleware::class, static fn (Container $c): CsrfMiddleware => new CsrfMiddleware($c->get(CsrfTokenManager::class)));
        $c->singleton(AuthorizationMiddleware::class, static fn (Container $c): AuthorizationMiddleware => new AuthorizationMiddleware($c->get(Router::class), $c->get(CurrentUser::class), $c->get(UserRepository::class)));

        // Repositories
        $c->singleton(UserRepository::class, static fn (Container $c): UserRepository => new UserRepository($c->get(PDO::class)));
        $c->singleton(AuditLogRepository::class, static fn (Container $c): AuditLogRepository => new AuditLogRepository($c->get(PDO::class)));
        $c->singleton(SystemSettingRepository::class, static fn (Container $c): SystemSettingRepository => new SystemSettingRepository($c->get(PDO::class)));
        $c->singleton(EmployeeRepository::class, static fn (Container $c): EmployeeRepository => new EmployeeRepository($c->get(PDO::class)));

        // Services
        $c->singleton(AuditLogService::class, static fn (Container $c): AuditLogService => new AuditLogService($c->get(AuditLogRepository::class), $c->get(CurrentUser::class)));
        $c->singleton(SettingsService::class, static fn (Container $c): SettingsService => new SettingsService($c->get(SystemSettingRepository::class), $c->get(Config::class)));
        $c->singleton(DashboardService::class, static fn (Container $c): DashboardService => new DashboardService($c->get(PDO::class)));
        $c->singleton(LdapClientInterface::class, static function (Container $c): LdapClientInterface {
            $config = $c->get(Config::class);
            if ($config->get('ldap.driver') === 'fake') {
                $file = (string) $config->get('ldap.fake_file');

                return new FakeLdapClient(str_starts_with($file, '/') ? $file : $c->get('basePath') . '/' . $file);
            }

            return new LdapClient((string) $config->get('ldap.host'), (int) $config->get('ldap.port', 636), (int) $config->get('ldap.page_size', 500));
        });
        $c->singleton(LdapAuthenticator::class, static fn (Container $c): LdapAuthenticator => new LdapAuthenticator(
            $c->get(Config::class),
            $c->get(LdapClientInterface::class),
            $c->get(EmployeeRepository::class),
            $c->get(Logger::class)
        ));
        $c->singleton(AuthService::class, static function (Container $c): AuthService {
            $config = $c->get(Config::class);
            $ldap = (bool) $config->get('ldap.enabled') && (bool) $config->get('ldap.auth.enabled') ? $c->get(LdapAuthenticator::class) : null;

            return new AuthService($c->get(UserRepository::class), $c->get(PasswordHasher::class), $config, $c->get(Logger::class), $ldap);
        });

        // Controller
        $c->singleton(AuthController::class, static fn (Container $c): AuthController => new AuthController(
            $c->get(View::class),
            $c->get(CurrentUser::class),
            $c->get(AuthService::class),
            $c->get(SessionManager::class),
            $c->get(CsrfTokenManager::class),
            $c->get(AuditLogService::class),
            $c->get(UserRepository::class),
            $c->get(Config::class)
        ));
        $c->singleton(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
            $c->get(View::class),
            $c->get(CurrentUser::class),
            $c->get(DashboardService::class)
        ));

        foreach (glob($basePath . '/app/Core/Providers/*.php') ?: [] as $provider) {
            $register = require $provider;
            $register($c);
        }

        return $c;
    }

    /**
     * In-App-Benachrichtigungen des 2nd-Level-Supports (Glocke in der Kopfzeile). Wie bei openCounts
     * werden die Daten nur berechnet, wenn ein Layout sie anfordert (lazy), damit JSON-Antworten
     * keine unnötigen Abfragen auslösen.
     */
    private static function lazyHelpdeskNotifications(Container $container, CurrentUser $currentUser): object
    {
        return new class($container, $currentUser) {
            private ?bool $responsible = null;
            /** @var array<int,array{id:int,number:string,subject:string,url:string}>|null */
            private ?array $items = null;

            public function __construct(private readonly Container $container, private readonly CurrentUser $currentUser) {}

            /** Ob der angemeldete Benutzer der heutige 2nd Level Support ist (Glocke sichtbar). */
            public function visible(): bool
            {
                return $this->responsible();
            }

            /** @return array<int,array{id:int,number:string,subject:string,url:string}> */
            public function all(): array
            {
                if (!$this->responsible()) {
                    return [];
                }
                if ($this->items === null) {
                    $this->items = $this->resolveItems();
                }

                return $this->items;
            }

            public function count(): int
            {
                return count($this->all());
            }

            private function responsible(): bool
            {
                if ($this->responsible === null) {
                    $this->responsible = $this->resolveResponsible();
                }

                return $this->responsible;
            }

            private function resolveResponsible(): bool
            {
                $userId = $this->currentUser->id();
                if ($userId === null || !(bool) $this->container->get(Config::class)->get('helpdesk.enabled', true)) {
                    return false;
                }
                try {
                    return $this->container->get(SecondLevelReminderService::class)->secondLevelUserIdToday() === (int) $userId;
                } catch (\Throwable) {
                    return false;
                }
            }

            /** @return array<int,array{id:int,number:string,subject:string,url:string}> */
            private function resolveItems(): array
            {
                try {
                    return $this->container->get(SecondLevelReminderService::class)->notificationsFor((int) $this->currentUser->id());
                } catch (\Throwable) {
                    return [];
                }
            }
        };
    }

    /**
     * Die Zähler in der Navigation werden nur berechnet, wenn ein Layout sie anzeigt
     * (lazy über ArrayAccess-ähnliches Objekt vermeidet unnötige Abfragen bei JSON-Antworten).
     */
    private static function lazyOpenCounts(Container $container, CurrentUser $currentUser): \ArrayAccess
    {
        return new class($container, $currentUser) implements \ArrayAccess {
            /** @var array<string,int>|null */
            private ?array $counts = null;

            public function __construct(private readonly Container $container, private readonly CurrentUser $currentUser) {}

            /** @return array<string,int> */
            private function counts(): array
            {
                if ($this->counts === null) {
                    $this->counts = $this->currentUser->isAuthenticated() && $this->currentUser->can('movements.view')
                        ? $this->container->get(DashboardService::class)->openCounts()
                        : ['checkouts' => 0, 'returns' => 0];
                    $this->counts += $this->ticketCounts();
                }

                return $this->counts;
            }

            /** Ticket-Badges: Agenten sehen ihre zugewiesenen offenen Tickets, Portalnutzer ihre eigenen. @return array<string,int> */
            private function ticketCounts(): array
            {
                $counts = ['tickets' => 0, 'portal_tickets' => 0];
                $userId = $this->currentUser->id();
                if ($userId === null || !(bool) $this->container->get(Config::class)->get('helpdesk.enabled', true)) {
                    return $counts;
                }
                try {
                    $tickets = $this->container->get(TicketRepository::class);
                    if ($this->currentUser->can('helpdesk.view')) {
                        $counts['tickets'] = $tickets->openCountForUser((int) $userId, true);
                    }
                    if ($this->currentUser->can('portal.view')) {
                        $counts['portal_tickets'] = $tickets->openCountForUser((int) $userId, false);
                    }
                } catch (\Throwable) {
                    // Help-Desk-Tabellen fehlen (Migration noch nicht eingespielt): Badge stillschweigend auslassen
                }

                return $counts;
            }

            public function offsetExists(mixed $offset): bool { return isset($this->counts()[$offset]); }
            public function offsetGet(mixed $offset): mixed { return $this->counts()[$offset] ?? 0; }
            public function offsetSet(mixed $offset, mixed $value): void {}
            public function offsetUnset(mixed $offset): void {}
        };
    }
}
