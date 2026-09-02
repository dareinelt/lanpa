<?php

declare(strict_types=1);

/**
 * Legt ein Administrationskonto an oder setzt dessen Passwort zurueck.
 *
 * Aufruf:
 *   php scripts/create_admin.php <benutzername> [passwort]
 *   ADMIN_USERNAME=admin ADMIN_PASSWORD=... php scripts/create_admin.php
 *
 * Ohne Passwort wird ein sicheres Zufallspasswort erzeugt und einmalig ausgegeben.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Repositories\AdminUserRepository;

$username = $argv[1] ?? Env::get('ADMIN_USERNAME', 'admin');
$password = $argv[2] ?? Env::get('ADMIN_PASSWORD');

$username = trim((string) $username);
if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
    fwrite(STDERR, 'Ungültiger Benutzername (erlaubt: 3-64 Zeichen, A-Z a-z 0-9 . _ -).' . PHP_EOL);
    exit(1);
}

$generated = false;
if ($password === null || $password === '') {
    $password = bin2hex(random_bytes(9));
    $generated = true;
}

if (strlen($password) < 12) {
    fwrite(STDERR, 'Das Passwort muss mindestens 12 Zeichen lang sein.' . PHP_EOL);
    exit(1);
}

Database::connection();
$repository = new AdminUserRepository();
$hash = password_hash($password, PASSWORD_DEFAULT);

if ($repository->usernameExists($username)) {
    $user = $repository->findActiveByUsername($username);
    if ($user === null) {
        fwrite(STDERR, 'Der Benutzer existiert, ist aber deaktiviert.' . PHP_EOL);
        exit(1);
    }

    $repository->updatePasswordHash((int) $user['id'], $hash);
    fwrite(STDOUT, 'Passwort für "' . $username . '" wurde aktualisiert.' . PHP_EOL);
} else {
    $repository->create($username, $hash);
    fwrite(STDOUT, 'Administrationskonto "' . $username . '" wurde angelegt.' . PHP_EOL);
}

if ($generated) {
    fwrite(STDOUT, 'Generiertes Passwort (bitte sofort notieren und ändern): ' . $password . PHP_EOL);
}
