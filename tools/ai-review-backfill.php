<?php
declare(strict_types=1);

/**
 * Controlled, CLI-only recovery for accumulated AI document reviews.
 *
 * It deliberately uses the normal request-wide ensure helper and the normal
 * document worker.  It never makes an HTTP endpoint, changes classification,
 * or reprocesses a terminal review.  Use --dry-run before --execute.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI execution only.\n");
}

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

/** @return array{execute:bool,process:bool,cutoff:?string} */
function aics_backfill_options(array $arguments): array
{
    $execute = in_array('--execute', $arguments, true);
    $process = in_array('--process', $arguments, true);
    $cutoff = null;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--recover-launcher-failures-before=')) {
            $cutoff = substr($argument, strlen('--recover-launcher-failures-before='));
        }
    }
    if ($process && !$execute) throw new InvalidArgumentException('--process requires --execute.');
    if ($cutoff !== null && strtotime($cutoff) === false) throw new InvalidArgumentException('Invalid launcher-failure cutoff.');
    return ['execute' => $execute, 'process' => $process, 'cutoff' => $cutoff];
}

/** @return array{summary:array<string,int>,never_by_request:array<int,bool>,launcher_review_ids:array<int,int>,process_document_ids:array<int,int>} */
function aics_backfill_audit(mysqli $connection, ?string $cutoff): array
{
    $summary = array_fill_keys(['applications_scanned', 'documents_scanned', 'eligible_documents', 'completed_canonical', 'legacy_completed', 'never_analyzed', 'queued', 'processing', 'failed_other', 'launcher_recovery_eligible', 'unsupported_manual_only'], 0);
    $neverByRequest = [];
    $launcherReviewIds = [];
    $result = $connection->query("SELECT r.id AS request_id, d.id AS document_id, d.document_type, ai.id AS review_id, ai.status, ai.attempt_count, ai.last_error_code, ai.processed_at, ai.findings FROM aics_requests r INNER JOIN aics_request_documents d ON d.request_id = r.id LEFT JOIN document_ai_reviews ai ON ai.request_document_id = d.id WHERE LOWER(COALESCE(r.request_status, '')) NOT IN ('draft', 'incomplete', 'temporary') ORDER BY r.created_at ASC, r.id ASC, d.id ASC");
    $seenRequests = [];
    while ($row = $result->fetch_assoc()) {
        $requestId = (int) $row['request_id'];
        $documentId = (int) $row['document_id'];
        $seenRequests[$requestId] = true;
        $summary['documents_scanned']++;
        $expected = aics_normalize_document_type((string) ($row['document_type'] ?? ''));
        if (!aics_document_type_is_verifiable($expected)) {
            $summary['unsupported_manual_only']++;
            continue;
        }
        $summary['eligible_documents']++;
        $reviewId = (int) ($row['review_id'] ?? 0);
        if ($reviewId <= 0) {
            $summary['never_analyzed']++;
            $neverByRequest[$requestId] = true;
            continue;
        }
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === 'processing') {
            $summary[((int) ($row['attempt_count'] ?? 0) > 0) ? 'processing' : 'queued']++;
            continue;
        }
        if ($status === 'failed') {
            $isKnownLauncherFailure = (string) ($row['last_error_code'] ?? '') === 'AI_PROCESSOR_LAUNCH_FAILED'
                && $cutoff !== null
                && (string) ($row['processed_at'] ?? '') !== ''
                && (string) $row['processed_at'] < $cutoff;
            if ($isKnownLauncherFailure) {
                $summary['launcher_recovery_eligible']++;
                $launcherReviewIds[$reviewId] = $documentId;
            } else {
                $summary['failed_other']++;
            }
            continue;
        }
        $findings = json_decode((string) ($row['findings'] ?? ''), true);
        $tamperStatus = is_array($findings) && is_array($findings['tamper_detection'] ?? null)
            ? strtolower(trim((string) ($findings['tamper_detection']['status'] ?? ''))) : '';
        if (in_array($tamperStatus, ['completed', 'unavailable'], true)) $summary['completed_canonical']++;
        else $summary['legacy_completed']++;
    }
    $summary['applications_scanned'] = count($seenRequests);
    return ['summary' => $summary, 'never_by_request' => $neverByRequest, 'launcher_review_ids' => $launcherReviewIds, 'process_document_ids' => []];
}

/** Wait for the normal detached worker before launching the next document. */
function aics_backfill_process_sequentially(mysqli $connection, array $documentIds): array
{
    $outcome = ['launched' => 0, 'completed' => 0, 'failed' => 0, 'still_processing' => 0];
    foreach ($documentIds as $documentId) {
        if (!aics_launch_document_ai_processor((int) $documentId)) {
            $outcome['failed']++;
            continue;
        }
        $outcome['launched']++;
        $deadline = time() + 180;
        do {
            usleep(1000000);
            $stmt = $connection->prepare('SELECT status FROM document_ai_reviews WHERE request_document_id = ? LIMIT 1');
            $stmt->bind_param('i', $documentId); $stmt->execute();
            $row = $stmt->get_result()?->fetch_assoc(); $stmt->close();
            $status = strtolower((string) ($row['status'] ?? 'failed'));
            if ($status !== 'processing') break;
        } while (time() < $deadline);
        if ($status === 'processing') $outcome['still_processing']++;
        elseif ($status === 'failed') $outcome['failed']++;
        else $outcome['completed']++;
    }
    return $outcome;
}

try {
    $options = aics_backfill_options(array_slice($argv, 1));
    $connection = aics_db_connect();
    $identity = $connection->query('SELECT DATABASE() AS database_name')->fetch_assoc();
    $database = (string) ($identity['database_name'] ?? '');
    if ($database === '' || strtolower($database) === 'aics_http_regression') throw new RuntimeException('Refusing to run maintenance outside the active normal database.');
    aics_ensure_ai_review_schema($connection);
    $audit = aics_backfill_audit($connection, $options['cutoff']);
    $report = ['mode' => $options['execute'] ? 'execute' : 'dry-run', 'database' => $database, 'launcher_failure_cutoff' => $options['cutoff'], 'before' => $audit['summary'], 'queued_now' => 0, 'recovered_now' => 0, 'processing' => null];
    if ($options['execute']) {
        $processDocuments = [];
        foreach (array_keys($audit['never_by_request']) as $requestId) {
            foreach (aics_ensure_current_ai_reviews_for_request($connection, (int) $requestId) as $queued) {
                $processDocuments[(int) $queued['document_id']] = (int) $queued['document_id'];
                $report['queued_now']++;
            }
        }
        foreach ($audit['launcher_review_ids'] as $reviewId => $documentId) {
            if (aics_recover_launcher_failed_ai_review($connection, (int) $reviewId, (string) $options['cutoff']) !== null) {
                $processDocuments[(int) $documentId] = (int) $documentId;
                $report['recovered_now']++;
            }
        }
        ksort($processDocuments, SORT_NUMERIC);
        if ($options['process']) $report['processing'] = aics_backfill_process_sequentially($connection, array_values($processDocuments));
    }
    $report['after'] = aics_backfill_audit($connection, $options['cutoff'])['summary'];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $connection->close();
} catch (Throwable $exception) {
    fwrite(STDERR, 'AI review backfill failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
