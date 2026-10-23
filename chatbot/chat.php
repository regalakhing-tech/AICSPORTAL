<?php
declare(strict_types=1);

require_once __DIR__ . '/aics-chatbot-lib.php';
require_once __DIR__ . '/applicant-endpoint-lib.php';
require_once __DIR__ . '/rate-limit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

// Public landing-page guide: no session, so visitors leave no session files.
// Limits are kept on the server per address and overall (chatbot/rate-limit.php).
$now = time();
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateState = [];

$result = aics_chatbot_handle([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'raw_body' => aics_chatbot_read_body(),
    'api_key' => aics_chatbot_resolve_private_api_key(
        (string) (getenv('GROQ_API_KEY') ?: ''),
        aics_chatbot_private_env_path(),
        aics_chatbot_local_key_path()
    ),
    'now' => $now,
    'ip' => $ip,
    'rate_gate' => static fn (): bool => aics_chatbot_rate_limit_take(
        aics_chatbot_rate_limit_dir(),
        'public',
        ['ip:' . $ip => 15, 'global' => 200],
        $now
    ),
], $rateState);

http_response_code($result['status']);
if ($result['status'] === 405) {
    header('Allow: POST');
}

echo json_encode(
    $result['payload'],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
