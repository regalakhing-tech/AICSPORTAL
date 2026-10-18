<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Invalid verification progress request.']);
    exit;
}

$requestId = (int) ($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid verification progress request.']);
    exit;
}

try {
    $connection = aics_db_connect();
    aics_ensure_ai_review_schema($connection);
    $exists = $connection->prepare('SELECT id FROM aics_requests WHERE id = ? LIMIT 1');
    if (!$exists) throw new RuntimeException('Request lookup unavailable.');
    $exists->bind_param('i', $requestId);
    $exists->execute();
    $request = $exists->get_result()?->fetch_assoc();
    $exists->close();
    if (!is_array($request)) {
        $connection->close();
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Request was not found.']);
        exit;
    }

    $progress = aics_ai_request_progress($connection, $requestId);
    $connection->close();
    echo json_encode(['ok' => true] + $progress, JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('AICS AI request progress error: category=' . $exception::class);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to refresh verification progress.']);
}
