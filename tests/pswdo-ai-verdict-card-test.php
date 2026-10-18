<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/ai-verdict-view.php';

function verdict_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$check = static fn (string $title, string $state, string $label, string $reason, array $missing = []): array
    => compact('title', 'state', 'label', 'reason', 'missing');

// Gemini result for review #205: a death certificate uploaded in the medical certificate slot.
$gemini = aics_ai_verdict_view([
    'verdict' => [
        'source' => 'gemini', 'status' => 'flagged_inconsistency', 'tone' => 'danger',
        'headline' => 'Wrong document uploaded',
        'reason' => 'This looks like a Death Certificate ("CERTIFICATE OF DEATH"), not the required Medical Abstract or Medical Certificate.',
        'action' => 'Ask the applicant to upload the Medical Abstract or Medical Certificate.',
        'required_label' => 'Medical Abstract or Medical Certificate', 'detected_label' => 'Death Certificate',
        'document_title' => 'CERTIFICATE OF DEATH', 'confidence' => 96, 'model' => 'gemini-2.5-flash',
    ],
    'thesis_checks' => [
        'complete' => $check('Complete', 'pass', 'All parts present', 'Every section is visible.'),
        'consistent' => $check('Consistent', 'fail', 'Inconsistent', 'Wrong document type.'),
        'readable' => $check('Readable', 'pass', 'Clear to read', 'Text is clear.'),
        'unaltered' => $check('No visible alterations', 'pass', 'No alterations seen', 'No corrections seen.'),
        'untampered' => $check('No signs of tampering', 'review', 'Possible tampering', 'A website watermark <b>is</b> printed.'),
    ],
    'field_comparison' => [
        ['field' => 'name', 'label' => 'Name of deceased', 'document' => 'Jennifer Angeles Ramos', 'application' => 'Nicasio Eimar Bustillo', 'state' => 'fail', 'note' => 'Different person.'],
        ['field' => 'document_date', 'label' => 'Document date', 'document' => '2008-06-28', 'application' => '', 'state' => 'info', 'note' => ''],
    ],
], 'flagged_inconsistency');

verdict_assert($gemini['available'] === true, 'Gemini verdict is not available.');
verdict_assert($gemini['source'] === 'gemini', 'Source is not Gemini.');
verdict_assert($gemini['tone'] === 'danger', 'Wrong-document verdict is not shown as danger.');
verdict_assert($gemini['headline'] === 'Wrong document uploaded', 'Headline missing.');
verdict_assert($gemini['status_label'] === 'Flagged for review', 'Status label missing.');
verdict_assert(str_contains($gemini['source_label'], 'AICS AI') && !str_contains($gemini['source_label'], 'Gemini') && str_contains($gemini['source_label'], '60% of checks passed'), 'Source label must say AICS AI and show the percentage of five checks passed.');
verdict_assert(array_column($gemini['checks'], 'key') === ['complete', 'consistent', 'readable', 'unaltered', 'untampered'], 'The five thesis checks are not in order.');
verdict_assert($gemini['passed_count'] === 3, 'Passed count is wrong.');
verdict_assert($gemini['checks'][1]['icon'] === 'fa-circle-xmark', 'Fail icon missing.');
verdict_assert($gemini['checks'][4]['icon'] === 'fa-triangle-exclamation', 'Review icon missing.');
verdict_assert($gemini['type']['required'] === 'Medical Abstract or Medical Certificate' && $gemini['type']['detected'] === 'Death Certificate', 'Type row wrong.');
verdict_assert($gemini['type']['state'] === 'fail', 'Type mismatch is not marked.');
verdict_assert(count($gemini['rows']) === 2 && $gemini['rows'][0]['document'] === 'Jennifer Angeles Ramos', 'Comparison rows missing.');
verdict_assert($gemini['rows'][1]['document'] === 'June 28, 2008', 'ISO dates are not shown in a friendly format.');
verdict_assert($gemini['rows'][1]['application'] === '—', 'Empty application value is not shown as a dash.');

// Unknown or hostile values are normalized.
$hostile = aics_ai_verdict_view(['verdict' => ['source' => 'gemini', 'tone' => '<script>', 'headline' => 'x'],
    'thesis_checks' => ['complete' => ['state' => 'evil', 'label' => 'x', 'reason' => 'y']]], 'likely_valid');
verdict_assert($hostile['tone'] === 'success', 'Tone must fall back to the status tone.');
verdict_assert($hostile['checks'][0]['state'] === 'unavailable', 'Unknown check state must become unavailable.');

// Legacy review without a verdict falls back to the four stored assessments.
$legacy = aics_ai_verdict_view([
    'reasons' => ['Local OCR could not confirm the name.'],
    'assessments' => [
        'document_match' => ['state' => 'pass', 'label' => 'Document type matches', 'explanation' => 'Matched.'],
        'readability' => ['state' => 'review', 'label' => 'Check readability', 'explanation' => 'Blurry corner.'],
        'applicant_match' => ['state' => 'fail', 'label' => 'Identity differs', 'explanation' => 'Name differs.'],
        'alteration_signal' => ['state' => 'pass', 'label' => 'No signal', 'explanation' => 'Clear.'],
    ],
], 'flagged_inconsistency');
verdict_assert($legacy['available'] === true && $legacy['source'] === 'legacy', 'Legacy fallback missing.');
verdict_assert($legacy['checks'][1]['state'] === 'fail', 'Legacy consistency must reflect the worst of document and applicant match.');
verdict_assert($legacy['checks'][2]['state'] === 'review', 'Legacy readability not mapped.');
verdict_assert($legacy['checks'][0]['state'] === 'unavailable', 'Legacy completeness must not invent a pass.');
verdict_assert($legacy['headline'] !== '', 'Legacy headline missing.');

// File metadata: a stripped, re-saved image (like upload #89) and a camera photo.
$withFile = static fn (array $file): array => ['verdict' => ['source' => 'gemini', 'headline' => 'x'], 'tamper_detection' => ['auxiliary' => ['file' => $file]]];
$stripped = aics_ai_verdict_view($withFile(['image_format' => 'JPEG', 'pixel_size' => '500 × 661', 'camera_metadata' => 'missing', 'saved_with_adobe' => true]), 'needs_staff_review');
$meta = array_column($stripped['metadata']['rows'], 'value', 'label');
verdict_assert($meta['File'] === 'JPEG · 500 × 661 pixels', 'File type and size not shown.');
verdict_assert($meta['Camera'] === 'None recorded' && $meta['Date taken'] === 'None recorded', 'Missing camera data not shown.');
verdict_assert(str_contains($meta['Saved with'], 'Adobe'), 'Adobe save marker not shown.');
verdict_assert(str_contains($stripped['metadata']['note'], 'No camera information'), 'Missing-camera note absent.');
verdict_assert(str_contains($stripped['metadata']['note'], 'does not show alteration'), 'Metadata must be described as weak evidence.');
$photo = aics_ai_verdict_view($withFile(['image_format' => 'JPEG', 'pixel_size' => '4032 × 3024', 'camera_metadata' => 'present', 'camera' => 'Apple iPhone 13', 'date_taken' => '2026:09:20 10:14:59', 'editing_software' => '17.5.1', 'saved_with_adobe' => false]), 'likely_valid');
$photoMeta = array_column($photo['metadata']['rows'], 'value', 'label');
verdict_assert($photoMeta['Camera'] === 'Apple iPhone 13', 'Camera not shown.');
verdict_assert($photoMeta['Date taken'] === 'September 20, 2026 10:14 AM', 'EXIF date not formatted.');
$pdf = aics_ai_verdict_view($withFile(['creation_date' => "D:20260920101500+08'00'", 'creator' => 'Microsoft Word', 'editing_software' => 'Adobe PDF Library', 'pdf_signature' => ['state' => 'unsigned']]), 'likely_valid');
$pdfMeta = array_column($pdf['metadata']['rows'], 'value', 'label');
verdict_assert($pdfMeta['Created'] === 'September 20, 2026 10:15 AM' && $pdfMeta['Created with'] === 'Microsoft Word', 'PDF metadata not shown.');
verdict_assert($pdfMeta['Digital signature'] === 'Not digitally signed', 'PDF signature state not shown.');
verdict_assert(aics_ai_verdict_view(['verdict' => ['source' => 'gemini']], 'likely_valid')['metadata']['rows'] === [], 'Metadata invented when none was stored.');

$empty = aics_ai_verdict_view([], 'processing');
verdict_assert($empty['available'] === false, 'Processing review must not render a verdict.');

// Shared card renderer escapes every value.
require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-ai-verdict-card.php';
$html = pswdo_ai_verdict_card_html($gemini, null, 'Fallback');
verdict_assert(str_contains($html, 'class="ai-verdict-card is-danger"'), 'Card root missing.');
verdict_assert(substr_count($html, 'class="ai-verdict-check ') === 5, 'Five-check scorecard not rendered.');
verdict_assert(str_contains($html, 'ai-verdict-compare') && str_contains($html, 'Jennifer Angeles Ramos'), 'Comparison table not rendered.');
verdict_assert(str_contains($html, '&lt;b&gt;is&lt;/b&gt;') && !str_contains($html, '<b>is</b>'), 'Check reasons are not escaped.');
verdict_assert(str_contains($html, '3 of 5 passed'), 'Passed count not rendered.');
verdict_assert(pswdo_ai_verdict_card_html($empty, null, 'x') === '', 'Unavailable verdict must render nothing.');
$strippedHtml = pswdo_ai_verdict_card_html($stripped, null, 'x');
verdict_assert(str_contains($strippedHtml, 'File metadata') && str_contains($strippedHtml, '500 × 661 pixels'), 'Metadata section not rendered.');
verdict_assert(!str_contains($html, 'File metadata'), 'Empty metadata section rendered.');

// Evidence boxes: staff labels, red for strong findings, amber for hints, no duplicates.
$evidence = pswdo_ai_evidence_items([
    ['type' => 'typography_mismatch', 'page' => 1, 'box_2d' => [308, 692, 320, 871], 'confidence_band' => 'moderate', 'reason' => 'Font differs.'],
    ['type' => 'date_context_conflict', 'page' => 1, 'box_2d' => [805, 481, 819, 766], 'confidence_band' => 'high', 'reason' => 'Date conflict.'],
    ['type' => 'date_context_conflict', 'page' => 1, 'box_2d' => [805, 481, 819, 766], 'confidence_band' => 'high', 'reason' => 'Date conflict.'],
    ['type' => 'digital_text_overlay', 'page' => 1, 'box_2d' => [802, 538, 823, 773], 'confidence_band' => 'high', 'reason' => 'Flat digital black.'],
    'not an indicator',
]);
verdict_assert(count($evidence) === 3, 'Duplicate or invalid evidence items were kept.');
verdict_assert($evidence[0]['tone'] === 'strong' && $evidence[2]['tone'] === 'hint', 'Strong findings must come first and hints last.');
$evidenceLabels = array_column($evidence, 'label');
verdict_assert(in_array('Typed-on text', $evidenceLabels, true) && in_array('Date conflict', $evidenceLabels, true) && in_array('Font or spacing differs', $evidenceLabels, true), 'Evidence labels missing.');
verdict_assert(pswdo_ai_provider_label('gemini') === 'AICS AI image reading', 'Provider label must use the AICS AI name.');

// Page and live-refresh integration.
$page = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
verdict_assert(str_contains($page, 'aics_ai_verdict_view('), 'Request page does not build the verdict view.');
verdict_assert(str_contains($page, 'pswdo_ai_verdict_card_html('), 'Request page does not render the shared card.');
verdict_assert(str_contains($page, '<summary>Technical details</summary>'), 'Technical details are not collapsed under one summary.');
foreach (['Review Summary', 'Extracted Information', 'Information Match Check', 'Document Alteration Review', 'Recommended Staff Action', 'Gemini Beneficiary Cross-reference', 'ai-component-grid'] as $removed) {
    verdict_assert(!str_contains($page, $removed), "Old UI section still on the page: $removed");
}
verdict_assert(str_contains($page, 'View marked areas') && str_contains($page, 'record_ai_review_feedback'), 'Evidence button or staff feedback was removed.');
verdict_assert(!preg_match('/\b(forged|fraudulent|fake document)\b/i', $page . $html), 'Accusatory wording in page.');
$endpoint = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/ai-review-status.php');
verdict_assert(str_contains($endpoint, "'verdict_html' =>"), 'Status endpoint does not return the refreshed card.');
$js = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
verdict_assert(str_contains($js, 'data.verdict_html'), 'Rescan refresh does not use the server-rendered card.');
verdict_assert(str_contains($js, '"Technical details"'), 'Rescan refresh uses the old technical details label.');
$css = (string) file_get_contents(__DIR__ . '/../Pswdo/css files/pswdo-pages.css');
verdict_assert(str_contains($css, '.ai-verdict-card'), 'Verdict card styles missing.');

echo "PSWDO AI verdict card test passed.\n";
