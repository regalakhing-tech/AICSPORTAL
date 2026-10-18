<?php
declare(strict_types=1);

require_once __DIR__ . '/../chatbot/aics-chatbot-lib.php';

function chatbot_local_key_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' failed.');
    }
}

$testDirectory = sys_get_temp_dir() . '/aics-chatbot-key-' . bin2hex(random_bytes(6));
$testPath = $testDirectory . '/groq.key';
mkdir($testDirectory, 0700, true);

try {
    $localKey = 'gsk_' . 'localtest123456';
    file_put_contents($testPath, $localKey . PHP_EOL);

    chatbot_local_key_assert(
        aics_chatbot_resolve_api_key('', $testPath) === $localKey,
        'Read valid local key'
    );
    chatbot_local_key_assert(
        aics_chatbot_resolve_api_key('environment-key', $testPath) === 'environment-key',
        'Environment key takes precedence'
    );

    file_put_contents($testPath, 'not-a-key');
    chatbot_local_key_assert(
        aics_chatbot_resolve_api_key('', $testPath) === '',
        'Reject invalid local key'
    );
    chatbot_local_key_assert(
        aics_chatbot_resolve_api_key('', $testDirectory . '/missing.key') === '',
        'Missing local key is empty'
    );

    $expectedSuffix = 'aics-private' . DIRECTORY_SEPARATOR . 'groq.key';
    chatbot_local_key_assert(
        str_ends_with(aics_chatbot_local_key_path(), $expectedSuffix),
        'Default key is outside the web project'
    );

    $setupPath = __DIR__ . '/../chatbot/setup-local.php';
    chatbot_local_key_assert(is_file($setupPath), 'Local setup page exists');
    $setup = file_get_contents($setupPath);
    chatbot_local_key_assert(str_contains($setup, "['127.0.0.1', '::1']"), 'Setup is loopback-only');
    chatbot_local_key_assert(str_contains($setup, 'hash_equals'), 'Setup validates CSRF token');
    chatbot_local_key_assert(str_contains($setup, 'type="password"'), 'Setup uses a password input');
    chatbot_local_key_assert(!preg_match('/gsk_[A-Za-z0-9]+/', $setup), 'Setup contains no embedded key');
} finally {
    if (is_file($testPath)) {
        unlink($testPath);
    }
    if (is_dir($testDirectory)) {
        rmdir($testDirectory);
    }
}

echo "AICS chatbot local key tests passed.\n";
