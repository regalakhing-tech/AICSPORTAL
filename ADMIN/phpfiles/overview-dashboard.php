<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/admin-layout.php';

/**
 * @param string $value
 */
function admin_overview_date_label(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('M d, Y', $timestamp);
}

function admin_overview_period_range_label(string $from, string $to): string
{
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false) {
        return $from === $to ? $from : ($from . ' to ' . $to);
    }
    if ($from === $to) {
        return date('F d, Y', $fromTs);
    }
    return date('F d, Y', $fromTs) . ' to ' . date('F d, Y', $toTs);
}

admin_start_session();
$paths = admin_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = admin_current_display_name();
$initials = admin_initials($displayName);

$statusCode = trim((string) ($_GET['status'] ?? ''));
$statusMessages = [
    'login_success' => ['type' => 'success', 'text' => 'Administrator access granted.'],
];
$statusMessage = $statusMessages[$statusCode] ?? null;

$realtimeEndpoint = $paths['phpfiles_base_url'] . '/overview-dashboard-data.php';

$activityMetricsPayload = [
    'period_code' => 'month',
    'period_label' => 'This Month',
    'date_from' => date('Y-m-01'),
    'date_to' => date('Y-m-t'),
    'metrics' => [
        'registered_applicants' => 0,
        'submitted_requests' => 0,
        'pending_review' => 0,
        'interview_pending' => 0,
        'under_review' => 0,
        'pending_total' => 0,
        'approved_total' => 0,
        'not_approved_total' => 0,
        'released_total' => 0,
    ],
];
$activityTrendPayload = [
    'period_code' => 'month',
    'period_label' => 'This Month',
    'date_from' => date('Y-m-01'),
    'date_to' => date('Y-m-t'),
    'points' => [],
    'max_count' => 1,
];

$opsSummary = [
    'total_accounts' => 0,
    'active_accounts' => 0,
    'restricted_accounts' => 0,
    'total_requests' => 0,
    'pending_requests' => 0,
    'interview_pending' => 0,
    'approved_requests' => 0,
    'failed_logins_24h' => 0,
    'unauthorized_24h' => 0,
    'backup_month_total' => 0,
    'backup_month_success' => 0,
    'maintenance_month_total' => 0,
    'maintenance_month_passed' => 0,
];

$loadError = '';

$flowSteps = [];
$exceptionFlows = [];

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $displayName = admin_fetch_account_display_name($connection, $accountId, $displayName);
    $_SESSION['aics_display_name'] = $displayName;
    $initials = admin_initials($displayName);

    $activityMetricsPayload = admin_fetch_activity_metrics($connection, 'month');
    $activityTrendPayload = admin_fetch_activity_trend($connection, 'month');
    $flowSteps = admin_fetch_flow_steps($connection);
    $exceptionFlows = admin_fetch_exception_flows($connection);

    $accountSql = <<<SQL
        SELECT
            COUNT(*) AS total_accounts,
            SUM(CASE WHEN s.status_code = 'active' THEN 1 ELSE 0 END) AS active_accounts,
            SUM(CASE WHEN s.status_code IN ('restricted', 'locked', 'disabled', 'banned') THEN 1 ELSE 0 END) AS restricted_accounts
        FROM aics_accounts a
        INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
    SQL;
    $accountRow = $connection->query($accountSql)?->fetch_assoc();
    if (is_array($accountRow)) {
        $opsSummary['total_accounts'] = (int) ($accountRow['total_accounts'] ?? 0);
        $opsSummary['active_accounts'] = (int) ($accountRow['active_accounts'] ?? 0);
        $opsSummary['restricted_accounts'] = (int) ($accountRow['restricted_accounts'] ?? 0);
    }

    if (admin_table_exists($connection, 'aics_requests')) {
        $requestSql = <<<SQL
            SELECT
                COUNT(*) AS total_requests,
                SUM(CASE WHEN request_status IN ('pending', 'pending_review', 'for_review', 'incomplete_requirements', 'under_review') THEN 1 ELSE 0 END) AS pending_requests,
                SUM(CASE WHEN request_status IN ('for_interview') THEN 1 ELSE 0 END) AS interview_pending,
                SUM(CASE WHEN request_status IN ('approved', 'for_release', 'released') THEN 1 ELSE 0 END) AS approved_requests
            FROM aics_requests
        SQL;
        $requestRow = $connection->query($requestSql)?->fetch_assoc();
        if (is_array($requestRow)) {
            $opsSummary['total_requests'] = (int) ($requestRow['total_requests'] ?? 0);
            $opsSummary['pending_requests'] = (int) ($requestRow['pending_requests'] ?? 0);
            $opsSummary['interview_pending'] = (int) ($requestRow['interview_pending'] ?? 0);
            $opsSummary['approved_requests'] = (int) ($requestRow['approved_requests'] ?? 0);
        }
    }

    $loginSql = <<<SQL
        SELECT
            SUM(CASE WHEN is_success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS failed_logins_24h
        FROM aics_admin_login_attempts
    SQL;
    $loginRow = $connection->query($loginSql)?->fetch_assoc();
    if (is_array($loginRow)) {
        $opsSummary['failed_logins_24h'] = (int) ($loginRow['failed_logins_24h'] ?? 0);
    }

    $unauthorizedSql = <<<SQL
        SELECT COUNT(*) AS unauthorized_24h
        FROM aics_admin_audit_logs
        WHERE event_type = 'unauthorized_access'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    SQL;
    $unauthorizedRow = $connection->query($unauthorizedSql)?->fetch_assoc();
    if (is_array($unauthorizedRow)) {
        $opsSummary['unauthorized_24h'] = (int) ($unauthorizedRow['unauthorized_24h'] ?? 0);
    }

    $backupSql = <<<SQL
        SELECT
            COUNT(*) AS backup_month_total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS backup_month_success
        FROM aics_admin_backup_runs
        WHERE started_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    SQL;
    $backupRow = $connection->query($backupSql)?->fetch_assoc();
    if (is_array($backupRow)) {
        $opsSummary['backup_month_total'] = (int) ($backupRow['backup_month_total'] ?? 0);
        $opsSummary['backup_month_success'] = (int) ($backupRow['backup_month_success'] ?? 0);
    }

    $maintenanceSql = <<<SQL
        SELECT
            COUNT(*) AS maintenance_month_total,
            SUM(CASE WHEN check_status = 'pass' THEN 1 ELSE 0 END) AS maintenance_month_passed
        FROM aics_admin_maintenance_checks
        WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    SQL;
    $maintenanceRow = $connection->query($maintenanceSql)?->fetch_assoc();
    if (is_array($maintenanceRow)) {
        $opsSummary['maintenance_month_total'] = (int) ($maintenanceRow['maintenance_month_total'] ?? 0);
        $opsSummary['maintenance_month_passed'] = (int) ($maintenanceRow['maintenance_month_passed'] ?? 0);
    }

    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load full dashboard analytics right now.';
}

$activityMetrics = (array) ($activityMetricsPayload['metrics'] ?? []);
$trendPoints = (array) ($activityTrendPayload['points'] ?? []);
$trendMaxCount = max(1, (int) ($activityTrendPayload['max_count'] ?? 1));
$periodCode = (string) ($activityMetricsPayload['period_code'] ?? 'month');
$periodLabel = (string) ($activityMetricsPayload['period_label'] ?? 'This Month');
$periodDateFrom = (string) ($activityMetricsPayload['date_from'] ?? date('Y-m-01'));
$periodDateTo = (string) ($activityMetricsPayload['date_to'] ?? date('Y-m-t'));
$periodRangeLabel = admin_overview_period_range_label($periodDateFrom, $periodDateTo);

$trendSubmittedTotal = array_sum(array_map(
    static fn (array $point): int => (int) ($point['submitted_requests'] ?? 0),
    $trendPoints
));
$visibleTrendPoints = array_values(array_filter(
    $trendPoints,
    static fn (array $point): bool => (int) ($point['submitted_requests'] ?? 0) > 0
));

$trendMaxSubmitted = 1;
foreach ($trendPoints as $p) {
    $trendMaxSubmitted = max($trendMaxSubmitted, (int) ($p['submitted_requests'] ?? 0));
}
$trendAxisMax = max(4, (int) (ceil($trendMaxSubmitted / 4) * 4));
$trendAxisLabels = [
    $trendAxisMax,
    (int) round($trendAxisMax * 0.75),
    (int) round($trendAxisMax * 0.5),
    (int) round($trendAxisMax * 0.25),
    0,
];

$backupRate = $opsSummary['backup_month_total'] > 0
    ? ($opsSummary['backup_month_success'] / $opsSummary['backup_month_total']) * 100
    : 0.0;
$maintenanceRate = $opsSummary['maintenance_month_total'] > 0
    ? ($opsSummary['maintenance_month_passed'] / $opsSummary['maintenance_month_total']) * 100
    : 0.0;

admin_render_page_start('AICS ADMIN | Overview Dashboard', 'overview', $displayName, $initials, $paths);
?>
  <section class="hero-card compact">
    <div class="hero-copy">
      <p class="eyebrow"><i class="fas fa-gauge-high"></i> Dashboard Analytics</p>
      <h1>Administrator Dashboard</h1>
      <p>Track applicant usage, registration volume, request pipeline, and operational security in one unified view.</p>
    </div>
    <div class="hero-badges">
      <div class="hero-badge">
        <strong><?= (int) ($activityMetrics['registered_applicants'] ?? 0) ?></strong>
        <span>Registered Today</span>
      </div>
      <div class="hero-badge">
        <strong><?= (int) ($activityMetrics['submitted_requests'] ?? 0) ?></strong>
        <span>Requests Today</span>
      </div>
      <div class="hero-badge">
        <strong><?= (int) ($activityMetrics['interview_pending'] ?? 0) ?></strong>
        <span>Interview Pending</span>
      </div>
    </div>
  </section>

  <?php if (is_array($statusMessage)): ?>
    <div class="alert <?= admin_h((string) $statusMessage['type'] === 'success' ? 'alert-success' : 'alert-error') ?> flash-alert">
      <i class="fas fa-<?= (string) $statusMessage['type'] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= admin_h((string) $statusMessage['text']) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($loadError !== ''): ?>
    <div class="alert alert-error">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($loadError) ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-card dashboard-tab-wrapper" id="admin-overview-root" data-endpoint="<?= admin_h($realtimeEndpoint) ?>">
    <div class="dashboard-tab-nav" id="overview-tab-nav">
      <button type="button" class="dashboard-tab-btn active" data-tab-target="analytics">Live Applicant Analytics</button>
      <button type="button" class="dashboard-tab-btn" data-tab-target="operations">Operations Snapshot</button>
      <button type="button" class="dashboard-tab-btn" data-tab-target="governance">Governance Flow Plan</button>
    </div>

    <div class="dashboard-tab-panels">
      <section class="dashboard-tab-panel active" data-tab-panel="analytics">
        <div class="activity-toolbar">
          <p class="activity-period-label">
            <span class="activity-last-sync"><span class="live-dot" aria-hidden="true"></span> Live Sync &middot; Updated <strong id="activity-last-sync"><?= admin_h(admin_format_datetime(date('Y-m-d H:i:s'))) ?></strong></span>
          </p>
          <button type="button" class="btn-solid" id="activity-refresh-btn"><i class="fas fa-rotate"></i> Refresh</button>
        </div>

        <div class="metric-grid activity-metric-grid">
          <article class="metric-card metric-card-reg">
            <div class="metric-card-icon"><i class="fas fa-user-plus"></i></div>
            <div class="metric-card-info">
              <span>Registered Applicants</span>
              <strong data-activity-metric="registered_applicants"><?= (int) ($activityMetrics['registered_applicants'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-sub">
            <div class="metric-card-icon"><i class="fas fa-file-lines"></i></div>
            <div class="metric-card-info">
              <span>Submitted Requests</span>
              <strong data-activity-metric="submitted_requests"><?= (int) ($activityMetrics['submitted_requests'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-pending-rev">
            <div class="metric-card-icon"><i class="fas fa-inbox"></i></div>
            <div class="metric-card-info">
              <span>Pending Review</span>
              <strong data-activity-metric="pending_review"><?= (int) ($activityMetrics['pending_review'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-interview">
            <div class="metric-card-icon"><i class="fas fa-clipboard-user"></i></div>
            <div class="metric-card-info">
              <span>Interview Pending</span>
              <strong data-activity-metric="interview_pending"><?= (int) ($activityMetrics['interview_pending'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-under-rev">
            <div class="metric-card-icon"><i class="fas fa-magnifying-glass"></i></div>
            <div class="metric-card-info">
              <span>Under Review</span>
              <strong data-activity-metric="under_review"><?= (int) ($activityMetrics['under_review'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-total-pending">
            <div class="metric-card-icon"><i class="fas fa-clock"></i></div>
            <div class="metric-card-info">
              <span>Total Pending</span>
              <strong data-activity-metric="pending_total"><?= (int) ($activityMetrics['pending_total'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-approved">
            <div class="metric-card-icon"><i class="fas fa-circle-check"></i></div>
            <div class="metric-card-info">
              <span>Approved</span>
              <strong data-activity-metric="approved_total"><?= (int) ($activityMetrics['approved_total'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-not-approved">
            <div class="metric-card-icon"><i class="fas fa-circle-xmark"></i></div>
            <div class="metric-card-info">
              <span>Not Approved</span>
              <strong data-activity-metric="not_approved_total"><?= (int) ($activityMetrics['not_approved_total'] ?? 0) ?></strong>
            </div>
          </article>
          <article class="metric-card metric-card-released">
            <div class="metric-card-icon"><i class="fas fa-hand-holding-dollar"></i></div>
            <div class="metric-card-info">
              <span>Released</span>
              <strong data-activity-metric="released_total"><?= (int) ($activityMetrics['released_total'] ?? 0) ?></strong>
            </div>
          </article>
        </div>

        <article class="panel-card overview-graph-card">
          <header class="panel-head">
            <div class="overview-chart-heading">
              <span class="overview-chart-icon"><i class="fas fa-chart-simple"></i></span>
              <div>
                <h2>Application request activity</h2>
                <p>
                  <strong id="dashboard-period-label"><?= admin_h($periodLabel) ?></strong>
                  <span class="overview-period-range" id="dashboard-period-range"><?= admin_h($periodRangeLabel) ?></span>
                </p>
              </div>
            </div>
            <div class="overview-period-controls" id="activity-period-controls">
              <button type="button" class="overview-period-btn<?= $periodCode === 'day' ? ' active' : '' ?>" data-period="day">Day</button>
              <button type="button" class="overview-period-btn<?= $periodCode === 'week' ? ' active' : '' ?>" data-period="week">Week</button>
              <button type="button" class="overview-period-btn<?= $periodCode === 'month' ? ' active' : '' ?>" data-period="month">Month</button>
            </div>
          </header>

          <div class="overview-trend-chart" id="activity-chart">
            <?php if ($trendSubmittedTotal === 0): ?>
              <div class="overview-trend-empty">
                <span><i class="fas fa-chart-line"></i></span>
                <strong>No requests in this period</strong>
                <p>Choose another range to see more activity.</p>
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
                        $submittedCount = (int) ($point['submitted_requests'] ?? 0);
                        $stackHeight = $submittedCount > 0 ? max(8, (int) round(($submittedCount / $trendAxisMax) * 100)) : 0;
                        $pointLabel = trim((string) ($point['label'] ?? ''));
                        $pointTitle = 'Submitted: ' . $submittedCount;
                      ?>
                      <div class="overview-trend-col">
                        <strong class="overview-trend-total"><?= $submittedCount > 0 ? $submittedCount : '' ?></strong>
                        <div class="overview-trend-stack-wrap">
                          <div
                            class="overview-trend-stack overview-trend-bar"
                            style="height: <?= $stackHeight ?>%;"
                            title="<?= admin_h($pointTitle) ?>"
                            aria-label="<?= admin_h($pointTitle) ?>"
                          >
                          </div>
                        </div>
                        <span class="overview-trend-label"><?= admin_h($pointLabel) ?></span>
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
            <a href="<?= admin_h($paths['dashboard_url']) ?>?page=users">View requests <i class="fas fa-arrow-right"></i></a>
          </div>
        </article>
      </section>

      <section class="dashboard-tab-panel" data-tab-panel="operations">
        <div class="metric-grid">
          <article class="metric-card"><span>Total Accounts</span><strong><?= (int) $opsSummary['total_accounts'] ?></strong></article>
          <article class="metric-card"><span>Active Accounts</span><strong><?= (int) $opsSummary['active_accounts'] ?></strong></article>
          <article class="metric-card"><span>Restricted Accounts</span><strong><?= (int) $opsSummary['restricted_accounts'] ?></strong></article>
          <article class="metric-card"><span>Total Requests</span><strong><?= (int) $opsSummary['total_requests'] ?></strong></article>
          <article class="metric-card"><span>Pending Requests</span><strong><?= (int) $opsSummary['pending_requests'] ?></strong></article>
          <article class="metric-card"><span>Interview Pending</span><strong><?= (int) $opsSummary['interview_pending'] ?></strong></article>
          <article class="metric-card"><span>Approved Requests</span><strong><?= (int) $opsSummary['approved_requests'] ?></strong></article>
          <article class="metric-card"><span>Failed Logins (24h)</span><strong><?= (int) $opsSummary['failed_logins_24h'] ?></strong></article>
          <article class="metric-card"><span>Unauthorized Attempts (24h)</span><strong><?= (int) $opsSummary['unauthorized_24h'] ?></strong></article>
        </div>

        <section class="panel-grid two-col">
          <article class="panel-card">
            <header class="panel-head">
              <h2><i class="fas fa-bullseye"></i> Success Criteria</h2>
            </header>
            <div class="criteria-list">
              <div class="criteria-item">
                <div>
                  <strong>Backup Success Rate</strong>
                  <p>Target: at least 99% successful scheduled backups per month.</p>
                </div>
                <span class="pill <?= $backupRate >= 99 ? 'pill-success' : 'pill-warning' ?>"><?= number_format($backupRate, 1) ?>%</span>
              </div>
              <div class="criteria-item">
                <div>
                  <strong>Maintenance Pass Rate</strong>
                  <p>Target: at least 99.5% pass rate on routine service checks.</p>
                </div>
                <span class="pill <?= $maintenanceRate >= 99.5 ? 'pill-success' : 'pill-warning' ?>"><?= $opsSummary['maintenance_month_total'] > 0 ? number_format($maintenanceRate, 1) . '%' : 'No data' ?></span>
              </div>
              <div class="criteria-item">
                <div>
                  <strong>Audit Alert Burden</strong>
                  <p>Goal: keep failed login and unauthorized events as low as possible.</p>
                </div>
                <span class="pill <?= (($opsSummary['failed_logins_24h'] + $opsSummary['unauthorized_24h']) <= 5) ? 'pill-success' : 'pill-danger' ?>">
                  <?= (int) ($opsSummary['failed_logins_24h'] + $opsSummary['unauthorized_24h']) ?> alerts
                </span>
              </div>
            </div>
          </article>

          <article class="panel-card">
            <header class="panel-head">
              <h2><i class="fas fa-shield"></i> Security Pulse</h2>
            </header>
            <div class="list-stack">
              <div class="list-item">
                <div>
                  <strong>Backup Jobs This Month</strong>
                  <p>Successful runs versus all scheduled backup executions.</p>
                </div>
                <span class="pill pill-info"><?= (int) $opsSummary['backup_month_success'] ?>/<?= (int) $opsSummary['backup_month_total'] ?></span>
              </div>
              <div class="list-item">
                <div>
                  <strong>Maintenance Checks This Month</strong>
                  <p>Passed checks versus total maintenance checks.</p>
                </div>
                <span class="pill pill-info"><?= (int) $opsSummary['maintenance_month_passed'] ?>/<?= (int) $opsSummary['maintenance_month_total'] ?></span>
              </div>
              <div class="list-item">
                <div>
                  <strong>Unauthorized Attempts (24h)</strong>
                  <p>All admin-route access attempts from non-admin identities.</p>
                </div>
                <span class="pill <?= $opsSummary['unauthorized_24h'] > 0 ? 'pill-danger' : 'pill-success' ?>"><?= (int) $opsSummary['unauthorized_24h'] ?></span>
              </div>
            </div>
          </article>
        </section>
      </section>

      <section class="dashboard-tab-panel" data-tab-panel="governance">
        <article class="panel-card">
          <header class="panel-head">
            <h2><i class="fas fa-list-check"></i> System Administrator Flow Plan</h2>
          </header>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Step</th>
                  <th>Action</th>
                  <th>Frequency</th>
                  <th>Success Criteria</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($flowSteps) === 0): ?>
                  <tr>
                    <td colspan="4">No flow plan rows found yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($flowSteps as $flow): ?>
                    <tr>
                      <td><?= (int) $flow['step'] ?></td>
                      <td><?= admin_h((string) $flow['action']) ?></td>
                      <td><?= admin_h((string) $flow['frequency']) ?></td>
                      <td><?= admin_h((string) $flow['criteria']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>

        <article class="panel-card">
          <header class="panel-head">
            <h2><i class="fas fa-route"></i> Exception Flows</h2>
          </header>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Exception</th>
                  <th>Trigger</th>
                  <th>Response Flow</th>
                  <th>Frequency</th>
                  <th>Success Criteria</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($exceptionFlows) === 0): ?>
                  <tr>
                    <td colspan="5">No exception-flow rows found yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($exceptionFlows as $flow): ?>
                    <tr>
                      <td><?= admin_h((string) $flow['exception']) ?></td>
                      <td><?= admin_h((string) $flow['trigger']) ?></td>
                      <td><?= admin_h((string) $flow['response']) ?></td>
                      <td><?= admin_h((string) $flow['frequency']) ?></td>
                      <td><?= admin_h((string) $flow['criteria']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>
      </section>
    </div>
  </section>
<?php
admin_render_page_end($paths);
