<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function reprocess_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = 'aics_reprocess_test_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');

try {
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    $connection->query('CREATE TABLE aics_roles (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, role_code VARCHAR(30) NOT NULL, role_name VARCHAR(80) NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_role_code (role_code)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, role_id TINYINT UNSIGNED NOT NULL, PRIMARY KEY (id), CONSTRAINT fk_test_account_role FOREIGN KEY (role_id) REFERENCES aics_roles(id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_request_documents (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query("INSERT INTO aics_roles (id, role_code, role_name) VALUES (1, 'applicat', 'Applicant'), (2, 'pswdo', 'PSWDO Personnel')");
    $connection->query('INSERT INTO aics_accounts (id, role_id) VALUES (10, 1), (20, 2)');
    $connection->query('INSERT INTO aics_request_documents (id) VALUES (30), (31), (32)');
    aics_ensure_ai_review_schema($connection);
    $connection->query("INSERT INTO document_ai_reviews (id, request_document_id, status, expected_document_type, detected_document_type, confidence_score, extracted_fields, findings, model_service, model_version, attempt_count, last_error_code, last_error_message, processing_started_at, processed_at) VALUES (40, 30, 'likely_valid', 'med_cert', 'med_cert', 88.24, '{\"patient_name\":{\"value\":\"Juan\"}}', '{\"summary\":\"Previous result\"}', 'local_ocr_rules', 'step3-mvp-v1', 2, NULL, NULL, NOW(), NOW())");
    $connection->query("INSERT INTO document_ai_reviews (id, request_document_id, status, expected_document_type, detected_document_type, confidence_score, extracted_fields, findings, model_service, model_version, attempt_count, processed_at) VALUES (41, 31, 'completed', 'valid_id', 'valid_id', 90, '{\"name\":{\"value\":\"Juan Dela Cruz\"}}', '{\"summary\":\"ID unchanged\"}', 'local_ocr_rules', 'structured-consistency-v1', 1, NOW()), (42, 32, 'completed', 'billing_statement', 'billing_statement', 90, '{\"patient_name\":{\"value\":\"Juan Dela Cruz\"}}', '{\"summary\":\"Billing unchanged\"}', 'local_ocr_rules', 'structured-consistency-v1', 1, NOW())");

    try {
        aics_reprocess_ai_review($connection, 40, 10);
        throw new RuntimeException('Unauthorized reprocess was accepted.');
    } catch (RuntimeException $exception) {
        reprocess_assert($exception->getMessage() === 'Unauthorized AI reprocess request.', 'Unexpected unauthorized reprocess result.');
    }
    reprocess_assert((int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_review_history')->fetch_assoc()['total'] ?? 0) === 0, 'Unauthorized request created history.');

    $result = aics_reprocess_ai_review($connection, 40, 20);
    reprocess_assert($result['request_document_id'] === 30, 'Reprocess returned wrong document ID.');
    $history = $connection->query('SELECT * FROM document_ai_review_history WHERE ai_review_id = 40')->fetch_assoc();
    reprocess_assert(is_array($history) && $history['previous_status'] === 'likely_valid', 'Previous status was not archived.');
    reprocess_assert((int) ($history['previous_attempt_count'] ?? -1) === 2, 'Previous attempt count was not archived.');
    reprocess_assert((int) ($history['archived_by_account_id'] ?? 0) === 20, 'Reprocess staff account was not archived.');
    $current = $connection->query('SELECT * FROM document_ai_reviews WHERE id = 40')->fetch_assoc();
    reprocess_assert(($current['status'] ?? '') === 'processing', 'Current review was not queued.');
    reprocess_assert((int) ($current['attempt_count'] ?? -1) === 0, 'Current retry count was not reset.');
    reprocess_assert($current['findings'] === null && $current['extracted_fields'] === null, 'Current result fields were not cleared.');
    $siblings = $connection->query('SELECT id, status, extracted_fields FROM document_ai_reviews WHERE id IN (41, 42) ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    reprocess_assert(count($siblings) === 2, 'Sibling reviews were removed.');
    reprocess_assert(($siblings[0]['status'] ?? '') === 'completed' && str_contains((string) ($siblings[0]['extracted_fields'] ?? ''), 'Juan Dela Cruz'), 'Valid ID review was changed by Medical reprocess.');
    reprocess_assert(($siblings[1]['status'] ?? '') === 'completed' && str_contains((string) ($siblings[1]['extracted_fields'] ?? ''), 'Juan Dela Cruz'), 'Billing review was changed by Medical reprocess.');

    try {
        aics_reprocess_ai_review($connection, 40, 20);
        throw new RuntimeException('Processing review accepted a second reprocess.');
    } catch (RuntimeException $exception) {
        reprocess_assert($exception->getMessage() === 'AI verification is already processing.', 'Unexpected processing reprocess result.');
    }
    reprocess_assert((int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_review_history')->fetch_assoc()['total'] ?? 0) === 1, 'Second request created duplicate history.');

    $connection->close();
    echo "AI review reprocess test passed.\n";
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
