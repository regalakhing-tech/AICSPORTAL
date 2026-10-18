<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function component_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$components = pswdo_ai_component_assessments([
    'assessments' => [
        'document_match' => ['state' => 'pass', 'label' => 'Document type matches', 'explanation' => 'Matched locally.'],
        'readability' => ['state' => 'review', 'label' => 'Check readability', 'explanation' => 'Inspect the original.'],
        'applicant_match' => ['state' => 'fail', 'label' => 'Identity details differ', 'explanation' => 'Confirm stable fields.'],
        'alteration_signal' => ['state' => 'unavailable', 'label' => 'Alteration check unavailable', 'explanation' => 'Continue manual review.'],
    ],
]);
component_assert(array_keys($components) === ['document_match', 'readability', 'applicant_match', 'alteration_signal'], 'The four component results are not stable.');
component_assert($components['document_match']['title'] === 'Document match', 'Document assessment is missing.');
component_assert($components['readability']['title'] === 'Readability', 'Readability assessment is missing.');
component_assert($components['applicant_match']['title'] === 'Beneficiary match', 'Beneficiary comparison is missing.');
component_assert($components['alteration_signal']['title'] === 'Alteration signal', 'DocTamper assessment is missing.');

$legacy = pswdo_ai_component_assessments([]);
component_assert(count(array_filter($legacy, static fn (array $item): bool => $item['state'] === 'unavailable')) === 4, 'Legacy findings invented passing results.');

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
component_assert(is_string($page) && str_contains($page, 'pswdo_ai_verdict_card_html(') && !str_contains($page, 'ai-component-grid'), 'The five-check verdict card must replace the old component grid.');
component_assert(!str_contains($page, 'Document Identification Confidence:'), 'Misleading overall confidence remains visible.');
component_assert(!str_contains(strtolower($page), 'proof of fraud'), 'UI overstates alteration evidence.');
echo "PSWDO AI component results test passed.\n";
