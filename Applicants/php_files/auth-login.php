<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aics_redirect_with_message('../login.php', 'invalid_method');
}

$identifierRaw = trim((string) ($_POST['login_identifier'] ?? ($_POST['email'] ?? '')));
$password = (string)($_POST['password'] ?? '');

if ($identifierRaw === '' || $password === '') {
    aics_redirect_with_message('../login.php', 'missing_fields');
}

$email = aics_normalize_email_address($identifierRaw);
$phone = $email === null ? aics_normalize_mobile_number($identifierRaw) : null;
if ($email === null && $phone === null) {
    aics_redirect_with_message('../login.php', 'invalid_credentials');
}

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);

    $lookupColumn = $email !== null ? 'a.email' : 'p.contact_number';
    $lookupValue = $email ?? $phone;
    $sql = <<<SQL
        SELECT a.id, a.email, a.password_hash, a.auth_revision, r.role_code, s.status_code,
               CONCAT_WS(' ', p.first_name, p.last_name) AS display_name
        FROM aics_accounts a
        INNER JOIN aics_roles r ON r.id = a.role_id
        INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
        LEFT JOIN aics_applicant_profiles p ON p.account_id = a.id
        WHERE {$lookupColumn} = ?
          AND r.role_code = 'applicat'
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('s', $lookupValue);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$account || !password_verify($password, (string) $account['password_hash'])) {
        aics_record_login_attempt($connection, $identifierRaw, false, 'invalid_credentials', 'applicat', 'applicant');
        $connection->close();
        aics_redirect_with_message('../login.php', 'invalid_credentials');
    }

    $statusCode = strtolower(trim((string) ($account['status_code'] ?? 'active')));
    if ($statusCode !== 'active') {
        aics_record_login_attempt($connection, $identifierRaw, false, 'status_' . $statusCode, 'applicat', 'applicant');
        $connection->close();
        aics_redirect_with_message('../login.php', aics_account_status_auth_code($statusCode));
    }

    $displayName = trim((string)($account['display_name'] ?? 'Applicant'));
    if ($displayName === '') {
        $displayName = 'Applicant';
    }

    aics_record_login_attempt($connection, $identifierRaw, true, 'success', 'applicat', 'applicant');
    $connection->close();

    aics_start_session();
    session_regenerate_id(true);
    aics_set_user_session((int)$account['id'], trim((string) ($account['email'] ?? '')), (string)$account['role_code'], $displayName, (int) ($account['auth_revision'] ?? 0));
    if (aics_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'redirect' => 'dashboard.php?page=overview',
        ]);
        exit;
    }
    header('Location: ../dashboard.php?page=overview');
    exit;
} catch (Throwable $exception) {
    $message = strtolower(trim($exception->getMessage()));
    if (str_contains($message, 'database connection failed')) {
        aics_redirect_with_message('../login.php', 'database_unavailable');
    }

    aics_redirect_with_message('../login.php', 'login_failed');
}
