<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function feedback_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$valid = aics_normalize_ai_review_feedback([
    'ai_suggested_action' => 'staff_attention_needed',
    'staff_outcome' => 'accepted_for_manual_review',
    'agreement_state' => 'overridden',
    'corrected_document_type' => 'med_cert',
    'reason_code' => 'TYPE_CONFIRMED_BY_STAFF',
    'remarks' => str_repeat('x', 600),
]);
feedback_assert($valid['agreement_state'] === 'overridden', 'Agreement state was not preserved.');
feedback_assert(strlen($valid['remarks']) === 500, 'Remarks must be capped at 500 characters.');

try {
    aics_normalize_ai_review_feedback(['agreement_state' => 'overridden', 'reason_code' => '']);
    throw new RuntimeException('Override without a reason code must fail.');
} catch (InvalidArgumentException) {
}

$database = 'aics_ai_feedback_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');

try {
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    $connection->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_requests (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_request_documents (id INT UNSIGNED NOT NULL AUTO_INCREMENT, request_id INT UNSIGNED NOT NULL, document_type VARCHAR(60), PRIMARY KEY (id), CONSTRAINT fk_feedback_doc_request FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE) ENGINE=InnoDB');
    aics_ensure_ai_review_schema($connection);
    $connection->query('INSERT INTO aics_accounts (id) VALUES (7)');
    $connection->query('INSERT INTO aics_requests (id) VALUES (8)');
    $connection->query("INSERT INTO aics_request_documents (id, request_id, document_type) VALUES (9, 8, 'med_cert')");
    $connection->query("INSERT INTO document_ai_reviews (id, request_document_id, status) VALUES (10, 9, 'needs_staff_review')");

    $first = aics_record_ai_review_feedback($connection, 10, 7, $valid);
    $second = aics_record_ai_review_feedback($connection, 10, 7, array_merge($valid, ['agreement_state' => 'agreed', 'reason_code' => 'STAFF_CONFIRMED']));
    feedback_assert($first > 0 && $second > $first, 'Feedback entries must be insert-only audit records.');
    feedback_assert((int) $connection->query('SELECT COUNT(*) AS total FROM document_ai_review_feedback')->fetch_assoc()['total'] === 2, 'Expected two audit rows.');
    echo "AI review feedback test passed.\n";
    $connection->close();
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
