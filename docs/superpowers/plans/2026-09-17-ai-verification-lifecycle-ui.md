# AI Verification Lifecycle and Live UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Start advisory document verification immediately after upload and show durable, live blue scanning progress in the PSWDO request table.

**Architecture:** Extend the existing `document_ai_reviews` record with explicit processing-stage metadata while retaining the current terminal status values as advisory outcomes. Upload routes dispatch the existing request-scoped worker only after commit; a new authenticated request-status endpoint feeds one poller per active request row, while the existing document endpoint continues serving modal-level details.

**Tech Stack:** PHP 8+, MySQL/MariaDB, vanilla JavaScript, CSS, Python 3.10+, PyMySQL, existing XAMPP detached-worker launcher

**Spec:** `docs/superpowers/specs/2026-09-17-ai-document-verification-design.md`

## Global Constraints

- AI output remains advisory; automated processing must never approve, reject, or change PSWDO document-review state.
- Processing stays local and must not send applicant documents or OCR content to an external service.
- A successful upload remains successful if worker launch or processing fails.
- Existing terminal AI findings must not be silently reset by page views or ordinary queue checks.
- Browser responses and logs must not expose absolute paths, OCR text, commands, or stack traces.
- Preserve all existing user changes in the dirty worktree and stage only files named by each task.

## File Structure

- `includes/document-ai-reviews.php`: idempotent schema migration, queue/lease orchestration, request progress projection.
- `database/AICSdb.sql`: canonical fresh-install schema for lifecycle columns.
- `Applicants/php_files/save-request.php`: post-commit dispatch for create/edit submissions.
- `Applicants/php_files/documents-upload.php`: post-commit dispatch for document-page uploads and single re-uploads.
- `ai_processor/database.py`: atomic claim, stage/heartbeat writes, terminal cleanup.
- `ai_processor/processor.py`: ordered stage transitions around existing processing operations.
- `Pswdo/php_files/ai-request-status.php`: authenticated read-only request aggregate endpoint.
- `Pswdo/php_files/pswdo-data-lib.php`: initial server-rendered request progress projection.
- `Pswdo/php_files/request-management.php`: live-status endpoint wiring and accessible row markup.
- `Pswdo/js files/pswdo-pages.js`: request-level polling, backoff, visibility pause, DOM updates.
- `Pswdo/css files/pswdo-pages.css`: compact blue scanning state, progress bar, responsive behavior.
- `tests/ai-review-lifecycle-schema-test.php`: schema and projection integration tests.
- `tests/ai-submission-lifecycle-contract-test.php`: upload dispatch contract tests.
- `tests/pswdo-ai-request-status-test.php`: endpoint data-contract integration tests.
- `tests/pswdo-ai-live-table-ui-contract-test.php`: markup, script, and style contract tests.
- `ai_processor/tests/test_processing_stages.py`: worker stage/heartbeat unit tests.

---

### Task 1: Persist Explicit Verification Stages and Request Progress

**Files:**
- Modify: `includes/document-ai-reviews.php:6-215`
- Modify: `database/AICSdb.sql:186-214`
- Create: `tests/ai-review-lifecycle-schema-test.php`

**Interfaces:**
- Produces: `aics_ai_processing_stages(): array<int,string>`
- Produces: `aics_ai_stage_progress(string $stage): int`
- Produces: `aics_ai_request_progress(mysqli $connection, int $requestId): array{request_id:int,total:int,queued:int,active:int,terminal:int,attention:int,failed:int,progress_percent:int,state:string,label:string,documents:array<int,array<string,mixed>>,updated_at:?string}`
- Consumes: existing `aics_ensure_ai_review_schema()` and canonical terminal statuses.

- [ ] **Step 1: Write the failing schema/projection integration test**

Create a temporary database using the established pattern in `tests/ai-auto-ensure-test.php`. Assert that `aics_ensure_ai_review_schema()` creates `processing_stage`, `progress_percent`, `stage_updated_at`, and `worker_heartbeat_at`, then seed three documents with queued, OCR-active, and terminal review rows:

```php
$columns = $connection->query("SHOW COLUMNS FROM document_ai_reviews")->fetch_all(MYSQLI_ASSOC);
$names = array_column($columns, 'Field');
foreach (['processing_stage', 'progress_percent', 'stage_updated_at', 'worker_heartbeat_at'] as $column) {
    lifecycle_schema_assert(in_array($column, $names, true), "Missing lifecycle column: $column");
}

$progress = aics_ai_request_progress($connection, 41);
lifecycle_schema_assert($progress['total'] === 3, 'Incorrect verifiable total.');
lifecycle_schema_assert($progress['queued'] === 1 && $progress['active'] === 1 && $progress['terminal'] === 1, 'Lifecycle counts are incorrect.');
lifecycle_schema_assert($progress['progress_percent'] === 33, 'Terminal progress must be request based.');
lifecycle_schema_assert($progress['state'] === 'scanning' && $progress['label'] === 'Scanning 1 of 3', 'Scanning label is incorrect.');
```

- [ ] **Step 2: Run the test and verify the missing columns fail**

Run: `php tests/ai-review-lifecycle-schema-test.php`

Expected: FAIL with `Missing lifecycle column: processing_stage`.

- [ ] **Step 3: Add idempotent lifecycle columns and helpers**

Add these columns both to the `CREATE TABLE` statement and guarded `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` calls:

```sql
processing_stage VARCHAR(30) NOT NULL DEFAULT 'queued',
progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
stage_updated_at DATETIME DEFAULT NULL,
worker_heartbeat_at DATETIME DEFAULT NULL,
KEY idx_document_ai_reviews_stage (processing_stage)
```

Define a fixed stage-to-progress map:

```php
function aics_ai_processing_stages(): array
{
    return ['queued', 'preprocessing', 'ocr', 'verifying', 'tamper_scan', 'completed', 'failed'];
}

function aics_ai_stage_progress(string $stage): int
{
    return ['queued' => 0, 'preprocessing' => 10, 'ocr' => 35, 'verifying' => 65,
        'tamper_scan' => 85, 'completed' => 100, 'failed' => 100][$stage] ?? 0;
}
```

Implement `aics_ai_request_progress()` with one prepared query joining request documents to reviews. Count only `aics_document_type_is_verifiable()` rows; classify `status='processing' AND processing_stage='queued'` as queued, other processing rows as active, and current terminal statuses as terminal. Calculate progress as terminal/total so it cannot move backward when an active job changes stages. Return safe per-document labels, not errors or extracted content.

- [ ] **Step 4: Run schema and existing ensure tests**

Run: `php tests/ai-review-lifecycle-schema-test.php`

Expected: PASS with `AI review lifecycle schema test passed.`

Run: `php tests/ai-auto-ensure-test.php`

Expected: PASS with `AI automatic ensure test passed.`

- [ ] **Step 5: Commit the lifecycle schema**

```powershell
git add -- includes/document-ai-reviews.php database/AICSdb.sql tests/ai-review-lifecycle-schema-test.php
git commit -m "feat: persist AI verification lifecycle stages"
```

---

### Task 2: Dispatch Verification Immediately After Upload Commit

**Files:**
- Modify: `Applicants/php_files/save-request.php:1-20,252-282,381-406`
- Modify: `Applicants/php_files/documents-upload.php:1-10,40-93,145-179`
- Modify: `tests/ai-submission-lifecycle-contract-test.php:1-30`
- Create: `tests/ai-upload-dispatch-integration-test.php`

**Interfaces:**
- Consumes: `aics_dispatch_unclaimed_ai_reviews_for_request(mysqli $connection, int $requestId): array{dispatched:int,failed:int}`.
- Produces: every committed supported upload has an active or terminal `document_ai_reviews` row without requiring PSWDO View.

- [ ] **Step 1: Reverse the old lifecycle contract and add post-commit assertions**

Replace assertions that prohibit applicant-side dispatch with assertions that require one dispatch after each commit path and never before it:

```php
lifecycle_assert(str_contains($submission, 'aics_dispatch_unclaimed_ai_reviews_for_request($connection, $requestId)'), 'Submission does not dispatch committed documents.');
lifecycle_assert(str_contains($uploads, 'aics_dispatch_unclaimed_ai_reviews_for_request($connection, $requestId)'), 'Document upload does not dispatch committed documents.');
$commitAt = strpos($submission, '$connection->commit();');
$dispatchAt = strpos($submission, 'aics_dispatch_unclaimed_ai_reviews_for_request($connection, $requestId)', $commitAt ?: 0);
lifecycle_assert($commitAt !== false && $dispatchAt !== false && $dispatchAt > $commitAt, 'Submission dispatch must occur after commit.');
```

In the integration test, seed a request and document, invoke the queue helper after commit, and assert exactly one review row is created even when called twice. Replace the launcher with a test seam or set a pre-existing request lease so the test does not launch a process.

- [ ] **Step 2: Run the lifecycle tests and verify they fail on the old behavior**

Run: `php tests/ai-submission-lifecycle-contract-test.php`

Expected: FAIL with `Submission does not dispatch committed documents.`

- [ ] **Step 3: Wire post-commit dispatch into both upload routes**

Require `includes/document-ai-reviews.php` from `save-request.php`. Keep the database connection open after commit, release the PHP session lock, call the dispatcher through a non-throwing post-commit wrapper, then close the connection:

```php
$connection->commit();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    $dispatch = aics_dispatch_unclaimed_ai_reviews_for_request($connection, $requestId);
} catch (Throwable $dispatchError) {
    error_log('Post-upload AI dispatch failed: category=' . $dispatchError::class);
    $dispatch = ['dispatched' => 0, 'failed' => 0];
}
$connection->close();
```

Use the same order in full upload and single re-upload branches of `documents-upload.php`. The integration test must force the dispatcher wrapper to throw after commit and assert the request/document rows and stored file remain intact and the response still reports upload success. Do not convert `$dispatch['failed'] > 0` into an upload error; the helper already persists retryable failure state. Change the success copy to `Documents uploaded successfully. Verification has started.` for new uploads and `Document re-uploaded. Verification has started.` for replacements.

- [ ] **Step 4: Run upload lifecycle regression tests**

Run: `php tests/ai-submission-lifecycle-contract-test.php`

Expected: PASS.

Run: `php tests/ai-upload-dispatch-integration-test.php`

Expected: PASS with one review row and no duplicate worker ownership.

Run: `php tests/applicant-submission-auth-regression.php`

Expected: PASS.

- [ ] **Step 5: Commit post-upload dispatch**

```powershell
git add -- Applicants/php_files/save-request.php Applicants/php_files/documents-upload.php tests/ai-submission-lifecycle-contract-test.php tests/ai-upload-dispatch-integration-test.php
git commit -m "feat: start document verification after upload"
```

---

### Task 3: Report Worker Stage, Heartbeat, and Safe Terminal State

**Files:**
- Modify: `ai_processor/database.py:1-96`
- Modify: `ai_processor/processor.py:48-127`
- Create: `ai_processor/tests/test_processing_stages.py`

**Interfaces:**
- Produces: `update_stage(connection, review_id: int, stage: str, progress: int) -> None`.
- Produces: `finish()` sets `processing_stage='completed'`, `progress_percent=100`, and clears heartbeat.
- Produces: `fail()` sets `processing_stage='failed'`, `progress_percent=100`, and clears heartbeat.
- Consumes: lifecycle columns from Task 1.

- [ ] **Step 1: Write failing unit tests for ordered stage writes**

Mock the database and processing collaborators, then assert stage calls surround the existing operations:

```python
@patch('ai_processor.processor.update_stage')
def test_process_one_reports_ordered_stages(self, update_stage):
    process_one(document_id=17)
    self.assertEqual(
        [item.args[2] for item in update_stage.call_args_list],
        ['preprocessing', 'ocr', 'verifying', 'tamper_scan'],
    )

def test_update_stage_rejects_unknown_stage(self):
    with self.assertRaises(ValueError):
        update_stage(self.connection, 7, 'invented', 50)
```

Also assert `finish()` and `fail()` write terminal stage/progress and do not store exception text.

- [ ] **Step 2: Run tests and verify the missing function fails**

Run: `python -m unittest ai_processor.tests.test_processing_stages -v`

Expected: FAIL because `update_stage` is not defined.

- [ ] **Step 3: Implement atomic stage updates**

Add a constant map and validated write:

```python
STAGE_PROGRESS = {'queued': 0, 'preprocessing': 10, 'ocr': 35,
                  'verifying': 65, 'tamper_scan': 85}

def update_stage(connection, review_id: int, stage: str, progress: int) -> None:
    if stage not in STAGE_PROGRESS or progress != STAGE_PROGRESS[stage]:
        raise ValueError('Invalid AI processing stage.')
    with connection.cursor() as cur:
        cur.execute(
            "UPDATE document_ai_reviews SET processing_stage=%s, progress_percent=%s, "
            "stage_updated_at=NOW(), worker_heartbeat_at=NOW() WHERE id=%s AND status='processing'",
            (stage, progress, review_id),
        )
    connection.commit()
```

Call it before file loading, OCR, verification, and DocTamper. Update `finish()` and `fail()` terminal writes. When claiming a job, atomically set `processing_stage='preprocessing'`, `progress_percent=10`, and heartbeat along with `attempt_count`.

- [ ] **Step 4: Run worker tests**

Run: `python -m unittest ai_processor.tests.test_processing_stages ai_processor.tests.test_request_batch -v`

Expected: PASS.

Run: `python -m unittest discover -s ai_processor/tests -p "test_*.py"`

Expected: all worker tests PASS.

- [ ] **Step 5: Commit worker lifecycle reporting**

```powershell
git add -- ai_processor/database.py ai_processor/processor.py ai_processor/tests/test_processing_stages.py
git commit -m "feat: report AI worker progress stages"
```

---

### Task 4: Add the Authenticated Request-Level Status Endpoint

**Files:**
- Create: `Pswdo/php_files/ai-request-status.php`
- Create: `tests/pswdo-ai-request-status-test.php`
- Modify: `Pswdo/php_files/request-management.php:97`

**Interfaces:**
- Endpoint: `GET ai-request-status.php?request_id=<positive integer>`.
- Produces JSON: `{ok, request_id, total, queued, active, terminal, attention, failed, progress_percent, state, label, updated_at, documents}`.
- Consumes: `aics_ai_request_progress()` from Task 1 and existing PSWDO authentication guard.

- [ ] **Step 1: Write a failing endpoint contract test**

Follow the isolated HTTP/database pattern used by existing AI endpoint tests. Seed one queued, one `ocr`, one `needs_staff_review`, and one failed document. Assert HTTP 200 and this safe shape:

```php
status_assert($payload['ok'] === true, 'Status endpoint did not succeed.');
status_assert($payload['label'] === 'Scanning 2 of 4', 'Live label is incorrect.');
status_assert($payload['attention'] === 1 && $payload['failed'] === 1, 'Terminal outcomes were not counted.');
foreach ($payload['documents'] as $document) {
    status_assert(!array_key_exists('file_path', $document), 'Endpoint leaked a file path.');
    status_assert(!array_key_exists('extracted_fields', $document), 'Endpoint leaked extracted identity data.');
}
```

Add negative cases for missing authentication and invalid request ID.

- [ ] **Step 2: Run the endpoint test and verify the file-not-found failure**

Run: `php tests/pswdo-ai-request-status-test.php`

Expected: FAIL because `ai-request-status.php` does not exist.

- [ ] **Step 3: Implement the read-only endpoint**

Use `auth-guard.php`, return `Cache-Control: no-store, private`, accept GET only, validate a positive request ID, verify that the request exists, and serialize `aics_ai_request_progress()`. Catch exceptions, log only the exception class/category, and return `Unable to refresh verification progress.` with HTTP 500.

Add this body attribute:

```php
data-ai-request-status-url="<?= pswdo_h($paths['php_files_base_url'] . '/ai-request-status.php') ?>"
```

- [ ] **Step 4: Run endpoint and security tests**

Run: `php tests/pswdo-ai-request-status-test.php`

Expected: PASS.

Run: `php tests/pswdo-ai-artifact-security-test.php`

Expected: PASS.

- [ ] **Step 5: Commit the request status API**

```powershell
git add -- Pswdo/php_files/ai-request-status.php Pswdo/php_files/request-management.php tests/pswdo-ai-request-status-test.php
git commit -m "feat: expose safe request verification progress"
```

---

### Task 5: Render Compact Initial Request-Level Verification State

**Files:**
- Modify: `Pswdo/php_files/pswdo-data-lib.php:697-818`
- Modify: `Pswdo/php_files/request-management.php:211-249`
- Create: `tests/pswdo-ai-live-table-ui-contract-test.php`

**Interfaces:**
- Produces request fields: `ai_total`, `ai_queued_total`, `ai_active_total`, `ai_terminal_total`, `ai_progress_percent`, `ai_request_state`, `ai_request_label`.
- Produces row attributes: `data-ai-request-row`, `data-request-id`, `data-ai-state`.
- Consumes: current review status/stage columns from Tasks 1 and 3.

- [ ] **Step 1: Write failing server-rendered UI assertions**

```php
ui_assert(str_contains($page, 'data-ai-request-row'), 'Request rows are not live-update targets.');
ui_assert(str_contains($page, 'request-ai-progress'), 'Request progress bar is missing.');
ui_assert(str_contains($page, 'aria-live="polite"'), 'Scanning state is not announced accessibly.');
ui_assert(str_contains($page, 'Scanning <?= (int) $aiTerminal ?> of <?= (int) $aiTotal ?>'), 'Scanning copy is not compact.');
ui_assert(!str_contains($page, 'AI Verification Pending'), 'Legacy vague state remains in the table.');
```

- [ ] **Step 2: Run the UI contract and verify it fails**

Run: `php tests/pswdo-ai-live-table-ui-contract-test.php`

Expected: FAIL with `Request rows are not live-update targets.`

- [ ] **Step 3: Add request progress fields and accessible markup**

Extend the document query to include `air.processing_stage`, `air.progress_percent`, and `air.stage_updated_at`. Aggregate request-level counts without changing staff review counters. Render the Documents cell as:

```php
<div class="request-ai-state" aria-live="polite" aria-atomic="true">
  <span class="request-ai-label"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><?= pswdo_h($aiLabel) ?></span>
  <span class="request-ai-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $aiProgress ?>">
    <span style="--ai-progress: <?= $aiProgress ?>%"></span>
  </span>
</div>
```

Show the spinner and bar only for `queued` or `scanning`. Use `AI results ready`, `N need review`, `Re-upload needed`, `Scan unavailable`, and `No verifiable files` for terminal/empty states.

- [ ] **Step 4: Run data and markup contracts**

Run: `php tests/pswdo-ai-live-table-ui-contract-test.php`

Expected: PASS.

Run: `php tests/pswdo-request-management-refinement-test.php`

Expected: PASS after replacing its obsolete label expectations with the new compact labels.

- [ ] **Step 5: Commit initial live row markup**

```powershell
git add -- Pswdo/php_files/pswdo-data-lib.php Pswdo/php_files/request-management.php tests/pswdo-ai-live-table-ui-contract-test.php tests/pswdo-request-management-refinement-test.php
git commit -m "feat: render request verification progress"
```

---

### Task 6: Poll Active Requests and Apply the Blue Scanning UI

**Files:**
- Modify: `Pswdo/js files/pswdo-pages.js:329-520`
- Modify: `Pswdo/css files/pswdo-pages.css:5071-5124,6174-6215`
- Modify: `tests/pswdo-ai-live-table-ui-contract-test.php`

**Interfaces:**
- Consumes: request-status JSON from Task 4 and row markup from Task 5.
- Produces: `window.AicsAiRequestPoller` with `discover()`, `refresh(requestId)`, and `stop(requestId)` for testability.

- [ ] **Step 1: Add failing script/style contract assertions**

Assert the JS uses the request endpoint, one timer per request, a hidden-tab pause, exponential failure delay capped at 30 seconds, and terminal cleanup. Assert CSS includes `.request-table tr.is-ai-scanning`, `.request-ai-progress`, a reduced-motion rule, and no rule that hides the Action column.

```php
ui_assert(str_contains($js, 'data-ai-request-status-url'), 'Request-level endpoint is not used.');
ui_assert(str_contains($js, 'document.visibilityState === "hidden"'), 'Hidden-tab polling pause is missing.');
ui_assert(str_contains($css, '.request-table tr.is-ai-scanning td'), 'Blue scanning row state is missing.');
ui_assert(str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'Reduced-motion fallback is missing.');
```

- [ ] **Step 2: Run the contract and verify it fails**

Run: `php tests/pswdo-ai-live-table-ui-contract-test.php`

Expected: FAIL with `Request-level endpoint is not used.`

- [ ] **Step 3: Implement request-level polling and DOM rendering**

Create one state object per request:

```javascript
const jobs = new Map();
const terminal = new Set(["ready", "attention", "reupload", "failed", "empty"]);

function nextDelay(failures) {
  return Math.min(30000, 2500 * (2 ** Math.min(failures, 3)));
}
```

Use recursive `setTimeout`, not `setInterval`, so requests never overlap. Stop terminal jobs, pause scheduling while hidden, and call `discover()` when visibility returns. Apply `is-ai-scanning` only to active rows, update the label and `aria-valuenow`, and remove the spinner after terminal state. Preserve the View button and its badge.

Replace the page-level health copy while active work exists with `Scanning N documents`; after all active rows finish, restore `AI ready` or the health failure label.

- [ ] **Step 4: Add compact responsive styling**

Use a pale blue `#eff8ff` row fill, `#0ea5e9` inset left accent, a 4px progress track, and a moving blue fill. Add:

```css
.request-table tr.is-ai-scanning td { background:#eff8ff; }
.request-table tr.is-ai-scanning td:first-child { box-shadow:inset 3px 0 #0ea5e9; }
.request-ai-progress > span { width:var(--ai-progress); transition:width .25s ease; }
@media (prefers-reduced-motion: reduce) {
  .request-ai-progress > span { transition:none; }
  .request-ai-label .fa-spin { animation:none; }
}
```

At narrow widths, allow the table wrapper to scroll horizontally while keeping the Action column width and button visible.

- [ ] **Step 5: Run UI and syntax regression tests**

Run: `php tests/pswdo-ai-live-table-ui-contract-test.php`

Expected: PASS.

Run: `node --check "Pswdo/js files/pswdo-pages.js"`

Expected: no output and exit code 0.

Run: `php tests/pswdo-request-management-refinement-test.php`

Expected: PASS.

- [ ] **Step 6: Commit the live scanning experience**

```powershell
git add -- "Pswdo/js files/pswdo-pages.js" "Pswdo/css files/pswdo-pages.css" tests/pswdo-ai-live-table-ui-contract-test.php
git commit -m "feat: show live AI scanning rows"
```

---

### Task 7: Verify Lifecycle Failure Recovery End to End

**Files:**
- Modify: `tests/ai-backfill-recovery-test.php`
- Modify: `tests/ai-request-ensure-test.php`
- Modify: `tests/ai-health-test.php`
- Modify: `ai_processor/README.md:1-20`

**Interfaces:**
- Verifies all lifecycle interfaces from Tasks 1-6.
- Documents local runtime behavior and safe recovery commands.

- [ ] **Step 1: Add stale-stage and launch-failure regression cases**

Seed an active review with an expired heartbeat and assert ensure/recovery makes it terminal `failed`, `processing_stage='failed'`, and `progress_percent=100`. Seed an unclaimed queued row with a current lease and assert a second ensure does not launch another worker. Confirm staff review columns and request status do not change.

- [ ] **Step 2: Run focused recovery tests**

Run: `php tests/ai-backfill-recovery-test.php`

Expected: PASS.

Run: `php tests/ai-request-ensure-test.php`

Expected: PASS.

Run: `php tests/ai-health-test.php`

Expected: PASS.

- [ ] **Step 3: Update runtime documentation**

Document that upload routes queue work after commit, list lifecycle stages, explain that launch failure does not invalidate the upload, and provide the existing retry UI as the supported recovery mechanism. Remove the stale statement that PSWDO View is the normal processing trigger.

- [ ] **Step 4: Run the complete lifecycle verification set**

Run:

```powershell
php tests/ai-review-lifecycle-schema-test.php
php tests/ai-submission-lifecycle-contract-test.php
php tests/ai-upload-dispatch-integration-test.php
php tests/ai-request-ensure-test.php
php tests/ai-backfill-recovery-test.php
php tests/pswdo-ai-request-status-test.php
php tests/pswdo-ai-live-table-ui-contract-test.php
python -m unittest ai_processor.tests.test_processing_stages ai_processor.tests.test_request_batch -v
node --check "Pswdo/js files/pswdo-pages.js"
```

Expected: every command exits 0; no request remains `processing` after the seeded timeout case.

- [ ] **Step 5: Commit recovery coverage and documentation**

```powershell
git add -- tests/ai-backfill-recovery-test.php tests/ai-request-ensure-test.php tests/ai-health-test.php ai_processor/README.md
git commit -m "test: cover AI verification lifecycle recovery"
```
