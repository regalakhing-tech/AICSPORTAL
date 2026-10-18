<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

pswdo_start_session();
$paths = pswdo_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$initials = pswdo_initials($displayName);

$metrics = [];
$topCases = [];
$notificationLogs = [];
$todayApprovedCount = 0;
$workflowData = [
    'counts' => [
        'new_submissions' => 0,
        'document_review' => 0,
        'for_screening' => 0,
        'incomplete_requirements' => 0,
        'invalid_requirements' => 0,
        'release_ready' => 0,
    ],
    'new_submissions' => [],
    'document_review' => [],
    'for_screening' => [],
    'release_ready' => [],
];
$periodMetrics = [
    'period_code' => 'month',
    'period_label' => pswdo_overview_period_label('month'),
    'date_from' => pswdo_overview_period_range('month')[0],
    'date_to' => pswdo_overview_period_range('month')[1],
    'metrics' => [],
];
$periodTrend = [
    'points' => [],
    'max_submitted' => 1,
];
$latestRequestDate = '';
$loadError = '';

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);

    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? $initials);

    $metrics = pswdo_fetch_dashboard_metrics($connection);
    $periodMetrics = pswdo_fetch_period_metrics($connection, 'month');
    $periodTrend = pswdo_fetch_period_trend($connection, 'month');
    $latestRequestDate = pswdo_fetch_latest_request_date($connection);
    $topCases = pswdo_fetch_top_priority_cases($connection, 6);
    $notificationLogs = pswdo_fetch_notification_logs($connection, 6);
    $todayApproved = pswdo_fetch_daily_approved_list($connection, date('Y-m-d'));
    $todayApprovedCount = count($todayApproved);
    $workflowData = pswdo_fetch_workflow_dashboard_data($connection, 4);

    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load PSWDO dashboard data right now.';
}

$pendingReview = (int) ($metrics['pending_review'] ?? 0);
$interviewInitial = (int) (($metrics['for_interview'] ?? 0) + ($metrics['under_review'] ?? 0));
$approved = (int) ($metrics['approved'] ?? 0);
$notApproved = (int) ($metrics['not_approved'] ?? 0);
$released = (int) ($metrics['released'] ?? 0);
$documentsChecked = (int) ($metrics['documents_checked'] ?? 0);
$documentsUploaded = (int) ($metrics['documents_uploaded'] ?? 0);
$totalRequests = (int) ($metrics['total_requests'] ?? 0);
$syncLabel = pswdo_datetime_label(date('Y-m-d H:i:s'));
$realtimeEndpoint = $paths['php_files_base_url'] . '/overview-dashboard-data.php';

$periodCode = (string) ($periodMetrics['period_code'] ?? 'month');
$periodLabel = (string) ($periodMetrics['period_label'] ?? pswdo_overview_period_label('month'));
$periodDateFrom = (string) ($periodMetrics['date_from'] ?? pswdo_overview_period_range('month')[0]);
$periodDateTo = (string) ($periodMetrics['date_to'] ?? pswdo_overview_period_range('month')[1]);
$periodRangeLabel = $periodDateFrom === $periodDateTo
    ? pswdo_date_label($periodDateFrom)
    : (pswdo_date_label($periodDateFrom) . ' to ' . pswdo_date_label($periodDateTo));
$trendPoints = (array) ($periodTrend['points'] ?? []);
$trendMaxSubmitted = (int) ($periodTrend['max_submitted'] ?? 1);
if ($trendMaxSubmitted < 1) {
    $trendMaxSubmitted = 1;
}
$trendSubmittedTotal = array_sum(array_map(
    static fn (array $point): int => (int) ($point['submitted'] ?? 0),
    $trendPoints
));
$visibleTrendPoints = array_values(array_filter(
    $trendPoints,
    static fn (array $point): bool => (int) ($point['submitted'] ?? 0) > 0
));
$trendAxisMax = max(4, (int) (ceil($trendMaxSubmitted / 4) * 4));
$trendAxisLabels = [
    $trendAxisMax,
    (int) round($trendAxisMax * 0.75),
    (int) round($trendAxisMax * 0.5),
    (int) round($trendAxisMax * 0.25),
    0,
];
$workflowCounts = (array) ($workflowData['counts'] ?? []);
$workflowNewSubmissions = (array) ($workflowData['new_submissions'] ?? []);
$workflowDocumentReview = (array) ($workflowData['document_review'] ?? []);
$workflowForScreening = (array) ($workflowData['for_screening'] ?? []);
$workflowReleaseReady = (array) ($workflowData['release_ready'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | Overview</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
</head>
<body class="overview-dashboard-page" data-dashboard-endpoint="<?= pswdo_h($realtimeEndpoint) ?>" data-dashboard-url="<?= pswdo_h($paths['dashboard_url']) ?>">
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
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview"><i class="fas fa-layer-group"></i> Overview Dashboard</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=map"><i class="fas fa-map-location-dot"></i> Map Analytics</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests"><i class="fas fa-folder-open"></i> Request Management</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening"><i class="fas fa-clipboard-check"></i> Screening Queue</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"><i class="fas fa-calendar-check"></i> Release Scheduling</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports"><i class="fas fa-chart-pie"></i> Reports & Lists</a>
      </nav>

      <div class="sidebar-footer">
        <a href="<?= pswdo_h($paths['logout_url']) ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Log Out</a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right">
          <div class="top-chip top-chip-live">
            <span class="live-dot" aria-hidden="true"></span>
            Live Sync
          </div>
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">
        <section class="panel-card page-header-card">
          <div class="page-header-main">
            <h1><i class="fas fa-layer-group"></i> Dashboard</h1>
            <p class="overview-last-synced">
              Updated
              <strong id="dashboard-last-synced" data-iso="<?= pswdo_h(date('c')) ?>"><?= pswdo_h($syncLabel) ?></strong>
            </p>
          </div>
          <div class="page-header-actions">
            <button type="button" class="btn-solid" id="js-dashboard-refresh"><i class="fas fa-rotate"></i> Refresh</button>
          </div>
        </section>

        <div
          class="alert alert-error<?= $loadError === '' ? ' is-hidden' : '' ?>"
          id="dashboard-load-error"
          role="status"
          aria-live="polite"
        >
          <i class="fas fa-triangle-exclamation"></i>
          <span><?= pswdo_h($loadError !== '' ? $loadError : 'Unable to refresh dashboard data right now.') ?></span>
        </div>


        <section class="overview-queue-grid">
          <a class="panel-card workflow-stage-card workflow-stage-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests&amp;status_filter=pending_review">
            <span class="workflow-stage-icon stage-new"><i class="fas fa-inbox"></i></span>
            <div class="workflow-stage-copy">
              <strong id="dashboard-workflow-new-submissions"><?= (int) ($workflowCounts['new_submissions'] ?? 0) ?></strong>
              <span class="workflow-stage-label">New application requests</span>
            </div>
          </a>

          <article class="panel-card workflow-stage-card">
            <span class="workflow-stage-icon stage-docs"><i class="fas fa-file-circle-check"></i></span>
            <div class="workflow-stage-copy">
              <strong id="dashboard-workflow-document-review"><?= (int) ($workflowCounts['document_review'] ?? 0) ?></strong>
              <span class="workflow-stage-label">Document checks</span>
            </div>
          </article>

          <a class="panel-card workflow-stage-card workflow-stage-card-accent workflow-stage-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release&amp;release_view=waiting_release">
            <span class="workflow-stage-icon stage-release"><i class="fas fa-calendar-check"></i></span>
            <div class="workflow-stage-copy">
              <strong id="dashboard-workflow-release-ready"><?= (int) ($workflowCounts['release_ready'] ?? 0) ?></strong>
              <span class="workflow-stage-label">Ready to release</span>
            </div>
          </a>

          <a class="panel-card workflow-stage-card workflow-stage-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening&amp;queue_tab=for_screening">
            <span class="workflow-stage-icon stage-screen"><i class="fas fa-clipboard-check"></i></span>
            <div class="workflow-stage-copy">
              <strong id="dashboard-workflow-for-screening"><?= (int) ($workflowCounts['for_screening'] ?? 0) ?></strong>
              <span class="workflow-stage-label">For screening</span>
            </div>
          </a>
        </section>

        <article class="panel-card overview-graph-card">
          <header class="panel-head">
            <div class="overview-chart-heading">
              <span class="overview-chart-icon"><i class="fas fa-chart-simple"></i></span>
              <div>
                <h2>Application request activity</h2>
                <p><strong id="dashboard-period-label"><?= pswdo_h($periodLabel) ?></strong><span class="overview-period-range" id="dashboard-period-range"><?= pswdo_h($periodRangeLabel) ?></span></p>
              </div>
            </div>
            <div class="overview-period-controls" id="dashboard-period-controls">
              <button type="button" class="overview-period-btn<?= $periodCode === 'day' ? ' active' : '' ?>" data-period="day">Day</button>
              <button type="button" class="overview-period-btn<?= $periodCode === 'week' ? ' active' : '' ?>" data-period="week">Week</button>
              <button type="button" class="overview-period-btn<?= $periodCode === 'month' ? ' active' : '' ?>" data-period="month">Month</button>
            </div>
          </header>
          <div class="overview-trend-chart" id="dashboard-overview-graph">
            <?php if ($trendSubmittedTotal === 0): ?>
              <div class="overview-trend-empty">
                <span><i class="fas fa-chart-line"></i></span>
                <strong>No requests in this period</strong>
                <p><?= $latestRequestDate !== '' ? 'Latest request: ' . pswdo_h(pswdo_date_label($latestRequestDate)) . '. Try a longer range.' : 'No requests have been submitted yet.' ?></p>
              </div>
            <?php else: ?>
            <div class="overview-trend-visual">
              <div class="overview-trend-axis" aria-hidden="true">
                <?php foreach ($trendAxisLabels as $axisLabel): ?><span><?= $axisLabel ?></span><?php endforeach; ?>
              </div>
              <div class="overview-trend-scroll">
                <div class="overview-trend-plot" style="grid-template-columns: repeat(<?= count($visibleTrendPoints) ?>, minmax(58px, 1fr));">
                <?php foreach ($visibleTrendPoints as $point): ?>
                  <?php
                    $submittedCount = (int) ($point['submitted'] ?? 0);
                    $stackHeight = $submittedCount > 0 ? max(8, (int) round(($submittedCount / $trendAxisMax) * 100)) : 0;
                    $pendingCount = (int) ($point['pending_review'] ?? 0);
                    $interviewCount = (int) ($point['interview_initial'] ?? 0);
                    $approvedCount = (int) ($point['approved'] ?? 0);
                    $notApprovedCount = (int) ($point['not_approved'] ?? 0);
                    $releasedCount = (int) ($point['released'] ?? 0);
                    $otherCount = (int) ($point['other'] ?? 0);
                    $pointLabel = trim((string) ($point['label'] ?? ''));
                    $pointTitle = 'Submitted: ' . $submittedCount
                        . ' | Pending: ' . $pendingCount
                        . ' | Interview/Initial: ' . $interviewCount
                        . ' | Approved: ' . $approvedCount
                        . ' | Not Approved: ' . $notApprovedCount
                        . ' | Released: ' . $releasedCount
                        . ($otherCount > 0 ? (' | Other: ' . $otherCount) : '');
                  ?>
                  <div class="overview-trend-col">
                    <strong class="overview-trend-total"><?= $submittedCount > 0 ? $submittedCount : '' ?></strong>
                    <div class="overview-trend-stack-wrap">
                      <div
                        class="overview-trend-stack overview-trend-bar"
                        style="height: <?= $stackHeight ?>%;"
                        title="<?= pswdo_h($pointTitle) ?>"
                        aria-label="<?= pswdo_h($pointTitle) ?>"
                      >
                      </div>
                    </div>
                    <span class="overview-trend-label"><?= pswdo_h($pointLabel) ?></span>
                  </div>
                <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <div class="overview-trend-insight">
            <span class="overview-insight-icon"><i class="fas fa-wand-magic-sparkles"></i></span>
            <div>
              <strong>Activity summary</strong>
              <p><span id="dashboard-trend-total"><?= $trendSubmittedTotal ?></span> requests in the selected period.</p>
            </div>
            <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests">View requests <i class="fas fa-arrow-right"></i></a>
          </div>
        </article>

        <section class="panel-grid two-col overview-feed-grid">
          <article class="panel-card workflow-lane-card dashboard-feed-card feed-priority">
            <header class="panel-head">
              <h2><i class="fas fa-bolt"></i> Priority cases</h2>
              <a class="btn-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests">View all</a>
            </header>
            <div class="list-stack" id="dashboard-top-cases">
              <?php if (count($topCases) === 0): ?>
                <p class="empty-note">No ranked active cases yet.</p>
              <?php else: ?>
                <?php foreach (array_slice($topCases, 0, 4) as $case): ?>
                  <?php
                    $smartPriorityLabel = (string) ($case['priority_indicator'] ?? 'STANDARD');
                    $smartPriorityClass = (string) ($case['priority_chip_class'] ?? 'priority-chip-normal');
                  ?>
                  <div class="list-item overview-feed-item priority-feed-item">
                    <span class="feed-rank"><?= (int) ($case['priority_rank'] ?? 0) ?></span>
                    <div class="feed-item-copy">
                      <span class="feed-reference"><?= pswdo_h((string) ($case['reference_no'] ?? '-')) ?></span>
                      <strong><?= pswdo_h((string) ($case['beneficiary_name'] ?? 'Beneficiary')) ?></strong>
                      <p><?= pswdo_h((string) ($case['assistance_label'] ?? 'Assistance Request')) ?></p>
                    </div>
                    <span class="priority-chip <?= pswdo_h($smartPriorityClass) ?>"><?= pswdo_h($smartPriorityLabel) ?></span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>

          <article class="panel-card workflow-lane-card dashboard-feed-card feed-requests">
            <header class="panel-head">
              <h2><i class="fas fa-inbox"></i> New application requests</h2>
              <a class="btn-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests&status_filter=pending_review">View all</a>
            </header>
            <div class="workflow-list" id="dashboard-incoming-submissions">
              <?php if (count($workflowNewSubmissions) === 0): ?>
                <p class="empty-note">No new submissions waiting right now.</p>
              <?php else: ?>
                <?php foreach ($workflowNewSubmissions as $item): ?>
                  <div class="workflow-list-item dashboard-queue-item">
                    <div class="workflow-list-main">
                      <span class="feed-reference"><?= pswdo_h((string) ($item['reference_no'] ?? '-')) ?></span>
                      <strong><?= pswdo_h((string) ($item['beneficiary_name'] ?? 'Applicant')) ?></strong>
                      <p><?= pswdo_h((string) ($item['assistance_label'] ?? 'Assistance Request')) ?> · <?= pswdo_h((string) ($item['date_applied_label'] ?? 'Not available')) ?></p>
                      <span class="feed-progress"><i class="fas fa-paperclip"></i> <?= (int) ($item['uploaded_total'] ?? 0) ?>/<?= (int) ($item['required_total'] ?? 0) ?> files</span>
                    </div>
                    <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests&reopen_request=<?= (int) ($item['request_id'] ?? 0) ?>" class="feed-open-btn" aria-label="Open request"><i class="fas fa-arrow-right"></i></a>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>

          <article class="panel-card workflow-lane-card dashboard-feed-card feed-documents">
            <header class="panel-head">
              <h2><i class="fas fa-file-circle-check"></i> Document checks</h2>
              <a class="btn-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests">View all</a>
            </header>
            <div class="workflow-list" id="dashboard-document-review-backlog">
              <?php if (count($workflowDocumentReview) === 0): ?>
                <p class="empty-note">All uploaded documents are currently checked.</p>
              <?php else: ?>
                <?php foreach ($workflowDocumentReview as $item): ?>
                  <div class="workflow-list-item dashboard-queue-item">
                    <div class="workflow-list-main">
                      <span class="feed-reference"><?= pswdo_h((string) ($item['reference_no'] ?? '-')) ?></span>
                      <strong><?= pswdo_h((string) ($item['beneficiary_name'] ?? 'Applicant')) ?></strong>
                      <p><?= pswdo_h((string) ($item['assistance_label'] ?? 'Assistance Request')) ?> · <?= pswdo_h((string) ($item['address_label'] ?? 'Not available')) ?></p>
                      <span class="feed-progress"><i class="fas fa-file-circle-check"></i> <?= (int) ($item['checked_total'] ?? 0) ?>/<?= (int) ($item['uploaded_total'] ?? 0) ?> checked</span>
                    </div>
                    <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests&reopen_request=<?= (int) ($item['request_id'] ?? 0) ?>" class="feed-open-btn" aria-label="Open document review"><i class="fas fa-arrow-right"></i></a>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>

          <article class="panel-card workflow-lane-card dashboard-feed-card feed-notifications">
            <header class="panel-head">
              <h2><i class="fas fa-comment-sms"></i> Notifications</h2>
              <a class="btn-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports">View all</a>
            </header>
            <div class="list-stack" id="dashboard-notification-logs">
              <?php if (count($notificationLogs) === 0): ?>
                <p class="empty-note">No SMS or notification logs recorded yet.</p>
              <?php else: ?>
                <?php foreach (array_slice($notificationLogs, 0, 4) as $log): ?>
                  <div class="list-item overview-feed-item notification-feed-item">
                    <span class="notification-feed-icon"><i class="fas fa-comment-sms"></i></span>
                    <div class="feed-item-copy">
                      <span class="feed-reference"><?= pswdo_h((string) ($log['reference_no'] ?? '-')) ?></span>
                      <strong><?= pswdo_h((string) ($log['stage'] ?? 'General Update')) ?></strong>
                      <p><?= pswdo_h((string) ($log['beneficiary_name'] ?? 'Applicant')) ?> · <?= pswdo_h((string) ($log['sent_at_label'] ?? 'Not available')) ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>
        </section>

      </section>
    </main>
  </div>

  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
</body>
</html>
