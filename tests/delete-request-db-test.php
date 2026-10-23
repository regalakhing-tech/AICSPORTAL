<?php
declare(strict_types=1);

// tools/delete-request.php removes one request and everything linked to it
// (documents, AICS AI results, notifications and their read marks, audit log
// entries, files), keeps every other request, and changes nothing without --confirm.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/http-regression-db.php';

function del_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

del_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');

aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_ensure_ai_review_schema($c);
aics_ensure_notification_read_schema($c);

$tag = strtoupper(bin2hex(random_bytes(3)));
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-del-' . strtolower($tag);
$uploads = $temp . DIRECTORY_SEPARATOR . 'uploads';
$private = $temp . DIRECTORY_SEPARATOR . 'private';
$artifacts = $private . DIRECTORY_SEPARATOR . 'ai_review_artifacts';
mkdir($uploads, 0777, true);
mkdir($artifacts, 0777, true);

$count = static fn (string $sql): int => (int) $c->query($sql)->fetch_row()[0];
$run = static function (array $arguments) use ($uploads, $private, $artifacts): array {
    $env = array_merge(getenv(), ['AICS_UPLOAD_ROOT' => $uploads, 'AICS_PRIVATE_ROOT' => $private, 'AICS_AI_REVIEW_ARTIFACT_ROOT' => $artifacts]);
    $command = array_merge([PHP_BINARY, __DIR__ . '/../tools/delete-request.php'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    clearstatcache();
    return [$code, $out];
};

$role = (int) ($c->query('SELECT MIN(id) FROM aics_roles')->fetch_row()[0] ?? 1);
$statusId = (int) ($c->query('SELECT MIN(id) FROM aics_account_statuses')->fetch_row()[0] ?? 1);
$requestStatus = (int) ($c->query('SELECT MIN(id) FROM aics_request_statuses')->fetch_row()[0] ?? 1);
foreach ([9101, 9102] as $account) {
    $c->query("INSERT IGNORE INTO aics_accounts (id, role_id, account_status_id, email, password_hash) VALUES ({$account}, {$role}, {$statusId}, 'del{$account}-{$tag}@example.test', 'x')");
}

$ids = [];
$reviews = [];
foreach (['target' => "AICS-2026-D{$tag}", 'keep' => "AICS-2026-K{$tag}"] as $key => $reference) {
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status) VALUES ('{$reference}', CURDATE(), 'Test', 'Person', 40, 'Test Person', '09180000000', 'Test', 'Applicant', 40, 'Poblacion', 'Sibalom', 'male', '[\"medical\"]', '[]', 'unclaimed', 'pending')");
    $id = (int) $c->insert_id;
    $ids[$key] = $id;
    $c->query("INSERT INTO aics_account_requests (account_id, request_id) VALUES (9101, {$id})");
    $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES ({$id}, 'valid_id', 'id.jpg', 'id.jpg', 'x', 'image/jpeg', 10)");
    $documentId = (int) $c->insert_id;
    $c->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type) VALUES ({$documentId}, 'completed', 'valid_id')");
    $reviews[$key] = (int) $c->insert_id;
    $c->query("INSERT INTO document_ai_review_feedback (ai_review_id, reviewed_by_account_id, ai_suggested_action, staff_outcome, agreement_state, reason_code) VALUES ({$reviews[$key]}, 9102, 'ready_for_staff_review', 'document_accepted', 'agree', 'OK')");
    $c->query("INSERT INTO aics_request_status_history (request_id, status_id) VALUES ({$id}, {$requestStatus})");
    $c->query("INSERT INTO aics_request_notifications (request_id, notification_stage, message_text) VALUES ({$id}, 'release_scheduled', 'Your release is scheduled.')");
    $notificationId = (int) $c->insert_id;
    $c->query("INSERT INTO aics_applicant_notification_reads (account_id, notification_id) VALUES (9101, {$notificationId})");
    $c->query("INSERT INTO aics_admin_audit_logs (event_type, outcome, event_message, context_json) VALUES ('ai_gate_admin_retry', 'success', 'Administrator asked AICS AI to check request #{$id} again.', '{\"request_id\": {$id}}')");
    mkdir($uploads . DIRECTORY_SEPARATOR . $reference);
    file_put_contents($uploads . DIRECTORY_SEPARATOR . $reference . DIRECTORY_SEPARATOR . 'id.jpg', 'id bytes');
    mkdir($artifacts . DIRECTORY_SEPARATOR . 'review_' . $reviews[$key] . DIRECTORY_SEPARATOR . 'page_1', 0777, true);
    file_put_contents($artifacts . DIRECTORY_SEPARATOR . 'review_' . $reviews[$key] . DIRECTORY_SEPARATOR . 'page_1' . DIRECTORY_SEPARATOR . 'original-page.png', 'png');
}
$target = $ids['target'];
$keep = $ids['keep'];
$targetReference = "AICS-2026-D{$tag}";
$c->query("INSERT INTO document_ai_request_worker_leases (request_id, lease_token) VALUES ({$target}, REPEAT('a', 32))");
// A log about request #<target>9 is another request and must stay.
$c->query("INSERT INTO aics_admin_audit_logs (event_type, outcome, event_message) VALUES ('ai_gate_admin_release', 'success', 'Administrator sent request #{$target}9 to PSWDO.')");

$linked = static fn (int $id): int => $count("SELECT COUNT(*) FROM aics_request_notifications WHERE request_id = {$id}")
    + $count("SELECT COUNT(*) FROM aics_request_documents WHERE request_id = {$id}")
    + $count("SELECT COUNT(*) FROM aics_account_requests WHERE request_id = {$id}")
    + $count("SELECT COUNT(*) FROM aics_request_status_history WHERE request_id = {$id}");

try {
    [$code, $out] = $run([$targetReference]);
    del_assert($code === 0 && str_contains($out, 'Preview only'), "Preview failed:\n{$out}");
    del_assert(str_contains($out, 'aics_admin_audit_logs') && str_contains($out, 'aics_applicant_notification_reads'), "Preview does not list the audit logs and read marks:\n{$out}");
    del_assert($count("SELECT COUNT(*) FROM aics_requests WHERE id = {$target}") === 1 && $linked($target) === 4, 'The preview changed the database.');
    del_assert(is_dir($uploads . DIRECTORY_SEPARATOR . $targetReference), 'The preview removed files.');

    [$code, $out] = $run(['AICS-2026-NOPE99', '--confirm']);
    del_assert($code === 0 && str_contains($out, 'Nothing to delete'), "An unknown reference was not reported:\n{$out}");

    [$code, $out] = $run([$targetReference, '--confirm']);
    del_assert($code === 0 && str_contains($out, 'Done.'), "Delete failed:\n{$out}");

    del_assert($count("SELECT COUNT(*) FROM aics_requests WHERE id = {$target}") === 0, 'The request was not deleted.');
    del_assert($linked($target) === 0, 'Rows linked to the deleted request remain.');
    del_assert($count("SELECT COUNT(*) FROM document_ai_reviews WHERE id = {$reviews['target']}") === 0
        && $count("SELECT COUNT(*) FROM document_ai_review_feedback WHERE ai_review_id = {$reviews['target']}") === 0, 'AICS AI results of the deleted request remain.');
    del_assert($count("SELECT COUNT(*) FROM document_ai_request_worker_leases WHERE request_id = {$target}") === 0, 'The AI worker lease remains.');
    del_assert($count('SELECT COUNT(*) FROM aics_applicant_notification_reads WHERE account_id = 9101') === 1, 'Read marks: the deleted request\'s mark remains, or the other request\'s mark was removed.');
    del_assert($count("SELECT COUNT(*) FROM aics_admin_audit_logs WHERE event_message LIKE '%#{$target} %'") === 0, 'The audit log entry for the deleted request remains.');
    del_assert($count("SELECT COUNT(*) FROM aics_admin_audit_logs WHERE event_message LIKE '%#{$target}9 %'") === 1, 'An audit log entry for another request was deleted.');

    del_assert($count("SELECT COUNT(*) FROM aics_requests WHERE id = {$keep}") === 1 && $linked($keep) === 4, 'The other request lost data.');
    del_assert($count("SELECT COUNT(*) FROM document_ai_review_feedback WHERE ai_review_id = {$reviews['keep']}") === 1, 'The other request lost its AICS AI feedback.');
    del_assert($count("SELECT COUNT(*) FROM aics_admin_audit_logs WHERE event_message LIKE '%#{$keep} %'") === 1, 'The other request lost its audit log entry.');
    del_assert($count('SELECT COUNT(*) FROM aics_accounts WHERE id = 9101') === 1, 'The applicant account was deleted.');

    del_assert(!is_dir($uploads . DIRECTORY_SEPARATOR . $targetReference) && !is_dir($artifacts . DIRECTORY_SEPARATOR . 'review_' . $reviews['target']), "Files of the deleted request remain:\n{$out}");
    del_assert(is_dir($uploads . DIRECTORY_SEPARATOR . "AICS-2026-K{$tag}") && is_dir($artifacts . DIRECTORY_SEPARATOR . 'review_' . $reviews['keep']), 'Files of the other request were deleted.');

    $backups = glob($private . '/backups/deleted-requests/' . $targetReference . '-*.json') ?: [];
    $backup = $backups === [] ? null : json_decode((string) file_get_contents($backups[0]), true);
    del_assert(is_array($backup) && count($backup['tables']['aics_requests'] ?? []) === 1 && count($backup['tables']['aics_request_notifications'] ?? []) === 1, 'The backup does not hold the deleted rows.');

    echo "Delete request DB checks passed.\n";
} finally {
    foreach ($ids as $id) $c->query("DELETE FROM aics_requests WHERE id = {$id}");
    $c->query("DELETE FROM aics_admin_audit_logs WHERE event_message LIKE 'Administrator % request #{$target}%' OR event_message LIKE '%request #{$keep} %'");
    $c->query('DELETE FROM aics_applicant_notification_reads WHERE account_id = 9101');
    $c->query("DELETE FROM aics_accounts WHERE id IN (9101, 9102) AND email LIKE 'del%@example.test'");
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temp);
}
