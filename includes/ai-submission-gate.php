<?php
declare(strict_types=1);

/**
 * AICS AI submission gate.
 *
 * PSWDO only receives requests whose documents passed the AICS AI check.
 * Each request carries a gate state decided from its per-document AI review
 * statuses: `pending` (still checking), `held` (the applicant must replace a
 * document), `admin_review` (the AI could not confirm or could not finish)
 * and `released` (visible to PSWDO). Nothing is released on a timeout.
 */

require_once __DIR__ . '/document-requirements.php';
require_once __DIR__ . '/document-ai-reviews.php';
require_once __DIR__ . '/beneficiary-priority.php';

const AICS_AI_GATE_BLOCKING_STATUSES = ['needs_reupload', 'flagged_inconsistency'];
const AICS_AI_GATE_PASSED_STATUSES = ['likely_valid', 'completed'];
const AICS_AI_GATE_SYNC_BATCH = 50;

function aics_ai_gate_env_int(string $name, int $default, int $minimum = 1): int
{
    $value = getenv($name);
    if ($value === false || trim($value) === '' || preg_match('/^\d+$/', trim($value)) !== 1) return $default;
    return max($minimum, (int) trim($value));
}

/** Automatic retries per document before the request goes to the admin. */
function aics_ai_gate_max_retries(): int
{
    return aics_ai_gate_env_int('AICS_AI_GATE_MAX_RETRIES', 4, 0);
}

/**
 * Minutes to wait after a failed result before the next retry, so a short
 * outage (rate limit, restart) does not use up every retry within minutes.
 */
function aics_ai_gate_retry_backoff_minutes(int $retriesDone): int
{
    return [0 => 1, 1 => 5, 2 => 15][max(0, $retriesDone)] ?? 30;
}

/**
 * Decide a request's gate state from aics_ai_request_progress() documents
 * enriched with `retry_count` and `local_only` (aics_ai_gate_request_documents()).
 * A `failed` review or a local-only result (the AICS AI step did not run, so
 * only OCR checked it) is retried, then sent to the admin — never to PSWDO.
 *
 * @return array{state:string, reason:string, blocking:list<array<string,mixed>>, retry:list<array<string,mixed>>, uncertain:list<array<string,mixed>>}
 */
function aics_ai_gate_decide(array $progress, ?int $maxRetries = null): array
{
    $maxRetries ??= aics_ai_gate_max_retries();
    $documents = array_values(array_filter((array) ($progress['documents'] ?? []), 'is_array'));
    $blocking = $retry = $uncertain = [];
    $checking = false;
    $exhausted = false;
    foreach ($documents as $document) {
        $status = strtolower(trim((string) ($document['status'] ?? '')));
        if ($status === 'processing' || $status === '') {
            $checking = true;
        } elseif ($status === 'failed' || !empty($document['local_only'])) {
            if ((int) ($document['retry_count'] ?? 0) < $maxRetries) {
                $retry[] = $document;
            } else {
                $uncertain[] = $document;
                $exhausted = true;
            }
        } elseif (in_array($status, AICS_AI_GATE_BLOCKING_STATUSES, true)) {
            $blocking[] = $document;
        } elseif (!in_array($status, AICS_AI_GATE_PASSED_STATUSES, true)) {
            $uncertain[] = $document;
        }
    }
    $decision = static fn (string $state, string $reason): array => ['state' => $state, 'reason' => $reason, 'blocking' => $blocking, 'retry' => $retry, 'uncertain' => $uncertain];
    if ($documents === []) return $decision('released', 'no_documents');
    if ($blocking !== []) return $decision('held', 'blocked');
    if ($retry !== []) return $decision('pending', 'ai_failed');
    if ($checking) return $decision('pending', 'checking');
    if ($exhausted) return $decision('admin_review', 'ai_unavailable');
    if ($uncertain !== []) return $decision('admin_review', 'uncertain');
    return $decision('released', 'passed');
}

/** Expected processing days for a priority indicator. */
function aics_ai_gate_eta_days(string $indicator): int
{
    return match (strtoupper(trim($indicator))) {
        'URGENT' => aics_ai_gate_env_int('AICS_ETA_URGENT_DAYS', 1),
        'HIGH' => aics_ai_gate_env_int('AICS_ETA_HIGH_DAYS', 3),
        default => aics_ai_gate_env_int('AICS_ETA_STANDARD_DAYS', 7),
    };
}

/** Plain reason shown to the applicant for one document. */
function aics_ai_gate_document_reason(string $status): string
{
    return match ($status) {
        'needs_reupload' => 'blurry, cut off, or incomplete',
        'flagged_inconsistency' => 'does not match your application or looks edited',
        default => 'needs a clearer copy',
    };
}

/** Notification title (stored in notification_stage) for each gate notice. */
function aics_ai_gate_stage_title(string $stage): string
{
    return match ($stage) {
        'ai_check_hold' => 'AICS AI: Re-upload needed',
        'ai_check_admin_review' => 'AICS AI: Being reviewed',
        'ai_check_passed' => 'AICS AI: Documents checked',
        'ai_check_admin_release' => 'Sent to PSWDO for review',
        default => 'AICS AI update',
    };
}

/** Applicant notice text (exact copy from the design spec), at most 600 characters. */
function aics_ai_gate_applicant_message(string $stage, string $referenceNo, array $items, int $etaDays, string $indicator): string
{
    $text = match ($stage) {
        'ai_check_hold' => (static function () use ($referenceNo, $items): string {
            $parts = [];
            foreach ($items as $item) {
                $label = aics_document_requirement_label((string) ($item['document_type'] ?? ''));
                $parts[] = $label . ' (' . aics_ai_gate_document_reason(strtolower((string) ($item['status'] ?? ''))) . ')';
            }
            return 'AICS AI found a problem with ' . count($items) . ' document(s) in request ' . $referenceNo . ': '
                . implode('; ', $parts) . '. Upload a clear replacement in Manage Applications. It will be checked again automatically.';
        })(),
        'ai_check_admin_review' => 'Request ' . $referenceNo . ' is being reviewed before it goes to PSWDO. You will be notified when it moves forward.',
        'ai_check_passed' => 'AICS AI checked the documents for request ' . $referenceNo . '. PSWDO will now review it. Expected processing time: about ' . $etaDays . ' day(s).'
            . (strtoupper($indicator) === 'URGENT' ? ' Your request was marked urgent.' : ''),
        'ai_check_admin_release' => 'Request ' . $referenceNo . ' is now with PSWDO for review. Expected processing time: about ' . $etaDays . ' day(s).',
        default => throw new InvalidArgumentException('Unknown AICS AI gate notice.'),
    };
    return mb_strimwidth($text, 0, 600, '…');
}

/** SQL condition that keeps PSWDO queries to released requests. */
function aics_ai_gate_visible_sql(string $alias = 'r'): string
{
    return "{$alias}.ai_gate_status = 'released'";
}

/**
 * Create the gate columns and event table when missing. The Railway database
 * is never re-imported, so this runs from code (once per PHP process).
 */
function aics_ai_gate_ensure_schema(mysqli $connection): void
{
    static $done = [];
    $key = spl_object_id($connection);
    if (isset($done[$key])) return;
    $columns = $connection->query("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'aics_requests' AND COLUMN_NAME = 'ai_gate_eta_days') OR (TABLE_NAME = 'document_ai_reviews' AND COLUMN_NAME = 'gate_retry_count'))");
    $hasTable = $connection->query("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aics_ai_gate_events'");
    $ready = (int) ($columns?->fetch_assoc()['total'] ?? 0) === 2 && (int) ($hasTable?->fetch_assoc()['total'] ?? 0) === 1;
    if (!$ready) {
        foreach (aics_ai_gate_schema_statements() as $statement) {
            if (!$connection->query($statement)) throw new RuntimeException('Unable to prepare the AICS AI gate schema.');
        }
    }
    $done[$key] = true;
}

/** @return list<string> Same statements as database/AICSdb.sql. */
function aics_ai_gate_schema_statements(): array
{
    return [
        "ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_status` VARCHAR(16) NOT NULL DEFAULT 'released'",
        "ALTER TABLE `aics_requests` ALTER COLUMN `ai_gate_status` SET DEFAULT 'pending'",
        "ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_reason` VARCHAR(24) NULL DEFAULT NULL",
        "ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_updated_at` DATETIME NULL DEFAULT NULL",
        "ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_priority` VARCHAR(16) NULL DEFAULT NULL",
        "ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_eta_days` TINYINT UNSIGNED NULL DEFAULT NULL",
        "ALTER TABLE `aics_requests` ADD INDEX IF NOT EXISTS `idx_aics_requests_ai_gate_status` (`ai_gate_status`)",
        "ALTER TABLE `document_ai_reviews` ADD COLUMN IF NOT EXISTS `gate_retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS `aics_ai_gate_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `document_type` VARCHAR(80) DEFAULT NULL,
  `verdict_status` VARCHAR(40) DEFAULT NULL,
  `detail` VARCHAR(500) NOT NULL DEFAULT '',
  `actor_account_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_ai_gate_events_created_at` (`created_at`),
  KEY `idx_aics_ai_gate_events_request_id` (`request_id`),
  CONSTRAINT `fk_aics_ai_gate_events_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** Most AI workers allowed to run at once, so a rush of submissions queues instead of overloading the server. */
function aics_ai_gate_max_workers(): int
{
    return aics_ai_gate_env_int('AICS_AI_MAX_WORKERS', 2);
}

function aics_ai_gate_stale_minutes(): int
{
    return max(1, (int) (getenv('AICS_AI_STALE_MINUTES') ?: 30));
}

/**
 * Start an AI worker. Tests set AICS_AI_GATE_TEST_LAUNCH=ok|fail (with
 * AICS_HTTP_TEST_MODE=1) to record the launch without starting Python.
 */
function aics_ai_gate_launch(string $workerArgument, int $id, string $leaseToken = ''): bool
{
    $fake = getenv('AICS_HTTP_TEST_MODE') === '1' ? (string) getenv('AICS_AI_GATE_TEST_LAUNCH') : '';
    if ($fake === 'ok' || $fake === 'fail') {
        $GLOBALS['aics_ai_gate_test_launches'][] = [$workerArgument, $id];
        return $fake === 'ok';
    }
    return aics_launch_document_ai_processor($id, $workerArgument, $leaseToken);
}

/** Requests with a live AI worker: a fresh request lease, or a claimed review still inside the stall window. */
function aics_ai_gate_busy_workers(mysqli $connection): int
{
    aics_ensure_request_ai_worker_lease_schema($connection);
    $stale = aics_ai_gate_stale_minutes();
    $stmt = $connection->prepare("SELECT COUNT(*) AS total FROM (
        SELECT request_id FROM document_ai_request_worker_leases WHERE acquired_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)
        UNION
        SELECT d.request_id FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id
        WHERE ai.status = 'processing' AND ai.attempt_count > 0 AND ai.processing_started_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)
    ) busy");
    if (!$stmt) throw new RuntimeException('Unable to count AI workers.');
    $stmt->bind_param('ii', $stale, $stale);
    $stmt->execute();
    $total = (int) ($stmt->get_result()?->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

/**
 * aics_ai_request_progress() documents plus what the gate needs: retries done,
 * whether only local OCR checked the file, minutes since the result, and findings.
 */
function aics_ai_gate_request_documents(mysqli $connection, int $requestId): array
{
    $progress = aics_ai_request_progress($connection, $requestId);
    $ids = array_values(array_filter(array_map(static fn (array $doc): int => (int) ($doc['review_id'] ?? 0), $progress['documents'])));
    $extra = [];
    if ($ids !== []) {
        $result = $connection->query('SELECT id, gate_retry_count, findings, last_error_message, TIMESTAMPDIFF(MINUTE, processed_at, CURRENT_TIMESTAMP) AS minutes_since_result FROM document_ai_reviews WHERE id IN (' . implode(',', $ids) . ')');
        foreach ($result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $row) $extra[(int) $row['id']] = $row;
    }
    foreach ($progress['documents'] as &$doc) {
        $row = $extra[(int) ($doc['review_id'] ?? 0)] ?? [];
        $findings = json_decode((string) ($row['findings'] ?? ''), true);
        $findings = is_array($findings) ? $findings : [];
        $doc['retry_count'] = (int) ($row['gate_retry_count'] ?? 0);
        $doc['local_only'] = ($findings['verdict']['source'] ?? '') === 'local' && $doc['status'] !== 'processing';
        $doc['minutes_since_result'] = isset($row['minutes_since_result']) ? (int) $row['minutes_since_result'] : null;
        $doc['findings'] = $findings;
        $doc['last_error_message'] = (string) ($row['last_error_message'] ?? '');
    }
    unset($doc);
    return $progress;
}

/** One-line AI finding for the admin log. */
function aics_ai_gate_document_detail(array $doc): string
{
    $verdict = is_array($doc['findings']['verdict'] ?? null) ? $doc['findings']['verdict'] : [];
    $headline = trim((string) ($verdict['headline'] ?? ''));
    $reason = trim((string) ($verdict['reason'] ?? ''));
    if ($reason === '' && is_array($doc['findings']['reasons'] ?? null)) $reason = trim((string) ($doc['findings']['reasons'][0] ?? ''));
    if (($verdict['source'] ?? '') === 'local') $headline = 'AICS AI did not run (local OCR only)' . ($headline !== '' ? ' — ' . $headline : '');
    if ($headline === '' && $reason === '') $reason = trim((string) ($doc['last_error_message'] ?? '')) ?: 'Status: ' . (string) ($doc['status'] ?? '');
    return mb_strimwidth(trim($headline . ($headline !== '' && $reason !== '' ? ': ' : '') . $reason), 0, 500, '…');
}

function aics_ai_gate_log_event(mysqli $connection, int $requestId, string $eventType, ?string $documentType, ?string $verdictStatus, string $detail, ?int $actorAccountId): void
{
    $detail = mb_strimwidth($detail, 0, 500, '…');
    $stmt = $connection->prepare('INSERT INTO aics_ai_gate_events (request_id, event_type, document_type, verdict_status, detail, actor_account_id) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new RuntimeException('Unable to record the AICS AI gate event.');
    $stmt->bind_param('issssi', $requestId, $eventType, $documentType, $verdictStatus, $detail, $actorAccountId);
    $stmt->execute();
    $stmt->close();
}

/** Delete admin log records older than 7 days. */
function aics_ai_gate_purge_events(mysqli $connection): int
{
    $connection->query('DELETE FROM aics_ai_gate_events WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)');
    return max(0, $connection->affected_rows);
}

function aics_ai_gate_notify(mysqli $connection, int $requestId, string $stage, string $message): void
{
    $title = aics_ai_gate_stage_title($stage);
    $channel = 'applicant_portal';
    $stmt = $connection->prepare('INSERT INTO aics_request_notifications (request_id, notification_stage, channel, message_text, sent_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
    if (!$stmt) throw new RuntimeException('Unable to save the applicant notice.');
    $stmt->bind_param('isss', $requestId, $title, $channel, $message);
    $stmt->execute();
    $stmt->close();
}

/** The Smart Priority indicator PSWDO shows for this request: URGENT, HIGH or STANDARD. */
function aics_ai_gate_priority_indicator(mysqli $connection, int $requestId): string
{
    $stmt = $connection->prepare("SELECT r.client_categories_json, t.assistance_code FROM aics_requests r
        LEFT JOIN aics_request_assistance ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types t ON t.id = ra.assistance_type_id
        WHERE r.id = ? ORDER BY (t.assistance_code = 'medical') DESC LIMIT 1");
    if (!$stmt) return 'STANDARD';
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc() ?: [];
    $stmt->close();
    $categories = json_decode((string) ($row['client_categories_json'] ?? '[]'), true);
    $categories = is_array($categories) ? array_map(static fn ($value): string => strtolower(trim((string) $value)), $categories) : [];
    $assistance = (string) ($row['assistance_code'] ?? '');
    try {
        $urgency = aics_smart_priority_analysis_enabled()
            ? aics_smart_priority_for_request($connection, $requestId, $categories, $assistance)
            : aics_medical_urgency_from_document_reviews($connection, $requestId, $assistance);
    } catch (Throwable) {
        return 'STANDARD';
    }
    $indicator = strtoupper((string) ($urgency['indicator'] ?? 'STANDARD'));
    return in_array($indicator, ['URGENT', 'HIGH'], true) ? $indicator : 'STANDARD';
}

/** Requeue a review as a fresh job (the worker refuses rows with 3+ attempts). */
function aics_ai_gate_requeue_review(mysqli $connection, int $reviewId, bool $freshRetryBudget = false): bool
{
    $reset = $freshRetryBudget ? 1 : 0;
    $stmt = $connection->prepare("UPDATE document_ai_reviews SET status = 'processing', processing_stage = 'queued', progress_percent = 0, stage_updated_at = CURRENT_TIMESTAMP, worker_heartbeat_at = NULL, attempt_count = 0, last_error_code = NULL, last_error_message = NULL, processing_started_at = CURRENT_TIMESTAMP, processed_at = NULL, gate_retry_count = IF(? = 1, 0, LEAST(gate_retry_count + 1, 255)) WHERE id = ? AND status <> 'processing'");
    if (!$stmt) throw new RuntimeException('Unable to retry the AICS AI check.');
    $stmt->bind_param('ii', $reset, $reviewId);
    $stmt->execute();
    $updated = $stmt->affected_rows === 1;
    $stmt->close();
    return $updated;
}

/**
 * Recover stalled work, create missing reviews and start one request worker
 * when a slot is free. A lease that cannot be used is released at once.
 */
function aics_ai_gate_dispatch(mysqli $connection, int $requestId, int &$slots): int
{
    $ensure = aics_ensure_current_ai_reviews_for_request($connection, $requestId);
    if (!(bool) $ensure['worker_needed']) return 0;
    $lease = (string) ($ensure['worker_lease_token'] ?? '');
    if ($slots > 0 && $lease !== '') {
        if (aics_ai_gate_launch('--request-id', $requestId, $lease)) {
            $slots--;
            return 1;
        }
        foreach ((array) ($ensure['queued'] ?? []) as $item) {
            aics_mark_ai_review_failed($connection, (int) ($item['review_id'] ?? 0), 'AI_PROCESSOR_LAUNCH_FAILED', 'AICS AI could not be started.');
        }
    }
    $release = $connection->prepare('DELETE FROM document_ai_request_worker_leases WHERE request_id = ? AND lease_token = ?');
    if ($release) {
        $release->bind_param('is', $requestId, $lease);
        $release->execute();
        $release->close();
    }
    return 0;
}

/** Move a request between gate states; true only for the caller that made the change. */
function aics_ai_gate_transition(mysqli $connection, int $requestId, string $from, string $to, string $reason, ?string $priority = null, ?int $etaDays = null): bool
{
    $stmt = $connection->prepare('UPDATE aics_requests SET ai_gate_status = ?, ai_gate_reason = ?, ai_gate_updated_at = CURRENT_TIMESTAMP, ai_gate_priority = COALESCE(?, ai_gate_priority), ai_gate_eta_days = COALESCE(?, ai_gate_eta_days) WHERE id = ? AND ai_gate_status = ?');
    if (!$stmt) throw new RuntimeException('Unable to update the AICS AI gate.');
    $stmt->bind_param('sssiis', $to, $reason, $priority, $etaDays, $requestId, $from);
    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    return $changed;
}

/** Release a request to PSWDO with its expected processing time. */
function aics_ai_gate_release(mysqli $connection, array $request, string $from, string $reason, string $stage, string $eventType, ?int $actorAccountId): bool
{
    $requestId = (int) $request['id'];
    $indicator = aics_ai_gate_priority_indicator($connection, $requestId);
    $eta = aics_ai_gate_eta_days($indicator);
    if (!aics_ai_gate_transition($connection, $requestId, $from, 'released', $reason, $indicator, $eta)) return false;
    $detail = match ($reason) {
        'passed' => 'All documents passed the AICS AI check.',
        'no_documents' => 'No documents needed an AICS AI check.',
        default => 'Released to PSWDO by an administrator.',
    };
    aics_ai_gate_log_event($connection, $requestId, $eventType, null, null, $detail . ' Priority ' . $indicator . ', about ' . $eta . ' day(s).', $actorAccountId);
    aics_ai_gate_notify($connection, $requestId, $stage, aics_ai_gate_applicant_message($stage, (string) $request['reference_no'], [], $eta, $indicator));
    return true;
}

/** Apply a decision to one request; totals count transitions made by this caller. */
function aics_ai_gate_apply(mysqli $connection, array $request, array $decision, array &$totals): void
{
    $requestId = (int) $request['id'];
    $from = (string) $request['ai_gate_status'];
    $state = $decision['state'];
    $reason = $decision['reason'];
    if ($state === $from) {
        if ($reason !== (string) ($request['ai_gate_reason'] ?? '')) {
            $stmt = $connection->prepare('UPDATE aics_requests SET ai_gate_reason = ? WHERE id = ? AND ai_gate_status = ?');
            if ($stmt) { $stmt->bind_param('sis', $reason, $requestId, $from); $stmt->execute(); $stmt->close(); }
        }
        return;
    }
    if ($state === 'released') {
        $stage = $reason === 'no_documents' ? 'ai_check_admin_release' : 'ai_check_passed';
        if (aics_ai_gate_release($connection, $request, $from, $reason, $stage, 'ai_release', null)) $totals['released']++;
        return;
    }
    if (!aics_ai_gate_transition($connection, $requestId, $from, $state, $reason)) return;
    $documents = $state === 'held' ? $decision['blocking'] : $decision['uncertain'];
    foreach ($documents as $doc) {
        aics_ai_gate_log_event($connection, $requestId, $state === 'held' ? 'ai_hold' : 'ai_admin_review', (string) $doc['document_type'], (string) $doc['status'], aics_ai_gate_document_detail($doc), null);
    }
    $stage = $state === 'held' ? 'ai_check_hold' : 'ai_check_admin_review';
    aics_ai_gate_notify($connection, $requestId, $stage, aics_ai_gate_applicant_message($stage, (string) $request['reference_no'], $documents, 0, 'STANDARD'));
    $totals[$state === 'held' ? 'held' : 'admin_review']++;
}

/** Drive one pending request: start or retry its AI checks, then apply the decision. */
function aics_ai_gate_process_request(mysqli $connection, array $request, int &$slots, array &$totals): void
{
    $requestId = (int) $request['id'];
    $totals['dispatched'] += aics_ai_gate_dispatch($connection, $requestId, $slots);
    $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, $requestId));
    if ($decision['reason'] === 'ai_failed') {
        $requeued = 0;
        foreach ($decision['retry'] as $doc) {
            $since = $doc['minutes_since_result'];
            if ($since !== null && $since < aics_ai_gate_retry_backoff_minutes((int) $doc['retry_count'])) continue;
            if (!aics_ai_gate_requeue_review($connection, (int) $doc['review_id'])) continue;
            $requeued++;
            aics_ai_gate_log_event($connection, $requestId, 'ai_retry', (string) $doc['document_type'], (string) $doc['status'],
                'Retry ' . ((int) $doc['retry_count'] + 1) . ' of ' . aics_ai_gate_max_retries() . '. ' . aics_ai_gate_document_detail($doc), null);
        }
        if ($requeued > 0) {
            $totals['retried'] += $requeued;
            $totals['dispatched'] += aics_ai_gate_dispatch($connection, $requestId, $slots);
            $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, $requestId));
        }
    }
    aics_ai_gate_apply($connection, $request, $decision, $totals);
}

/**
 * Drive pending requests (oldest first) and apply AICS AI decisions. Safe to
 * call from many places at once: every transition is a conditional UPDATE.
 *
 * @return array{released:int, held:int, admin_review:int, dispatched:int, retried:int}
 */
function aics_ai_gate_sync(mysqli $connection, ?int $requestId = null): array
{
    $totals = ['released' => 0, 'held' => 0, 'admin_review' => 0, 'dispatched' => 0, 'retried' => 0];
    aics_ai_gate_ensure_schema($connection);
    aics_ai_gate_purge_events($connection);
    $sql = "SELECT id, reference_no, ai_gate_status, ai_gate_reason FROM aics_requests WHERE ai_gate_status = 'pending'"
        . ($requestId !== null ? ' AND id = ' . (int) $requestId : '')
        . ' ORDER BY COALESCE(ai_gate_updated_at, created_at), id LIMIT ' . AICS_AI_GATE_SYNC_BATCH;
    $result = $connection->query($sql);
    $requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($requests === []) return $totals;
    $slots = max(0, aics_ai_gate_max_workers() - aics_ai_gate_busy_workers($connection));
    foreach ($requests as $request) {
        try {
            aics_ai_gate_process_request($connection, $request, $slots, $totals);
        } catch (Throwable $exception) {
            error_log('AICS AI gate sync failed for request_id=' . (int) $request['id'] . ': ' . $exception->getMessage());
        }
    }
    return $totals;
}

function aics_ai_gate_is_released(mysqli $connection, int $requestId): bool
{
    aics_ai_gate_ensure_schema($connection);
    $stmt = $connection->prepare("SELECT 1 FROM aics_requests WHERE id = ? AND ai_gate_status = 'released'");
    if (!$stmt) return false;
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $released = (bool) $stmt->get_result()?->fetch_row();
    $stmt->close();
    return $released;
}

/** Log an applicant change; a held or admin-review request is checked again. */
function aics_ai_gate_note_documents_changed(mysqli $connection, int $requestId, ?int $actorAccountId, string $eventType): void
{
    if (!in_array($eventType, ['applicant_reupload', 'applicant_edit'], true)) throw new InvalidArgumentException('Unknown applicant change.');
    aics_ai_gate_ensure_schema($connection);
    $detail = $eventType === 'applicant_edit' ? 'Applicant edited the application.' : 'Applicant uploaded documents.';
    aics_ai_gate_log_event($connection, $requestId, $eventType, null, null, $detail, $actorAccountId);
    $stmt = $connection->prepare("UPDATE aics_requests SET ai_gate_status = 'pending', ai_gate_reason = 'checking', ai_gate_updated_at = CURRENT_TIMESTAMP WHERE id = ? AND ai_gate_status IN ('held', 'admin_review')");
    if (!$stmt) throw new RuntimeException('Unable to update the AICS AI gate.');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $stmt->close();
}

/** @return array<string,mixed>|null */
function aics_ai_gate_request_row(mysqli $connection, int $requestId): ?array
{
    $stmt = $connection->prepare('SELECT id, reference_no, ai_gate_status, ai_gate_reason FROM aics_requests WHERE id = ?');
    if (!$stmt) return null;
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

/** Admin: send a request that has not passed to PSWDO. */
function aics_ai_gate_admin_release(mysqli $connection, int $requestId, int $adminAccountId): bool
{
    aics_ai_gate_ensure_schema($connection);
    $request = aics_ai_gate_request_row($connection, $requestId);
    if ($request === null || $request['ai_gate_status'] === 'released') return false;
    return aics_ai_gate_release($connection, $request, (string) $request['ai_gate_status'], 'admin', 'ai_check_admin_release', 'admin_release', $adminAccountId > 0 ? $adminAccountId : null);
}

/** Admin: send an admin-review request back to the applicant to replace the doubtful documents. */
function aics_ai_gate_admin_request_reupload(mysqli $connection, int $requestId, int $adminAccountId): bool
{
    aics_ai_gate_ensure_schema($connection);
    $request = aics_ai_gate_request_row($connection, $requestId);
    if ($request === null || $request['ai_gate_status'] !== 'admin_review') return false;
    $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, $requestId));
    $items = array_merge($decision['uncertain'], $decision['blocking'], $decision['retry']);
    if ($items === [] || !aics_ai_gate_transition($connection, $requestId, 'admin_review', 'held', 'admin_reupload')) return false;
    foreach ($items as $doc) {
        aics_ai_gate_log_event($connection, $requestId, 'admin_reupload_request', (string) $doc['document_type'], (string) $doc['status'], 'Administrator asked the applicant for a clearer copy.', $adminAccountId > 0 ? $adminAccountId : null);
    }
    $items = array_map(static fn (array $doc): array => ['status' => 'needs_staff_review'] + $doc, $items);
    aics_ai_gate_notify($connection, $requestId, 'ai_check_hold', aics_ai_gate_applicant_message('ai_check_hold', (string) $request['reference_no'], $items, 0, 'STANDARD'));
    return true;
}

/**
 * Admin: check again with a fresh retry budget — one request, or (null) every
 * request the AI could not finish. Returns the number of requests retried.
 */
function aics_ai_gate_admin_retry(mysqli $connection, ?int $requestId, int $adminAccountId): int
{
    aics_ai_gate_ensure_schema($connection);
    $sql = "SELECT id, reference_no, ai_gate_status, ai_gate_reason FROM aics_requests WHERE ai_gate_status IN ('admin_review', 'pending')"
        . ($requestId !== null ? ' AND id = ' . (int) $requestId : " AND ai_gate_status = 'admin_review' AND ai_gate_reason = 'ai_unavailable'");
    $result = $connection->query($sql);
    $retried = 0;
    foreach ($result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $request) {
        $id = (int) $request['id'];
        $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, $id));
        $requeued = 0;
        foreach (array_merge($decision['retry'], $decision['uncertain']) as $doc) {
            if (($doc['status'] === 'failed' || !empty($doc['local_only'])) && aics_ai_gate_requeue_review($connection, (int) $doc['review_id'], true)) $requeued++;
        }
        if ($requeued === 0) continue;
        if ($request['ai_gate_status'] !== 'pending') aics_ai_gate_transition($connection, $id, (string) $request['ai_gate_status'], 'pending', 'checking');
        aics_ai_gate_log_event($connection, $id, 'admin_retry', null, null, 'Administrator asked AICS AI to check ' . $requeued . ' document(s) again.', $adminAccountId > 0 ? $adminAccountId : null);
        aics_ai_gate_sync($connection, $id);
        $retried++;
    }
    return $retried;
}

/** True when the AI worker will find an AICS AI key, resolved like ai_processor/config.py does. */
function aics_ai_gate_ai_key_configured(): bool
{
    $names = ['AICS_GEMINI_API_KEY', 'AICS_GEMINI_IMAGE_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'];
    foreach ($names as $name) {
        if (trim((string) getenv($name)) !== '') return true;
    }
    $file = (string) (getenv('AICS_EXTERNAL_AI_ENV_FILE') ?: 'C:\xampp\aics_private\aics-ai.env');
    $lines = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
    foreach (is_array($lines) ? $lines : [] as $line) {
        $line = trim(str_replace("\xEF\xBB\xBF", '', $line));
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (in_array($name, $names, true) && $value !== '') return true;
    }
    return false;
}
