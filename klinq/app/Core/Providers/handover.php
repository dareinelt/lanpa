<?php

declare(strict_types=1);

use App\Controllers\HandoverController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Core\View;
use App\Repositories\DocumentRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\HandoverRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\DocumentService;
use App\Services\HandoverService;
use App\Services\MailClient;
use App\Services\PdfClient;
use App\Services\SettingsService;

return static function (Container $c): void {
    $c->singleton(HandoverRepository::class, static fn (Container $c): HandoverRepository => new HandoverRepository($c->get(PDO::class)));
    $c->singleton(PdfClient::class, static fn (Container $c): PdfClient => new PdfClient($c->get(Config::class), $c->get(Logger::class)));
    $c->singleton(MailClient::class, static fn (Container $c): MailClient => new MailClient($c->get(Config::class), $c->get(Logger::class)));
    $c->singleton(HandoverService::class, static fn (Container $c): HandoverService => new HandoverService(
        $c->get(HandoverRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(DocumentService::class),
        $c->get(DocumentRepository::class),
        $c->get(PdfClient::class),
        $c->get(SettingsService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(Logger::class),
        $c->get(MailClient::class)
    ));
    $c->singleton(HandoverController::class, static fn (Container $c): HandoverController => new HandoverController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(HandoverService::class),
        $c->get(HandoverRepository::class),
        $c->get(DocumentService::class),
        $c->get(PdfClient::class),
        $c->get(MailClient::class)
    ));
};
