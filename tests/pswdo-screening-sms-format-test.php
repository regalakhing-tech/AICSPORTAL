<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$expected = "{greeting}, Nicacio Bustillo.\n"
    . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Aprubado na po ang inyong AICS application.\n"
    . "Reference No.: AICS-2026-58AF70\n"
    . "Maaari na po kayong pumunta sa PSWDO Office sa anumang araw ng opisina:\n"
    . "Lunes hanggang Huwebes, 8:00 AM - 11:00 AM at 1:00 PM - 4:00 PM.\n"
    . "Pakidala po ang inyong valid ID at ang orihinal na kopya ng inyong mga requirements.\n"
    . "Maraming salamat po.";

if (pswdo_default_sms_message('for_screening', 'AICS-2026-58AF70', 'Nicacio Bustillo') !== $expected) {
    throw new RuntimeException('Screening SMS fallback does not match the requested format.');
}

$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
if (!is_string($script) || !preg_match('/id: "screening_default",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/', $script, $matches)) {
    throw new RuntimeException('Default screening SMS template could not be read.');
}
$template = stripcslashes($matches[1]);
$rendered = str_replace(
    ['{applicant}', '{reference}'],
    ['Nicacio Bustillo', 'AICS-2026-58AF70'],
    $template
);
if ($rendered !== $expected) {
    throw new RuntimeException('Screening queue SMS preview does not match the requested format.');
}

// The SMS goes to the applicant's phone, so it greets the applicant, not the beneficiary.
$personalized = pswdo_apply_prescreening_sms_tokens($template, [
    'applicant_name' => 'Nicacio Bustillo',
    'beneficiary_name' => 'Ruchel R. Bustillo',
    'reference_no' => 'AICS-2026-58AF70',
]);
if ($personalized !== $expected) {
    throw new RuntimeException('Bulk screening SMS is not personalized for each applicant.');
}

$dataLib = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
if (!is_string($dataLib) || !str_contains($dataLib, "pswdo_default_sms_message('for_screening', \$referenceNo, pswdo_request_identity(\$connection, \$requestId)['name'], ")) {
    throw new RuntimeException('Approval notification does not use the screening SMS format addressed to the applicant.');
}

echo "PSWDO screening SMS format test passed.\n";
