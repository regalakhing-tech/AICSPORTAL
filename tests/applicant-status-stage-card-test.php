<?php
declare(strict_types=1);

// After PSWDO decides, the applicant's status card says what to do next for
// each stage, the "checked by PSWDO" progress is gone (PSWDO no longer checks
// documents one by one), and the cooldown notice is short on phones.
function stage_card_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$page = (string) file_get_contents(__DIR__ . '/../Applicants/php_files/application-status.php');
$css = (string) file_get_contents(__DIR__ . '/../Applicants/css files/applicant-pages.css');
$dict = (string) file_get_contents(__DIR__ . '/../Applicants/js files/applicant-i18n-dict.js');

// One card per stage, in place of the expected processing time.
stage_card_assert(str_contains($page, 'function status_stage_notice(array $req): ?array'), 'The status page has no stage card.');
stage_card_assert(strpos($page, '($stageNotice = status_stage_notice($req)) !== null') < strpos($page, "is_array(\$req['gate_eta'] ?? null)"), 'The expected processing time still shows after PSWDO decides.');
foreach ([
    'Approved for further screening' => 'Visit the PSWDO Office',
    'Further screening passed' => 'Wait for your release schedule',
    'Release schedule missed' => 'Wait for a new schedule',
] as $label => $title) {
    stage_card_assert(str_contains($page, "'label' => '{$label}', 'title' => '{$title}'"), "The \"{$label}\" stage card is missing.");
}
stage_card_assert(str_contains($page, "'Release scheduled'") && str_contains($page, "'Place: ' ."), 'A scheduled release does not show its date and place.');
stage_card_assert(str_contains($page, 'Any office day: Monday to Thursday, 8:00 AM - 11:00 AM or 1:00 PM - 4:00 PM.'), 'Approved applicants are not told the office hours.');

// Every stage card text is translated into Tagalog and Hiligaynon.
foreach (['Approved for further screening', 'Visit the PSWDO Office', 'Bring the original copies of your requirements.', 'Further screening passed', 'Wait for your release schedule',
          'PSWDO will text you the date, time and place to receive your assistance.', 'Release scheduled', 'Your assistance is ready',
          'Bring your valid ID and your requirements. Please arrive on time.', 'Release schedule missed', 'Wait for a new schedule',
          'PSWDO will text you a new date, time and place.'] as $text) {
    stage_card_assert(str_contains($dict, '"' . $text . '": ['), "\"{$text}\" has no Tagalog/Hiligaynon translation.");
}
stage_card_assert(str_contains($dict, "[/^Place: (.+)$/, ['Lugar: {1}', 'Lugar: {1}']]"), 'The release place line is not translated.');

// No more "checked by PSWDO" document progress.
stage_card_assert(!str_contains($page, 'Overall Document Progress') && !str_contains($page, 'docs checked') && !str_contains($page, 'checked by PSWDO'), 'The status page still shows document checks by PSWDO.');
stage_card_assert(str_contains($page, 'required documents uploaded'), 'The checklist does not say how many documents were uploaded.');

// Short cooldown notice on phones.
stage_card_assert(str_contains($page, '<span class="cooldown-policy">Under PSWDO policy, an annual interval applies between assistance grants.</span>'), 'The cooldown policy sentence cannot be hidden on phones.');
stage_card_assert(str_contains($css, "body .status-page .cooldown-policy,\n  body .status-page .cooldown-tag { display: none; }") || str_contains($css, "body .status-page .cooldown-policy,\r\n  body .status-page .cooldown-tag { display: none; }"), 'The cooldown notice is not shortened on phones.');

echo "Applicant status stage card checks passed.\n";
