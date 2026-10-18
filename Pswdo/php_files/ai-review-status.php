<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';
require_once __DIR__ . '/pswdo-ai-verdict-card.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$documentId = (int) ($_GET['document_id'] ?? 0);
$requestId = (int) ($_GET['request_id'] ?? 0);
if ($documentId <= 0 || $requestId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid verification status request.']);
    exit;
}

try {
    $connection = aics_db_connect();
    $stmt = $connection->prepare('SELECT ai.id, ai.status, ai.attempt_count, ai.confidence_score, ai.findings, ai.extracted_fields, ai.detected_document_type, ai.expected_document_type, ai.processed_at, ai.last_error_code, ais.tamper_detection AS supplement_tamper_detection, r.beneficiary_complete_name, r.beneficiary_birth_date, r.beneficiary_age, r.gender, r.beneficiary_barangay, r.beneficiary_sitio_purok, r.beneficiary_municipality, r.beneficiary_province FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id INNER JOIN aics_requests r ON r.id = d.request_id LEFT JOIN document_ai_review_image_supplements ais ON ais.ai_review_id = ai.id WHERE d.id = ? AND d.request_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to load verification status.');
    $stmt->bind_param('ii', $documentId, $requestId);
    $stmt->execute();
    $review = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    $connection->close();
    if (!is_array($review)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Verification record not found.']);
        exit;
    }
    $findings = json_decode((string) ($review['findings'] ?? ''), true);
    $imageReview = pswdo_tamper_analysis_json((string) ($review['findings'] ?? ''), (string) ($review['supplement_tamper_detection'] ?? ''));
    $extractedFields = json_decode((string) ($review['extracted_fields'] ?? ''), true);
    $reasons = is_array($findings) && is_array($findings['reasons'] ?? null) ? $findings['reasons'] : [];
    $status = strtolower(trim((string) ($review['status'] ?? 'failed')));
    $compactReview = pswdo_ai_compact_explanation($status, is_array($findings) ? $findings : [], $imageReview);
    $waiting = $status === 'processing' && (int) ($review['attempt_count'] ?? 0) === 0;
    $verdictView = aics_ai_verdict_view(is_array($findings) ? $findings : [], $status);
    $verdictHtml = pswdo_ai_verdict_card_html($verdictView, is_array($compactReview['date_facts'] ?? null) ? $compactReview['date_facts'] : null, pswdo_document_label((string) ($review['expected_document_type'] ?? '')));
    $beneficiaryAddressParts = array_values(array_filter(array_map(
        static fn (string $field): string => trim((string) ($review[$field] ?? '')),
        ['beneficiary_sitio_purok', 'beneficiary_barangay', 'beneficiary_municipality', 'beneficiary_province']
    ), static fn (string $value): bool => $value !== ''));
    $labels = ['processing' => $waiting ? 'Waiting for Review' : 'Reviewing Document', 'completed' => 'Review Complete', 'likely_valid' => 'Document Matched', 'needs_staff_review' => 'Staff Review Needed', 'needs_reupload' => 'Replace Document', 'flagged_inconsistency' => 'Flagged Inconsistency', 'failed' => 'AI Review Unavailable'];
    $summary = $status === 'processing'
        ? ($waiting ? 'Queued for AI document verification.' : 'AI document verification is in progress.')
        : (($verdictView['source'] ?? '') === 'gemini' ? $verdictView['headline'] . ' — ' . $verdictView['reason'] : trim((string) ($reasons[0] ?? '')));
    echo json_encode([
        'ok' => true,
        'document_id' => $documentId,
        'review_id' => (int) ($review['id'] ?? 0),
        'status' => $status,
        'status_label' => $labels[$status] ?? 'Technical Processing Error',
        'is_processing' => $status === 'processing',
        'queue_state' => $waiting ? 'waiting' : ($status === 'processing' ? 'analyzing' : 'terminal'),
        'attempt_count' => (int) ($review['attempt_count'] ?? 0),
        'can_reprocess' => $status !== 'processing',
        'summary' => $summary,
        'confidence_score' => $review['confidence_score'] !== null ? (float) $review['confidence_score'] : null,
        'processed_at' => $review['processed_at'] !== null ? (string) $review['processed_at'] : null,
        'expected_document_type' => (string) ($review['expected_document_type'] ?? ''),
        'detected_document_type' => (string) ($review['detected_document_type'] ?? ''),
        'beneficiary' => [
            'full_name' => trim((string) ($review['beneficiary_complete_name'] ?? '')),
            'date_of_birth' => (string) ($review['beneficiary_birth_date'] ?? ''),
            'age' => (int) ($review['beneficiary_age'] ?? 0),
            'sex' => trim((string) ($review['gender'] ?? '')),
            'address' => implode(', ', $beneficiaryAddressParts),
        ],
        'findings' => is_array($findings) ? $findings : [],
        'image_review' => $imageReview,
        'compact_review' => $compactReview,
        'verdict_html' => $verdictHtml,
        'extracted_fields' => is_array($extractedFields) ? $extractedFields : [],
    ]);
} catch (Throwable $exception) {
    error_log('AICS AI status endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to refresh verification status.']);
}
