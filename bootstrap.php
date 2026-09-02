<?php

declare(strict_types=1);

/**
 * Bootstrap: Autoloader, Konfiguration, Fehlerbehandlung.
 * Wird sowohl vom Webserver (public/index.php) als auch von den CLI-Skripten genutzt.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', str_replace('\\', '/', __DIR__));
}

require_once BASE_PATH . '/app/Core/Autoloader.php';

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Env;
use App\Core\Logger;
use App\Core\View;

Autoloader::register('App', BASE_PATH . '/app');

Env::load(BASE_PATH . '/.env');
Config::boot(BASE_PATH . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Berlin'));
mb_internal_encoding('UTF-8');
setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'German');

View::setViewPath(BASE_PATH . '/views');

$appDebug = (bool) Config::get('app.debug', false);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Zentraler Logger.
 */
function app_logger(): Logger
{
    static $logger = null;

    if (!$logger instanceof Logger) {
        $logger = new Logger(
            (string) Config::get('app.log_file', BASE_PATH . '/storage/logs/app.log'),
            (string) Config::get('app.log_level', 'info')
        );
    }

    return $logger;
}

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});
