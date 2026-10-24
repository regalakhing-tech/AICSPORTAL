<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-submission-gate.php';
require_once __DIR__ . '/http-regression-db.php';

function screening_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
screening_assert(str_contains((string) file_get_contents($root . '/ADMIN/dashboard.php'), "'ai' => 'ai-screening.php'"), 'The admin router must open AICS AI Screening.');
$page = (string) file_get_contents($root . '/ADMIN/phpfiles/ai-screening.php');
screening_assert(str_starts_with(ltrim(substr($page, (int) strpos($page, 'require_once'))), "require_once __DIR__ . '/auth-guard.php';"), 'The page must require the admin guard first.');
screening_assert(substr_count($page, 'admin_csrf_input()') >= 4, 'Every action form needs a CSRF token.');
foreach (['admin_require_valid_csrf()', 'aics_ai_gate_sync(', 'aics_ai_gate_purge_events(', 'aics_ai_environment_health(', 'aics_ai_gate_ai_key_configured(', 'admin_log_event(',
    'aics_ai_gate_admin_release(', 'aics_ai_gate_admin_request_reupload(', 'aics_ai_gate_admin_retry(',
    'Release to PSWDO', 'Ask applicant to re-upload', 'Retry AICS AI check', 'Retry all', 'Records older than 7 days are deleted automatically.'] as $needle) {
    screening_assert(str_contains($page, $needle), "AICS AI Screening is missing: {$needle}");
}
screening_assert(stripos($page, 'gemini') === false, 'Admin text says AICS AI, never Gemini.');

screening_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
aics_http_regression_require_seed_data($c);
aics_ai_gate_ensure_schema($c);

$request = static function (mysqli $c, string $ref, string $gate, string $updated): int {
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, ai_gate_status, ai_gate_updated_at)
        VALUES ('{$ref}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 40, 'Poblacion', 'Sibalom', 'female', '[\"special_cases\"]', '[]', 'unclaimed', '{$gate}', {$updated})");
    return (int) $c->insert_id;
};
$review = $request($c, 'SCR-REVIEW', 'admin_review', 'NOW()');
$stuck = $request($c, 'SCR-STUCK', 'pending', 'NOW() - INTERVAL 31 MINUTE');
$fresh = $request($c, 'SCR-FRESH', 'pending', 'NOW() - INTERVAL 5 MINUTE');
$request($c, 'SCR-DONE', 'released', 'NOW()');
screening_assert(aics_ai_gate_attention_count($c) === 2, 'attention counts admin review plus requests waiting past the alert time');
putenv('AICS_AI_GATE_ALERT_MINUTES=60');
screening_assert(aics_ai_gate_attention_count($c) === 1, 'alert time is configurable');
putenv('AICS_AI_GATE_ALERT_MINUTES');

$waiting = aics_ai_gate_requests_in_state($c, 'pending');
screening_assert(count($waiting) === 2 && (int) $waiting[0]['id'] === $stuck && (int) $waiting[0]['minutes_waiting'] >= 31, 'waiting requests oldest first with minutes waiting');
screening_assert((int) aics_ai_gate_requests_in_state($c, 'admin_review')[0]['id'] === $review, 'admin review requests listed');

aics_ai_gate_log_event($c, $review, 'ai_admin_review', 'med_cert', 'needs_staff_review', 'AICS AI could not confirm the hospital name.', null);
aics_ai_gate_log_event($c, $fresh, 'ai_retry', 'med_cert', 'failed', 'Retry 1 of 4.', null);
aics_ai_gate_log_event($c, $fresh, 'applicant_reupload', null, null, 'Applicant uploaded: Medical Certificate.', null);
aics_ai_gate_log_event($c, $stuck, 'ai_retry', null, null, 'old', null);
$c->query("UPDATE aics_ai_gate_events SET created_at = NOW() - INTERVAL 8 DAY WHERE request_id = {$stuck}");
$log = aics_ai_gate_recent_events($c);
$byRef = array_column($log, null, 'reference_no');
screening_assert(isset($byRef['SCR-REVIEW'], $byRef['SCR-FRESH']) && !isset($byRef['SCR-STUCK']), 'log shows only the last 7 days');
screening_assert($byRef['SCR-REVIEW']['ai_gate_status'] === 'admin_review' && count($byRef['SCR-FRESH']['events']) === 2, 'log is grouped by request with its current state');
screening_assert($byRef['SCR-FRESH']['events'][0]['event_type'] === 'applicant_reupload', 'newest event first');
$c->close();
echo "Admin AICS AI Screening checks passed.\n";
