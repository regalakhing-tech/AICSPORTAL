<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

admin_set_security_headers(false);
admin_start_session();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['aics_account_id']) || (string) ($_SESSION['aics_role_code'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized.',
    ]);
    exit;
}

try {
    admin_refresh_session_state();
} catch (RuntimeException $exception) {
    admin_destroy_session();
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Session expired. Please log in again.',
    ]);
    exit;
}

$period = admin_normalize_period((string) ($_GET['period'] ?? 'day'));

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $metricsPayload = admin_fetch_activity_metrics($connection, $period);
    $trendPayload = admin_fetch_activity_trend($connection, $period);
    $connection->close();

    echo json_encode([
        'ok' => true,
        'period' => [
            'code' => (string) ($metricsPayload['period_code'] ?? $period),
            'label' => (string) ($metricsPayload['period_label'] ?? 'Today'),
            'date_from' => (string) ($metricsPayload['date_from'] ?? date('Y-m-d')),
            'date_to' => (string) ($metricsPayload['date_to'] ?? date('Y-m-d')),
        ],
        'metrics' => (array) ($metricsPayload['metrics'] ?? []),
        'trend' => [
            'points' => (array) ($trendPayload['points'] ?? []),
            'max_count' => (int) ($trendPayload['max_count'] ?? 1),
        ],
        'generated_at' => date('c'),
        'generated_at_label' => admin_format_datetime(date('Y-m-d H:i:s')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load overview analytics right now.',
    ]);
}
