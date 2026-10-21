<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/../../includes/ai-submission-gate.php';

admin_start_session();
$paths = admin_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = admin_current_display_name();
$initials = admin_initials($displayName);

// Actions: Release to PSWDO, Ask applicant to re-upload, Retry AICS AI check, Retry all.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notice = 'nothing';
    $count = 0;
    try {
        admin_require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $connection = aics_db_connect();
        admin_ensure_schema($connection);
        if ($action === 'release' && $requestId > 0 && aics_ai_gate_admin_release($connection, $requestId, $accountId)) {
            $notice = 'released';
            admin_log_event($connection, 'ai_gate_admin_release', 'success', 'Administrator sent request #' . $requestId . ' to PSWDO without an AICS AI pass.', $accountId, null, ['request_id' => $requestId]);
        } elseif ($action === 'reupload' && $requestId > 0 && aics_ai_gate_admin_request_reupload($connection, $requestId, $accountId)) {
            $notice = 'reupload';
            admin_log_event($connection, 'ai_gate_admin_reupload_request', 'success', 'Administrator asked the applicant of request #' . $requestId . ' for a clearer copy.', $accountId, null, ['request_id' => $requestId]);
        } elseif ($action === 'retry' && $requestId > 0) {
            $count = aics_ai_gate_admin_retry($connection, $requestId, $accountId);
            if ($count > 0) {
                $notice = 'retried';
                admin_log_event($connection, 'ai_gate_admin_retry', 'success', 'Administrator asked AICS AI to check request #' . $requestId . ' again.', $accountId, null, ['request_id' => $requestId]);
            }
        } elseif ($action === 'retry_all') {
            $count = aics_ai_gate_admin_retry($connection, null, $accountId);
            if ($count > 0) {
                $notice = 'retried';
                admin_log_event($connection, 'ai_gate_admin_retry', 'success', 'Administrator asked AICS AI to check ' . $count . ' request(s) again.', $accountId, null, ['requests' => $count]);
            }
        }
        $connection->close();
    } catch (Throwable $exception) {
        error_log('AICS AI Screening action failed: ' . $exception->getMessage());
        $notice = 'failed';
    }
    header('Location: ai-screening.php?notice=' . urlencode($notice) . '&count=' . $count, true, 303);
    exit;
}

$noticeMessages = [
    'released' => ['success', 'The request was sent to PSWDO. The applicant has been told the expected processing time.'],
    'reupload' => ['success', 'The applicant was asked to upload a clearer copy.'],
    'retried' => ['success', 'AICS AI will check %d request(s) again.'],
    'nothing' => ['error', 'Nothing was changed. The request may have moved on already, or it had nothing to check again.'],
    'failed' => ['error', 'The action could not be completed. Please refresh and try again.'],
];
$noticeKey = (string) ($_GET['notice'] ?? '');
$notice = $noticeMessages[$noticeKey] ?? null;
if ($notice !== null) $notice[1] = sprintf($notice[1], max(1, (int) ($_GET['count'] ?? 0)));

$stateLabels = ['pending' => 'Checking', 'held' => 'Waiting for applicant', 'admin_review' => 'Needs your decision', 'released' => 'With PSWDO'];
$reasonLabels = ['uncertain' => 'AICS AI could not confirm a document', 'ai_unavailable' => 'AICS AI could not check a document'];
$eventLabels = [
    'ai_hold' => 'Sent back to applicant', 'ai_release' => 'Sent to PSWDO', 'ai_retry' => 'Checked again automatically',
    'ai_admin_review' => 'Sent to admin', 'applicant_reupload' => 'Applicant uploaded', 'applicant_edit' => 'Applicant edited',
    'admin_release' => 'Admin sent to PSWDO', 'admin_reupload_request' => 'Admin asked for re-upload', 'admin_retry' => 'Admin asked for a new check',
    'ai_priority_urgent' => 'Marked urgent', 'ai_priority_update' => 'Priority updated',
];
$statusLabels = [
    'needs_reupload' => 'Blurry or incomplete', 'flagged_inconsistency' => 'Mismatch or edited', 'needs_staff_review' => 'Not confirmed',
    'failed' => 'Check failed', 'likely_valid' => 'Valid', 'completed' => 'Valid', 'processing' => 'Checking',
    'URGENT' => 'Urgent', 'HIGH' => 'High priority', 'STANDARD' => 'Standard priority',
];

$loadError = '';
$alertMinutes = aics_ai_gate_alert_minutes();
$reviewRequests = [];
$waitingRequests = [];
$log = [];
try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);
    $displayName = admin_fetch_account_display_name($connection, $accountId, $displayName);
    $_SESSION['aics_display_name'] = $displayName;
    $initials = admin_initials($displayName);

    aics_ai_gate_sync($connection);
    aics_ai_gate_purge_events($connection);
    foreach (aics_ai_gate_requests_in_state($connection, 'admin_review') as $row) {
        $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, (int) $row['id']));
        $row['documents'] = array_map(static fn (array $doc): array => [
            'label' => aics_document_requirement_label((string) $doc['document_type']),
            'status' => (string) $doc['status'],
            'detail' => aics_ai_gate_document_detail($doc),
        ], array_merge($decision['uncertain'], $decision['blocking'], $decision['retry']));
        $reviewRequests[] = $row;
    }
    $waitingRequests = aics_ai_gate_requests_in_state($connection, 'pending');
    $log = aics_ai_gate_recent_events($connection);
    $connection->close();
} catch (Throwable $exception) {
    $loadError = 'AICS AI Screening could not be loaded. ' . $exception->getMessage();
}

$health = ['status' => 'unavailable', 'components' => []];
try {
    $health = aics_ai_environment_health(true);
} catch (Throwable) {
    $health = ['status' => 'unavailable', 'components' => []];
}
$keyConfigured = aics_ai_gate_ai_key_configured();
$unavailableParts = array_keys(array_filter((array) $health['components'], static fn ($state): bool => $state === 'unavailable'));
$serviceProblem = $health['status'] === 'unavailable' || !$keyConfigured;
$stuckCount = count(array_filter($waitingRequests, static fn (array $row): bool => (int) $row['minutes_waiting'] >= $alertMinutes));
$unavailableCount = count(array_filter($reviewRequests, static fn (array $row): bool => ($row['ai_gate_reason'] ?? '') === 'ai_unavailable'));

function ai_screening_wait(int $minutes): string
{
    if ($minutes < 60) return $minutes . ' min';
    if ($minutes < 1440) return intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    return intdiv($minutes, 1440) . ' day(s)';
}

admin_render_page_start('AICS ADMIN | AICS AI Screening', 'ai', $displayName, $initials, $paths);
admin_render_page_head(
    'fa-robot',
    'AICS AI Screening',
    'PSWDO only receives requests whose documents passed the AICS AI check. Requests the AI could not confirm or could not check wait here for you.'
);
?>

  <?php if ($notice !== null): ?>
    <div class="alert <?= $notice[0] === 'success' ? 'alert-success' : 'alert-error' ?> ai-screen-alert">
      <i class="fas fa-<?= $notice[0] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= admin_h($notice[1]) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($loadError !== ''): ?>
    <div class="alert alert-error ai-screen-alert"><i class="fas fa-triangle-exclamation"></i><span><?= admin_h($loadError) ?></span></div>
  <?php endif; ?>

  <?php if ($serviceProblem): ?>
    <div class="alert alert-error ai-screen-alert">
      <i class="fas fa-plug-circle-xmark"></i>
      <span>
        AICS AI service problem:
        <?= !$keyConfigured ? 'the AICS AI key is not configured. ' : '' ?>
        <?= $unavailableParts !== [] ? 'unavailable parts: ' . admin_h(implode(', ', $unavailableParts)) . '. ' : '' ?>
        New requests wait instead of going to PSWDO. After it is fixed, use Retry all below.
      </span>
    </div>
  <?php endif; ?>
  <?php if ($stuckCount > 0): ?>
    <div class="alert alert-warning ai-screen-alert">
      <i class="fas fa-hourglass-half"></i>
      <span><?= $stuckCount ?> request(s) have been waiting for AICS AI longer than <?= $alertMinutes ?> minutes. They are retried automatically.</span>
    </div>
  <?php endif; ?>

  <section class="adm-stat-grid">
    <?php
      admin_render_stat('fa-user-check', 'Needs your decision', (string) count($reviewRequests), count($reviewRequests) > 0 ? 'watch' : 'ok', count($reviewRequests) > 0 ? 'Release or ask for a re-upload' : 'Nothing waiting');
      admin_render_stat('fa-hourglass-half', 'Being checked', (string) count($waitingRequests), 'brand', 'AICS AI is reading the documents');
      admin_render_stat('fa-clock', 'Waiting over ' . $alertMinutes . ' min', (string) $stuckCount, $stuckCount > 0 ? 'alert' : 'neutral', 'Retried automatically');
      admin_render_stat($serviceProblem ? 'fa-plug-circle-xmark' : 'fa-plug-circle-check', 'AICS AI service', $serviceProblem ? 'Problem' : 'Working', $serviceProblem ? 'alert' : 'ok', $serviceProblem ? 'New requests are waiting' : 'Checking documents normally');
    ?>
  </section>

  <section class="panel-card">
    <header class="panel-head ai-screen-panel-head">
      <h2><i class="fas fa-user-check" aria-hidden="true"></i> Needs your decision</h2>
      <?php if ($unavailableCount > 0): ?>
        <form method="post" class="ai-screen-inline-form">
          <?= admin_csrf_input() ?>
          <input type="hidden" name="action" value="retry_all">
          <button type="submit" class="btn-outline btn-mini"><i class="fas fa-rotate" aria-hidden="true"></i> Retry all (<?= $unavailableCount ?>)</button>
        </form>
      <?php endif; ?>
    </header>
    <?php if ($reviewRequests === []): ?>
      <?php admin_render_empty('fa-circle-check', 'Nothing needs your decision right now', 'Requests appear here when AICS AI cannot confirm or cannot check their documents.'); ?>
    <?php else: ?>
      <div class="ai-screen-list">
        <?php foreach ($reviewRequests as $row): ?>
          <article class="ai-screen-card">
            <div class="ai-screen-card-head">
              <span class="ai-screen-ref"><i class="fas fa-file-medical" aria-hidden="true"></i> <strong><?= admin_h((string) $row['reference_no']) ?></strong></span>
              <span class="ai-state-chip state-admin_review"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> <?= admin_h($reasonLabels[(string) ($row['ai_gate_reason'] ?? '')] ?? 'Needs your decision') ?></span>
            </div>
            <p class="ai-screen-meta">
              <span><i class="fas fa-user" aria-hidden="true"></i> <?= admin_h((string) ($row['beneficiary_complete_name'] ?? '')) ?></span>
              <span><i class="fas fa-clock" aria-hidden="true"></i> Waiting <?= admin_h(ai_screening_wait((int) $row['minutes_waiting'])) ?></span>
            </p>
            <ul class="ai-screen-docs">
              <?php foreach ($row['documents'] as $doc): ?>
                <li>
                  <span class="ai-doc-label"><i class="fas fa-file-lines" aria-hidden="true"></i> <?= admin_h($doc['label']) ?></span>
                  <span class="ai-doc-status"><?= admin_h($statusLabels[$doc['status']] ?? $doc['status']) ?></span>
                  <span class="ai-doc-detail"><?= admin_h($doc['detail']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="ai-screen-actions">
              <form method="post">
                <?= admin_csrf_input() ?>
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn-solid btn-mini"><i class="fas fa-paper-plane" aria-hidden="true"></i> Release to PSWDO</button>
              </form>
              <form method="post">
                <?= admin_csrf_input() ?>
                <input type="hidden" name="action" value="reupload">
                <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn-outline btn-mini"><i class="fas fa-file-arrow-up" aria-hidden="true"></i> Ask applicant to re-upload</button>
              </form>
              <?php if (($row['ai_gate_reason'] ?? '') === 'ai_unavailable'): ?>
                <form method="post">
                  <?= admin_csrf_input() ?>
                  <input type="hidden" name="action" value="retry">
                  <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                  <button type="submit" class="btn-outline btn-mini"><i class="fas fa-rotate" aria-hidden="true"></i> Retry AICS AI check</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel-card">
    <header class="panel-head"><h2><i class="fas fa-hourglass-half" aria-hidden="true"></i> Being checked by AICS AI</h2></header>
    <?php if ($waitingRequests === []): ?>
      <?php admin_render_empty('fa-mug-hot', 'No requests are waiting for AICS AI', 'New submissions show here while their documents are being checked.'); ?>
    <?php else: ?>
      <ul class="ai-screen-waiting">
        <?php foreach ($waitingRequests as $row): ?>
          <li class="<?= (int) $row['minutes_waiting'] >= $alertMinutes ? 'is-late' : '' ?>">
            <strong><i class="fas fa-file-medical" aria-hidden="true"></i> <?= admin_h((string) $row['reference_no']) ?></strong>
            <span><?= admin_h((string) ($row['beneficiary_complete_name'] ?? '')) ?></span>
            <span class="ai-wait"><i class="fas fa-clock" aria-hidden="true"></i> <?= admin_h(ai_screening_wait((int) $row['minutes_waiting'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel-card">
    <header class="panel-head ai-screen-panel-head">
      <h2><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Last 7 days</h2>
      <span class="ai-screen-note"><i class="fas fa-broom" aria-hidden="true"></i> Records older than 7 days are deleted automatically.</span>
    </header>
    <?php if ($log === []): ?>
      <?php admin_render_empty('fa-clock-rotate-left', 'No AICS AI activity in the last 7 days', 'Each check, retry and decision is listed here for a week.'); ?>
    <?php else: ?>
      <div class="ai-screen-list">
        <?php foreach ($log as $group): ?>
          <article class="ai-screen-card">
            <div class="ai-screen-card-head">
              <span class="ai-screen-ref"><i class="fas fa-file-medical" aria-hidden="true"></i> <strong><?= admin_h($group['reference_no']) ?></strong></span>
              <span class="ai-state-chip state-<?= admin_h($group['ai_gate_status']) ?>"><?= admin_h($stateLabels[$group['ai_gate_status']] ?? $group['ai_gate_status']) ?></span>
            </div>
            <ol class="ai-log-events">
              <?php foreach ($group['events'] as $event): ?>
                <li>
                  <time datetime="<?= admin_h((string) $event['created_at']) ?>"><?= admin_h(date('M j, g:i A', strtotime((string) $event['created_at']) ?: time())) ?></time>
                  <span class="ai-log-type"><?= admin_h($eventLabels[(string) $event['event_type']] ?? (string) $event['event_type']) ?></span>
                  <?php if (trim((string) ($event['document_type'] ?? '')) !== ''): ?>
                    <span class="ai-doc-label"><?= admin_h(aics_document_requirement_label((string) $event['document_type'])) ?></span>
                  <?php endif; ?>
                  <?php if (trim((string) ($event['verdict_status'] ?? '')) !== ''): ?>
                    <span class="ai-doc-status"><?= admin_h($statusLabels[(string) $event['verdict_status']] ?? (string) $event['verdict_status']) ?></span>
                  <?php endif; ?>
                  <span class="ai-doc-detail"><?= admin_h((string) $event['detail']) ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php
admin_render_page_end($paths);
