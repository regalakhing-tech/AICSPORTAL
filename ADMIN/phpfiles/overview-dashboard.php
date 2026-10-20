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
    'last_backup_at' => '',
    'ai_attention' => 0,
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

    $lastBackup = $connection->query("SELECT MAX(completed_at) FROM aics_admin_backup_runs WHERE status = 'success'")?->fetch_row();
    $opsSummary['last_backup_at'] = (string) ($lastBackup[0] ?? '');
    $opsSummary['ai_attention'] = aics_ai_gate_attention_count($connection);

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

// What needs the administrator's attention, as plain sentences.
$plural = static fn (int $count, string $one, string $many): string => $count === 1 ? $one : $many;
$dashboardUrl = $paths['dashboard_url'];
$backupDays = null;
$lastBackupTs = $opsSummary['last_backup_at'] !== '' ? strtotime($opsSummary['last_backup_at']) : false;
if ($lastBackupTs !== false) {
    $backupDays = max(0, (int) floor((time() - $lastBackupTs) / 86400));
}
$aiAttention = (int) $opsSummary['ai_attention'];
$failedLogins = (int) $opsSummary['failed_logins_24h'];
$unauthorized = (int) $opsSummary['unauthorized_24h'];
$restricted = (int) $opsSummary['restricted_accounts'];
$maintenanceTotal = (int) $opsSummary['maintenance_month_total'];
$maintenanceFailed = max(0, $maintenanceTotal - (int) $opsSummary['maintenance_month_passed']);

$statusRows = [];
$statusRows[] = $aiAttention > 0
    ? ['tone' => 'watch', 'icon' => 'fa-robot', 'text' => $aiAttention . ' ' . $plural($aiAttention, 'request needs', 'requests need') . ' your decision',
        'detail' => 'AICS AI could not confirm or could not check their documents.', 'href' => $dashboardUrl . '?page=ai', 'action' => 'Review requests']
    : ['tone' => 'ok', 'icon' => 'fa-robot', 'text' => 'No requests are waiting for your decision',
        'detail' => 'AICS AI sends checked requests straight to PSWDO.', 'href' => $dashboardUrl . '?page=ai', 'action' => 'Open AICS AI'];
if ($unauthorized > 0) {
    $statusRows[] = ['tone' => 'alert', 'icon' => 'fa-user-secret', 'text' => $unauthorized . ' unauthorized access ' . $plural($unauthorized, 'attempt', 'attempts') . ' in the last 24 hours',
        'detail' => 'Someone without admin rights tried to open admin pages.', 'href' => $dashboardUrl . '?page=logs', 'action' => 'View security logs'];
} else {
    $statusRows[] = ['tone' => $failedLogins >= 5 ? 'watch' : 'ok', 'icon' => 'fa-right-to-bracket',
        'text' => $failedLogins === 0 ? 'No failed sign-ins in the last 24 hours' : $failedLogins . ' failed ' . $plural($failedLogins, 'sign-in', 'sign-ins') . ' in the last 24 hours',
        'detail' => $failedLogins >= 5 ? 'Check for repeated attempts on the same account.' : 'No unauthorized access attempts.', 'href' => $dashboardUrl . '?page=logs', 'action' => 'View security logs'];
}
$statusRows[] = $restricted > 0
    ? ['tone' => 'watch', 'icon' => 'fa-user-lock', 'text' => $restricted . ' ' . $plural($restricted, 'account is', 'accounts are') . ' restricted, locked or banned',
        'detail' => 'Out of ' . (int) $opsSummary['total_accounts'] . ' accounts.', 'href' => $dashboardUrl . '?page=users', 'action' => 'Manage users']
    : ['tone' => 'ok', 'icon' => 'fa-users', 'text' => 'All ' . (int) $opsSummary['total_accounts'] . ' accounts are active',
        'detail' => 'No restricted, locked or banned accounts.', 'href' => $dashboardUrl . '?page=users', 'action' => 'Manage users'];
if ($backupDays === null) {
    $statusRows[] = ['tone' => 'alert', 'icon' => 'fa-database', 'text' => 'No backup has been made yet',
        'detail' => 'Run a backup so the records can be restored if something goes wrong.', 'href' => $dashboardUrl . '?page=backup', 'action' => 'Run a backup'];
} else {
    $statusRows[] = ['tone' => $backupDays > 7 ? 'watch' : 'ok', 'icon' => 'fa-database',
        'text' => $backupDays > 7 ? 'Last backup was ' . $backupDays . ' days ago' : 'Last backup: ' . date('M j, Y', (int) $lastBackupTs),
        'detail' => $backupDays > 7 ? 'A backup at least once a week is recommended.' : ($backupDays === 0 ? 'Made today.' : $backupDays . ' ' . $plural($backupDays, 'day', 'days') . ' ago.'),
        'href' => $dashboardUrl . '?page=backup', 'action' => $backupDays > 7 ? 'Run a backup' : 'Open backups'];
}
if ($maintenanceTotal === 0) {
    $statusRows[] = ['tone' => 'watch', 'icon' => 'fa-stethoscope', 'text' => 'No maintenance check this month',
        'detail' => 'Run the checks to confirm the database and logs are healthy.', 'href' => $dashboardUrl . '?page=backup', 'action' => 'Run checks'];
} elseif ($maintenanceFailed > 0) {
    $statusRows[] = ['tone' => 'alert', 'icon' => 'fa-stethoscope', 'text' => $maintenanceFailed . ' maintenance ' . $plural($maintenanceFailed, 'check', 'checks') . ' failed this month',
        'detail' => 'Open the check log to see what failed.', 'href' => $dashboardUrl . '?page=backup', 'action' => 'View checks'];
} else {
    $statusRows[] = ['tone' => 'ok', 'icon' => 'fa-stethoscope', 'text' => 'All ' . $maintenanceTotal . ' maintenance ' . $plural($maintenanceTotal, 'check', 'checks') . ' passed this month',
        'detail' => 'Database, backup folder and logs are healthy.', 'href' => $dashboardUrl . '?page=backup', 'action' => 'Open maintenance'];
}
$attentionCount = count(array_filter($statusRows, static fn (array $row): bool => $row['tone'] !== 'ok'));
$toneIcons = ['ok' => 'fa-circle-check', 'watch' => 'fa-circle-exclamation', 'alert' => 'fa-triangle-exclamation'];
$toneWords = ['ok' => 'All good', 'watch' => 'Check soon', 'alert' => 'Act now'];

admin_render_page_start('AICS ADMIN | Overview Dashboard', 'overview', $displayName, $initials, $paths);
admin_render_page_head('fa-chart-line', 'Overview', 'What needs your attention today, and how applications are moving.');
?>

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

  <section class="adm-board<?= $attentionCount > 0 ? ' has-attention' : '' ?>" aria-labelledby="adm-board-title">
    <header class="adm-board-head">
      <span class="adm-board-mark"><i class="fas <?= $attentionCount > 0 ? 'fa-bell' : 'fa-circle-check' ?>" aria-hidden="true"></i></span>
      <div>
        <h2 id="adm-board-title"><?= $attentionCount > 0 ? admin_h($attentionCount . ' ' . $plural($attentionCount, 'thing needs', 'things need') . ' your attention') : 'Everything looks good' ?></h2>
        <p>Checked when this page opened. Refresh the page to check again.</p>
      </div>
    </header>
    <ul class="adm-board-list">
      <?php foreach ($statusRows as $row): ?>
        <li class="adm-board-row tone-<?= admin_h($row['tone']) ?>">
          <span class="adm-board-state" title="<?= admin_h($toneWords[$row['tone']]) ?>"><i class="fas <?= admin_h($toneIcons[$row['tone']]) ?>" aria-hidden="true"></i><span class="sr-only"><?= admin_h($toneWords[$row['tone']]) ?>:</span></span>
          <span class="adm-board-topic"><i class="fas <?= admin_h($row['icon']) ?>" aria-hidden="true"></i></span>
          <div class="adm-board-text">
            <strong><?= admin_h($row['text']) ?></strong>
            <span><?= admin_h($row['detail']) ?></span>
          </div>
          <a class="adm-board-action" href="<?= admin_h($row['href']) ?>"><?= admin_h($row['action']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="panel-card dashboard-tab-wrapper" id="admin-overview-root" data-endpoint="<?= admin_h($realtimeEndpoint) ?>">
    <div class="dashboard-tab-nav" id="overview-tab-nav" role="tablist">
      <button type="button" class="dashboard-tab-btn active" data-tab-target="analytics"><i class="fas fa-chart-column" aria-hidden="true"></i> Applications</button>
      <button type="button" class="dashboard-tab-btn" data-tab-target="operations"><i class="fas fa-server" aria-hidden="true"></i> Operations</button>
      <button type="button" class="dashboard-tab-btn" data-tab-target="governance"><i class="fas fa-list-check" aria-hidden="true"></i> Admin routine</button>
    </div>

    <div class="dashboard-tab-panels">
      <section class="dashboard-tab-panel active" data-tab-panel="analytics">
        <div class="activity-toolbar">
          <p class="activity-period-label">
            <span class="activity-last-sync"><span class="live-dot" aria-hidden="true"></span> Live. Updated <strong id="activity-last-sync"><?= admin_h(admin_format_datetime(date('Y-m-d H:i:s'))) ?></strong></span>
          </p>
          <button type="button" class="btn-outline btn-mini" id="activity-refresh-btn"><i class="fas fa-rotate" aria-hidden="true"></i> Refresh</button>
        </div>

        <div class="adm-stat-grid is-nine">
          <?php
            admin_render_stat('fa-user-plus', 'Registered applicants', (string) (int) ($activityMetrics['registered_applicants'] ?? 0), 'brand', 'New applicant accounts', 'data-activity-metric="registered_applicants"');
            admin_render_stat('fa-file-lines', 'Submitted requests', (string) (int) ($activityMetrics['submitted_requests'] ?? 0), 'brand', 'Applications received', 'data-activity-metric="submitted_requests"');
            admin_render_stat('fa-inbox', 'Pending review', (string) (int) ($activityMetrics['pending_review'] ?? 0), 'watch', 'Waiting for PSWDO', 'data-activity-metric="pending_review"');
            admin_render_stat('fa-clipboard-user', 'Interview pending', (string) (int) ($activityMetrics['interview_pending'] ?? 0), 'violet', 'Scheduled for interview', 'data-activity-metric="interview_pending"');
            admin_render_stat('fa-magnifying-glass', 'Under review', (string) (int) ($activityMetrics['under_review'] ?? 0), 'brand', 'Being checked by PSWDO', 'data-activity-metric="under_review"');
            admin_render_stat('fa-hourglass-half', 'Total pending', (string) (int) ($activityMetrics['pending_total'] ?? 0), 'watch', 'Not decided yet', 'data-activity-metric="pending_total"');
            admin_render_stat('fa-circle-check', 'Approved', (string) (int) ($activityMetrics['approved_total'] ?? 0), 'ok', 'Approved for assistance', 'data-activity-metric="approved_total"');
            admin_render_stat('fa-circle-xmark', 'Not approved', (string) (int) ($activityMetrics['not_approved_total'] ?? 0), 'alert', 'Declined by PSWDO', 'data-activity-metric="not_approved_total"');
            admin_render_stat('fa-hand-holding-dollar', 'Released', (string) (int) ($activityMetrics['released_total'] ?? 0), 'ok', 'Assistance given out', 'data-activity-metric="released_total"');
          ?>
        </div>

        <article class="panel-card overview-graph-card">
          <header class="panel-head">
            <div class="overview-chart-heading">
              <span class="overview-chart-icon"><i class="fas fa-chart-simple" aria-hidden="true"></i></span>
              <div>
                <h2>Requests submitted</h2>
                <p>
                  <strong id="dashboard-period-label"><?= admin_h($periodLabel) ?></strong>
                  <span class="overview-period-range" id="dashboard-period-range"><?= admin_h($periodRangeLabel) ?></span>
                </p>
              </div>
            </div>
            <div class="overview-period-controls" id="activity-period-controls" role="group" aria-label="Period">
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
            <span class="overview-insight-icon"><i class="fas fa-file-circle-check" aria-hidden="true"></i></span>
            <div>
              <strong>In this period</strong>
              <p><span id="dashboard-trend-total"><?= $trendSubmittedTotal ?></span> requests were submitted.</p>
            </div>
            <a href="<?= admin_h($paths['dashboard_url']) ?>?page=ai">Open AICS AI Screening</a>
          </div>
        </article>
      </section>

      <section class="dashboard-tab-panel" data-tab-panel="operations">
        <div class="adm-stat-grid is-nine">
          <?php
            admin_render_stat('fa-users', 'Total accounts', (string) (int) $opsSummary['total_accounts'], 'brand', 'Applicants, PSWDO and admins');
            admin_render_stat('fa-user-check', 'Active accounts', (string) (int) $opsSummary['active_accounts'], 'ok', 'Can sign in');
            admin_render_stat('fa-user-lock', 'Restricted accounts', (string) (int) $opsSummary['restricted_accounts'], $opsSummary['restricted_accounts'] > 0 ? 'watch' : 'neutral', 'Restricted, locked or banned');
            admin_render_stat('fa-folder-open', 'Total requests', (string) (int) $opsSummary['total_requests'], 'brand', 'All time');
            admin_render_stat('fa-inbox', 'Pending requests', (string) (int) $opsSummary['pending_requests'], 'watch', 'Waiting for a decision');
            admin_render_stat('fa-clipboard-user', 'Interview pending', (string) (int) $opsSummary['interview_pending'], 'violet', 'Scheduled for interview');
            admin_render_stat('fa-circle-check', 'Approved requests', (string) (int) $opsSummary['approved_requests'], 'ok', 'Approved, scheduled or released');
            admin_render_stat('fa-right-to-bracket', 'Failed sign-ins', (string) (int) $opsSummary['failed_logins_24h'], $opsSummary['failed_logins_24h'] >= 5 ? 'watch' : 'neutral', 'Last 24 hours');
            admin_render_stat('fa-user-secret', 'Unauthorized attempts', (string) (int) $opsSummary['unauthorized_24h'], $opsSummary['unauthorized_24h'] > 0 ? 'alert' : 'neutral', 'Last 24 hours');
          ?>
        </div>

        <section class="panel-grid two-col">
          <article class="panel-card">
            <header class="panel-head">
              <h2><i class="fas fa-bullseye" aria-hidden="true"></i> Targets</h2>
            </header>
            <div class="criteria-list">
              <div class="criteria-item">
                <span class="adm-row-icon"><i class="fas fa-floppy-disk" aria-hidden="true"></i></span>
                <div>
                  <strong>Backup success rate</strong>
                  <p>Target: at least 99% of backups succeed each month.</p>
                </div>
                <span class="pill <?= $backupRate >= 99 ? 'pill-success' : 'pill-warning' ?>"><?= number_format($backupRate, 1) ?>%</span>
              </div>
              <div class="criteria-item">
                <span class="adm-row-icon"><i class="fas fa-stethoscope" aria-hidden="true"></i></span>
                <div>
                  <strong>Maintenance pass rate</strong>
                  <p>Target: at least 99.5% of routine checks pass.</p>
                </div>
                <span class="pill <?= $maintenanceRate >= 99.5 ? 'pill-success' : 'pill-warning' ?>"><?= $opsSummary['maintenance_month_total'] > 0 ? number_format($maintenanceRate, 1) . '%' : 'No data' ?></span>
              </div>
              <div class="criteria-item">
                <span class="adm-row-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                <div>
                  <strong>Security alerts</strong>
                  <p>Goal: keep failed sign-ins and unauthorized attempts low.</p>
                </div>
                <span class="pill <?= (($opsSummary['failed_logins_24h'] + $opsSummary['unauthorized_24h']) <= 5) ? 'pill-success' : 'pill-danger' ?>">
                  <?= (int) ($opsSummary['failed_logins_24h'] + $opsSummary['unauthorized_24h']) ?> alerts
                </span>
              </div>
            </div>
          </article>

          <article class="panel-card">
            <header class="panel-head">
              <h2><i class="fas fa-shield" aria-hidden="true"></i> Security this month</h2>
            </header>
            <div class="list-stack">
              <div class="list-item">
                <span class="adm-row-icon"><i class="fas fa-database" aria-hidden="true"></i></span>
                <div>
                  <strong>Backups</strong>
                  <p>Successful backups out of all backups run.</p>
                </div>
                <span class="pill pill-info"><?= (int) $opsSummary['backup_month_success'] ?>/<?= (int) $opsSummary['backup_month_total'] ?></span>
              </div>
              <div class="list-item">
                <span class="adm-row-icon"><i class="fas fa-heart-pulse" aria-hidden="true"></i></span>
                <div>
                  <strong>Maintenance checks</strong>
                  <p>Passed checks out of all checks run.</p>
                </div>
                <span class="pill pill-info"><?= (int) $opsSummary['maintenance_month_passed'] ?>/<?= (int) $opsSummary['maintenance_month_total'] ?></span>
              </div>
              <div class="list-item">
                <span class="adm-row-icon"><i class="fas fa-user-secret" aria-hidden="true"></i></span>
                <div>
                  <strong>Unauthorized attempts (24 hours)</strong>
                  <p>Tries to open admin pages without admin rights.</p>
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
            <h2><i class="fas fa-list-check" aria-hidden="true"></i> Administrator routine</h2>
          </header>
          <?php if (count($flowSteps) === 0): ?>
            <?php admin_render_empty('fa-list-check', 'No routine steps yet', 'Steps appear here once the routine is set up.'); ?>
          <?php else: ?>
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Step</th>
                    <th>Action</th>
                    <th>How often</th>
                    <th>Done when</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($flowSteps as $flow): ?>
                    <tr>
                      <td><span class="adm-step"><?= (int) $flow['step'] ?></span></td>
                      <td><strong><?= admin_h((string) $flow['action']) ?></strong></td>
                      <td><span class="pill pill-neutral"><i class="fas fa-repeat" aria-hidden="true"></i> <?= admin_h((string) $flow['frequency']) ?></span></td>
                      <td><?= admin_h((string) $flow['criteria']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </article>

        <article class="panel-card">
          <header class="panel-head">
            <h2><i class="fas fa-route" aria-hidden="true"></i> When something goes wrong</h2>
          </header>
          <?php if (count($exceptionFlows) === 0): ?>
            <?php admin_render_empty('fa-route', 'No problem responses yet', 'Responses for common problems appear here once they are set up.'); ?>
          <?php else: ?>
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Problem</th>
                    <th>Noticed by</th>
                    <th>What to do</th>
                    <th>How often</th>
                    <th>Done when</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($exceptionFlows as $flow): ?>
                    <tr>
                      <td><strong><?= admin_h((string) $flow['exception']) ?></strong></td>
                      <td><?= admin_h((string) $flow['trigger']) ?></td>
                      <td><?= admin_h((string) $flow['response']) ?></td>
                      <td><?= admin_h((string) $flow['frequency']) ?></td>
                      <td><?= admin_h((string) $flow['criteria']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </article>
      </section>
    </div>
  </section>
<?php
admin_render_page_end($paths);
