<?php
declare(strict_types=1);

// Expected processing time follows Smart Priority: URGENT 1 office day, HIGH
// 1 to 2, STANDARD 1 to 3. The PSWDO office is open Monday to Thursday, so a
// request released on Thursday (or Friday to Sunday) is processed from Monday.
require_once __DIR__ . '/../includes/ai-submission-gate.php';

function eta_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$manila = new DateTimeZone('Asia/Manila');
$at = static fn (string $when): DateTimeImmutable => new DateTimeImmutable($when, $manila);
$day = static fn (DateTimeImmutable $date): string => $date->format('D Y-m-d');

eta_assert(aics_ai_gate_eta_days('URGENT') === 1 && aics_ai_gate_eta_days('HIGH') === 2 && aics_ai_gate_eta_days('STANDARD') === 3, 'URGENT 1, HIGH 2, STANDARD 3 office days.');
eta_assert(aics_ai_gate_eta_days('unknown') === 3, 'Unknown priority uses STANDARD.');
putenv('AICS_ETA_HIGH_DAYS=4'); eta_assert(aics_ai_gate_eta_days('HIGH') === 4, 'Env override still works.'); putenv('AICS_ETA_HIGH_DAYS');

// 2026-10-21 is a Wednesday.
$wednesday = $at('2026-10-21 09:30');
eta_assert($day(aics_ai_gate_office_day_after($wednesday, 1)) === 'Thu 2026-10-22', 'Wednesday + 1 office day = Thursday.');
eta_assert($day(aics_ai_gate_office_day_after($wednesday, 2)) === 'Mon 2026-10-26', 'Wednesday + 2 office days skips Friday to Sunday.');
eta_assert($day(aics_ai_gate_office_day_after($wednesday, 3)) === 'Tue 2026-10-27', 'Wednesday + 3 office days = Tuesday.');
$thursday = $at('2026-10-22 16:45');
eta_assert($day(aics_ai_gate_office_day_after($thursday, 1)) === 'Mon 2026-10-26', 'Thursday urgent is processed on Monday.');
eta_assert($day(aics_ai_gate_office_day_after($thursday, 3)) === 'Wed 2026-10-28', 'Thursday standard ends Wednesday.');
foreach (['2026-10-23 08:00', '2026-10-24 12:00', '2026-10-25 23:59'] as $closed) {
    eta_assert($day(aics_ai_gate_office_day_after($at($closed), 1)) === 'Mon 2026-10-26', "Released while the office is closed ({$closed}): Monday.");
}
eta_assert($day(aics_ai_gate_office_day_after($at('2026-10-19 10:00'), 1)) === 'Tue 2026-10-20', 'Monday + 1 office day = Tuesday.');

eta_assert(aics_ai_gate_eta_span(1) === '1 office day' && aics_ai_gate_eta_span(2) === '1–2 office days' && aics_ai_gate_eta_span(3) === '1–3 office days', 'Spans read 1, 1–2 and 1–3 office days.');
eta_assert(aics_ai_gate_eta_text(1, $thursday) === '1 office day (by Monday, Oct 26)', 'Urgent text names the day.');
eta_assert(aics_ai_gate_eta_text(2, $wednesday) === '1–2 office days (by Monday, Oct 26)', 'High text names the last day.');

// Notices.
$urgent = aics_ai_gate_applicant_message('ai_check_passed', 'AICS-2026-0001', [], 1, 'URGENT', $thursday);
eta_assert($urgent === 'AICS AI checked the documents for request AICS-2026-0001. PSWDO will now review it. Expected processing time: 1 office day (by Monday, Oct 26). Your request was marked urgent.', 'Urgent passed copy.');
eta_assert(aics_ai_gate_applicant_message('ai_check_passed', 'R', [], 3, 'STANDARD', $wednesday) === 'AICS AI checked the documents for request R. PSWDO will now review it. Expected processing time: 1–3 office days (by Tuesday, Oct 27).', 'Standard passed copy.');
eta_assert(aics_ai_gate_applicant_message('ai_check_admin_release', 'R', [], 2, 'HIGH', $wednesday) === 'Request R is now with PSWDO for review. Expected processing time: 1–2 office days (by Monday, Oct 26).', 'Admin release copy.');
eta_assert(aics_ai_gate_applicant_message('ai_check_urgent', 'R', [], 1, 'URGENT', $wednesday) === 'AICS AI marked request R as urgent. Expected processing time: 1 office day (by Thursday, Oct 22).', 'Marked urgent copy.');
eta_assert(aics_ai_gate_applicant_message('ai_priority_update', 'R', [], 2, 'HIGH', $wednesday) === 'AICS AI updated the priority of request R to High. Expected processing time: 1–2 office days (by Monday, Oct 26).', 'Priority update copy.');
eta_assert(aics_ai_gate_stage_title('ai_priority_update') === 'AICS AI: Priority updated', 'Priority update title.');

// Status summary for the applicant page and PSWDO.
$summary = aics_ai_gate_eta_summary('HIGH', '2026-10-22 16:45:00', $at('2026-10-23 10:00'));
eta_assert($summary !== null && $summary['priority'] === 'HIGH' && $summary['priority_label'] === 'High' && $summary['span'] === '1–2 office days', 'Summary names the priority and span.');
eta_assert($summary['due_label'] === 'Tuesday, Oct 27' && $summary['due_short'] === 'Tue, Oct 27' && $summary['overdue'] === false, 'Summary counts office days from the release.');
eta_assert(aics_ai_gate_eta_summary('URGENT', '2026-10-21 09:00:00', $at('2026-10-23 08:00'))['overdue'] === true, 'Past the expected day is overdue.');
eta_assert(aics_ai_gate_eta_summary('URGENT', '2026-10-21 09:00:00', $at('2026-10-22 17:00'))['overdue'] === false, 'The expected day itself is not overdue.');
eta_assert(aics_ai_gate_eta_summary('', '2026-10-21 09:00:00') === null && aics_ai_gate_eta_summary('HIGH', null) === null && aics_ai_gate_eta_summary('HIGH', 'bad') === null, 'No summary without a priority and release time.');

echo "AICS AI priority ETA checks passed.\n";
