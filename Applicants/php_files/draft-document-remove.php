<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/draft-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    if (!aics_is_logged_in_applicat()) throw new RuntimeException('Unauthorized.');
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) throw new RuntimeException('Invalid request.');
    $draftId = trim((string) ($body['draft_id'] ?? ''));
    $documentType = trim((string) ($body['document_type'] ?? ''));
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    aics_ensure_draft_schema($connection);
    aics_remove_draft_document($connection, (int) $_SESSION['aics_account_id'], $draftId, $documentType);
    $connection->close();
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
