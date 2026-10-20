<?php

declare(strict_types=1);

namespace App\Core;

final class SessionManager
{
    public function __construct(private readonly Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }

        $secure = $this->config->get('app.security.session_secure');
        if ($secure === null) {
            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        }

        session_name('ASSETSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) $secure,
            'httponly' => true,
            'samesite' => (string) $this->config->get('app.security.session_same_site', 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) $this->config->get('app.security.session_lifetime', 480) * 60));
        session_start();

        $now = time();
        $idle = (int) $this->config->get('app.security.session_lifetime', 480) * 60;
        if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $idle) {
            $this->destroy();
            session_start();
        }
        $_SESSION['last_activity'] = $now;
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();
    }
}
