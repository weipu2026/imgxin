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

$csrf  = Auth::csrf();
$sites = Sites::all();

// 每次进页面顺手清一次过期文件
$keepDays   = max(1, (int)Config::get('trash_keep_days', 30));
$autoPurged = Store::purgeExpired($keepDays);

$page = max(1, (int)($_GET['page'] ?? 1));
$list = Store::listTrash(null, $page, 40);

$siteName = static function (string $slug) use ($sites): string {
    foreach ($sites as $s) {
        if ($s['slug'] === $slug) {
            return $s['name'];
        }
    }
    return $slug;
};

$usage = Store::trashUsage();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>回收站 · imgxin</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-trash">
<div class="wrap">
<?php $active = 'trash'; require __DIR__ . '/partials/topbar.php'; ?>
<main>
  <section class="page-heading">
    <div>
      <h1>回收站</h1>
      <p>误删的图片会暂存在这里，在保留期内可以恢复。</p>
    </div>
    <div class="heading-meta">
      共 <b><?= (int)$usage['count'] ?></b> 张 · <b><?= Http::esc(Http::bytes((int)$usage['bytes'])) ?></b><br>
      保留 <b><?= (int)$keepDays ?></b> 天
    </div>
  </section>

  <?php if ($autoPurged > 0): ?>
    <div class="alert alert-warning">已自动清理 <?= (int)$autoPurged ?> 个超过 <?= (int)$keepDays ?> 天的旧文件。</div>
  <?php endif; ?>

  <section class="card flush" aria-label="回收站操作">
    <div class="trash-toolbar">
      <p>删除只是挪到这里，直链会立即失效；恢复后链接原样可用。</p>
      <div class="row">
        <button type="button" class="btn" id="purgeExpired">清理过期</button>
        <button type="button" class="btn btn-danger" id="purgeAll">清空回收站</button>
      </div>
    </div>
  </section>

  <?php if ($list['items'] === []): ?>
    <div class="card empty">
      <div class="empty-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg>
      </div>
      回收站是空的
    </div>
  <?php else: ?>
    <section class="card flush trash-list" aria-label="已删除图片">
      <?php foreach ($list['items'] as $it): ?>
        <article class="trash-item" data-site="<?= Http::esc($it['site']) ?>" data-id="<?= Http::esc($it['id']) ?>" data-month="<?= Http::esc($it['month']) ?>" data-name="<?= Http::esc($it['name']) ?>">
          <div class="thumb">
            <img src="api/thumb.php?s=<?= Http::esc($it['site']) ?>&amp;m=<?= Http::esc($it['month']) ?>&amp;id=<?= Http::esc($it['id']) ?>&amp;e=<?= Http::esc(pathinfo($it['name'], PATHINFO_EXTENSION)) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
          </div>
          <div class="meta">
            <div class="name" title="<?= Http::esc($it['original_name']) ?>"><span class="tag tag-blue"><?= Http::esc($siteName($it['site'])) ?></span><?= Http::esc($it['original_name']) ?></div>
            <div class="sub"><?= Http::esc($it['name']) ?> · <?= Http::esc(Http::bytes((int)$it['bytes'])) ?><?= $it['width'] > 0 ? ' · ' . (int)$it['width'] . '×' . (int)$it['height'] : '' ?></div>
            <div class="sub">删除于 <?= Http::esc($it['deleted_at']) ?></div>
          </div>
          <div class="days">还剩 <b><?= (int)$it['days_left'] ?></b> 天</div>
          <div class="actions">
            <button type="button" class="btn btn-sm restore">恢复</button>
            <button type="button" class="btn btn-sm btn-danger purge">彻底删除</button>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <?php if ($list['pages'] > 1): ?>
      <nav class="pager" aria-label="回收站分页">
        <?php if ($list['page'] > 1): ?><a href="?page=<?= (int)($list['page'] - 1) ?>">上一页</a><?php endif; ?>
        <span class="cur"><?= (int)$list['page'] ?> / <?= (int)$list['pages'] ?></span>
        <?php if ($list['page'] < $list['pages']): ?><a href="?page=<?= (int)($list['page'] + 1) ?>">下一页</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <footer>imgxin · 回收站文件不参与静态托管</footer>
</main>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const toastEl = document.getElementById('toast');
let tt = null;
function toast(msg){
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(tt);
  tt = setTimeout(() => toastEl.classList.remove('show'), 2200);
}

async function call(payload){
  const r = await fetch('api/trash.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload)
  });
  return r.json();
}

function itemOf(el){
  return { site: el.dataset.site, id: el.dataset.id, month: el.dataset.month };
}

function reloadIfEmpty(){
  if (!document.querySelectorAll('.trash-item').length) {
    setTimeout(() => location.reload(), 500);
  }
}

document.querySelectorAll('.restore').forEach(b => b.addEventListener('click', async () => {
  const row = b.closest('.trash-item');
  b.disabled = true;
  try {
    const j = await call({ action: 'restore', items: [itemOf(row)] });
    if (j.ok && j.done) {
      row.remove();
      toast('已恢复，链接恢复可用');
      reloadIfEmpty();
    } else if (j.conflict) {
      toast('该图已重新上传过，原位置被占用，无法恢复');
      b.disabled = false;
    } else {
      toast('恢复失败：文件可能已被清理');
      b.disabled = false;
    }
  } catch (e) {
    toast('恢复失败：' + e.message);
    b.disabled = false;
  }
}));

document.querySelectorAll('.purge').forEach(b => b.addEventListener('click', async () => {
  const row = b.closest('.trash-item');
  if (!confirm('彻底删除后无法恢复，确定？')) { return; }
  b.disabled = true;
  try {
    const j = await call({ action: 'purge', items: [itemOf(row)] });
    if (j.ok && j.done) {
      row.remove();
      toast('已彻底删除');
      reloadIfEmpty();
    } else {
      toast('删除失败');
      b.disabled = false;
    }
  } catch (e) {
    toast('删除失败：' + e.message);
    b.disabled = false;
  }
}));

document.getElementById('purgeExpired').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const j = await call({ action: 'purge_expired' });
    toast(j.ok ? `已清理 ${j.purged} 个超过 ${j.days} 天的文件` : '清理失败');
    if (j.ok) { setTimeout(() => location.reload(), 800); }
  } catch (err) {
    toast('清理失败：' + err.message);
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('purgeAll').addEventListener('click', async (e) => {
  if (!confirm('清空回收站内全部文件？此操作不可恢复。')) { return; }
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const j = await call({ action: 'purge_all' });
    toast(j.ok ? `已彻底删除 ${j.purged} 个文件` : '清空失败');
    if (j.ok) { setTimeout(() => location.reload(), 800); }
  } catch (err) {
    toast('清空失败：' + err.message);
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
