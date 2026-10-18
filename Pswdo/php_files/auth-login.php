<?php
declare(strict_types=1);

require_once __DIR__ . '/pswdo-common.php';

pswdo_start_session();

function pswdo_redirect_login(string $authCode): void
{
    header('Location: ../login.php?auth=' . urlencode($authCode));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pswdo_redirect_login('login_failed');
}

$email = filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = (string) ($_POST['password'] ?? '');

if ($email === false || trim($password) === '') {
    pswdo_redirect_login('missing_fields');
}

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);

    $sql = <<<SQL
        SELECT
            a.id,
            a.email,
            a.password_hash,
            r.role_code,
            s.status_code,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS display_name
        FROM aics_accounts a
        INNER JOIN aics_roles r ON r.id = a.role_id
        INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = a.id
        WHERE a.email = ?
          AND r.role_code = 'pswdo'
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $account = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!is_array($account) || !password_verify($password, (string) ($account['password_hash'] ?? ''))) {
        aics_record_login_attempt($connection, (string) $email, false, 'invalid_credentials', 'pswdo', 'pswdo');
        $connection->close();
        pswdo_redirect_login('invalid_credentials');
    }

    $statusCode = strtolower(trim((string) ($account['status_code'] ?? 'active')));
    if ($statusCode !== 'active') {
        aics_record_login_attempt($connection, (string) $email, false, 'status_' . $statusCode, 'pswdo', 'pswdo');
        $connection->close();
        pswdo_redirect_login(aics_account_status_auth_code($statusCode));
    }

    $displayName = trim((string) ($account['display_name'] ?? 'PSWDO Personnel'));
    if ($displayName === '') {
        $displayName = 'PSWDO Personnel';
    }

    aics_record_login_attempt($connection, (string) $email, true, 'success', 'pswdo', 'pswdo');
    session_regenerate_id(true);
    aics_set_user_session((int) $account['id'], (string) $account['email'], 'pswdo', $displayName);
    $connection->close();

    header('Location: ../dashboard.php?page=overview');
    exit;
} catch (Throwable $exception) {
    $message = strtolower(trim($exception->getMessage()));
    if (str_contains($message, 'database connection failed')) {
        pswdo_redirect_login('database_unavailable');
    }

    pswdo_redirect_login('login_failed');
}
