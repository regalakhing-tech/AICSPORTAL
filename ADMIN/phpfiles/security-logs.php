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

/** Login result reasons in plain words. */
function security_reason_label(string $reason): string
{
    $reason = strtolower(trim($reason));
    if (str_starts_with($reason, 'status_')) return 'Account ' . str_replace('_', ' ', substr($reason, 7));
    return match ($reason) {
        'success' => 'Signed in',
        'invalid_credentials' => 'Wrong password or account',
        'locked' => 'Locked out after too many tries',
        'inactive_account' => 'Account not active',
        '' => '-',
        default => ucfirst(str_replace('_', ' ', $reason)),
    };
}

/** "Chrome on Windows" from a browser user-agent string, and whether it is a phone. */
function security_device(string $userAgent): array
{
    $browser = match (true) {
        str_contains($userAgent, 'Edg/') => 'Edge',
        str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
        str_contains($userAgent, 'Firefox/') => 'Firefox',
        str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($userAgent, 'Chrome/') => 'Chrome',
        str_contains($userAgent, 'Safari/') => 'Safari',
        default => '',
    };
    $system = match (true) {
        str_contains($userAgent, 'Android') => 'Android',
        str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
        str_contains($userAgent, 'Windows') => 'Windows',
        str_contains($userAgent, 'Mac OS') => 'macOS',
        str_contains($userAgent, 'Linux') => 'Linux',
        default => '',
    };
    $label = trim($browser . ($browser !== '' && $system !== '' ? ' on ' : '') . $system);
    return ['label' => $label !== '' ? $label : 'Unknown device', 'mobile' => in_array($system, ['Android', 'iOS'], true)];
}

$portalIcons = ['admin' => 'fa-user-shield', 'pswdo' => 'fa-user-tie', 'applicant' => 'fa-user'];
$outcomeIcons = ['success' => 'fa-circle-check', 'failure' => 'fa-circle-xmark', 'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'];

admin_render_page_start('AICS ADMIN | Security Logs', 'logs', $displayName, $initials, $paths);
admin_render_page_head(
    'fa-shield-halved',
    'Security Logs',
    'Sign-ins from every portal and every change made in the admin area. This page refreshes every 10 seconds.'
);
?>

  <?php if ($loadError !== ''): ?>
    <div class="alert alert-error">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($loadError) ?></span>
    </div>
  <?php endif; ?>

  <section class="adm-stat-grid">
    <?php
      admin_render_stat('fa-right-to-bracket', 'Successful sign-ins', (string) (int) $summary['success_24h'], 'ok', 'Last 24 hours');
      admin_render_stat('fa-circle-xmark', 'Failed sign-ins', (string) (int) $summary['failed_24h'], (int) $summary['failed_24h'] >= 5 ? 'watch' : 'neutral', 'Last 24 hours');
      admin_render_stat('fa-user-lock', 'Lockouts', (string) (int) $summary['lockouts_24h'], (int) $summary['lockouts_24h'] > 0 ? 'watch' : 'neutral', 'Last 24 hours');
      admin_render_stat('fa-user-secret', 'Unauthorized access', (string) (int) $summary['unauthorized_24h'], (int) $summary['unauthorized_24h'] > 0 ? 'alert' : 'neutral', 'Last 24 hours');
      admin_render_stat('fa-database', 'Backup failures', (string) (int) $summary['backup_failures_month'], (int) $summary['backup_failures_month'] > 0 ? 'alert' : 'neutral', 'This month');
    ?>
  </section>

  <?php if ($clearLockoutStatus !== ''): ?>
    <div class="alert <?= $clearLockoutStatus === 'success' ? 'alert-success' : 'alert-error' ?>">
      <i class="fas fa-<?= $clearLockoutStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= admin_h($clearLockoutMessage) ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-right-to-bracket" aria-hidden="true"></i> Sign-in attempts</h2>
      <span class="ai-screen-note"><i class="fas fa-globe" aria-hidden="true"></i> All portals</span>
    </header>
    <?php if (count($loginAttempts) === 0): ?>
      <?php admin_render_empty('fa-right-to-bracket', 'No sign-in attempts yet', 'Every sign-in to the applicant, PSWDO and admin portals is listed here.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Result</th>
            <th>Account</th>
            <th>Portal</th>
            <th>Reason</th>
            <th>Device</th>
            <th>IP address</th>
            <th>Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($loginAttempts as $attempt): ?>
              <?php
                $isSuccess = (int) ($attempt['is_success'] ?? 0) === 1;
                $reason = (string) ($attempt['attempt_reason'] ?? '');
                $isLocked = !$isSuccess && in_array($reason, ['locked', 'invalid_credentials', 'inactive_account'], true);
                $attemptEmail = (string) ($attempt['email'] ?? '');
                $attemptIp = (string) ($attempt['ip_address'] ?? '');
                $portal = strtolower(trim((string) ($attempt['login_portal'] ?? '')));
                $device = security_device((string) ($attempt['user_agent'] ?? ''));
              ?>
              <tr>
                <td><span class="pill <?= $isSuccess ? 'pill-success' : 'pill-danger' ?>"><i class="fas <?= $isSuccess ? 'fa-circle-check' : 'fa-circle-xmark' ?>" aria-hidden="true"></i> <?= $isSuccess ? 'Success' : 'Failed' ?></span></td>
                <td>
                  <span class="adm-cell-main"><?= admin_h($attemptEmail !== '' ? $attemptEmail : '-') ?></span>
                  <small class="adm-cell-sub">#<?= (int) ($attempt['id'] ?? 0) ?></small>
                </td>
                <td><span class="pill pill-neutral"><i class="fas <?= admin_h($portalIcons[$portal] ?? 'fa-door-open') ?>" aria-hidden="true"></i> <?= admin_h($portal !== '' ? ucfirst($portal) : '-') ?></span></td>
                <td><?= admin_h(security_reason_label($reason)) ?></td>
                <td title="<?= admin_h((string) ($attempt['user_agent'] ?? '')) ?>"><span class="adm-contact"><i class="fas <?= $device['mobile'] ? 'fa-mobile-screen' : 'fa-desktop' ?>" aria-hidden="true"></i> <?= admin_h($device['label']) ?></span></td>
                <td class="adm-nowrap"><?= admin_h($attemptIp) ?></td>
                <td class="adm-nowrap"><?= admin_h(admin_format_datetime((string) ($attempt['created_at'] ?? ''))) ?></td>
                <td>
                  <?php if ($isLocked && ($attemptEmail !== '' || $attemptIp !== '')): ?>
                    <form method="post" action="" class="inline-form" onsubmit="return confirm('Clear lockout for <?= admin_h($attemptEmail !== '' ? $attemptEmail : $attemptIp) ?>?');">
                      <?= admin_csrf_input() ?>
                      <input type="hidden" name="action" value="clear_lockout">
                      <input type="hidden" name="target_email" value="<?= admin_h($attemptEmail) ?>">
                      <input type="hidden" name="target_ip" value="<?= admin_h($attemptIp) ?>">
                      <button type="submit" class="btn-outline btn-mini adm-btn-warn">
                        <i class="fas fa-unlock" aria-hidden="true"></i> Clear lockout
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="adm-muted" aria-label="No action">&mdash;</span>
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
      <h2><i class="fas fa-file-shield" aria-hidden="true"></i> Admin activity</h2>
      <span class="ai-screen-note"><i class="fas fa-fingerprint" aria-hidden="true"></i> Who changed what, and when</span>
    </header>
    <?php if (count($auditLogs) === 0): ?>
      <?php admin_render_empty('fa-file-shield', 'No admin activity recorded yet', 'Account changes, backups and blocked access attempts are listed here.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Outcome</th>
            <th>Event</th>
            <th>Done by</th>
            <th>Account affected</th>
            <th>Details</th>
            <th>IP address</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
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
              ?>
              <tr>
                <td><span class="pill <?= admin_h($pillClass) ?>"><i class="fas <?= admin_h($outcomeIcons[$outcome] ?? 'fa-circle-info') ?>" aria-hidden="true"></i> <?= admin_h(ucfirst($outcome)) ?></span></td>
                <td>
                  <span class="adm-cell-main"><?= admin_h(ucfirst(str_replace('_', ' ', (string) ($log['event_type'] ?? '')))) ?></span>
                  <small class="adm-cell-sub">#<?= (int) ($log['id'] ?? 0) ?></small>
                </td>
                <td><?= admin_h((string) ($log['actor_email'] ?? '-')) ?></td>
                <td><?= admin_h((string) ($log['target_email'] ?? '-')) ?></td>
                <td<?= $contextRaw !== '' ? ' title="' . admin_h(substr($contextRaw, 0, 600)) . '"' : '' ?>><?= admin_h((string) ($log['event_message'] ?? '')) ?></td>
                <td class="adm-nowrap"><?= admin_h((string) ($log['ip_address'] ?? '')) ?></td>
                <td class="adm-nowrap"><?= admin_h(admin_format_datetime((string) ($log['created_at'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
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
