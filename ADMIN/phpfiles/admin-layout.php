<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
require_once __DIR__ . '/../../includes/ai-submission-gate.php';

/**
 * @param array<string, string> $paths
 */
function admin_render_page_start(
    string $title,
    string $activePage,
    string $displayName,
    string $initials,
    array $paths
): void {
    $nav = [
        'overview' => ['icon' => 'fa-chart-line', 'label' => 'Overview', 'href' => $paths['dashboard_url'] . '?page=overview', 'group' => 'Monitor'],
        'logs' => ['icon' => 'fa-shield-halved', 'label' => 'Security Logs', 'href' => $paths['dashboard_url'] . '?page=logs', 'group' => 'Monitor'],
        'users' => ['icon' => 'fa-users-gear', 'label' => 'User Management', 'href' => $paths['dashboard_url'] . '?page=users', 'group' => 'Manage'],
        'backup' => ['icon' => 'fa-database', 'label' => 'Backup & Maintenance', 'href' => $paths['dashboard_url'] . '?page=backup', 'group' => 'Manage'],
    ];
    $groups = [];
    foreach ($nav as $key => $item) {
        $groups[$item['group']][$key] = $item;
    }
    $activeLabel = (string) ($nav[$activePage]['label'] ?? ($activePage === 'ai' ? 'AICS AI Screening' : 'Overview'));
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= admin_h($title) ?></title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= admin_h($paths['css_url']) ?>">
</head>
<body class="admin-app-page">
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <a href="<?= admin_h($paths['dashboard_url']) ?>?page=overview" class="brand">
          <img src="<?= admin_h($paths['logo_url']) ?>" alt="AICS Logo" />
        </a>
        <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
      </div>

      <nav class="sidebar-nav" aria-label="Administration">
        <?php foreach ($groups as $groupLabel => $items): ?>
          <p class="nav-title"><?= admin_h($groupLabel) ?></p>
          <?php foreach ($items as $key => $item): ?>
            <a class="nav-link<?= $activePage === $key ? ' active' : '' ?>" href="<?= admin_h($item['href']) ?>"<?= $activePage === $key ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon"><i class="fas <?= admin_h($item['icon']) ?>" aria-hidden="true"></i></span>
              <span class="nav-label"><?= admin_h($item['label']) ?></span>
              <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
                <span class="nav-badge" title="Requests needing attention"><?= (int) $item['badge'] ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </nav>

      <div class="sidebar-footer">
        <a href="<?= admin_h($paths['logout_url']) ?>" class="logout-link">
          <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
          Log Out
        </a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <nav class="top-crumbs" aria-label="Breadcrumb">
          <span>Administration</span>
          <i class="fas fa-chevron-right" aria-hidden="true"></i>
          <strong><?= admin_h($activeLabel) ?></strong>
        </nav>
        <div class="top-nav-right">
          <span class="top-date"><i class="fas fa-calendar-day" aria-hidden="true"></i> <?= admin_h(date('D, M j, Y')) ?></span>
          <div class="top-chip">
            <i class="fas fa-lock" aria-hidden="true"></i>
            Secure admin
          </div>
          <div class="profile-chip">
            <div class="avatar"><?= admin_h($initials) ?></div>
            <span><?= admin_h($displayName) ?></span>
          </div>
        </div>
      </header>
      <section class="content-wrap">
<?php
}

/**
 * Page header: icon, title, one-line purpose, and optional actions on the right.
 */
function admin_render_page_head(string $icon, string $title, string $purpose, string $actionsHtml = ''): void
{
    ?>
  <header class="adm-page-head">
    <span class="adm-page-icon"><i class="fas <?= admin_h($icon) ?>" aria-hidden="true"></i></span>
    <div class="adm-page-copy">
      <h1><?= admin_h($title) ?></h1>
      <p><?= admin_h($purpose) ?></p>
    </div>
    <?php if ($actionsHtml !== ''): ?>
      <div class="adm-page-actions"><?= $actionsHtml ?></div>
    <?php endif; ?>
  </header>
<?php
}

/**
 * One number with its icon. $tone: neutral, brand, ok, watch, alert.
 * $valueAttributes is raw HTML (for example a data attribute the page script updates).
 */
function admin_render_stat(string $icon, string $label, string $value, string $tone = 'neutral', string $hint = '', string $valueAttributes = ''): void
{
    ?>
    <article class="adm-stat tone-<?= admin_h($tone) ?>">
      <span class="adm-stat-icon"><i class="fas <?= admin_h($icon) ?>" aria-hidden="true"></i></span>
      <div class="adm-stat-body">
        <span class="adm-stat-label"><?= admin_h($label) ?></span>
        <strong class="adm-stat-value"<?= $valueAttributes !== '' ? ' ' . $valueAttributes : '' ?>><?= admin_h($value) ?></strong>
        <?php if ($hint !== ''): ?><span class="adm-stat-hint"><?= admin_h($hint) ?></span><?php endif; ?>
      </div>
    </article>
<?php
}

/** An empty table row or list with an icon and what to do next. */
function admin_render_empty(string $icon, string $title, string $hint = ''): void
{
    ?>
    <div class="adm-empty">
      <span class="adm-empty-icon"><i class="fas <?= admin_h($icon) ?>" aria-hidden="true"></i></span>
      <strong><?= admin_h($title) ?></strong>
      <?php if ($hint !== ''): ?><p><?= admin_h($hint) ?></p><?php endif; ?>
    </div>
<?php
}

/**
 * @param array<string, string> $paths
 */
function admin_render_page_end(array $paths): void
{
    ?>
      </section>
    </main>
  </div>
  <script src="<?= admin_h(dirname(dirname($paths['dashboard_url']))) ?>/asset/digits-only.js"></script>
  <script src="<?= admin_h($paths['js_url']) ?>"></script>
</body>
</html>
<?php
}
