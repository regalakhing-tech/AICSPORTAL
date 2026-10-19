<?php
declare(strict_types=1);

// PSWDO pages must never wait for AICS AI: Smart Priority on page loads uses the
// cached analysis only, and a background job computes it. Before, every page
// load re-scored every request with a live AI call (up to 100 s each).
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function speed_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Cache-only Smart Priority: no AI call, a clear "pending" when nothing is cached.
$cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-priority-cache-' . bin2hex(random_bytes(4));
mkdir($cacheRoot, 0700, true);
putenv('AICS_EXTERNAL_AI_STATE_ROOT=' . $cacheRoot);
$file = $cacheRoot . DIRECTORY_SEPARATOR . 'med.pdf';
file_put_contents($file, "%PDF-1.4\nPatient requires surgery.\n%%EOF\n");
$documents = [['document_type' => 'med_cert', 'path' => $file, 'mime' => 'application/pdf', 'size' => filesize($file)]];

$start = microtime(true);
$pending = aics_smart_priority_cache_only_assess(['youth'], 'medical', $documents);
speed_assert(!empty($pending['pending']) && $pending['indicator'] === 'STANDARD', 'Nothing cached: pending with the baseline indicator.');
speed_assert(microtime(true) - $start < 1.0, 'Cache-only lookup must not call AICS AI.');
speed_assert(aics_smart_priority_cache_lookup($documents)['hit'] === false, 'Cache lookup reports a miss.');

$cacheFile = aics_smart_priority_cache_file($documents);
speed_assert(is_string($cacheFile) && str_starts_with($cacheFile, realpath($cacheRoot)), 'Cache file lives in the AI state folder.');
file_put_contents($cacheFile, json_encode(['expires' => time() + 600, 'result' => ['urgent' => true, 'reason' => 'Scheduled surgery.', 'evidence' => ['surgery']]]));
$cached = aics_smart_priority_cache_only_assess(['youth'], 'medical', $documents);
speed_assert(empty($cached['pending']) && $cached['indicator'] === 'URGENT', 'A cached urgent analysis is used without calling AICS AI.');
speed_assert(aics_smart_priority_cache_only_assess(['youth'], 'burial', $documents)['indicator'] === 'STANDARD', 'Non-medical stays standard.');
array_map('unlink', array_merge(glob($cacheRoot . '/smart_priority/*') ?: [], [$file]));
@rmdir($cacheRoot . '/smart_priority');
@rmdir($cacheRoot);
putenv('AICS_EXTERNAL_AI_STATE_ROOT');

// Page code: no live AI call and no schema change per request.
$root = __DIR__ . '/..';
$lib = (string) file_get_contents($root . '/Pswdo/php_files/pswdo-data-lib.php');
$scoring = substr($lib, (int) strpos($lib, 'function pswdo_refresh_request_scoring('), 3500);
speed_assert(!str_contains($scoring, 'ALTER TABLE'), 'Scoring must not alter tables on every page load.');
speed_assert(str_contains($scoring, 'bool $allowAnalysis = false'), 'Page loads score from cached analysis only.');
$refresh = substr($lib, (int) strpos($lib, 'function pswdo_refresh_smart_priorities('), 1500);
speed_assert(str_contains($refresh, 'aics_ai_gate_visible_sql('), 'Only requests PSWDO can see are scored.');
$gate = (string) file_get_contents($root . '/includes/ai-submission-gate.php');
speed_assert(str_contains($gate, 'aics_smart_priority_for_request($connection, $requestId, $categories, $assistance, false)'), 'Releasing a request must not wait for AICS AI urgency.');
$tool = (string) @file_get_contents($root . '/tools/smart-priority-refresh.php');
speed_assert(str_contains($tool, "PHP_SAPI !== 'cli'") && str_contains($tool, 'pswdo_refresh_request_scoring($connection, $row, null, true)') && str_contains($tool, 'aics_ai_gate_note_priority('), 'The background job computes urgency and upgrades urgent requests.');
speed_assert(str_contains((string) file_get_contents($root . '/deploy/start.sh'), 'tools/smart-priority-refresh.php'), 'The container runs the background job.');

// Urgent upgrade after release: once, only for requests released by AICS AI.
speed_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
aics_ai_gate_ensure_schema($c);
$insert = static function (mysqli $c, string $ref, ?string $gateUpdated): int {
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, ai_gate_status, ai_gate_priority, ai_gate_eta_days, ai_gate_updated_at) VALUES ('{$ref}', CURDATE(), 'A', 'B', 30, 'A B', '09170000000', 'A', 'B', 30, 'X', 'Sibalom', 'male', '[\"medical\"]', 'unclaimed', 'released', 'STANDARD', 7, " . ($gateUpdated ?? 'NULL') . ")");
    return (int) $c->insert_id;
};
$fresh = $insert($c, 'SPEED-1', 'NOW()');
$legacy = $insert($c, 'SPEED-2', null);
speed_assert(aics_ai_gate_note_priority($c, $fresh, 'URGENT') === true, 'A request released by AICS AI is upgraded to urgent.');
speed_assert($c->query("SELECT CONCAT(ai_gate_priority, '/', ai_gate_eta_days) FROM aics_requests WHERE id = {$fresh}")->fetch_row()[0] === 'URGENT/1', 'Expected time becomes about 1 day.');
speed_assert((int) $c->query("SELECT COUNT(*) FROM aics_request_notifications WHERE request_id = {$fresh} AND message_text LIKE '%as urgent. Expected processing time: about 1 day(s).'")->fetch_row()[0] === 1, 'The applicant is told.');
speed_assert(aics_ai_gate_note_priority($c, $fresh, 'URGENT') === false, 'No second notice.');
speed_assert(aics_ai_gate_note_priority($c, $legacy, 'URGENT') === false, 'Requests from before the gate are not touched.');
speed_assert(aics_ai_gate_note_priority($c, $fresh, 'HIGH') === false, 'Only urgent results upgrade.');
$c->close();
echo "PSWDO page speed checks passed.\n";
