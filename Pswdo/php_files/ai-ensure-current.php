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
$requestId = (int) ($_POST['request_id'] ?? 0);
$documentId = (int) ($_POST['document_id'] ?? 0);
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf) || $requestId <= 0 || $documentId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unable to ensure AI verification. Please refresh and try again.']);
    exit;
}

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);
    if (!aics_ai_gate_is_released($connection, $requestId)) {
        $connection->close();
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Request was not found.']);
        exit;
    }
    $cachedHealth = $_SESSION['aics_ai_health_v3']['payload'] ?? null;
    $canLaunch = aics_ai_health_allows_processing(is_array($cachedHealth) ? $cachedHealth : null);
    $stmt = $connection->prepare('SELECT d.id, d.document_type FROM aics_request_documents d WHERE d.id = ? AND d.request_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to load uploaded documents.');
    $stmt->bind_param('ii', $documentId, $requestId);
    $stmt->execute();
    $documents = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $queued = [];
    $skippedUnavailable = [];
    foreach ($documents as $document) {
        $currentDocumentId = (int) ($document['id'] ?? 0);
        // Health availability is advisory for processing only. Do not create a
        // synthetic failed review simply because staff opened a request while
        // the service was down; a future healthy ensure will create it.
        if (!$canLaunch) {
            $skippedUnavailable[] = $currentDocumentId;
            continue;
        }
        $result = aics_ensure_current_ai_review($connection, $currentDocumentId, (string) ($document['document_type'] ?? ''));
        if (!($result['should_launch'] ?? false)) continue;
        $documentId = (int) $result['request_document_id'];
        $reviewId = (int) $result['review_id'];
        if (!aics_launch_document_ai_processor($documentId)) {
            aics_mark_ai_review_failed($connection, $reviewId, 'AI_PROCESSOR_LAUNCH_FAILED', 'AI verification is temporarily unavailable. Please use the normal review process while service is restored.');
            continue;
        }
        $queued[] = ['document_id' => $documentId, 'review_id' => $reviewId, 'status' => 'processing'];
    }
    $connection->close();
    echo json_encode(['ok' => true, 'request_id' => $requestId, 'queued' => $queued, 'skipped_unavailable' => $skippedUnavailable]);
} catch (Throwable $exception) {
    error_log('AICS ensure current AI review error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to ensure AI verification right now.']);
}
