<?php

declare(strict_types=1);

use App\Controllers\AdSyncController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Repositories\AdSyncRunRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Services\Ad\AdUserLookupService;
use App\Services\Ad\AdUserMapper;
use App\Services\Ad\EmployeeSyncService;
use App\Services\Ldap\LdapClientInterface;

return static function (Container $c): void {
    $c->singleton(AdSyncRunRepository::class, static fn (Container $c): AdSyncRunRepository => new AdSyncRunRepository($c->get(\PDO::class)));
    $c->singleton(AdUserMapper::class, static fn (Container $c): AdUserMapper => new AdUserMapper((array) $c->get(Config::class)->get('ldap.attributes', [])));
    $c->singleton(AdUserLookupService::class, static fn (Container $c): AdUserLookupService => new AdUserLookupService(
        $c->get(Config::class),
        $c->get(LdapClientInterface::class),
        $c->get(AdUserMapper::class),
        $c->get(Logger::class)
    ));
    $c->singleton(EmployeeSyncService::class, static fn (Container $c): EmployeeSyncService => new EmployeeSyncService(
        $c->get(Config::class),
        $c->get(LdapClientInterface::class),
        $c->get(AdUserMapper::class),
        $c->get(EmployeeRepository::class),
        $c->get(AdSyncRunRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(Logger::class)
    ));
    $c->singleton(AdSyncController::class, static fn (Container $c): AdSyncController => new AdSyncController(
        $c,
        $c->get(EmployeeSyncService::class),
        $c->get(AdSyncRunRepository::class),
        $c->get(Config::class)
    ));
};
