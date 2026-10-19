<?php
declare(strict_types=1);

// While AICS AI checks a new application, the applicant sees how many documents
// are done and what is happening to each one, live, instead of a spinner.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-submission-gate.php';
require_once __DIR__ . '/http-regression-db.php';

function progress_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$endpoint = (string) @file_get_contents($root . '/Applicants/php_files/ai-check-progress.php');
progress_assert($endpoint !== '', 'The progress endpoint must exist.');
foreach (['aics_is_logged_in_applicat()', 'aics_account_requests', 'aics_ai_gate_sync($connection, $requestId)', 'aics_ai_gate_applicant_progress('] as $needle) {
    progress_assert(str_contains($endpoint, $needle), "Progress endpoint is missing: {$needle}");
}
$status = (string) file_get_contents($root . '/Applicants/php_files/application-status.php');
foreach (['ai-check-live', 'data-progress-url', 'aics_ai_gate_applicant_progress(', 'AICS AI is checking your documents.', 'You can leave this page', 'Replace the document AICS AI could not accept.', 'status_gate_stage_override('] as $needle) {
    progress_assert(str_contains($status, $needle), "Status page is missing: {$needle}");
}
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
foreach (['dataset.progressUrl', 'upload.onprogress', 'const payload = await submitFormData();', '}, 900);'] as $needle) {
    progress_assert(str_contains($js, $needle), "Applicant script is missing: {$needle}");
}

progress_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
aics_ai_gate_ensure_schema($c);
$c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, ai_gate_status) VALUES ('PROG-1', CURDATE(), 'A', 'B', 30, 'A B', '09170000000', 'A', 'B', 30, 'X', 'Sibalom', 'male', '[\"medical\"]', 'unclaimed', 'pending')");
$requestId = (int) $c->insert_id;
$documents = [
    ['med_cert', "'likely_valid'", "'completed'", 1, '{"verdict":{"source":"gemini"}}'],
    ['billing_statement', "'processing'", "'ocr'", 1, ''],
    ['valid_id', "'processing'", "'queued'", 0, ''],
    ['valid_id_back', "'failed'", "'failed'", 1, ''],
];
foreach ($documents as [$type, $status, $stage, $attempts, $findings]) {
    $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES ({$requestId}, '{$type}', 'a.jpg', 'a.jpg', 'x', 'image/jpeg', 1)");
    $doc = (int) $c->insert_id;
    $f = $findings === '' ? 'NULL' : "'{$findings}'";
    $c->query("INSERT INTO document_ai_reviews (request_document_id, status, processing_stage, progress_percent, attempt_count, findings, processed_at) VALUES ({$doc}, {$status}, {$stage}, 35, {$attempts}, {$f}, NOW())");
}

$progress = aics_ai_gate_applicant_progress($c, $requestId);
progress_assert($progress['state'] === 'pending' && $progress['total'] === 3 && $progress['checked'] === 1, 'one of the three AI-checked documents is checked');
$byLabel = array_column($progress['documents'], null, 'label');
progress_assert(($byLabel['Medical Abstract or Medical Certificate']['state'] ?? '') === 'done' && $byLabel['Medical Abstract or Medical Certificate']['percent'] === 100 && $byLabel['Medical Abstract or Medical Certificate']['status_text'] === 'Checked', 'a passed document is checked');
$billing = $byLabel[aics_document_requirement_label('billing_statement')] ?? [];
progress_assert(($billing['state'] ?? '') === 'checking' && $billing['status_text'] === 'Reading the text' && $billing['percent'] === 35, 'an active document shows its step in plain words');
$front = $byLabel[aics_document_requirement_label('valid_id')] ?? [];
progress_assert(($front['state'] ?? '') === 'waiting' && $front['status_text'] === 'Waiting' && $front['percent'] === 0, 'a queued document is waiting');
progress_assert(!isset($byLabel[aics_document_requirement_label('valid_id_back')]), 'the back of the ID is not an AI-checked document');
progress_assert(stripos(json_encode($progress), 'gemini') === false && stripos(json_encode($progress), 'failed') === false, 'no internal wording reaches the applicant');

$c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, ai_gate_status) VALUES ('PROG-2', CURDATE(), 'A', 'B', 30, 'A B', '09170000000', 'A', 'B', 30, 'X', 'Sibalom', 'male', '[\"medical\"]', 'unclaimed', 'pending')");
$retryRequest = (int) $c->insert_id;
$c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES ({$retryRequest}, 'med_cert', 'a.jpg', 'a.jpg', 'x', 'image/jpeg', 1)");
$c->query("INSERT INTO document_ai_reviews (request_document_id, status, processing_stage, attempt_count, processed_at) VALUES (" . (int) $c->insert_id . ", 'failed', 'failed', 1, NOW())");
$retry = aics_ai_gate_applicant_progress($c, $retryRequest)['documents'][0] ?? [];
progress_assert(($retry['state'] ?? '') === 'checking' && $retry['status_text'] === 'Checking again', 'a failed check that will be retried says so');

$c->query("UPDATE aics_requests SET ai_gate_status = 'released', ai_gate_eta_days = 3 WHERE id = {$requestId}");
$released = aics_ai_gate_applicant_progress($c, $requestId);
progress_assert($released['state'] === 'released' && $released['eta_days'] === 3, 'a finished check reports its result');
$c->close();
echo "Applicant AICS AI check progress checks passed.\n";
