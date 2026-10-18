<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function backfill_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$database = 'aics_backfill_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
try {
    $server->query('CREATE DATABASE `' . $database . '`');
    $db = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $db->query('CREATE TABLE aics_roles (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id))');
    $db->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id))');
    $db->query('CREATE TABLE aics_request_documents (id INT UNSIGNED NOT NULL AUTO_INCREMENT, request_id INT UNSIGNED NOT NULL, document_type VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
    aics_ensure_ai_review_schema($db);
    foreach ([1, 2, 3, 4, 5] as $id) $db->query("INSERT INTO aics_request_documents (id, request_id, document_type) VALUES ($id, 50, 'valid_id')");
    $db->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, last_error_code, processed_at) VALUES (1, 'failed', 'valid_id', 'AI_PROCESSOR_LAUNCH_FAILED', '2026-09-14 08:35:52'), (2, 'failed', 'valid_id', 'AI_OCR_UNAVAILABLE', '2026-09-14 08:35:52'), (3, 'completed', 'valid_id', NULL, NOW()), (4, 'processing', 'valid_id', NULL, NULL)");
    $launcher = (int) ($db->query('SELECT id FROM document_ai_reviews WHERE request_document_id = 1')->fetch_assoc()['id'] ?? 0);
    backfill_assert(aics_recover_launcher_failed_ai_review($db, $launcher, '2026-09-14 08:49:46') === 1, 'Known historical launcher failure was not recovered.');
    backfill_assert((string) ($db->query('SELECT status FROM document_ai_reviews WHERE request_document_id = 1')->fetch_assoc()['status'] ?? '') === 'processing', 'Recovery did not queue a normal current attempt.');
    backfill_assert((int) ($db->query("SELECT COUNT(*) AS total FROM document_ai_review_history WHERE ai_review_id = $launcher AND archive_reason = 'launcher_failure_recovery'")->fetch_assoc()['total'] ?? 0) === 1, 'Launcher failure history was not preserved.');
    backfill_assert(aics_recover_launcher_failed_ai_review($db, $launcher, '2026-09-14 08:49:46') === null, 'A second recovery was not idempotently rejected.');
    $other = (int) ($db->query('SELECT id FROM document_ai_reviews WHERE request_document_id = 2')->fetch_assoc()['id'] ?? 0);
    backfill_assert(aics_recover_launcher_failed_ai_review($db, $other, '2026-09-14 08:49:46') === null, 'Unrelated failure was retried.');
    $queued = aics_ensure_current_ai_reviews_for_request($db, 50);
    backfill_assert(array_column($queued['newly_created'] ?? [], 'document_id') === [5], 'Backfill did not select only the never-analyzed document.');
    $second = aics_ensure_current_ai_reviews_for_request($db, 50);
    backfill_assert(($second['newly_created'] ?? []) === [], 'Second backfill created duplicate review work.');
    backfill_assert((string) ($db->query('SELECT status FROM document_ai_reviews WHERE request_document_id = 3')->fetch_assoc()['status'] ?? '') === 'completed', 'Completed review was changed by backfill.');
    $db->close();
    echo "AI backfill recovery test passed.\n";
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
