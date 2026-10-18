<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-password-reset-common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reset_reply(int $status, bool $success, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
    reset_reply(405, false, 'This request is not allowed.');
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) reset_reply(400, false, 'Invalid request.');
$action = (string) ($input['action'] ?? '');
$phone = aics_normalize_mobile_number((string) ($input['phone'] ?? ''));
if ($phone === null) reset_reply(400, false, 'Enter a valid Philippine mobile number.');
if ($action !== 'send' && $action !== 'reset') reset_reply(400, false, 'Invalid request.');

aics_start_session();
$now = time();
$connection = null;

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    aics_ensure_password_reset_schema($connection);
    $connection->begin_transaction();

    $lookup = $connection->prepare("SELECT a.id FROM aics_applicant_profiles p INNER JOIN aics_accounts a ON a.id = p.account_id INNER JOIN aics_roles r ON r.id = a.role_id INNER JOIN aics_account_statuses s ON s.id = a.account_status_id WHERE p.contact_number = ? AND r.role_code = 'applicat' AND s.status_code = 'active' LIMIT 1");
    $lookup->bind_param('s', $phone);
    $lookup->execute();
    $account = $lookup->get_result()?->fetch_assoc();
    $lookup->close();
    if ($account) {
        $accountId = (int) $account['id'];
        $lock = $connection->prepare('SELECT id FROM aics_accounts WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $accountId);
        $lock->execute();
        if (!$lock->get_result()?->fetch_assoc()) $account = null;
        $lock->close();
    }

    if ($action === 'send') {
        $generic = 'If this mobile number belongs to an active applicant account, a reset code will be sent by SMS.';
        if (!$account) {
            $connection->commit();
            reset_reply(200, true, $generic, ['cooldown_seconds' => 60]);
        }
        $accountId = (int) $account['id'];
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $rate = $connection->prepare('SELECT COUNT(*) AS total FROM aics_password_resets WHERE request_ip = ? AND sent_at > ?');
        $since = $now - 900;
        $rate->bind_param('si', $ip, $since);
        $rate->execute();
        $ipCount = (int) ($rate->get_result()?->fetch_assoc()['total'] ?? 0);
        $rate->close();
        $existing = $connection->prepare('SELECT sent_at, window_start, send_count FROM aics_password_resets WHERE account_id = ? FOR UPDATE');
        $existing->bind_param('i', $accountId);
        $existing->execute();
        $previous = $existing->get_result()?->fetch_assoc();
        $existing->close();
        $windowStart = $previous && $now - (int) $previous['window_start'] < 900 ? (int) $previous['window_start'] : $now;
        $sendCount = $previous && $windowStart !== $now ? (int) $previous['send_count'] : 0;
        if ($ipCount >= 5 || $sendCount >= 5 || ($previous && $now - (int) $previous['sent_at'] < 60)) {
            $connection->commit();
            reset_reply(200, true, $generic, ['cooldown_seconds' => 60]);
        }
        $code = sprintf('%06d', random_int(0, 999999));
        if (!aics_send_password_reset_sms($phone, $code)) {
            $connection->rollback();
            reset_reply(503, false, 'SMS could not be sent. Please try again shortly.');
        }
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $expires = $now + 300;
        $sendCount++;
        $save = $connection->prepare('INSERT INTO aics_password_resets (account_id, code_hash, sent_at, expires_at, failed_attempts, window_start, send_count, request_ip) VALUES (?, ?, ?, ?, 0, ?, ?, ?) ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), sent_at = VALUES(sent_at), expires_at = VALUES(expires_at), failed_attempts = 0, window_start = VALUES(window_start), send_count = VALUES(send_count), request_ip = VALUES(request_ip)');
        $save->bind_param('isiiiis', $accountId, $hash, $now, $expires, $windowStart, $sendCount, $ip);
        $save->execute();
        $save->close();
        $connection->commit();
        reset_reply(200, true, $generic, ['cooldown_seconds' => 60]);
    }

    $code = trim((string) ($input['code'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirmation = (string) ($input['confirm_password'] ?? '');
    $policyError = aics_password_policy_error($password);
    if ($policyError !== null || $password !== $confirmation) {
        $connection->rollback();
        reset_reply(400, false, $policyError ?? 'Passwords do not match.');
    }
    if (!$account) {
        $connection->rollback();
        reset_reply(400, false, 'The code is invalid or expired. Request a new code.');
    }
    $accountId = (int) $account['id'];
    $attempt = $connection->prepare('SELECT code_hash, expires_at, failed_attempts FROM aics_password_resets WHERE account_id = ? FOR UPDATE');
    $attempt->bind_param('i', $accountId);
    $attempt->execute();
    $row = $attempt->get_result()?->fetch_assoc();
    $attempt->close();
    $status = aics_reset_otp_status($row, $code, $now);
    if ($status !== 'valid') {
        if ($row && $status === 'invalid_otp') {
            $failure = $connection->prepare('UPDATE aics_password_resets SET failed_attempts = failed_attempts + 1 WHERE account_id = ?');
            $failure->bind_param('i', $accountId);
            $failure->execute();
            $failure->close();
        }
        $connection->commit();
        reset_reply(400, false, $status === 'otp_locked' ? 'Too many incorrect codes. Request a new code.' : 'The code is invalid or expired. Request a new code.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $connection->prepare('UPDATE aics_accounts SET password_hash = ?, auth_revision = auth_revision + 1, session_invalidate_at = NOW() WHERE id = ?');
    $update->bind_param('si', $hash, $accountId);
    $update->execute();
    $update->close();
    $consume = $connection->prepare("UPDATE aics_password_resets SET code_hash = '', expires_at = 0 WHERE account_id = ?");
    $consume->bind_param('i', $accountId);
    $consume->execute();
    $consume->close();
    $connection->commit();
    reset_reply(200, true, 'Password reset. You can now sign in with your new password.');
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) @$connection->rollback();
    error_log('Applicant password reset failed: ' . $exception->getMessage());
    reset_reply(503, false, 'Password reset is temporarily unavailable. Please try again.');
} finally {
    if ($connection instanceof mysqli) $connection->close();
}
