<?php
declare(strict_types=1);

require_once __DIR__ . '/aics-chatbot-lib.php';
require_once __DIR__ . '/applicant-endpoint-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

$rateState = is_array($_SESSION['aics_chatbot_rate'] ?? null)
    ? $_SESSION['aics_chatbot_rate']
    : [];

$result = aics_chatbot_handle([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'raw_body' => file_get_contents('php://input') ?: '',
    'api_key' => aics_chatbot_resolve_private_api_key(
        (string) (getenv('GROQ_API_KEY') ?: ''),
        aics_chatbot_private_env_path(),
        aics_chatbot_local_key_path()
    ),
    'now' => time(),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
], $rateState);

$_SESSION['aics_chatbot_rate'] = $rateState;

http_response_code($result['status']);
if ($result['status'] === 405) {
    header('Allow: POST');
}

echo json_encode(
    $result['payload'],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
