<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name = (string)(Config::base('session_name') ?: 'imgxin');
        $https = Http::isHttps();

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** 密码哈希：优先取后台设置的，其次取 config.php 里的 */
    private static function hash(): string
    {
        $hash = trim((string)Config::get('admin_hash', ''));
        if ($hash === '') {
            $hash = trim((string)(Config::base('admin_hash') ?: ''));
        }
        return $hash;
    }

    public static function configured(): bool
    {
        return self::hash() !== '';
    }

    public static function check(string $password): bool
    {
        $hash = self::hash();
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function user(): bool
    {
        self::start();
        return !empty($_SESSION['imgxin_ok']);
    }

    public static function login(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['imgxin_ok'] = true;
        $_SESSION['imgxin_at'] = time();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, [
                'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => (bool)$p['secure'], 'httponly' => (bool)$p['httponly'], 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['imgxin_csrf'])) {
            $_SESSION['imgxin_csrf'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['imgxin_csrf'];
    }

    public static function csrfOk(?string $token): bool
    {
        self::start();
        $expect = (string)($_SESSION['imgxin_csrf'] ?? '');
        return $token !== null && $token !== '' && $expect !== '' && hash_equals($expect, $token);
    }
}
