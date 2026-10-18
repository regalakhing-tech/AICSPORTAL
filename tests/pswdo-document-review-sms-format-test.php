<?php
declare(strict_types=1);

$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
if (!is_string($script) || !is_string($page)) {
    throw new RuntimeException('Document review SMS sources could not be read.');
}

$cases = [
    'doc_reupload_default' => "Good day, Peter Parker.\n"
        . "This is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
        . "Your document for request AICS-2026-D1C55F requires changes.\n"
        . "Document: Valid Identification Card\n"
        . "Please re-upload the updated document through your Application Status page.\n"
        . "Reason: Please update this document based on the PSWDO review.\n"
        . "Thank you.",
    'doc_invalid_default' => "Good day.\n"
        . "This is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
        . "Your document for request AICS-2026-D1C55F has been marked as invalid.\n"
        . "Document: Valid Identification Card\n"
        . "Please upload a clearer or correct version through your Application Status page.\n"
        . "Reason: Please update this document based on the PSWDO review.\n"
        . "Thank you.",
];

foreach ($cases as $id => $expected) {
    $pattern = '/id: "' . preg_quote($id, '/') . '",\s*label: "[^"]+",\s*text: "((?:\\\\.|[^"\\\\])*)"/';
    if (!preg_match($pattern, $script, $matches)) {
        throw new RuntimeException("Document review template {$id} is missing.");
    }
    $rendered = str_replace(
        ['{beneficiary}', '{reference}', '{document_label}', '{reason}'],
        ['Peter Parker', 'AICS-2026-D1C55F', 'Valid Identification Card', 'Please update this document based on the PSWDO review.'],
        stripcslashes($matches[1])
    );
    if ($rendered !== $expected) {
        throw new RuntimeException("Document review template {$id} does not match the requested format.");
    }
}

if (!str_contains($page, 'data-document-reason="Please update this document based on the PSWDO review."')) {
    throw new RuntimeException('Document review default reason does not match the requested format.');
}

echo "PSWDO document review SMS format test passed.\n";
