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

$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    Http::fail('请求格式不正确');
}
if (count($payload['items']) > 200) {
    Http::fail('一次最多删 200 张，请分批操作');
}

$okCount = 0;
$fail = [];

foreach ($payload['items'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $site  = (string)($item['site'] ?? '');
    $month = (string)($item['month'] ?? '');
    $id    = (string)($item['id'] ?? '');

    if (!Sites::slugOk($site) || !preg_match('/^\d{6}$/', $month) || !preg_match('/^[a-f0-9]{8,64}$/', $id)) {
        $fail[] = ['id' => $id, 'reason' => '参数不合法'];
        continue;
    }

    if (Store::trash($site, $month, $id)) {
        $okCount++;
    } else {
        $fail[] = ['id' => $id, 'reason' => '文件不存在'];
    }
}

Http::json([
    'ok'      => true,
    'deleted' => $okCount,
    'failed'  => count($fail),
    'items'   => $fail,
]);
