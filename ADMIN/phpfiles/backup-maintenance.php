<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/admin-layout.php';

admin_start_session();
$paths = admin_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = admin_current_display_name();
$initials = admin_initials($displayName);

/**
 * @param array<string, scalar> $query
 */
function admin_backup_redirect(array $query = []): void
{
    admin_redirect('../dashboard.php', ['page' => 'backup'] + $query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $connection = null;
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        admin_require_valid_csrf();

        $connection = aics_db_connect();
        admin_ensure_schema($connection);

        if ($action === 'run_backup') {
            $backupType = trim((string) ($_POST['backup_type'] ?? 'incremental'));
            $result = admin_run_backup($connection, $accountId, $backupType);
            $connection->close();

            if ($result['ok']) {
                admin_backup_redirect(['status' => 'backup_success', 'run' => (int) ($result['run_id'] ?? 0)]);
            }

            admin_backup_redirect(['error' => 'backup_failed']);
        } elseif ($action === 'run_maintenance') {
            $result = admin_run_maintenance_checks($connection, $accountId);
            $connection->close();

            if ($result['ok']) {
                admin_backup_redirect(['status' => 'maintenance_ok']);
            }

            admin_backup_redirect([
                'status' => 'maintenance_warn',
                'failed' => (int) ($result['failed_checks'] ?? 0),
            ]);
        } else {
            throw new RuntimeException('invalid_action');
        }
    } catch (Throwable $exception) {
        if ($connection instanceof mysqli) {
            try {
                $connection->rollback();
            } catch (Throwable) {
                // ignore rollback errors
            }
            $connection->close();
        }

        admin_backup_redirect(['error' => $exception->getMessage()]);
    }
}

$statusCode = trim((string) ($_GET['status'] ?? ''));
$errorCode = trim((string) ($_GET['error'] ?? ''));
$failedChecks = max(0, (int) ($_GET['failed'] ?? 0));

$statusMessage = null;
if ($statusCode === 'backup_success') {
    $statusMessage = ['type' => 'success', 'text' => 'Backup process completed successfully.'];
} elseif ($statusCode === 'maintenance_ok') {
    $statusMessage = ['type' => 'success', 'text' => 'Routine maintenance checks passed.'];
} elseif ($statusCode === 'maintenance_warn') {
    $statusMessage = ['type' => 'error', 'text' => 'Maintenance checks completed with ' . $failedChecks . ' failed item(s).'];
}

$errorMessages = [
    'backup_failed' => 'Backup process failed. Check recent backup logs and retry.',
    'invalid_action' => 'Unsupported action submitted.',
    'invalid_csrf' => 'Request token expired or invalid. Please retry.',
    'invalid_method' => 'Invalid request method.',
];
$errorMessage = $errorMessages[$errorCode] ?? ($errorCode !== '' ? 'Unable to process the request.' : null);

$summary = [
    'backup_month_total' => 0,
    'backup_month_success' => 0,
    'backup_month_failed' => 0,
    'maintenance_month_total' => 0,
    'maintenance_month_pass' => 0,
];

$backupRuns = [];
$maintenanceLogs = [];
$loadError = '';

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $displayName = admin_fetch_account_display_name($connection, $accountId, $displayName);
    $_SESSION['aics_display_name'] = $displayName;
    $initials = admin_initials($displayName);

    $summarySql = <<<SQL
        SELECT
            COUNT(*) AS backup_month_total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS backup_month_success,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS backup_month_failed
        FROM aics_admin_backup_runs
        WHERE started_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    SQL;
    $backupSummary = $connection->query($summarySql)?->fetch_assoc();
    if (is_array($backupSummary)) {
        $summary['backup_month_total'] = (int) ($backupSummary['backup_month_total'] ?? 0);
        $summary['backup_month_success'] = (int) ($backupSummary['backup_month_success'] ?? 0);
        $summary['backup_month_failed'] = (int) ($backupSummary['backup_month_failed'] ?? 0);
    }

    $maintenanceSummarySql = <<<SQL
        SELECT
            COUNT(*) AS maintenance_month_total,
            SUM(CASE WHEN check_status = 'pass' THEN 1 ELSE 0 END) AS maintenance_month_pass
        FROM aics_admin_maintenance_checks
        WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    SQL;
    $maintenanceSummary = $connection->query($maintenanceSummarySql)?->fetch_assoc();
    if (is_array($maintenanceSummary)) {
        $summary['maintenance_month_total'] = (int) ($maintenanceSummary['maintenance_month_total'] ?? 0);
        $summary['maintenance_month_pass'] = (int) ($maintenanceSummary['maintenance_month_pass'] ?? 0);
    }

    $runsSql = <<<SQL
        SELECT
            b.id,
            b.backup_type,
            b.status,
            b.file_name,
            b.file_size,
            b.checksum_sha256,
            b.error_message,
            b.started_at,
            b.completed_at,
            a.email AS initiated_by_email
        FROM aics_admin_backup_runs b
        LEFT JOIN aics_accounts a ON a.id = b.initiated_by_account_id
        ORDER BY b.id DESC
        LIMIT 100
    SQL;
    $runsResult = $connection->query($runsSql);
    while ($row = $runsResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $backupRuns[] = $row;
    }

    $maintenanceSql = <<<SQL
        SELECT
            m.id,
            m.check_type,
            m.check_status,
            m.details_json,
            m.created_at,
            a.email AS performed_by_email
        FROM aics_admin_maintenance_checks m
        LEFT JOIN aics_accounts a ON a.id = m.performed_by_account_id
        ORDER BY m.id DESC
        LIMIT 100
    SQL;
    $maintenanceResult = $connection->query($maintenanceSql);
    while ($row = $maintenanceResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $maintenanceLogs[] = $row;
    }

    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load backup and maintenance records right now.';
}

$backupRate = $summary['backup_month_total'] > 0
    ? ($summary['backup_month_success'] / $summary['backup_month_total']) * 100
    : 0.0;
$maintenanceRate = $summary['maintenance_month_total'] > 0
    ? ($summary['maintenance_month_pass'] / $summary['maintenance_month_total']) * 100
    : 0.0;

$backupTypeLabels = ['incremental' => 'Daily incremental', 'full' => 'Weekly full', 'archive' => 'Monthly archive'];
$runStatusIcons = ['success' => 'fa-circle-check', 'failed' => 'fa-circle-xmark'];

admin_render_page_start('AICS ADMIN | Backup & Maintenance', 'backup', $displayName, $initials, $paths);
admin_render_page_head(
    'fa-database',
    'Backup & Maintenance',
    'Save a copy of the database, check that the system is healthy, and download past backups.'
);
?>

  <?php if (is_array($statusMessage)): ?>
    <div class="alert <?= admin_h((string) $statusMessage['type'] === 'success' ? 'alert-success' : 'alert-error') ?> flash-alert">
      <i class="fas fa-<?= (string) $statusMessage['type'] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= admin_h((string) $statusMessage['text']) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($errorMessage !== null): ?>
    <div class="alert alert-error flash-alert">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($errorMessage) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($loadError !== ''): ?>
    <div class="alert alert-error">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($loadError) ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-grid two-col">
    <article class="panel-card adm-action-card">
      <span class="adm-action-icon tone-brand"><i class="fas fa-floppy-disk" aria-hidden="true"></i></span>
      <div class="adm-action-body">
        <h2>Back up the database</h2>
        <p>Saves applicants, requests and settings to a file you can download and restore.</p>
        <form action="" method="post" class="adm-action-form">
          <?= admin_csrf_input() ?>
          <input type="hidden" name="action" value="run_backup">
          <label class="adm-field">
            <span>Backup type</span>
            <select name="backup_type" required>
              <option value="incremental">Daily incremental</option>
              <option value="full">Weekly full</option>
              <option value="archive">Monthly archive</option>
            </select>
          </label>
          <button type="submit" class="btn-solid js-confirm" data-confirm="Start database backup now?">
            <i class="fas fa-play" aria-hidden="true"></i>
            Start backup
          </button>
        </form>
      </div>
    </article>

    <article class="panel-card adm-action-card">
      <span class="adm-action-icon tone-ok"><i class="fas fa-stethoscope" aria-hidden="true"></i></span>
      <div class="adm-action-body">
        <h2>Run maintenance checks</h2>
        <p>Checks the database connection, the backup folder and the security log tables.</p>
        <ul class="adm-check-list">
          <li><i class="fas fa-plug" aria-hidden="true"></i> Database connection</li>
          <li><i class="fas fa-folder-open" aria-hidden="true"></i> Backup folder</li>
          <li><i class="fas fa-table-list" aria-hidden="true"></i> Log tables</li>
        </ul>
        <form action="" method="post" class="adm-action-form">
          <?= admin_csrf_input() ?>
          <input type="hidden" name="action" value="run_maintenance">
          <button type="submit" class="btn-outline js-confirm" data-confirm="Run maintenance checks now?">
            <i class="fas fa-screwdriver-wrench" aria-hidden="true"></i>
            Run checks
          </button>
        </form>
      </div>
    </article>
  </section>

  <section class="adm-stat-grid">
    <?php
      admin_render_stat('fa-floppy-disk', 'Backups this month', (string) (int) $summary['backup_month_total'], 'brand', 'All backup runs');
      admin_render_stat('fa-chart-pie', 'Backup success rate', $summary['backup_month_total'] > 0 ? number_format($backupRate, 1) . '%' : 'No data', $summary['backup_month_total'] > 0 ? ($backupRate < 99 ? 'watch' : 'ok') : 'neutral', 'Target: 99% or higher');
      admin_render_stat('fa-circle-xmark', 'Backup failures', (string) (int) $summary['backup_month_failed'], (int) $summary['backup_month_failed'] > 0 ? 'alert' : 'neutral', 'This month');
      admin_render_stat('fa-stethoscope', 'Maintenance checks', (string) (int) $summary['maintenance_month_total'], (int) $summary['maintenance_month_total'] === 0 ? 'watch' : 'brand', 'This month');
      admin_render_stat('fa-heart-pulse', 'Checks passed', $summary['maintenance_month_total'] > 0 ? number_format($maintenanceRate, 1) . '%' : 'No data', $summary['maintenance_month_total'] > 0 && $maintenanceRate < 99.5 ? 'alert' : ($summary['maintenance_month_total'] > 0 ? 'ok' : 'neutral'), 'Target: 99.5% or higher');
    ?>
  </section>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Backup history</h2>
    </header>
    <?php if (count($backupRuns) === 0): ?>
      <?php admin_render_empty('fa-floppy-disk', 'No backups yet', 'Start a backup above. Each one appears here with a download button.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Type</th>
            <th>File</th>
            <th>Size</th>
            <th>Started by</th>
            <th>Started</th>
            <th>Finished</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($backupRuns as $run): ?>
              <?php
                $status = (string) ($run['status'] ?? 'running');
                $statusClass = match ($status) {
                    'success' => 'pill-success',
                    'failed' => 'pill-danger',
                    default => 'pill-warning',
                };
                $fileSize = (int) ($run['file_size'] ?? 0);
                $sizeLabel = $fileSize > 0 ? number_format($fileSize / 1024, 2) . ' KB' : '-';
                $checksum = trim((string) ($run['checksum_sha256'] ?? ''));
                $backupType = (string) ($run['backup_type'] ?? '');
              ?>
              <tr>
                <td><span class="pill <?= admin_h($statusClass) ?>"><i class="fas <?= admin_h($runStatusIcons[$status] ?? 'fa-spinner') ?>" aria-hidden="true"></i> <?= admin_h(ucfirst($status)) ?></span></td>
                <td><?= admin_h($backupTypeLabels[$backupType] ?? ucfirst($backupType)) ?></td>
                <td<?= $checksum !== '' ? ' title="SHA-256 checksum: ' . admin_h($checksum) . '"' : '' ?>>
                  <span class="adm-cell-main adm-file"><i class="fas fa-file-zipper" aria-hidden="true"></i> <?= admin_h((string) ($run['file_name'] ?? '-')) ?></span>
                  <small class="adm-cell-sub">#<?= (int) ($run['id'] ?? 0) ?><?= $checksum !== '' ? ', checksum ' . admin_h(substr($checksum, 0, 12)) : '' ?></small>
                </td>
                <td class="adm-nowrap"><?= admin_h($sizeLabel) ?></td>
                <td><?= admin_h((string) ($run['initiated_by_email'] ?? '-')) ?></td>
                <td class="adm-nowrap"><?= admin_h(admin_format_datetime((string) ($run['started_at'] ?? ''))) ?></td>
                <td class="adm-nowrap"><?= admin_h(admin_format_datetime((string) ($run['completed_at'] ?? ''))) ?></td>
                <td>
                  <?php if ($status === 'success'): ?>
                    <a class="btn-outline btn-mini" href="<?= admin_h($paths['download_backup_url']) ?>?id=<?= (int) ($run['id'] ?? 0) ?>"><i class="fas fa-download" aria-hidden="true"></i> Download</a>
                  <?php else: ?>
                    <span class="small-col"><?= admin_h((string) ($run['error_message'] ?? '-')) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-heart-pulse" aria-hidden="true"></i> Maintenance check log</h2>
    </header>
    <?php if (count($maintenanceLogs) === 0): ?>
      <?php admin_render_empty('fa-stethoscope', 'No maintenance checks yet', 'Run the checks above. Each result appears here.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Result</th>
            <th>Check</th>
            <th>Details</th>
            <th>Run by</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($maintenanceLogs as $check): ?>
              <?php
                $status = (string) ($check['check_status'] ?? 'fail');
                $statusClass = $status === 'pass' ? 'pill-success' : 'pill-danger';
                $details = trim((string) ($check['details_json'] ?? ''));
              ?>
              <tr>
                <td><span class="pill <?= admin_h($statusClass) ?>"><i class="fas <?= $status === 'pass' ? 'fa-circle-check' : 'fa-circle-xmark' ?>" aria-hidden="true"></i> <?= $status === 'pass' ? 'Passed' : 'Failed' ?></span></td>
                <td>
                  <span class="adm-cell-main"><?= admin_h(ucfirst(str_replace('_', ' ', (string) ($check['check_type'] ?? '')))) ?></span>
                  <small class="adm-cell-sub">#<?= (int) ($check['id'] ?? 0) ?></small>
                </td>
                <td class="small-col"<?= $details !== '' ? ' title="' . admin_h(substr($details, 0, 600)) . '"' : '' ?>><?= admin_h($details !== '' ? substr($details, 0, 140) : '-') ?></td>
                <td><?= admin_h((string) ($check['performed_by_email'] ?? '-')) ?></td>
                <td class="adm-nowrap"><?= admin_h(admin_format_datetime((string) ($check['created_at'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
<?php
admin_render_page_end($paths);
