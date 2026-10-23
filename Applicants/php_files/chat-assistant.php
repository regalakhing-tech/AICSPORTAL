<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../chatbot/applicant-assistant-lib.php';
require_once __DIR__ . '/../../chatbot/applicant-endpoint-lib.php';
require_once __DIR__ . '/../../chatbot/rate-limit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0, private');
header('X-Content-Type-Options: nosniff');

aics_start_session();
$sessionSnapshot = $_SESSION;
$csrf = trim((string) ($_SERVER['HTTP_X_AICS_CSRF'] ?? ''));
$database = null;

if (empty($sessionSnapshot['aics_account_id']) || ($sessionSnapshot['aics_role_code'] ?? '') !== 'applicat') {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to use your application guide.']);
    exit;
}

try {
    $database = aics_db_connect();
    aics_ensure_auth_schema($database);
    $preflight = aics_chatbot_applicant_preflight(
        ['csrf' => $csrf],
        $sessionSnapshot,
        static fn (int $_accountId, string $role): ?array => aics_authenticated_account_for_role($database, $role)
    );
} catch (Throwable) {
    $preflight = ['ok' => false, 'status' => 503, 'message' => 'I cannot check your application records right now. Please try again later.'];
}

if (($preflight['ok'] ?? false) !== true) {
    if ($database instanceof mysqli) $database->close();
    http_response_code((int) ($preflight['status'] ?? 503));
    echo json_encode(['error' => (string) ($preflight['message'] ?? 'The assistant is unavailable.')], JSON_UNESCAPED_UNICODE);
    exit;
}

$accountId = (int) $preflight['account_id'];
$rateState = is_array($_SESSION['aics_applicant_chat_rate'] ?? null) ? $_SESSION['aics_applicant_chat_rate'] : [];
$releasedForProvider = false;

$contextLoader = static fn (int $sessionAccountId, string $reference): array =>
    aics_chatbot_fetch_applicant_context($database, $sessionAccountId, $reference);

$now = time();
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$result = aics_chatbot_handle_applicant([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'raw_body' => aics_chatbot_read_body(),
    'api_key' => aics_chatbot_resolve_private_api_key(
        (string) (getenv('GROQ_API_KEY') ?: getenv('AICS_EXTERNAL_AI_API_KEY') ?: ''),
        aics_chatbot_private_env_path(),
        aics_chatbot_local_key_path()
    ),
    'account_id' => $accountId,
    'now' => $now,
    'ip' => $ip,
    // Per account, per address and overall; kept on the server, so a new sign-in does not reset it.
    'rate_gate' => static fn (): bool => aics_chatbot_rate_limit_take(
        aics_chatbot_rate_limit_dir(),
        'applicant',
        ['account:' . $accountId => 15, 'ip:' . $ip => 60, 'global' => 400],
        $now
    ),
], $rateState, $contextLoader, static function (string $key, array $messages, ?string $prompt = null) use (&$rateState, &$releasedForProvider): array {
    // Reserve this request while the session is locked, then let other pages load during Groq work.
    $_SESSION['aics_applicant_chat_rate'] = $rateState;
    $releasedForProvider = true;
    session_write_close();
    return aics_chatbot_groq_transport($key, $messages, $prompt);
});

if ($database instanceof mysqli) $database->close();
aics_start_session();
if ((int) ($_SESSION['aics_account_id'] ?? 0) !== $accountId) {
    $result = aics_chatbot_result(401, 'Your session has changed. Please sign in again.');
} elseif (!$releasedForProvider) {
    $_SESSION['aics_applicant_chat_rate'] = $rateState;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

http_response_code((int) $result['status']);
if ((int) $result['status'] === 405) header('Allow: POST');
echo json_encode($result['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
