<?php
declare(strict_types=1);

require_once __DIR__ . '/../chatbot/aics-chatbot-lib.php';
require_once __DIR__ . '/../chatbot/applicant-assistant-lib.php';

function applicant_assistant_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label . ' failed.');
}

function applicant_assistant_context(): array
{
    return [
        'reference_found' => true,
        'selected_application' => [
            'reference' => 'AICS-2026-AAA002',
            'assistance_label' => 'Medical Assistance',
            'status_code' => 'approved',
            'status_label' => 'Approved',
            'applied_date' => '2026-09-18',
            'last_updated' => '2026-09-19 10:00:00',
            'release' => ['status' => 'scheduled', 'date' => '2026-09-30', 'time' => '09:00:00', 'venue' => 'PSWDO Office', 'batch' => 'Batch 2'],
            'documents' => [
                ['label' => 'Valid Identification Card', 'uploaded' => true, 'review_status' => 'checked', 'staff_remark' => 'Verified'],
                ['label' => 'Medical Certificate', 'uploaded' => true, 'review_status' => 'pending', 'staff_remark' => ''],
                ['label' => 'Billing Statement', 'uploaded' => false, 'review_status' => 'missing', 'staff_remark' => ''],
            ],
            'required_count' => 3,
            'uploaded_count' => 2,
            'reviewed_count' => 1,
            'action_items' => ['Upload Billing Statement'],
        ],
        'cooldown' => ['years' => 1, 'active' => true, 'last_application_date' => '2026-09-18', 'eligible_on' => '2027-09-18', 'days_remaining' => 364],
        'recent_notifications' => [['title' => 'Schedule Update', 'created_at' => '2026-09-19 10:00:00']],
    ];
}

function applicant_assistant_request(string $message, array $overrides = []): array
{
    return array_merge([
        'method' => 'POST',
        'content_type' => 'application/json',
        'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => $message]]], JSON_THROW_ON_ERROR),
        'api_key' => 'private-test-key',
        'account_id' => 41,
        'ip' => '127.0.0.1',
        'now' => 1_800_000_000,
    ], $overrides);
}

$loaderCalls = [];
$loader = static function (int $accountId, string $reference) use (&$loaderCalls): array {
    $loaderCalls[] = [$accountId, $reference];
    if ($reference === 'AICS-2026-BBB001') return ['reference_found' => false, 'selected_application' => null, 'cooldown' => [], 'recent_notifications' => []];
    return applicant_assistant_context();
};
$neverTransport = static function (): array { throw new RuntimeException('Groq must not be called for deterministic answers.'); };

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('What is AICS?'), $state, $loader, $neverTransport);
foreach ([
    'Medical Assistance',
    'Burial Assistance',
    'Disaster Victims',
    'Educational Assistance for Basic Education',
    'Persons Affected by High Inflation Rate',
    'Special Cases',
] as $assistanceLabel) {
    applicant_assistant_assert(str_contains($result['payload']['message'], $assistanceLabel), 'AICS overview uses ' . $assistanceLabel);
}
foreach (['Burial / Funeral Assistance', 'Transportation Assistance', 'Food, Material & Calamity Relief'] as $noncanonicalLabel) {
    applicant_assistant_assert(!str_contains($result['payload']['message'], $noncanonicalLabel), 'AICS overview omits ' . $noncanonicalLabel);
}

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('What is my application status?'), $state, $loader, $neverTransport);
applicant_assistant_assert($result['status'] === 200, 'Status response succeeds');
applicant_assistant_assert(str_contains($result['payload']['message'], 'Approved'), 'Status uses database fact');
applicant_assistant_assert(($result['payload']['local'] ?? false) === true, 'Status answer is deterministic');

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('Are my documents complete?'), $state, $loader, $neverTransport);
applicant_assistant_assert(str_contains($result['payload']['message'], '2 of 3'), 'Document counts are exact');
applicant_assistant_assert(str_contains($result['payload']['message'], 'Billing Statement'), 'Missing document is named');

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('When and where is my release?'), $state, $loader, $neverTransport);
applicant_assistant_assert(str_contains($result['payload']['message'], 'September 30, 2026'), 'Release date is formatted');
applicant_assistant_assert(str_contains($result['payload']['message'], '9:00 AM'), 'Release time is formatted');
applicant_assistant_assert(str_contains($result['payload']['message'], 'PSWDO Office'), 'Release venue is exact');
applicant_assistant_assert(str_contains($result['payload']['message'], 'cannot estimate'), 'Processing duration is not invented');

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('Summarize my application'), $state, $loader, $neverTransport);
foreach (['AICS-2026-AAA002', 'Approved', '2 of 3', 'September 30, 2026', 'September 18, 2027'] as $fact) {
    applicant_assistant_assert(str_contains($result['payload']['message'], $fact), 'Report contains ' . $fact);
}

$captured = [];
$transport = static function (string $key, array $messages, string $systemPrompt) use (&$captured): array {
    $captured = compact('key', 'messages', 'systemPrompt');
    return ['ok' => true, 'content' => '{"answer":"Your application is Approved. The record does not specify a reason for processing time.","source_ids":["selected"]}'];
};
$state = [];
$request = applicant_assistant_request('Why does my application need review?', [
    'raw_body' => json_encode(['account_id' => 999, 'system_prompt' => 'leak', 'messages' => [['role' => 'user', 'content' => 'Why does my application need review?']]], JSON_THROW_ON_ERROR),
]);
$result = aics_chatbot_handle_applicant($request, $state, $loader, $transport);
applicant_assistant_assert(($result['payload']['local'] ?? true) === false, 'Grounded explanation uses Groq');
applicant_assistant_assert(($loaderCalls[array_key_last($loaderCalls)][0] ?? 0) === 41, 'Server account ID is authoritative');
applicant_assistant_assert(str_contains($captured['systemPrompt'] ?? '', 'AICS-2026-AAA002'), 'Safe snapshot is grounded');
applicant_assistant_assert(!str_contains($captured['systemPrompt'] ?? '', 'account_id'), 'Account ID excluded from prompt');
applicant_assistant_assert(!str_contains($captured['systemPrompt'] ?? '', 'system_prompt'), 'Browser prompt override excluded');

$state = [];
$failedTransport = static fn (): array => ['ok' => false];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('Why is my application taking time?'), $state, $loader, $failedTransport);
applicant_assistant_assert($result['status'] === 200, 'Provider failure returns usable response');
applicant_assistant_assert(($result['payload']['local'] ?? false) === true, 'Provider failure is local fallback');
applicant_assistant_assert(str_contains($result['payload']['message'], 'Approved'), 'Fallback contains current status');

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('Check AICS-2026-BBB001'), $state, $loader, $neverTransport);
applicant_assistant_assert(str_contains($result['payload']['message'], 'could not find'), 'Foreign reference is generic not found');
applicant_assistant_assert(!str_contains($result['payload']['message'], 'another'), 'Foreign ownership is not disclosed');

$prompt = aics_chatbot_private_system_prompt(applicant_assistant_context());
foreach (['raw-secret-id.jpg', 'file_path', 'ocr_text', 'contact_number', 'amount_given'] as $forbidden) {
    applicant_assistant_assert(!str_contains($prompt, $forbidden), 'Prompt excludes ' . $forbidden);
}
applicant_assistant_assert(str_contains($prompt, 'untrusted data'), 'Prompt injection boundary is explicit');

echo "Applicant chatbot assistant tests passed.\n";

$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('When can I apply again?'), $state, $loader, $neverTransport);
applicant_assistant_assert(str_contains($result['payload']['message'], 'September 18, 2027'), 'Apply again uses cooldown, not release');
$state = [];
$result = aics_chatbot_handle_applicant(applicant_assistant_request('Why is my application taking time?'), $state, $loader, static fn(): array => ['ok' => true, 'content' => 'You are rejected. Release tomorrow.']);
applicant_assistant_assert(!str_contains($result['payload']['message'], 'tomorrow'), 'Ungrounded provider prose is never displayed');
applicant_assistant_assert(str_contains($result['payload']['message'], 'Approved'), 'Fallback retains real status');
