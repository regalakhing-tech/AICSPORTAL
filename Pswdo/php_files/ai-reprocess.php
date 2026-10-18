<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Invalid request method.']);
    exit;
}
pswdo_start_session();
$csrf = trim((string) ($_POST['csrf_token'] ?? ''));
$sessionCsrf = trim((string) ($_SESSION['aics_pswdo_csrf'] ?? ''));
$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$reviewId = (int) ($_POST['ai_review_id'] ?? 0);
$requestId = (int) ($_POST['request_id'] ?? 0);
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf) || $accountId <= 0 || $reviewId <= 0 || $requestId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unable to start AI verification. Please refresh and retry.']);
    exit;
}

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);
    $result = aics_reprocess_ai_review($connection, $reviewId, $accountId, $requestId);
    $documentId = (int) ($result['request_document_id'] ?? 0);
    if (!aics_launch_document_ai_processor($documentId)) {
        aics_mark_ai_review_failed($connection, $reviewId, 'AI_PROCESSOR_LAUNCH_FAILED', 'Local verification could not be started. Please retry or use manual review.');
        echo json_encode(['ok' => false, 'document_id' => $documentId, 'review_id' => $reviewId, 'status' => 'failed', 'status_label' => 'Failed', 'is_processing' => false, 'can_reprocess' => true, 'summary' => 'Local verification could not be started. Please retry.', 'confidence_score' => null, 'findings' => []]);
        $connection->close();
        exit;
    }
    $connection->close();
    echo json_encode(['ok' => true, 'document_id' => $documentId, 'review_id' => $reviewId, 'status' => 'processing', 'status_label' => 'Processing', 'is_processing' => true, 'can_reprocess' => false, 'summary' => 'Awaiting local verification', 'confidence_score' => null, 'findings' => []]);
} catch (Throwable $exception) {
    error_log('AICS in-place AI reprocess error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to start AI verification. Please retry or use manual review.']);
}

