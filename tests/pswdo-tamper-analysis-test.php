<?php
declare(strict_types=1);
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
function tamper_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$completed = pswdo_tamper_analysis_json(json_encode(['tamper_detection' => ['status' => 'completed', 'aggregate' => ['tampered_pixel_ratio' => 0.0000434, 'max_tamper_probability' => 0.517117, 'mean_tamper_probability' => 0.076887, 'processing_time_seconds' => 20.86], 'pages' => [['page' => 1, 'tampered_pixel_ratio' => 0.0000434, 'max_tamper_probability' => 0.517117, 'mean_tamper_probability' => 0.076887, 'processing_time_seconds' => 20.86]]]]));
tamper_assert($completed['status'] === 'completed' && count($completed['pages']) === 1, 'Completed analysis did not normalize.');
tamper_assert(pswdo_tamper_percent(0.0000434) === '0.0043%', 'Ratio formatting is incorrect.');
tamper_assert(pswdo_tamper_percent(0.517117) === '51.7117%', 'Probability formatting is incorrect.');
tamper_assert(pswdo_tamper_seconds(20.86) === '20.86 s', 'Time formatting is incorrect.');
$unavailable = pswdo_tamper_analysis_json('{"tamper_detection":{"status":"unavailable","error_code":"service_unavailable"}}');
tamper_assert($unavailable['status'] === 'unavailable' && str_contains($unavailable['message'], 'could not be completed') && str_contains($unavailable['message'], 'Document identification results remain available'), 'Unavailable image result was not kept separate from Document Check.');
$missingSource = pswdo_tamper_analysis_json('{"summary":"legacy"}', '{"status":"unavailable","error_code":"ai_document_file_not_found"}');
tamper_assert(str_contains($missingSource['message'], 'original uploaded file is no longer available') && str_contains($missingSource['staff_guidance'], 'available Document Check'), 'Missing-source guidance is not truthful.');
$missing = pswdo_tamper_analysis_json(''); tamper_assert($missing['status'] === 'not_analyzed' && str_contains($missing['message'], 'Waiting for AI image analysis'), 'Never-analyzed state was not queued safely.');
$historical = pswdo_tamper_analysis_json('{"summary":"legacy","tampering":{"status":"completed"}}'); tamper_assert($historical['status'] === 'legacy' && str_contains($historical['message'], 'before the current image-review feature'), 'Legacy review was incorrectly treated as an image failure.');
$supplement = pswdo_tamper_analysis_json('{"summary":"legacy"}', json_encode(['status' => 'completed', 'aggregate' => ['tampered_pixel_ratio' => 0, 'max_tamper_probability' => .2, 'mean_tamper_probability' => .1, 'processing_time_seconds' => 1], 'pages' => [['page' => 1, 'tampered_pixel_ratio' => 0, 'max_tamper_probability' => .2, 'mean_tamper_probability' => .1, 'processing_time_seconds' => 1]] ])); tamper_assert($supplement['status'] === 'completed', 'Supplemental image data did not remain independent from legacy findings.');
$recovered = pswdo_tamper_analysis_json('{"tamper_detection":{"status":"unavailable","error_code":"service_disabled"}}', json_encode(['status' => 'completed', 'aggregate' => ['tampered_pixel_ratio' => 0, 'max_tamper_probability' => .2, 'mean_tamper_probability' => .1, 'processing_time_seconds' => 1], 'pages' => [['page' => 1, 'tampered_pixel_ratio' => 0, 'max_tamper_probability' => .2, 'mean_tamper_probability' => .1, 'processing_time_seconds' => 1]]])); tamper_assert($recovered['status'] === 'completed', 'A stale failed native image result overrode its successful supplement.');
tamper_assert(pswdo_tamper_analysis_json('{"tamper_detection":{"status":"completed","aggregate":{"max_tamper_probability":"<script>"}}}')['status'] === 'unknown', 'Malformed analysis was not safe.');
$multi = $completed; $multi['pages'][] = $completed['pages'][0]; tamper_assert(count($multi['pages']) === 2, 'Multi-page structure was not retained.');
$combined = pswdo_tamper_analysis_json(json_encode(['tamper_detection' => [
    'status' => 'completed', 'decision' => 'review',
    'label' => 'Suspected alteration — staff review required', 'confidence_band' => 'moderate',
    'evidence_families' => ['gemini_visual', 'semantic_chronology'],
    'indicators' => [['type' => 'date_context_conflict', 'page' => 1, 'confidence_band' => 'moderate', 'reason' => 'Date context requires inspection.', 'source' => 'chronology']],
    'providers' => ['gemini' => ['status' => 'completed'], 'semantic_chronology' => ['status' => 'completed']],
]]));
tamper_assert($combined['status'] === 'completed' && $combined['decision'] === 'review', 'Combined alteration decision did not normalize.');
tamper_assert(count($combined['indicators']) === 1 && $combined['indicators'][0]['type'] === 'date_context_conflict', 'Combined indicators were not retained.');
$dateConflict = pswdo_tamper_analysis_json(json_encode(['tamper_detection' => [
    'status' => 'completed', 'decision' => 'review',
    'review_basis' => 'deterministic_chronology_conflict', 'confidence_band' => 'high',
    'date_conflict' => ['document_date' => '2026-09-24', 'conflicting_years' => [2021]],
    'evidence_families' => ['semantic_chronology'], 'indicators' => [], 'providers' => [],
]]));
tamper_assert(($dateConflict['review_basis'] ?? '') === 'deterministic_chronology_conflict', 'Date-conflict review basis was not retained.');
tamper_assert($dateConflict['label'] === 'Document date inconsistency — staff review required', 'Date-conflict label was not shown clearly.');
$flagExplanation = pswdo_flag_explanation($dateConflict);
tamper_assert(is_array($flagExplanation), 'Compact flag explanation was not created.');
tamper_assert($flagExplanation['document_date'] === 'September 24, 2026', 'Compact explanation did not format the document date.');
tamper_assert($flagExplanation['event_dates'] === '2021', 'Compact explanation did not show the conflicting event year.');
tamper_assert(str_contains($flagExplanation['reason'], 'conflict'), 'Compact explanation did not clearly state why the document was flagged.');
$manualCompact = pswdo_ai_compact_explanation('needs_staff_review', ['reasons' => ['The document type could not be confirmed.']], $completed);
tamper_assert($manualCompact['tone'] === 'warning' && $manualCompact['title'] === 'Staff review needed', 'Manual-review compact result is unclear.');
tamper_assert($manualCompact['reason'] === 'The document type could not be confirmed.', 'Manual-review compact result omitted the main reason.');
tamper_assert(str_contains($manualCompact['action'], 'original document'), 'Manual-review compact result omitted a direct action.');
$genericFlagCompact = pswdo_ai_compact_explanation('flagged_inconsistency', ['reasons' => ['Submitted values conflict.']], $completed);
tamper_assert($genericFlagCompact['tone'] === 'danger', 'A non-date inconsistency is not shown with the required red danger state.');
$validCompact = pswdo_ai_compact_explanation('likely_valid', [], $completed);
tamper_assert($validCompact['tone'] === 'success' && $validCompact['title'] === 'Checks completed', 'Successful compact result is unclear.');
$failedCompact = pswdo_ai_compact_explanation('failed', [], $unavailable);
tamper_assert($failedCompact['tone'] === 'neutral' && str_contains($failedCompact['action'], 'manually'), 'Unavailable compact result omitted manual-review guidance.');
$replacementCompact = pswdo_ai_compact_explanation('needs_reupload', ['reasons' => ['The image is too blurred to review reliably.']], $unavailable);
tamper_assert($replacementCompact['tone'] === 'danger' && str_contains($replacementCompact['action'], 'replacement'), 'Replacement compact result is unclear.');
$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
tamper_assert(is_string($page) && str_contains($page, 'AI Document Verification') && str_contains($page, 'Document Check') && str_contains($page, 'Document Alteration Review'), 'Staff-facing headings are missing.');
tamper_assert(str_contains($page, 'Evidence Source') && str_contains($page, 'Reason for Review') && str_contains($page, 'Suspected alteration'), 'Combined alteration evidence is not rendered for staff.');
tamper_assert(str_contains($page, 'ai-review-explanation') && str_contains($page, 'View full verification details'), 'Compact review explanation is missing.');
tamper_assert(!str_contains($page, 'ai-row-flag-brief') && !str_contains($page, 'ai-row-flag-action'), 'The document table repeats date-conflict details outside the Details control.');
tamper_assert(str_contains($page, 'ai-details-toggle') && str_contains($page, 'date_facts'), 'The document table must retain access to date-conflict details.');
tamper_assert(str_contains($page, 'ai-review-explanation'), 'The compact result card is not shared by every review state.');
$poller = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
tamper_assert(is_string($poller) && str_contains($poller, 'data.image_review') && str_contains($poller, 'data.compact_review'), 'Live refresh does not preserve the detailed review explanation.');
tamper_assert(!str_contains($poller, 'ai-row-flag-brief') && !str_contains($poller, 'ai-row-flag-action'), 'Live refresh repeats date-conflict details in the document row.');
tamper_assert(str_contains($poller, 'if (terminal) { stop(documentId);') && !str_contains($poller, 'if (terminal && rescanReloads.has'), 'Terminal live results do not reload the complete server-rendered review.');
tamper_assert(is_string($page) && str_contains($page, 'Final verification and approval remain with authorized PSWDO personnel.'), 'Human-review advisory wording is missing.');
tamper_assert(!str_contains($page, 'Experimental') && !str_contains($page, 'Phase 1') && !str_contains($page, 'Phase 2') && !str_contains($page, '>Not Analyzed<'), 'Legacy staff-facing terminology remains.');
tamper_assert(str_contains($page, 'Not Yet Analyzed') && str_contains($page, 'Earlier Review') && str_contains($page, 'AI Review Unavailable') && str_contains($page, 'Review Complete'), 'Review state labels are incomplete.');
tamper_assert(str_contains($page, 'Area Flagged for Review') && str_contains($page, 'Highest Change Signal') && str_contains($page, 'Overall Change Signal') && str_contains($page, 'Processing Time'), 'Technical detail labels are missing.');
tamper_assert(str_contains($page, 'Waiting for Review') && str_contains($page, 'Reviewing Document') && str_contains($page, 'Queued for AI document verification.') && str_contains($page, 'AI document verification is in progress.'), 'Queued and active review wording is incomplete.');
tamper_assert(str_contains($page, 'Review Method') && str_contains($page, 'AI-Assisted Image Review') && !str_contains($page, '<dt>Model</dt><dd>ADCD-Net'), 'Model terminology dominates the staff UI.');
tamper_assert(str_contains($page, 'Show Technical Details') && str_contains($page, 'AI-assisted image review is provided for staff guidance only.'), 'Technical-detail collapse or advisory wording is missing.');
foreach (['Authentic', 'Tampered', 'Fake', 'Fraudulent', 'Passed', 'Failed', 'Suspicious'] as $forbidden) tamper_assert(!str_contains($page, '>' . $forbidden . '<'), 'Tamper UI contains a forbidden decision label: ' . $forbidden);
tamper_assert(is_string($page) && str_contains($page, 'count($tamperPages) > 1'), 'Single-page UI clutter guard is missing.');
echo "PSWDO tamper analysis test passed.\n";
