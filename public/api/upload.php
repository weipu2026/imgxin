<?php
declare(strict_types=1);

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

// ---------- 请求体预检 ----------
// post_max_size 超限时 PHP 会直接清空 $_FILES / $_POST，提前返回 413 比“没有收到文件”更准确。
$postMaxBytes = Http::iniBytes((string)ini_get('post_max_size'));
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    Http::fail('请求体超过 php.ini 的 post_max_size 限制，请调大后重试', 413);
}

// ---------- 站点 ----------
$site = strtolower(trim((string)($_POST['site'] ?? '')));
if ($site === '') {
    $site = Sites::defaultSlug();
}
if (!Sites::exists($site)) {
    Http::fail('站点「' . $site . '」不存在，请先在后台添加');
}

// ---------- 限流 ----------
// Token 上传（插件/脚本/Obsidian 等服务端调用）按 Token+站点限流，
// 多个博客各自独立配额，互不挤占；浏览器直传仍按 IP。
$limit = (int)Config::get('rate_per_hour', 60);
$rateKey = $byToken
    ? ('upload:token:' . md5($expected) . ':' . $site)
    : ('upload:' . Http::ip());
if (!Rate::hit($rateKey, $limit)) {
    Http::fail('上传太频繁了，休息一下再传', 429);
}

// ---------- 文件基础校验 ----------
$file = $_FILES['file'] ?? null;
if (!is_array($file) || !isset($file['error'])) {
    Http::fail('没有收到文件');
}

$errMap = [
    UPLOAD_ERR_INI_SIZE   => '文件超过服务器 php.ini 的 upload_max_filesize 限制',
    UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
    UPLOAD_ERR_PARTIAL    => '文件只上传了一部分，请重试',
    UPLOAD_ERR_NO_FILE    => '没有选择文件',
    UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
    UPLOAD_ERR_CANT_WRITE => '临时目录不可写',
    UPLOAD_ERR_EXTENSION  => '上传被 PHP 扩展中止',
];
$err = (int)$file['error'];
if ($err !== UPLOAD_ERR_OK) {
    Http::fail($errMap[$err] ?? ('上传失败（错误码 ' . $err . '）'));
}

$tmp  = (string)$file['tmp_name'];
$size = (int)($file['size'] ?? 0);

if (!is_uploaded_file($tmp)) {
    Http::fail('来源不是合法的上传文件');
}

$maxBytes = (int)Config::get('max_bytes', 20 * 1024 * 1024);
if ($size <= 0 || $size > $maxBytes) {
    Http::fail('文件大小超出限制（上限 ' . Http::bytes($maxBytes) . '）');
}

// ---------- 真实类型校验 ----------
$allowed = [
    IMAGETYPE_JPEG => ['format' => 'JPEG', 'ext' => 'jpg',  'mime' => 'image/jpeg'],
    IMAGETYPE_PNG  => ['format' => 'PNG',  'ext' => 'png',  'mime' => 'image/png'],
    IMAGETYPE_GIF  => ['format' => 'GIF',  'ext' => 'gif',  'mime' => 'image/gif'],
    IMAGETYPE_WEBP => ['format' => 'WEBP', 'ext' => 'webp', 'mime' => 'image/webp'],
    IMAGETYPE_BMP  => ['format' => 'BMP',  'ext' => 'bmp',  'mime' => 'image/bmp'],
];
if (defined('IMAGETYPE_AVIF')) {
    $allowed[IMAGETYPE_AVIF] = ['format' => 'AVIF', 'ext' => 'avif', 'mime' => 'image/avif'];
}

$info = @getimagesize($tmp);
if ($info === false || empty($info[2])) {
    Http::fail('这不是有效的图片文件');
}

$type = (int)$info[2];
if (!isset($allowed[$type])) {
    Http::fail('不支持这种格式，仅允许 jpg / png / gif / webp / avif / bmp');
}
$src = $allowed[$type];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($tmp);
$mimeAlias = ['image/jpg' => 'image/jpeg', 'image/x-png' => 'image/png', 'image/x-bmp' => 'image/bmp'];
$mime = $mimeAlias[$mime] ?? $mime;
if ($mime !== $src['mime']) {
    Http::fail('文件内容与类型不符，已拒绝');
}

$width  = (int)$info[0];
$height = (int)$info[1];
$maxPixels = (int)Config::get('max_pixels', 50000000);
if ($width <= 0 || $height <= 0) {
    Http::fail('图片尺寸异常');
}
if ($width * $height > $maxPixels) {
    Http::fail('图片太大（' . $width . '×' . $height . '），上限 ' . number_format($maxPixels) . ' 像素');
}

// ---------- 磁盘配额预检 ----------
// 在转码前就拦住，避免"转完才发现磁盘不够"。配额为 0 表示不限制。
$quotaGb = (int)Config::get('total_quota_gb', 0);
if ($quotaGb > 0) {
    $free = @disk_free_space((string)Config::base('storage_dir'));
    if ($free !== false && $free < $quotaGb * 1024 * 1024 * 1024) {
        Http::fail('磁盘剩余空间不足（低于 ' . $quotaGb . ' GB），无法上传，请清理图床或调低配额');
    }
}

// ---------- 压缩参数 ----------
$out = strtolower((string)Config::get('output', 'webp'));
if (!in_array($out, ['webp', 'avif', 'png', 'original'], true)) {
    $out = 'webp';
}
if ($out !== 'original' && !Imager::supports($out)) {
    $out = Imager::supports('webp') ? 'webp' : 'original';
}
// GD 只能写 AVIF、不能读 AVIF；在进入转码前明确拒绝，避免到解码阶段才报模糊错误。
if ($out !== 'original' && Imager::backend() === 'gd' && $src['format'] === 'AVIF') {
    Http::fail('当前使用 GD 后端，无法读取 AVIF 图片，请安装 imagick 或把输出格式设为 original');
}

$quality = (int)Config::get('quality', 84);
$qParam  = isset($_POST['quality']) ? (int)$_POST['quality'] : 0;
if ($qParam >= 60 && $qParam <= 95) {
    $quality = $qParam;
}
$lossless = (bool)Config::get('lossless_alpha', true);
if (isset($_POST['lossless']) && $_POST['lossless'] === '0') {
    $lossless = false;
}

$maxEdge = max(0, min(20000, (int)Config::get('max_edge', 0)));

// ---------- 指纹：原图内容 + 压缩参数 + 站点 ----------
$sha = hash_file('sha256', $tmp);
if (!is_string($sha) || strlen($sha) < 32) {
    Http::fail('计算文件指纹失败');
}
$profile = $out === 'original' ? 'original' : ($out . ':' . $quality . ':' . ($lossless ? '1' : '0') . ':' . $maxEdge);
$id = substr(hash('sha256', $sha . '|' . $profile . '|' . $site), 0, 16);

$existing = Store::find($site, $id);
if ($existing !== null) {
    $existMeta = $existing['meta'] ?? [];
    Http::json([
        'ok'           => true,
        'site'         => $site,
        'id'           => $id,
        'url'          => $existing['url'],
        'markdown'     => '![图片](' . $existing['url'] . ')',
        'html'         => '<img src="' . $existing['url'] . '" alt="">',
        'width'        => (int)($existMeta['width'] ?? 0),
        'height'       => (int)($existMeta['height'] ?? 0),
        'bytes'        => (int)($existMeta['bytes'] ?? 0),
        'origin_bytes' => $size,
        'format'       => pathinfo($existing['name'], PATHINFO_EXTENSION),
        'deduped'      => true,
    ]);
}

// ---------- 转码 ----------
$tmpDir = (string)Config::base('data_dir') . '/tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

if ($out === 'original') {
    $ext     = $src['ext'];
    $bytes   = $size;
    $finalW  = $width;
    $finalH  = $height;
    $frames  = 1;
    $tmpFile = $tmpDir . '/' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@copy($tmp, $tmpFile)) {
        Http::fail('写入临时文件失败');
    }
} else {
    $ext     = $out === 'png' ? 'png' : $out;
    $tmpFile = $tmpDir . '/' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    try {
        $result = Imager::convert($tmp, $tmpFile, $out, $quality, $lossless, $maxEdge);
    } catch (Throwable $e) {
        @unlink($tmpFile);
        Http::fail($e->getMessage(), 422);
    }
    $bytes  = (int)$result['bytes'];
    $finalW = (int)$result['width'];
    $finalH = (int)$result['height'];
    $frames = (int)$result['frames'];
}

// ---------- 落盘 ----------
$meta = [
    'id'            => $id,
    'sha256'        => $sha,
    'site'          => $site,
    'profile'       => $profile,
    'original_name' => mb_substr((string)($file['name'] ?? ''), 0, 120),
    'source_format' => $src['format'],
    'format'        => $ext,
    'width'         => $finalW,
    'height'        => $finalH,
    'frames'        => $frames,
    'bytes'         => $bytes,
    'origin_bytes'  => $size,
    'max_edge'      => $maxEdge,
    'uploaded_at'   => date('Y-m-d H:i:s'),
    'ip'            => Http::ip(),
    'via'           => $byToken ? 'token' : 'session',
];

try {
    $stored = Store::put($site, $tmpFile, $id, $ext, $meta);
} catch (Throwable $e) {
    @unlink($tmpFile);
    Http::fail($e->getMessage(), 500);
}

Http::json([
    'ok'           => true,
    'site'         => $site,
    'id'           => $id,
    'url'          => $stored['url'],
    'markdown'     => '![图片](' . $stored['url'] . ')',
    'html'         => '<img src="' . $stored['url'] . '" alt="">',
    'width'        => $finalW,
    'height'       => $finalH,
    'bytes'        => $bytes,
    'origin_bytes' => $size,
    'format'       => $ext,
    'deduped'      => false,
]);
