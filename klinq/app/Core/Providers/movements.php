<?php

declare(strict_types=1);

use App\Controllers\DocumentController;
use App\Controllers\Mobile\MobileController;
use App\Controllers\MovementController;
use App\Core\Config;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\DocumentService;
use App\Services\MailClient;
use App\Services\MovementService;
use App\Services\PdfClient;
use App\Services\SettingsService;
use App\Core\Logger;

return static function (Container $c): void {
    $c->singleton(MovementRepository::class, static fn (Container $c): MovementRepository => new MovementRepository($c->get(PDO::class)));
    $c->singleton(DocumentRepository::class, static fn (Container $c): DocumentRepository => new DocumentRepository($c->get(PDO::class)));

    $c->singleton(DocumentService::class, static fn (Container $c): DocumentService => new DocumentService(
        $c->get(DocumentRepository::class),
        $c->get(Config::class),
        $c->get(CurrentUser::class),
        $c->get(AuditLogService::class)
    ));
    $c->singleton(PdfClient::class, static fn (Container $c): PdfClient => new PdfClient($c->get(Config::class), $c->get(Logger::class)));
    $c->singleton(MailClient::class, static fn (Container $c): MailClient => new MailClient($c->get(Config::class), $c->get(Logger::class)));
    $c->singleton(MovementService::class, static fn (Container $c): MovementService => new MovementService(
        $c->get(MovementRepository::class),
        $c->get(AssetRepository::class),
        $c->get(AssetStatusRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(AssetHistoryRepository::class),
        $c->get(AssetService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(DocumentService::class),
        $c->get(PdfClient::class),
        $c->get(MailClient::class),
        $c->get(SettingsService::class),
        $c->get(Logger::class)
    ));

    $c->singleton(DocumentController::class, static fn (Container $c): DocumentController => new DocumentController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(DocumentRepository::class),
        $c->get(DocumentService::class)
    ));
    $c->singleton(MovementController::class, static fn (Container $c): MovementController => new MovementController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(MovementRepository::class),
        $c->get(MovementService::class),
        $c->get(DocumentRepository::class),
        $c->get(DocumentService::class),
        $c->get(EmployeeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(AssetTypeRepository::class),
        $c->get(AuthService::class)
    ));
    $c->singleton(MobileController::class, static fn (Container $c): MobileController => new MobileController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(AssetRepository::class),
        $c->get(MovementRepository::class),
        $c->get(MovementService::class),
        $c->get(DocumentService::class),
        $c->get(EmployeeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(MailClient::class)
    ));
};
