<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-submission-gate.php';
require_once __DIR__ . '/http-regression-db.php';

function gate_db_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

gate_db_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
aics_http_regression_require_seed_data($c);
aics_ai_gate_ensure_schema($c);
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=false');

function gate_value(mysqli $c, string $sql): mixed
{
    $row = $c->query($sql)?->fetch_row();
    return $row[0] ?? null;
}

/** Insert a request with one document and (optionally) its AI review; returns [requestId, documentId, reviewId]. */
function gate_fixture(mysqli $c, ?string $reviewStatus, array $review = [], string $assistance = 'special_cases', string $gate = 'pending', string $documentType = 'med_cert'): array
{
    static $n = 0;
    $n++;
    $ref = 'GATE-' . getmypid() . '-' . $n;
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, ai_gate_status)
        VALUES ('{$ref}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 40, 'Poblacion', 'Sibalom', 'female', '[\"{$assistance}\"]', '[]', 'unclaimed', '{$gate}')");
    $requestId = (int) $c->insert_id;
    $c->query("INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT {$requestId}, id FROM aics_assistance_types WHERE assistance_code = '{$assistance}'");
    $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES ({$requestId}, '{$documentType}', 'a.jpg', 'a.jpg', 'x/a.jpg', 'image/jpeg', 10)");
    $documentId = (int) $c->insert_id;
    $reviewId = 0;
    if ($reviewStatus !== null) {
        $findings = $c->real_escape_string(json_encode($review['findings'] ?? ['verdict' => ['source' => 'gemini', 'headline' => 'Checked', 'reason' => 'Document reviewed.']]));
        $attempts = (int) ($review['attempt_count'] ?? ($reviewStatus === 'processing' ? 0 : 1));
        $retries = (int) ($review['gate_retry_count'] ?? 0);
        $started = $review['started'] ?? 'NOW()';
        $processed = $review['processed'] ?? ($reviewStatus === 'processing' ? 'NULL' : 'NOW() - INTERVAL 2 MINUTE');
        $error = isset($review['error']) ? "'" . $c->real_escape_string($review['error']) . "'" : 'NULL';
        $c->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, findings, attempt_count, gate_retry_count, processing_started_at, processed_at, last_error_message)
            VALUES ({$documentId}, '{$reviewStatus}', '{$documentType}', '{$findings}', {$attempts}, {$retries}, {$started}, {$processed}, {$error})");
        $reviewId = (int) $c->insert_id;
    }
    return [$requestId, $documentId, $reviewId];
}

function gate_count(mysqli $c, string $table, int $requestId, string $where = '1=1'): int
{
    return (int) gate_value($c, "SELECT COUNT(*) FROM {$table} WHERE request_id = {$requestId} AND {$where}");
}

function gate_reset_workers(mysqli $c): void
{
    $c->query('DELETE FROM document_ai_request_worker_leases');
    $c->query("UPDATE document_ai_reviews SET status = 'likely_valid', processed_at = NOW() WHERE status = 'processing'");
    $c->query("UPDATE aics_requests SET ai_gate_status = 'released' WHERE ai_gate_status = 'pending'");
    $GLOBALS['aics_ai_gate_test_launches'] = [];
    putenv('AICS_AI_GATE_TEST_LAUNCH=ok');
    putenv('AICS_AI_MAX_WORKERS');
}

$tests = [];

$tests['testHoldLogsAndNotifies'] = function () use ($c): void {
    [$id] = gate_fixture($c, 'needs_reupload', ['findings' => ['verdict' => ['source' => 'gemini', 'headline' => 'Unreadable', 'reason' => 'The photo is blurred.']]]);
    $result = aics_ai_gate_sync($c, $id);
    gate_db_assert($result['held'] === 1, 'held count');
    gate_db_assert(gate_value($c, "SELECT CONCAT(ai_gate_status, '/', ai_gate_reason) FROM aics_requests WHERE id = {$id}") === 'held/blocked', 'held state');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $id, "event_type = 'ai_hold' AND verdict_status = 'needs_reupload' AND detail LIKE '%blurred%'") === 1, 'hold event with findings');
    gate_db_assert(gate_count($c, 'aics_request_notifications', $id, "notification_stage = 'AICS AI: Re-upload needed' AND channel = 'applicant_portal' AND message_text LIKE 'AICS AI found a problem with 1 document(s)%'") === 1, 'hold notice');
    $again = aics_ai_gate_sync($c, $id);
    gate_db_assert($again['held'] === 0 && gate_count($c, 'aics_ai_gate_events', $id) === 1 && gate_count($c, 'aics_request_notifications', $id) === 1, 'testSecondSyncIsNoop: second sync changes nothing');
};

$tests['testPassReleasesWithEta'] = function () use ($c): void {
    [$id] = gate_fixture($c, 'likely_valid');
    $result = aics_ai_gate_sync($c, $id);
    gate_db_assert($result['released'] === 1, 'released count');
    gate_db_assert(gate_value($c, "SELECT CONCAT(ai_gate_status, '/', ai_gate_priority, '/', ai_gate_eta_days) FROM aics_requests WHERE id = {$id}") === 'released/STANDARD/7', 'released with ETA');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $id, "event_type = 'ai_release'") === 1, 'release event');
    gate_db_assert(gate_count($c, 'aics_request_notifications', $id, "notification_stage = 'AICS AI: Documents checked' AND message_text LIKE '%about 7 day(s).'") === 1, 'passed notice');
    gate_db_assert(aics_ai_gate_is_released($c, $id), 'is_released');
};

$tests['testNoDocumentsReleases'] = function () use ($c): void {
    [$id] = gate_fixture($c, null, [], 'special_cases', 'pending', 'other_supporting');
    gate_db_assert(aics_ai_gate_sync($c, $id)['released'] === 1, 'a request with nothing to check is released');
};

$tests['testQueuedReviewIsDispatched'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id] = gate_fixture($c, 'processing');
    $result = aics_ai_gate_sync($c, $id);
    gate_db_assert($result['dispatched'] === 1, 'dispatched');
    gate_db_assert(in_array(['--request-id', $id], $GLOBALS['aics_ai_gate_test_launches'], true), 'request worker launched');
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$id}") === 'pending', 'still pending');
    gate_db_assert(aics_ai_gate_sync($c, $id)['dispatched'] === 0, 'a running worker is not launched twice');
};

$tests['testFailedReviewIsRetried'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id, , $reviewId] = gate_fixture($c, 'failed', ['attempt_count' => 3, 'error' => 'Worker crashed.']);
    $result = aics_ai_gate_sync($c, $id);
    gate_db_assert($result['retried'] === 1 && $result['dispatched'] === 1, 'retried and dispatched');
    gate_db_assert(gate_value($c, "SELECT CONCAT(status, '/', processing_stage, '/', attempt_count, '/', gate_retry_count) FROM document_ai_reviews WHERE id = {$reviewId}") === 'processing/queued/0/1', 'requeued as fresh with retry counted');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $id, "event_type = 'ai_retry'") === 1, 'retry event');
    gate_db_assert(in_array(['--request-id', $id], $GLOBALS['aics_ai_gate_test_launches'], true), 'worker launched for the retry');
};

$tests['testBackoffWaitsBeforeRetry'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id] = gate_fixture($c, 'failed', ['gate_retry_count' => 1, 'processed' => 'NOW() - INTERVAL 2 MINUTE']);
    gate_db_assert(aics_ai_gate_sync($c, $id)['retried'] === 0, 'second retry waits 5 minutes');
    $c->query("UPDATE document_ai_reviews ai JOIN aics_request_documents d ON d.id = ai.request_document_id SET ai.processed_at = NOW() - INTERVAL 6 MINUTE WHERE d.request_id = {$id}");
    gate_db_assert(aics_ai_gate_sync($c, $id)['retried'] === 1, 'retried after the wait');
};

$tests['testLocalOnlyResultIsRetried'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id] = gate_fixture($c, 'likely_valid', ['findings' => ['verdict' => ['source' => 'local', 'headline' => 'Looks valid']]]);
    $result = aics_ai_gate_sync($c, $id);
    gate_db_assert($result['retried'] === 1 && $result['released'] === 0, 'an OCR-only pass is re-checked, not released');
};

$tests['testStalledReviewIsRetried'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id, , $reviewId] = gate_fixture($c, 'processing', ['attempt_count' => 1, 'started' => 'NOW() - INTERVAL 31 MINUTE']);
    aics_ai_gate_sync($c, $id);
    gate_db_assert(gate_value($c, "SELECT CONCAT(status, '/', last_error_code) FROM document_ai_reviews WHERE id = {$reviewId}") === 'failed/AI_PROCESSING_TIMEOUT', 'stalled work recovered');
    $c->query("UPDATE document_ai_reviews SET processed_at = NOW() - INTERVAL 2 MINUTE WHERE id = {$reviewId}");
    gate_db_assert(aics_ai_gate_sync($c, $id)['retried'] === 1, 'stalled work retried');
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$id}") === 'pending', 'never released while retrying');
};

$tests['testLaunchFailureEndsInAdminReview'] = function () use ($c): void {
    gate_reset_workers($c);
    putenv('AICS_AI_GATE_TEST_LAUNCH=fail');
    [$id, , $reviewId] = gate_fixture($c, 'processing');
    $states = [];
    for ($run = 0; $run < 12; $run++) {
        aics_ai_gate_sync($c, $id);
        $states[] = gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$id}");
        gate_db_assert(gate_value($c, 'SELECT COUNT(*) FROM document_ai_request_worker_leases') === '0', 'a failed launch leaves no lease behind');
        if (end($states) !== 'pending') break;
        $c->query("UPDATE document_ai_reviews SET processed_at = NOW() - INTERVAL 1 HOUR WHERE id = {$reviewId}");
    }
    gate_db_assert(!in_array('released', $states, true), 'never released');
    gate_db_assert(gate_value($c, "SELECT CONCAT(ai_gate_status, '/', ai_gate_reason) FROM aics_requests WHERE id = {$id}") === 'admin_review/ai_unavailable', 'admin review after retries: ' . implode(',', $states));
    gate_db_assert((int) gate_value($c, "SELECT gate_retry_count FROM document_ai_reviews WHERE id = {$reviewId}") === 4, 'four retries');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $id, "event_type = 'ai_admin_review'") === 1, 'admin review event');
    gate_db_assert(gate_count($c, 'aics_request_notifications', $id, "notification_stage = 'AICS AI: Being reviewed'") === 1, 'admin review notice');
};

$tests['testUncertainGoesToAdmin'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id] = gate_fixture($c, 'needs_staff_review');
    $result = aics_ai_gate_sync($c, $id);
    gate_db_assert($result['admin_review'] === 1 && gate_value($c, "SELECT CONCAT(ai_gate_status, '/', ai_gate_reason) FROM aics_requests WHERE id = {$id}") === 'admin_review/uncertain', 'uncertain to admin');
    gate_db_assert(!aics_ai_gate_is_released($c, $id), 'not released');
};

$tests['testWorkerCapLimitsDispatch'] = function () use ($c): void {
    gate_reset_workers($c);
    putenv('AICS_AI_MAX_WORKERS=2');
    [$busy] = gate_fixture($c, 'likely_valid', [], 'special_cases', 'released');
    $c->query("INSERT INTO document_ai_request_worker_leases (request_id, lease_token, acquired_at) VALUES ({$busy}, '" . str_repeat('a', 32) . "', CURRENT_TIMESTAMP)");
    for ($i = 0; $i < 5; $i++) gate_fixture($c, 'processing');
    gate_db_assert(aics_ai_gate_busy_workers($c) === 1, 'one worker already busy');
    $result = aics_ai_gate_sync($c);
    gate_db_assert($result['dispatched'] === 1, 'only one more worker started, got ' . $result['dispatched']);
    gate_db_assert((int) gate_value($c, 'SELECT COUNT(*) FROM document_ai_request_worker_leases') === 2, 'no stray leases for requests that must wait');
};

$tests['testReuploadReturnsToPending'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id, , $reviewId] = gate_fixture($c, 'needs_reupload');
    aics_ai_gate_sync($c, $id);
    aics_ai_gate_note_documents_changed($c, $id, null, 'applicant_reupload');
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$id}") === 'pending', 'back to pending');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $id, "event_type = 'applicant_reupload'") === 1, 're-upload logged');
    $c->query("UPDATE document_ai_reviews SET status = 'likely_valid' WHERE id = {$reviewId}");
    gate_db_assert(aics_ai_gate_sync($c, $id)['released'] === 1, 're-decided on the new result');
    aics_ai_gate_note_documents_changed($c, $id, null, 'applicant_edit');
    gate_db_assert(aics_ai_gate_is_released($c, $id) && gate_count($c, 'aics_ai_gate_events', $id, "event_type = 'applicant_edit'") === 1, 'released stays released; edit logged');
};

$tests['testAdminActions'] = function () use ($c): void {
    gate_reset_workers($c);
    $admin = (int) gate_value($c, 'SELECT id FROM aics_accounts ORDER BY id LIMIT 1');
    $adminId = $admin > 0 ? $admin : null;
    [$a] = gate_fixture($c, 'needs_staff_review');
    aics_ai_gate_sync($c, $a);
    gate_db_assert(aics_ai_gate_admin_request_reupload($c, $a, (int) $adminId), 'ask for re-upload');
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$a}") === 'held', 'held after admin asks');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $a, "event_type = 'admin_reupload_request'") === 1, 'admin re-upload logged');
    gate_db_assert(gate_count($c, 'aics_request_notifications', $a, "message_text LIKE '%needs a clearer copy%'") === 1, 'applicant told what to fix');

    [$b] = gate_fixture($c, 'needs_staff_review');
    aics_ai_gate_sync($c, $b);
    gate_db_assert(aics_ai_gate_admin_release($c, $b, (int) $adminId), 'admin release');
    gate_db_assert(aics_ai_gate_is_released($c, $b), 'released by admin');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $b, "event_type = 'admin_release'") === 1, 'admin release logged');
    gate_db_assert(gate_count($c, 'aics_request_notifications', $b, "notification_stage = 'Sent to PSWDO for review' AND message_text LIKE '%Expected processing time: about 7 day(s).'") === 1, 'admin release notice');
    gate_db_assert(!aics_ai_gate_admin_release($c, $b, (int) $adminId), 'cannot release twice');

    [$d, , $reviewId] = gate_fixture($c, 'failed', ['gate_retry_count' => 4]);
    aics_ai_gate_sync($c, $d);
    gate_db_assert(gate_value($c, "SELECT ai_gate_reason FROM aics_requests WHERE id = {$d}") === 'ai_unavailable', 'fixture reached admin');
    $c->query("UPDATE aics_requests SET ai_gate_status = 'released' WHERE ai_gate_status = 'admin_review' AND id <> {$d}");
    $retriedAll = aics_ai_gate_admin_retry($c, null, (int) $adminId);
    gate_db_assert($retriedAll === 1, 'retry all, got ' . $retriedAll);
    gate_db_assert(gate_value($c, "SELECT CONCAT(status, '/', attempt_count, '/', gate_retry_count) FROM document_ai_reviews WHERE id = {$reviewId}") === 'processing/0/0', 'requeued with a fresh retry budget');
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$d}") === 'pending', 'pending again');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $d, "event_type = 'admin_retry'") === 1, 'admin retry logged');
    gate_db_assert(in_array(['--request-id', $d], $GLOBALS['aics_ai_gate_test_launches'], true), 'worker started right away');
};

$tests['testPurge'] = function () use ($c): void {
    [$id] = gate_fixture($c, 'likely_valid', [], 'special_cases', 'released');
    aics_ai_gate_log_event($c, $id, 'ai_release', null, null, 'old', null);
    aics_ai_gate_log_event($c, $id, 'ai_release', null, null, 'recent', null);
    $c->query("UPDATE aics_ai_gate_events SET created_at = NOW() - INTERVAL 8 DAY WHERE request_id = {$id} AND detail = 'old'");
    $c->query("UPDATE aics_ai_gate_events SET created_at = NOW() - INTERVAL 6 DAY WHERE request_id = {$id} AND detail = 'recent'");
    gate_db_assert(aics_ai_gate_purge_events($c) >= 1, 'purged');
    gate_db_assert(gate_count($c, 'aics_ai_gate_events', $id) === 1 && gate_count($c, 'aics_ai_gate_events', $id, "detail = 'recent'") === 1, 'only the 6-day-old record is kept');
};

$tests['testBlurredDocumentGoesBackToApplicant'] = function () use ($c): void {
    gate_reset_workers($c);
    $checks = static fn (string $readable, string $consistent): array => ['verdict' => ['source' => 'gemini', 'headline' => 'Staff review needed', 'reason' => 'The document image is heavily blurred.'],
        'thesis_checks' => ['complete' => ['state' => 'pass'], 'consistent' => ['state' => $consistent], 'readable' => ['state' => $readable], 'unaltered' => ['state' => 'pass'], 'untampered' => ['state' => 'pass']]];
    [$blurred] = gate_fixture($c, 'needs_staff_review', ['findings' => $checks('review', 'pass')]);
    aics_ai_gate_sync($c, $blurred);
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$blurred}") === 'held', 'a blurred document goes back to the applicant');
    gate_db_assert(gate_count($c, 'aics_request_notifications', $blurred, "message_text LIKE '%(blurry, cut off, or incomplete)%'") === 1, 'the applicant is told it is blurry');
    gate_db_assert(aics_ai_gate_applicant_items($c, $blurred)[0]['reason'] === 'blurry, cut off, or incomplete', 'status page names the reason');
    [$doubtful] = gate_fixture($c, 'needs_staff_review', ['findings' => $checks('pass', 'review')]);
    aics_ai_gate_sync($c, $doubtful);
    gate_db_assert(gate_value($c, "SELECT ai_gate_status FROM aics_requests WHERE id = {$doubtful}") === 'admin_review', 'a doubtful match still goes to the admin');
};

$tests['testCancelledRequestIsIgnored'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id] = gate_fixture($c, 'processing');
    $c->query("UPDATE aics_requests SET request_status = 'cancelled', ai_gate_updated_at = NOW() - INTERVAL 2 HOUR WHERE id = {$id}");
    $result = aics_ai_gate_sync($c);
    gate_db_assert($result['dispatched'] === 0 && $GLOBALS['aics_ai_gate_test_launches'] === [], 'no AI work for a cancelled request');
    gate_db_assert(aics_ai_gate_attention_count($c) === (int) gate_value($c, "SELECT COUNT(*) FROM aics_requests WHERE ai_gate_status = 'admin_review'"), 'a cancelled request raises no admin alert');
    gate_db_assert(!in_array($id, array_map('intval', array_column(aics_ai_gate_requests_in_state($c, 'pending'), 'id')), true), 'a cancelled request is not listed as waiting');
    $c->query("UPDATE document_ai_reviews ai JOIN aics_request_documents d ON d.id = ai.request_document_id SET ai.status = 'likely_valid' WHERE d.request_id = {$id}");
    aics_ai_gate_sync($c, $id);
    gate_db_assert(gate_count($c, 'aics_request_notifications', $id) === 0, 'a cancelled request is never announced to PSWDO');
};

$tests['testCrashedWorkerFreesItsSlot'] = function () use ($c): void {
    gate_reset_workers($c);
    [$crashed] = gate_fixture($c, 'likely_valid', [], 'special_cases', 'released');
    $c->query("INSERT INTO document_ai_request_worker_leases (request_id, lease_token, acquired_at) VALUES ({$crashed}, '" . str_repeat('b', 32) . "', NOW() - INTERVAL 20 MINUTE)");
    gate_db_assert(aics_ai_gate_busy_workers($c) === 0, 'a 20-minute-old lease without heartbeats is not a running worker');
    [$working] = gate_fixture($c, 'processing', ['attempt_count' => 1, 'started' => 'NOW() - INTERVAL 25 MINUTE']);
    $c->query("UPDATE document_ai_reviews ai JOIN aics_request_documents d ON d.id = ai.request_document_id SET ai.worker_heartbeat_at = NOW() - INTERVAL 1 MINUTE WHERE d.request_id = {$working}");
    gate_db_assert(aics_ai_gate_busy_workers($c) === 1, 'a worker with a recent heartbeat is running');
    $c->query("UPDATE aics_requests SET ai_gate_status = 'released' WHERE id = {$working}");
    $c->query("DELETE FROM document_ai_request_worker_leases WHERE request_id = {$crashed}");
};

$tests['testPageSyncDefersToScheduler'] = function () use ($c): void {
    gate_reset_workers($c);
    [$id] = gate_fixture($c, 'processing');
    putenv('AICS_AI_GATE_SCHEDULER=1');
    aics_ai_gate_page_sync($c);
    gate_db_assert($GLOBALS['aics_ai_gate_test_launches'] === [], 'with the container scheduler, PSWDO page loads do not drive the AI');
    putenv('AICS_AI_GATE_SCHEDULER');
    aics_ai_gate_page_sync($c);
    gate_db_assert(in_array(['--request-id', $id], $GLOBALS['aics_ai_gate_test_launches'], true), 'without a scheduler, page loads drive the AI');
    $c->query("UPDATE aics_requests SET ai_gate_status = 'released' WHERE id = {$id}");
    $many = [];
    for ($i = 0; $i < 3; $i++) $many[] = gate_fixture($c, 'needs_reupload')[0];
    $result = aics_ai_gate_sync($c, null, true, 2);
    gate_db_assert($result['held'] === 2, 'a batch size limits one pass, got ' . $result['held']);
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "ok {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: " . $exception->getMessage() . ' @' . basename($exception->getFile()) . ':' . $exception->getLine() . "\n";
    }
}
$c->close();
if ($failed > 0) { echo "{$failed} failed\n"; exit(1); }
echo "PASS\n";
