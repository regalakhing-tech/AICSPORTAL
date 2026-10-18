<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
pswdo_start_session();
$csrf = trim((string) ($_POST['csrf_token'] ?? ''));
$sessionCsrf = trim((string) ($_SESSION['aics_pswdo_csrf'] ?? ''));
$requestId = (int) ($_POST['request_id'] ?? 0);
if ($requestId <= 0 || $csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Unable to prepare current AI verification.']); exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $connection = aics_db_connect();
    $exists = $connection->prepare('SELECT id FROM aics_requests WHERE id = ? LIMIT 1');
    if (!$exists) throw new RuntimeException('Request lookup unavailable.');
    $exists->bind_param('i', $requestId); $exists->execute(); $request = $exists->get_result()?->fetch_assoc(); $exists->close();
    if (!is_array($request)) { $connection->close(); http_response_code(404); echo json_encode(['ok' => false, 'message' => 'Request was not found.']); exit; }
    $ensure = aics_ensure_current_ai_reviews_for_request($connection, $requestId);
    if ($ensure['worker_needed'] && !aics_launch_request_ai_processor($requestId, (string) $ensure['worker_lease_token'])) {
        foreach ($ensure['queued'] as $item) aics_mark_ai_review_failed($connection, (int) $item['review_id'], 'AI_PROCESSOR_LAUNCH_FAILED', 'Local verification could not be started.');
        $release = $connection->prepare('DELETE FROM document_ai_request_worker_leases WHERE request_id = ? AND lease_token = ?');
        if ($release) { $leaseToken = (string) $ensure['worker_lease_token']; $release->bind_param('is', $requestId, $leaseToken); $release->execute(); $release->close(); }
        $connection->close(); http_response_code(503); echo json_encode(['ok' => false, 'message' => 'AI verification could not be started.']); exit;
    }
    $connection->close();
    echo json_encode(['ok' => true, 'request_id' => $requestId, 'queued' => $ensure['queued'], 'worker_needed' => (bool) $ensure['worker_needed']]);
} catch (Throwable $exception) {
    error_log('AICS request AI ensure error: ' . $exception->getMessage());
    http_response_code(500); echo json_encode(['ok' => false, 'message' => 'Unable to prepare current AI verification.']);
}
