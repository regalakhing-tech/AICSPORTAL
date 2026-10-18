<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../chatbot/applicant-assistant-lib.php';
require_once __DIR__ . '/../chatbot/applicant-endpoint-lib.php';
$key = aics_chatbot_resolve_private_api_key((string) (getenv('GROQ_API_KEY') ?: ''), aics_chatbot_private_env_path(), aics_chatbot_local_key_path());
if ($key === '') { echo "Groq chatbot: key unavailable\n"; exit(1); }
// Synthetic context only; no applicant records are sent by this diagnostic.
$context = ['selected_application' => null, 'applications' => [], 'cooldown' => []];
$result = aics_chatbot_groq_transport($key, [['role' => 'user', 'content' => 'what is iacs']], aics_chatbot_rag_prompt($context));
$answer = json_decode((string) ($result['content'] ?? ''), true);
$ok = !empty($result['ok']) && aics_chatbot_validate_rag_answer((string) ($result['content'] ?? ''), $context) !== null;
echo $ok ? "Groq chatbot: connected; structured response verified\n" : "Groq chatbot: unavailable or unexpected response; local fallback remains available\n";
if ($ok) {
    $context['selected_application'] = ['reference' => 'AICS-2026-ABC123', 'classification' => ['PWD'], 'assistance_label' => 'Medical Assistance', 'status_label' => 'Pending Review', 'documents' => [], 'release' => ['date' => '']];
    $result = aics_chatbot_groq_transport($key, [['role' => 'user', 'content' => 'classification mof my appliction?']], aics_chatbot_rag_prompt($context));
    $validated = aics_chatbot_validate_rag_answer((string) ($result['content'] ?? ''), $context);
    $ok = $validated !== null && preg_match('/PWD|disabilit/i', $validated['message']) === 1 && in_array('AICS-2026-ABC123', $validated['sources'], true);
    echo $ok ? "Groq chatbot: misspelled classification question answered from synthetic record\n" : "Groq chatbot: classification probe did not meet expected grounding\n";
}
if ($ok) {
    $result = aics_chatbot_groq_transport($key, [
        ['role' => 'user', 'content' => 'When can I apply again?'],
        ['role' => 'user', 'content' => 'i mean where can i see my application which tab?'],
    ], aics_chatbot_rag_prompt($context));
    $validated = aics_chatbot_validate_rag_answer((string) ($result['content'] ?? ''), $context);
    $ok = $validated !== null && str_contains($validated['message'], 'Application Status') && in_array('application-status.php', array_column($validated['actions'], 'page'), true);
    echo $ok ? "Groq chatbot: navigation follow-up uses Application Status and correct page button\n" : "Groq chatbot: navigation probe did not meet expected guidance\n";
}
exit($ok ? 0 : 1);
