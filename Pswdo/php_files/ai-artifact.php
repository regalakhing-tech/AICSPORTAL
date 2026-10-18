<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';
require_once __DIR__ . '/../../includes/document-ai-artifacts.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

$request = aics_ai_artifact_request($_GET);
if ($request === null) {
    http_response_code(404);
    exit;
}

try {
    $connection = aics_db_connect();
    $statement = $connection->prepare(
        'SELECT ai.id, ai.request_document_id, ai.status, ai.findings, ais.tamper_detection AS supplement_tamper_detection
         FROM document_ai_reviews ai
         INNER JOIN aics_request_documents d ON d.id = ai.request_document_id
         LEFT JOIN document_ai_review_image_supplements ais ON ais.ai_review_id = ai.id
         WHERE ai.id = ? AND ai.request_document_id = d.id
         LIMIT 1'
    );
    if (!$statement) throw new RuntimeException('Review lookup unavailable.');
    $statement->bind_param('i', $request['review_id']);
    $statement->execute();
    $review = $statement->get_result()?->fetch_assoc();
    $statement->close();
    $connection->close();

    // Old completed reviews legitimately have no Stage A metadata and are not
    // reprocessed by this read-only route.
    if (!is_array($review) || !in_array((string) ($review['status'] ?? ''), aics_ai_review_statuses(), true)
        || !aics_ai_artifact_available((string) ($review['findings'] ?? ''), $request['page'], $request['type'], (string) ($review['supplement_tamper_detection'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    $file = aics_ai_artifact_file(aics_ai_artifact_root(), $request['review_id'], $request['page'], $request['type']);
    if ($file === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
} catch (Throwable $exception) {
    error_log('AICS private AI artifact retrieval failed.');
    if (!headers_sent()) http_response_code(404);
}
