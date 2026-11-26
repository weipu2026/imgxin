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

$action = (string)($_POST['action'] ?? '');

if ($action === 'add') {
    $name = (string)($_POST['name'] ?? '');
    $slug = (string)($_POST['slug'] ?? '');
    try {
        $site = Sites::add($name, $slug);
        Http::json(['ok' => true, 'site' => $site, 'message' => '已新增站点「' . $site['name'] . '」']);
    } catch (Throwable $e) {
        Http::fail($e->getMessage());
    }
}

if ($action === 'remove') {
    $slug = (string)($_POST['slug'] ?? '');
    $r = Sites::remove($slug);
    $map = [
        'ok'        => '已删除该站点',
        'notfound'  => '站点不存在',
        'last'      => '至少要保留一个站点',
        'not_empty' => '该站点在图片库或回收站里还有数据，请先清空后再删除',
    ];
    Http::json(['ok' => $r === 'ok', 'error' => $r === 'ok' ? null : $map[$r]]);
}

Http::fail('未知操作');
