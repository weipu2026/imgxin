<?php
declare(strict_types=1);

final class Http
{
    public static function json(array $payload, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(string $message, int $code = 400): never
    {
        self::json(['ok' => false, 'error' => $message], $code);
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** 是否处于 HTTPS。默认只认服务器变量，显式开启 trusted_proxy 后才接受反代头。 */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if ((bool)Config::base('trusted_proxy')) {
            $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            return $proto === 'https';
        }
        return false;
    }

    /** 当前请求推断出的站点根地址（没配 base_url 时兜底用） */
    public static function baseUrl(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9\.\-:\[\]]+$/', $host)) {
            $host = 'localhost';
        }
        return $scheme . '://' . $host;
    }

    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function bytes(int $n): string
    {
        if ($n < 1024) {
            return $n . ' B';
        }
        if ($n < 1024 * 1024) {
            return round($n / 1024, 1) . ' KB';
        }
        if ($n < 1024 * 1024 * 1024) {
            return round($n / 1024 / 1024, 2) . ' MB';
        }
        if ($n < 1024 * 1024 * 1024 * 1024) {
            return round($n / 1024 / 1024 / 1024, 2) . ' GB';
        }
        return round($n / 1024 / 1024 / 1024 / 1024, 2) . ' TB';
    }

    /** 把 php.ini 的 128M / 2G / -1 之类的值换算成字节；-1 表示不限制。 */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (float)$value < 0) {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num  = (float)$value;
        return (int)match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
