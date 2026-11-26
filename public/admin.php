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
$sites  = Sites::all();

// 不只依赖回收站页面触发清理；后台首页每次打开也顺手清掉过期文件。
Store::purgeExpired(max(1, (int)Config::get('trash_keep_days', 30)));

$cap    = Store::capacity();

$siteFilter = (string)($_GET['s'] ?? 'all');
if ($siteFilter !== 'all' && !Sites::exists($siteFilter)) {
    $siteFilter = 'all';
}

$months = Store::months($siteFilter === 'all' ? null : $siteFilter);

$keyword = trim((string)($_GET['q'] ?? ''));
$month   = (string)($_GET['m'] ?? '');

if ($keyword !== '') {
    // 有搜索词就跨月找，避免"记得图但不记得哪个月"
    $month = 'all';
} elseif ($month === 'all') {
    $month = 'all';
} elseif (!preg_match('/^\d{6}$/', $month)) {
    $month = $months[0] ?? Store::month();
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;

$list = Store::listMonth($siteFilter === 'all' ? null : $siteFilter, $month, $page, $perPage, $keyword);

$monthLabel = static fn(string $m): string => $m === 'all'
    ? '全部月份'
    : substr($m, 0, 4) . ' 年 ' . (int)substr($m, 4, 2) . ' 月';

$qs = static function (array $override = []) use ($siteFilter, $month, $keyword): string {
    $params = array_filter(array_merge(['s' => $siteFilter, 'm' => $month, 'q' => $keyword], $override), static fn($v) => $v !== '' && $v !== null && $v !== 'all');
    return '?' . http_build_query($params);
};

$siteName = static function (string $slug) use ($sites): string {
    foreach ($sites as $s) {
        if ($s['slug'] === $slug) {
            return $s['name'];
        }
    }
    return $slug;
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>图片管理 · imgxin</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-admin">
<div class="wrap">
<?php $active = 'admin'; require __DIR__ . '/partials/topbar.php'; ?>
<main>
  <section class="page-heading">
    <div>
      <h1>图片管理</h1>
      <p>按站点和月份整理图片，搜索、复制链接或批量管理。</p>
    </div>
    <div class="heading-meta"><b><?= count($sites) ?></b> 个站点 · <b><?= (int)$cap['total_count'] ?></b> 张图片</div>
  </section>

  <section class="card stats" aria-label="容量统计">
    <div class="stat"><span class="stat-value"><?= Http::esc(Http::bytes((int)$cap['total_bytes'])) ?></span><span class="stat-label">图床总占用</span></div>
    <div class="stat"><span class="stat-value"><?= (int)$cap['total_count'] ?></span><span class="stat-label">图片总数</span></div>
    <div class="stat"><span class="stat-value"><?= (int)$cap['this_month'] ?></span><span class="stat-label">本月新增</span></div>
    <div class="stat"><span class="stat-value"><?= $cap['disk_free'] === null ? '—' : Http::esc(Http::bytes((int)$cap['disk_free'])) ?></span><span class="stat-label">服务器磁盘剩余</span></div>
  </section>

  <?php if ((int)$cap['trash_count'] > 0): ?>
    <div class="alert alert-warning trash-tip">
      <span>回收站里还有 <b><?= (int)$cap['trash_count'] ?></b> 张、占 <?= Http::esc(Http::bytes((int)$cap['trash_bytes'])) ?>，<?= (int)Config::get('trash_keep_days', 30) ?> 天后自动彻底删除。</span>
      <a class="btn btn-sm btn-soft spacer" href="trash.php">去回收站处理</a>
    </div>
  <?php endif; ?>

  <section class="card sites-card">
    <div class="card-head sites-head">
      <h2>站点分布</h2>
      <span class="hint"><?= count($sites) ?> 个站点</span>
    </div>
    <div class="site-list">
      <?php foreach ($cap['sites'] as $s): ?>
        <?php $pct = $cap['total_bytes'] > 0 ? round($s['bytes'] / $cap['total_bytes'] * 100) : 0; ?>
        <div class="site-row">
          <div class="site-name" title="<?= Http::esc($s['name']) ?>"><?= Http::esc($s['name']) ?></div>
          <div class="site-slug">/<?= Http::esc($s['slug']) ?></div>
          <div class="progress"><i style="width:<?= max(1, $pct) ?>%"></i></div>
          <div class="site-usage"><?= (int)$s['count'] ?> 张 · <?= Http::esc(Http::bytes((int)$s['bytes'])) ?></div>
          <?php if (count($sites) > 1): ?>
            <button type="button" class="btn btn-sm btn-danger rmSite" data-slug="<?= Http::esc($s['slug']) ?>" data-name="<?= Http::esc($s['name']) ?>">删除</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <form class="add-site" method="post" action="api/sites.php" id="addSiteForm">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf" value="<?= Http::esc($csrf) ?>">
      <input type="text" name="name" placeholder="站点名称，如 小新笔记" maxlength="30" required aria-label="站点名称">
      <input type="text" name="slug" placeholder="标识（可选，英文/拼音）" maxlength="32" aria-label="站点标识">
      <button class="btn btn-primary btn-sm" type="submit">+ 新增站点</button>
    </form>
  </section>

  <form class="card filters" method="get" action="admin.php">
    <select name="s" onchange="this.form.submit()">
      <option value="all" <?= $siteFilter === 'all' ? 'selected' : '' ?>>全部站点</option>
      <?php foreach ($sites as $s): ?>
        <option value="<?= Http::esc($s['slug']) ?>" <?= $siteFilter === $s['slug'] ? 'selected' : '' ?>><?= Http::esc($s['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="m" onchange="this.form.submit()">
      <?php if ($months === []): ?>
        <option value="<?= Http::esc($month) ?>"><?= Http::esc($monthLabel($month)) ?></option>
      <?php endif; ?>
      <?php
        $allCnt = 0;
        if ($siteFilter === 'all') {
            $allCnt = (int)$cap['total_count'];
        } else {
            foreach ($months as $m) {
                $allCnt += Store::countMonth($siteFilter, $m);
            }
        }
      ?>
      <option value="all" <?= $month === 'all' ? 'selected' : '' ?>>全部月份（<?= $allCnt ?>）</option>
      <?php foreach ($months as $m): ?>
        <?php
          $cnt = 0;
          if ($siteFilter === 'all') {
              foreach ($sites as $s) {
                  $cnt += Store::countMonth($s['slug'], $m);
              }
          } else {
              $cnt = Store::countMonth($siteFilter, $m);
          }
        ?>
        <option value="<?= Http::esc($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= Http::esc($monthLabel($m)) ?>（<?= $cnt ?>）</option>
      <?php endforeach; ?>
    </select>

    <input type="search" name="q" value="<?= Http::esc($keyword) ?>" placeholder="搜原文件名或哈希（跨全部月份）">
    <button class="btn">筛选</button>
    <?php if ($keyword !== '' || $siteFilter !== 'all' || $month === 'all'): ?>
      <a class="btn" href="admin.php">重置</a>
    <?php endif; ?>
    <button type="button" class="btn" id="expLinks" title="把当前筛选下的所有直链复制走">复制直链</button>
    <button type="button" class="btn" id="expMd" title="全部转成 Markdown 图片语法">复制 Markdown</button>
    <span class="filter-count">本页 <?= count($list['items']) ?> 张 / 共 <?= (int)$list['total'] ?> 张</span>
  </form>

  <?php if ($list['items'] === []): ?>
    <div class="card empty">
      <div class="empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m4 16 4.5-4.5 3 3 4-4 4.5 4.5"/></svg></div>
      <?= $keyword !== '' ? '没有匹配的图片' : '这个月还没有图，去上传几张吧' ?>
    </div>
  <?php else: ?>
    <div class="image-grid">
      <?php foreach ($list['items'] as $it): ?>
        <article class="image-card" data-site="<?= Http::esc($it['site']) ?>" data-id="<?= Http::esc($it['id']) ?>" data-month="<?= Http::esc($it['month']) ?>">
          <input class="chk" type="checkbox" aria-label="选择图片">
          <a class="thumb" href="<?= Http::esc($it['url']) ?>" target="_blank" rel="noopener">
            <img src="<?= Http::esc($it['url']) ?>" alt="" loading="lazy">
          </a>
          <div class="image-info">
            <div class="name" title="<?= Http::esc($it['original_name']) ?>"><?= Http::esc($it['original_name']) ?></div>
            <div><span class="tag tag-blue"><?= Http::esc($siteName($it['site'])) ?></span><?= $it['width'] > 0 ? (int)$it['width'] . '×' . (int)$it['height'] : '—' ?> · <?= Http::esc(Http::bytes((int)$it['bytes'])) ?></div>
            <div><?= Http::esc(substr($it['uploaded_at'], 5, 11)) ?></div>
            <div class="actions"><a href="<?= Http::esc($it['url']) ?>" target="_blank" rel="noopener">打开</a><a href="#" class="cp" data-url="<?= Http::esc($it['url']) ?>">直链</a><a href="#" class="cpMd" data-v="![图片](<?= Http::esc($it['url']) ?>)">MD</a></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($list['pages'] > 1): ?>
      <div class="pager">
        <?php if ($list['page'] > 1): ?><a href="<?= Http::esc($qs(['page' => $list['page'] - 1])) ?>">上一页</a><?php endif; ?>
        <?php
          $start = max(1, $list['page'] - 2);
          $end   = min($list['pages'], $start + 4);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <?php if ($p === $list['page']): ?>
            <span class="cur"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= Http::esc($qs(['page' => $p])) ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($list['page'] < $list['pages']): ?><a href="<?= Http::esc($qs(['page' => $list['page'] + 1])) ?>">下一页</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
</div>

<div class="selbar" id="selbar">
  <span>已选 <b id="selcount">0</b> 张</span>
  <button type="button" class="btn btn-danger" id="delSel">移入回收站</button>
  <button type="button" class="btn" id="clearSel">取消</button>
</div>
<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const TRASH_DAYS = <?= max(1, (int)Config::get('trash_keep_days', 30)) ?>;
const toastEl = document.getElementById('toast');
let toastTimer = null;
function toast(msg){
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2200);
}

function copyText(text, tip){
  const done = () => toast(tip || '已复制');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(fb);
  } else { fb(); }
  function fb(){
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败'); }
    document.body.removeChild(ta);
  }
}

document.querySelectorAll('.cp').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  copyText(a.dataset.url, '已复制直链');
}));

document.querySelectorAll('.cpMd').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  copyText(a.dataset.v, '已复制 Markdown');
}));

const selbar = document.getElementById('selbar');
const selcount = document.getElementById('selcount');
const checks = [...document.querySelectorAll('.chk')];

function sync(){
  const sel = checks.filter(c => c.checked);
  sel.forEach(c => c.closest('.image-card').classList.add('sel'));
  checks.filter(c => !c.checked).forEach(c => c.closest('.image-card').classList.remove('sel'));
  selcount.textContent = sel.length;
  selbar.classList.toggle('show', sel.length > 0);
}
checks.forEach(c => c.addEventListener('change', sync));

document.getElementById('clearSel').addEventListener('click', () => {
  checks.forEach(c => c.checked = false); sync();
});

document.getElementById('delSel').addEventListener('click', async () => {
  const items = checks.filter(c => c.checked).map(c => {
    const el = c.closest('.image-card');
    return { site: el.dataset.site, id: el.dataset.id, month: el.dataset.month };
  });
  if (!items.length) return;
  if (!confirm(`把选中的 ${items.length} 张移入回收站？直链会立即失效，${TRASH_DAYS} 天内可恢复。`)) return;

  const btn = document.getElementById('delSel');
  btn.disabled = true;
  try {
    const r = await fetch('api/bulk_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ items })
    });
    const j = await r.json();
    if (j.ok) {
      toast(`已移入回收站 ${j.deleted} 张${j.failed ? '，失败 ' + j.failed : ''}`);
      setTimeout(() => location.reload(), 900);
    } else {
      toast(j.error || '操作失败');
      btn.disabled = false;
    }
  } catch (e) {
    toast('操作失败：' + e.message);
    btn.disabled = false;
  }
});

// 导出当前筛选下的全部链接
async function exportLinks(kind){
  const params = new URLSearchParams({ s: <?= json_encode($siteFilter) ?>, m: <?= json_encode($month) ?>, q: <?= json_encode($keyword) ?> });
  const btns = [document.getElementById('expLinks'), document.getElementById('expMd')];
  btns.forEach(b => b.disabled = true);
  try {
    const r = await fetch('api/links.php?' + params.toString());
    const j = await r.json();
    if (!j.ok) { toast(j.error || '导出失败'); return; }
    if (!j.count) { toast('当前筛选下没有图片'); return; }
    const lines = j.items.map(it => kind === 'md' ? it.markdown : it.url);
    copyText(lines.join('\n'), `已复制 ${j.count} 条${kind === 'md' ? ' Markdown' : '直链'}`);
  } catch (e) {
    toast('导出失败：' + e.message);
  } finally {
    btns.forEach(b => b.disabled = false);
  }
}
document.getElementById('expLinks').addEventListener('click', () => exportLinks('url'));
document.getElementById('expMd').addEventListener('click', () => exportLinks('md'));

// 新增站点
document.getElementById('addSiteForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const r = await fetch('api/sites.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF } });
  const j = await r.json();
  if (j.ok) { toast(j.message); setTimeout(() => location.reload(), 700); }
  else { toast(j.error || '新增失败'); }
});

// 删除站点
document.querySelectorAll('.rmSite').forEach(b => b.addEventListener('click', async () => {
  const name = b.dataset.name;
  if (!confirm(`删除站点「${name}」？站点下有图片时会拒绝删除。`)) return;
  const fd = new FormData();
  fd.append('action', 'remove');
  fd.append('slug', b.dataset.slug);
  fd.append('csrf', CSRF);
  const r = await fetch('api/sites.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF } });
  const j = await r.json();
  if (j.ok) { toast('已删除'); setTimeout(() => location.reload(), 700); }
  else { toast(j.error || '删除失败'); }
}));
</script>
</body>
</html>
