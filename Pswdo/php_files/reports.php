<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

pswdo_start_session();
$paths = pswdo_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$initials = pswdo_initials($displayName);

$reportDate = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}

$summary = [];
$notificationLogs = [];
$aiOperationalMetrics = [];
$loadError = '';

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);
    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? $initials);

    $summary = pswdo_fetch_report_summary($connection, $dateFrom, $dateTo);
    $aiOperationalMetrics = pswdo_fetch_ai_operational_metrics($connection, $dateFrom, $dateTo);
    $notificationLogs = pswdo_fetch_notification_logs($connection, 20);
    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load reports right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | Reports</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
</head>
<body class="reports-page">
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview" class="brand">
          <img src="<?= pswdo_h($paths['logo_url']) ?>" alt="AICS Logo" />
        </a>
        <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
      </div>

      <nav class="sidebar-nav">
        <p class="nav-title">PSWDO Menu</p>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview"><i class="fas fa-layer-group"></i> Overview Dashboard</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=map"><i class="fas fa-map-location-dot"></i> Map Analytics</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests"><i class="fas fa-folder-open"></i> Request Management</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening"><i class="fas fa-clipboard-check"></i> Screening Queue</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"><i class="fas fa-calendar-check"></i> Release Scheduling</a>
        <?= pswdo_mswdo_nav_link($paths['dashboard_url']) ?>
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports"><i class="fas fa-chart-pie"></i> Reports & Lists</a>
      </nav>

      <div class="sidebar-footer">
        <a href="<?= pswdo_h($paths['logout_url']) ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Log Out</a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right">
          <div class="top-chip"><i class="fas fa-chart-pie"></i> Official Reports</div>
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">
        <div class="report-print-watermark" aria-hidden="true">
          <img src="<?= pswdo_h(rtrim(dirname($paths['pswdo_base_url']), '/') . '/asset/logoinphone.png') ?>" alt="" />
        </div>
        <?php
          $printReportTitle = 'Application Summary Report';
          $printPeriodLabel = pswdo_date_label($dateFrom) . ' to ' . pswdo_date_label($dateTo);
          $printGeneratedAt = date('F j, Y \a\t g:i A');
          $printPreparedBy = $displayName;
          require __DIR__ . '/_report-print-header.php';
          require __DIR__ . '/_report-print-summary.php';
        ?>

        <!-- Executive Screen Header Card -->
        <section class="panel-card page-header-card reports-header-card">
          <div class="reports-header-content">
            <div class="reports-header-icon-box">
              <i class="fas fa-chart-pie"></i>
            </div>
            <div class="reports-header-titles">
              <div class="reports-title-row">
                <h1>Daily Approved Lists and Office Summaries</h1>
                <span class="reports-status-tag"><i class="fas fa-shield-check"></i> PSWDO Antique</span>
              </div>
              <p class="reports-header-desc">
                Official summaries, assistance disbursement registries, and periodic office performance metrics.
              </p>
            </div>
          </div>
          <div class="page-header-actions">
            <div class="report-period-pill">
              <i class="fas fa-calendar-range"></i>
              <span>Period: <strong><?= pswdo_h($dateFrom) ?></strong> &ndash; <strong><?= pswdo_h($dateTo) ?></strong></span>
            </div>
            <button type="button" class="btn-outline print-btn" onclick="window.print()" title="Print report summary">
              <i class="fas fa-print"></i> Print Report
            </button>
          </div>
        </section>

        <!-- Quick navigation to formal report registries -->
        <section class="reports-nav-grid">
          <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=report_for_release&date_from=<?= rawurlencode($dateFrom) ?>&date_to=<?= rawurlencode($dateTo) ?>" class="report-feature-card card-schedule">
            <div class="card-glow glow-schedule"></div>
            <div class="card-inner">
              <div class="card-header-row">
                <div class="feature-icon-squircle icon-schedule">
                  <i class="fas fa-calendar-check"></i>
                </div>
                <span class="feature-badge badge-schedule">
                  <i class="fas fa-clock"></i> Queued for Release
                </span>
              </div>
              <div class="card-text-block">
                <h3 class="feature-title">Approved AICS Ready for Release Scheduling</h3>
                <p class="feature-desc">
                  Formal registry of applicants approved and queued for assistance release, complete with scheduled dates, venues, and assigned distribution batches.
                </p>
              </div>
              <div class="card-footer-row">
                <span class="feature-action-link">Open Schedule Registry</span>
                <span class="feature-arrow-btn"><i class="fas fa-arrow-right"></i></span>
              </div>
            </div>
          </a>

          <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=report_released&date_from=<?= rawurlencode($dateFrom) ?>&date_to=<?= rawurlencode($dateTo) ?>" class="report-feature-card card-released">
            <div class="card-glow glow-released"></div>
            <div class="card-inner">
              <div class="card-header-row">
                <div class="feature-icon-squircle icon-released">
                  <i class="fas fa-hand-holding-heart"></i>
                </div>
                <span class="feature-badge badge-released">
                  <i class="fas fa-check-double"></i> Completed Releases
                </span>
              </div>
              <div class="card-text-block">
                <h3 class="feature-title">Beneficiaries Who Received Assistance</h3>
                <p class="feature-desc">
                  Official masterlist of AICS beneficiaries who have successfully received and claimed their approved financial assistance grants.
                </p>
              </div>
              <div class="card-footer-row">
                <span class="feature-action-link">Open Disbursement Masterlist</span>
                <span class="feature-arrow-btn"><i class="fas fa-arrow-right"></i></span>
              </div>
            </div>
          </a>
        </section>

        <?php if ($loadError !== ''): ?>
          <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= pswdo_h($loadError) ?></div>
        <?php endif; ?>

        <!-- Filter Period Card -->
        <section class="panel-card report-filter-card">
          <form method="get" action="<?= pswdo_h($paths['dashboard_url']) ?>" class="filter-toolbar-form">
            <input type="hidden" name="page" value="reports" />
            <div class="filter-intro">
              <span class="filter-intro-icon"><i class="fas fa-sliders"></i></span>
              <div>
                <strong class="filter-intro-title">Filter Summary Period</strong>
                <span class="filter-intro-hint">Adjust date range for statistics &amp; activity</span>
              </div>
            </div>
            <div class="filter-fields-group">
              <div class="filter-field-item">
                <label for="date_from"><i class="fas fa-calendar-day"></i> Summary Date From</label>
                <div class="input-with-icon">
                  <input id="date_from" type="date" name="date_from" value="<?= pswdo_h($dateFrom) ?>">
                </div>
              </div>
              <div class="filter-field-item">
                <label for="date_to"><i class="fas fa-calendar-check"></i> Summary Date To</label>
                <div class="input-with-icon">
                  <input id="date_to" type="date" name="date_to" value="<?= pswdo_h($dateTo) ?>">
                </div>
              </div>
              <div class="filter-actions-item">
                <button type="submit" class="btn-solid filter-btn">
                  <i class="fas fa-arrows-rotate"></i> Refresh Report
                </button>
              </div>
            </div>
          </form>
        </section>

        <!-- Metric Summary KPI Cards -->
        <section class="report-stats-grid">
          <article class="report-stat-card stat-total">
            <div class="stat-icon-wrap icon-blue">
              <i class="fas fa-folder-open"></i>
            </div>
            <div class="stat-content">
              <span class="stat-label">Total Submitted</span>
              <strong class="stat-number"><?= number_format((int) ($summary['total_submitted'] ?? 0)) ?></strong>
              <span class="stat-period-note">During selected period</span>
            </div>
          </article>

          <article class="report-stat-card stat-approved">
            <div class="stat-icon-wrap icon-indigo">
              <i class="fas fa-circle-check"></i>
            </div>
            <div class="stat-content">
              <span class="stat-label">Approved</span>
              <strong class="stat-number"><?= number_format((int) ($summary['approved'] ?? 0)) ?></strong>
              <span class="stat-period-note">Passed verification</span>
            </div>
          </article>

          <article class="report-stat-card stat-disapproved">
            <div class="stat-icon-wrap icon-rose">
              <i class="fas fa-circle-xmark"></i>
            </div>
            <div class="stat-content">
              <span class="stat-label">Not Approved</span>
              <strong class="stat-number"><?= number_format((int) ($summary['not_approved'] ?? 0)) ?></strong>
              <span class="stat-period-note">Ineligible / Disapproved</span>
            </div>
          </article>

          <article class="report-stat-card stat-released">
            <div class="stat-icon-wrap icon-emerald">
              <i class="fas fa-hand-holding-dollar"></i>
            </div>
            <div class="stat-content">
              <span class="stat-label">Released</span>
              <strong class="stat-number"><?= number_format((int) ($summary['released'] ?? 0)) ?></strong>
              <span class="stat-period-note">Disbursed to client</span>
            </div>
          </article>

          <article class="report-stat-card stat-pending">
            <div class="stat-icon-wrap icon-amber">
              <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-content">
              <span class="stat-label">Under Review</span>
              <strong class="stat-number"><?= number_format((int) ($summary['under_review'] ?? 0)) ?></strong>
              <span class="stat-period-note">In screening / queue</span>
            </div>
          </article>
        </section>

        <section class="panel-card ai-operations-panel">
          <header class="panel-head">
            <div class="panel-title-group"><h2><i class="fas fa-microchip"></i> AI workflow health</h2></div>
            <span class="panel-sub-note">Operational workflow metrics; not validated model accuracy.</span>
          </header>
          <div class="ai-operations-grid">
            <div><strong><?= number_format((int) ($aiOperationalMetrics['reviews'] ?? 0)) ?></strong><span>Documents reviewed</span></div>
            <div><strong><?= $aiOperationalMetrics['invocation_rate'] === null ? '—' : pswdo_h((string) $aiOperationalMetrics['invocation_rate']) . '%' ?></strong><span>External review used</span></div>
            <div><strong><?= $aiOperationalMetrics['unavailable_rate'] === null ? '—' : pswdo_h((string) $aiOperationalMetrics['unavailable_rate']) . '%' ?></strong><span>Provider unavailable</span></div>
            <div><strong><?= $aiOperationalMetrics['p95_ms'] === null ? '—' : number_format((int) $aiOperationalMetrics['p95_ms']) . ' ms' ?></strong><span>P95 provider time</span></div>
            <div><strong><?= $aiOperationalMetrics['agreement_rate'] === null ? '—' : pswdo_h((string) $aiOperationalMetrics['agreement_rate']) . '%' ?></strong><span>Staff agreement</span></div>
            <div><strong><?= $aiOperationalMetrics['override_rate'] === null ? '—' : pswdo_h((string) $aiOperationalMetrics['override_rate']) . '%' ?></strong><span>Staff override</span></div>
          </div>
        </section>

        <!-- Notification Log Summary Panel -->
        <section class="panel-card report-log-panel report-notifications-panel">
          <header class="panel-head">
            <div class="panel-title-group">
              <h2><i class="fas fa-comment-sms"></i> Notifications</h2>
              <span class="table-count-badge"><?= count($notificationLogs) ?> logs</span>
            </div>
            <span class="panel-sub-note"><i class="fas fa-circle-info"></i> Latest delivery activity</span>
          </header>
          <?php if (count($notificationLogs) === 0): ?>
            <div class="report-empty-state">
              <div class="empty-icon"><i class="fas fa-inbox"></i></div>
              <h3>No Notification Logs Found</h3>
              <p>No automated applicant SMS logs are recorded yet for this office.</p>
            </div>
          <?php else: ?>
            <?php require __DIR__ . '/_notification-log-list.php'; ?>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>

  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
</body>
</html>
