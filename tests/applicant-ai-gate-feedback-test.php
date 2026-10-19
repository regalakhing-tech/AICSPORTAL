<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-submission-gate.php';
require_once __DIR__ . '/http-regression-db.php';

function feedback_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$reviews = (string) file_get_contents($root . '/includes/document-ai-reviews.php');
$queueStart = strpos($reviews, 'function aics_queue_ai_reviews_after_commit(');
$queueBody = substr($reviews, (int) $queueStart, (int) strpos($reviews, "\nfunction ", (int) $queueStart + 10) - (int) $queueStart);
feedback_assert(str_contains($queueBody, 'aics_ai_gate_after_applicant_save('), 'Saving documents must update the AICS AI gate.');
feedback_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/dashboard-data-lib.php'), 'aics_ai_gate_sync_for_account('), 'The applicant dashboard must run the AICS AI gate.');
$status = (string) file_get_contents($root . '/Applicants/php_files/application-status.php');
feedback_assert(str_contains($status, 'aics_ai_gate_sync_for_account('), 'The status page must run the AICS AI gate.');
foreach (['ai-gate-hold-banner', 'AICS AI is checking your documents.', 'Your documents are being reviewed before they go to PSWDO.', 'Expected processing time: about', 'aics_ai_gate_applicant_items(', '?page=cancel-request'] as $needle) {
    feedback_assert(str_contains($status, $needle), "Status page is missing: {$needle}");
}
feedback_assert(stripos($status, 'gemini') === false, 'Applicant text says AICS AI, never Gemini.');

feedback_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
aics_http_regression_require_seed_data($c);
aics_ai_gate_ensure_schema($c);
putenv('AICS_AI_GATE_TEST_LAUNCH=ok');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=false');
$GLOBALS['aics_ai_gate_test_launches'] = [];

$request = static function (mysqli $c, string $ref, string $gate): int {
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, ai_gate_status)
        VALUES ('{$ref}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 40, 'Poblacion', 'Sibalom', 'female', '[\"special_cases\"]', '[]', 'unclaimed', '{$gate}')");
    return (int) $c->insert_id;
};
$document = static function (mysqli $c, int $requestId, string $type, string $uploadedAt = 'NOW()'): int {
    $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size, uploaded_at) VALUES ({$requestId}, '{$type}', 'a.jpg', 'a.jpg', 'x/a.jpg', 'image/jpeg', 10, {$uploadedAt})");
    return (int) $c->insert_id;
};
$events = static fn (mysqli $c, int $id, string $type): int => (int) $c->query("SELECT COUNT(*) FROM aics_ai_gate_events WHERE request_id = {$id} AND event_type = '{$type}'")->fetch_row()[0];
$gate = static fn (mysqli $c, int $id): string => (string) $c->query("SELECT ai_gate_status FROM aics_requests WHERE id = {$id}")->fetch_row()[0];

// A held request whose applicant replaces the blurred file is checked again.
$held = $request($c, 'FB-HELD', 'held');
$old = $document($c, $held, 'med_cert', 'NOW() - INTERVAL 1 DAY');
$c->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, processed_at) VALUES ({$old}, 'needs_reupload', 'med_cert', NOW() - INTERVAL 1 DAY)");
$items = aics_ai_gate_applicant_items($c, $held);
feedback_assert(count($items) === 1 && $items[0]['label'] === 'Medical Abstract or Medical Certificate' && $items[0]['reason'] === 'blurry, cut off, or incomplete', 'held items name the document and reason');
$c->query("DELETE FROM aics_request_documents WHERE id = {$old}");
$document($c, $held, 'med_cert');
$result = aics_queue_ai_reviews_after_commit($c, $held);
feedback_assert($result['queued'] === 1 && $result['failed'] === 0, 'the new file is queued');
feedback_assert($gate($c, $held) === 'pending', 're-upload returns the request to pending');
feedback_assert($events($c, $held, 'applicant_reupload') === 1, 're-upload logged for the admin');
feedback_assert(str_contains((string) $c->query("SELECT detail FROM aics_ai_gate_events WHERE request_id = {$held} AND event_type = 'applicant_reupload'")->fetch_row()[0], 'Medical Abstract or Medical Certificate'), 'the log names the uploaded document');
feedback_assert($GLOBALS['aics_ai_gate_test_launches'] === [] && (int) $c->query('SELECT COUNT(*) FROM document_ai_request_worker_leases')->fetch_row()[0] === 0, 'saving stays database-only: no worker started from the applicant request');

// An edit without new files is logged as an edit; a released request stays with PSWDO.
$released = $request($c, 'FB-RELEASED', 'released');
$doc = $document($c, $released, 'med_cert', 'NOW() - INTERVAL 1 DAY');
$c->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, processed_at) VALUES ({$doc}, 'likely_valid', 'med_cert', NOW() - INTERVAL 1 DAY)");
aics_queue_ai_reviews_after_commit($c, $released);
feedback_assert($gate($c, $released) === 'released' && $events($c, $released, 'applicant_edit') === 1, 'released request stays released; edit logged');

// A new request with nothing for the AI to check goes straight to PSWDO.
$plain = $request($c, 'FB-PLAIN', 'pending');
$document($c, $plain, 'other_supporting');
aics_queue_ai_reviews_after_commit($c, $plain);
feedback_assert($gate($c, $plain) === 'released', 'nothing to check is released at once');

// Opening the status page drives this applicant's own pending checks.
$account = (int) $c->query('SELECT id FROM aics_accounts ORDER BY id LIMIT 1')->fetch_row()[0];
if ($account <= 0) {
    $c->query("INSERT INTO aics_accounts (role_id, account_status_id, password_hash) SELECT r.id, s.id, 'x' FROM aics_roles r, aics_account_statuses s WHERE r.role_code = 'applicat' AND s.status_code = 'active' LIMIT 1");
    $account = (int) $c->insert_id;
}
$c->query("INSERT INTO aics_account_requests (account_id, request_id) VALUES ({$account}, {$held})");
aics_ai_gate_sync_for_account($c, $account);
feedback_assert(in_array(['--request-id', $held], $GLOBALS['aics_ai_gate_test_launches'], true), 'status page starts the queued AI check');
$c->close();
echo "Applicant AICS AI feedback checks passed.\n";
