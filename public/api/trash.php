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
if (!is_array($payload)) {
    Http::fail('请求格式不正确');
}

$action = (string)($payload['action'] ?? '');
$items  = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

if (in_array($action, ['restore', 'purge'], true)) {
    if ($items === []) {
        Http::fail('没有要处理的项目');
    }
    if (count($items) > 200) {
        Http::fail('一次最多处理 200 张');
    }

    $done = 0;
    $conflict = 0;
    $missing = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $site  = (string)($item['site'] ?? '');
        $month = (string)($item['month'] ?? '');
        $id    = (string)($item['id'] ?? '');

        if (!Sites::slugOk($site) || !preg_match('/^\d{6}$/', $month) || !preg_match('/^[a-f0-9]{8,64}$/', $id)) {
            $missing++;
            continue;
        }

        if ($action === 'restore') {
            $r = Store::restore($site, $month, $id);
            if ($r === 'ok') {
                $done++;
            } elseif ($r === 'conflict') {
                $conflict++;
            } else {
                $missing++;
            }
        } else {
            if (Store::purge($site, $month, $id)) {
                $done++;
            } else {
                $missing++;
            }
        }
    }

    Http::json([
        'ok'       => true,
        'done'     => $done,
        'conflict' => $conflict,
        'missing'  => $missing,
    ]);
}

if ($action === 'purge_expired') {
    $days = max(1, min(365, (int)($payload['days'] ?? Config::get('trash_keep_days', 30))));
    $n = Store::purgeExpired($days);
    Http::json(['ok' => true, 'purged' => $n, 'days' => $days]);
}

if ($action === 'purge_all') {
    $n = 0;
    foreach (Store::listTrash(null, 1, 1000)['items'] as $it) {
        if (Store::purge($it['site'], $it['month'], $it['id'])) {
            $n++;
        }
    }
    Http::json(['ok' => true, 'purged' => $n]);
}

Http::fail('未知操作');
