<?php
declare(strict_types=1);
require __DIR__ . '/chatbot-applicant-assistant-test.php';
$calls = 0;
$provider = static function(string $key, array $messages, string $prompt) use (&$calls): array {
    $calls++;
    applicant_assistant_assert(str_contains($prompt, 'Sources:'), 'Provider receives retrieved evidence');
    return ['ok' => true, 'content' => json_encode(['answer' => 'Your application record is available. What detail would you like explained?', 'source_ids' => ['selected']])];
};
foreach (['classification mof my appliction?', 'what is iacs', 'how long pa?', 'all my aplications', 'ano ibig sabihin nito?'] as $question) {
    $state = [];
    $response = aics_chatbot_handle_applicant(applicant_assistant_request($question), $state, $loader, $provider);
    applicant_assistant_assert($response['status'] === 200 && $response['payload']['local'] === false, 'Natural question reaches Groq: ' . $question);
    applicant_assistant_assert(!empty($response['payload']['sources']), 'Response includes verified source labels');
}
applicant_assistant_assert($calls === 5, 'All questions reach provider without keyword refusal');
$context = applicant_assistant_context();
foreach ([
    ['answer' => 'Unsupported claim', 'source_ids' => []],
    ['answer' => 'Unsupported claim', 'source_ids' => ['invented']],
    ['answer' => 'AICS-2026-FFFFFF is approved', 'source_ids' => ['selected']],
] as $bad) applicant_assistant_assert(aics_chatbot_validate_rag_answer(json_encode($bad), $context) === null, 'Invalid grounding rejected');
$context['selected_application']['documents'][0]['staff_remark'] = 'PRIVATE STAFF NOTE';
applicant_assistant_assert(!str_contains(aics_chatbot_rag_prompt($context), 'PRIVATE STAFF NOTE'), 'Private freeform note excluded');
echo "Applicant RAG typo routing, source validation, and privacy checks passed.\n";
$navigation = aics_chatbot_validate_rag_answer(json_encode(['answer' => 'Open Application Status in the sidebar.', 'source_ids' => ['portal_status']]), $context);
applicant_assistant_assert($navigation['actions'][0]['page'] === 'application-status.php', 'Navigation action uses server-owned page');
$profile = aics_chatbot_validate_rag_answer(json_encode(['answer' => 'Open My Profile, update your details, then press Save.', 'source_ids' => ['portal_profile']]), $context);
applicant_assistant_assert($profile['actions'][0]['page'] === 'my-profile.php', 'Profile tutorial has correct action');
$help = aics_chatbot_portal_help();
applicant_assistant_assert(str_contains($help['portal_profile']['facts'], 'Age is calculated and read-only'), 'Age instruction matches form');
applicant_assistant_assert(str_contains($help['portal_documents']['facts'], 'Manage Applications, then Edit Application'), 'Document replacement uses actual workflow');
echo "Applicant portal tutorial and navigation checks passed.\n";
