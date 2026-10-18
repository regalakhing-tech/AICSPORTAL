<?php
declare(strict_types=1);

require_once __DIR__ . '/../chatbot/aics-chatbot-lib.php';
require_once __DIR__ . '/../chatbot/applicant-context.php';
require_once __DIR__ . '/../chatbot/applicant-rag.php';

function chatbot_terminology_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$canonical = [
    'medical' => 'Medical Assistance',
    'burial' => 'Burial Assistance',
    'disaster_victims' => 'Disaster Victims',
    'educational_basic' => 'Educational Assistance for Basic Education',
    'high_inflation_affected' => 'Persons Affected by High Inflation Rate',
    'special_cases' => 'Special Cases',
];

foreach ($canonical as $code => $label) {
    $meta = aics_chatbot_assistance_meta(json_encode([$code], JSON_THROW_ON_ERROR));
    chatbot_terminology_assert(($meta['label'] ?? '') === $label, "{$code} does not use the canonical portal label.");
}

foreach (['funeral assistance', 'tulong sa libing', 'school assistance', 'calamity relief', 'transportation assistance'] as $message) {
    chatbot_terminology_assert(aics_chatbot_is_aics_topic($message), "Input synonym is no longer understood: {$message}");
}

$normalized = aics_chatbot_normalize_assistance_terms(
    'Funeral Assistance, Disaster Assistance, Special Cases Assistance, and Educational Assistance — Basic Education. Bring the funeral contract.'
);
foreach (['Burial Assistance', 'Disaster Victims', 'Special Cases', 'Educational Assistance for Basic Education', 'funeral contract'] as $term) {
    chatbot_terminology_assert(str_contains($normalized, $term), "Normalized chatbot text is missing {$term}.");
}
foreach (['Funeral Assistance', 'Disaster Assistance', 'Special Cases Assistance', 'Educational Assistance — Basic Education'] as $term) {
    chatbot_terminology_assert(!str_contains($normalized, $term), "Noncanonical chatbot label remains: {$term}");
}

$prompt = aics_chatbot_system_prompt();
$guide = (string) (aics_chatbot_rag_sources(['cooldown' => [], 'applications' => []])['guide']['facts'] ?? '');
foreach (array_values($canonical) as $label) {
    chatbot_terminology_assert(str_contains($prompt, $label), "System prompt is missing canonical label: {$label}");
    chatbot_terminology_assert(str_contains($guide, $label), "RAG guide is missing canonical label: {$label}");
}

echo "Chatbot terminology tests passed.\n";
