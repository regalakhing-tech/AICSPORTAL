<?php
declare(strict_types=1);

$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
if (!is_string($script) || !is_string($page)) {
    throw new RuntimeException('Document review SMS sources could not be read.');
}

$cases = [
    'doc_reupload_default' => "{greeting}, Peter Parker.\n"
        . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
        . "Kailangan pong palitan ang isang dokumento ng inyong AICS application.\n"
        . "Reference No.: AICS-2026-D1C55F\n"
        . "Dokumento: Valid Identification Card\n"
        . "Dahilan: Pakipalitan po ang dokumentong ito ayon sa pagsusuri ng PSWDO.\n"
        . "Paki-upload po ang bagong kopya sa inyong Application Status page.\n"
        . "Maraming salamat po.",
    'doc_invalid_default' => "{greeting}, Peter Parker.\n"
        . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
        . "Hindi po tinanggap ang isang dokumento ng inyong AICS application.\n"
        . "Reference No.: AICS-2026-D1C55F\n"
        . "Dokumento: Valid Identification Card\n"
        . "Dahilan: Pakipalitan po ang dokumentong ito ayon sa pagsusuri ng PSWDO.\n"
        . "Paki-upload po ang mas malinaw o tamang kopya sa inyong Application Status page.\n"
        . "Maraming salamat po.",
];

foreach ($cases as $id => $expected) {
    $pattern = '/id: "' . preg_quote($id, '/') . '",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/';
    if (!preg_match($pattern, $script, $matches)) {
        throw new RuntimeException("Document review template {$id} is missing.");
    }
    $rendered = str_replace(
        ['{applicant}', '{reference}', '{document_label}', '{reason}'],
        ['Peter Parker', 'AICS-2026-D1C55F', 'Valid Identification Card', 'Pakipalitan po ang dokumentong ito ayon sa pagsusuri ng PSWDO.'],
        stripcslashes($matches[1])
    );
    if ($rendered !== $expected) {
        throw new RuntimeException("Document review template {$id} does not match the requested format.");
    }
}

if (!str_contains($page, 'data-document-reason="Pakipalitan po ang dokumentong ito ayon sa pagsusuri ng PSWDO."')) {
    throw new RuntimeException('Document review default reason does not match the requested format.');
}

echo "PSWDO document review SMS format test passed.\n";
