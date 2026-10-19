<?php
declare(strict_types=1);

// Live AICS AI check progress for one of the signed-in applicant's own requests.
// Polled by the Application Status page while a new application is being checked.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/ai-submission-gate.php';

aics_start_session();
if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Please sign in again.']);
    exit;
}
$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$requestId = (int) ($_GET['request_id'] ?? 0);
// Polling must not hold the session lock that other pages need.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $connection = aics_db_connect();
    $owner = $connection->prepare('SELECT 1 FROM aics_account_requests WHERE account_id = ? AND request_id = ? LIMIT 1');
    $owner->bind_param('ii', $accountId, $requestId);
    $owner->execute();
    $owns = (bool) $owner->get_result()?->fetch_row();
    $owner->close();
    if ($accountId <= 0 || $requestId <= 0 || !$owns) {
        $connection->close();
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Application not found.']);
        exit;
    }
    // Starts or retries this request's checks when no scheduler has done so yet.
    aics_ai_gate_sync($connection, $requestId);
    $progress = aics_ai_gate_applicant_progress($connection, $requestId);
    $connection->close();
    echo json_encode(['ok' => true] + $progress, JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('AICS AI check progress failed: category=' . $exception::class);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Progress is not available right now.']);
}
