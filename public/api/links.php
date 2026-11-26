<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/bootstrap.php';

// 只读导出，登录即可，不要求 CSRF（GET 无副作用）
if (!Auth::user()) {
    Http::fail('未登录或登录已过期', 401);
}

$site = (string)($_GET['s'] ?? 'all');
if ($site !== 'all' && !Sites::exists($site)) {
    Http::fail('站点不存在');
}

$month = (string)($_GET['m'] ?? Store::month());
if ($month !== 'all' && !preg_match('/^\d{6}$/', $month)) {
    Http::fail('月份参数不合法');
}

$keyword = trim((string)($_GET['q'] ?? ''));
$items   = Store::links($site === 'all' ? null : $site, $month, $keyword);

Http::json([
    'ok'    => true,
    'count' => count($items),
    'items' => $items,
]);
