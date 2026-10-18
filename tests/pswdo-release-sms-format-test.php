<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$expected = "Good day, Maria Santos.\n"
    . "This is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Your application, AICS-2026-123456, is scheduled for assistance release on:\n"
    . "Date: October 5, 2026\n"
    . "Time: 9:00 AM\n"
    . "Venue: PSWDO Office\n"
    . "Please arrive on time and bring your valid ID and required documents.\n"
    . "Thank you.";

$item = [
    'beneficiary_name' => 'Maria Santos',
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

echo "PSWDO release SMS format test passed.\n";
