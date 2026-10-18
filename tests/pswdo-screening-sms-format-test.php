<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$expected = "Good day, Nicacio Bustillo.\n"
    . "This is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Your AICS application, AICS-2026-58AF70, has been approved for further screening.\n"
    . "Please proceed to:\n"
    . "Office: PSWDO Office\n"
    . "Purpose: Further Screening\n"
    . "Please bring your valid ID and required documents.\n"
    . "Thank you.";

if (pswdo_default_sms_message('for_screening', 'AICS-2026-58AF70', 'Nicacio Bustillo') !== $expected) {
    throw new RuntimeException('Screening SMS fallback does not match the requested format.');
}

$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
if (!is_string($script) || !preg_match('/id: "screening_default",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/', $script, $matches)) {
    throw new RuntimeException('Default screening SMS template could not be read.');
}
$template = stripcslashes($matches[1]);
$rendered = str_replace(
    ['{beneficiary}', '{reference}'],
    ['Nicacio Bustillo', 'AICS-2026-58AF70'],
    $template
);
if ($rendered !== $expected) {
    throw new RuntimeException('Screening queue SMS preview does not match the requested format.');
}

$personalized = pswdo_apply_prescreening_sms_tokens($template, [
    'beneficiary_name' => 'Nicacio Bustillo',
    'reference_no' => 'AICS-2026-58AF70',
]);
if ($personalized !== $expected) {
    throw new RuntimeException('Bulk screening SMS is not personalized for each recipient.');
}

$dataLib = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
if (!is_string($dataLib) || !str_contains($dataLib, "pswdo_default_sms_message('for_screening', \$referenceNo, \$beneficiaryName)")) {
    throw new RuntimeException('Approval notification does not use the screening SMS format.');
}

echo "PSWDO screening SMS format test passed.\n";
