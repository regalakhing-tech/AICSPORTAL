<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';

aics_start_session();
aics_require_applicat_login();

$requestId = (int) ($_GET['request_id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$expectedToken = (string) ($_SESSION['aics_edit_entry_tokens'][(string) $requestId] ?? '');
if ($requestId <= 0 || $expectedToken === '' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    exit('Edit authorization is not available. Open the application from Manage Applications.');
}

$connection = aics_db_connect();
try {
    aics_ensure_auth_schema($connection);
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $stmt = $connection->prepare('SELECT r.request_status FROM aics_requests r INNER JOIN aics_account_requests ar ON ar.request_id = r.id WHERE ar.account_id = ? AND r.id = ? LIMIT 1');
    $stmt->bind_param('ii', $accountId, $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    $blocked = ['approved','validated','for_release','for release','released','distributed','completed','closed','rejected','denied','not_approved','not approved','cancelled','canceled'];
    if (!is_array($row) || in_array(strtolower(trim((string) ($row['request_status'] ?? ''))), $blocked, true)) {
        http_response_code(403);
        exit('This application is no longer eligible for editing.');
    }
    $_SESSION['aics_edit_request_grants'][(string) $requestId] = time() + 900;
    unset($_SESSION['aics_edit_entry_tokens'][(string) $requestId]);
    header('Location: submit-request.php?edit_request_id=' . $requestId, true, 302);
} finally {
    $connection->close();
}
