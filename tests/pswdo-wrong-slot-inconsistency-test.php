<?php
declare(strict_types=1);
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function wrong_slot_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$findings = [
    'reasons' => ['The uploaded document appears to be a Death Certificate, but this upload slot requires a Valid ID.'],
    'checks' => ['document_type' => [
        'status' => 'fail', 'expected' => 'valid_id', 'detected' => 'death_cert',
        'classification' => ['decision' => 'wrong_slot'],
    ]],
];
$summary = pswdo_ai_review_summary('flagged_inconsistency', $findings);
wrong_slot_assert(str_contains($summary, 'does not match the required document'), 'The document-type mismatch is hidden in the row summary.');
wrong_slot_assert(!str_contains(strtolower(pswdo_ai_review_status_meta('flagged_inconsistency')['summary']), 'name and date of birth'), 'The generic flag text incorrectly claims a name/date mismatch.');
$compact = pswdo_ai_compact_explanation('flagged_inconsistency', $findings, []);
wrong_slot_assert($compact['title'] === 'Wrong document type', 'The compact flag identifies the wrong problem.');
wrong_slot_assert(str_contains($compact['reason'], 'Death Certificate'), 'The detected document type is missing from the explanation.');
wrong_slot_assert(str_contains($compact['action'], 'correct document'), 'Staff are not told to request the correct type.');
echo "PSWDO wrong-slot inconsistency test passed.\n";
