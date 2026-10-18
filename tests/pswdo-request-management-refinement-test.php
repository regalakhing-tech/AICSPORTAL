<?php
declare(strict_types=1);

function refinement_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$data = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
$common = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-common.php');
refinement_assert(is_string($page) && is_string($data) && is_string($common), 'PSWDO refinement sources could not be read.');
$requestStateSource = $page . $data;
foreach (['No verifiable files', 'Scanning ', 'Scan unavailable', 'Re-upload needed', 'need review', 'AI results ready'] as $label) refinement_assert(str_contains($requestStateSource, $label), "Missing compact queue state: $label");
refinement_assert(!str_contains($page, 'staff checked</span>') && !str_contains($page, 'current<?= $aiReprocessingNeeded'), 'Visible X/Y request-summary counts remain.');
refinement_assert(!str_contains($page, 'Base Priority') && !str_contains($page, 'Medical Urgency</span>') && str_contains($page, 'Findings / explanation'), 'Priority explanation is missing.');
$detailHead = substr($page, (int) strpos($page, '<div class="request-detail-head">'), (int) (strpos($page, '<div class="request-review-summary-grid">') - strpos($page, '<div class="request-detail-head">')));
refinement_assert(!str_contains($detailHead, 'priority-chip'), 'Priority indicator must no longer appear in the request header.');
$summaryStart = strpos($page, '<div class="request-review-summary-grid">');
$duplicateStart = strpos($page, 'Duplicate check', $summaryStart ?: 0);
$priorityStart = strpos($page, 'Priority indicator', $summaryStart ?: 0);
refinement_assert($summaryStart !== false && $duplicateStart !== false && $priorityStart !== false && $priorityStart < $duplicateStart, 'Priority indicator must appear above Duplicate check in the summary grid.');
refinement_assert(str_contains($page, "\$request['priority']['priority_basis']") && str_contains($page, 'pswdo_h($priorityExplanation)'), 'Priority findings must use the existing escaped priority basis.');
$css = file_get_contents(__DIR__ . '/../Pswdo/css files/pswdo-pages.css');
refinement_assert(is_string($css) && str_contains($css, '.request-review-summary-grid {') && preg_match('/\.request-review-summary-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $css) === 1, 'Priority and Duplicate sections must render as stacked rows.');
refinement_assert(preg_match('/\.(?:priority-indicator-section|duplicate-check-section)\s*\{[^}]*grid-template-columns:/s', $css) === 0, 'Priority and Duplicate headings must sit above their details like Request.');
refinement_assert(!str_contains($css, 'repeat(2, minmax(0, 260px))'), 'Duplicate detail cards must use the Request card grid.');
refinement_assert(str_contains($page, 'No basis found') && !str_contains($page, "'—'"), 'Duplicate match-basis fallback is not staff friendly.');
refinement_assert(str_contains($page, 'beneficiaryUsesApplicantAddress') && str_contains($page, "'Address not available'") && !str_contains($page, 'Historical beneficiary address unavailable'), 'Beneficiary address fallback is not truthful.');
refinement_assert(str_contains($common, 'does not match the required document') && str_contains($common, 'not clear enough for reliable reading') && str_contains($common, 'differences that require staff review'), 'Status summaries do not reflect primary review reasons.');
foreach (['Document match', 'Readability', 'Beneficiary match', 'Alteration signal'] as $label) refinement_assert(str_contains($page . $common, $label), "Missing component assessment: $label");
refinement_assert(!str_contains($page, 'Document Identification Confidence:'), 'Misleading overall identification confidence remains visible.');
refinement_assert(substr_count($page, 'request-detail-item-income-wide') >= 4, 'Beneficiary and applicant income rows do not fill the final grid row.');
refinement_assert(is_string($css) && str_contains($css, '.request-detail-item-income-wide { grid-column: span 2; }') && str_contains($css, '.request-detail-item-address, .request-detail-item-income-wide { grid-column:auto; }'), 'Responsive full-width income grid behavior is missing.');
refinement_assert(str_contains($css, '.ai-tamper-summary { grid-template-columns: 1fr;'), 'Alteration check still uses large side-by-side cards.');
echo "PSWDO request-management refinement test passed.\n";
