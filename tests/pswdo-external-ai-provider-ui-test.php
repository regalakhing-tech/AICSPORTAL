<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function provider_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$text = pswdo_external_ai_provider_meta([
    'external_ai' => ['status' => 'completed', 'payload_mode' => 'text', 'cache_hit' => false],
]);
provider_assert($text['label'] === 'External AI checked', 'Completed provider label is incorrect.');
provider_assert(str_contains($text['detail'], 'OCR text'), 'Text-only evidence is not disclosed.');

$image = pswdo_external_ai_provider_meta([
    'external_ai' => ['status' => 'completed', 'payload_mode' => 'image', 'cache_hit' => true],
]);
provider_assert(str_contains($image['detail'], 'Document image'), 'Image-assisted evidence is not disclosed.');

$unavailable = pswdo_external_ai_provider_meta([
    'external_ai' => ['status' => 'unavailable', 'error_code' => 'EXTERNAL_AI_TIMEOUT'],
]);
provider_assert($unavailable['label'] === 'External AI unavailable', 'Unavailable label is incorrect.');
provider_assert(str_contains($unavailable['detail'], 'Local checks completed'), 'Local fallback is not explained.');

$local = pswdo_external_ai_provider_meta([
    'external_ai' => ['status' => 'not_needed', 'reason' => 'local_evidence_sufficient'],
]);
provider_assert($local['label'] === 'External AI not needed', 'Local-only label is incorrect.');
provider_assert(str_contains($local['detail'], 'Local evidence was sufficient'), 'Local evidence is not explained.');

$missing = pswdo_external_ai_provider_meta([]);
provider_assert($missing['label'] === 'Provider details unavailable', 'Legacy findings must not invent an external check.');

$rendered = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$card = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-ai-verdict-card.php') . file_get_contents(__DIR__ . '/../includes/ai-verdict-view.php');
provider_assert(is_string($rendered) && !str_contains($rendered, 'ai-provider-status'), 'The old provider status box must stay removed; the verdict card names its source.');
provider_assert(!str_contains($rendered, 'AICS_EXTERNAL_AI_API_KEY') && !str_contains($card, 'AICS_GEMINI_API_KEY'), 'Provider UI references a private key.');
provider_assert(str_contains($card, 'Document vs. application') && str_contains($card, 'In the application'), 'Beneficiary comparison is not rendered.');
provider_assert(str_contains($card, 'Checked by AICS AI') && preg_match('/Checked by Gemini|Gemini vision|Gemini did not run/', $card) === 0, 'Staff labels must say AICS AI, not Gemini.');

$dynamicRenderer = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
provider_assert(is_string($dynamicRenderer), 'Dynamic AI details renderer is unavailable.');
provider_assert(str_contains($dynamicRenderer, 'Beneficiary Cross-reference'), 'Dynamic details omit beneficiary results after a rescan.');
provider_assert(str_contains($dynamicRenderer, 'Beneficiary match'), 'Dynamic details do not identify beneficiary matching.');
provider_assert(!str_contains($dynamicRenderer, 'Applicant match'), 'Dynamic details still identify applicant matching.');
provider_assert(str_contains($dynamicRenderer, 'Beneficiary record'), 'Dynamic details omit beneficiary comparison values.');
provider_assert(str_contains($dynamicRenderer, 'Extracted:'), 'Dynamic details omit extracted values.');
provider_assert(!str_contains($dynamicRenderer, 'Gemini extracted'), 'Dynamic details retain Gemini branding in extracted-value labels.');
provider_assert(str_contains($dynamicRenderer, 'Different value detected - inspect original document'), 'Dynamic details expose or omit the safe conflict message.');
provider_assert(!str_contains($dynamicRenderer, 'â€¢') && !str_contains($dynamicRenderer, 'â€”'), 'Dynamic details contain mojibake punctuation.');

$statusEndpoint = file_get_contents(__DIR__ . '/../Pswdo/php_files/ai-review-status.php');
provider_assert(is_string($statusEndpoint) && str_contains($statusEndpoint, "'beneficiary' =>"), 'AI status response omits the beneficiary snapshot.');
provider_assert(str_contains($statusEndpoint, "'flagged_inconsistency' => 'Flagged Inconsistency'"), 'Polling hides the flagged inconsistency result behind a generic label.');
foreach (['beneficiary_complete_name', 'beneficiary_birth_date', 'beneficiary_age', 'beneficiary_barangay', 'beneficiary_municipality', 'beneficiary_province'] as $field) {
    provider_assert(str_contains($statusEndpoint, $field), "AI status response omits {$field}.");
}

echo "PSWDO external AI provider UI test passed.\n";
