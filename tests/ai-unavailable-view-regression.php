<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function unavailable_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$source = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
unavailable_assert(is_string($source) && preg_match('/\(\(\) => \{\s+const root = document\.body;\s+function syncBodyLock\(\)[\s\S]+?function getFileExtension\(/', $source) === 1, 'Request modal initializer is missing its root binding.');
unavailable_assert(is_string($source) && str_contains($source, 'async function ensureCurrentAiReviews(requestId)') && str_contains($source, 'void ensureCurrentAiReviews(requestId);'), 'Request-wide background AI ensure is missing.');
unavailable_assert(is_string($source) && !str_contains($source, 'void ensureCurrentAiReviews(requestId, documentId);'), 'Opening individual AI Details still starts document-specific processing.');

$database = 'aics_unavailable_test_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');
try {
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    $connection->query('CREATE TABLE aics_roles (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_request_documents (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    aics_ensure_ai_review_schema($connection);
    $connection->query('INSERT INTO aics_request_documents (id) VALUES (1)');
    unavailable_assert(!aics_ai_health_allows_processing(['status' => 'unavailable']), 'Unavailable health allowed processing.');
    unavailable_assert((int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_reviews')->fetch_assoc()['total'] ?? -1) === 0, 'Unavailable view created a synthetic review.');
    unavailable_assert(aics_ai_health_allows_processing(['status' => 'operational']), 'Operational health blocked processing.');
    $result = aics_ensure_current_ai_review($connection, 1, 'valid_id');
    unavailable_assert($result['should_launch'], 'Recovery did not queue the still-missing review.');
    $connection->close();
    echo "AI unavailable/view regression test passed.\n";
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
