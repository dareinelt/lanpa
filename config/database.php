<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'driver' => 'mysql',
    'host' => Env::get('DB_HOST', 'db'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::get('DB_NAME', 'intranet'),
    'username' => Env::get('DB_USER', 'intranet'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
