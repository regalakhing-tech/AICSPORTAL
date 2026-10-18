<?php
declare(strict_types=1);

require_once __DIR__ . '/document-requirements.php';

const AICS_AI_REVIEW_MAX_ATTEMPTS = 3;

/** @return array<int,string> */
function aics_ai_review_statuses(): array { return ['processing', 'completed', 'likely_valid', 'needs_staff_review', 'needs_reupload', 'flagged_inconsistency', 'failed']; }

/** @return array<int,string> */
function aics_ai_processing_stages(): array
{
    return ['queued', 'preprocessing', 'ocr', 'verifying', 'tamper_scan', 'completed', 'failed'];
}

function aics_ai_stage_progress(string $stage): int
{
    return [
        'queued' => 0,
        'preprocessing' => 10,
        'ocr' => 35,
        'verifying' => 65,
        'tamper_scan' => 85,
        'completed' => 100,
        'failed' => 100,
    ][strtolower(trim($stage))] ?? 0;
}

function aics_ensure_ai_review_schema(mysqli $connection): void
{
    $connection->query("CREATE TABLE IF NOT EXISTS document_ai_reviews (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, request_document_id INT UNSIGNED NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'processing', processing_stage VARCHAR(30) NOT NULL DEFAULT 'queued', progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0, stage_updated_at DATETIME DEFAULT NULL, worker_heartbeat_at DATETIME DEFAULT NULL, expected_document_type VARCHAR(100) DEFAULT NULL, detected_document_type VARCHAR(100) DEFAULT NULL, confidence_score DECIMAL(5,2) DEFAULT NULL, extracted_fields LONGTEXT DEFAULT NULL, findings LONGTEXT DEFAULT NULL, model_service VARCHAR(100) DEFAULT NULL, model_version VARCHAR(100) DEFAULT NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, last_error_code VARCHAR(100) DEFAULT NULL, last_error_message TEXT DEFAULT NULL, processing_started_at DATETIME DEFAULT NULL, processed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_document_ai_reviews_document (request_document_id), KEY idx_document_ai_reviews_status (status), KEY idx_document_ai_reviews_stage (processing_stage), CONSTRAINT fk_document_ai_reviews_document FOREIGN KEY (request_document_id) REFERENCES aics_request_documents(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query("ALTER TABLE document_ai_reviews ADD COLUMN IF NOT EXISTS processing_stage VARCHAR(30) NOT NULL DEFAULT 'queued' AFTER status");
    $connection->query("ALTER TABLE document_ai_reviews ADD COLUMN IF NOT EXISTS progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER processing_stage");
    $connection->query("ALTER TABLE document_ai_reviews ADD COLUMN IF NOT EXISTS stage_updated_at DATETIME DEFAULT NULL AFTER progress_percent");
    $connection->query("ALTER TABLE document_ai_reviews ADD COLUMN IF NOT EXISTS worker_heartbeat_at DATETIME DEFAULT NULL AFTER stage_updated_at");
    $connection->query("CREATE INDEX IF NOT EXISTS idx_document_ai_reviews_stage ON document_ai_reviews (processing_stage)");
    $connection->query("UPDATE document_ai_reviews SET processing_stage = CASE WHEN status = 'failed' THEN 'failed' ELSE 'completed' END, progress_percent = 100, stage_updated_at = COALESCE(stage_updated_at, processed_at, updated_at) WHERE status <> 'processing' AND processing_stage = 'queued'");
    aics_ensure_request_ai_worker_lease_schema($connection);
    $connection->query("CREATE TABLE IF NOT EXISTS document_ai_review_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_review_id BIGINT UNSIGNED NOT NULL, request_document_id INT UNSIGNED NOT NULL, previous_status VARCHAR(30) NOT NULL, previous_expected_document_type VARCHAR(100) DEFAULT NULL, previous_detected_document_type VARCHAR(100) DEFAULT NULL, previous_confidence_score DECIMAL(5,2) DEFAULT NULL, previous_extracted_fields LONGTEXT DEFAULT NULL, previous_findings LONGTEXT DEFAULT NULL, previous_model_service VARCHAR(100) DEFAULT NULL, previous_model_version VARCHAR(100) DEFAULT NULL, previous_attempt_count INT UNSIGNED NOT NULL DEFAULT 0, previous_processing_started_at DATETIME DEFAULT NULL, previous_processed_at DATETIME DEFAULT NULL, previous_error_code VARCHAR(100) DEFAULT NULL, previous_error_message TEXT DEFAULT NULL, archive_reason VARCHAR(80) NOT NULL, archived_by_account_id INT UNSIGNED DEFAULT NULL, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_document_ai_review_history_review (ai_review_id), KEY idx_document_ai_review_history_document (request_document_id), KEY idx_document_ai_review_history_archived_at (archived_at), KEY idx_document_ai_review_history_staff (archived_by_account_id), CONSTRAINT fk_document_ai_review_history_review FOREIGN KEY (ai_review_id) REFERENCES document_ai_reviews(id) ON DELETE CASCADE, CONSTRAINT fk_document_ai_review_history_document FOREIGN KEY (request_document_id) REFERENCES aics_request_documents(id) ON DELETE CASCADE, CONSTRAINT fk_document_ai_review_history_staff FOREIGN KEY (archived_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query("CREATE TABLE IF NOT EXISTS document_ai_review_image_supplements (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_review_id BIGINT UNSIGNED NOT NULL, status VARCHAR(30) NOT NULL, tamper_detection LONGTEXT DEFAULT NULL, source VARCHAR(80) NOT NULL DEFAULT 'legacy_image_enrichment', error_code VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_document_ai_review_image_supplements_review (ai_review_id), KEY idx_document_ai_review_image_supplements_status (status), CONSTRAINT fk_document_ai_review_image_supplements_review FOREIGN KEY (ai_review_id) REFERENCES document_ai_reviews(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query("CREATE TABLE IF NOT EXISTS document_ai_review_feedback (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_review_id BIGINT UNSIGNED NOT NULL, reviewed_by_account_id INT UNSIGNED NOT NULL, ai_suggested_action VARCHAR(50) NOT NULL, staff_outcome VARCHAR(50) NOT NULL, agreement_state VARCHAR(30) NOT NULL, corrected_document_type VARCHAR(100) DEFAULT NULL, reason_code VARCHAR(80) NOT NULL, remarks VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_ai_feedback_review (ai_review_id), KEY idx_ai_feedback_staff (reviewed_by_account_id), KEY idx_ai_feedback_created (created_at), CONSTRAINT fk_ai_feedback_review FOREIGN KEY (ai_review_id) REFERENCES document_ai_reviews(id) ON DELETE CASCADE, CONSTRAINT fk_ai_feedback_staff FOREIGN KEY (reviewed_by_account_id) REFERENCES aics_accounts(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** @return array{ai_suggested_action:string,staff_outcome:string,agreement_state:string,corrected_document_type:?string,reason_code:string,remarks:string} */
function aics_normalize_ai_review_feedback(array $feedback): array
{
    $suggested = strtolower(trim((string) ($feedback['ai_suggested_action'] ?? 'staff_attention_needed')));
    $outcome = strtolower(trim((string) ($feedback['staff_outcome'] ?? 'accepted_for_manual_review')));
    $agreement = strtolower(trim((string) ($feedback['agreement_state'] ?? '')));
    $correctedType = aics_normalize_document_type((string) ($feedback['corrected_document_type'] ?? ''));
    $reason = strtoupper(trim((string) ($feedback['reason_code'] ?? '')));
    $remarks = trim((string) ($feedback['remarks'] ?? ''));

    if (!in_array($suggested, ['ready_for_staff_review', 'staff_attention_needed', 'reupload_recommended', 'verification_unavailable'], true)) {
        throw new InvalidArgumentException('Invalid AI suggestion.');
    }
    if (!in_array($outcome, ['document_accepted', 'accepted_for_manual_review', 'reupload_requested', 'not_applicable'], true)) {
        throw new InvalidArgumentException('Invalid staff outcome.');
    }
    if (!in_array($agreement, ['agreed', 'overridden', 'not_applicable'], true)) {
        throw new InvalidArgumentException('Select whether you agree with the AI suggestion.');
    }
    if ($agreement === 'overridden' && $reason === '') {
        throw new InvalidArgumentException('A reason is required when overriding the AI suggestion.');
    }
    if ($reason === '') $reason = 'STAFF_CONFIRMED';
    if (preg_match('/^[A-Z0-9_]{3,64}$/', $reason) !== 1) {
        throw new InvalidArgumentException('Invalid feedback reason.');
    }
    $allowedTypes = aics_ai_supported_document_types();
    if ($correctedType !== '' && !in_array($correctedType, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid corrected document type.');
    }
    return [
        'ai_suggested_action' => $suggested,
        'staff_outcome' => $outcome,
        'agreement_state' => $agreement,
        'corrected_document_type' => $correctedType !== '' ? $correctedType : null,
        'reason_code' => $reason,
        'remarks' => function_exists('mb_substr') ? mb_substr($remarks, 0, 500) : substr($remarks, 0, 500),
    ];
}

/** @return array<int,string> */
function aics_ai_supported_document_types(): array
{
    return ['valid_id', 'med_cert', 'billing_statement', 'death_cert', 'funeral_contract', 'report_police_med', 'school_assessment', 'registration_form', 'mdrrmo_cert', 'eclip_cert', 'crisis_situation_cert', 'damaged_house_photo'];
}

function aics_record_ai_review_feedback(mysqli $connection, int $reviewId, int $staffId, array $feedback): int
{
    if ($reviewId <= 0 || $staffId <= 0) throw new InvalidArgumentException('Invalid feedback target.');
    $values = aics_normalize_ai_review_feedback($feedback);
    $connection->begin_transaction();
    try {
        $target = $connection->prepare('SELECT ai.id FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id INNER JOIN aics_accounts a ON a.id = ? WHERE ai.id = ? FOR UPDATE');
        if (!$target) throw new RuntimeException('Unable to verify feedback target.');
        $target->bind_param('ii', $staffId, $reviewId);
        $target->execute();
        $exists = (bool) $target->get_result()?->fetch_assoc();
        $target->close();
        if (!$exists) throw new InvalidArgumentException('AI review feedback target is unavailable.');
        $stmt = $connection->prepare('INSERT INTO document_ai_review_feedback (ai_review_id, reviewed_by_account_id, ai_suggested_action, staff_outcome, agreement_state, corrected_document_type, reason_code, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Unable to save AI review feedback.');
        $stmt->bind_param('iissssss', $reviewId, $staffId, $values['ai_suggested_action'], $values['staff_outcome'], $values['agreement_state'], $values['corrected_document_type'], $values['reason_code'], $values['remarks']);
        $stmt->execute();
        $id = (int) $connection->insert_id;
        $stmt->close();
        $connection->commit();
        return $id;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/**
 * Return a privacy-safe request summary for server rendering and live polling.
 *
 * @return array{request_id:int,total:int,queued:int,active:int,terminal:int,attention:int,failed:int,progress_percent:int,state:string,label:string,documents:array<int,array<string,mixed>>,updated_at:?string}
 */
function aics_ai_request_progress(mysqli $connection, int $requestId): array
{
    $summary = [
        'request_id' => $requestId,
        'total' => 0,
        'queued' => 0,
        'active' => 0,
        'terminal' => 0,
        'attention' => 0,
        'failed' => 0,
        'progress_percent' => 0,
        'state' => 'empty',
        'label' => 'No verifiable files',
        'documents' => [],
        'updated_at' => null,
    ];
    if ($requestId <= 0) return $summary;

    $stmt = $connection->prepare(
        'SELECT d.id AS document_id, d.document_type, ai.id AS review_id, ai.status,
                ai.processing_stage, ai.progress_percent, ai.attempt_count,
                ai.stage_updated_at, ai.processed_at, ai.updated_at
         FROM aics_request_documents d
         LEFT JOIN document_ai_reviews ai ON ai.request_document_id = d.id
         WHERE d.request_id = ?
         ORDER BY d.id'
    );
    if (!$stmt) throw new RuntimeException('Unable to load AI verification progress.');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $terminalStatuses = ['completed', 'likely_valid', 'needs_staff_review', 'needs_reupload', 'flagged_inconsistency', 'failed'];
    $attentionStatuses = ['needs_staff_review', 'needs_reupload', 'flagged_inconsistency'];
    $reuploadCount = 0;

    foreach ($rows as $row) {
        $documentType = aics_normalize_document_type((string) ($row['document_type'] ?? ''));
        if (!aics_document_type_is_verifiable($documentType)) continue;

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $status = $status !== '' ? $status : 'processing';
        $stage = strtolower(trim((string) ($row['processing_stage'] ?? 'queued')));
        if ($status !== 'processing') $stage = $status === 'failed' ? 'failed' : 'completed';
        if (!in_array($stage, aics_ai_processing_stages(), true)) $stage = $status === 'processing' ? 'queued' : 'completed';
        $isTerminal = in_array($status, $terminalStatuses, true);
        $isQueued = !$isTerminal && $stage === 'queued';
        $isActive = !$isTerminal && !$isQueued;
        $updatedAt = trim((string) ($row['stage_updated_at'] ?? ''));
        if ($updatedAt === '') $updatedAt = trim((string) ($row['processed_at'] ?? ''));
        if ($updatedAt === '') $updatedAt = trim((string) ($row['updated_at'] ?? ''));

        $summary['total']++;
        if ($isQueued) $summary['queued']++;
        if ($isActive) $summary['active']++;
        if ($isTerminal) $summary['terminal']++;
        if (in_array($status, $attentionStatuses, true)) $summary['attention']++;
        if ($status === 'needs_reupload') $reuploadCount++;
        if ($status === 'failed') $summary['failed']++;
        if ($updatedAt !== '' && ($summary['updated_at'] === null || $updatedAt > $summary['updated_at'])) {
            $summary['updated_at'] = $updatedAt;
        }

        $summary['documents'][] = [
            'document_id' => (int) ($row['document_id'] ?? 0),
            'review_id' => (int) ($row['review_id'] ?? 0),
            'document_type' => $documentType,
            'status' => $status,
            'stage' => $stage,
            'progress_percent' => $isTerminal ? 100 : max(0, min(99, (int) ($row['progress_percent'] ?? aics_ai_stage_progress($stage)))),
            'is_terminal' => $isTerminal,
            'can_retry' => $status === 'failed',
        ];
    }

    if ($summary['total'] > 0) {
        $summary['progress_percent'] = (int) round(($summary['terminal'] / $summary['total']) * 100);
    }
    if ($summary['queued'] > 0 || $summary['active'] > 0) {
        $summary['state'] = 'scanning';
        $summary['label'] = 'Scanning ' . $summary['terminal'] . ' of ' . $summary['total'];
    } elseif ($summary['failed'] > 0) {
        $summary['state'] = 'failed';
        $summary['label'] = 'Scan unavailable';
    } elseif ($reuploadCount > 0) {
        $summary['state'] = 'reupload';
        $summary['label'] = 'Re-upload needed';
    } elseif ($summary['attention'] > 0) {
        $summary['state'] = 'attention';
        $summary['label'] = $summary['attention'] . ' need review';
    } elseif ($summary['total'] > 0) {
        $summary['state'] = 'ready';
        $summary['label'] = 'AI results ready';
    }

    return $summary;
}

/** Create the small request-worker ownership table for existing deployments. */
function aics_ensure_request_ai_worker_lease_schema(mysqli $connection): void
{
    // A detached request worker needs a durable ownership record. This avoids
    // duplicate workers when staff open the same request repeatedly while its
    // queued rows still have attempt_count=0.
    $connection->query("CREATE TABLE IF NOT EXISTS document_ai_request_worker_leases (request_id INT UNSIGNED NOT NULL, lease_token CHAR(32) NOT NULL, acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (request_id), KEY idx_document_ai_request_worker_leases_acquired (acquired_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function aics_create_ai_review(mysqli $connection, int $requestDocumentId, string $storedDocumentType): bool
{
    if ($requestDocumentId <= 0) return false;
    $status = 'processing'; $expected = aics_normalize_document_type($storedDocumentType);
    if (!aics_document_type_is_verifiable($expected)) return false;
    $stmt = $connection->prepare('INSERT IGNORE INTO document_ai_reviews (request_document_id, status, expected_document_type) VALUES (?, ?, ?)');
    if (!$stmt) throw new RuntimeException('Unable to queue document verification.');
    $stmt->bind_param('iss', $requestDocumentId, $status, $expected); $stmt->execute();
    $inserted = $stmt->affected_rows === 1;
    $stmt->close();
    return $inserted;
}

/**
 * Persist missing AI review rows without acquiring a worker lease or starting
 * Python. Applicant-facing requests use this after commit so their response
 * time depends only on small database operations.
 */
function aics_queue_ai_reviews_for_request(mysqli $connection, int $requestId): int
{
    if ($requestId <= 0) return 0;
    $stmt = $connection->prepare('SELECT id, document_type FROM aics_request_documents WHERE request_id = ? ORDER BY id');
    if (!$stmt) throw new RuntimeException('Unable to read documents for verification queueing.');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $queued = 0;
    foreach ($documents as $document) {
        if (aics_create_ai_review($connection, (int) ($document['id'] ?? 0), (string) ($document['document_type'] ?? ''))) {
            $queued++;
        }
    }
    return $queued;
}

/**
 * Queue post-commit work without allowing an AI outage to turn a successfully
 * saved applicant submission into an error response.
 *
 * @return array{queued:int,failed:int}
 */
function aics_queue_ai_reviews_after_commit(mysqli $connection, int $requestId): array
{
    try {
        return ['queued' => aics_queue_ai_reviews_for_request($connection, $requestId), 'failed' => 0];
    } catch (Throwable $exception) {
        error_log('AICS post-upload AI queue failed: category=' . $exception::class);
        return ['queued' => 0, 'failed' => 1];
    }
}

/**
 * Dispatch one detached, sequential request worker after the upload
 * transaction commits. The processor cannot see rows until that commit.
 * Keeping this dispatch here prevents upload routes from defining a second
 * queue, and keeps the applicant response independent of OCR/ADCD duration.
 *
 * @return array{dispatched:int,failed:int}
 */
function aics_dispatch_unclaimed_ai_reviews_for_request(mysqli $connection, int $requestId): array
{
    if ($requestId <= 0) return ['dispatched' => 0, 'failed' => 0];
    $queued = aics_ensure_current_ai_reviews_for_request($connection, $requestId);
    if (!(bool) $queued['worker_needed']) return ['dispatched' => 0, 'failed' => 0];

    $leaseToken = (string) ($queued['worker_lease_token'] ?? '');
    if ($leaseToken !== '' && aics_launch_request_ai_processor($requestId, $leaseToken)) {
        return ['dispatched' => 1, 'failed' => 0];
    }

    // A failed detached launch must not strand a lease or make a successfully
    // saved application look unsaved. Preserve the established unavailable /
    // Retry path for only this request's unclaimed rows.
    $failed = 0;
    foreach ((array) ($queued['queued'] ?? []) as $job) {
        $reviewId = (int) ($job['review_id'] ?? 0);
        if ($reviewId <= 0) continue;
        aics_mark_ai_review_failed(
            $connection,
            $reviewId,
            'AI_PROCESSOR_LAUNCH_FAILED',
            'Local verification could not be started. PSWDO staff can review this document or reprocess it after the local service is restored.'
        );
        $failed++;
    }
    $lease = $connection->prepare('DELETE FROM document_ai_request_worker_leases WHERE request_id = ? AND lease_token = ?');
    if ($lease) {
        $lease->bind_param('is', $requestId, $leaseToken);
        $lease->execute();
        $lease->close();
    }
    return ['dispatched' => 0, 'failed' => $failed];
}

/**
 * Start verification only after the caller commits its upload transaction.
 * Dispatch failures are persisted where possible and must never turn a saved
 * applicant upload into an application error response.
 *
 * @return array{dispatched:int,failed:int}
 */
function aics_dispatch_ai_reviews_after_commit(mysqli $connection, int $requestId): array
{
    try {
        return aics_dispatch_unclaimed_ai_reviews_for_request($connection, $requestId);
    } catch (Throwable $exception) {
        error_log('AICS post-upload AI dispatch failed: category=' . $exception::class);
        return ['dispatched' => 0, 'failed' => 0];
    }
}

/** @return array<string,mixed>|null */
function aics_get_latest_ai_review(mysqli $connection, int $requestDocumentId): ?array
{
    $stmt = $connection->prepare('SELECT * FROM document_ai_reviews WHERE request_document_id = ? LIMIT 1'); if (!$stmt) return null;
    $stmt->bind_param('i', $requestDocumentId); $stmt->execute(); $row = $stmt->get_result()?->fetch_assoc(); $stmt->close(); return is_array($row) ? $row : null;
}

function aics_update_ai_review_status(mysqli $connection, int $reviewId, string $status, ?string $findings = null): void
{
    if (!in_array($status, aics_ai_review_statuses(), true)) throw new InvalidArgumentException('Unsupported AI review status.');
    $stmt = $connection->prepare("UPDATE document_ai_reviews SET status = ?, findings = ?, processed_at = CASE WHEN ? = 'processing' THEN NULL ELSE CURRENT_TIMESTAMP END WHERE id = ?");
    if (!$stmt) throw new RuntimeException('Unable to update document verification.'); $stmt->bind_param('sssi', $status, $findings, $status, $reviewId); $stmt->execute(); $stmt->close();
}

function aics_mark_ai_review_failed(mysqli $connection, int $reviewId, string $errorCode, string $errorMessage): void
{
    $status = 'failed'; $stmt = $connection->prepare('UPDATE document_ai_reviews SET status = ?, last_error_code = ?, last_error_message = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?');
    if (!$stmt) throw new RuntimeException('Unable to record AI processing failure.'); $stmt->bind_param('sssi', $status, $errorCode, $errorMessage, $reviewId); $stmt->execute(); $stmt->close();
}

function aics_retry_ai_review(mysqli $connection, int $reviewId): bool
{
    $status = 'processing'; $failed = 'failed'; $max = AICS_AI_REVIEW_MAX_ATTEMPTS;
    $stmt = $connection->prepare('UPDATE document_ai_reviews SET status = ?, attempt_count = attempt_count + 1, last_error_code = NULL, last_error_message = NULL, processing_started_at = CURRENT_TIMESTAMP, processed_at = NULL WHERE id = ? AND status = ? AND attempt_count < ?');
    if (!$stmt) throw new RuntimeException('Unable to retry document verification.'); $stmt->bind_param('sisi', $status, $reviewId, $failed, $max); $stmt->execute(); $updated = $stmt->affected_rows === 1; $stmt->close(); return $updated;
}

/**
 * Atomically preserve a current result, or queue exactly one new/recoverable
 * review for a document.  Unlike manual reprocessing, this never archives or
 * resets a completed finding.
 *
 * @return array{review_id:int,request_document_id:int,status:string,should_launch:bool,reason:string}
 */
function aics_ensure_current_ai_review(mysqli $connection, int $requestDocumentId, string $storedDocumentType): array
{
    $empty = ['review_id' => 0, 'request_document_id' => $requestDocumentId, 'status' => '', 'should_launch' => false, 'reason' => 'unsupported_document'];
    if ($requestDocumentId <= 0) return $empty;
    $expected = aics_normalize_document_type($storedDocumentType);
    if (!aics_document_type_is_verifiable($expected)) return $empty;

    $status = 'processing';
    $insert = $connection->prepare('INSERT IGNORE INTO document_ai_reviews (request_document_id, status, expected_document_type, processing_started_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
    if (!$insert) throw new RuntimeException('Unable to ensure document verification.');
    $insert->bind_param('iss', $requestDocumentId, $status, $expected);
    $insert->execute();
    $created = $insert->affected_rows === 1;
    $insert->close();

    $review = aics_get_latest_ai_review($connection, $requestDocumentId);
    if (!is_array($review)) throw new RuntimeException('Unable to load ensured document verification.');
    $reviewId = (int) ($review['id'] ?? 0);
    $currentStatus = strtolower(trim((string) ($review['status'] ?? 'failed')));
    if ($created) return ['review_id' => $reviewId, 'request_document_id' => $requestDocumentId, 'status' => 'processing', 'should_launch' => true, 'reason' => 'created'];
    if ($currentStatus === 'processing') return ['review_id' => $reviewId, 'request_document_id' => $requestDocumentId, 'status' => 'processing', 'should_launch' => false, 'reason' => 'already_processing'];
    if ($currentStatus === 'failed' && aics_retry_ai_review($connection, $reviewId)) {
        return ['review_id' => $reviewId, 'request_document_id' => $requestDocumentId, 'status' => 'processing', 'should_launch' => true, 'reason' => 'retried'];
    }
    return ['review_id' => $reviewId, 'request_document_id' => $requestDocumentId, 'status' => $currentStatus, 'should_launch' => false, 'reason' => $currentStatus === 'failed' ? 'retry_exhausted' : 'current_result'];
}

/**
 * Queue every eligible document for one request in a single transaction.
 * Existing terminal results are immutable during ordinary viewing.  A legacy
 * result may not have the newer alteration branch, but it is still a real
 * completed review and must never be silently re-run just because View was
 * opened.
 *
 * @return array{newly_created:array<int,array{document_id:int,review_id:int,status:string}>,already_queued:array<int,array{document_id:int,review_id:int,status:string}>,actively_processing:array<int,array{document_id:int,review_id:int,status:string}>,terminal:array<int,array{document_id:int,review_id:int,status:string}>,unavailable:array<int,array{document_id:int,review_id:int,status:string}>,queued:array<int,array{document_id:int,review_id:int,status:string}>,worker_needed:bool,worker_lease_token:?string}
 */
function aics_ensure_current_ai_reviews_for_request(mysqli $connection, int $requestId): array
{
    $result = ['newly_created' => [], 'already_queued' => [], 'actively_processing' => [], 'terminal' => [], 'unavailable' => [], 'queued' => [], 'worker_needed' => false, 'worker_lease_token' => null];
    if ($requestId <= 0) return $result;
    aics_ensure_request_ai_worker_lease_schema($connection);
    $connection->begin_transaction();
    try {
        // Queued rows have attempt_count=0 and must wait for the sequential
        // batch.  Only a row actually claimed by a worker can become stale.
        $staleMinutes = max(1, (int) (getenv('AICS_AI_STALE_MINUTES') ?: 30));
        $recover = $connection->prepare("UPDATE document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id SET ai.status = 'failed', ai.processing_stage = 'failed', ai.progress_percent = 100, ai.stage_updated_at = CURRENT_TIMESTAMP, ai.worker_heartbeat_at = NULL, ai.last_error_code = 'AI_PROCESSING_TIMEOUT', ai.last_error_message = 'Local verification did not complete within the configured retry window.', ai.processed_at = CURRENT_TIMESTAMP WHERE d.request_id = ? AND ai.status = 'processing' AND ai.attempt_count > 0 AND ai.processing_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)");
        if (!$recover) throw new RuntimeException('Unable to recover stale verification work.');
        $recover->bind_param('ii', $requestId, $staleMinutes); $recover->execute(); $recover->close();
        $stmt = $connection->prepare('SELECT d.id AS document_id, d.document_type, ai.id AS review_id, ai.status, ai.attempt_count, ai.findings FROM aics_request_documents d LEFT JOIN document_ai_reviews ai ON ai.request_document_id = d.id WHERE d.request_id = ? ORDER BY d.id FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Unable to load request documents.');
        $stmt->bind_param('i', $requestId); $stmt->execute();
        $documents = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close();
        foreach ($documents as $document) {
            $documentId = (int) ($document['document_id'] ?? 0);
            $expected = aics_normalize_document_type((string) ($document['document_type'] ?? ''));
            if ($documentId <= 0 || !aics_document_type_is_verifiable($expected)) continue;
            $reviewId = (int) ($document['review_id'] ?? 0);
            $status = strtolower(trim((string) ($document['status'] ?? '')));
            $findings = json_decode((string) ($document['findings'] ?? ''), true);
            $canonical = is_array($findings) && is_array($findings['tamper_detection'] ?? null)
                ? strtolower(trim((string) ($findings['tamper_detection']['status'] ?? ''))) : '';
            $item = ['document_id' => $documentId, 'review_id' => $reviewId, 'status' => $status];
            if ($reviewId > 0 && $status === 'processing') {
                if ((int) ($document['attempt_count'] ?? 0) > 0) $result['actively_processing'][] = $item;
                else $result['already_queued'][] = $item;
                continue;
            }
            if ($reviewId > 0 && $status === 'failed') { $result['terminal'][] = $item; continue; }
            if ($reviewId > 0 && in_array($canonical, ['completed', 'unavailable'], true)) {
                $result[$canonical === 'unavailable' ? 'unavailable' : 'terminal'][] = $item; continue;
            }
            if ($reviewId > 0 && in_array($status, ['completed', 'likely_valid', 'needs_staff_review', 'needs_reupload', 'flagged_inconsistency'], true)) { $result['terminal'][] = $item; continue; }
            if ($reviewId <= 0) {
                $insert = $connection->prepare("INSERT INTO document_ai_reviews (request_document_id, status, expected_document_type, processing_started_at) VALUES (?, 'processing', ?, CURRENT_TIMESTAMP)");
                if (!$insert) throw new RuntimeException('Unable to queue document verification.');
                $insert->bind_param('is', $documentId, $expected); $insert->execute(); $reviewId = (int) $connection->insert_id; $insert->close();
                $result['newly_created'][] = ['document_id' => $documentId, 'review_id' => $reviewId, 'status' => 'processing'];
            }
        }
        $result['queued'] = array_merge($result['newly_created'], $result['already_queued']);
        if ($result['queued'] !== [] && $result['actively_processing'] === []) {
            $lease = $connection->prepare('SELECT lease_token, acquired_at FROM document_ai_request_worker_leases WHERE request_id = ? FOR UPDATE');
            if (!$lease) throw new RuntimeException('Unable to lock request AI worker lease.');
            $lease->bind_param('i', $requestId); $lease->execute(); $existingLease = $lease->get_result()?->fetch_assoc(); $lease->close();
            $leaseExpired = is_array($existingLease) && strtotime((string) ($existingLease['acquired_at'] ?? '')) < (time() - ($staleMinutes * 60));
            if (!is_array($existingLease) || $leaseExpired) {
                $token = bin2hex(random_bytes(16));
                $upsert = $connection->prepare('INSERT INTO document_ai_request_worker_leases (request_id, lease_token, acquired_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE lease_token = VALUES(lease_token), acquired_at = CURRENT_TIMESTAMP');
                if (!$upsert) throw new RuntimeException('Unable to acquire request AI worker lease.');
                $upsert->bind_param('is', $requestId, $token); $upsert->execute(); $upsert->close();
                $result['worker_needed'] = true;
                $result['worker_lease_token'] = $token;
            }
        }
        $connection->commit();
        return $result;
    } catch (Throwable $exception) {
        $connection->rollback(); throw $exception;
    }
}

/**
 * Perform the one permitted infrastructure recovery for a historical worker
 * launch failure.  The unique current-review row is retained by design, so
 * the old failure is archived before that row is reset for a new attempt.
 * This is intentionally narrower than normal staff reprocessing.
 */
function aics_recover_launcher_failed_ai_review(mysqli $connection, int $reviewId, string $failedBefore): ?int
{
    if ($reviewId <= 0 || strtotime($failedBefore) === false) return null;
    $connection->begin_transaction();
    try {
        $review = $connection->prepare("SELECT ai.id, ai.request_document_id, ai.status, ai.last_error_code, ai.processed_at FROM document_ai_reviews ai WHERE ai.id = ? FOR UPDATE");
        if (!$review) throw new RuntimeException('Unable to lock failed AI verification.');
        $review->bind_param('i', $reviewId); $review->execute();
        $row = $review->get_result()?->fetch_assoc(); $review->close();
        if (!is_array($row) || (string) $row['status'] !== 'failed' || (string) $row['last_error_code'] !== 'AI_PROCESSOR_LAUNCH_FAILED' || (string) ($row['processed_at'] ?? '') >= $failedBefore) {
            $connection->rollback(); return null;
        }
        $archive = $connection->prepare('INSERT INTO document_ai_review_history (ai_review_id, request_document_id, previous_status, previous_expected_document_type, previous_detected_document_type, previous_confidence_score, previous_extracted_fields, previous_findings, previous_model_service, previous_model_version, previous_attempt_count, previous_processing_started_at, previous_processed_at, previous_error_code, previous_error_message, archive_reason) SELECT id, request_document_id, status, expected_document_type, detected_document_type, confidence_score, extracted_fields, findings, model_service, model_version, attempt_count, processing_started_at, processed_at, last_error_code, last_error_message, ? FROM document_ai_reviews WHERE id = ?');
        if (!$archive) throw new RuntimeException('Unable to archive launcher failure.');
        $reason = 'launcher_failure_recovery'; $archive->bind_param('si', $reason, $reviewId); $archive->execute();
        if ($archive->affected_rows !== 1) throw new RuntimeException('Unable to archive launcher failure.');
        $archive->close();
        $reset = $connection->prepare("UPDATE document_ai_reviews SET status = 'processing', detected_document_type = NULL, confidence_score = NULL, extracted_fields = NULL, findings = NULL, model_service = NULL, model_version = NULL, attempt_count = 0, last_error_code = NULL, last_error_message = NULL, processing_started_at = NULL, processed_at = NULL WHERE id = ? AND status = 'failed' AND last_error_code = 'AI_PROCESSOR_LAUNCH_FAILED'");
        if (!$reset) throw new RuntimeException('Unable to queue launcher recovery.');
        $reset->bind_param('i', $reviewId); $reset->execute();
        if ($reset->affected_rows !== 1) throw new RuntimeException('Unable to queue launcher recovery.');
        $reset->close();
        $connection->commit();
        return (int) $row['request_document_id'];
    } catch (Throwable $exception) {
        $connection->rollback(); throw $exception;
    }
}

/** @param array<string,string> $components */
function aics_ai_health_status_from_components(array $components): string
{
    foreach (['database', 'python', 'worker', 'tesseract', 'poppler'] as $component) {
        if (($components[$component] ?? 'unavailable') !== 'available') return 'unavailable';
    }
    if (($components['adcd'] ?? 'unavailable') === 'unavailable') return 'degraded';
    if (($components['external_ai'] ?? 'disabled') === 'unavailable') return 'degraded';
    return in_array('warning', $components, true) ? 'degraded' : 'operational';
}

/** @param array<string,mixed>|null $healthPayload */
function aics_ai_health_allows_processing(?array $healthPayload): bool
{
    return !is_array($healthPayload) || strtolower(trim((string) ($healthPayload['status'] ?? ''))) !== 'unavailable';
}

/** @return array{project_root:string,python:string,poppler:string,tesseract:string,adcd_enabled:string,adcd_service_url:string,adcd_connect_timeout:string,adcd_read_timeout:string,adcd_output_root:string} */
function aics_ai_runtime_configuration(): array
{
    $adcdEnabled = trim((string) getenv('AICS_ADCD_ENABLED'));
    $adcdServiceUrl = trim((string) (getenv('AICS_ADCD_SERVICE_URL') ?: getenv('ADCD_SERVICE_URL')));
    $adcdConnectTimeout = trim((string) getenv('ADCD_CONNECT_TIMEOUT'));
    $adcdReadTimeout = trim((string) getenv('ADCD_READ_TIMEOUT'));
    $adcdOutputRoot = trim((string) getenv('AICS_ADCD_OUTPUT_ROOT'));
    return [
        'project_root' => trim((string) getenv('AICS_PROJECT_ROOT')) ?: dirname(__DIR__),
        'python' => trim((string) getenv('AICS_PYTHON_EXECUTABLE')),
        'poppler' => trim((string) getenv('AICS_POPPLER_PATH')),
        'tesseract' => trim((string) getenv('AICS_TESSERACT_PATH')),
        // ADCD-Net is part of the integrated local verification workflow.
        // A host can explicitly opt out with AICS_ADCD_ENABLED=0.
        'adcd_enabled' => $adcdEnabled === '1' ? '1' : '0',
        'adcd_service_url' => $adcdServiceUrl !== '' ? $adcdServiceUrl : 'http://127.0.0.1:5001',
        'adcd_connect_timeout' => $adcdConnectTimeout !== '' ? $adcdConnectTimeout : '3',
        'adcd_read_timeout' => $adcdReadTimeout !== '' ? $adcdReadTimeout : '90',
        'adcd_output_root' => $adcdOutputRoot,
    ];
}

/** @return array{status:string,components:array<string,string>} */
function aics_ai_environment_health(bool $databaseAvailable = true): array
{
    $runtime = aics_ai_runtime_configuration();
    $python = $runtime['python'];
    $poppler = $runtime['poppler'];
    $tesseract = $runtime['tesseract'];
    $projectRoot = $runtime['project_root'];
    $pythonAvailable = $python !== '' && is_file($python) && is_readable($python);
    $workerAvailable = is_file($projectRoot . DIRECTORY_SEPARATOR . 'ai_processor' . DIRECTORY_SEPARATOR . 'processor.py') && function_exists('exec');
    $externalAiState = 'unavailable';
    // This is deliberately import-only: it confirms the configured Python
    // environment can load the processor's OCR/PDF dependencies without OCR,
    // rendering, or launching a document worker. The endpoint caches it.
    if ($pythonAvailable && $workerAvailable) {
        $output = [];
        $exitCode = -1;
        $pythonRoot = str_replace('\\', '/', $projectRoot);
        $probe = 'import json,sys; sys.path.insert(0, ' . var_export($pythonRoot, true) . '); import ai_processor.processor; from ai_processor.external_ai.probe import configuration_payload; print(json.dumps(configuration_payload()))';
        $command = escapeshellarg($python) . ' -c ' . escapeshellarg($probe);
        @exec($command, $output, $exitCode);
        $workerAvailable = $exitCode === 0;
        if ($workerAvailable) {
            foreach ($output as $line) {
                $externalPayload = json_decode((string) $line, true);
                if (!is_array($externalPayload)) continue;
                $configurationStatus = is_array($externalPayload) ? strtolower(trim((string) ($externalPayload['status'] ?? ''))) : '';
                $externalAiState = $configurationStatus === 'disabled' ? 'disabled' : ($configurationStatus === 'configured' ? 'configured' : 'unavailable');
                break;
            }
        }
    }
    $adcdAvailable = false;
    if ($runtime['adcd_enabled'] === '1') {
        $healthUrl = rtrim($runtime['adcd_service_url'], '/') . '/health';
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $rawHealth = @file_get_contents($healthUrl, false, $context);
        $adcdHealth = is_string($rawHealth) ? json_decode($rawHealth, true) : null;
        $adcdAvailable = is_array($adcdHealth)
            && strtolower(trim((string) ($adcdHealth['status'] ?? ''))) === 'ready'
            && ($adcdHealth['model_loaded'] ?? false) === true;
    }
    $components = [
        'database' => $databaseAvailable ? 'available' : 'unavailable',
        'python' => $pythonAvailable ? 'available' : 'unavailable',
        'worker' => $workerAvailable ? 'available' : 'unavailable',
        'tesseract' => $tesseract !== '' && is_file($tesseract) ? 'available' : 'unavailable',
        'poppler' => $poppler !== '' && is_file(rtrim($poppler, '\\/') . DIRECTORY_SEPARATOR . 'pdfinfo.exe') && is_file(rtrim($poppler, '\\/') . DIRECTORY_SEPARATOR . 'pdftoppm.exe') ? 'available' : 'unavailable',
        'adcd' => $runtime['adcd_enabled'] === '0' ? 'disabled' : ($adcdAvailable ? 'available' : 'unavailable'),
        'external_ai' => $externalAiState,
    ];
    return ['status' => aics_ai_health_status_from_components($components), 'components' => $components];
}

/**
 * Archive a completed/failed AI result and queue the same review for a new
 * manual processing cycle. This deliberately does not touch PSWDO document
 * review rows or any request workflow state.
 *
 * @return array{review_id:int,request_document_id:int}
 */
function aics_requeue_archived_ai_review(mysqli $connection, int $reviewId, int $staffAccountId, string $archiveReason, int $requestId = 0): array
{
    if ($reviewId <= 0 || $staffAccountId <= 0) {
        throw new InvalidArgumentException('Invalid AI reprocess request.');
    }
    if ($archiveReason === '' || strlen($archiveReason) > 80 || preg_match('/^[a-z0-9_]+$/', $archiveReason) !== 1) {
        throw new InvalidArgumentException('Invalid AI reprocess archive reason.');
    }

    $connection->begin_transaction();
    try {
        $staff = $connection->prepare('SELECT a.id FROM aics_accounts a INNER JOIN aics_roles r ON r.id = a.role_id WHERE a.id = ? AND r.role_code = ? LIMIT 1');
        if (!$staff) throw new RuntimeException('Unable to authorize AI reprocess request.');
        $roleCode = 'pswdo';
        $staff->bind_param('is', $staffAccountId, $roleCode);
        $staff->execute();
        $authorized = $staff->get_result()?->fetch_assoc();
        $staff->close();
        if (!is_array($authorized)) throw new RuntimeException('Unauthorized AI reprocess request.');

        $reviewSql = 'SELECT ai.* FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id WHERE ai.id = ?';
        if ($requestId > 0) $reviewSql .= ' AND d.request_id = ?';
        $reviewSql .= ' FOR UPDATE';
        $reviewStmt = $connection->prepare($reviewSql);
        if (!$reviewStmt) throw new RuntimeException('Unable to lock AI verification.');
        if ($requestId > 0) $reviewStmt->bind_param('ii', $reviewId, $requestId); else $reviewStmt->bind_param('i', $reviewId);
        $reviewStmt->execute();
        $review = $reviewStmt->get_result()?->fetch_assoc();
        $reviewStmt->close();
        if (!is_array($review)) throw new RuntimeException('AI verification record was not found.');
        if ((string) ($review['status'] ?? '') === 'processing') throw new RuntimeException('AI verification is already processing.');

        $archive = $connection->prepare(
            'INSERT INTO document_ai_review_history (ai_review_id, request_document_id, previous_status, previous_expected_document_type, previous_detected_document_type, previous_confidence_score, previous_extracted_fields, previous_findings, previous_model_service, previous_model_version, previous_attempt_count, previous_processing_started_at, previous_processed_at, previous_error_code, previous_error_message, archive_reason, archived_by_account_id) SELECT id, request_document_id, status, expected_document_type, detected_document_type, confidence_score, extracted_fields, findings, model_service, model_version, attempt_count, processing_started_at, processed_at, last_error_code, last_error_message, ?, ? FROM document_ai_reviews WHERE id = ?'
        );
        if (!$archive) throw new RuntimeException('Unable to archive AI verification result.');
        $archive->bind_param('sii', $archiveReason, $staffAccountId, $reviewId);
        $archive->execute();
        $archived = $archive->affected_rows === 1;
        $archive->close();
        if (!$archived) throw new RuntimeException('Unable to archive AI verification result.');

        $reset = $connection->prepare("UPDATE document_ai_reviews SET status = 'processing', detected_document_type = NULL, confidence_score = NULL, extracted_fields = NULL, findings = NULL, model_service = NULL, model_version = NULL, attempt_count = 0, last_error_code = NULL, last_error_message = NULL, processing_started_at = NULL, processed_at = NULL WHERE id = ? AND status <> 'processing'");
        if (!$reset) throw new RuntimeException('Unable to queue AI verification reprocess.');
        $reset->bind_param('i', $reviewId);
        $reset->execute();
        $resetDone = $reset->affected_rows === 1;
        $reset->close();
        if (!$resetDone) throw new RuntimeException('AI verification could not be queued for reprocess.');

        $connection->commit();
        return ['review_id' => $reviewId, 'request_document_id' => (int) $review['request_document_id']];
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/**
 * Normal staff Retry wrapper.  HTTP authorization remains outside this
 * operation; the low-level operation independently verifies the actor role
 * so a CLI maintenance caller must provide a real PSWDO actor as well.
 *
 * @return array{review_id:int,request_document_id:int}
 */
function aics_reprocess_ai_review(mysqli $connection, int $reviewId, int $staffAccountId, int $requestId = 0): array
{
    return aics_requeue_archived_ai_review($connection, $reviewId, $staffAccountId, 'manual_reprocess', $requestId);
}

/**
 * Starts one document-scoped Python worker without holding the PHP response.
 * AICS_PYTHON_EXECUTABLE should be the direct python.exe path on XAMPP.
 * Values are server-only, never sent to UI.
 */
function aics_launch_document_ai_processor(int $documentId, string $workerArgument = '--document-id', string $workerLeaseToken = ''): bool
{
    if ($documentId <= 0 || !in_array($workerArgument, ['--document-id', '--request-id'], true) || ($workerLeaseToken !== '' && preg_match('/^[a-f0-9]{32}$/', $workerLeaseToken) !== 1)) return false;
    $runtime = aics_ai_runtime_configuration();
    $projectRoot = $runtime['project_root'];
    $pythonExecutable = $runtime['python'];
    $pythonExists = $pythonExecutable !== '' && file_exists($pythonExecutable);
    $pythonIsFile = $pythonExecutable !== '' && is_file($pythonExecutable);
    $pythonReadable = $pythonExecutable !== '' && is_readable($pythonExecutable);
    $projectExists = is_dir($projectRoot);
    $popplerPath = $runtime['poppler'];
    $popplerDirectoryValid = $popplerPath !== '' && is_dir($popplerPath);
    $pdfinfoExists = $popplerDirectoryValid && is_file(rtrim($popplerPath, '\\/') . DIRECTORY_SEPARATOR . 'pdfinfo.exe');
    $pdftoppmExists = $popplerDirectoryValid && is_file(rtrim($popplerPath, '\\/') . DIRECTORY_SEPARATOR . 'pdftoppm.exe');
    $tesseractPath = $runtime['tesseract'];
    $tesseractExists = $tesseractPath !== '' && is_file($tesseractPath);
    // Only a filesystem-path value is allowed into cmd.exe. The child retains
    // Apache's inherited PATH, SystemRoot, TEMP, database, and AI settings.
    $canPropagatePoppler = $popplerPath !== '' && preg_match('/^[a-zA-Z0-9_:\\.\\/ -]+$/', $popplerPath) === 1;
    $canPropagateTesseract = $tesseractPath !== '' && preg_match('/^[a-zA-Z0-9_:\\.\\/ -]+$/', $tesseractPath) === 1;
    $adcdEnabled = $runtime['adcd_enabled'];
    $adcdServiceUrl = $runtime['adcd_service_url'];
    $adcdConnectTimeout = $runtime['adcd_connect_timeout'];
    $adcdReadTimeout = $runtime['adcd_read_timeout'];
    $adcdOutputRoot = $runtime['adcd_output_root'];
    $canPropagateAdcdUrl = preg_match('#^http://127\\.0\\.0\\.1:5001$#', $adcdServiceUrl) === 1;
    $canPropagateAdcdTimeouts = preg_match('/^\\d+(?:\\.\\d+)?$/', $adcdConnectTimeout) === 1
        && preg_match('/^\\d+(?:\\.\\d+)?$/', $adcdReadTimeout) === 1;
    $canPropagateAdcdOutputRoot = $adcdOutputRoot !== ''
        && is_dir($adcdOutputRoot)
        && preg_match('/^[a-zA-Z0-9_:\\.\\\\\/ -]+$/', $adcdOutputRoot) === 1;
    // Apache SetEnv values are not reliably inherited by Windows' detached
    // start process. Preserve the isolated test selection explicitly so a
    // test-alias worker cannot connect to the development database.
    $httpTestMode = getenv('AICS_HTTP_TEST_MODE') === '1';
    $testDatabaseName = trim((string) (getenv('AICS_TEST_DB_NAME') ?: getenv('AICS_DB_NAME')));
    $canPropagateTestDatabase = $httpTestMode
        && $testDatabaseName !== ''
        && preg_match('/^[a-zA-Z0-9_]+$/', $testDatabaseName) === 1;
    $lastError = error_get_last();
    error_log('AICS AI launcher diagnostics: python_configured=' . ($pythonExecutable !== '' ? 'yes' : 'no')
        . ' exists=' . ($pythonExists ? 'yes' : 'no')
        . ' is_file=' . ($pythonIsFile ? 'yes' : 'no')
        . ' readable=' . ($pythonReadable ? 'yes' : 'no')
        . ' project_exists=' . ($projectExists ? 'yes' : 'no')
        . ' document_id=' . $documentId
        . ' launch_method=cmd-start-background'
        . ' php_sees_poppler=' . ($popplerPath !== '' ? 'yes' : 'no')
        . ' poppler_directory_valid=' . ($popplerDirectoryValid ? 'yes' : 'no')
        . ' pdfinfo_exists=' . ($pdfinfoExists ? 'yes' : 'no')
        . ' pdftoppm_exists=' . ($pdftoppmExists ? 'yes' : 'no')
        . ' php_sees_tesseract=' . ($tesseractPath !== '' ? 'yes' : 'no')
        . ' tesseract_exists=' . ($tesseractExists ? 'yes' : 'no')
        . ' poppler_environment_explicitly_propagated=' . ($canPropagatePoppler ? 'yes' : 'no')
        . ' tesseract_environment_explicitly_propagated=' . ($canPropagateTesseract ? 'yes' : 'no')
        . ' adcd_enabled=' . $adcdEnabled
        . ' adcd_service_url_explicitly_propagated=' . ($canPropagateAdcdUrl ? 'yes' : 'no')
        . ' adcd_timeouts_explicitly_propagated=' . ($canPropagateAdcdTimeouts ? 'yes' : 'no')
        . ' adcd_output_root_explicitly_propagated=' . ($canPropagateAdcdOutputRoot ? 'yes' : 'no')
        . ' isolated_test_database_explicitly_propagated=' . ($canPropagateTestDatabase ? 'yes' : 'no')
        . ' prior_php_error=' . ($lastError ? 'yes' : 'no'));
    if ($pythonExecutable === '' || !$pythonExists || !$pythonIsFile || !$pythonReadable || !$projectExists) {
        error_log('AICS AI launch error: Python executable or project root is unavailable.');
        return false;
    }
    $logDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'ai_processor' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        error_log('AICS AI launch error: cannot create processor log directory.');
        return false;
    }
    // A shared redirected log can be locked by a concurrent Windows worker,
    // causing `start /B` itself to fail before Python is launched.  Each
    // dispatch receives an append-only diagnostic file; the processor keeps
    // its own application log independently.
    $launchLogFile = $logDirectory . DIRECTORY_SEPARATOR . 'launch-' . $documentId . '-' . str_replace('.', '', sprintf('%.6F', microtime(true))) . '.log';
    $workerWrapper = $projectRoot . DIRECTORY_SEPARATOR . 'ai_processor' . DIRECTORY_SEPARATOR . 'run-ai-worker.bat';
    if (!is_file($workerWrapper) || !is_readable($workerWrapper)) {
        error_log('AICS AI launch error: worker wrapper is unavailable.');
        return false;
    }
    // `start /B call` leaves the batch command tied to the parent cmd.exe on
    // Windows. Spawn a child cmd.exe instead and redirect all its handles
    // inside that child; Apache/PHP can then return as soon as start succeeds.
    $workerTask = 'call ' . escapeshellarg($workerWrapper)
        . ' ' . $workerArgument . ' ' . escapeshellarg((string) $documentId)
        . ($workerLeaseToken !== '' ? ' --request-lease ' . escapeshellarg($workerLeaseToken) : '')
        . ' < NUL >> ' . escapeshellarg($launchLogFile) . ' 2>&1';
    $workerCommand = 'start "" /B cmd.exe /D /S /C ' . escapeshellarg($workerTask);
    // cmd /C start /B returns after dispatch; stdout is intentionally irrelevant.
    // Set the variable inside cmd.exe because Apache SetEnv is not reliably
    // inherited by a detached Windows start process. This augments, rather
    // than replaces, the inherited environment.
    $environmentPrefix = '';
    if ($canPropagateTestDatabase) {
        $environmentPrefix .= 'set "AICS_HTTP_TEST_MODE=1" && set "AICS_TEST_DB_NAME=' . $testDatabaseName . '" && ';
    }
    if ($canPropagatePoppler) $environmentPrefix .= 'set "AICS_POPPLER_PATH=' . $popplerPath . '" && ';
    if ($canPropagateTesseract) $environmentPrefix .= 'set "AICS_TESSERACT_PATH=' . $tesseractPath . '" && ';
    $environmentPrefix .= 'set "AICS_ADCD_ENABLED=' . $adcdEnabled . '" && ';
    if ($canPropagateAdcdUrl) $environmentPrefix .= 'set "AICS_ADCD_SERVICE_URL=' . $adcdServiceUrl . '" && ';
    if ($canPropagateAdcdTimeouts) {
        $environmentPrefix .= 'set "ADCD_CONNECT_TIMEOUT=' . $adcdConnectTimeout . '" && ';
        $environmentPrefix .= 'set "ADCD_READ_TIMEOUT=' . $adcdReadTimeout . '" && ';
    }
    if ($canPropagateAdcdOutputRoot) $environmentPrefix .= 'set "AICS_ADCD_OUTPUT_ROOT=' . $adcdOutputRoot . '" && ';
    // Windows start may discard cmd-local SET values when Apache dispatches a
    // detached child. Set the same safe values in PHP's process environment,
    // which is inherited by the Python worker.
    putenv('AICS_ADCD_ENABLED=' . $adcdEnabled);
    if ($canPropagateAdcdUrl) putenv('AICS_ADCD_SERVICE_URL=' . $adcdServiceUrl);
    if ($canPropagateAdcdTimeouts) {
        putenv('ADCD_CONNECT_TIMEOUT=' . $adcdConnectTimeout);
        putenv('ADCD_READ_TIMEOUT=' . $adcdReadTimeout);
    }
    if ($canPropagateAdcdOutputRoot) putenv('AICS_ADCD_OUTPUT_ROOT=' . $adcdOutputRoot);
    $command = 'cmd.exe /D /S /C ' . escapeshellarg('cd /d ' . escapeshellarg($projectRoot) . ' && ' . $environmentPrefix . $workerCommand);
    error_log('AICS AI launcher dispatch: document_id=' . $documentId . ' wrapper_configured=yes');
    if (!function_exists('exec')) {
        error_log('AICS AI launch error: exec() is disabled.');
        return false;
    }
    $output = [];
    $exitCode = -1;
    @exec($command, $output, $exitCode);
    $lastError = error_get_last();
    error_log('AICS AI launcher dispatch result: document_id=' . $documentId
        . ' exit_code=' . $exitCode
        . ' output_count=' . count($output)
        . ' prior_php_error=' . ($lastError ? 'yes' : 'no'));
    if ($exitCode !== 0) {
        error_log('AICS AI launch error: background dispatch failed for document_id=' . $documentId);
        return false;
    }
    error_log('AICS AI processor dispatched for document_id=' . $documentId);
    return true;
}

/** Dispatch one trusted sequential worker for all queued documents in a request. */
function aics_launch_request_ai_processor(int $requestId, string $workerLeaseToken): bool
{
    return aics_launch_document_ai_processor($requestId, '--request-id', $workerLeaseToken);
}
