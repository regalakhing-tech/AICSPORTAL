<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$expected = "{greeting}, Maria Santos.\n"
    . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Naka-schedule na po ang pagkuha ng tulong para sa inyong AICS application.\n"
    . "Reference No.: AICS-2026-123456\n"
    . "Petsa: October 5, 2026\n"
    . "Oras: 9:00 AM\n"
    . "Lugar: PSWDO Office\n"
    . "Pakidating po sa takdang oras at dalhin ang inyong valid ID at mga requirements.\n"
    . "Maraming salamat po.";

$item = [
    'applicant_name' => 'Maria Santos',
    'beneficiary_name' => 'Jose Santos',
    'reference_no' => 'AICS-2026-123456',
    'scheduled_date_label' => 'October 5, 2026',
    'scheduled_time_label' => '9:00 AM',
    'venue' => 'PSWDO Office',
    'release_batch' => 'Batch 1',
];

if (pswdo_build_release_schedule_sms_message($item) !== $expected) {
    throw new RuntimeException('Bulk release SMS fallback does not match the requested format.');
}

$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
if (!is_string($script)) {
    throw new RuntimeException('PSWDO page script could not be read.');
}

foreach (['release_schedule_default', 'release_schedule_notice'] as $id) {
    $pattern = '/id: "' . preg_quote($id, '/') . '",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/';
    if (!preg_match($pattern, $script, $matches)) {
        throw new RuntimeException("Release SMS template {$id} is missing.");
    }
    $template = stripcslashes($matches[1]);
    if (pswdo_apply_release_schedule_sms_tokens($template, $item) !== $expected) {
        throw new RuntimeException("Release SMS template {$id} does not match the requested format.");
    }
}

// A mass notice carries each applicant's own saved schedule, including the
// 1:00 PM time of Batch 2, never a date or time typed once for everyone.
$batchTwo = ['scheduled_time_label' => pswdo_release_time_label('13:00:00'), 'scheduled_date_label' => 'October 26, 2026'] + $item;
$filled = pswdo_apply_release_schedule_sms_tokens(stripcslashes($matches[1]), $batchTwo);
if (!str_contains($filled, "Petsa: October 26, 2026\nOras: 01:00 PM\n")) {
    throw new RuntimeException('A mass release notice does not use the applicant\'s own date and batch time.');
}
$library = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
if (!str_contains($library, "\$item['scheduled_time_label'] = pswdo_release_time_label(\$newTime);")) {
    throw new RuntimeException('A Batch 2 applicant is texted the morning time.');
}
if (str_contains($script, '.replaceAll("{schedule_date}", formatMassDateLabel') || !str_contains($script, 'sms-mass-placeholder-note')) {
    throw new RuntimeException('The mass notice still writes one date and time into everyone\'s SMS.');
}

echo "PSWDO release SMS format test passed.\n";
