<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/bootstrap.php';

// 回收站里的图不在 storage/ 下，/i/ 拿不到，这里受权限保护地读出来
if (!Auth::user()) {
    http_response_code(403);
    exit;
}

$site  = (string)($_GET['s'] ?? '');
$month = (string)($_GET['m'] ?? '');
$id    = (string)($_GET['id'] ?? '');
$ext   = strtolower((string)($_GET['e'] ?? ''));

if (!Sites::slugOk($site) || !preg_match('/^\d{6}$/', $month) || !preg_match('/^[a-f0-9]{8,64}$/', $id) || !preg_match('/^(webp|png|jpg|gif|avif|bmp)$/', $ext)) {
    http_response_code(404);
    exit;
}

$file = (string)Config::base('trash_dir') . '/' . $site . '/' . $month . '/' . $id . '.' . $ext;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

$types = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg',
          'gif' => 'image/gif', 'avif' => 'image/avif', 'bmp' => 'image/bmp'];

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($file);
