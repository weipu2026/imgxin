<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

Auth::start();

if (!Auth::user()) {
    header('Location: ./');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: settings.php');
    exit;
}

if (!Auth::csrfOk(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
    header('Location: settings.php?upgrade=csrf');
    exit;
}

try {
    $result = Upgrade::run();
    $notice = $result['message'];
} catch (Throwable $e) {
    $notice = '升级失败：' . $e->getMessage();
}

$_SESSION['imgxin_upgrade'] = $notice;
header('Location: settings.php?upgrade=1');
exit;
