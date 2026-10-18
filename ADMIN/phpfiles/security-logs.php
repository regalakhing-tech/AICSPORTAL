<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/admin-layout.php';

admin_start_session();
$paths = admin_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = admin_current_display_name();
$initials = admin_initials($displayName);

// Handle "Clear Lockout" POST action
$clearLockoutStatus = '';
$clearLockoutMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'clear_lockout') {
    try {
        admin_require_valid_csrf();
        $targetEmail = trim((string) ($_POST['target_email'] ?? ''));
        $targetIp = trim((string) ($_POST['target_ip'] ?? ''));
        if ($targetEmail === '' && $targetIp === '') {
            throw new RuntimeException('No target email or IP provided.');
        }

        $connection = aics_db_connect();
        admin_ensure_schema($connection);

        // Delete failed login attempts within the lockout window for the given email/IP.
        $threshold = date('Y-m-d H:i:s', time() - ADMIN_LOGIN_WINDOW_SECONDS);
        $deleteStmt = $connection->prepare(
            'DELETE FROM aics_admin_login_attempts WHERE is_success = 0 AND created_at >= ? AND (email = ? OR ip_address = ?)'
        );
        $deleteStmt->bind_param('sss', $threshold, $targetEmail, $targetIp);
        $deleteStmt->execute();
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();

        admin_log_event(
            $connection,
            'lockout_cleared',
            'success',
            'Administrator cleared login lockout for ' . ($targetEmail !== '' ? $targetEmail : $targetIp) . '.',
            $accountId,
            null,
            ['target_email' => $targetEmail, 'target_ip' => $targetIp, 'attempts_cleared' => $affected]
        );

        $connection->close();

        $clearLockoutStatus = 'success';
        $clearLockoutMessage = 'Lockout cleared for ' . ($targetEmail !== '' ? $targetEmail : $targetIp) . ' (' . $affected . ' failed attempt' . ($affected === 1 ? '' : 's') . ' removed).';
    } catch (Throwable $exception) {
        $clearLockoutStatus = 'error';
        $clearLockoutMessage = 'Could not clear lockout. ' . $exception->getMessage();
    }
}

$summary = [
    'failed_24h' => 0,
    'success_24h' => 0,
    'lockouts_24h' => 0,
    'unauthorized_24h' => 0,
    'backup_failures_month' => 0,
];

$loginAttempts = [];
$auditLogs = [];
$loadError = '';

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $displayName = admin_fetch_account_display_name($connection, $accountId, $displayName);
    $_SESSION['aics_display_name'] = $displayName;
    $initials = admin_initials($displayName);

    $summarySql = <<<SQL
        SELECT
            SUM(CASE WHEN is_success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS failed_24h,
            SUM(CASE WHEN is_success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS success_24h,
            SUM(CASE WHEN attempt_reason = 'locked' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS lockouts_24h
        FROM aics_admin_login_attempts
    SQL;
    $summaryRow = $connection->query($summarySql)?->fetch_assoc();
    if (is_array($summaryRow)) {
        $summary['failed_24h'] = (int) ($summaryRow['failed_24h'] ?? 0);
        $summary['success_24h'] = (int) ($summaryRow['success_24h'] ?? 0);
        $summary['lockouts_24h'] = (int) ($summaryRow['lockouts_24h'] ?? 0);
    }

    $unauthorizedSql = <<<SQL
        SELECT COUNT(*) AS unauthorized_24h
        FROM aics_admin_audit_logs
        WHERE event_type = 'unauthorized_access'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    SQL;
    $unauthorizedRow = $connection->query($unauthorizedSql)?->fetch_assoc();
    if (is_array($unauthorizedRow)) {
        $summary['unauthorized_24h'] = (int) ($unauthorizedRow['unauthorized_24h'] ?? 0);
    }

    $backupFailSql = <<<SQL
        SELECT COUNT(*) AS backup_failures_month
        FROM aics_admin_backup_runs
        WHERE status = 'failed'
          AND started_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    SQL;
    $backupFailRow = $connection->query($backupFailSql)?->fetch_assoc();
    if (is_array($backupFailRow)) {
        $summary['backup_failures_month'] = (int) ($backupFailRow['backup_failures_month'] ?? 0);
    }

    $attemptsSql = <<<SQL
        SELECT id, email, ip_address, user_agent, is_success, attempt_reason, role_code, login_portal, created_at
        FROM aics_admin_login_attempts
        ORDER BY id DESC
        LIMIT 200
    SQL;
    $attemptsResult = $connection->query($attemptsSql);
    while ($row = $attemptsResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $loginAttempts[] = $row;
    }

    $auditSql = <<<SQL
        SELECT
            l.id,
            l.actor_account_id,
            l.target_account_id,
            l.event_type,
            l.outcome,
            l.event_message,
            l.ip_address,
            l.user_agent,
            l.context_json,
            l.created_at,
            actor.email AS actor_email,
            target.email AS target_email
        FROM aics_admin_audit_logs l
        LEFT JOIN aics_accounts actor ON actor.id = l.actor_account_id
        LEFT JOIN aics_accounts target ON target.id = l.target_account_id
        ORDER BY l.id DESC
        LIMIT 200
    SQL;
    $auditResult = $connection->query($auditSql);
    while ($row = $auditResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $auditLogs[] = $row;
    }

    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load full security logs right now.';
}

admin_render_page_start('AICS ADMIN | Security Logs', 'logs', $displayName, $initials, $paths);
?>
  <section class="hero-card compact">
    <div class="hero-copy">
      <p class="eyebrow"><i class="fas fa-shield-halved"></i> Accountability</p>
      <h1>Security & Audit Logs</h1>
      <p>Track login attempts, unauthorized access, account changes, and backup events with complete security context.</p>
    </div>
  </section>

  <?php if ($loadError !== ''): ?>
    <div class="alert alert-error">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($loadError) ?></span>
    </div>
  <?php endif; ?>

  <section class="metric-grid">
    <article class="metric-card"><span>Successful Logins (24h)</span><strong><?= (int) $summary['success_24h'] ?></strong></article>
    <article class="metric-card"><span>Failed Logins (24h)</span><strong><?= (int) $summary['failed_24h'] ?></strong></article>
    <article class="metric-card"><span>Lockouts (24h)</span><strong><?= (int) $summary['lockouts_24h'] ?></strong></article>
    <article class="metric-card"><span>Unauthorized Access (24h)</span><strong><?= (int) $summary['unauthorized_24h'] ?></strong></article>
    <article class="metric-card"><span>Backup Failures (Month)</span><strong><?= (int) $summary['backup_failures_month'] ?></strong></article>
  </section>

  <?php if ($clearLockoutStatus !== ''): ?>
    <div class="alert <?= $clearLockoutStatus === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:18px;">
      <i class="fas fa-<?= $clearLockoutStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= admin_h($clearLockoutMessage) ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-right-to-bracket"></i> Login Attempts (All Portals)</h2>
    </header>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Email</th>
            <th>Role</th>
            <th>Portal</th>
            <th>IP Address</th>
            <th>Result</th>
            <th>Reason</th>
            <th>User Agent</th>
            <th>Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($loginAttempts) === 0): ?>
            <tr><td colspan="10">No login attempts found.</td></tr>
          <?php else: ?>
            <?php foreach ($loginAttempts as $attempt): ?>
              <?php
                $isSuccess = (int) ($attempt['is_success'] ?? 0) === 1;
                $reason = (string) ($attempt['attempt_reason'] ?? '');
                $isLocked = !$isSuccess && in_array($reason, ['locked', 'invalid_credentials', 'inactive_account'], true);
                $attemptEmail = (string) ($attempt['email'] ?? '');
                $attemptIp = (string) ($attempt['ip_address'] ?? '');
              ?>
              <tr>
                <td>#<?= (int) ($attempt['id'] ?? 0) ?></td>
                <td><?= admin_h($attemptEmail) ?></td>
                <td><?= admin_h((string) ($attempt['role_code'] ?? '-')) ?></td>
                <td><?= admin_h((string) ($attempt['login_portal'] ?? '-')) ?></td>
                <td><?= admin_h($attemptIp) ?></td>
                <td><span class="pill <?= $isSuccess ? 'pill-success' : 'pill-danger' ?>"><?= $isSuccess ? 'Success' : 'Failed' ?></span></td>
                <td><?= admin_h($reason) ?></td>
                <td class="small-col"><?= admin_h((string) ($attempt['user_agent'] ?? '')) ?></td>
                <td><?= admin_h(admin_format_datetime((string) ($attempt['created_at'] ?? ''))) ?></td>
                <td>
                  <?php if ($isLocked && ($attemptEmail !== '' || $attemptIp !== '')): ?>
                    <form method="post" action="" style="display:inline;" onsubmit="return confirm('Clear lockout for <?= admin_h($attemptEmail !== '' ? $attemptEmail : $attemptIp) ?>?');">
                      <?= admin_csrf_input() ?>
                      <input type="hidden" name="action" value="clear_lockout">
                      <input type="hidden" name="target_email" value="<?= admin_h($attemptEmail) ?>">
                      <input type="hidden" name="target_ip" value="<?= admin_h($attemptIp) ?>">
                      <button type="submit" class="btn-tag" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:11px;padding:4px 10px;border-radius:6px;cursor:pointer;font-weight:600;">
                        <i class="fas fa-unlock"></i> Clear Lockout
                      </button>
                    </form>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-size:11px;">&mdash;</span>
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
      <h2><i class="fas fa-file-shield"></i> Admin Audit Trail</h2>
    </header>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Event Type</th>
            <th>Outcome</th>
            <th>Actor</th>
            <th>Target</th>
            <th>Message</th>
            <th>IP</th>
            <th>Context</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($auditLogs) === 0): ?>
            <tr><td colspan="9">No audit logs recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($auditLogs as $log): ?>
              <?php
                $outcome = (string) ($log['outcome'] ?? 'info');
                $pillClass = match ($outcome) {
                    'success' => 'pill-success',
                    'failure' => 'pill-danger',
                    'warning' => 'pill-warning',
                    default => 'pill-info',
                };
                $contextRaw = trim((string) ($log['context_json'] ?? ''));
                $contextShort = $contextRaw !== '' ? substr($contextRaw, 0, 120) : '-';
              ?>
              <tr>
                <td>#<?= (int) ($log['id'] ?? 0) ?></td>
                <td><?= admin_h((string) ($log['event_type'] ?? '')) ?></td>
                <td><span class="pill <?= admin_h($pillClass) ?>"><?= admin_h(strtoupper($outcome)) ?></span></td>
                <td><?= admin_h((string) ($log['actor_email'] ?? '-')) ?></td>
                <td><?= admin_h((string) ($log['target_email'] ?? '-')) ?></td>
                <td><?= admin_h((string) ($log['event_message'] ?? '')) ?></td>
                <td><?= admin_h((string) ($log['ip_address'] ?? '')) ?></td>
                <td class="small-col"><?= admin_h($contextShort) ?></td>
                <td><?= admin_h(admin_format_datetime((string) ($log['created_at'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <script>
    (() => {
      const refreshMs = 10000;
      window.setInterval(() => {
        if (document.hidden) return;
        window.location.reload();
      }, refreshMs);
    })();
  </script>
<?php
admin_render_page_end($paths);
