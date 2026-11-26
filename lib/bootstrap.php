<?php
declare(strict_types=1);

define('IMGXIN_ROOT', dirname(__DIR__));

// 接口返回 JSON，任何 notice/warning 的 HTML 输出都会污染响应体；
// 统一关掉屏幕输出，错误进 error_log。
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 页面本身使用内联脚本和样式，因此先允许 unsafe-inline；同时限制资源来源，
// 阻止外部脚本注入，并避免后台被第三方页面 iframe 嵌入。
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' https: http: data:; connect-src 'self' https: http:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once IMGXIN_ROOT . '/lib/Config.php';
require_once IMGXIN_ROOT . '/lib/Sites.php';
require_once IMGXIN_ROOT . '/lib/Auth.php';
require_once IMGXIN_ROOT . '/lib/Http.php';
require_once IMGXIN_ROOT . '/lib/Rate.php';
require_once IMGXIN_ROOT . '/lib/Imager.php';
require_once IMGXIN_ROOT . '/lib/Store.php';

$__conf = require IMGXIN_ROOT . '/config.php';
Config::boot(is_array($__conf) ? $__conf : []);

foreach ([Config::base('storage_dir'), Config::base('data_dir'), Config::base('trash_dir'), Config::base('data_dir') . '/tmp', Config::base('data_dir') . '/meta', Config::base('data_dir') . '/trash', Config::base('data_dir') . '/rate'] as $__dir) {
    if (is_string($__dir) && $__dir !== '' && !is_dir($__dir)) {
        @mkdir($__dir, 0755, true);
    }
}
unset($__conf, $__dir);
