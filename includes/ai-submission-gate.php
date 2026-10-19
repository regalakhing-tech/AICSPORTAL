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
        } elseif (!empty($document['unclear'])) {
            // Only readability/completeness in doubt: the applicant can fix it with a clearer photo.
            $blocking[] = ['reason_status' => 'needs_reupload'] + $document;
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
        'ai_check_urgent' => 'AICS AI: Marked urgent',
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
                $parts[] = $label . ' (' . aics_ai_gate_document_reason(strtolower((string) ($item['reason_status'] ?? $item['status'] ?? ''))) . ')';
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

/** SQL condition for requests the applicant has not cancelled (the gate leaves cancelled ones alone). */
function aics_ai_gate_active_sql(string $alias = ''): string
{
    $column = ($alias !== '' ? $alias . '.' : '') . 'request_status';
    return "LOWER(TRIM(COALESCE({$column}, ''))) NOT IN ('cancelled', 'canceled')";
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

/** A worker counts as running while it reported progress (or started) this recently. */
function aics_ai_gate_heartbeat_minutes(): int
{
    return aics_ai_gate_env_int('AICS_AI_WORKER_HEARTBEAT_MINUTES', 10);
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

/**
 * Requests with a live AI worker: a lease taken in the last few minutes (the
 * worker is starting), or a claimed review whose worker reported progress
 * recently. A crashed worker stops counting after the heartbeat window instead
 * of holding its slot until the 30-minute stall recovery.
 */
function aics_ai_gate_busy_workers(mysqli $connection): int
{
    aics_ensure_request_ai_worker_lease_schema($connection);
    $window = aics_ai_gate_heartbeat_minutes();
    $stmt = $connection->prepare("SELECT COUNT(*) AS total FROM (
        SELECT request_id FROM document_ai_request_worker_leases WHERE acquired_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)
        UNION
        SELECT d.request_id FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id
        WHERE ai.status = 'processing' AND ai.attempt_count > 0 AND COALESCE(ai.worker_heartbeat_at, ai.processing_started_at) > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)
    ) busy");
    if (!$stmt) throw new RuntimeException('Unable to count AI workers.');
    $stmt->bind_param('ii', $window, $window);
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
        $doc['unclear'] = $doc['status'] === 'needs_staff_review' && aics_ai_gate_only_unclear($findings);
        $doc['minutes_since_result'] = isset($row['minutes_since_result']) ? (int) $row['minutes_since_result'] : null;
        $doc['findings'] = $findings;
        $doc['last_error_message'] = (string) ($row['last_error_message'] ?? '');
    }
    unset($doc);
    return $progress;
}

/**
 * True when AICS AI doubted only how readable or complete the document is (a
 * blurry, dark or cut-off photo) and every other check passed. The applicant
 * can fix that with a clearer photo, so it never needs an admin's time.
 */
function aics_ai_gate_only_unclear(array $findings): bool
{
    if (($findings['verdict']['source'] ?? '') !== 'gemini' || !is_array($findings['thesis_checks'] ?? null)) return false;
    $doubts = [];
    foreach ($findings['thesis_checks'] as $key => $check) {
        if (strtolower((string) ($check['state'] ?? '')) !== 'pass') $doubts[] = (string) $key;
    }
    return $doubts !== [] && array_diff($doubts, ['readable', 'complete']) === [];
}

/** One-line AI finding for the admin log. */
function aics_ai_gate_document_detail(array $doc): string
{
    $verdict = is_array($doc['findings']['verdict'] ?? null) ? $doc['findings']['verdict'] : [];
    $headline = trim((string) ($verdict['headline'] ?? ''));
    $reason = trim((string) ($verdict['reason'] ?? ''));
    if ($reason === '' && is_array($doc['findings']['reasons'] ?? null)) $reason = trim((string) ($doc['findings']['reasons'][0] ?? ''));
    if (($verdict['source'] ?? '') === 'local') $headline = 'AICS AI did not run (local OCR only)' . ($headline !== '' ? ' — ' . $headline : '');
    if ($headline === '' && $reason === '') {
        $reason = trim((string) ($doc['last_error_message'] ?? ''))
            ?: (($doc['status'] ?? '') === 'failed' ? 'AICS AI could not check this document.' : 'Status: ' . (string) ($doc['status'] ?? ''));
    }
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
        // Release never waits for AICS AI urgency: a cached analysis is used if there
        // is one; tools/smart-priority-refresh.php upgrades urgent requests later.
        $urgency = aics_smart_priority_analysis_enabled()
            ? aics_smart_priority_for_request($connection, $requestId, $categories, $assistance, false)
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
function aics_ai_gate_process_request(mysqli $connection, array $request, int &$slots, array &$totals, bool $drive = true): void
{
    $requestId = (int) $request['id'];
    if ($drive) $totals['dispatched'] += aics_ai_gate_dispatch($connection, $requestId, $slots);
    $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, $requestId));
    if ($drive && $decision['reason'] === 'ai_failed') {
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
 * With $drive = false it only decides (no AI worker is started or retried).
 *
 * @return array{released:int, held:int, admin_review:int, dispatched:int, retried:int}
 */
function aics_ai_gate_sync(mysqli $connection, ?int $requestId = null, bool $drive = true, int $batch = AICS_AI_GATE_SYNC_BATCH): array
{
    $totals = ['released' => 0, 'held' => 0, 'admin_review' => 0, 'dispatched' => 0, 'retried' => 0];
    aics_ai_gate_ensure_schema($connection);
    aics_ai_gate_purge_events($connection);
    $sql = "SELECT id, reference_no, ai_gate_status, ai_gate_reason FROM aics_requests WHERE ai_gate_status = 'pending' AND " . aics_ai_gate_active_sql()
        . ($requestId !== null ? ' AND id = ' . (int) $requestId : '')
        . ' ORDER BY COALESCE(ai_gate_updated_at, created_at), id LIMIT ' . max(1, $batch);
    $result = $connection->query($sql);
    $requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($requests === []) return $totals;
    $slots = $drive ? max(0, aics_ai_gate_max_workers() - aics_ai_gate_busy_workers($connection)) : 0;
    foreach ($requests as $request) {
        try {
            aics_ai_gate_process_request($connection, $request, $slots, $totals, $drive);
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
function aics_ai_gate_note_documents_changed(mysqli $connection, int $requestId, ?int $actorAccountId, string $eventType, ?string $detail = null): void
{
    if (!in_array($eventType, ['applicant_reupload', 'applicant_edit'], true)) throw new InvalidArgumentException('Unknown applicant change.');
    aics_ai_gate_ensure_schema($connection);
    $detail ??= $eventType === 'applicant_edit' ? 'Applicant edited the application.' : 'Applicant uploaded documents.';
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
    $sql = "SELECT id, reference_no, ai_gate_status, ai_gate_reason FROM aics_requests WHERE ai_gate_status IN ('admin_review', 'pending') AND " . aics_ai_gate_active_sql()
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

/**
 * After an applicant saves a request or uploads files (database only, no AI
 * worker is started here): log the change for the admin, send a held or
 * admin-review request back to checking, and decide what can be decided now.
 */
function aics_ai_gate_after_applicant_save(mysqli $connection, int $requestId, ?int $actorAccountId): void
{
    aics_ai_gate_ensure_schema($connection);
    $stmt = $connection->prepare('SELECT document_type FROM aics_request_documents WHERE request_id = ? AND uploaded_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE) ORDER BY id');
    if (!$stmt) throw new RuntimeException('Unable to read uploaded documents.');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $types = array_column($stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [], 'document_type');
    $stmt->close();
    if ($types === []) {
        aics_ai_gate_note_documents_changed($connection, $requestId, $actorAccountId, 'applicant_edit');
    } else {
        $labels = array_unique(array_map(static fn ($type): string => aics_document_requirement_label((string) $type), $types));
        aics_ai_gate_note_documents_changed($connection, $requestId, $actorAccountId, 'applicant_reupload', 'Applicant uploaded: ' . implode(', ', $labels) . '.');
    }
    aics_ai_gate_sync($connection, $requestId, false);
}

/** Drive this applicant's own pending requests before their status is shown. */
function aics_ai_gate_sync_for_account(mysqli $connection, int $accountId): void
{
    if ($accountId <= 0) return;
    aics_ai_gate_ensure_schema($connection);
    $stmt = $connection->prepare("SELECT r.id FROM aics_requests r INNER JOIN aics_account_requests ar ON ar.request_id = r.id WHERE ar.account_id = ? AND r.ai_gate_status = 'pending' AND " . aics_ai_gate_active_sql('r') . ' ORDER BY r.id DESC LIMIT 5');
    if (!$stmt) return;
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [], 'id'));
    $stmt->close();
    foreach ($ids as $id) aics_ai_gate_sync($connection, $id);
}

/**
 * Documents the applicant must replace, for the status page.
 *
 * @return list<array{label:string, reason:string}>
 */
function aics_ai_gate_applicant_items(mysqli $connection, int $requestId): array
{
    $decision = aics_ai_gate_decide(aics_ai_gate_request_documents($connection, $requestId));
    $items = [];
    foreach (array_merge($decision['blocking'], $decision['uncertain'], $decision['retry']) as $doc) {
        $status = !empty($doc['local_only']) ? 'needs_staff_review' : (string) ($doc['reason_status'] ?? $doc['status'] ?? '');
        $items[] = [
            'type' => aics_normalize_document_type((string) ($doc['document_type'] ?? '')),
            'label' => aics_document_requirement_label((string) ($doc['document_type'] ?? '')),
            'reason' => aics_ai_gate_document_reason($status),
            'fix' => aics_ai_gate_fix_instruction($doc),
        ];
    }
    return $items;
}

/** Minutes a request may wait for AICS AI before the admin is alerted. */
function aics_ai_gate_alert_minutes(): int
{
    return aics_ai_gate_env_int('AICS_AI_GATE_ALERT_MINUTES', 30);
}

/** Requests needing the admin: admin review, plus checks waiting past the alert time. */
function aics_ai_gate_attention_count(mysqli $connection): int
{
    aics_ai_gate_ensure_schema($connection);
    $minutes = aics_ai_gate_alert_minutes();
    $stmt = $connection->prepare("SELECT COUNT(*) AS total FROM aics_requests WHERE " . aics_ai_gate_active_sql() . " AND (ai_gate_status = 'admin_review' OR (ai_gate_status = 'pending' AND COALESCE(ai_gate_updated_at, created_at) < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)))");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $minutes);
    $stmt->execute();
    $total = (int) ($stmt->get_result()?->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

/** @return list<array<string,mixed>> Requests in one gate state, longest waiting first. */
function aics_ai_gate_requests_in_state(mysqli $connection, string $state, int $limit = 100): array
{
    aics_ai_gate_ensure_schema($connection);
    $stmt = $connection->prepare('SELECT id, reference_no, beneficiary_complete_name, ai_gate_reason, TIMESTAMPDIFF(MINUTE, COALESCE(ai_gate_updated_at, created_at), CURRENT_TIMESTAMP) AS minutes_waiting FROM aics_requests WHERE ai_gate_status = ? AND ' . aics_ai_gate_active_sql() . ' ORDER BY COALESCE(ai_gate_updated_at, created_at), id LIMIT ?');
    if (!$stmt) return [];
    $stmt->bind_param('si', $state, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

/**
 * The 7-day admin log grouped by request, most recently active first.
 *
 * @return list<array{request_id:int, reference_no:string, ai_gate_status:string, ai_gate_reason:?string, events:list<array<string,mixed>>}>
 */
function aics_ai_gate_recent_events(mysqli $connection, int $limitRequests = 100): array
{
    aics_ai_gate_ensure_schema($connection);
    $result = $connection->query('SELECT e.id, e.request_id, e.event_type, e.document_type, e.verdict_status, e.detail, e.actor_account_id, e.created_at, r.reference_no, r.ai_gate_status, r.ai_gate_reason
        FROM aics_ai_gate_events e INNER JOIN aics_requests r ON r.id = e.request_id
        WHERE e.created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
        ORDER BY e.created_at DESC, e.id DESC LIMIT 2000');
    $groups = [];
    foreach ($result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $row) {
        $id = (int) $row['request_id'];
        if (!isset($groups[$id])) {
            if (count($groups) >= $limitRequests) continue;
            $groups[$id] = ['request_id' => $id, 'reference_no' => (string) $row['reference_no'], 'ai_gate_status' => (string) $row['ai_gate_status'], 'ai_gate_reason' => $row['ai_gate_reason'], 'events' => []];
        }
        $groups[$id]['events'][] = $row;
    }
    return array_values($groups);
}

/**
 * Gate sync for PSWDO page loads. When the container scheduler and the AI
 * worker already drive the gate (AICS_AI_GATE_SCHEDULER=1), page loads skip it
 * so a long queue never slows PSWDO down.
 */
function aics_ai_gate_page_sync(mysqli $connection): void
{
    if (getenv('AICS_AI_GATE_SCHEDULER') === '1') return;
    aics_ai_gate_sync($connection);
}

/**
 * Live progress of the AICS AI check for the applicant, in plain words and
 * without internal statuses: how many documents are checked and what is
 * happening to each one.
 *
 * @return array{state:string, eta_days:int, total:int, checked:int, documents:list<array{label:string, state:string, status_text:string, percent:int}>}
 */
function aics_ai_gate_applicant_progress(mysqli $connection, int $requestId): array
{
    aics_ai_gate_ensure_schema($connection);
    $stmt = $connection->prepare('SELECT ai_gate_status, ai_gate_eta_days FROM aics_requests WHERE id = ?');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()?->fetch_assoc() ?: [];
    $stmt->close();
    $progress = aics_ai_gate_request_documents($connection, $requestId);
    $decision = aics_ai_gate_decide($progress);
    $retrying = array_flip(array_map(static fn (array $doc): int => (int) ($doc['review_id'] ?? 0), $decision['retry']));
    $steps = ['preprocessing' => 'Preparing the photo', 'ocr' => 'Reading the text', 'verifying' => 'Comparing with your application', 'tamper_scan' => 'Checking for edits'];
    $documents = [];
    foreach ($progress['documents'] as $doc) {
        $label = aics_document_requirement_label((string) ($doc['document_type'] ?? ''));
        if (isset($retrying[(int) ($doc['review_id'] ?? 0)])) {
            $documents[] = ['label' => $label, 'state' => 'checking', 'status_text' => 'Checking again', 'percent' => 5];
        } elseif (($doc['status'] ?? '') === 'processing' || ($doc['status'] ?? '') === '') {
            $stage = (string) ($doc['stage'] ?? 'queued');
            $documents[] = $stage === 'queued' || !isset($steps[$stage])
                ? ['label' => $label, 'state' => 'waiting', 'status_text' => 'Waiting', 'percent' => 0]
                : ['label' => $label, 'state' => 'checking', 'status_text' => $steps[$stage], 'percent' => max(1, min(99, (int) ($doc['progress_percent'] ?? 0)))];
        } else {
            $documents[] = ['label' => $label, 'state' => 'done', 'status_text' => 'Checked', 'percent' => 100];
        }
    }
    return [
        'state' => (string) ($request['ai_gate_status'] ?? 'pending'),
        'eta_days' => (int) ($request['ai_gate_eta_days'] ?? 0),
        'total' => count($documents),
        'checked' => count(array_filter($documents, static fn (array $doc): bool => $doc['state'] === 'done')),
        'documents' => $documents,
    ];
}

/**
 * One plain instruction telling the applicant how to fix a document AICS AI
 * could not accept, based on which of its checks failed.
 */
function aics_ai_gate_fix_instruction(array $doc): string
{
    $findings = is_array($doc['findings'] ?? null) ? $doc['findings'] : [];
    $verdict = is_array($findings['verdict'] ?? null) ? $findings['verdict'] : [];
    $required = trim((string) ($verdict['required_label'] ?? '')) ?: aics_document_requirement_label((string) ($doc['document_type'] ?? ''));
    $detected = trim((string) ($verdict['detected_label'] ?? ''));
    $state = static fn (string $key): string => strtolower(trim((string) ($findings['thesis_checks'][$key]['state'] ?? '')));
    $wrongDocument = ($findings['checks']['document_type']['classification']['decision'] ?? '') === 'wrong_slot'
        || ($detected !== '' && strcasecmp($detected, $required) !== 0 && $state('consistent') === 'fail');
    if ($wrongDocument) {
        return $detected !== ''
            ? "This looks like a {$detected}, not a {$required}. Upload your {$required}."
            : "This is not a {$required}. Upload your {$required}.";
    }
    if ($state('unaltered') === 'fail' || $state('untampered') === 'fail') return 'This document looks edited. Upload a photo or scan of the original document.';
    if ($state('consistent') === 'fail') return 'The name or details on this document do not match your application. Upload the document issued for the person named in your application.';
    $unreadable = in_array($state('readable'), ['fail', 'review'], true);
    $incomplete = in_array($state('complete'), ['fail', 'review'], true);
    if ($unreadable && $incomplete) return 'Take a new, sharp photo in good light that shows the whole page.';
    if ($unreadable) return 'The photo is blurry or hard to read. Take a new photo in good light and keep the camera steady.';
    if ($incomplete) return 'Part of the document is missing or cut off. Upload a photo or scan that shows the whole document.';
    if ((string) ($doc['reason_status'] ?? $doc['status'] ?? '') === 'flagged_inconsistency') {
        return 'The document does not match your application. Check that it is the right document for the right person and upload it again.';
    }
    return 'Take a new, sharp photo in good light that shows the whole page.';
}

/**
 * After release the background job may find the medical case urgent: mark the
 * request urgent (about 1 day) and tell the applicant once. Only requests
 * released by the AICS AI gate are changed.
 */
function aics_ai_gate_note_priority(mysqli $connection, int $requestId, string $indicator): bool
{
    if (strtoupper(trim($indicator)) !== 'URGENT') return false;
    aics_ai_gate_ensure_schema($connection);
    $eta = aics_ai_gate_eta_days('URGENT');
    $stmt = $connection->prepare("UPDATE aics_requests SET ai_gate_priority = 'URGENT', ai_gate_eta_days = ? WHERE id = ? AND ai_gate_status = 'released' AND ai_gate_updated_at IS NOT NULL AND COALESCE(ai_gate_priority, '') <> 'URGENT'");
    $stmt->bind_param('ii', $eta, $requestId);
    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$changed) return false;
    $reference = (string) ($connection->query('SELECT reference_no FROM aics_requests WHERE id = ' . (int) $requestId)?->fetch_row()[0] ?? '');
    aics_ai_gate_log_event($connection, $requestId, 'ai_priority_urgent', null, null, 'AICS AI found the medical case urgent. Expected processing time: about ' . $eta . ' day(s).', null);
    aics_ai_gate_notify($connection, $requestId, 'ai_check_urgent', 'AICS AI marked request ' . $reference . ' as urgent. Expected processing time: about ' . $eta . ' day(s).');
    return true;
}
