<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/pswdo-common.php';

pswdo_start_session();

if (!isset($_SESSION['aics_account_id']) || (string) ($_SESSION['aics_role_code'] ?? '') !== 'pswdo') {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$displayName = (string) ($_SESSION['aics_display_name'] ?? 'PSWDO Personnel');
$email = (string) ($_SESSION['aics_email'] ?? '');

http_response_code(200);
echo json_encode([
    'ok' => true,
    'display_name' => $displayName,
    'email' => $email,
], JSON_UNESCAPED_UNICODE);
