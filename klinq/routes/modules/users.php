<?php

declare(strict_types=1);

use App\Controllers\ProfileController;
use App\Controllers\UserController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): UserController => $c->get(UserController::class);
    $router->get('/admin/users', static fn (Request $r): Response => $ctl()->index($r), 'users.manage');
    $router->get('/admin/users/new', static fn (Request $r): Response => $ctl()->create($r), 'users.manage');
    $router->post('/admin/users', static fn (Request $r): Response => $ctl()->store($r), 'users.manage');
    $router->get('/admin/users/{id}/edit', static fn (Request $r): Response => $ctl()->edit($r), 'users.manage');
    $router->post('/admin/users/{id}', static fn (Request $r): Response => $ctl()->update($r), 'users.manage');
    $router->post('/admin/users/{id}/toggle-active', static fn (Request $r): Response => $ctl()->toggleActive($r), 'users.manage');
    $router->post('/admin/users/{id}/password', static fn (Request $r): Response => $ctl()->resetPassword($r), 'users.manage');

    // Eigenes Passwort – jeder angemeldete Benutzer
    $profile = static fn (): ProfileController => $c->get(ProfileController::class);
    $router->get('/profile/password', static fn (Request $r): Response => $profile()->passwordForm($r));
    $router->post('/profile/password', static fn (Request $r): Response => $profile()->changePassword($r));
};
