<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

Auth::start();

if (!Auth::user()) {
    header('Location: ./');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['do'] ?? '') === 'logout') {
    if (Auth::csrfOk(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
        Auth::logout();
    }
    header('Location: ./');
    exit;
}

$csrf   = Auth::csrf();
$error  = '';
$notice = '';
$passChanged = false;

$upgradeNotice = (string)($_SESSION['imgxin_upgrade'] ?? '');
unset($_SESSION['imgxin_upgrade']);
$pendingUpgrade = Upgrade::pending();
$downgrade = Upgrade::isDowngrade();
if ($upgradeNotice !== '') {
    $notice = $upgradeNotice;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null;
    if (!Auth::csrfOk($token)) {
        $error = '会话已过期，请刷新页面重试';
    } else {
        $patch = [];

        $base = trim((string)($_POST['base_url'] ?? ''));
        if ($base !== '' && !preg_match('#^https?://[A-Za-z0-9\.\-:\[\]]+(/[^\s]*)?$#', $base)) {
            $error = '域名格式不对，示例：https://img.40404.cn';
        }
        $patch['base_url'] = rtrim($base, '/');

        $output = strtolower((string)($_POST['output'] ?? 'webp'));
        $patch['output'] = in_array($output, ['webp', 'avif', 'png', 'original'], true) ? $output : 'webp';

        $patch['quality']        = max(60, min(95, (int)($_POST['quality'] ?? 84)));
        $patch['lossless_alpha'] = !empty($_POST['lossless_alpha']);

        $maxMb = (float)($_POST['max_mb'] ?? 20);
        $patch['max_bytes'] = (int)max(1, min(200, $maxMb)) * 1024 * 1024;

        $maxPixels = (int)($_POST['max_pixels'] ?? 50000000);
        $patch['max_pixels'] = max(1_000_000, min(200_000_000, $maxPixels));

        $maxEdge = (int)($_POST['max_edge'] ?? 0);
        $patch['max_edge'] = max(0, min(20000, $maxEdge));

        $patch['rate_per_hour'] = max(1, min(2000, (int)($_POST['rate_per_hour'] ?? 60)));

        $wlRaw = (string)($_POST['referer_whitelist'] ?? '');
        $wl = [];
        foreach (preg_split('/[\s,，;；]+/u', $wlRaw) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9\.\-\*_]+$/', $item)) {
                $error = '白名单里「' . $item . '」看起来不是域名，只填域名或 *.域名';
                continue;
            }
            $wl[] = $item;
        }
        $patch['referer_whitelist']   = array_values(array_unique($wl));
        $patch['allow_empty_referer'] = !empty($_POST['allow_empty_referer']);

        $patch['trash_keep_days'] = max(1, min(365, (int)($_POST['trash_keep_days'] ?? 30)));

        // API Token：留空保留原值（不误清），勾选清空则删掉
        if (!empty($_POST['clear_api_token'])) {
            $patch['api_token'] = '';
        } else {
            $apiToken = trim((string)($_POST['api_token'] ?? ''));
            if ($apiToken !== '' && !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $apiToken)) {
                $error = 'API Token 只允许 16-64 位字母、数字、下划线或短横线';
            }
            if ($apiToken !== '') {
                $patch['api_token'] = $apiToken;
            }
        }

        // 修改密码：留空则不动
        $newPass = (string)($_POST['new_password'] ?? '');
        if ($newPass !== '') {
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if (strlen($newPass) < 8) {
                $error = '新密码至少 8 位';
            } elseif (!hash_equals($newPass, $confirm)) {
                $error = '两次输入的新密码不一致';
            } else {
                $patch['admin_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
                $passChanged = true;
            }
        }

        if ($error === '') {
            try {
                Config::save($patch);
                $notice = $passChanged ? '已保存，密码已更新（下次登录用新密码）' : '已保存，立即生效';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$s        = Config::all();
$backend  = Imager::backend();
$webpOk   = Imager::supports('webp');
$avifOk   = Imager::supports('avif');

// 生成可粘贴的 nginx 防盗链片段
$wl = (array)$s['referer_whitelist'];
$referers = [];
if (!empty($s['allow_empty_referer'])) {
    $referers[] = 'none';
}
$referers[] = 'blocked';
$referers[] = 'server_names';
foreach ($wl as $w) {
    $referers[] = (string)$w;
}
$snippet = "location ^~ /i/ {\n"
    . "    alias /www/wwwroot/YOUR_SITE/storage/;\n"
    . "    autoindex off;\n"
    . "    add_header Cache-Control \"public, max-age=31536000, immutable\";\n"
    . "    add_header X-Content-Type-Options nosniff;\n"
    . "    add_header Access-Control-Allow-Origin \"*\";\n"
    . "    valid_referers " . implode(' ', $referers) . ";\n"
    . "    if (\$invalid_referer) { return 403; }\n"
    . "}";

// ---------- 环境自检 ----------
$uploadsDir = static fn(string $p): array => [
    'path'     => $p,
    'exists'   => is_dir($p),
    'writable' => is_dir($p) && is_writable($p),
];

$env = [
    'php'        => PHP_VERSION . ' · ' . PHP_SAPI,
    'upload_max' => (string)ini_get('upload_max_filesize'),
    'post_max'   => (string)ini_get('post_max_size'),
    'memory'     => (string)ini_get('memory_limit'),
    'uptime'     => (string)ini_get('max_execution_time'),
    'backend'    => $backend === 'imagick' ? 'imagick' . (class_exists('Imagick') ? ' ' . (Imagick::getVersion()['versionString'] ?? '') : '') : ($backend === 'gd' ? 'GD ' . (gd_info()['GD Version'] ?? '') : '无'),
    'dirs'       => [
        $uploadsDir((string)Config::base('storage_dir')),
        $uploadsDir((string)Config::base('data_dir')),
        $uploadsDir((string)Config::base('trash_dir')),
    ],
    'disk_free'  => (float)@disk_free_space((string)Config::base('storage_dir') ?: '.'),
    'disk_total' => (float)@disk_total_space((string)Config::base('storage_dir') ?: '.'),
    'probe'      => null,
];

$firstImg = Store::listMonth(null, 'all', 1, 1)['items'][0] ?? null;
if ($firstImg !== null) {
    $env['probe'] = $firstImg['url'];
}

$postBytes   = Http::iniBytes($env['post_max']);
$uploadBytes = Http::iniBytes($env['upload_max']);
$limitBytes  = (int)$s['max_bytes'];
$memBytes    = Http::iniBytes($env['memory']);
$warnSize = ($uploadBytes > 0 && $limitBytes > $uploadBytes)
    || ($postBytes > 0 && $limitBytes > $postBytes);
$warnMem = $memBytes > 0 && $memBytes < 256 * 1024 * 1024;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>设置 · imgxin</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-settings">
<div class="wrap">
<?php $active = 'settings'; require __DIR__ . '/partials/topbar.php'; ?>
<main>
  <section class="page-heading">
    <div>
      <h1>图床设置</h1>
      <p>配置直链、转码、上传限制和安全策略，保存后立即生效。</p>
    </div>
    <div class="heading-meta">图片仍由 <b>Nginx</b> 静态托管<br>PHP 不参与图片读取</div>
  </section>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= Http::esc($error) ?></div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?>
    <div class="alert alert-success" role="status"><?= Http::esc($notice) ?></div>
  <?php endif; ?>

  <section class="card settings-card">
    <div class="card-head">
      <h2>版本与升级</h2>
      <span class="hint">覆盖代码后一键执行数据迁移</span>
    </div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field">
          <label>代码版本</label>
          <div class="env-value"><?= Http::esc(Upgrade::codeVersion()) ?></div>
        </div>
        <div class="field">
          <label>数据版本</label>
          <div class="env-value"><?= Http::esc(Upgrade::storedVersion() === '0.0.0' ? '尚未初始化' : Upgrade::storedVersion()) ?></div>
        </div>
      </div>

      <?php if ($pendingUpgrade): ?>
        <div class="alert alert-info">
          检测到 <?= count($pendingUpgrade) ?> 项待执行迁移，覆盖新代码后点击升级即可。
          <form method="post" action="upgrade.php" class="upgrade-form">
            <input type="hidden" name="csrf" value="<?= Http::esc($csrf) ?>">
            <button type="submit" class="btn btn-primary">一键升级</button>
          </form>
        </div>
      <?php elseif ($downgrade): ?>
        <div class="alert alert-error">当前数据版本高于代码版本，可能是降级覆盖。请先备份 data/，再恢复对应版本代码。</div>
      <?php else: ?>
        <div class="desc">当前已是最新版本。升级时只需覆盖 public/、lib/、config.php（如未改过固定项）和 README，再回到这里点升级。</div>
      <?php endif; ?>
    </div>
  </section>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= Http::esc($csrf) ?>">

    <section class="card settings-card">
      <div class="card-head">
        <h2>访问与转码</h2>
        <span class="hint">直链域名和默认输出</span>
      </div>
      <div class="card-body">
        <div class="field">
          <label for="baseUrl">直链域名（base_url）</label>
          <input type="text" id="baseUrl" name="base_url" value="<?= Http::esc((string)$s['base_url']) ?>" placeholder="https://img.40404.cn">
          <div class="desc">留空则自动用当前访问域名。将来换域名、或接上 CDN 后想改成加速域名，只改这里即可，已生成的图片链接会跟着变。</div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label for="output">输出格式</label>
            <select id="output" name="output">
              <option value="webp" <?= $s['output'] === 'webp' ? 'selected' : '' ?>>webp（推荐）</option>
              <option value="avif" <?= $s['output'] === 'avif' ? 'selected' : '' ?>>avif<?= $avifOk ? '' : '（不可用）' ?></option>
              <option value="png" <?= $s['output'] === 'png' ? 'selected' : '' ?>>png</option>
              <option value="original" <?= $s['output'] === 'original' ? 'selected' : '' ?>>原样保存，不转</option>
            </select>
            <div class="desc">webp 支持度已足够覆盖博客场景；avif 更省体积但编码更慢。</div>
          </div>
          <div class="field">
            <label for="quality">有损质量（60-95）</label>
            <input type="number" id="quality" name="quality" min="60" max="95" value="<?= (int)$s['quality'] ?>">
            <div class="desc">照片类 82-86 比较平衡，文字截图会自动走无损。</div>
          </div>
        </div>

        <div class="check">
          <input type="checkbox" id="la" name="lossless_alpha" value="1" <?= !empty($s['lossless_alpha']) ? 'checked' : '' ?>>
          <label for="la">带透明通道的图（PNG/透明 GIF）用无损 webp</label>
        </div>
        <div class="check">
          <input type="checkbox" id="ne" name="allow_empty_referer" value="1" <?= !empty($s['allow_empty_referer']) ? 'checked' : '' ?>>
          <label for="ne">防盗链放行空 Referer（直接打开链接、微信/QQ 内嵌）</label>
        </div>
      </div>
    </section>

    <section class="card settings-card">
      <div class="card-head">
        <h2>上传与安全</h2>
        <span class="hint">大小、像素与防盗链白名单</span>
      </div>
      <div class="card-body">
        <div class="form-grid">
          <div class="field">
            <label for="maxMb">单张大小上限（MB）</label>
            <input type="number" id="maxMb" name="max_mb" min="1" max="200" step="1" value="<?= (int)round(((int)$s['max_bytes']) / 1048576) ?>">
            <div class="desc">还要同步调 php.ini 的 upload_max_filesize 和 post_max_size。</div>
          </div>
          <div class="field">
            <label for="maxPixels">单张像素上限</label>
            <input type="number" id="maxPixels" name="max_pixels" min="1000000" max="200000000" step="1000000" value="<?= (int)$s['max_pixels'] ?>">
            <div class="desc">默认 5000 万像素，防解压炸弹。</div>
          </div>
        </div>
        <div class="field">
          <label for="maxEdge">最长边上限（等比缩小）</label>
          <select id="maxEdge" name="max_edge">
            <?php foreach ([0 => '不缩放（保持原尺寸）', 1280 => '1280 px（正文窄图）', 1920 => '1920 px（常见宽屏）', 2560 => '2560 px（推荐）', 3840 => '3840 px（4K）'] as $v => $label): ?>
              <option value="<?= $v ?>" <?= (int)$s['max_edge'] === $v ? 'selected' : '' ?>><?= Http::esc($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="desc">手机原图动辄 4000px，博客正文根本用不到。超过上限的图会等比缩到这个最长边再转码，画质几乎看不出差别，体积能省一半以上。</div>
        </div>
        <div class="field">
          <label for="ratePerHour">每 IP 每小时上传上限</label>
          <input type="number" id="ratePerHour" name="rate_per_hour" min="1" max="2000" value="<?= (int)$s['rate_per_hour'] ?>">
        </div>
        <div class="field">
          <label for="refererWhitelist">防盗链白名单</label>
          <textarea id="refererWhitelist" name="referer_whitelist" placeholder="*.40404.cn"><?= Http::esc(implode("\n", array_map('strval', (array)$s['referer_whitelist']))) ?></textarea>
          <div class="desc">一行一个，支持 *.example.com 这种通配。只填域名，不要带 http://。</div>
        </div>
      </div>
    </section>

    <section class="card settings-card">
      <div class="card-head">
        <h2>回收站</h2>
        <span class="hint">软删除保留策略</span>
      </div>
      <div class="card-body">
        <div class="field">
          <label for="trashKeepDays">保留天数</label>
          <input type="number" id="trashKeepDays" name="trash_keep_days" min="1" max="365" value="<?= (int)$s['trash_keep_days'] ?>">
          <div class="desc">超过这个天数的软删除文件，在打开回收站页面或后台首页时自动彻底删除。</div>
        </div>
      </div>
    </section>

    <section class="card settings-card">
      <div class="card-head">
        <h2>API Token</h2>
        <span class="hint">脚本 / 编辑器免登录上传</span>
      </div>
      <div class="card-body">
        <div class="field">
          <label for="tok">上传令牌</label>
          <div class="token-row">
            <input type="text" name="api_token" id="tok" value="<?= Http::esc((string)$s['api_token']) ?>" placeholder="留空表示不使用 Token" autocomplete="off" spellcheck="false">
            <button type="button" class="btn" id="genTok">随机生成</button>
          </div>
        </div>
        <div class="check">
          <input type="checkbox" id="clrTok" name="clear_api_token" value="1">
          <label for="clrTok">清空 Token（关闭免登录上传）</label>
        </div>
        <div class="desc">有 Token 就能直接调上传接口，不用登录、不用带 CSRF；万一泄露，改这里或清空即可立刻失效。</div>
        <pre class="code-block">curl -X POST "<?= Http::esc(rtrim((string)$s['base_url'], '/') !== '' ? (string)$s['base_url'] : 'https://img.40404.cn') ?>/api/upload.php" \
  -H "X-API-Token: 你的Token" \
  -F "file=@photo.jpg" \
  -F "site=<?= Http::esc(Sites::defaultSlug()) ?>" \
  -F "quality=84"</pre>
        <div class="desc">返回 JSON，<code>url</code> 就是直链。不给 <code>site</code> 会落到默认站点；Token 只给上传权限，删除等操作仍要登录。</div>
      </div>
    </section>

    <section class="card settings-card">
      <div class="card-head">
        <h2>管理员密码</h2>
        <span class="hint">留空则不修改</span>
      </div>
      <div class="card-body">
        <div class="form-grid">
          <div class="field">
            <label class="sr-only" for="newPassword">新密码</label>
            <input type="password" id="newPassword" name="new_password" placeholder="新密码，至少 8 位" autocomplete="new-password">
          </div>
          <div class="field">
            <label class="sr-only" for="confirmPassword">确认新密码</label>
            <input type="password" id="confirmPassword" name="confirm_password" placeholder="再输一次确认" autocomplete="new-password">
          </div>
        </div>
        <div class="desc">改完写入 <code>data/settings.json</code>，优先于 <code>config.php</code> 里的哈希，下次登录用新密码。</div>
      </div>
    </section>

    <div class="settings-actions">
      <button class="btn btn-primary btn-lg" type="submit">保存设置</button>
    </div>
  </form>

  <section class="card settings-card">
    <div class="card-head">
      <h2>运行环境</h2>
      <div class="row">
        <span class="tag <?= $backend === 'imagick' ? 'on' : ($backend === 'gd' ? 'on' : 'off') ?>"><?= $backend === 'imagick' ? 'imagick' : ($backend === 'gd' ? 'GD 兜底' : '无转码器') ?></span>
        <span class="tag <?= $webpOk ? 'on' : 'off' ?>">webp <?= $webpOk ? '可用' : '不可用' ?></span>
        <span class="tag <?= $avifOk ? 'on' : 'off' ?>">avif <?= $avifOk ? '可用' : '不可用' ?></span>
      </div>
    </div>
    <div class="card-body">
      <div class="env-grid">
        <div class="env-key">PHP</div>
        <div class="env-value"><?= Http::esc($env['php']) ?></div>

        <div class="env-key">转码后端</div>
        <div class="env-value"><?= Http::esc($env['backend']) ?></div>

        <div class="env-key">upload_max_filesize</div>
        <div class="env-value">
          <?= Http::esc($env['upload_max']) ?>
          <?php if ($uploadBytes > 0 && $limitBytes > $uploadBytes): ?>
            <span class="tag off">小于后台单张上限，传大图会失败</span>
          <?php endif; ?>
        </div>

        <div class="env-key">post_max_size</div>
        <div class="env-value">
          <?= Http::esc($env['post_max']) ?>
          <?php if ($postBytes > 0 && $limitBytes > $postBytes): ?>
            <span class="tag off">小于单张上限</span>
          <?php endif; ?>
        </div>

        <div class="env-key">memory_limit / max_execution_time</div>
        <div class="env-value"><?= Http::esc($env['memory']) ?> / <?= Http::esc($env['uptime']) ?>s</div>

        <div class="env-key">磁盘</div>
        <div class="env-value">剩 <?= Http::esc(Http::bytes((int)$env['disk_free'])) ?> / 共 <?= Http::esc(Http::bytes((int)$env['disk_total'])) ?></div>

        <?php foreach ($env['dirs'] as $d): ?>
          <div class="env-key"><?= Http::esc(basename($d['path'])) ?></div>
          <div class="env-value">
            <?php if (!$d['exists']): ?>
              <span class="tag off">目录不存在</span>
            <?php elseif (!$d['writable']): ?>
              <span class="tag off">不可写（chown www:www 试试）</span>
            <?php else: ?>
              <span class="tag on">可写</span>
            <?php endif; ?>
            <span class="path"><?= Http::esc($d['path']) ?></span>
          </div>
        <?php endforeach; ?>

        <div class="env-key">直链自检</div>
        <div class="env-value">
          <?php if ($env['probe'] === null): ?>
            <span class="muted">还没有图片，传一张后再看</span>
          <?php else: ?>
            <button type="button" class="btn btn-sm" id="probeBtn">测一张现有图片</button>
            <span id="probeOut" class="hint">用浏览器真实请求一次 /i/，验证 nginx alias 是否配对了</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="desc">设置文件：<?= Http::esc(Config::settingsFile()) ?></div>
      <?php if ($backend === 'gd'): ?>
        <div class="env-note">当前走 GD 兜底：动图只保留首帧、手机照片的 EXIF 方向不会自动纠正、大图上限 2000 万像素。装上 imagick 后自动切回。</div>
      <?php endif; ?>
      <?php if ($warnSize): ?>
        <div class="env-note">php.ini 的上传上限比后台「单张大小上限」小，实际能传的文件以 php.ini 为准。两边调成一致再重启 php-fpm。</div>
      <?php endif; ?>
      <?php if ($warnMem): ?>
        <div class="env-note">memory_limit 只有 <?= Http::esc($env['memory']) ?>，转 4000px 以上的大图可能中途失败。建议调到 256M 以上：宝塔 → PHP 设置 → 性能调整 → 内存限制。</div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card settings-card">
    <div class="card-head">
      <h2>Nginx 防盗链片段</h2>
      <span class="hint">按当前白名单生成</span>
    </div>
    <div class="card-body">
      <pre><?= Http::esc($snippet) ?></pre>
      <div class="desc">粘贴到宝塔「配置文件」里，并记得把 <code>/www/wwwroot/YOUR_SITE/storage/</code> 换成站点实际路径。这层只拦回源请求，图片被 CDN 缓存后仍要在 EdgeOne 上单独配 Referer 管控。</div>
    </div>
  </section>

  <footer>imgxin · 设置保存在本地文件，不依赖数据库</footer>
</main>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
<?php if ($env['probe'] !== null): ?>
(function () {
  const btn = document.getElementById('probeBtn');
  const out = document.getElementById('probeOut');
  const url = <?= json_encode($env['probe'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    out.textContent = '请求中…';
    out.style.color = 'var(--muted)';
    try {
      const r = await fetch(url, { method: 'HEAD', cache: 'no-store' });
      if (r.ok) {
        out.textContent = '200 OK · ' + (r.headers.get('content-type') || '');
        out.style.color = 'var(--success)';
      } else {
        out.textContent = r.status + ' · 直链打不开，检查 alias 路径与文件是否存在';
        out.style.color = 'var(--danger)';
      }
    } catch (e) {
      out.textContent = '请求失败：' + e.message;
      out.style.color = 'var(--danger)';
    } finally {
      btn.disabled = false;
    }
  });
})();
<?php endif; ?>

document.getElementById('genTok').addEventListener('click', function () {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);
  let out = '';
  for (let i = 0; i < bytes.length; i++) { out += chars[bytes[i] % chars.length]; }
  document.getElementById('tok').value = out;
  document.getElementById('clrTok').checked = false;
});
</script>
</body>
</html>
