<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/secure-document-response.php';

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
         WHERE d.id = ? AND d.request_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $documentId, $requestId); $stmt->execute();
    $document = $stmt->get_result()?->fetch_assoc(); $stmt->close(); $connection->close();
    if (!is_array($document)) {
        http_response_code(404);
        exit('Document not found.');
    }
    $root = __DIR__ . '/../../Applicants/uploads/' . basename((string) $document['reference_no']);
    $file = $root . DIRECTORY_SEPARATOR . basename((string) $document['stored_name']);
    aics_serve_document_file($file, $root, aics_document_requirement_label((string) $document['document_type']));
} catch (Throwable $exception) {
    error_log('PSWDO document preview failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to preview the document.');
}
