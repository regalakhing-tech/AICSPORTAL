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

const AICS_AI_GATE_BLOCKING_STATUSES = ['needs_reupload', 'flagged_inconsistency'];
const AICS_AI_GATE_PASSED_STATUSES = ['likely_valid', 'completed'];
const AICS_AI_GATE_SYNC_BATCH = 50;

function aics_ai_gate_env_int(string $name, int $default, int $minimum = 1): int
{
    $value = getenv($name);
    if ($value === false || trim($value) === '' || preg_match('/^\d+$/', trim($value)) !== 1) return $default;
    return max($minimum, (int) trim($value));
}

/** Attempts per document before the request goes to the admin. */
function aics_ai_gate_max_attempts(): int
{
    return aics_ai_gate_env_int('AICS_AI_GATE_MAX_ATTEMPTS', 5);
}

/**
 * Minutes to wait after a failed attempt before retrying, so a short outage
 * (rate limit, restart) does not use up every attempt within minutes.
 */
function aics_ai_gate_retry_backoff_minutes(int $attemptCount): int
{
    if ($attemptCount <= 0) return 0;
    return [1 => 1, 2 => 5, 3 => 15][$attemptCount] ?? 30;
}

/**
 * Decide a request's gate state from aics_ai_request_progress().
 *
 * @return array{state:string, reason:string, blocking:list<array<string,mixed>>, retry:list<array<string,mixed>>, uncertain:list<array<string,mixed>>}
 */
function aics_ai_gate_decide(array $progress, ?int $maxAttempts = null): array
{
    $maxAttempts ??= aics_ai_gate_max_attempts();
    $documents = array_values(array_filter((array) ($progress['documents'] ?? []), 'is_array'));
    $blocking = $retry = $uncertain = [];
    $checking = false;
    $exhausted = false;
    foreach ($documents as $document) {
        $status = strtolower(trim((string) ($document['status'] ?? '')));
        if (in_array($status, AICS_AI_GATE_BLOCKING_STATUSES, true)) {
            $blocking[] = $document;
        } elseif ($status === 'failed') {
            if ((int) ($document['attempt_count'] ?? 0) < $maxAttempts) {
                $retry[] = $document;
            } else {
                $uncertain[] = $document;
                $exhausted = true;
            }
        } elseif ($status === 'needs_staff_review') {
            $uncertain[] = $document;
        } elseif (!in_array($status, AICS_AI_GATE_PASSED_STATUSES, true)) {
            $checking = true;
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
    $hasColumn = $connection->query("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aics_requests' AND COLUMN_NAME = 'ai_gate_eta_days'");
    $hasTable = $connection->query("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aics_ai_gate_events'");
    $ready = (int) ($hasColumn?->fetch_assoc()['total'] ?? 0) === 1 && (int) ($hasTable?->fetch_assoc()['total'] ?? 0) === 1;
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
