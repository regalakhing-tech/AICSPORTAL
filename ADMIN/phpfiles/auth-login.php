<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

admin_set_security_headers(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect_login('invalid_method');
}

admin_start_session();
if (!admin_validate_csrf((string) ($_POST['_csrf'] ?? ''))) {
    admin_redirect_login('invalid_request');
}

$email = filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = (string) ($_POST['password'] ?? '');

if ($email === false || trim($password) === '') {
    admin_redirect_login('missing_fields');
}

$connection = null;

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $lockState = admin_login_lock_state($connection, $email);
    if ($lockState['locked']) {
        admin_record_login_attempt($connection, $email, false, 'locked');
        admin_log_event(
            $connection,
            'login_locked',
            'warning',
            'Admin login blocked due to repeated failed attempts.',
            null,
            null,
            ['email' => $email, 'retry_after_seconds' => $lockState['retry_after_seconds']]
        );
        $connection->close();

        admin_redirect_login('locked', ['retry' => $lockState['retry_after_seconds']]);
    }

    $sql = <<<SQL
        SELECT
            a.id,
            a.email,
            a.password_hash,
            s.status_code,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS display_name
        FROM aics_accounts a
        INNER JOIN aics_roles r ON r.id = a.role_id
        INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = a.id
        WHERE a.email = ?
          AND r.role_code = 'admin'
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $account = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!is_array($account) || !password_verify($password, (string) ($account['password_hash'] ?? ''))) {
        admin_record_login_attempt($connection, $email, false, 'invalid_credentials');
        admin_log_event(
            $connection,
            'login_failed',
            'failure',
            'Invalid admin credentials submitted.',
            null,
            null,
            ['email' => $email]
        );
        $connection->close();

        admin_redirect_login('invalid_credentials');
    }

    $statusCode = strtolower(trim((string) ($account['status_code'] ?? 'active')));
    if ($statusCode !== 'active') {
        $authCode = aics_account_status_auth_code($statusCode);
        admin_record_login_attempt($connection, $email, false, 'inactive_account');
        admin_log_event(
            $connection,
            'login_denied_status',
            'warning',
            'Admin login denied due to account status.',
            (int) ($account['id'] ?? 0),
            (int) ($account['id'] ?? 0),
            ['email' => $email, 'status_code' => $statusCode]
        );
        $connection->close();

        admin_redirect_login($authCode);
    }

    $accountId = (int) ($account['id'] ?? 0);
    $displayName = trim((string) ($account['display_name'] ?? 'System Administrator'));
    if ($displayName === '') {
        $displayName = 'System Administrator';
    }

    admin_start_session();
    session_regenerate_id(true);
    aics_set_user_session($accountId, (string) $account['email'], 'admin', $displayName);
    $_SESSION['admin_fingerprint'] = admin_session_fingerprint();
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));

    admin_record_login_attempt($connection, $email, true, 'success');
    admin_log_event(
        $connection,
        'login_success',
        'success',
        'Admin user logged in successfully.',
        $accountId,
        $accountId
    );
    $connection->close();

    admin_redirect('../dashboard.php', ['page' => 'overview', 'status' => 'login_success']);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        $connection->close();
    }

    admin_try_log_event(
        'login_error',
        'failure',
        'Admin login encountered an unexpected error.',
        null,
        null,
        ['email' => (string) $email]
    );

    $message = strtolower(trim($exception->getMessage()));
    if (str_contains($message, 'database connection failed')) {
        admin_redirect_login('database_unavailable');
    }

    admin_redirect_login('login_failed');
}
