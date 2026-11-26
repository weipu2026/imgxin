<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Http::fail('只接受 POST', 405);
}
if (!Auth::user()) {
    Http::fail('未登录或登录已过期', 401);
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Auth::csrfOk(is_string($csrf) ? $csrf : null)) {
    Http::fail('会话校验失败，请刷新页面', 403);
}

$site  = (string)($_POST['site'] ?? '');
$month = (string)($_POST['month'] ?? '');
$id    = (string)($_POST['id'] ?? '');

if (!Sites::slugOk($site) || !preg_match('/^\d{6}$/', $month) || !preg_match('/^[a-f0-9]{8,64}$/', $id)) {
    Http::fail('参数不合法');
}

$ok = Store::trash($site, $month, $id);

$keepDays = max(1, (int)Config::get('trash_keep_days', 30));

Http::json([
    'ok'      => $ok,
    'message' => $ok ? ('已移入回收站，' . $keepDays . ' 天内可恢复') : '文件不存在',
]);
