<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function ensure_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$database = 'aics_ensure_test_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');
try {
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    $connection->query('CREATE TABLE aics_roles (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_request_documents (id INT UNSIGNED NOT NULL AUTO_INCREMENT, request_id INT UNSIGNED NOT NULL DEFAULT 0, document_type VARCHAR(100) NOT NULL DEFAULT \'valid_id\', PRIMARY KEY (id)) ENGINE=InnoDB');
    aics_ensure_ai_review_schema($connection);
    $connection->query('INSERT INTO aics_request_documents (id) VALUES (1), (2), (3), (4), (5), (6), (7), (8), (9)');

    $created = aics_ensure_current_ai_review($connection, 1, 'valid_id');
    ensure_assert($created['should_launch'] && $created['reason'] === 'created', 'Missing review did not queue exactly one initial review.');
    $again = aics_ensure_current_ai_review($connection, 1, 'valid_id');
    ensure_assert(!$again['should_launch'] && $again['reason'] === 'already_processing', 'Active processing was launched twice.');

    foreach ([2 => 'completed', 3 => 'needs_staff_review', 4 => 'needs_reupload', 5 => 'flagged_inconsistency', 6 => 'likely_valid'] as $documentId => $status) {
        $connection->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type) VALUES ($documentId, '$status', 'valid_id')");
        $result = aics_ensure_current_ai_review($connection, $documentId, 'valid_id');
        ensure_assert(!$result['should_launch'] && $result['status'] === $status, "Current $status result was changed.");
    }
    ensure_assert((int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_review_history')->fetch_assoc()['total'] ?? -1) === 0, 'Opening a request created AI history.');

    $connection->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, attempt_count) VALUES (7, 'failed', 'valid_id', 2)");
    $retry = aics_ensure_current_ai_review($connection, 7, 'valid_id');
    ensure_assert($retry['should_launch'] && $retry['reason'] === 'retried', 'Recoverable failed review was not retried.');
    $connection->query("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, attempt_count) VALUES (8, 'failed', 'valid_id', 3)");
    $capped = aics_ensure_current_ai_review($connection, 8, 'valid_id');
    ensure_assert(!$capped['should_launch'] && $capped['reason'] === 'retry_exhausted', 'Retry cap was bypassed.');
    $unsupported = aics_ensure_current_ai_review($connection, 9, 'other');
    ensure_assert(!$unsupported['should_launch'] && $unsupported['reason'] === 'unsupported_document', 'Unsupported document was queued.');

    $connection->query("INSERT INTO aics_request_documents (id, request_id, document_type) VALUES (10, 77, 'valid_id'), (11, 77, 'med_cert'), (12, 77, 'other')");
    $queued = aics_queue_ai_reviews_after_commit($connection, 77);
    ensure_assert($queued['queued'] === 2 && $queued['failed'] === 0, 'Database-only applicant queue did not accept the two supported documents.');
    ensure_assert((int) ($connection->query("SELECT COUNT(*) AS total FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id WHERE d.request_id = 77")->fetch_assoc()['total'] ?? 0) === 2, 'Database-only applicant queue did not persist one review per supported document.');
    ensure_assert((int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_request_worker_leases WHERE request_id = 77')->fetch_assoc()['total'] ?? 0) === 0, 'Applicant queue unexpectedly acquired a worker lease.');
    $queuedAgain = aics_queue_ai_reviews_after_commit($connection, 77);
    ensure_assert($queuedAgain['queued'] === 0 && (int) ($connection->query("SELECT COUNT(*) AS total FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id WHERE d.request_id = 77")->fetch_assoc()['total'] ?? 0) === 2, 'Database-only applicant queue was not idempotent.');
    $connection->close();
    echo "AI automatic ensure test passed.\n";
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
