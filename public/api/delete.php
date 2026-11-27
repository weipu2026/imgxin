<?php
declare(strict_types=1);

/**
 * 删除图片（移入回收站，不是硬删除）。
 *
 * 两种调用方：
 * 1. 后台页面：登录态 + CSRF，参数 site + month + id
 * 2. 外部脚本 / Typecho 插件：X-API-Token（或 Bearer），参数 url，或 site + id
 *
 * Token 删除只移入回收站，永久删除仍然只能在后台操作。
 */

require dirname(__DIR__, 2) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Http::fail('只接受 POST', 405);
}

// ---------- 鉴权：登录态+CSRF，或 API Token ----------
$token = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '');
if ($token === '') {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $token = trim($m[1]);
    }
}
$expected = trim((string)Config::get('api_token', ''));
$byToken = $expected !== '' && $token !== '' && hash_equals($expected, $token);

if (!$byToken) {
    if (!Auth::user()) {
        Http::fail('未登录或登录已过期', 401);
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Auth::csrfOk(is_string($csrf) ? $csrf : null)) {
        Http::fail('会话校验失败，请刷新页面', 403);
    }
}

// ---------- 解析目标 ----------
$site  = strtolower(trim((string)($_POST['site'] ?? '')));
$month = trim((string)($_POST['month'] ?? ''));
$id    = strtolower(trim((string)($_POST['id'] ?? '')));
$url   = trim((string)($_POST['url'] ?? ''));

// 只给 URL 时，从 /i/{site}/{month}/{id}.{ext} 里拆出来（CDN 域名也能拆）
if ($url !== '' && ($id === '' || $site === '')) {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    if (preg_match('#/i/([a-z0-9][a-z0-9\-_]{0,31})/(\d{6})/([a-f0-9]{8,64})\.[a-z0-9]+$#i', $path, $m)) {
        $site  = strtolower($m[1]);
        $month = $m[2];
        $id    = strtolower($m[3]);
    }
}

if ($site === '') {
    $site = Sites::defaultSlug();
}

if (!Sites::slugOk($site) || !preg_match('/^[a-f0-9]{8,64}$/', $id)) {
    Http::fail('参数不合法');
}

// 没给月份就按 id 反查（插件只知道 URL/ID 的情况）
if (!preg_match('/^\d{6}$/', $month)) {
    $found = Store::find($site, $id);
    if ($found === null) {
        if ($byToken) {
            Http::fail('图片不存在或已删除', 404);
        }
        Http::json(['ok' => false, 'error' => '文件不存在']);
    }
    $month = $found['month'];
}

// ---------- 限流：只限 Token 调用，后台登录态操作不受影响 ----------
if ($byToken) {
    $limit = max(1, (int)Config::get('delete_per_hour', 120));
    $rateKey = 'delete:token:' . md5($expected) . ':' . $site;
    if (!Rate::hit($rateKey, $limit)) {
        Http::fail('删除操作太频繁了，请稍后再试', 429);
    }
}

// ---------- 移入回收站 ----------
$ok = Store::trash($site, $month, $id);

if (!$ok && $byToken) {
    Http::fail('图片不存在或删除失败', 404);
}

$keepDays = max(1, (int)Config::get('trash_keep_days', 30));

Http::json([
    'ok'      => $ok,
    'site'    => $site,
    'month'   => $month,
    'id'      => $id,
    'trashed' => $ok,
    'message' => $ok ? ('已移入回收站，' . $keepDays . ' 天内可恢复') : '文件不存在',
]);