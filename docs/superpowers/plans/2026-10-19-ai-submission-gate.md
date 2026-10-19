# AICS AI Submission Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep requests with blurred, edited or wrong documents out of every PSWDO view, log all AI findings and applicant changes for the admin for 7 days, and tell applicants either what to fix or how long processing will take.

**Architecture:** A per-request gate state (`pending` → `held` / `released`) on `aics_requests`, decided by one PHP module (`includes/ai-submission-gate.php`) from the existing per-document AI review statuses. The AI worker runs the gate through a PHP CLI tool as soon as it finishes a request; page loads run the same idempotent sync as a fallback. PSWDO queries filter on `released`; a new admin page shows the 7-day event log.

**Tech Stack:** PHP 8.2 + mysqli, MariaDB, Python 3 worker (`ai_processor`), plain-PHP test scripts in `tests/`, Python `unittest`.

**Spec:** `docs/superpowers/specs/2026-10-19-ai-submission-gate-design.md`

## Global Constraints

- Blocking AI statuses are exactly `needs_reupload` and `flagged_inconsistency`.
- Gate timeout: `AICS_AI_GATE_TIMEOUT_MINUTES`, default `30`.
- Expected processing days: URGENT `1`, HIGH `3`, STANDARD `7`; env overrides `AICS_ETA_URGENT_DAYS`, `AICS_ETA_HIGH_DAYS`, `AICS_ETA_STANDARD_DAYS`.
- Admin event records are deleted when older than 7 days; requests, documents and AI reviews are never deleted by this feature.
- Applicant and admin text says "AICS AI", never "Gemini". Applicant copy is fixed in the spec's "Applicant copy (exact)" section.
- Notification `message_text` is `VARCHAR(600)`: messages must be cut to 600 characters.
- Every schema change goes in `database/AICSdb.sql` (idempotent) **and** in `aics_ai_gate_ensure_schema()` — the Railway database is not re-imported.
- Existing rows get `ai_gate_status = 'released'`; new rows default to `'pending'`.
- New screens must have no horizontal overflow at 360 px (phone check used for the rest of the portal).
- DB tests run only against the isolated `aics_http_regression` database with `AICS_HTTP_TEST_MODE=1` (see `tests/run-http-regressions.ps1`), never `AICSdb`.

## Review Focus

1. **AI never finishes** (worker down, AI not configured on Railway): a pending request must reach PSWDO after 30 minutes with the `ai_check_skipped` message — Task 2 test `testTimeoutReleases`.
2. **Two syncs at once** (worker CLI and a PSWDO page load): exactly one event and one notification per transition — Task 2 test `testSecondSyncIsNoop`.
3. **Applicant re-uploads a held document:** the request must go back to `pending` and be re-decided on the new review, not stay held on the old one — Task 2 test `testReuploadReturnsToPending` and Task 5 test.
4. **Old requests created before this feature** must stay visible to PSWDO exactly as today — Task 4 test `legacy rows remain visible`.
5. **Admin page on a phone with long AI reasons** (500-character detail) must not overflow at 360 px — Task 6 step 6.

---

### Task 1: Gate schema and pure decision functions

**Files:**
- Create: `includes/ai-submission-gate.php`
- Modify: `database/AICSdb.sql` (after the `aics_requests` block and its ALTERs)
- Test: `tests/ai-submission-gate-test.php`

**Interfaces:**
- Produces:
  - `aics_ai_gate_ensure_schema(mysqli $connection): void`
  - `aics_ai_gate_decide(array $progress, int $pendingMinutes, int $timeoutMinutes): array{state:string, reason:string, blocking:list<array{document_type:string,status:string,review_id:int}>}` — `$progress` is the return value of `aics_ai_request_progress()` (`includes/document-ai-reviews.php`); `state` ∈ `pending|held|released`, `reason` ∈ `checking|blocked|passed|no_documents|timeout`
  - `aics_ai_gate_eta_days(string $indicator): int`
  - `aics_ai_gate_applicant_message(string $stage, string $referenceNo, array $blocking, int $etaDays, string $indicator): string` — `$stage` ∈ `ai_check_hold|ai_check_passed|ai_check_skipped|ai_check_admin_release`
  - `aics_ai_gate_visible_sql(string $alias = 'r'): string` → `"{$alias}.ai_gate_status = 'released'"`

- [ ] **Step 1: Write the failing test** `tests/ai-submission-gate-test.php` (plain PHP, `gate_assert()` helper that throws, prints `PASS` at the end):

```php
$doc = fn (string $status, bool $terminal = true, int $id = 1) => ['document_type' => 'med_cert', 'status' => $status, 'review_id' => $id, 'is_terminal' => $terminal];
$p = fn (array $docs) => ['total' => count($docs), 'terminal' => count(array_filter($docs, fn ($d) => $d['is_terminal'])), 'documents' => $docs];

gate_assert(aics_ai_gate_decide($p([]), 0, 30)['state'] === 'released', 'no verifiable documents releases');
gate_assert(aics_ai_gate_decide($p([]), 0, 30)['reason'] === 'no_documents', 'no_documents reason');
gate_assert(aics_ai_gate_decide($p([$doc('likely_valid'), $doc('needs_staff_review')]), 1, 30)['reason'] === 'passed', 'passed');
gate_assert(aics_ai_gate_decide($p([$doc('failed')]), 1, 30)['state'] === 'released', 'AI failure does not hold');
$held = aics_ai_gate_decide($p([$doc('likely_valid'), $doc('needs_reupload', true, 7), $doc('processing', false)]), 1, 30);
gate_assert($held['state'] === 'held' && $held['blocking'][0]['review_id'] === 7, 'one blocking doc holds even while others run');
gate_assert(aics_ai_gate_decide($p([$doc('flagged_inconsistency')]), 1, 30)['state'] === 'held', 'inconsistency holds');
gate_assert(aics_ai_gate_decide($p([$doc('processing', false)]), 29, 30)['state'] === 'pending', 'still checking');
gate_assert(aics_ai_gate_decide($p([$doc('processing', false)]), 30, 30)['reason'] === 'timeout', 'timeout releases at the limit');

gate_assert(aics_ai_gate_eta_days('URGENT') === 1 && aics_ai_gate_eta_days('HIGH') === 3 && aics_ai_gate_eta_days('STANDARD') === 7, 'default ETA days');
gate_assert(aics_ai_gate_eta_days('unknown') === 7, 'unknown indicator uses STANDARD');
putenv('AICS_ETA_HIGH_DAYS=2'); gate_assert(aics_ai_gate_eta_days('HIGH') === 2, 'env override'); putenv('AICS_ETA_HIGH_DAYS');

$hold = aics_ai_gate_applicant_message('ai_check_hold', 'AICS-2026-0001', [['document_type' => 'med_cert', 'status' => 'needs_reupload', 'review_id' => 1]], 0, 'STANDARD');
gate_assert($hold === 'AICS AI found a problem with 1 document(s) in request AICS-2026-0001: Medical Certificate (blurry, cut off, or incomplete). Upload a clear replacement in Manage Applications. It will be checked again automatically.', 'hold copy');
$urgent = aics_ai_gate_applicant_message('ai_check_passed', 'AICS-2026-0001', [], 1, 'URGENT');
gate_assert($urgent === 'AICS AI checked the documents for request AICS-2026-0001. PSWDO will now review it. Expected processing time: about 1 day(s). Your request was marked urgent.', 'urgent copy');
gate_assert(aics_ai_gate_applicant_message('ai_check_skipped', 'AICS-2026-0001', [], 7, 'STANDARD') === 'Request AICS-2026-0001 is now with PSWDO for review. Expected processing time: about 7 day(s).', 'skipped copy');
$many = array_fill(0, 40, ['document_type' => 'billing_statement', 'status' => 'flagged_inconsistency', 'review_id' => 1]);
gate_assert(strlen(aics_ai_gate_applicant_message('ai_check_hold', 'X', $many, 0, 'STANDARD')) <= 600, 'message fits VARCHAR(600)');
gate_assert(stripos($hold . $urgent, 'gemini') === false, 'branding');
gate_assert(aics_ai_gate_visible_sql('rq') === "rq.ai_gate_status = 'released'", 'visibility fragment');
```

Document labels come from the existing requirement labels (`aics_document_requirement_label()`, `includes/document-requirements.php`).

- [ ] **Step 2: Run it to verify it fails**

Run: `& "C:\xampp\php\php.exe" tests\ai-submission-gate-test.php`
Expected: fatal "Call to undefined function aics_ai_gate_decide()".

- [ ] **Step 3: Implement the four pure functions and `aics_ai_gate_visible_sql()` in `includes/ai-submission-gate.php`** (`declare(strict_types=1)`, requires `document-requirements.php`). Decision order: no documents → released/no_documents; any blocking status → held/blocked (list all blocking docs); all terminal → released/passed; `$pendingMinutes >= $timeoutMinutes` → released/timeout; else pending/checking. Truncate messages with `mb_strimwidth($text, 0, 600, '…')`.

- [ ] **Step 4: Add the schema** in `database/AICSdb.sql` and in `aics_ai_gate_ensure_schema()` (same statements, `IF NOT EXISTS`):

```sql
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `ai_gate_status` VARCHAR(16) NOT NULL DEFAULT 'released';
ALTER TABLE `aics_requests` ALTER COLUMN `ai_gate_status` SET DEFAULT 'pending';
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

- [ ] **Step 5: Run the test (PASS) and `tests/database-schema-regression.php` (PASS).**

- [ ] **Step 6: Commit** — `git add includes/ai-submission-gate.php database/AICSdb.sql tests/ai-submission-gate-test.php` / `git commit -m "feat: AICS AI gate decision rules and schema"`

### Task 2: Gate sync, event log, notifications and worker trigger

**Files:**
- Modify: `includes/ai-submission-gate.php`
- Create: `tools/ai-gate-sync.php`, `ai_processor/gate_trigger.py`
- Modify: `ai_processor/config.py` (setting), `ai_processor/processor.py` (`__main__` block), `Dockerfile` (ENV)
- Test: `tests/ai-submission-gate-db-test.php`, `ai_processor/tests/test_gate_trigger.py`

**Interfaces:**
- Consumes: Task 1 functions; `aics_ai_request_progress()`; `aics_ai_verdict_view(array $findings, string $status)` (`includes/ai-verdict-view.php`, keys `headline`, `reason`); `aics_smart_priority_for_request()` (Task 3 changes its rules, not its signature).
- Produces:
  - `aics_ai_gate_sync(mysqli $connection, ?int $requestId = null): array{released:int, held:int}`
  - `aics_ai_gate_priority_indicator(mysqli $connection, int $requestId): string` (`URGENT|HIGH|STANDARD`)
  - `aics_ai_gate_log_event(mysqli $connection, int $requestId, string $eventType, ?string $documentType, ?string $verdictStatus, string $detail, ?int $actorAccountId): void`
  - `aics_ai_gate_purge_events(mysqli $connection): int`
  - `aics_ai_gate_note_documents_changed(mysqli $connection, int $requestId, ?int $actorAccountId, string $eventType): void` (`applicant_reupload|applicant_edit`)
  - `aics_ai_gate_admin_release(mysqli $connection, int $requestId, int $adminAccountId): bool`
  - `aics_ai_gate_is_released(mysqli $connection, int $requestId): bool`
  - Event types: `ai_hold`, `ai_release`, `ai_timeout_release`, `applicant_reupload`, `applicant_edit`, `admin_release`.
  - Python: `trigger_gate_sync(php_executable: str, project_root: str, timeout_seconds: int = 120) -> bool`

- [ ] **Step 1: Write the failing DB test** `tests/ai-submission-gate-db-test.php`. Guard exactly like `tests/applicant-signup-birthdate-db-regression.php` (isolated DB + `AICS_HTTP_TEST_MODE=1`), create the DB with `aics_http_regression_create_database()`, seed with `aics_http_regression_require_seed_data()`, and insert fixture requests with one `med_cert` document and a `document_ai_reviews` row whose `status` you set per test. Tests:
  - `testHoldLogsAndNotifies`: review `needs_reupload` → `sync` returns `held=1`; row `ai_gate_status='held'`; one `ai_hold` event with `verdict_status='needs_reupload'`; one notification with stage `ai_check_hold`.
  - `testPassReleasesWithEta`: review `likely_valid`, non-medical request → `released=1`; `ai_gate_priority='STANDARD'`, `ai_gate_eta_days=7`; `ai_release` event; `ai_check_passed` notification.
  - `testTimeoutReleases`: review `processing`, `ai_gate_updated_at` 31 minutes ago → released; `ai_timeout_release` event; `ai_check_skipped` notification.
  - `testSecondSyncIsNoop`: run `sync` twice after `testHoldLogsAndNotifies` → second returns `held=0`, event and notification counts unchanged.
  - `testReuploadReturnsToPending`: held request, `note_documents_changed(..., 'applicant_reupload')` → `pending` + `applicant_reupload` event; set review to `likely_valid`; `sync` → released.
  - `testAdminRelease`: held request → `admin_release()` true, released, `admin_release` event with actor id, `ai_check_admin_release` notification; calling it on a released request returns false.
  - `testPurge`: event with `created_at` 8 days ago is deleted by `purge_events()`, one 6 days old is kept.

- [ ] **Step 2: Run it (with the isolated-DB env from `tests/run-http-regressions.ps1`) — expect undefined-function failures.**

- [ ] **Step 3: Implement the DB functions.** `sync`: ensure schema; purge; select `id, reference_no, ai_gate_status, ai_gate_updated_at` for `ai_gate_status IN ('pending','held')` (optionally one id); for each, `decide(aics_ai_request_progress(...), minutes since ai_gate_updated_at — or created_at when NULL, timeout)`; apply transitions only with `UPDATE aics_requests SET ai_gate_status=?, ai_gate_updated_at=NOW()[, ai_gate_priority=?, ai_gate_eta_days=?] WHERE id=? AND ai_gate_status=?` and act only when `affected_rows === 1`. On hold: one `ai_hold` event per blocking document with detail `"{headline}: {reason}"` from `aics_ai_verdict_view()` of that review's `findings`, cut to 500 chars. On release: indicator from `aics_ai_gate_priority_indicator()` (categories from `client_categories_json`, assistance code from `assistance_types_json`, then `aics_smart_priority_for_request()`; non-medical uses `aics_priority_from_beneficiary_categories()` mapped to HIGH/STANDARD). Notifications insert into `aics_request_notifications (request_id, notification_stage, message_text, sent_at)`. This module stays free of `ADMIN/` dependencies (the CLI tool loads it); the admin audit-log entry for a release is written by the admin page (Task 6).

- [ ] **Step 4: Create `tools/ai-gate-sync.php`** (CLI only: exit 1 unless `PHP_SAPI === 'cli'`; optional `--request-id N`; prints `released=N held=M`).

- [ ] **Step 5: Worker trigger.** Write `ai_processor/tests/test_gate_trigger.py` first (mock `subprocess.run`): empty `php_executable` → returns False without calling run; success → called with `[php, <project_root>/tools/ai-gate-sync.php]`, `timeout=120`, returns True; `TimeoutExpired`/non-zero exit → returns False and logs. Then implement `trigger_gate_sync` in `ai_processor/gate_trigger.py`, add `php_executable: str = os.getenv('AICS_PHP_EXECUTABLE', '')` to `Settings` in `config.py`, and call `trigger_gate_sync(SETTINGS.php_executable, SETTINGS.project_root)` in `processor.py`'s `__main__` after `process_request(...)` and after `process_one(...)` (best effort; never changes the exit code). Add `AICS_PHP_EXECUTABLE=/usr/local/bin/php` to the Dockerfile `ENV` block.

- [ ] **Step 6: Run** the DB test (all PASS), `python -m unittest ai_processor.tests.test_gate_trigger -q` (OK), and `python -m unittest discover -s ai_processor\tests -p "test_*.py" -q` (no new failures).

- [ ] **Step 7: Commit** — `feat: AICS AI gate sync, 7-day event log, applicant notices, worker trigger`

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

### Task 4: Hide pending and held requests from PSWDO

**Files:**
- Modify: `Pswdo/php_files/pswdo-data-lib.php` — `pswdo_fetch_dashboard_metrics`, `pswdo_fetch_latest_request_date`, `pswdo_fetch_period_metrics`, `pswdo_fetch_period_trend`, `pswdo_fetch_request_queue`, `pswdo_fetch_prescreening_counts`, `pswdo_fetch_prescreening_queue`, `pswdo_count_requests_by_status_values`, `pswdo_count_document_review_queue`, `pswdo_fetch_workflow_lane_requests`, `pswdo_fetch_report_summary`, `pswdo_fetch_ai_operational_metrics`, `pswdo_fetch_top_priority_cases`
- Modify: `includes/geo-analytics.php` (both `aics_requests` queries)
- Modify: `Pswdo/php_files/overview-dashboard.php`, `request-management.php`, `prescreening-queue.php` (call `aics_ai_gate_sync($connection)` right after the connection is opened)
- Modify: `Pswdo/php_files/workflow-action.php` (guard)
- Test: `tests/pswdo-ai-gate-visibility-test.php`

**Interfaces:**
- Consumes: `aics_ai_gate_visible_sql()`, `aics_ai_gate_sync()`, `aics_ai_gate_is_released()`.

- [ ] **Step 1: Write the failing test.** Contract part: for each function name above, extract its body from `pswdo-data-lib.php` and assert it contains `aics_ai_gate_visible_sql(`; assert `geo-analytics.php` contains it twice; assert `workflow-action.php` calls `aics_ai_gate_is_released(`. DB part (isolated DB): one request each with `released`, `held`, `pending`, plus a legacy row inserted without the column value set → `pswdo_fetch_request_queue()` and `pswdo_fetch_prescreening_queue()` return only the released and legacy rows (`legacy rows remain visible`); `pswdo_fetch_dashboard_metrics()` counts exclude the held/pending rows.
- [ ] **Step 2: Run it — FAIL.**
- [ ] **Step 3: Add `AND ' . aics_ai_gate_visible_sql('r') . '` (alias as used in each query) to every `aics_requests` query in the listed functions and in `geo-analytics.php`; add the three sync calls; in `workflow-action.php`, before any action, refuse when `!aics_ai_gate_is_released($connection, $requestId)` with the existing error redirect and message `This request is still being checked by AICS AI.`**
- [ ] **Step 4: Run it — PASS; run all `tests/pswdo-*.php` — no new failures (two known pre-existing failures: `pswdo-release-mswdo-municipality-regression`, `pswdo-tamper-analysis-test`).**
- [ ] **Step 5: Commit** — `feat: PSWDO only sees requests that passed the AICS AI check`

### Task 5: Applicant side — fallback sync, change logging, status page feedback

**Files:**
- Modify: `includes/document-ai-reviews.php` (`aics_queue_ai_reviews_after_commit`)
- Modify: `Applicants/php_files/dashboard-data.php`, `application-status.php`, `overview-dashboard.php` (call `aics_ai_gate_sync($connection, $requestId)` for the account's latest request before rendering/returning data)
- Modify: `Applicants/php_files/application-status.php` (banner + ETA line), `Applicants/css files/applicant-pages.css` (banner styles, phone block)
- Test: `tests/applicant-ai-gate-feedback-test.php`

**Interfaces:**
- Consumes: `aics_ai_gate_note_documents_changed()`, `aics_ai_gate_sync()`, columns `ai_gate_status`, `ai_gate_eta_days`; `aics_ai_request_progress()` for the blocking list.

- [ ] **Step 1: Write the failing test.** Contract: `aics_queue_ai_reviews_after_commit` calls `aics_ai_gate_note_documents_changed(`; the three pages call `aics_ai_gate_sync(`; `application-status.php` renders `Expected processing time: about` and a `ai-gate-hold-banner` element listing each blocking document label. DB (isolated): call `aics_queue_ai_reviews_after_commit()` on a held request → `pending` + `applicant_reupload` event; on a released request → stays `released`, no event.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** In `aics_queue_ai_reviews_after_commit($connection, $requestId)`, after queueing, call `aics_ai_gate_note_documents_changed($connection, $requestId, $_SESSION['aics_account_id'] ?? null, 'applicant_reupload')` (the function is a no-op unless the request is `held`; it records `applicant_edit` when called from `save-request.php` edit mode — pass that type there instead by adding an optional `string $gateEventType = 'applicant_reupload'` parameter to `aics_queue_ai_reviews_after_commit`). Status page: held → red banner `.ai-gate-hold-banner` with the hold message items and a link to Manage Applications; pending → `AICS AI is checking your documents.`; released with `ai_gate_eta_days` → `Expected processing time: about {days} day(s)`. Phone rules go inside the existing `@media (max-width: 640px)` phone block.
- [ ] **Step 4: Run — PASS; run all `tests/applicant-*.php` — no new failures.**
- [ ] **Step 5: Commit** — `feat: applicants see AICS AI holds and expected processing time`

### Task 6: Admin "AICS AI Screening" page

**Files:**
- Create: `ADMIN/phpfiles/ai-screening.php`
- Modify: `ADMIN/dashboard.php` (route `'ai' => 'ai-screening.php'`), `ADMIN/phpfiles/admin-layout.php` (nav item `'ai' => ['icon' => 'fa-robot', 'label' => 'AICS AI Screening', …]`), `ADMIN/cssfiles/admin-pages.css`
- Modify: `includes/ai-submission-gate.php` — add `aics_ai_gate_recent_events(mysqli $connection): array` (events joined with `reference_no`, newest first, grouped by request)
- Test: `tests/admin-ai-screening-test.php`

**Interfaces:**
- Consumes: `aics_ai_gate_sync()`, `aics_ai_gate_purge_events()`, `aics_ai_gate_admin_release()`; from `ADMIN/phpfiles/`: `auth-guard.php` (required first, as in `security-logs.php`), `admin_csrf_input()`, `admin_require_valid_csrf()`, `admin_log_event()`.

- [ ] **Step 1: Write the failing test.** Contract: route and nav item exist; page requires `auth-guard.php`, renders `admin_csrf_input()` in the release form and calls `admin_require_valid_csrf()` on POST; page calls `aics_ai_gate_purge_events(` and `admin_log_event(`. DB (isolated): `aics_ai_gate_recent_events()` returns events ≤ 7 days old grouped by request with the current `ai_gate_status`; POST release on a held request releases it.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement the page** using `admin-layout.php` like `security-logs.php`: run `aics_ai_gate_sync()` then `aics_ai_gate_purge_events()` on load; one card per request (reference, current state, each event with time, type label, document label, verdict, detail); a "Release to PSWDO" button on held requests (POST + `admin_require_valid_csrf()` → `aics_ai_gate_admin_release()` → on success `admin_log_event()` with event `ai_gate_admin_release` → redirect with flash). Header note: `Records older than 7 days are deleted automatically.`
- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Phone check:** render the page with a 500-character detail at 360 px using the overflow inspector approach from the 2026-10-19 mobile work (snapshot HTML via an admin session; admin pages deny framing). Expected: `scrollWidth == viewport`.
- [ ] **Step 6: Commit** — `feat: admin AICS AI Screening page with 7-day log and release`

### Task 7: Docs and end-to-end check

**Files:**
- Modify: `Readme.md` (Configuration: `AICS_AI_GATE_TIMEOUT_MINUTES`, `AICS_ETA_*_DAYS`, `AICS_PHP_EXECUTABLE`; feature summary for PSWDO/Admin/Applicant rows), `ai_processor/.env.example` (`AICS_PHP_EXECUTABLE=`)

- [ ] **Step 1: Update the docs.**
- [ ] **Step 2: Manual end-to-end on local XAMPP:** submit a request with a deliberately blurred medical certificate → applicant sees the hold banner and notification, PSWDO Request Management does not list it, admin page shows the hold with findings; re-upload a clear file → after the worker finishes, PSWDO lists it and the applicant sees `Expected processing time`. Repeat with AI disabled (`AICS_GEMINI_API_KEY` unset and worker stopped) → request appears in PSWDO after 30 minutes.
- [ ] **Step 3: Run all suites:** `tests/applicant-*.php`, `tests/pswdo-*.php`, `tests/ai-*.php`, `tests/admin-*.php`, `python -m unittest discover -s ai_processor\tests -p "test_*.py" -q` — no new failures.
- [ ] **Step 4: Commit** — `docs: AICS AI submission gate configuration`
