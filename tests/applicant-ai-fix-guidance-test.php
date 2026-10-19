<?php
declare(strict_types=1);

// When AICS AI rejects a document the applicant is told exactly what to fix and
// can replace it on the status page; nothing keeps spinning once there is a result.
require_once __DIR__ . '/../includes/ai-submission-gate.php';

function fix_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$checks = static fn (array $states): array => array_map(static fn (string $state): array => ['state' => $state], array_merge(
    ['complete' => 'pass', 'consistent' => 'pass', 'readable' => 'pass', 'unaltered' => 'pass', 'untampered' => 'pass'], $states));
$doc = static fn (string $status, array $findings): array => ['document_type' => 'med_cert', 'status' => $status, 'findings' => $findings];

$wrongType = $doc('flagged_inconsistency', ['verdict' => ['source' => 'gemini', 'required_label' => 'Medical Abstract or Medical Certificate', 'detected_label' => 'Death Certificate'],
    'thesis_checks' => $checks(['consistent' => 'fail']), 'checks' => ['document_type' => ['classification' => ['decision' => 'wrong_slot']]]]);
fix_assert(aics_ai_gate_fix_instruction($wrongType) === 'This looks like a Death Certificate, not a Medical Abstract or Medical Certificate. Upload your Medical Abstract or Medical Certificate.', 'wrong document names both documents');
fix_assert(aics_ai_gate_fix_instruction($doc('flagged_inconsistency', ['verdict' => ['source' => 'gemini'], 'thesis_checks' => $checks(['untampered' => 'fail'])])) === 'This document looks edited. Upload a photo or scan of the original document.', 'edited');
fix_assert(aics_ai_gate_fix_instruction($doc('flagged_inconsistency', ['verdict' => ['source' => 'gemini'], 'thesis_checks' => $checks(['consistent' => 'fail'])])) === 'The name or details on this document do not match your application. Upload the document issued for the person named in your application.', 'mismatch');
fix_assert(aics_ai_gate_fix_instruction($doc('needs_staff_review', ['verdict' => ['source' => 'gemini'], 'thesis_checks' => $checks(['readable' => 'review'])])) === 'The photo is blurry or hard to read. Take a new photo in good light and keep the camera steady.', 'blurry');
fix_assert(aics_ai_gate_fix_instruction($doc('needs_reupload', ['verdict' => ['source' => 'gemini'], 'thesis_checks' => $checks(['complete' => 'fail'])])) === 'Part of the document is missing or cut off. Upload a photo or scan that shows the whole document.', 'cut off');
fix_assert(aics_ai_gate_fix_instruction($doc('needs_reupload', ['verdict' => ['source' => 'gemini'], 'thesis_checks' => $checks(['complete' => 'fail', 'readable' => 'fail'])])) === 'Take a new, sharp photo in good light that shows the whole page.', 'blurry and cut off');
fix_assert(aics_ai_gate_fix_instruction($doc('needs_reupload', [])) === 'Take a new, sharp photo in good light that shows the whole page.', 'no findings: photo advice');
fix_assert(stripos(aics_ai_gate_fix_instruction($wrongType), 'gemini') === false, 'branding');

$root = __DIR__ . '/..';
$status = (string) file_get_contents($root . '/Applicants/php_files/application-status.php');
foreach (['ai-gate-fix', "name=\"action_mode\" value=\"reupload_single\"", "'badge' => 'attention'", "\$stageBadge === 'attention'", "\$stageBadge === 'review'"] as $needle) {
    fix_assert(str_contains($status, $needle), "Status page is missing: {$needle}");
}
$upload = (string) file_get_contents($root . '/Applicants/php_files/documents-upload.php');
$singleStart = (int) strpos($upload, "if (\$actionMode === 'reupload_single')");
$single = substr($upload, $singleStart, (int) strpos($upload, 'find_latest_request($connection', $singleStart) - $singleStart);
fix_assert(str_contains($single, 'DELETE FROM aics_request_documents'), 'Replacing a document must remove the old one first (one document per type).');
fix_assert(str_contains($single, 'AICS AI is checking it now.'), 'The applicant is told the new document is being checked.');
echo "Applicant AICS AI fix guidance checks passed.\n";
