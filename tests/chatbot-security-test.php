<?php
declare(strict_types=1);

// AICS Guide security: limits that cookies cannot reset, request size caps, no
// per-visitor session files for the public guide, no chat carried between
// accounts, and no direct web access to the chatbot's library files.
require_once __DIR__ . '/../chatbot/aics-chatbot-lib.php';
require_once __DIR__ . '/../chatbot/applicant-assistant-lib.php';
require_once __DIR__ . '/../chatbot/rate-limit.php';

function chat_security_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-chat-rate-' . bin2hex(random_bytes(3));
$now = 1_800_000_000;

try {
    // 1. Server-side limits: kept in a file, so a new cookie or a new sign-in does not reset them.
    for ($i = 1; $i <= 3; $i++) {
        chat_security_assert(aics_chatbot_rate_limit_take($dir, 'public', ['ip:203.0.113.5' => 3, 'global' => 5], $now + $i), "Request {$i} within the limit was refused.");
    }
    chat_security_assert(!aics_chatbot_rate_limit_take($dir, 'public', ['ip:203.0.113.5' => 3, 'global' => 5], $now + 4), 'A fourth request from the same address was allowed.');
    chat_security_assert(aics_chatbot_rate_limit_take($dir, 'public', ['ip:198.51.100.7' => 3, 'global' => 5], $now + 5), 'Another address was blocked by the first address\'s limit.');
    chat_security_assert(aics_chatbot_rate_limit_take($dir, 'public', ['ip:198.51.100.8' => 3, 'global' => 5], $now + 6), 'The fifth request overall was refused.');
    chat_security_assert(!aics_chatbot_rate_limit_take($dir, 'public', ['ip:198.51.100.9' => 3, 'global' => 5], $now + 7), 'The overall limit did not stop a new address.');
    chat_security_assert(aics_chatbot_rate_limit_take($dir, 'public', ['ip:203.0.113.5' => 3, 'global' => 5], $now + 700), 'The limit did not reset after the window.');
    chat_security_assert(aics_chatbot_rate_limit_take($dir, 'applicant', ['account:7' => 1], $now), 'Separate buckets share counts.');
    $stored = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'public.json');
    chat_security_assert(!str_contains($stored, '203.0.113.5'), 'Visitor addresses are stored in plain text.');
    chat_security_assert(!aics_chatbot_rate_limit_take($dir . DIRECTORY_SEPARATOR . "bad\0name", 'public', ['global' => 5], $now), 'An unusable limit folder must refuse (fail closed), not allow unlimited requests.');

    // 2. The handlers ask the server-side gate before calling the AI provider.
    $calls = 0;
    $transport = static function () use (&$calls): array { $calls++; return ['ok' => true, 'content' => 'AICS answer']; };
    $publicRequest = [
        'method' => 'POST', 'content_type' => 'application/json',
        'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => 'What is AICS?']]]),
        'api_key' => 'test-key', 'now' => $now, 'ip' => '203.0.113.9',
        'rate_gate' => static fn (): bool => false,
    ];
    $state = [];
    $result = aics_chatbot_handle($publicRequest, $state, $transport);
    chat_security_assert($result['status'] === 429 && $calls === 0, 'The public guide called the AI provider past the server-side limit.');

    $applicantRequest = [
        'method' => 'POST', 'content_type' => 'application/json',
        'raw_body' => json_encode(['messages' => [['role' => 'user', 'content' => 'What is my status?']]]),
        'api_key' => 'gsk_test', 'account_id' => 7, 'now' => $now, 'ip' => '203.0.113.9',
        'rate_gate' => static fn (): bool => false,
    ];
    $loader = static fn (): array => ['reference_found' => true, 'selected_application' => null, 'cooldown' => [], 'recent_notifications' => [], 'applications' => []];
    $state = [];
    $result = aics_chatbot_handle_applicant($applicantRequest, $state, $loader, $transport);
    chat_security_assert($result['status'] === 429 && $calls === 0, 'The applicant guide called the AI provider past the server-side limit.');

    // 3. Size caps: oversized bodies and long message lists are refused before any work.
    $state = [];
    $big = $publicRequest;
    $big['raw_body'] = str_repeat(' ', AICS_CHATBOT_MAX_BODY_BYTES + 1);
    unset($big['rate_gate']);
    chat_security_assert(aics_chatbot_handle($big, $state, $transport)['status'] === 413, 'An oversized public request was not refused.');
    $bigApplicant = $applicantRequest;
    $bigApplicant['raw_body'] = $big['raw_body'];
    chat_security_assert(aics_chatbot_handle_applicant($bigApplicant, $state, $loader, $transport)['status'] === 413, 'An oversized applicant request was not refused.');
    $many = array_fill(0, AICS_CHATBOT_MAX_MESSAGES + 1, ['role' => 'user', 'content' => 'AICS?']);
    $threw = false;
    try { aics_chatbot_validate_messages($many); } catch (InvalidArgumentException) { $threw = true; }
    chat_security_assert($threw, 'A message list longer than the cap was accepted.');
    chat_security_assert($calls === 0, 'The AI provider was called for a refused request.');

    // 4. Endpoints: bodies are read with a cap, the public guide keeps no session files.
    $publicEndpoint = (string) file_get_contents($root . '/chatbot/chat.php');
    chat_security_assert(!str_contains($publicEndpoint, 'session_start') && !str_contains($publicEndpoint, '$_SESSION'), 'The public guide still creates a session file for every visitor.');
    foreach (['chatbot/chat.php', 'Applicants/php_files/chat-assistant.php'] as $endpoint) {
        $code = (string) file_get_contents($root . '/' . $endpoint);
        chat_security_assert(str_contains($code, 'aics_chatbot_read_body()') && !str_contains($code, "file_get_contents('php://input')"), "{$endpoint} reads the request body without a size cap.");
        chat_security_assert(str_contains($code, "'rate_gate'") && str_contains($code, 'aics_chatbot_rate_limit_take('), "{$endpoint} has no server-side limit.");
    }

    // 5. A different account signing in on the same browser does not get the previous chat.
    $auth = (string) file_get_contents($root . '/Applicants/php_files/auth-common.php');
    chat_security_assert((bool) preg_match('/previousAccountId !== \$accountId\)\s*\{?\s*unset\(\$_SESSION\[\'aics_applicant_chat_csrf\'\], \$_SESSION\[\'aics_applicant_chat_rate\'\]\)/', $auth), 'Chat state is not cleared when another account signs in.');
    $loginJs = (string) file_get_contents($root . '/Applicants/js files/login.js');
    chat_security_assert(str_contains($loginJs, "key.startsWith('aics-chat-')"), 'The sign-in page does not clear chat history kept in the browser.');

    // 6. Library files, the local setup page and the README cannot be opened from the web.
    $htaccess = (string) @file_get_contents($root . '/chatbot/.htaccess');
    chat_security_assert(str_contains($htaccess, '<FilesMatch "\.(php|md)$">') && str_contains($htaccess, '<Files "chat.php">') && str_contains($htaccess, 'Require local'), 'chatbot/.htaccess does not limit direct web access.');
    $apache = (string) file_get_contents($root . '/deploy/apache.conf');
    chat_security_assert(str_contains($apache, '<LocationMatch "^/AICS/AICS/chatbot/(?!chat\.php$|chatbot\.(css|js)$)">'), 'Railway serves chatbot files other than chat.php, chatbot.css and chatbot.js.');

    echo "Chatbot security checks passed.\n";
} finally {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
