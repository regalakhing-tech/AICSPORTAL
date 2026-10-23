<?php
declare(strict_types=1);

// A Medical request goes to PSWDO only after AICS AI Smart Priority has checked
// its medical documents, so the applicant and PSWDO see the same priority from
// the start. Before, it was released with the default (HIGH for a senior or PWD)
// and turned URGENT about two minutes later, after PSWDO had already seen it.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function before_release_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

before_release_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');
$cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-priority-release-' . bin2hex(random_bytes(4));
mkdir($cacheRoot, 0700, true);
putenv('AICS_EXTERNAL_AI_STATE_ROOT=' . $cacheRoot);

aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_ai_gate_ensure_schema($c);
aics_ensure_urgency_indicator_columns($c);

$value = static fn (string $sql): mixed => $c->query($sql)?->fetch_row()[0] ?? null;
$uploadDirs = [];
/** A Medical request for a PWD whose documents just passed the AICS AI check. */
$request = static function (string $assistance = 'medical') use ($c, &$uploadDirs): int {
    static $n = 0;
    $n++;
    $reference = 'PREL-' . $n . '-' . bin2hex(random_bytes(2));
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status, ai_gate_status, ai_gate_reason) VALUES ('{$reference}', CURDATE(), 'Nathan', 'Yu', 40, 'Nathan Yu', '09170000000', 'Nathan', 'Yu', 40, 'X', 'Sibalom', 'male', '[\"{$assistance}\"]', '[\"pwd\"]', 'unclaimed', 'pending', 'pending', 'checking')");
    $id = (int) $c->insert_id;
    $c->query("INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT {$id}, id FROM aics_assistance_types WHERE assistance_code = '{$assistance}'");
    $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size, uploaded_at) VALUES ({$id}, 'med_cert', 'med.pdf', 'med.pdf', 'x', 'application/pdf', 60, NOW() - INTERVAL 10 MINUTE)");
    $dir = __DIR__ . '/../Applicants/uploads/' . $reference;
    mkdir($dir, 0775, true);
    file_put_contents($dir . '/med.pdf', "%PDF-1.4\nPatient {$reference} for dialysis.\n%%EOF\n");
    $uploadDirs[] = $dir;
    return $id;
};
$passed = ['state' => 'released', 'reason' => 'passed', 'blocking' => [], 'retry' => [], 'uncertain' => []];
$apply = static function (int $id, ?callable $analyse = null) use ($c, $passed): void {
    $row = aics_ai_gate_request_row($c, $id);
    $totals = ['released' => 0, 'held' => 0, 'admin_review' => 0, 'dispatched' => 0, 'retried' => 0];
    aics_ai_gate_apply($c, $row, $passed, $totals, $analyse);
};
$state = static fn (int $id): string => (string) $value("SELECT CONCAT(ai_gate_status, '/', COALESCE(ai_gate_reason, ''), '/', COALESCE(ai_gate_priority, '-')) FROM aics_requests WHERE id = {$id}");
// What the background analysis saves (pswdo_refresh_request_scoring with analysis allowed).
$analyseUrgent = static function (int $id) use ($c): void {
    $c->query("UPDATE aics_requests SET urgency_indicator = 'URGENT', urgency_basis = 'Gemini priority analysis found sufficient current medical urgency.', urgency_analyzed_at = NOW() WHERE id = {$id}");
};

try {
    putenv('AICS_AI_GATE_SCHEDULER=1');

    // 1. The background job analyses first, then releases: URGENT from the start, no later change.
    $first = $request();
    $apply($first, $analyseUrgent);
    before_release_assert($state($first) === 'released/passed/URGENT', 'The request was not released with the analysed priority: ' . $state($first));
    before_release_assert((int) $value("SELECT ai_gate_eta_days FROM aics_requests WHERE id = {$first}") === 1, 'Urgent should be 1 office day.');
    before_release_assert((int) $value("SELECT COUNT(*) FROM aics_request_notifications WHERE request_id = {$first} AND message_text LIKE '%1 office day%'") === 1, 'The first notice does not give the urgent processing time.');
    before_release_assert(aics_ai_gate_reconcile_priorities($c) === 0, 'The priority still changed after release.');

    // 2. A page load (no analysis allowed) waits for the background job instead of guessing.
    $waiting = $request();
    $apply($waiting);
    before_release_assert($state($waiting) === 'pending/prioritizing/-', 'A page load released the request before its priority was checked: ' . $state($waiting));
    before_release_assert((int) $value("SELECT COUNT(*) FROM aics_request_notifications WHERE request_id = {$waiting}") === 0, 'The applicant was told a priority before it was checked.');
    $progress = aics_ai_gate_applicant_progress($c, $waiting);
    before_release_assert(($progress['phase'] ?? '') === 'priority', 'The applicant progress does not say the priority is being checked.');
    $apply($waiting);
    before_release_assert($state($waiting) === 'pending/prioritizing/-', 'A second page load released it early.');
    $apply($waiting, $analyseUrgent);
    before_release_assert($state($waiting) === 'released/passed/URGENT', 'The background job did not release the waiting request: ' . $state($waiting));

    // 3. Never stuck: after the wait limit a page load releases with the default priority.
    $slow = $request();
    $apply($slow);
    $c->query("UPDATE aics_requests SET ai_gate_updated_at = NOW() - INTERVAL " . (AICS_AI_GATE_PRIORITY_WAIT_MINUTES + 1) . " MINUTE WHERE id = {$slow}");
    $apply($slow);
    before_release_assert($state($slow) === 'released/passed/HIGH', 'A request waited too long for its priority: ' . $state($slow));

    // 4. A failed analysis still releases (the background job updates the priority later).
    $failed = $request();
    $apply($failed, static function (): void { throw new RuntimeException('Gemini unavailable'); });
    before_release_assert($state($failed) === 'released/passed/HIGH', 'A failed analysis blocked the release: ' . $state($failed));

    // 5. Nothing to wait for: an analysis already saved, or not Medical Assistance.
    $saved = $request();
    $analyseUrgent($saved);
    $apply($saved);
    before_release_assert($state($saved) === 'released/passed/URGENT', 'A saved analysis was not used at once: ' . $state($saved));
    $burial = $request('burial');
    $apply($burial);
    before_release_assert($state($burial) === 'released/passed/STANDARD', 'A non-medical request waited for Smart Priority: ' . $state($burial));

    // 6. Without the background job (local XAMPP), page loads release as before.
    putenv('AICS_AI_GATE_SCHEDULER');
    $local = $request();
    $apply($local);
    before_release_assert($state($local) === 'released/passed/HIGH', 'Without a background job the request must not wait: ' . $state($local));

    // 7. The background job passes the analysis to the gate.
    $tool = (string) file_get_contents(__DIR__ . '/../tools/ai-gate-sync.php');
    before_release_assert(str_contains($tool, 'pswdo_analyse_request_priority($connection, $id);') && str_contains($tool, 'aics_ai_gate_sync($connection, $requestId, true, 500, $analyse)'), 'tools/ai-gate-sync.php does not analyse the priority before release.');
    // The analysis the background job runs saves what PSWDO shows.
    $viaJob = $request();
    $reference = (string) $value("SELECT reference_no FROM aics_requests WHERE id = {$viaJob}");
    $document = aics_smart_priority_document_file(['reference_no' => $reference, 'stored_name' => 'med.pdf', 'document_type' => 'med_cert']);
    file_put_contents((string) aics_smart_priority_cache_file([$document]), json_encode(['expires' => time() + 600, 'result' => ['urgent' => true, 'reason' => 'Dialysis for the current condition.', 'evidence' => ['Dialysis']]]));
    putenv('AICS_AI_GATE_SCHEDULER=1');
    $apply($viaJob, static fn (int $id) => pswdo_analyse_request_priority($c, $id));
    putenv('AICS_AI_GATE_SCHEDULER');
    before_release_assert($state($viaJob) === 'released/passed/URGENT', 'The background job analysis was not used for the release: ' . $state($viaJob));
    before_release_assert($value("SELECT CONCAT(r.urgency_indicator, '/', pr.urgency_indicator) FROM aics_requests r JOIN aics_request_prioritization pr ON pr.request_id = r.id WHERE r.id = {$viaJob}") === 'URGENT/URGENT', 'PSWDO does not show the same URGENT priority: ' . json_encode($c->query("SELECT r.urgency_indicator, r.urgency_basis, r.urgency_analyzed_at, pr.urgency_indicator AS pr_u, pr.priority_level FROM aics_requests r LEFT JOIN aics_request_prioritization pr ON pr.request_id = r.id WHERE r.id = {$viaJob}")->fetch_assoc()));
    $js = (string) file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js');
    before_release_assert(str_contains($js, 'data.phase === "priority"'), 'The applicant page does not show that the priority is being checked.');
} finally {
    putenv('AICS_AI_GATE_SCHEDULER');
    foreach ($uploadDirs as $dir) { @unlink($dir . '/med.pdf'); @rmdir($dir); }
    array_map('unlink', glob($cacheRoot . '/smart_priority/*') ?: []);
    @rmdir($cacheRoot . '/smart_priority');
    @rmdir($cacheRoot);
    $c->close();
}
echo "AICS AI priority before release checks passed.\n";
