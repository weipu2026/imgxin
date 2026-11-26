<?php
/**
 * 顶部导航栏
 * @var string $active  当前页面标识：upload|admin|trash|settings
 */
$active = $active ?? '';
// CSRF 显式从会话读取，不依赖父页面变量穿透
$csrf = Auth::csrf();
$navItems = [
    'upload' => ['上传', './'],
    'admin' => ['图片管理', 'admin.php'],
    'trash' => ['回收站', 'trash.php'],
    'settings' => ['设置', 'settings.php'],
];
?>
<header class="topbar">
  <a class="brand" href="./" aria-label="imgxin 图床首页">
    <span class="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M21 5H3a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1Zm-14.5 3A1.5 1.5 0 1 1 8.5 9.5 1.5 1.5 0 0 1 6.5 8Zm-3 8 4.5-4.5 3 3 4-4 4 4v3H3.5Z"/></svg>
    </span>
    <span class="brand-copy">
      <span class="brand-name">imgxin</span>
      <span class="brand-sub">轻量图床</span>
    </span>
  </a>

  <nav class="nav" aria-label="主导航">
    <?php foreach ($navItems as $key => $item): ?>
      <a href="<?= Http::esc($item[1]) ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>><?= Http::esc($item[0]) ?></a>
    <?php endforeach; ?>
    <?php if (Upgrade::pendingCount() > 0): ?>
      <a href="settings.php" class="nav-upgrade" title="有新版本可升级">升级</a>
    <?php elseif (Upgrade::isDowngrade()): ?>
      <a href="settings.php" class="nav-warn" title="当前数据版本高于代码版本">降级</a>
    <?php endif; ?>
    <span class="nav-divider" aria-hidden="true"></span>
    <form method="post">
      <input type="hidden" name="do" value="logout">
      <input type="hidden" name="csrf" value="<?= Http::esc($csrf) ?>">
      <button type="submit">退出</button>
    </form>
  </nav>
</header>
