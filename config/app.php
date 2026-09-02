<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'Intranet'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost:8080'),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Berlin'),
    'locale' => 'de_DE',
    'session_name' => Env::get('APP_SESSION_NAME', 'INTRANETSESSID'),
    'force_secure_cookies' => Env::bool('APP_FORCE_SECURE_COOKIES', false),
    'session_idle_timeout' => Env::int('APP_SESSION_IDLE_TIMEOUT', 3600),
    'log_level' => Env::get('APP_LOG_LEVEL', 'info'),
    'log_file' => BASE_PATH . '/storage/logs/app.log',
    'upload_path' => BASE_PATH . '/storage/uploads',
    'max_logo_bytes' => Env::int('APP_MAX_LOGO_BYTES', 512 * 1024),
    'allow_svg_logo' => Env::bool('APP_ALLOW_SVG_LOGO', true),
];
