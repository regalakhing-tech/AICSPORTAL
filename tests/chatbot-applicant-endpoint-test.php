<?php
declare(strict_types=1);

require_once __DIR__ . '/../chatbot/aics-chatbot-lib.php';
require_once __DIR__ . '/../chatbot/applicant-endpoint-lib.php';

function applicant_endpoint_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label . ' failed.');
}

$session = [
    'aics_account_id' => 41,
    'aics_role_code' => 'applicat',
    'aics_applicant_chat_csrf' => 'known-token',
];
$authorizerCalls = [];
$authorizer = static function (int $accountId, string $role) use (&$authorizerCalls): ?array {
    $authorizerCalls[] = [$accountId, $role];
    return $accountId === 41 && $role === 'applicat' ? ['id' => 41, 'status_code' => 'active'] : null;
};

$result = aics_chatbot_applicant_preflight(['csrf' => 'known-token'], $session, $authorizer);
applicant_endpoint_assert(($result['ok'] ?? false) === true, 'Valid applicant preflight');
applicant_endpoint_assert(($result['account_id'] ?? 0) === 41, 'Session account returned');
applicant_endpoint_assert($authorizerCalls === [[41, 'applicat']], 'Database authorizer receives session identity');

$result = aics_chatbot_applicant_preflight(['csrf' => 'known-token'], [], $authorizer);
applicant_endpoint_assert(($result['status'] ?? 0) === 401, 'Missing session rejected');
$result = aics_chatbot_applicant_preflight(['csrf' => 'known-token'], array_merge($session, ['aics_role_code' => 'pswdo']), $authorizer);
applicant_endpoint_assert(($result['status'] ?? 0) === 401, 'Wrong role rejected');
$result = aics_chatbot_applicant_preflight(['csrf' => 'wrong-token'], $session, $authorizer);
applicant_endpoint_assert(($result['status'] ?? 0) === 403, 'Wrong CSRF rejected');
$inactive = static fn (): ?array => null;
$result = aics_chatbot_applicant_preflight(['csrf' => 'known-token'], $session, $inactive);
applicant_endpoint_assert(($result['status'] ?? 0) === 401, 'Inactive database account rejected');

$temporary = tempnam(sys_get_temp_dir(), 'aics-groq-env-');
if ($temporary === false) throw new RuntimeException('Unable to create temporary environment fixture.');
try {
    file_put_contents($temporary, "AICS_EXTERNAL_AI_ENABLED=1\nAICS_EXTERNAL_AI_API_KEY=gsk_TestPrivateKey123\n");
    applicant_endpoint_assert(
        aics_chatbot_resolve_private_api_key('', $temporary, '') === 'gsk_TestPrivateKey123',
        'Private environment key loaded'
    );
    applicant_endpoint_assert(
        aics_chatbot_resolve_private_api_key('gsk_EnvironmentKey456', $temporary, '') === 'gsk_EnvironmentKey456',
        'Environment key takes precedence'
    );
} finally {
    @unlink($temporary);
}

$endpointSource = file_get_contents(__DIR__ . '/../Applicants/php_files/chat-assistant.php');
applicant_endpoint_assert(is_string($endpointSource), 'Applicant endpoint exists');
applicant_endpoint_assert(!preg_match('/gsk_[A-Za-z0-9]{20,}/', $endpointSource), 'Endpoint contains no hardcoded key');

echo "Applicant chatbot endpoint tests passed.\n";

