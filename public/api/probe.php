<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/bootstrap.php';

// 从服务器侧发起一次 HEAD 请求，验证直链真的能打开。
// 不用浏览器 fetch：后台和直链通常是两个域名，跨域 HEAD 会被 CORS 挡掉，
// 但图片本身用 <img> 加载并不受 CORS 限制，于是出现"图能显示、自检却 Failed to fetch"。
if (!Auth::user()) {
    Http::fail('未登录或登录已过期', 401);
}

$items = Store::listMonth(null, 'all', 1, 1)['items'] ?? [];
$first = $items[0] ?? null;
if ($first === null) {
    Http::fail('图床里还没有图片，先传一张再测');
}

$url = (string)$first['url'];

$probe = static function (string $url, bool $verifyTls = true): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_USERAGENT      => 'imgxin-probe',
        ]);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        return [
            'status' => (int)($info['http_code'] ?? 0),
            'final'  => (string)($info['url'] ?? $url),
            'type'   => (string)($info['content_type'] ?? ''),
            'bytes'  => (int)($info['download_content_length'] ?? 0),
            'error'  => $err,
        ];
    }

    // 没有 curl 的机器退回 stream
    $ctx = stream_context_create(['http' => [
        'method'        => 'HEAD',
        'timeout'       => 8,
        'ignore_errors' => true,
        'follow_location' => 1,
        'max_redirects' => 5,
        'header'        => "User-Agent: imgxin-probe\r\n",
    ], 'ssl' => ['verify_peer' => $verifyTls, 'verify_peer_name' => $verifyTls]]);
    $body = @fopen($url, 'rb', false, $ctx);
    $status = 0;
    $headers = $http_response_header ?? [];
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    $type = '';
    foreach ($headers as $line) {
        if (stripos($line, 'content-type:') === 0) {
            $type = trim(substr($line, 13));
        }
    }
    if ($body !== false) {
        @fclose($body);
    }

    return ['status' => $status, 'final' => $url, 'type' => $type, 'bytes' => 0, 'error' => $body === false ? '连接失败' : ''];
};

$r = $probe($url);
$tlsVerify = true;

// 证书链校验失败（服务器 CA 库过旧、或本机装了 TLS 拦截）时降级重试一次：
// 这里只是探测图片是否可读，重试不涉及密码等敏感数据。
if ($r['status'] === 0 && preg_match('/SSL|TLS|certificate|verify/i', $r['error'])) {
    $r = $probe($url, false);
    $tlsVerify = false;
}

$status = $r['status'];

$hint = '';
if ($status >= 200 && $status < 300) {
    $hint = '直链正常' . ($tlsVerify ? '' : '（HTTPS 证书链未通过校验，可能是服务器 CA 过旧或本地 TLS 拦截；浏览器不受影响）');
} elseif ($status === 0) {
    $hint = '服务器连不上这个域名：' . ($r['error'] !== '' ? $r['error'] : 'DNS / TLS / 防火墙问题');
    // 本地用 php -S 预览时，服务器请求自己会排在同一个进程后面，必然超时；线上 nginx 不会
    if (PHP_SAPI === 'cli-server') {
        $hint .= '（本地 php -S 预览会自己请求自己导致超时，线上 nginx 无此问题）';
    }
} elseif ($status === 403) {
    $hint = '被防盗链拦了（403）：检查防盗链白名单，或看 Referer 规则是否误伤空 Referer';
} elseif ($status === 404) {
    $hint = '文件不存在或 alias 路径不对（404）：核对 storage 目录与 nginx alias';
} elseif ($status >= 300 && $status < 400) {
    $hint = '仍在跳转（' . $status . '），直链没落到 storage，检查 /i/ 的 301 / 回源配置';
} else {
    $hint = '返回 ' . $status . '，检查 nginx 与 CDN 配置';
}

Http::json([
    'ok'     => $status >= 200 && $status < 300,
    'url'    => $url,
    'final'  => $r['final'],
    'status' => $status,
    'type'   => $r['type'],
    'bytes'  => $r['bytes'],
    'tls'    => $tlsVerify,
    'hint'   => $hint,
]);
