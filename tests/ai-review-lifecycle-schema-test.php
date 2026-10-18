<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function lifecycle_schema_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = 'aics_ai_lifecycle_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');

try {
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    $connection->query('CREATE TABLE aics_roles (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_requests (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_request_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id INT UNSIGNED NOT NULL,
        document_type VARCHAR(60) DEFAULT NULL,
        PRIMARY KEY (id),
        CONSTRAINT fk_lifecycle_document_request FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');

    aics_ensure_ai_review_schema($connection);

    $columns = $connection->query('SHOW COLUMNS FROM document_ai_reviews')->fetch_all(MYSQLI_ASSOC);
    $columnNames = array_column($columns, 'Field');
    foreach (['processing_stage', 'progress_percent', 'stage_updated_at', 'worker_heartbeat_at'] as $columnName) {
        lifecycle_schema_assert(in_array($columnName, $columnNames, true), 'Missing lifecycle column: ' . $columnName);
    }

    $connection->query('INSERT INTO aics_requests (id) VALUES (41)');
    $connection->query("INSERT INTO aics_request_documents (id, request_id, document_type) VALUES
        (101, 41, 'valid_id'),
        (102, 41, 'med_cert'),
        (103, 41, 'billing_statement'),
        (104, 41, 'other')");
    $connection->query("INSERT INTO document_ai_reviews
        (request_document_id, status, processing_stage, progress_percent, stage_updated_at, attempt_count)
        VALUES
        (101, 'processing', 'queued', 0, '2026-09-17 08:00:00', 0),
        (102, 'processing', 'ocr', 35, '2026-09-17 08:01:00', 1),
        (103, 'needs_staff_review', 'completed', 100, '2026-09-17 08:02:00', 1)");

    $progress = aics_ai_request_progress($connection, 41);
    lifecycle_schema_assert($progress['request_id'] === 41, 'Request identifier was not preserved.');
    lifecycle_schema_assert($progress['total'] === 3, 'Unsupported documents must not affect progress.');
    lifecycle_schema_assert($progress['queued'] === 1, 'Queued count is incorrect.');
    lifecycle_schema_assert($progress['active'] === 1, 'Active count is incorrect.');
    lifecycle_schema_assert($progress['terminal'] === 1, 'Terminal count is incorrect.');
    lifecycle_schema_assert($progress['attention'] === 1, 'Attention count is incorrect.');
    lifecycle_schema_assert($progress['failed'] === 0, 'Failed count is incorrect.');
    lifecycle_schema_assert($progress['progress_percent'] === 33, 'Request progress must use completed documents.');
    lifecycle_schema_assert($progress['state'] === 'scanning', 'Mixed active work must be scanning.');
    lifecycle_schema_assert($progress['label'] === 'Scanning 1 of 3', 'Scanning label is incorrect.');
    lifecycle_schema_assert($progress['updated_at'] === '2026-09-17 08:02:00', 'Latest safe update time is incorrect.');
    lifecycle_schema_assert(count($progress['documents']) === 3, 'Document projection is incomplete.');
    foreach ($progress['documents'] as $document) {
        lifecycle_schema_assert(!array_key_exists('file_path', $document), 'Progress projection leaked a file path.');
        lifecycle_schema_assert(!array_key_exists('extracted_fields', $document), 'Progress projection leaked extracted data.');
    }

    $connection->close();
    echo "AI review lifecycle schema test passed.\n";
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
