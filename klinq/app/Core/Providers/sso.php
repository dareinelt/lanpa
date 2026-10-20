<?php

declare(strict_types=1);

use App\Controllers\LanpaSsoController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Core\SessionManager;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Security\LanpaSsoToken;
use App\Services\AuditLogService;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Lanpa\LanpaBridge;
use App\Services\Lanpa\LanpaIdentityService;
use App\Services\Lanpa\LanpaUserService;

/**
 * Anmeldung ueber das Intranet (LANPA).
 *
 * Die Windows-Anmeldung stellt ausschliesslich der auth-Container des Intranets fest.
 * Diese Anwendung bringt keine eigene Kerberos-/AD-Anbindung mehr mit: Sie nimmt den
 * signierten Einstiegsschein des Intranets entgegen, legt den Benutzer an bzw.
 * aktualisiert ihn und uebernimmt die Sitzung.
 */
return static function (Container $c): void {
    $c->singleton(LanpaBridge::class, static fn (Container $c): LanpaBridge => new LanpaBridge(
        $c->get(Config::class),
        $c->get(AiTransportInterface::class)
    ));
    $c->singleton(LanpaSsoToken::class, static fn (Container $c): LanpaSsoToken => new LanpaSsoToken(
        $c->get(Config::class),
        (string) $c->get('basePath')
    ));
    $c->singleton(LanpaIdentityService::class, static fn (): LanpaIdentityService => new LanpaIdentityService());
    $c->singleton(LanpaUserService::class, static fn (Container $c): LanpaUserService => new LanpaUserService(
        $c->get(UserRepository::class),
        $c->get(Logger::class)
    ));
    $c->singleton(LanpaSsoController::class, static fn (Container $c): LanpaSsoController => new LanpaSsoController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(LanpaSsoToken::class),
        $c->get(LanpaUserService::class),
        $c->get(LanpaIdentityService::class),
        $c->get(SessionManager::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuditLogService::class),
        $c->get(Logger::class)
    ));
};
