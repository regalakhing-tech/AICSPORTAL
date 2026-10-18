<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/dashboard-data-lib.php';

aics_start_session();

if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
if ($accountId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid session',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $payload = aics_fetch_applicant_dashboard_data(
        $accountId,
        (string) ($_SESSION['aics_display_name'] ?? 'Applicant')
    );

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'dashboard' => $payload,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load dashboard data.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
