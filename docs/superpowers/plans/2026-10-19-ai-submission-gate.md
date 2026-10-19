# AICS AI Submission Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PSWDO only receives requests whose documents passed the AICS AI check. Failed documents go back to the applicant, uncertain or unfinished checks go to the admin, AICS AI is driven until it finishes, and applicants are told what to fix or how long processing will take.

**Architecture:** A per-request gate state (`pending` → `held` / `admin_review` / `released`) on `aics_requests`, decided by one PHP module (`includes/ai-submission-gate.php`) from the existing per-document AI review statuses. The same module drives the AI: it starts workers for queued checks (capped), recovers stalls and retries failures. A scheduler loop in the container, the AI worker (after each finished request) and page loads all run the same idempotent `aics_ai_gate_sync()`. PSWDO queries filter on `released`; a new admin page shows alerts, decisions and the 7-day log.

**Tech Stack:** PHP 8.2 + mysqli, MariaDB, Python 3 worker (`ai_processor`), plain-PHP test scripts in `tests/`, Python `unittest`.

**Spec:** `docs/superpowers/specs/2026-10-19-ai-submission-gate-design.md`

## Global Constraints

- Nothing is ever released to PSWDO automatically unless every verifiable document is `likely_valid` or `completed` (or the request has no verifiable documents). No timeout release.
- Blocking AI statuses are exactly `needs_reupload` and `flagged_inconsistency`.
- Retry limit: the existing `AICS_AI_REVIEW_MAX_ATTEMPTS` (3). Stall time: the existing `AICS_AI_STALE_MINUTES` (default 30).
- Admin alert threshold: `AICS_AI_GATE_ALERT_MINUTES`, default `30`. Concurrent AI workers: `AICS_AI_MAX_WORKERS`, default `2`. Requests examined per sync: constant `AICS_AI_GATE_SYNC_BATCH = 50`.
- Expected processing days: URGENT `1`, HIGH `3`, STANDARD `7`; env overrides `AICS_ETA_URGENT_DAYS`, `AICS_ETA_HIGH_DAYS`, `AICS_ETA_STANDARD_DAYS`.
- Admin event records are deleted when older than 7 days; requests, documents and AI reviews are never deleted by this feature.
- Applicant and admin text says "AICS AI", never "Gemini". Applicant copy is fixed in the spec's "Applicant copy (exact)" section.
- Notification `message_text` is `VARCHAR(600)`: messages are cut to 600 characters.
- Every schema change goes in `database/AICSdb.sql` (idempotent) **and** in `aics_ai_gate_ensure_schema()` — the Railway database is not re-imported.
- Existing rows get `ai_gate_status = 'released'`; new rows default to `'pending'`.
- New screens must have no horizontal overflow at 360 px.
- DB tests run only against the isolated `aics_http_regression` database with `AICS_HTTP_TEST_MODE=1` (see `tests/run-http-regressions.ps1`), never `AICSdb`. Tests never start Python: they set `AICS_AI_GATE_TEST_LAUNCH=ok|fail`.

## Review Focus

1. **AI service down** (Python missing, every launch fails): requests stay out of PSWDO, each check is retried until the 3-attempt limit, then the request is `admin_review` with reason `ai_unavailable` and the admin page shows the alert — Task 2 test `testLaunchFailureEndsInAdminReview`.
2. **Worker hangs** (claimed review older than the stall time): it is recovered to `failed` and retried, not left `pending` forever — Task 2 test `testStalledReviewIsRetried`.
3. **Many submissions at once** (e.g. 1,000): one sync starts at most `AICS_AI_MAX_WORKERS` workers in total, counting ones already running — Task 2 test `testWorkerCapLimitsDispatch`.
4. **Two syncs at once** (scheduler and a page load): exactly one event and one notification per transition — Task 2 test `testSecondSyncIsNoop`.
5. **Applicant re-uploads a held document:** the request goes back to `pending` and is re-decided on the new review — Task 2 test `testReuploadReturnsToPending` and the Task 5 DB test.

---

### Task 1: Gate schema and pure decision functions

**Files:**
- Create: `includes/ai-submission-gate.php`
- Modify: `includes/document-ai-reviews.php` (`aics_ai_request_progress`: add `'attempt_count' => (int) ($row['attempt_count'] ?? 0)` to each `documents[]` entry; the column is already selected)
- Modify: `database/AICSdb.sql` (after the `aics_requests` block and its ALTERs)
- Test: `tests/ai-submission-gate-test.php`

**Interfaces:**
- Produces:
  - `aics_ai_gate_ensure_schema(mysqli $connection): void` (static flag: runs its checks once per PHP process)
  - `aics_ai_gate_decide(array $progress, int $maxAttempts = AICS_AI_REVIEW_MAX_ATTEMPTS): array{state:string, reason:string, blocking:list<array>, retry:list<array>, uncertain:list<array>}` — `$progress` is `aics_ai_request_progress()`'s return; list items are its `documents[]` entries. `state` ∈ `pending|held|admin_review|released`; `reason` ∈ `no_documents|blocked|ai_failed|checking|ai_unavailable|uncertain|passed`; `retry` = `failed` docs with `attempt_count < $maxAttempts`; `uncertain` = `needs_staff_review` docs plus `failed` docs at the limit.
  - `aics_ai_gate_eta_days(string $indicator): int`
  - `aics_ai_gate_applicant_message(string $stage, string $referenceNo, array $items, int $etaDays, string $indicator): string` — `$stage` ∈ `ai_check_hold|ai_check_admin_review|ai_check_passed|ai_check_admin_release`
  - `aics_ai_gate_visible_sql(string $alias = 'r'): string` → `"{$alias}.ai_gate_status = 'released'"`

- [ ] **Step 1: Write the failing test** `tests/ai-submission-gate-test.php` (plain PHP, `gate_assert()` helper that throws, prints `PASS` at the end):

```php
$doc = fn (string $status, int $attempts = 1, int $id = 1) => ['document_type' => 'med_cert', 'status' => $status, 'review_id' => $id, 'attempt_count' => $attempts, 'is_terminal' => $status !== 'processing'];
$p = fn (array $docs) => ['total' => count($docs), 'documents' => $docs];
$d = fn (array $docs) => aics_ai_gate_decide($p($docs), 3);

gate_assert($d([])['state'] === 'released' && $d([])['reason'] === 'no_documents', 'no verifiable documents releases');
gate_assert($d([$doc('likely_valid'), $doc('completed')])['reason'] === 'passed', 'all valid passes');
$held = $d([$doc('likely_valid'), $doc('needs_reupload', 1, 7), $doc('processing')]);
gate_assert($held['state'] === 'held' && $held['blocking'][0]['review_id'] === 7, 'one blocking doc holds even while others run');
gate_assert($d([$doc('flagged_inconsistency'), $doc('failed', 1)])['state'] === 'held', 'blocking beats failure');
$retry = $d([$doc('failed', 2, 9), $doc('likely_valid')]);
gate_assert($retry['state'] === 'pending' && $retry['reason'] === 'ai_failed' && $retry['retry'][0]['review_id'] === 9, 'failed with attempts left is retried');
gate_assert($d([$doc('processing', 0)])['reason'] === 'checking', 'still checking');
gate_assert($d([$doc('failed', 3), $doc('processing')])['reason'] === 'checking', 'waits for other documents before giving up');
$gaveUp = $d([$doc('failed', 3, 4), $doc('likely_valid')]);
gate_assert($gaveUp['state'] === 'admin_review' && $gaveUp['reason'] === 'ai_unavailable' && $gaveUp['uncertain'][0]['review_id'] === 4, 'exhausted retries go to admin');
gate_assert($d([$doc('needs_staff_review'), $doc('likely_valid')])['reason'] === 'uncertain', 'uncertain goes to admin, never PSWDO');
foreach ([[$doc('failed', 1)], [$doc('failed', 3)], [$doc('processing')], [$doc('needs_staff_review')]] as $docs) {
    gate_assert($d($docs)['state'] !== 'released', 'only a pass releases');
}

gate_assert(aics_ai_gate_eta_days('URGENT') === 1 && aics_ai_gate_eta_days('HIGH') === 3 && aics_ai_gate_eta_days('STANDARD') === 7, 'default ETA days');
gate_assert(aics_ai_gate_eta_days('unknown') === 7, 'unknown indicator uses STANDARD');
putenv('AICS_ETA_HIGH_DAYS=2'); gate_assert(aics_ai_gate_eta_days('HIGH') === 2, 'env override'); putenv('AICS_ETA_HIGH_DAYS');

$hold = aics_ai_gate_applicant_message('ai_check_hold', 'AICS-2026-0001', [$doc('needs_reupload')], 0, 'STANDARD');
gate_assert($hold === 'AICS AI found a problem with 1 document(s) in request AICS-2026-0001: Medical Certificate (blurry, cut off, or incomplete). Upload a clear replacement in Manage Applications. It will be checked again automatically.', 'hold copy');
gate_assert(str_contains(aics_ai_gate_applicant_message('ai_check_hold', 'X', [$doc('needs_staff_review')], 0, 'STANDARD'), 'Medical Certificate (needs a clearer copy)'), 'admin re-upload reason');
gate_assert(aics_ai_gate_applicant_message('ai_check_admin_review', 'AICS-2026-0001', [], 0, 'STANDARD') === 'Request AICS-2026-0001 is being reviewed before it goes to PSWDO. You will be notified when it moves forward.', 'admin review copy');
$urgent = aics_ai_gate_applicant_message('ai_check_passed', 'AICS-2026-0001', [], 1, 'URGENT');
gate_assert($urgent === 'AICS AI checked the documents for request AICS-2026-0001. PSWDO will now review it. Expected processing time: about 1 day(s). Your request was marked urgent.', 'urgent copy');
gate_assert(aics_ai_gate_applicant_message('ai_check_admin_release', 'AICS-2026-0001', [], 7, 'STANDARD') === 'Request AICS-2026-0001 is now with PSWDO for review. Expected processing time: about 7 day(s).', 'admin release copy');
$many = array_fill(0, 40, ['document_type' => 'billing_statement', 'status' => 'flagged_inconsistency', 'review_id' => 1]);
gate_assert(strlen(aics_ai_gate_applicant_message('ai_check_hold', 'X', $many, 0, 'STANDARD')) <= 600, 'message fits VARCHAR(600)');
gate_assert(stripos($hold . $urgent, 'gemini') === false, 'branding');
gate_assert(aics_ai_gate_visible_sql('rq') === "rq.ai_gate_status = 'released'", 'visibility fragment');
```

Document labels come from `aics_document_requirement_label()` (`includes/document-requirements.php`).

- [ ] **Step 2: Run it to verify it fails**

Run: `& "C:\xampp\php\php.exe" tests\ai-submission-gate-test.php`
Expected: fatal "Call to undefined function aics_ai_gate_decide()".

- [ ] **Step 3: Implement the pure functions in `includes/ai-submission-gate.php`** (`declare(strict_types=1)`, requires `document-requirements.php` and `document-ai-reviews.php`). Decision order is the spec's D2: no documents → released/no_documents; any blocking → held/blocked (all blocking docs listed); any `failed` with attempts left → pending/ai_failed; any non-terminal → pending/checking; any `failed` at the limit → admin_review/ai_unavailable; any `needs_staff_review` → admin_review/uncertain; else released/passed. Truncate messages with `mb_strimwidth($text, 0, 600, '…')`. Add `attempt_count` to the progress entries.

- [ ] **Step 4: Add the schema** in `database/AICSdb.sql` and in `aics_ai_gate_ensure_schema()` (same statements, `IF NOT EXISTS`):

```sql
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_status` VARCHAR(16) NOT NULL DEFAULT 'released';
ALTER TABLE `aics_requests` ALTER COLUMN `ai_gate_status` SET DEFAULT 'pending';
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_reason` VARCHAR(24) NULL DEFAULT NULL;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_updated_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_priority` VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_eta_days` TINYINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `aics_requests` ADD INDEX IF NOT EXISTS `idx_aics_requests_ai_gate_status` (`ai_gate_status`);
CREATE TABLE IF NOT EXISTS `aics_ai_gate_events` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 5: Run the test (PASS), `tests/database-schema-regression.php` (PASS), and the existing tests that cover `aics_ai_request_progress` (`grep -l aics_ai_request_progress tests/*.php`) — no new failures.**

- [ ] **Step 6: Commit** — `git add includes/ai-submission-gate.php includes/document-ai-reviews.php database/AICSdb.sql tests/ai-submission-gate-test.php` / `git commit -m "feat: AICS AI gate decision rules and schema"`

### Task 2: Gate sync — drive the AI, apply decisions, log, notify; scheduler and worker trigger

**Files:**
- Modify: `includes/ai-submission-gate.php`
- Create: `tools/ai-gate-sync.php`, `ai_processor/gate_trigger.py`
- Modify: `ai_processor/config.py`, `ai_processor/processor.py` (`__main__`), `Dockerfile` (ENV), `deploy/start.sh` (schema + scheduler loop)
- Test: `tests/ai-submission-gate-db-test.php`, `ai_processor/tests/test_gate_trigger.py`

**Interfaces:**
- Consumes: Task 1 functions; from `includes/document-ai-reviews.php`: `aics_ensure_current_ai_reviews_for_request()` (stall recovery, missing rows, request lease; returns `worker_needed`, `worker_lease_token`, `queued`), `aics_retry_ai_review()`, `aics_launch_document_ai_processor()`, `aics_mark_ai_review_failed()`; `aics_ai_verdict_view(array $findings, string $status)` (`includes/ai-verdict-view.php`, keys `headline`, `reason`); `aics_smart_priority_for_request()` (Task 3 changes its rules, not its signature).
- Produces:
  - `aics_ai_gate_sync(mysqli $connection, ?int $requestId = null): array{released:int, held:int, admin_review:int, dispatched:int, retried:int}`
  - `aics_ai_gate_launch(string $workerArgument, int $id, string $leaseToken = ''): bool` — calls `aics_launch_document_ai_processor()`; when `AICS_HTTP_TEST_MODE=1` and `AICS_AI_GATE_TEST_LAUNCH` is `ok`/`fail`, returns that result without starting Python and appends `[$workerArgument, $id]` to `$GLOBALS['aics_ai_gate_test_launches']`.
  - `aics_ai_gate_busy_workers(mysqli $connection): int` — distinct requests with a lease younger than the stall time **or** a claimed (`attempt_count > 0`) `processing` review started within the stall time.
  - `aics_ai_gate_priority_indicator(mysqli $connection, int $requestId): string` (`URGENT|HIGH|STANDARD`)
  - `aics_ai_gate_log_event(mysqli $connection, int $requestId, string $eventType, ?string $documentType, ?string $verdictStatus, string $detail, ?int $actorAccountId): void`
  - `aics_ai_gate_purge_events(mysqli $connection): int`
  - `aics_ai_gate_note_documents_changed(mysqli $connection, int $requestId, ?int $actorAccountId, string $eventType): void` (`applicant_reupload|applicant_edit`; always logs; moves `held` → `pending`)
  - `aics_ai_gate_admin_release(mysqli $connection, int $requestId, int $adminAccountId): bool` (any non-released state)
  - `aics_ai_gate_admin_request_reupload(mysqli $connection, int $requestId, int $adminAccountId): bool` (`admin_review` → `held`, `ai_check_hold` notice listing the `uncertain` docs)
  - `aics_ai_gate_admin_retry(mysqli $connection, ?int $requestId, int $adminAccountId): int` — resets `attempt_count = 0` on `failed` reviews of the request (or of every `admin_review` request with reason `ai_unavailable` when `$requestId` is null), moves those requests to `pending`, runs `aics_ai_gate_sync()` for each, returns the number of requests retried.
  - `aics_ai_gate_is_released(mysqli $connection, int $requestId): bool`
  - Event types: `ai_hold`, `ai_release`, `ai_retry`, `ai_admin_review`, `applicant_reupload`, `applicant_edit`, `admin_release`, `admin_reupload_request`, `admin_retry`.
  - Python: `trigger_gate_sync(php_executable: str, project_root: str, timeout_seconds: int = 120) -> bool`

- [ ] **Step 1: Write the failing DB test** `tests/ai-submission-gate-db-test.php`. Guard exactly like `tests/applicant-signup-birthdate-db-regression.php` (isolated DB + `AICS_HTTP_TEST_MODE=1`), create the DB with `aics_http_regression_create_database()`, seed with `aics_http_regression_require_seed_data()`, insert fixture requests with one `med_cert` document and a `document_ai_reviews` row whose `status` / `attempt_count` / `processing_started_at` you set per test; `putenv('AICS_AI_GATE_TEST_LAUNCH=ok')` unless stated. Tests:
  - `testHoldLogsAndNotifies`: `needs_reupload` → `held=1`; `ai_gate_status='held'`, `ai_gate_reason='blocked'`; one `ai_hold` event with `verdict_status='needs_reupload'`; one `ai_check_hold` notification.
  - `testPassReleasesWithEta`: `likely_valid`, non-medical → `released=1`; `ai_gate_priority='STANDARD'`, `ai_gate_eta_days=7`; `ai_release` event; `ai_check_passed` notification.
  - `testQueuedReviewIsDispatched`: queued review (`processing`, `attempt_count=0`) → `dispatched=1`, launches contain `['--request-id', <id>]`, state stays `pending`.
  - `testFailedReviewIsRetried`: `failed`, `attempt_count=1` → `retried=1`, review now `processing` with `attempt_count=2`, launches contain `['--document-id', <document id>]`, one `ai_retry` event.
  - `testStalledReviewIsRetried`: `processing`, `attempt_count=1`, started 31 minutes ago → after one sync the review was recovered and retried (`attempt_count=2`, `processing`), state `pending`.
  - `testLaunchFailureEndsInAdminReview`: `AICS_AI_GATE_TEST_LAUNCH=fail`, queued review → run sync until the state stops changing (max 5 runs) → review `failed` with `attempt_count=3`, request `admin_review` / `ai_unavailable`, one `ai_admin_review` event, one `ai_check_admin_review` notification, never `released`.
  - `testUncertainGoesToAdmin`: `needs_staff_review` → `admin_review` / `uncertain`; not released.
  - `testWorkerCapLimitsDispatch`: `AICS_AI_MAX_WORKERS=2`, one fresh lease row for another request, five pending requests with queued reviews → `dispatched=1`.
  - `testSecondSyncIsNoop`: run `sync` twice after `testHoldLogsAndNotifies` → second returns `held=0`; event and notification counts unchanged.
  - `testReuploadReturnsToPending`: held request, `note_documents_changed(..., 'applicant_reupload')` → `pending` + `applicant_reupload` event; set review to `likely_valid`; `sync` → released. On a released request it logs the event and stays `released`.
  - `testAdminActions`: `admin_review` request → `admin_request_reupload()` true, `held`, `admin_reupload_request` event, `ai_check_hold` notice containing `needs a clearer copy`; another → `admin_release()` true, released, `admin_release` event with actor id, `ai_check_admin_release` notification; `admin_release()` on a released request returns false; `ai_unavailable` request → `admin_retry(null, …)` returns 1, review `attempt_count` back to 1 after the retry, `admin_retry` event.
  - `testPurge`: event with `created_at` 8 days ago is deleted by `purge_events()`, one 6 days old is kept.

- [ ] **Step 2: Run it (with the isolated-DB env from `tests/run-http-regressions.ps1`) — expect undefined-function failures.**

- [ ] **Step 3: Implement the DB functions.** `sync`: ensure schema; purge; `$slots = max(0, AICS_AI_MAX_WORKERS - busy_workers())`; select `pending` requests (or the one id) ordered by `COALESCE(ai_gate_updated_at, created_at)` ascending, limit `AICS_AI_GATE_SYNC_BATCH`. For each:
  1. `aics_ensure_current_ai_reviews_for_request()` (recovers stalls, creates missing rows). If it returns `worker_needed`: when `$slots > 0`, `aics_ai_gate_launch('--request-id', $id, $lease)` and `$slots--`; otherwise, or when the launch fails, delete that lease row (same statement as `Pswdo/php_files/ai-ensure-request-current.php`). A failed launch also marks its `queued` reviews failed with `aics_mark_ai_review_failed($c, $reviewId, 'AI_PROCESSOR_LAUNCH_FAILED', 'AICS AI could not be started.')`.
  2. `decide(aics_ai_request_progress())`. For `ai_failed`, each `retry` doc while `$slots > 0`: `aics_retry_ai_review()` then `aics_ai_gate_launch('--document-id', $documentId)` (on failure `aics_mark_ai_review_failed`), `ai_retry` event, `$slots--`.
  3. Apply `held` / `admin_review` / `released` only with `UPDATE aics_requests SET ai_gate_status=?, ai_gate_reason=?, ai_gate_updated_at=NOW()[, ai_gate_priority=?, ai_gate_eta_days=?] WHERE id=? AND ai_gate_status=?` and act only when `affected_rows === 1`. A `pending` request whose reason changed only updates `ai_gate_reason` (no event).

  On hold: one `ai_hold` event per blocking document with detail `"{headline}: {reason}"` from `aics_ai_verdict_view()` of that review's `findings`, cut to 500 chars. On admin review: one `ai_admin_review` event per `uncertain` document (detail from the verdict view, or `last_error_message` for failed rows). On release: indicator from `aics_ai_gate_priority_indicator()` (categories from `client_categories_json`, assistance code from `assistance_types_json`, then `aics_smart_priority_for_request()`; non-medical uses `aics_priority_from_beneficiary_categories()` mapped to HIGH/STANDARD). Notifications insert into `aics_request_notifications (request_id, notification_stage, message_text, sent_at)`. This module stays free of `ADMIN/` dependencies (the CLI tool loads it); admin audit-log entries are written by the admin page (Task 6).

- [ ] **Step 4: Create `tools/ai-gate-sync.php`** (CLI only: exit 1 unless `PHP_SAPI === 'cli'`; `--schema-only` runs `aics_ai_gate_ensure_schema()` and exits; optional `--request-id N`; prints `released=N held=N admin_review=N dispatched=N retried=N`). In `deploy/start.sh`, after the migration bundle and before `exec apache2-foreground`: run `php "$APP/tools/ai-gate-sync.php" --schema-only`, then start the scheduler `( while true; do php "$APP/tools/ai-gate-sync.php" >/dev/null 2>&1; sleep 60; done ) &`.

- [ ] **Step 5: Worker trigger.** Write `ai_processor/tests/test_gate_trigger.py` first (mock `subprocess.run`): empty `php_executable` → returns False without calling run; success → called with `[php, <project_root>/tools/ai-gate-sync.php]`, `timeout=120`, returns True; `TimeoutExpired` / non-zero exit → returns False and logs. Then implement `trigger_gate_sync` in `ai_processor/gate_trigger.py`, add `php_executable: str = os.getenv('AICS_PHP_EXECUTABLE', '')` to `Settings` in `config.py`, and call `trigger_gate_sync(SETTINGS.php_executable, SETTINGS.project_root)` in `processor.py`'s `__main__` after `process_request(...)` and after `process_one(...)` (best effort; never changes the exit code). Running a full sync here makes the next queued request start as soon as a worker frees up. Add `AICS_PHP_EXECUTABLE=/usr/local/bin/php` to the Dockerfile `ENV` block.

- [ ] **Step 6: Run** the DB test (all PASS), `python -m unittest ai_processor.tests.test_gate_trigger -q` (OK), `python -m unittest discover -s ai_processor\tests -p "test_*.py" -q` (no new failures), and `sh -n deploy/start.sh` in Git Bash (no output).

- [ ] **Step 7: Commit** — `feat: AICS AI gate sync drives checks to completion, logs and notifies`

### Task 3: Urgency analysis for every Medical request

**Files:**
- Modify: `includes/smart-priority-medical-analysis.php` (`aics_smart_priority_qualifies`, `aics_smart_priority_assess`, `aics_smart_priority_for_request`, prompt, fingerprint revision)
- Modify: `Pswdo/php_files/pswdo-common.php` (`pswdo_request_management_indicator`)
- Test: update `tests/smart-priority-medical-analysis-test.php`, `tests/smart-priority-regression.php`, `tests/pswdo-request-management-indicators-test.php`, `tests/pswdo-screening-priority-indicator-test.php`

**Interfaces:**
- Produces: unchanged signatures; new rules — Medical always qualifies; baseline `HIGH` for senior citizen/PWD, `STANDARD` otherwise; analysis `urgent=true` → `URGENT`.

- [ ] **Step 1: Update tests first:** non-senior/PWD Medical with `urgent=true` → `URGENT`; with `urgent=false` → `STANDARD`; senior Medical with `urgent=false` → `HIGH` (unchanged); Burial → `STANDARD` without calling the analyzer; Request Management shows `URGENT` for an urgent non-senior Medical request; the prompt contains the sentence below.
- [ ] **Step 2: Run them — expect failures on the new expectations.**
- [ ] **Step 3: Implement.** `qualifies()` returns true for Medical; `assess()` and `for_request()` use the category baseline wherever they now return `HIGH` as the no-urgency / unavailable result. Add this sentence to `aics_smart_priority_gemini_prompt()` after the "Consider admission, surgery…" sentence: `A scheduled or required operation or surgery for the current condition is sufficient for urgent=true; a purely elective or cosmetic procedure is not.` Change the fingerprint `revision` to `priority-v4-all-medical-operations`.
- [ ] **Step 4: Run the four tests — PASS.**
- [ ] **Step 5: Commit** — `feat: Smart Priority urgency for all medical requests, operations count as urgent`

### Task 4: PSWDO only sees released requests

**Files:**
- Modify: `Pswdo/php_files/pswdo-data-lib.php` — `pswdo_fetch_dashboard_metrics`, `pswdo_fetch_latest_request_date`, `pswdo_fetch_period_metrics`, `pswdo_fetch_period_trend`, `pswdo_fetch_request_queue`, `pswdo_fetch_prescreening_counts`, `pswdo_fetch_prescreening_queue`, `pswdo_count_requests_by_status_values`, `pswdo_count_document_review_queue`, `pswdo_fetch_workflow_lane_requests`, `pswdo_fetch_report_summary`, `pswdo_fetch_ai_operational_metrics`, `pswdo_fetch_top_priority_cases`
- Modify: `includes/geo-analytics.php` (both `aics_requests` queries)
- Modify: `Pswdo/php_files/overview-dashboard.php`, `request-management.php`, `prescreening-queue.php` (call `aics_ai_gate_sync($connection)` right after the connection is opened)
- Modify: `Pswdo/php_files/workflow-action.php`, `Pswdo/php_files/ai-ensure-request-current.php`, `Pswdo/php_files/ai-ensure-current.php` (guard)
- Test: `tests/pswdo-ai-gate-visibility-test.php`

**Interfaces:**
- Consumes: `aics_ai_gate_visible_sql()`, `aics_ai_gate_sync()`, `aics_ai_gate_is_released()`.

- [ ] **Step 1: Write the failing test.** Contract part: for each function name above, extract its body from `pswdo-data-lib.php` and assert it contains `aics_ai_gate_visible_sql(`; assert `geo-analytics.php` contains it twice; assert the three guarded files call `aics_ai_gate_is_released(`. DB part (isolated DB): one request each with `released`, `held`, `admin_review`, `pending`, plus a legacy row inserted before the column existed (create it, then run `aics_ai_gate_ensure_schema()`) → `pswdo_fetch_request_queue()` and `pswdo_fetch_prescreening_queue()` return only the released and legacy rows (`legacy rows remain visible`); `pswdo_fetch_dashboard_metrics()` counts exclude the other three.
- [ ] **Step 2: Run it — FAIL.**
- [ ] **Step 3: Add `AND ' . aics_ai_gate_visible_sql('r') . '` (alias as used in each query) to every `aics_requests` query in the listed functions and in `geo-analytics.php`; add the three sync calls; in the three guarded files, refuse non-released requests before any action — `workflow-action.php` with its existing error redirect and message `This request is still being checked by AICS AI.`, the two JSON endpoints with HTTP 404 and their existing "not found" JSON.**
- [ ] **Step 4: Run it — PASS; run all `tests/pswdo-*.php` — no new failures (two known pre-existing failures: `pswdo-release-mswdo-municipality-regression`, `pswdo-tamper-analysis-test`).**
- [ ] **Step 5: Commit** — `feat: PSWDO only sees requests that passed the AICS AI check`

### Task 5: Applicant side — start the check, log changes, status page feedback

**Files:**
- Modify: `includes/document-ai-reviews.php` (`aics_queue_ai_reviews_after_commit`)
- Modify: `Applicants/php_files/save-request.php` (edit mode passes `'applicant_edit'`)
- Modify: `Applicants/php_files/dashboard-data.php`, `application-status.php`, `overview-dashboard.php` (call `aics_ai_gate_sync($connection, $requestId)` for the account's latest request before rendering/returning data)
- Modify: `Applicants/php_files/application-status.php` (banner + ETA line), `Applicants/css files/applicant-pages.css` (banner styles, phone block)
- Test: `tests/applicant-ai-gate-feedback-test.php`

**Interfaces:**
- Consumes: `aics_ai_gate_note_documents_changed()`, `aics_ai_gate_sync()`, `aics_ai_gate_applicant_message()`, columns `ai_gate_status`, `ai_gate_eta_days`; `aics_ai_request_progress()` + `aics_ai_gate_decide()` for the blocking list.

- [ ] **Step 1: Write the failing test.** Contract: `aics_queue_ai_reviews_after_commit` calls `aics_ai_gate_note_documents_changed(` and `aics_ai_gate_sync(`; `save-request.php` passes `'applicant_edit'` in edit mode; the three pages call `aics_ai_gate_sync(`; `application-status.php` renders `Expected processing time: about`, `AICS AI is checking your documents.`, `Your documents are being reviewed before they go to PSWDO.` and a `ai-gate-hold-banner` element listing each blocking document label. DB (isolated, `AICS_AI_GATE_TEST_LAUNCH=ok`): `aics_queue_ai_reviews_after_commit()` on a held request → `pending` + `applicant_reupload` event + one launch recorded; on a new request with no verifiable documents → `released`.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** Add `string $gateEventType = 'applicant_reupload'` to `aics_queue_ai_reviews_after_commit($connection, $requestId)`; after queueing call `aics_ai_gate_note_documents_changed($connection, $requestId, $_SESSION['aics_account_id'] ?? null, $gateEventType)` then `aics_ai_gate_sync($connection, $requestId)` (this starts the check — today nothing starts it until PSWDO opens the request), all inside the existing try/catch so a gate error never fails the applicant's save. Status page: held → red banner `.ai-gate-hold-banner` with the hold message items and a link to Manage Applications; pending → `AICS AI is checking your documents.`; admin review → `Your documents are being reviewed before they go to PSWDO.`; released with `ai_gate_eta_days` → `Expected processing time: about {days} day(s)`. Phone rules go inside the existing `@media (max-width: 640px)` phone block.
- [ ] **Step 4: Run — PASS; run all `tests/applicant-*.php` — no new failures.**
- [ ] **Step 5: Commit** — `feat: applicants see AICS AI holds and expected processing time`

### Task 6: Admin "AICS AI Screening" page with alerts

**Files:**
- Create: `ADMIN/phpfiles/ai-screening.php`
- Modify: `ADMIN/dashboard.php` (route `'ai' => 'ai-screening.php'`), `ADMIN/phpfiles/admin-layout.php` (nav item `'ai' => ['icon' => 'fa-robot', 'label' => 'AICS AI Screening', …]` with a count badge from `aics_ai_gate_attention_count()` when > 0), `ADMIN/cssfiles/admin-pages.css`
- Modify: `includes/ai-submission-gate.php` — add `aics_ai_gate_recent_events(mysqli $connection): array` (events joined with `reference_no`, newest first, grouped by request) and `aics_ai_gate_attention_count(mysqli $connection): int` (`admin_review` requests + `pending` requests waiting longer than `AICS_AI_GATE_ALERT_MINUTES`)
- Test: `tests/admin-ai-screening-test.php`

**Interfaces:**
- Consumes: Task 2 admin functions, `aics_ai_gate_sync()`, `aics_ai_gate_purge_events()`, `aics_ai_environment_health()` (`status` ∈ `available|unavailable`, `components`); from `ADMIN/phpfiles/`: `auth-guard.php` (required first, as in `security-logs.php`), `admin_csrf_input()`, `admin_require_valid_csrf()`, `admin_log_event()`.

- [ ] **Step 1: Write the failing test.** Contract: route and nav item exist; page requires `auth-guard.php`, renders `admin_csrf_input()` in every action form and calls `admin_require_valid_csrf()` on POST; page calls `aics_ai_gate_purge_events(`, `aics_ai_environment_health(` and `admin_log_event(`; the page source contains `Release to PSWDO`, `Ask applicant to re-upload`, `Retry AICS AI check`, `Retry all`. DB (isolated): `aics_ai_gate_recent_events()` returns events ≤ 7 days old grouped by request with the current `ai_gate_status`; `aics_ai_gate_attention_count()` counts one `admin_review` request and one `pending` request 31 minutes old, but not a `pending` request 5 minutes old.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement the page** using `admin-layout.php` like `security-logs.php`: run `aics_ai_gate_sync()` then `aics_ai_gate_purge_events()` on load. Sections, top to bottom:
  1. **Alert bar** (only when there is something to say): AI service unavailable (from `aics_ai_environment_health()`, listing unavailable components); `{n} request(s) waiting longer than {AICS_AI_GATE_ALERT_MINUTES} minutes`; `{n} request(s) need your decision`.
  2. **Needs your decision**: one card per `admin_review` request (reference, reason `uncertain` → "AICS AI could not confirm a document", `ai_unavailable` → "AICS AI could not check a document", the uncertain documents with their findings) with **Release to PSWDO**, **Ask applicant to re-upload** and, for `ai_unavailable`, **Retry AICS AI check**; a **Retry all** button when any `ai_unavailable` requests exist.
  3. **Waiting for AICS AI**: `pending` requests with minutes waiting.
  4. **7-day log**: one card per request (reference, current state, each event with time, type label, document label, verdict, detail). Header note: `Records older than 7 days are deleted automatically.`

  Each action: POST + `admin_require_valid_csrf()` → the Task 2 function → on success `admin_log_event()` with event `ai_gate_admin_release` / `ai_gate_admin_reupload_request` / `ai_gate_admin_retry` → redirect with flash.
- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Phone check:** render the page with a 500-character detail at 360 px using the overflow inspector approach from the 2026-10-19 mobile work (snapshot HTML via an admin session; admin pages deny framing). Expected: `scrollWidth == viewport`.
- [ ] **Step 6: Commit** — `feat: admin AICS AI Screening page with alerts, decisions and 7-day log`

### Task 7: Docs and end-to-end check

**Files:**
- Modify: `Readme.md` (Configuration: `AICS_AI_GATE_ALERT_MINUTES`, `AICS_AI_MAX_WORKERS`, `AICS_ETA_*_DAYS`, `AICS_PHP_EXECUTABLE`; local note: run `php tools/ai-gate-sync.php` in a loop or rely on page loads; feature summary for PSWDO/Admin/Applicant rows), `ai_processor/.env.example` (`AICS_PHP_EXECUTABLE=`)

- [ ] **Step 1: Update the docs.**
- [ ] **Step 2: Manual end-to-end on local XAMPP:** submit a request with a deliberately blurred medical certificate → the check starts without opening PSWDO; applicant sees the hold banner and notification; PSWDO Request Management does not list it; admin page shows the hold with findings. Re-upload a clear file → after the worker finishes, PSWDO lists it and the applicant sees `Expected processing time`. Repeat with the worker broken (`AICS_PYTHON_EXECUTABLE` pointed at a missing file) → after the retries the request is in the admin page's "Needs your decision" with the AI-unavailable alert and never appears in PSWDO; fix the setting and press **Retry all** → it is checked and released.
- [ ] **Step 3: Run all suites:** `tests/applicant-*.php`, `tests/pswdo-*.php`, `tests/ai-*.php`, `tests/admin-*.php`, `python -m unittest discover -s ai_processor\tests -p "test_*.py" -q` — no new failures.
- [ ] **Step 4: Railway check after deploy:** the deploy log shows the schema step; the admin page shows no "AI service unavailable" alert; submit one test request on the live site and confirm it reaches PSWDO only after the check passes.
- [ ] **Step 5: Commit** — `docs: AICS AI submission gate configuration`
