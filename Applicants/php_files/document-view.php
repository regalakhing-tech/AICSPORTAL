<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/secure-document-response.php';

if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    exit('Unauthorized.');
}

$requestId = (int) ($_GET['request_id'] ?? 0);
$documentId = (int) ($_GET['document_id'] ?? 0);
if ($requestId <= 0 || $documentId <= 0) {
    http_response_code(400);
    exit('Invalid document selection.');
}

try {
    $connection = aics_db_connect();
    $stmt = $connection->prepare(
        'SELECT d.document_type, d.stored_name, r.reference_no
         FROM aics_request_documents d
         INNER JOIN aics_requests r ON r.id = d.request_id
         INNER JOIN aics_account_requests ar ON ar.request_id = r.id
         WHERE d.id = ? AND d.request_id = ? AND ar.account_id = ? LIMIT 1'
    );
    $accountId = (int) $_SESSION['aics_account_id'];
    $stmt->bind_param('iii', $documentId, $requestId, $accountId); $stmt->execute();
    $document = $stmt->get_result()?->fetch_assoc(); $stmt->close(); $connection->close();
    if (!is_array($document)) {
        http_response_code(404);
        exit('Document not found.');
    }
    $root = __DIR__ . '/../uploads/' . basename((string) $document['reference_no']);
    $file = $root . DIRECTORY_SEPARATOR . basename((string) $document['stored_name']);
    // A replaced document gets a new id and address, so this one never changes: keep it for a sign-in session.
    aics_serve_document_file($file, $root, aics_document_requirement_label((string) $document['document_type']), 14400);
} catch (Throwable $exception) {
    error_log('Applicant submitted document preview failed.');
    http_response_code(500);
    exit('Unable to preview the document.');
}
