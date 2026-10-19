<?php
declare(strict_types=1);

// Railway's MariaDB runs in strict mode: a Medical request stores the urgency
// value REQUIRES_STAFF_REVIEW (21 characters), which a VARCHAR(20) column rejects
// ("Data too long for column 'urgency_indicator'"), failing every submission.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/beneficiary-priority.php';
require_once __DIR__ . '/http-regression-db.php';

function urgency_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
foreach (['database/AICSdb.sql', 'Applicants/php_files/save-request.php', 'Pswdo/php_files/pswdo-common.php'] as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    urgency_assert(preg_match('/urgency_indicator`?\s+VARCHAR\(20\)/i', $source) !== 1, "{$file} still creates a 20-character urgency_indicator column.");
}
foreach (['Applicants/php_files/save-request.php', 'Pswdo/php_files/pswdo-common.php', 'tools/ai-gate-sync.php'] as $file) {
    urgency_assert(str_contains((string) file_get_contents($root . '/' . $file), 'aics_ensure_urgency_indicator_columns('), "{$file} must widen existing urgency columns.");
}

urgency_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
// A database created before the fix, like the live Railway one.
$c->query('ALTER TABLE aics_requests MODIFY COLUMN urgency_indicator VARCHAR(20) DEFAULT NULL');
$c->query('ALTER TABLE aics_request_prioritization MODIFY COLUMN urgency_indicator VARCHAR(20) DEFAULT NULL');
$c->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status) VALUES ('URG-1', CURDATE(), 'A', 'B', 30, 'A B', '09170000000', 'A', 'B', 30, 'X', 'Sibalom', 'male', '[\"medical\"]', 'unclaimed')");
$requestId = (int) $c->insert_id;
$failedBefore = false;
try {
    $c->query("UPDATE aics_requests SET urgency_indicator = 'REQUIRES_STAFF_REVIEW' WHERE id = {$requestId}");
} catch (mysqli_sql_exception $exception) {
    $failedBefore = $exception->getCode() === 1406;
}
urgency_assert($failedBefore, 'Precondition: strict mode rejects the 21-character value in a 20-character column.');

aics_ensure_urgency_indicator_columns($c);
$c->query("UPDATE aics_requests SET urgency_indicator = 'REQUIRES_STAFF_REVIEW' WHERE id = {$requestId}");
$c->query("INSERT INTO aics_request_prioritization (request_id, urgency_score, urgency_indicator, vulnerability_score, readiness_score, risk_penalty, priority_score, priority_level) VALUES ({$requestId}, 0, 'REQUIRES_STAFF_REVIEW', 0, 0, 0, 0, 'NORMAL')");
urgency_assert($c->query("SELECT urgency_indicator FROM aics_requests WHERE id = {$requestId}")->fetch_row()[0] === 'REQUIRES_STAFF_REVIEW', 'the full value is stored on the request');
urgency_assert($c->query("SELECT urgency_indicator FROM aics_request_prioritization WHERE request_id = {$requestId}")->fetch_row()[0] === 'REQUIRES_STAFF_REVIEW', 'the full value is stored on the priority record');
$c->close();
echo "Urgency indicator strict-mode checks passed.\n";
