<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-password-reset-common.php';

if (getenv('AICS_HTTP_TEST_MODE') !== '1' || AICS_DB_NAME !== 'aics_http_regression') {
    throw new RuntimeException('Password reset HTTP test requires the isolated regression database.');
}

function reset_http_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{status:int,body:array<string,mixed>} */
function reset_http_request(string $url, array $body): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Requested-With: XMLHttpRequest\r\nConnection: close",
        'content' => json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $response = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $status = preg_match('/\s(\d{3})\s/', $statusLine, $match) === 1 ? (int) $match[1] : 0;
    return ['status' => $status, 'body' => is_string($response) ? (json_decode($response, true) ?: []) : []];
}

$connection = aics_db_connect();
aics_ensure_auth_schema($connection);
aics_ensure_password_reset_schema($connection);
$accountId = 0;
try {
    $role = $connection->query("SELECT id FROM aics_roles WHERE role_code = 'applicat'")->fetch_assoc();
    $status = $connection->query("SELECT id FROM aics_account_statuses WHERE status_code = 'active'")->fetch_assoc();
    $roleId = (int) $role['id'];
    $statusId = (int) $status['id'];
    $email = 'reset-regression-' . bin2hex(random_bytes(6)) . '@example.invalid';
    $phone = '09' . (string) random_int(100000000, 999999999);
    $oldHash = password_hash('Original2026', PASSWORD_DEFAULT);
    $create = $connection->prepare('INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)');
    $create->bind_param('iiss', $roleId, $statusId, $email, $oldHash);
    $create->execute();
    $accountId = (int) $connection->insert_id;
    $create->close();
    $profile = $connection->prepare('INSERT INTO aics_applicant_profiles (account_id, first_name, last_name, contact_number) VALUES (?, ?, ?, ?)');
    $first = 'Reset'; $last = 'Regression';
    $profile->bind_param('isss', $accountId, $first, $last, $phone);
    $profile->execute();
    $profile->close();
    $codeHash = password_hash('246810', PASSWORD_DEFAULT);
    $sent = time(); $expires = $sent + 300; $ip = '127.0.0.1';
    $seed = $connection->prepare('INSERT INTO aics_password_resets (account_id, code_hash, sent_at, expires_at, window_start, send_count, request_ip) VALUES (?, ?, ?, ?, ?, 1, ?)');
    $seed->bind_param('isiiis', $accountId, $codeHash, $sent, $expires, $sent, $ip);
    $seed->execute();
    $seed->close();
    $event = $connection->prepare('INSERT INTO aics_password_reset_sends (account_id, request_ip, sent_at) VALUES (?, ?, ?)');
    for ($index = 0; $index < 5; $index++) {
        $event->bind_param('isi', $accountId, $ip, $sent);
        $event->execute();
    }
    $event->close();
    reset_http_assert(aics_count_recent_reset_sends($connection, $ip, $sent - 900) === 5, 'IP limit did not count repeat sends to one account.');

    $endpoint = rtrim((string) getenv('AICS_HTTP_BASE_URL'), '/') . '/php_files/auth-reset-password.php';
    $wrong = reset_http_request($endpoint, ['action' => 'reset', 'phone' => $phone, 'code' => '111111', 'password' => 'NewPassword2026', 'confirm_password' => 'NewPassword2026']);
    reset_http_assert($wrong['status'] === 400, 'Wrong code was accepted.');
    $attempts = $connection->query('SELECT failed_attempts FROM aics_password_resets WHERE account_id = ' . $accountId)->fetch_assoc();
    reset_http_assert((int) $attempts['failed_attempts'] === 1, 'Wrong code did not count toward attempt limit.');

    $good = reset_http_request($endpoint, ['action' => 'reset', 'phone' => $phone, 'code' => '246810', 'password' => 'NewPassword2026', 'confirm_password' => 'NewPassword2026']);
    reset_http_assert($good['status'] === 200 && ($good['body']['success'] ?? false) === true, 'Correct code did not reset password.');
    $account = $connection->query('SELECT password_hash FROM aics_accounts WHERE id = ' . $accountId)->fetch_assoc();
    reset_http_assert(password_verify('NewPassword2026', $account['password_hash']), 'New password hash was not saved.');
    reset_http_assert(!password_verify('Original2026', $account['password_hash']), 'Old password still verifies.');
    aics_start_session();
    $_SESSION['aics_account_id'] = $accountId;
    $_SESSION['aics_role_code'] = 'applicat';
    $_SESSION['aics_auth_revision'] = 0;
    $_SESSION['aics_session_started_at'] = time();
    reset_http_assert(aics_session_is_invalidated($connection, $accountId), 'Reset did not invalidate an existing session.');
    reset_http_assert(aics_refresh_session_status_for_role('applicat') === null, 'Applicant endpoint guard still accepts an old session.');
    aics_set_user_session($accountId, $email, 'applicat', 'Reset Regression', 1);
    reset_http_assert(!aics_session_is_invalidated($connection, $accountId), 'Fresh login with new revision was rejected.');
    reset_http_assert(aics_refresh_session_status_for_role('applicat') === 'active', 'Fresh applicant session was rejected.');

    $replay = reset_http_request($endpoint, ['action' => 'reset', 'phone' => $phone, 'code' => '246810', 'password' => 'AnotherPassword2026', 'confirm_password' => 'AnotherPassword2026']);
    reset_http_assert($replay['status'] === 400, 'Used code was accepted again.');
    echo "Applicant password reset HTTP test passed.\n";
} finally {
    if ($accountId > 0) {
        $cleanup = $connection->prepare('DELETE FROM aics_accounts WHERE id = ? AND email LIKE ?');
        $marker = 'reset-regression-%@example.invalid';
        $cleanup->bind_param('is', $accountId, $marker);
        $cleanup->execute();
        $cleanup->close();
    }
    $connection->close();
}
