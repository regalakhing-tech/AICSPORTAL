<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/draft-lib.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/secure-document-response.php';

if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    exit('Unauthorized.');
}

$documentId = trim((string) ($_GET['document_id'] ?? ''));
if (!preg_match('/^[a-f0-9-]{36}$/i', $documentId)) {
    http_response_code(400);
    exit('Invalid document selection.');
}

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    aics_ensure_draft_schema($connection);
    $accountId = (int) $_SESSION['aics_account_id'];
    $stmt = $connection->prepare(
        "SELECT d.draft_id, d.document_type, d.stored_name
         FROM aics_application_draft_documents d
         INNER JOIN aics_application_drafts a ON a.id = d.draft_id
         WHERE d.id = ? AND a.account_id = ? AND a.status IN ('active','finalizing') AND a.expires_at >= NOW()
         LIMIT 1"
    );
    $stmt->bind_param('si', $documentId, $accountId); $stmt->execute();
    $document = $stmt->get_result()?->fetch_assoc(); $stmt->close(); $connection->close();
    if (!is_array($document)) {
        http_response_code(404);
        exit('Document not found.');
    }

    $draftRoot = aics_draft_upload_root();
    $file = $draftRoot . DIRECTORY_SEPARATOR . basename((string) $document['draft_id']) . DIRECTORY_SEPARATOR . basename((string) $document['stored_name']);
    aics_serve_document_file($file, $draftRoot, aics_document_requirement_label((string) $document['document_type']));
} catch (Throwable $exception) {
    error_log('Applicant draft preview failed.');
    http_response_code(500);
    exit('Unable to preview the document.');
}
