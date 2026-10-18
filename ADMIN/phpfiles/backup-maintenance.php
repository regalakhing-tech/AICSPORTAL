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

admin_render_page_start('AICS ADMIN | Backup & Maintenance', 'backup', $displayName, $initials, $paths);
?>
  <section class="hero-card compact">
    <div class="hero-copy">
      <p class="eyebrow"><i class="fas fa-database"></i> Resilience Controls</p>
      <h1>Backup & Maintenance</h1>
      <p>Run secure backup jobs, validate system health, and monitor backup integrity from a single maintenance console.</p>
    </div>
  </section>

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
    <article class="panel-card">
      <header class="panel-head">
        <h2><i class="fas fa-floppy-disk"></i> Run Backup</h2>
      </header>
      <form action="" method="post" class="form-grid">
        <?= admin_csrf_input() ?>
        <input type="hidden" name="action" value="run_backup">
        <label>Backup Type
          <select name="backup_type" required>
            <option value="incremental">Daily Incremental</option>
            <option value="full">Weekly Full</option>
            <option value="archive">Monthly Archive</option>
          </select>
        </label>
        <button type="submit" class="btn-solid js-confirm" data-confirm="Start database backup now?">
          <i class="fas fa-play"></i>
          Start Backup
        </button>
      </form>
    </article>

    <article class="panel-card">
      <header class="panel-head">
        <h2><i class="fas fa-stethoscope"></i> Run Maintenance Checks</h2>
      </header>
      <form action="" method="post" class="form-grid">
        <?= admin_csrf_input() ?>
        <input type="hidden" name="action" value="run_maintenance">
        <p class="empty-note">Runs database connectivity, backup directory, and logging schema checks.</p>
        <button type="submit" class="btn-outline js-confirm" data-confirm="Run maintenance checks now?">
          <i class="fas fa-screwdriver-wrench"></i>
          Run Checks
        </button>
      </form>
    </article>
  </section>

  <section class="metric-grid">
    <article class="metric-card"><span>Monthly Backup Runs</span><strong><?= (int) $summary['backup_month_total'] ?></strong></article>
    <article class="metric-card"><span>Backup Success Rate</span><strong><?= number_format($backupRate, 1) ?>%</strong></article>
    <article class="metric-card"><span>Backup Failures</span><strong><?= (int) $summary['backup_month_failed'] ?></strong></article>
    <article class="metric-card"><span>Maintenance Checks</span><strong><?= (int) $summary['maintenance_month_total'] ?></strong></article>
    <article class="metric-card"><span>Maintenance Pass Rate</span><strong><?= $summary['maintenance_month_total'] > 0 ? number_format($maintenanceRate, 1) . '%' : 'No data' ?></strong></article>
  </section>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-clock-rotate-left"></i> Backup History</h2>
    </header>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Run ID</th>
            <th>Type</th>
            <th>Status</th>
            <th>File</th>
            <th>Size</th>
            <th>Checksum</th>
            <th>Initiated By</th>
            <th>Started</th>
            <th>Completed</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($backupRuns) === 0): ?>
            <tr><td colspan="10">No backup runs yet.</td></tr>
          <?php else: ?>
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
              ?>
              <tr>
                <td>#<?= (int) ($run['id'] ?? 0) ?></td>
                <td><?= admin_h(strtoupper((string) ($run['backup_type'] ?? ''))) ?></td>
                <td><span class="pill <?= admin_h($statusClass) ?>"><?= admin_h(strtoupper($status)) ?></span></td>
                <td><?= admin_h((string) ($run['file_name'] ?? '-')) ?></td>
                <td><?= admin_h($sizeLabel) ?></td>
                <td class="small-col"><?= admin_h($checksum !== '' ? substr($checksum, 0, 22) . '...' : '-') ?></td>
                <td><?= admin_h((string) ($run['initiated_by_email'] ?? '-')) ?></td>
                <td><?= admin_h(admin_format_datetime((string) ($run['started_at'] ?? ''))) ?></td>
                <td><?= admin_h(admin_format_datetime((string) ($run['completed_at'] ?? ''))) ?></td>
                <td>
                  <?php if ($status === 'success'): ?>
                    <a class="btn-outline btn-mini" href="<?= admin_h($paths['download_backup_url']) ?>?id=<?= (int) ($run['id'] ?? 0) ?>">Download</a>
                  <?php else: ?>
                    <span class="small-col"><?= admin_h((string) ($run['error_message'] ?? '-')) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-heart-pulse"></i> Maintenance Check Logs</h2>
    </header>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Check Type</th>
            <th>Status</th>
            <th>Details</th>
            <th>Performed By</th>
            <th>Timestamp</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($maintenanceLogs) === 0): ?>
            <tr><td colspan="6">No maintenance checks recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($maintenanceLogs as $check): ?>
              <?php
                $status = (string) ($check['check_status'] ?? 'fail');
                $statusClass = $status === 'pass' ? 'pill-success' : 'pill-danger';
                $details = trim((string) ($check['details_json'] ?? ''));
              ?>
              <tr>
                <td>#<?= (int) ($check['id'] ?? 0) ?></td>
                <td><?= admin_h((string) ($check['check_type'] ?? '')) ?></td>
                <td><span class="pill <?= admin_h($statusClass) ?>"><?= admin_h(strtoupper($status)) ?></span></td>
                <td class="small-col"><?= admin_h($details !== '' ? substr($details, 0, 140) : '-') ?></td>
                <td><?= admin_h((string) ($check['performed_by_email'] ?? '-')) ?></td>
                <td><?= admin_h(admin_format_datetime((string) ($check['created_at'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php
admin_render_page_end($paths);
