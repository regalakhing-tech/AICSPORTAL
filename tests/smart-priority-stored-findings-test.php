<?php
declare(strict_types=1);

// Smart Priority findings are saved to the database once and read from there.
// Before, every PSWDO page load re-checked the AI cache: a request showed URGENT
// when a cached result existed and HIGH (or "analysis in progress") when the
// daily cache had expired or an analysis failed.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function stored_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

stored_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');
$cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-stored-findings-' . bin2hex(random_bytes(4));
mkdir($cacheRoot, 0700, true);
putenv('AICS_EXTERNAL_AI_STATE_ROOT=' . $cacheRoot);

aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_ai_gate_ensure_schema($c);

$reference = 'STORED-' . bin2hex(random_bytes(3));
$c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status, ai_gate_status) VALUES ('{$reference}', CURDATE(), 'Ana', 'Cruz', 70, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 70, 'X', 'Sibalom', 'female', '[\"medical\"]', '[\"senior_citizen\"]', 'unclaimed', 'pending', 'released')");
$id = (int) $c->insert_id;
$c->query("INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT {$id}, id FROM aics_assistance_types WHERE assistance_code = 'medical'");
$uploadDir = __DIR__ . '/../Applicants/uploads/' . $reference;
mkdir($uploadDir, 0775, true);
file_put_contents($uploadDir . '/med.pdf', "%PDF-1.4\nScheduled surgery for the current condition.\n%%EOF\n");
$c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size, uploaded_at) VALUES ({$id}, 'med_cert', 'med.pdf', 'med.pdf', 'x', 'application/pdf', 60, NOW() - INTERVAL 1 HOUR)");
$document = aics_smart_priority_document_file(['reference_no' => $reference, 'stored_name' => 'med.pdf', 'document_type' => 'med_cert']);
stored_assert(is_array($document), 'Fixture document resolves.');
$cacheFile = aics_smart_priority_cache_file([$document]);
$row = ['id' => $id, 'client_categories_json' => '["senior_citizen"]', 'assistance_types_json' => '["medical"]', 'assistance_code' => 'medical'];
$stored = static fn (): array => $c->query("SELECT r.urgency_indicator, r.urgency_basis, r.urgency_analyzed_at, p.priority_basis FROM aics_requests r LEFT JOIN aics_request_prioritization p ON p.request_id = r.id WHERE r.id = {$id}")->fetch_assoc();

try {
    // 1. Nothing analysed yet: senior baseline, "in progress", not saved as analysed.
    pswdo_refresh_request_scoring($c, $row, null);
    $s = $stored();
    stored_assert($s['urgency_indicator'] === 'HIGH' && $s['urgency_analyzed_at'] === null && str_contains((string) $s['priority_basis'], 'still checking'), 'Pending analysis keeps the baseline and is not saved as analysed.');
    stored_assert(str_contains((string) $s['priority_basis'], 'High: the beneficiary is a Senior Citizen requesting Medical Assistance.'), 'The explanation says why the request is HIGH.');
    stored_assert(pswdo_fetch_requests_needing_urgency_analysis($c, 10) !== [] && (int) pswdo_fetch_requests_needing_urgency_analysis($c, 10)[0]['id'] === $id, 'The background job picks up a request with no saved analysis.');

    // 2. The analysis finished (cached urgent result): saved to the database.
    file_put_contents($cacheFile, json_encode(['expires' => time() + 600, 'result' => ['urgent' => true, 'reason' => 'Scheduled surgery for the current condition.', 'evidence' => ['Scheduled surgery']]]));
    pswdo_refresh_request_scoring($c, $row, null);
    $s = $stored();
    stored_assert($s['urgency_indicator'] === 'URGENT' && $s['urgency_analyzed_at'] !== null && str_contains((string) $s['urgency_basis'], 'Scheduled surgery'), 'The finished analysis is saved with its findings.');
    stored_assert(str_contains((string) $s['priority_basis'], 'Urgent: AICS AI found a current medical need') && !str_contains((string) $s['priority_basis'], 'Gemini'), 'The explanation says why the request is URGENT.');
    stored_assert(pswdo_fetch_requests_needing_urgency_analysis($c, 10) === [], 'A saved analysis is not repeated.');

    // 3. The cache expires (a new day): the saved findings are used, no flip to HIGH.
    unlink($cacheFile);
    pswdo_refresh_request_scoring($c, $row, null);
    $s = $stored();
    stored_assert($s['urgency_indicator'] === 'URGENT' && str_contains((string) $s['priority_basis'], 'Scheduled surgery') && !str_contains((string) $s['priority_basis'], 'still checking'), 'Saved findings stay after the cache is gone.');

    // 4. A failed analysis never replaces saved findings.
    file_put_contents($cacheFile, json_encode(['expires' => time() + 600, 'result' => null]));
    pswdo_refresh_request_scoring($c, $row, null, true);
    $s = $stored();
    stored_assert($s['urgency_indicator'] === 'URGENT' && str_contains((string) $s['urgency_basis'], 'Scheduled surgery'), 'A failed analysis keeps the saved result.');

    // 5. A replaced document is analysed again in the background.
    $c->query("UPDATE aics_request_documents SET uploaded_at = NOW() + INTERVAL 1 MINUTE WHERE request_id = {$id}");
    stored_assert((int) (pswdo_fetch_requests_needing_urgency_analysis($c, 10)[0]['id'] ?? 0) === $id, 'Changed documents are analysed again.');
    $high = pswdo_priority_reason('HIGH', ['senior_citizen'], 'medical', 'Senior/PWD Medical Assistance; no sufficient current urgency evidence. Stable condition.', false);
    stored_assert(str_starts_with($high, 'High: the beneficiary is a Senior Citizen requesting Medical Assistance.') && str_contains($high, 'no current medical urgency') && str_contains($high, 'Stable condition.'), 'A HIGH result explains the category and the AI finding.');
    $standard = pswdo_priority_reason('STANDARD', [], 'medical', 'Medical Assistance; no current urgent trigger detected by priority analysis.', false);
    stored_assert(str_starts_with($standard, 'Standard:') && str_contains($standard, 'No medical certificate or abstract'), 'A STANDARD result explains why.');
    $tool = (string) file_get_contents(__DIR__ . '/../tools/smart-priority-refresh.php');
    stored_assert(str_contains($tool, 'pswdo_fetch_requests_needing_urgency_analysis('), 'The background job uses the saved-analysis check.');
} finally {
    @unlink($uploadDir . '/med.pdf');
    @rmdir($uploadDir);
    array_map('unlink', glob($cacheRoot . '/smart_priority/*') ?: []);
    @rmdir($cacheRoot . '/smart_priority');
    @rmdir($cacheRoot);
    $c->close();
}
echo "Smart Priority stored findings checks passed.\n";
