<?php
declare(strict_types=1);

// Applicant SMS are in Tagalog with a greeting for the time they are sent, and
// finishing pre-screening (one or selected applicants) texts them to wait for
// the schedule.
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function tagalog_sms_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-10-22 ' . $time, new DateTimeZone('Asia/Manila'));
foreach ([
    '05:00' => 'Magandang umaga po', '11:59' => 'Magandang umaga po',
    '12:00' => 'Magandang tanghali po', '12:59' => 'Magandang tanghali po',
    '13:00' => 'Magandang hapon po', '17:59' => 'Magandang hapon po',
    '18:00' => 'Magandang gabi po', '23:30' => 'Magandang gabi po', '04:59' => 'Magandang gabi po',
] as $time => $greeting) {
    tagalog_sms_assert(pswdo_sms_greeting($at($time)) === $greeting, "At {$time} the SMS greeting is not \"{$greeting}\".");
}
tagalog_sms_assert(pswdo_sms_with_greeting('{greeting}, Ana.', $at('08:15')) === 'Magandang umaga po, Ana.', 'The greeting is not filled into the SMS.');

$passed = pswdo_default_sms_message('screening_passed', 'AICS-2026-0001', 'Ana Cruz');
tagalog_sms_assert($passed === "{greeting}, Ana Cruz.\n"
    . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Tapos na po at pumasa na sa further screening ang inyong AICS application.\n"
    . "Reference No.: AICS-2026-0001\n"
    . "Hintayin na lamang po ang aming text para sa petsa, oras at lugar ng pagkuha ng inyong tulong.\n"
    . "Maraming salamat po.", 'The Approve for Scheduling SMS does not say further screening passed and to wait for the schedule text.');
tagalog_sms_assert(!str_contains($passed, 'final interview'), 'The Approve for Scheduling SMS still mentions the final interview.');
tagalog_sms_assert(pswdo_normalize_sms_decision_status('screening_passed') === 'screening_passed', 'The pre-screening SMS is filed as another kind of message.');

foreach (['for_screening', 'screening_passed', 'incomplete_requirements', 'invalid_requirements', 'release_schedule', 'ready_for_release', 'missed_release'] as $status) {
    $message = pswdo_default_sms_message($status, 'AICS-1', 'Ana');
    tagalog_sms_assert(str_starts_with($message, '{greeting}, Ana.') && str_ends_with($message, 'Maraming salamat po.'), "The {$status} SMS does not greet and thank in Tagalog.");
    tagalog_sms_assert(preg_match('/\b(Good day|Thank you|Please|Your application)\b/', $message) !== 1, "The {$status} SMS still has English sentences.");
}
$release = pswdo_build_release_schedule_sms_message(['beneficiary_name' => 'Ana', 'reference_no' => 'AICS-1']);
tagalog_sms_assert(str_contains($release, "Petsa: itinakdang petsa\nOras: itinakdang oras\nLugar: PSWDO Office"), 'The release SMS is not in Tagalog.');

$script = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
preg_match_all('/\btext: "((?:\\\\.|[^"\\\\])*)"/', $script, $templates);
tagalog_sms_assert(count($templates[1]) >= 20, 'The SMS templates could not be read.');
foreach ($templates[1] as $template) {
    tagalog_sms_assert(str_starts_with($template, '{greeting}, {applicant}.') && preg_match('/\b(Good day|Thank you|Please)\b/', $template) !== 1, 'An SMS template is not in Tagalog or does not greet the applicant: ' . substr($template, 0, 60));
}
tagalog_sms_assert(str_contains($script, 'function smsGreeting(') && str_contains($script, '.replaceAll("{greeting}", smsGreeting())'), 'The SMS preview does not fill in the greeting.');
// The Screening Queue Mass Notice opens on "further screening passed, wait for the schedule text", not the approval notice.
preg_match('/for_screening: \[\s*\{\s*id: "([a-z_]+)"/', $script, $firstScreening);
preg_match('/id: "screening_schedule_soon",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/', $script, $scheduleSoon);
tagalog_sms_assert(($firstScreening[1] ?? '') === 'screening_schedule_soon' && str_contains(stripcslashes($scheduleSoon[1] ?? ''), "Tapos na po at pumasa na sa further screening ang inyong AICS application.\nReference No.: {reference}\nHintayin na lamang po ang aming text para sa petsa, oras at lugar ng pagkuha ng inyong tulong."), 'The Mass Notice does not default to telling applicants further screening passed and to wait for the schedule.');

// Rejecting texts the applicant, in Tagalog, with the reason PSWDO wrote.
$rejection = pswdo_rejection_sms_message('AICS-2026-0001', 'Reine Bustillo', 'Hindi tugma ang pangalan sa valid ID');
tagalog_sms_assert($rejection === "{greeting}, Reine Bustillo.\n"
    . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Ikinalulungkot po naming ipaalam na hindi naaprubahan ang inyong AICS application.\n"
    . "Reference No.: AICS-2026-0001\n"
    . "Dahilan: Hindi tugma ang pangalan sa valid ID.\n"
    . "Para sa paglilinaw o karagdagang tulong, maaari po kayong pumunta sa PSWDO Office, Lunes hanggang Huwebes, 8:00 AM - 11:00 AM at 1:00 PM - 4:00 PM.\n"
    . "Maraming salamat po.", 'The rejection SMS is not the Tagalog notice with the reason.');
preg_match('/id: "reject_default",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/', $script, $rejectTemplate);
tagalog_sms_assert(str_replace(['{applicant}', '{reference}', '{rejection_reason}'], ['Reine Bustillo', 'AICS-2026-0001', 'Hindi tugma ang pangalan sa valid ID.'], stripcslashes($rejectTemplate[1] ?? '')) === $rejection, 'The Rejection Notice template differs from the SMS sent on reject.');
tagalog_sms_assert(pswdo_normalize_sms_decision_status('rejection_notice') === 'rejection_notice', 'The rejection SMS is filed as another kind of message.');
$workflowSource = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/workflow-action.php');
tagalog_sms_assert(str_contains($workflowSource, "pswdo_send_sms_to_applicant_action(\$connection, \$requestId, 'rejection_notice', \$rejectionSms, \$accountId)"), 'Rejecting does not text the applicant.');
$managementPage = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
tagalog_sms_assert(str_contains($managementPage, 'id="reject-sms-preview"') && str_contains($script, 'function showRejectSmsPreview()'), 'The Reject window does not show the SMS before it is sent.');

$library = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
tagalog_sms_assert(substr_count($library, 'pswdo_sms_with_greeting(') >= 3, 'An SMS can be sent without its greeting filled in.');
tagalog_sms_assert(str_contains($library, "pswdo_send_sms_to_applicant_action(\$connection, \$requestId, 'screening_passed', '', \$accountId)"), 'Finishing pre-screening does not text the applicant.');

$workflow = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/workflow-action.php');
tagalog_sms_assert(str_contains($workflow, 'pswdo_finish_prescreening_action(') && str_contains($workflow, "\$actionType === 'bulk_approve_for_release'") && str_contains($workflow, 'pswdo_bulk_finish_prescreening_action('), 'The Screening Queue approvals are not wired to the SMS.');

$queue = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/prescreening-queue.php');
tagalog_sms_assert(str_contains($queue, 'id="select-all-screening"') && str_contains($queue, 'class="js-screening-select"') && str_contains($queue, 'id="bulk-approve-release-btn"'), 'Applicants cannot be selected for scheduling together.');
tagalog_sms_assert(!str_contains($queue, 'approve-release-sms-preview') && str_contains($queue, 'Approve for Scheduling?') && str_contains($queue, 'Further screening passed.') && str_contains($queue, 'id="approve-release-name"'), 'Approve for Scheduling is not a plain confirmation naming the applicant.');
// One-by-one and the Mass Notice send the same "further screening passed" SMS; the pre-screening text is gone.
tagalog_sms_assert(str_replace(['{applicant}', '{reference}'], ['Ana Cruz', 'AICS-2026-0001'], stripcslashes($scheduleSoon[1] ?? '')) === $passed, 'The Mass Notice and Approve for Scheduling send different texts.');
tagalog_sms_assert(!str_contains($script, 'pumasa ang pre-screening') && !str_contains($library, 'pumasa ang pre-screening'), 'The pre-screening SMS text is still offered.');

echo "PSWDO Tagalog SMS and screening checks passed.\n";
