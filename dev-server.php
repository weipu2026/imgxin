<?php
declare(strict_types=1);

/**
 * 本地预览用的内置服务器入口，线上部署用 nginx，不需要这个文件。
 *
 * 用法（在 imgxin 目录下）：
 *   php -S 127.0.0.1:8899 dev-server.php
 *
 * 作用：把 /i/ 的直链映射到 storage/，模拟线上 nginx 的 alias 行为。
 */

$root = __DIR__;
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/i/([a-z0-9][a-z0-9\-_]{0,31})/(\d{6})/([a-f0-9]{8,64})\.([a-z0-9]+)$#', $uri, $m)) {
    $file = $root . '/storage/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '.' . $m[4];
    if (!is_file($file)) {
        http_response_code(404);
        echo '404';
        return true;
    }
    $types = [
        'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'gif'  => 'image/gif',  'avif' => 'image/avif', 'bmp' => 'image/bmp',
    ];
    header('Content-Type: ' . ($types[$m[4]] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return true;
}

$path = $root . '/public' . $uri;

if (is_file($path)) {
    if (str_ends_with($path, '.php')) {
        require $path;
        return true;
    }

    // 内置服务器的文档根目录是项目根目录，public 下的静态资源需要显式回源。
    $types = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = $types[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($path) : false);
    header('Content-Type: ' . ($type ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    return true;
}

if ($uri === '/' || $uri === '/index.php') {
    require $root . '/public/index.php';
    return true;
}

http_response_code(404);
echo '404';
return true;
