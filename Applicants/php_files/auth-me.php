<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth-common.php';

aics_start_session();

if (!aics_has_role_session('applicat')) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$statusCode = aics_refresh_session_status_for_role('applicat');
if ($statusCode === null) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$displayName = (string) ($_SESSION['aics_display_name'] ?? 'Applicat');
$email = (string) ($_SESSION['aics_email'] ?? '');
$bannedModalRequired = ((int) ($_SESSION['aics_banned_modal_required'] ?? 0)) === 1;

http_response_code(200);
echo json_encode([
    'ok' => true,
    'display_name' => $displayName,
    'email' => $email,
    'status_code' => $statusCode,
    'banned_modal_required' => $bannedModalRequired,
], JSON_UNESCAPED_UNICODE);
exit;
