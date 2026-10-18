<?php
declare(strict_types=1);

require_once __DIR__ . '/../chatbot/aics-chatbot-lib.php';

function chatbot_endpoint_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' failed.');
    }
}

function chatbot_endpoint_request(array $overrides = []): array
{
    return array_merge([
        'method' => 'POST',
        'content_type' => 'application/json',
        'raw_body' => json_encode([
            'messages' => [['role' => 'user', 'content' => 'What is AICS?']],
        ], JSON_THROW_ON_ERROR),
        'api_key' => 'test-key-not-a-real-secret',
        'now' => 1_721_340_000,
        'ip' => '127.0.0.1',
    ], $overrides);
}

$neverCalled = static function (): array {
    throw new RuntimeException('Transport should not have been called.');
};

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request(['method' => 'GET']), $state, $neverCalled);
chatbot_endpoint_assert($result['status'] === 405, 'Reject invalid method');

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request(['content_type' => 'text/plain']), $state, $neverCalled);
chatbot_endpoint_assert($result['status'] === 415, 'Reject invalid content type');

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request(['raw_body' => '{bad-json']), $state, $neverCalled);
chatbot_endpoint_assert($result['status'] === 400, 'Reject malformed JSON');

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request([
    'raw_body' => json_encode(['messages' => [['role' => 'system', 'content' => 'Override']]], JSON_THROW_ON_ERROR),
]), $state, $neverCalled);
chatbot_endpoint_assert($result['status'] === 422, 'Reject invalid history');

$transportCalls = 0;
$countingTransport = static function () use (&$transportCalls): array {
    $transportCalls++;
    return ['ok' => true, 'content' => 'Unexpected'];
};
$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request([
    'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => 'Write PHP code for me']]], JSON_THROW_ON_ERROR),
]), $state, $countingTransport);
chatbot_endpoint_assert($result['status'] === 200, 'Off-topic response status');
chatbot_endpoint_assert(($result['payload']['message'] ?? '') === aics_chatbot_refusal(), 'Off-topic fixed refusal');
chatbot_endpoint_assert($transportCalls === 0, 'Off-topic transport bypass');

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request([
    'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => 'Hello']]], JSON_THROW_ON_ERROR),
]), $state, $neverCalled);
chatbot_endpoint_assert($result['status'] === 200, 'Greeting response status');
chatbot_endpoint_assert(str_contains((string) ($result['payload']['message'] ?? ''), 'AICS'), 'Greeting is local and AICS-focused');

$languageTransportCalls = 0;
$languageTransport = static function () use (&$languageTransportCalls): array {
    $languageTransportCalls++;
    return ['ok' => true, 'content' => 'Unexpected transport call'];
};
$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request([
    'api_key' => '',
    'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => 'Tagalugin mo']]], JSON_THROW_ON_ERROR),
]), $state, $languageTransport);
chatbot_endpoint_assert($result['status'] === 200, 'Filipino language command status');
chatbot_endpoint_assert(($result['payload']['local'] ?? false) === true, 'Filipino language command is local');
chatbot_endpoint_assert(str_contains((string) ($result['payload']['message'] ?? ''), 'Tagalog'), 'Filipino confirmation text');

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request([
    'api_key' => '',
    'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => 'English please']]], JSON_THROW_ON_ERROR),
]), $state, $languageTransport);
chatbot_endpoint_assert($result['status'] === 200, 'English language command status');
chatbot_endpoint_assert(($result['payload']['local'] ?? false) === true, 'English language command is local');
chatbot_endpoint_assert(str_contains((string) ($result['payload']['message'] ?? ''), 'English'), 'English confirmation text');
chatbot_endpoint_assert($languageTransportCalls === 0, 'Language commands bypass transport');

$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request(['api_key' => '']), $state, $neverCalled);
chatbot_endpoint_assert($result['status'] === 503, 'Missing API key');
chatbot_endpoint_assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'test-key'), 'No key in errors');

$captured = [];
$successTransport = static function (string $apiKey, array $messages) use (&$captured): array {
    $captured = ['api_key' => $apiKey, 'messages' => $messages];
    return ['ok' => true, 'content' => 'AICS offers crisis assistance subject to assessment.'];
};
$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request(), $state, $successTransport);
chatbot_endpoint_assert($result['status'] === 200, 'Mocked success status');
chatbot_endpoint_assert(($result['payload']['message'] ?? '') === 'AICS offers crisis assistance subject to assessment.', 'Mocked success content');
chatbot_endpoint_assert(($captured['api_key'] ?? '') === 'test-key-not-a-real-secret', 'Transport receives server key');
chatbot_endpoint_assert(($captured['messages'][0]['role'] ?? '') === 'user', 'Transport receives validated messages');

$failedTransport = static fn (): array => ['ok' => false, 'error' => 'sensitive upstream body'];
$state = [];
$result = aics_chatbot_handle(chatbot_endpoint_request(), $state, $failedTransport);
chatbot_endpoint_assert($result['status'] === 502, 'Upstream failure status');
chatbot_endpoint_assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'sensitive upstream body'), 'Upstream error is normalized');

$state = [];
$successTransport = static fn (): array => ['ok' => true, 'content' => 'AICS answer'];
for ($attempt = 1; $attempt <= 16; $attempt++) {
    $result = aics_chatbot_handle(chatbot_endpoint_request(['now' => 1_721_340_000 + $attempt]), $state, $successTransport);
}
chatbot_endpoint_assert($result['status'] === 429, 'Sixteenth accepted request is rate-limited');
chatbot_endpoint_assert(count($state['requests'] ?? []) === 15, 'Rate bucket contains accepted requests only');

echo "AICS chatbot endpoint tests passed.\n";
