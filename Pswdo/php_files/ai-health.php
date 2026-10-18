<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
pswdo_start_session();

$cacheKey = 'aics_ai_health_v3';
$cacheTtl = 45;
$cached = $_SESSION[$cacheKey] ?? null;
if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) >= time()) {
    echo json_encode($cached['payload'] ?? ['ok' => false]);
    exit;
}
// Health probing can import the worker module. The authenticated PSWDO
// session is no longer needed after cache lookup, so never hold its lock
// while database or runtime checks run.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $connection = aics_db_connect();
    $databaseAvailable = $connection->ping();
    $recentFailures = 0;
    $failureResult = $connection->query("SELECT COUNT(*) AS total FROM document_ai_reviews WHERE status = 'failed' AND processed_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)");
    if ($failureRow = $failureResult?->fetch_assoc()) {
        $recentFailures = (int) ($failureRow['total'] ?? 0);
    }
    $connection->close();
    $health = aics_ai_environment_health($databaseAvailable);
    if ($health['status'] === 'operational' && $recentFailures >= 3) {
        $health['components']['recent_failures'] = 'warning';
        $health['status'] = aics_ai_health_status_from_components($health['components']);
    }
    $payload = ['ok' => true, 'status' => $health['status'], 'checked_at' => gmdate('c'), 'components' => $health['components']];
} catch (Throwable $exception) {
    error_log('AICS AI health check error: ' . $exception->getMessage());
    $health = aics_ai_environment_health(false);
    $payload = ['ok' => true, 'status' => $health['status'], 'checked_at' => gmdate('c'), 'components' => $health['components']];
}
echo json_encode($payload);
