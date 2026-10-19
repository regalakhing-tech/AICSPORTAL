<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/ai-submission-gate.php';

function gate_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$doc = fn (string $status, int $attempts = 1, int $id = 1) => ['document_type' => 'med_cert', 'status' => $status, 'review_id' => $id, 'attempt_count' => $attempts, 'is_terminal' => $status !== 'processing'];
$p = fn (array $docs) => ['total' => count($docs), 'documents' => $docs];
$d = fn (array $docs) => aics_ai_gate_decide($p($docs), 3);

gate_assert($d([])['state'] === 'released' && $d([])['reason'] === 'no_documents', 'no verifiable documents releases');
gate_assert($d([$doc('likely_valid'), $doc('completed')])['reason'] === 'passed', 'all valid passes');
$held = $d([$doc('likely_valid'), $doc('needs_reupload', 1, 7), $doc('processing')]);
gate_assert($held['state'] === 'held' && $held['blocking'][0]['review_id'] === 7, 'one blocking doc holds even while others run');
gate_assert($d([$doc('flagged_inconsistency'), $doc('failed', 1)])['state'] === 'held', 'blocking beats failure');
$retry = $d([$doc('failed', 2, 9), $doc('likely_valid')]);
gate_assert($retry['state'] === 'pending' && $retry['reason'] === 'ai_failed' && $retry['retry'][0]['review_id'] === 9, 'failed with attempts left is retried');
gate_assert($d([$doc('processing', 0)])['reason'] === 'checking', 'still checking');
gate_assert($d([$doc('failed', 3), $doc('processing')])['reason'] === 'checking', 'waits for other documents before giving up');
$gaveUp = $d([$doc('failed', 3, 4), $doc('likely_valid')]);
gate_assert($gaveUp['state'] === 'admin_review' && $gaveUp['reason'] === 'ai_unavailable' && $gaveUp['uncertain'][0]['review_id'] === 4, 'exhausted retries go to admin');
gate_assert($d([$doc('needs_staff_review'), $doc('likely_valid')])['reason'] === 'uncertain', 'uncertain goes to admin, never PSWDO');
foreach ([[$doc('failed', 1)], [$doc('failed', 3)], [$doc('processing')], [$doc('needs_staff_review')]] as $docs) {
    gate_assert($d($docs)['state'] !== 'released', 'only a pass releases');
}
gate_assert(aics_ai_gate_decide($p([$doc('failed', 4)]))['reason'] === 'ai_failed', 'default attempt limit is 5');
putenv('AICS_AI_GATE_MAX_ATTEMPTS=2'); gate_assert(aics_ai_gate_decide($p([$doc('failed', 2)]))['reason'] === 'ai_unavailable', 'attempt limit env override'); putenv('AICS_AI_GATE_MAX_ATTEMPTS');
gate_assert(aics_ai_gate_retry_backoff_minutes(1) === 1 && aics_ai_gate_retry_backoff_minutes(2) === 5 && aics_ai_gate_retry_backoff_minutes(3) === 15 && aics_ai_gate_retry_backoff_minutes(4) === 30 && aics_ai_gate_retry_backoff_minutes(9) === 30, 'retry backoff');
gate_assert(aics_ai_gate_retry_backoff_minutes(0) === 0, 'a launch that never ran retries at once');

gate_assert(aics_ai_gate_eta_days('URGENT') === 1 && aics_ai_gate_eta_days('HIGH') === 3 && aics_ai_gate_eta_days('STANDARD') === 7, 'default ETA days');
gate_assert(aics_ai_gate_eta_days('unknown') === 7, 'unknown indicator uses STANDARD');
putenv('AICS_ETA_HIGH_DAYS=2'); gate_assert(aics_ai_gate_eta_days('HIGH') === 2, 'env override'); putenv('AICS_ETA_HIGH_DAYS');

$hold = aics_ai_gate_applicant_message('ai_check_hold', 'AICS-2026-0001', [$doc('needs_reupload')], 0, 'STANDARD');
gate_assert($hold === 'AICS AI found a problem with 1 document(s) in request AICS-2026-0001: Medical Abstract or Medical Certificate (blurry, cut off, or incomplete). Upload a clear replacement in Manage Applications. It will be checked again automatically.', 'hold copy');
gate_assert(str_contains(aics_ai_gate_applicant_message('ai_check_hold', 'X', [$doc('needs_staff_review')], 0, 'STANDARD'), 'Medical Abstract or Medical Certificate (needs a clearer copy)'), 'admin re-upload reason');
gate_assert(str_contains(aics_ai_gate_applicant_message('ai_check_hold', 'X', [$doc('flagged_inconsistency')], 0, 'STANDARD'), '(does not match your application or looks edited)'), 'inconsistency reason');
gate_assert(aics_ai_gate_applicant_message('ai_check_admin_review', 'AICS-2026-0001', [], 0, 'STANDARD') === 'Request AICS-2026-0001 is being reviewed before it goes to PSWDO. You will be notified when it moves forward.', 'admin review copy');
$urgent = aics_ai_gate_applicant_message('ai_check_passed', 'AICS-2026-0001', [], 1, 'URGENT');
gate_assert($urgent === 'AICS AI checked the documents for request AICS-2026-0001. PSWDO will now review it. Expected processing time: about 1 day(s). Your request was marked urgent.', 'urgent copy');
gate_assert(aics_ai_gate_applicant_message('ai_check_passed', 'R', [], 7, 'STANDARD') === 'AICS AI checked the documents for request R. PSWDO will now review it. Expected processing time: about 7 day(s).', 'standard passed copy');
gate_assert(aics_ai_gate_applicant_message('ai_check_admin_release', 'AICS-2026-0001', [], 7, 'STANDARD') === 'Request AICS-2026-0001 is now with PSWDO for review. Expected processing time: about 7 day(s).', 'admin release copy');
$many = array_fill(0, 40, ['document_type' => 'billing_statement', 'status' => 'flagged_inconsistency', 'review_id' => 1]);
gate_assert(mb_strlen(aics_ai_gate_applicant_message('ai_check_hold', 'X', $many, 0, 'STANDARD')) <= 600, 'message fits VARCHAR(600)');
gate_assert(stripos($hold . $urgent, 'gemini') === false, 'branding');
gate_assert(aics_ai_gate_visible_sql('rq') === "rq.ai_gate_status = 'released'", 'visibility fragment');
gate_assert(aics_ai_gate_visible_sql() === "r.ai_gate_status = 'released'", 'default alias');

echo "PASS\n";
