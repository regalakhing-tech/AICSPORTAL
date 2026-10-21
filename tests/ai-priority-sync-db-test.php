<?php
declare(strict_types=1);

// The applicant's priority and expected processing time always follow the
// priority PSWDO sees. Before, the applicant side could only be raised to
// URGENT: after an edit PSWDO showed HIGH while the applicant still saw URGENT.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function sync_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

sync_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');
$cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-priority-sync-' . bin2hex(random_bytes(4));
mkdir($cacheRoot, 0700, true);
putenv('AICS_EXTERNAL_AI_STATE_ROOT=' . $cacheRoot);

aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_ai_gate_ensure_schema($c);
aics_ensure_urgency_indicator_columns($c);

$value = static fn (string $sql): mixed => $c->query($sql)?->fetch_row()[0] ?? null;
$notices = static fn (int $id, string $like): int => (int) $value("SELECT COUNT(*) FROM aics_request_notifications WHERE request_id = {$id} AND message_text LIKE '{$like}'");

/** A released medical request for a senior citizen; returns its id. */
$request = static function (array $set = []) use ($c): int {
    static $n = 0;
    $n++;
    $reference = 'SYNC-' . $n . '-' . bin2hex(random_bytes(2));
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status, ai_gate_status, ai_gate_priority, ai_gate_eta_days, ai_gate_updated_at) VALUES ('{$reference}', CURDATE(), 'Ana', 'Cruz', 70, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 70, 'X', 'Sibalom', 'female', '[\"medical\"]', '[\"senior_citizen\"]', 'unclaimed', 'pending', 'released', 'URGENT', 1, NOW() - INTERVAL 1 DAY)");
    $id = (int) $c->insert_id;
    $c->query("INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT {$id}, id FROM aics_assistance_types WHERE assistance_code = 'medical'");
    $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size, uploaded_at) VALUES ({$id}, 'med_cert', 'med.pdf', 'med.pdf', 'x', 'application/pdf', 60, NOW() - INTERVAL 2 HOUR)");
    $fields = [];
    foreach ($set as $column => $sql) $fields[] = "{$column} = {$sql}";
    if ($fields !== []) $c->query('UPDATE aics_requests SET ' . implode(', ', $fields) . " WHERE id = {$id}");
    return $id;
};
$analysed = static fn (string $indicator): array => ['urgency_indicator' => "'{$indicator}'", 'urgency_basis' => "'Saved AICS AI findings.'", 'urgency_analyzed_at' => 'NOW() - INTERVAL 1 HOUR'];

try {
    // 1. Applicant URGENT, PSWDO HIGH after an analysis of the edited documents: the applicant follows PSWDO.
    $edited = $request($analysed('HIGH'));
    sync_assert(aics_ai_gate_reconcile_priorities($c) >= 1, 'A mismatched priority is fixed.');
    sync_assert($value("SELECT CONCAT(ai_gate_priority, '/', ai_gate_eta_days) FROM aics_requests WHERE id = {$edited}") === 'HIGH/2', 'Applicant priority becomes HIGH, 1–2 office days.');
    sync_assert($notices($edited, '%updated the priority of request % to High. Expected processing time: 1–2 office days (by %).') === 1, 'The applicant is told the new priority.');
    sync_assert((int) $value("SELECT COUNT(*) FROM aics_ai_gate_events WHERE request_id = {$edited} AND event_type = 'ai_priority_update'") === 1, 'The change is in the admin log.');
    sync_assert((int) $value("SELECT TIMESTAMPDIFF(MINUTE, ai_gate_updated_at, NOW()) FROM aics_requests WHERE id = {$edited}") < 2, 'Expected time counts from the change.');
    aics_ai_gate_reconcile_priorities($c);
    sync_assert($notices($edited, '%updated the priority%') === 1, 'No second notice.');

    // 2. Raised again later: one urgent notice.
    sync_assert(aics_ai_gate_sync_priority($c, $edited, 'URGENT') === true, 'A later urgent result raises it again.');
    sync_assert($value("SELECT CONCAT(ai_gate_priority, '/', ai_gate_eta_days) FROM aics_requests WHERE id = {$edited}") === 'URGENT/1', 'Urgent is 1 office day.');
    sync_assert($notices($edited, '%marked request % as urgent. Expected processing time: 1 office day (by %).') === 1, 'Urgent notice.');
    sync_assert(aics_ai_gate_sync_priority($c, $edited, 'URGENT') === false && aics_ai_gate_sync_priority($c, $edited, 'REQUIRES_STAFF_REVIEW') === false, 'Same or unknown priority changes nothing.');

    // 3. Left alone: requests from before the gate, not yet with PSWDO, or already finished.
    $legacy = $request($analysed('HIGH') + ['ai_gate_updated_at' => 'NULL']);
    $held = $request($analysed('HIGH') + ['ai_gate_status' => "'held'"]);
    $finished = $request($analysed('HIGH') + ['request_status' => "'released'"]);
    aics_ai_gate_reconcile_priorities($c);
    foreach ([$legacy, $held, $finished] as $id) {
        sync_assert($value("SELECT ai_gate_priority FROM aics_requests WHERE id = {$id}") === 'URGENT' && $notices($id, '%') === 0, "Request {$id} is not touched.");
    }

    // 4. Edited after the analysis: not trusted until AICS AI checks it again.
    $stale = $request($analysed('HIGH'));
    aics_ai_gate_log_event($c, $stale, 'applicant_edit', null, null, 'Applicant edited the application.', null);
    aics_ai_gate_reconcile_priorities($c);
    sync_assert($value("SELECT ai_gate_priority FROM aics_requests WHERE id = {$stale}") === 'URGENT', 'A stale analysis does not change the applicant priority.');
    $needing = array_map(static fn (array $row): int => (int) $row['id'], pswdo_fetch_requests_needing_urgency_analysis($c, 50));
    sync_assert(in_array($stale, $needing, true) && !in_array($edited, $needing, true), 'An edit after the analysis is analysed again; a fresh analysis is not.');

    // 5. Stored expected days follow the current rules (they were 3 and 7 days before).
    $old = $request($analysed('STANDARD') + ['ai_gate_priority' => "'STANDARD'", 'ai_gate_eta_days' => '7']);
    aics_ai_gate_reconcile_priorities($c);
    sync_assert((int) $value("SELECT ai_gate_eta_days FROM aics_requests WHERE id = {$old}") === 3 && $notices($old, '%') === 0, 'Old stored days are corrected without a notice.');

    // 6. A new analysis saved by PSWDO scoring updates the applicant at once.
    $scored = $request(['ai_gate_priority' => "'HIGH'", 'ai_gate_eta_days' => '2']);
    $reference = (string) $value("SELECT reference_no FROM aics_requests WHERE id = {$scored}");
    $uploadDir = __DIR__ . '/../Applicants/uploads/' . $reference;
    mkdir($uploadDir, 0775, true);
    file_put_contents($uploadDir . '/med.pdf', "%PDF-1.4\nScheduled surgery for the current condition.\n%%EOF\n");
    $document = aics_smart_priority_document_file(['reference_no' => $reference, 'stored_name' => 'med.pdf', 'document_type' => 'med_cert']);
    file_put_contents((string) aics_smart_priority_cache_file([$document]), json_encode(['expires' => time() + 600, 'result' => ['urgent' => true, 'reason' => 'Scheduled surgery for the current condition.', 'evidence' => ['Scheduled surgery']]]));
    pswdo_refresh_request_scoring($c, ['id' => $scored, 'client_categories_json' => '["senior_citizen"]', 'assistance_types_json' => '["medical"]', 'assistance_code' => 'medical'], null);
    sync_assert($value("SELECT CONCAT(urgency_indicator, '/', ai_gate_priority, '/', ai_gate_eta_days) FROM aics_requests WHERE id = {$scored}") === 'URGENT/URGENT/1', 'PSWDO and the applicant both show URGENT.');

    // 7. Release uses the saved PSWDO priority when there is one.
    $pending = $request($analysed('URGENT') + ['ai_gate_status' => "'pending'", 'ai_gate_priority' => 'NULL', 'ai_gate_eta_days' => 'NULL']);
    $row = aics_ai_gate_request_row($c, $pending);
    sync_assert(aics_ai_gate_release($c, $row, 'pending', 'passed', 'ai_check_passed', 'ai_release', null), 'Released.');
    sync_assert($value("SELECT CONCAT(ai_gate_priority, '/', ai_gate_eta_days) FROM aics_requests WHERE id = {$pending}") === 'URGENT/1', 'Release keeps the saved urgent priority.');

    // 8. An edit keeps the last priority while AICS AI checks the request again.
    $save = (string) file_get_contents(__DIR__ . '/../Applicants/php_files/save-request.php');
    $persist = substr($save, (int) strpos($save, 'function persist_structured_priority_and_urgency('), 2600);
    sync_assert(str_contains($persist, 'urgency_basis = NULL') && str_contains($persist, 'urgency_analyzed_at = NULL'), 'An edit clears the saved analysis so it runs again.');
    sync_assert(str_contains($persist, "['URGENT', 'HIGH', 'STANDARD']"), 'An edit keeps the last AICS AI priority until the new check.');
    $tool = (string) file_get_contents(__DIR__ . '/../tools/smart-priority-refresh.php');
    sync_assert(str_contains($tool, 'aics_ai_gate_reconcile_priorities('), 'The background job fixes mismatched priorities.');
} finally {
    if (isset($uploadDir)) { @unlink($uploadDir . '/med.pdf'); @rmdir($uploadDir); }
    array_map('unlink', glob($cacheRoot . '/smart_priority/*') ?: []);
    @rmdir($cacheRoot . '/smart_priority');
    @rmdir($cacheRoot);
    $c->close();
}
echo "AICS AI priority sync checks passed.\n";
