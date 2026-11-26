<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

Auth::start();

$configured = Auth::configured();
$logged     = Auth::user();
$error      = '';
$notice     = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'setup' && !$configured) {
        if (!Auth::csrfOk(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
            $error = '会话已过期，请刷新页面重试';
        } else {
            $password = (string)($_POST['password'] ?? '');
            $confirm  = (string)($_POST['confirm_password'] ?? '');

            if (strlen($password) < 8) {
                $error = '管理员密码至少 8 位';
            } elseif (!hash_equals($password, $confirm)) {
                $error = '两次输入的密码不一致';
            } else {
                try {
                    Config::save(['admin_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                    Auth::login();
                    header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'));
                    exit;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    } elseif ($do === 'login') {
        $rateKey  = 'login:' . Http::ip();
        $maxFail  = 5;
        $lockSecs = 900;

        if (!Auth::csrfOk(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
            $error = '会话已过期，请刷新页面重试';
        } elseif (Rate::blocked($rateKey, $maxFail, $lockSecs)) {
            $error = '密码错误次数过多，已锁定 15 分钟，请稍后再试';
        } elseif (!$configured) {
            $error = '还没有设置管理员密码，请刷新页面完成初始化';
        } elseif (Auth::check((string)($_POST['password'] ?? ''))) {
            Rate::reset($rateKey);
            Auth::login();
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'));
            exit;
        } else {
            $n = Rate::fail($rateKey, $lockSecs);
            $left = max(0, $maxFail - $n);
            $error = $left > 0
                ? '密码不正确，再错 ' . $left . ' 次将锁定 15 分钟'
                : '密码错误次数过多，已锁定 15 分钟，请稍后再试';
        }
    } elseif ($do === 'logout') {
        if (Auth::csrfOk(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
            Auth::logout();
        }
        header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'));
        exit;
    }
}

$csrf   = Auth::csrf();
$month  = Store::month();
$sites  = $logged ? Sites::all() : [];
$cap    = $logged ? Store::capacity() : ['this_month' => 0, 'total_bytes' => 0, 'total_count' => 0];
$outFmt = (string)Config::get('output', 'webp');
$maxMb  = round(((int)Config::get('max_bytes', 20 * 1024 * 1024)) / 1048576, 1);
$boot   = [
    'csrf'      => $csrf,
    'upload'    => 'api/upload.php',
    'del'       => 'api/delete.php',
    'trashDays' => max(1, (int)Config::get('trash_keep_days', 30)),
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>图床 · imgxin</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= $logged ? 'page-upload' : 'login-page' ?>">
<div class="wrap<?= $logged ? '' : ' login-shell' ?>">

<?php if (!$logged): ?>

  <div class="login-wrap">
    <div class="login-card">

      <div class="brand">
        <span class="brand-mark" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M21 5H3a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1Zm-14.5 3A1.5 1.5 0 1 1 8.5 9.5 1.5 1.5 0 0 1 6.5 8Zm-3 8 4.5-4.5 3 3 4-4 4 4v3H3.5Z"/></svg>
        </span>
        <span class="brand-copy">
          <span class="brand-name">imgxin</span>
          <span class="brand-sub">自建图床 · 上传即转码 · 直链即用</span>
        </span>
      </div>

      <?php if (!$configured): ?>
        <div class="alert alert-info">首次运行，请设置管理员密码。设置完成后，初始化入口会自动关闭。</div>
      <?php endif; ?>

      <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= Http::esc($error) ?></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="do" value="<?= $configured ? 'login' : 'setup' ?>">
        <input type="hidden" name="csrf" value="<?= Http::esc($csrf) ?>">
        <div class="pw">
          <input type="password" id="pw" name="password" placeholder="<?= $configured ? '管理员密码' : '设置管理员密码，至少 8 位' ?>" autocomplete="<?= $configured ? 'current-password' : 'new-password' ?>" required autofocus>
          <button type="button" class="eye" id="eye" aria-label="显示或隐藏密码" title="显示 / 隐藏">
            <svg viewBox="0 0 24 24"><path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 11.2a4.2 4.2 0 1 1 0-8.4 4.2 4.2 0 0 1 0 8.4Z"/></svg>
          </button>
        </div>
        <?php if (!$configured): ?>
          <div class="pw">
            <input type="password" id="pw2" name="confirm_password" placeholder="再输入一次确认" autocomplete="new-password" required>
          </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-login"><?= $configured ? '进入后台' : '设置密码并进入' ?></button>
      </form>

      <div class="login-foot">
        <span>PHP <?= Http::esc(PHP_VERSION) ?></span>
        <span><?= Imager::backend() === 'imagick' ? 'imagick' : (Imager::backend() === 'gd' ? 'GD 兜底' : '无转码器') ?></span>
      </div>

    </div>
  </div>

<?php else: ?>

<?php $active = 'upload'; require __DIR__ . '/partials/topbar.php'; ?>
<main>
  <section class="page-heading">
    <div>
      <h1>上传图片</h1>
      <p>拖拽、粘贴或选择图片，上传后自动转码并生成直链。</p>
    </div>
    <div class="heading-meta">
      本月 <b><?= (int)$cap['this_month'] ?></b> 张 · 共 <b><?= Http::esc(Http::bytes((int)$cap['total_bytes'])) ?></b><br>
      默认输出 <b><?= Http::esc($outFmt === 'original' ? '原格式' : strtoupper($outFmt)) ?></b>
    </div>
  </section>

  <section class="card upload-card" aria-label="上传图片">
    <div class="upload-toolbar">
      <label class="control-group" for="site">上传到
        <select id="site">
          <?php foreach ($sites as $s): ?>
            <option value="<?= Http::esc($s['slug']) ?>"><?= Http::esc($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="control-group" for="quality">压缩质量
        <select id="quality">
          <option value="">默认（<?= (int)Config::get('quality', 84) ?>）</option>
          <option value="92">高画质 92</option>
          <option value="84">均衡 84</option>
          <option value="76">小体积 76</option>
        </select>
      </label>
      <label class="control-group check-control" for="lossless">
        <input type="checkbox" id="lossless" checked> 透明图走无损
      </label>
    </div>

    <div class="dropzone" id="drop" tabindex="0" role="button" aria-label="选择或拖入图片">
      <div class="dropzone-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>
      </div>
      <strong>把图片拖进来，或点击选择</strong>
      <p>也可以直接 Ctrl+V 粘贴截图</p>
      <p>支持 jpg / png / gif / webp / avif / bmp · 单张上限 <?= Http::esc((string)$maxMb) ?> MB</p>
    </div>
    <input type="file" id="picker" accept="image/*" multiple hidden>
    <div class="upload-foot">上传后自动转码、按月归档到所选站点；文件名由内容哈希生成，同一张图重复上传会复用已有链接。</div>
    <div class="queue" id="queue"></div>
  </section>

  <section id="results" aria-live="polite"></section>
  <footer>imgxin · Nginx 静态托管 · 无数据库</footer>
</main>

<?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<?php if ($logged): ?>
<script>
const BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const drop   = document.getElementById('drop');
const picker = document.getElementById('picker');
const queue  = document.getElementById('queue');
const results= document.getElementById('results');
const toastEl= document.getElementById('toast');

let toastTimer = null;
function toast(msg){
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
}

function humanSize(n){
  if (!n && n !== 0) return '';
  if (n < 1024) return n + ' B';
  if (n < 1048576) return (n/1024).toFixed(1) + ' KB';
  return (n/1048576).toFixed(2) + ' MB';
}

function copyText(text, tip){
  const done = () => toast(tip || '已复制');
  if (navigator.clipboard && window.isSecureContext){
    navigator.clipboard.writeText(text).then(done).catch(() => fallback());
  } else { fallback(); }
  function fallback(){
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch(e) { toast('复制失败，请手动选择'); }
    document.body.removeChild(ta);
  }
}

drop.addEventListener('click', () => picker.click());
drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); picker.click(); } });
picker.addEventListener('change', () => { if (picker.files.length) enqueue([...picker.files]); picker.value=''; });
['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('hover'); }));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('hover'); }));
drop.addEventListener('drop', e => { if (e.dataTransfer.files.length) enqueue([...e.dataTransfer.files]); });

// 剪贴板粘贴上传（截图直接 Ctrl+V，或者复制了图片文件）
document.addEventListener('paste', e => {
  const cd = e.clipboardData;
  if (!cd) return;
  const raw = [...(cd.files || [])].filter(f => f && /^image\//.test(f.type));
  if (!raw.length) return;
  e.preventDefault();
  const stamp = Date.now();
  const files = raw.map((f, i) => {
    const name = f.name && f.name !== 'image.png' ? f.name : `paste-${stamp}-${i + 1}.${(f.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`;
    return new File([f], name, { type: f.type });
  });
  toast(files.length > 1 ? `粘贴了 ${files.length} 张，开始上传` : '已粘贴，开始上传');
  enqueue(files);
});

function currentSite(){
  const el = document.getElementById('site');
  return el ? el.value : '';
}

// 记住上次选的站点，刷新后不用再挑
(function () {
  const el = document.getElementById('site');
  if (!el) { return; }
  try {
    const saved = localStorage.getItem('imgxin.site');
    if (saved && [...el.options].some(o => o.value === saved)) { el.value = saved; }
    el.addEventListener('change', () => {
      try { localStorage.setItem('imgxin.site', el.value); } catch (e) {}
    });
  } catch (e) {}
})();

function currentQuality(){
  const v = document.getElementById('quality').value;
  const lossless = document.getElementById('lossless').checked ? '1' : '0';
  return { quality: v, lossless };
}

let batch = null;

function enqueue(files){
  if (!batch) { batch = { n: 0, origin: 0, stored: 0, saved: 0, dup: 0, urls: [] }; }
  queue.style.display = 'block';
  const items = [];
  files.forEach(f => {
    const el = document.createElement('div');
    el.className = 'qitem';

    // 文件名来自用户文件，必须用 textContent，不能直接拼进 innerHTML。
    const name = document.createElement('span');
    name.className = 'qname';
    name.textContent = f.name;

    const bar = document.createElement('span');
    bar.className = 'progress';
    const barFill = document.createElement('i');
    bar.appendChild(barFill);

    const st = document.createElement('span');
    st.className = 'st';
    st.textContent = '等待';

    el.append(name, bar, st);
    queue.appendChild(el);
    items.push({ file: f, el, bar: barFill, st });
  });

  // 3 路并发：上传吃网络、转码吃 CPU，再多反而互相拖慢
  const single = items.length === 1;
  let idx = 0;
  const run = async () => {
    while (idx < items.length) {
      const it = items[idx++];
      await uploadOne(it, single);
    }
  };
  Promise.all(Array.from({ length: Math.min(3, items.length) }, run)).then(() => {
    finishBatch();
    setTimeout(() => { queue.innerHTML = ''; queue.style.display = 'none'; }, 1200);
  });
}

function finishBatch(){
  if (!batch) return;
  const b = batch;
  batch = null;
  if (b.n === 0) return;

  const el = document.createElement('div');
  el.className = 'summary';
  const pct = b.origin > 0 ? Math.round((1 - b.stored / b.origin) * 100) : 0;
  el.innerHTML = `<span>本次 <b>${b.n}</b> 张</span>`
    + `<span>${humanSize(b.origin)} → <b>${humanSize(b.stored)}</b></span>`
    + (pct > 0 ? `<span>省了 <b>${pct}%</b></span>` : '')
    + (b.dup > 0 ? `<span>${b.dup} 张复用已有链接</span>` : '')
    + `<button type="button" class="btn btn-sm btn-soft copyAll">复制全部 Markdown</button>`;
  results.prepend(el);
  el.querySelector('.copyAll').addEventListener('click', () => {
    copyText(b.urls.map(u => '![](' + u + ')').join('\n'), '已复制 ' + b.urls.length + ' 条 Markdown');
  });
  setTimeout(() => el.remove(), 8000);
}

function uploadOne(it, single){
  return new Promise(resolve => {
    const form = new FormData();
    form.append('file', it.file);
    form.append('site', currentSite());
    const cfg = currentQuality();
    if (cfg.quality) { form.append('quality', cfg.quality); }
    form.append('lossless', cfg.lossless);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', BOOT.upload, true);
    xhr.setRequestHeader('X-CSRF-Token', BOOT.csrf);
    xhr.setRequestHeader('Accept', 'application/json');

    it.st.textContent = '上传 0%';
    xhr.upload.onprogress = e => {
      if (!e.lengthComputable) return;
      const p = Math.min(60, Math.round(e.loaded / e.total * 60));
      it.bar.style.width = p + '%';
      it.st.textContent = '上传 ' + Math.round(e.loaded / e.total * 100) + '%';
    };
    xhr.onload = () => {
      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch(e) {}
      if (xhr.status === 200 && data && data.ok){
        it.bar.style.width = '100%';
        it.st.textContent = data.deduped ? '秒传' : '完成';
        it.st.style.color = 'var(--success)';
        if (batch){
          batch.n++;
          batch.urls.push(data.url);
          if (data.deduped) { batch.dup++; }
          else {
            batch.origin += (data.origin_bytes || 0);
            batch.stored += (data.bytes || 0);
          }
        }
        render(data);
        if (single) { copyText(data.url, '已复制直链，可以直接粘贴到文章里'); }
      } else {
        it.bar.style.background = 'var(--danger)';
        it.st.textContent = '失败';
        it.st.style.color = 'var(--danger)';
        toast((data && data.error) || ('上传失败 HTTP ' + xhr.status));
      }
      resolve();
    };
    xhr.onerror = () => { it.st.textContent='失败'; it.bar.style.background='var(--danger)'; toast('网络错误'); resolve(); };
    xhr.send(form);
  });
}

function render(d){
  const el = document.createElement('div');
  el.className = 'result';
  const saved = d.origin_bytes > 0 ? Math.round((1 - d.bytes / d.origin_bytes) * 100) : 0;
  const stat = d.deduped
    ? '<span class="tag tag-green">复用已有</span>'
    : `<span class="tag tag-blue">${(d.format||'').toUpperCase()}</span><span class="tag">${humanSize(d.origin_bytes)} → ${humanSize(d.bytes)}${saved>0?` · -${saved}%`:''}</span>`;
  el.innerHTML = `
    <div class="thumb"><img src="${d.url}" alt="" loading="lazy"></div>
    <div class="meta">
      <div class="name"><span class="tag tag-blue">${d.site}</span>${d.width}×${d.height} · ${stat}</div>
      <div class="urlbox"><input readonly value="${d.url}"></div>
      <div class="result-actions">
        <button type="button" class="btn btn-sm copy" data-v="${d.url}">复制直链</button>
        <button type="button" class="btn btn-sm copy" data-v="${d.markdown.replace(/"/g,'&quot;')}">Markdown</button>
        <button type="button" class="btn btn-sm copy" data-v="${d.html.replace(/"/g,'&quot;')}">HTML</button>
        <button type="button" class="btn btn-sm btn-danger del" data-id="${d.id}" data-url="${d.url}">删除</button>
      </div>
    </div>`;
  results.prepend(el);

  el.querySelectorAll('.copy').forEach(b => b.addEventListener('click', () => copyText(b.dataset.v)));
  el.querySelector('.urlbox input').addEventListener('click', e => { e.target.select(); });
  el.querySelector('.del').addEventListener('click', async e => {
    const btn = e.currentTarget;
    if (!confirm(`删除后直链会失效，${BOOT.trashDays} 天内可恢复，确定？`)) return;
    const m = (btn.dataset.url.match(/\/i\/(\d{6})\//) || [])[1] || '';
    if (!m) { toast('无法解析月份，删除失败'); return; }
    const body = new URLSearchParams({ site: d.site, id: btn.dataset.id, month: m });
    btn.disabled = true;
    try {
      const r = await fetch(BOOT.del, { method:'POST', headers:{'X-CSRF-Token':BOOT.csrf}, body });
      const j = await r.json();
      if (j.ok) { el.remove(); toast(j.message); } else { toast(j.error || '删除失败'); btn.disabled = false; }
    } catch(err) { toast('删除失败'); btn.disabled = false; }
  });
}
</script>
<?php endif; ?>

<script>
(function () {
  var eye = document.getElementById('eye'), pw = document.getElementById('pw');
  if (!eye || !pw) { return; }
  eye.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    eye.style.opacity = show ? '.95' : '.5';
    pw.focus();
  });
})();
</script>
</body>
</html>
