<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

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
        'overview' => ['icon' => 'fa-chart-line', 'label' => 'Overview', 'href' => $paths['dashboard_url'] . '?page=overview'],
        'users' => ['icon' => 'fa-users-gear', 'label' => 'User Management', 'href' => $paths['dashboard_url'] . '?page=users'],
        'logs' => ['icon' => 'fa-shield-halved', 'label' => 'Security Logs', 'href' => $paths['dashboard_url'] . '?page=logs'],
        'backup' => ['icon' => 'fa-database', 'label' => 'Backup & Maintenance', 'href' => $paths['dashboard_url'] . '?page=backup'],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= admin_h($title) ?></title>
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

      <nav class="sidebar-nav">
        <p class="nav-title">Administration</p>
        <?php foreach ($nav as $key => $item): ?>
          <a class="nav-link<?= $activePage === $key ? ' active' : '' ?>" href="<?= admin_h($item['href']) ?>">
            <i class="fas <?= admin_h($item['icon']) ?>"></i>
            <?= admin_h($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="sidebar-footer">
        <a href="<?= admin_h($paths['logout_url']) ?>" class="logout-link">
          <i class="fas fa-right-from-bracket"></i>
          Log Out
        </a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right">
          <div class="top-chip">
            <i class="fas fa-lock"></i>
            Secure ADMIN
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
