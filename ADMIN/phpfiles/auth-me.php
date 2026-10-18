<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

admin_set_security_headers(false);
admin_start_session();

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Not authenticated as admin.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'account_id' => (int) ($_SESSION['aics_account_id'] ?? 0),
    'email' => (string) ($_SESSION['aics_email'] ?? ''),
    'display_name' => admin_current_display_name(),
    'role_code' => (string) ($_SESSION['aics_role_code'] ?? ''),
]);
