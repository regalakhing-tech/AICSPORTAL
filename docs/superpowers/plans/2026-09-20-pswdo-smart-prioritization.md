# PSWDO Document-Based Smart Prioritization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Order PSWDO work by document-verified medical urgency first, Senior Citizen/PWD vulnerability second, and oldest application third.

**Architecture:** Centralize the auditable precedence table in the existing beneficiary-priority policy module. Reuse it through PSWDO display helpers and one generated SQL `CASE` so Request Management, stored ranks, and Screening Queue cannot use conflicting priority rules.

**Tech Stack:** PHP 8, MySQL/MariaDB, Python 3 document extraction, existing procedural PSWDO pages and CLI regression tests

**Spec:** `docs/superpowers/specs/2026-09-20-pswdo-smart-prioritization-design.md`

## Global Constraints

- `URGENT` requires reliable explicit timing evidence extracted from a medical certificate or billing/prescription document.
- Diagnosis, assistance type, applicant statement, or category alone must not create medical urgency.
- Senior Citizen and PWD produce base priority `HIGH`; all other beneficiary categories produce `NORMAL`.
- Priority only orders staff work and never makes an approval, denial, or release decision.
- Unknown or unreliable medical evidence remains `REQUIRES_STAFF_REVIEW` and receives no automatic urgent promotion.
- Inside one tier, order oldest application first and request ID ascending.

---

### Task 1: Canonical Smart Priority Policy and Labels

**Files:**
- Modify: `tests/smart-priority-regression.php`
- Modify: `includes/beneficiary-priority.php`
- Modify: `Pswdo/php_files/pswdo-common.php`

**Interfaces:**
- Produces: `aics_smart_priority_tier(string $basePriority, string $medicalUrgency): int`
- Produces: `aics_smart_priority_label(string $basePriority, string $medicalUrgency): string`
- Produces: `pswdo_priority_badge_meta(string $priorityLevel, string $medicalUrgency = ''): array`
- Consumes: existing `aics_priority_from_beneficiary_categories()` and document-derived urgency states

- [ ] **Step 1: Write failing precedence and label tests**

Add literal assertions that prove `NORMAL + URGENT` outranks `HIGH + STANDARD`, `HIGH + URGENT` wins the urgent tie, review-needed states remain after verified standard states, and the PSWDO label/badge describes the effective priority rather than category alone.

- [ ] **Step 2: Run the regression test and verify RED**

Run: `C:\xampp\php\php.exe tests/smart-priority-regression.php`

Expected: FAIL because `aics_smart_priority_tier()` and `aics_smart_priority_label()` do not exist.

- [ ] **Step 3: Implement the minimal canonical policy**

Normalize historical `High Priority ...` strings as `HIGH`; normalize only `URGENT` and `STANDARD` as verified states; map everything else to `REQUIRES_STAFF_REVIEW`. Implement the six tiers in the exact order from the design spec and use them to return concise staff-facing labels.

- [ ] **Step 4: Update PSWDO badge metadata to recognize urgent non-Senior/PWD cases**

Keep the optional second parameter backward compatible. Return the strong/high badge for verified urgency or high base category, and the warning/review treatment for unknown evidence.

- [ ] **Step 5: Run the regression test and verify GREEN**

Run: `C:\xampp\php\php.exe tests/smart-priority-regression.php`

Expected: `Smart priority category, precedence, and safeguard checks passed.`

### Task 2: Use One Ordering Rule Across PSWDO Queues

**Files:**
- Modify: `tests/pswdo-request-ordering-contract-test.php`
- Modify: `Pswdo/php_files/pswdo-data-lib.php`

**Interfaces:**
- Consumes: `aics_smart_priority_tier()` policy order from Task 1
- Produces: `pswdo_smart_priority_order_case_sql(string $alias = 'pr'): string`
- Produces: deterministic Request Management, stored-rank, and Screening Queue ordering

- [ ] **Step 1: Replace the old newest-first contract with failing behavioral ordering cases**

Use literal fixtures representing urgent surgery, Senior/PWD standard medicine, ordinary standard assistance, and review-needed evidence. Assert urgency-first, category tie-break, and oldest-first ordering through the shared PHP comparator/sort key.

- [ ] **Step 2: Run ordering tests and verify RED**

Run: `C:\xampp\php\php.exe tests/pswdo-request-ordering-contract-test.php`

Expected: FAIL because Request Management still orders newest first and the shared ordering helper is missing.

- [ ] **Step 3: Add the SQL ordering helper and wire all consumers**

Generate a normalized SQL `CASE` for the six tiers. Use it in `pswdo_recalculate_priority_ranks()`, the Screening Queue default order, and Request Management after its priority refresh. Use `COALESCE(NULLIF(date_applied, '0000-00-00'), DATE(created_at)) ASC, id ASC` for ties.

- [ ] **Step 4: Correct the Smart Priority filter semantics**

Treat a row as a priority case when `urgency_indicator = 'URGENT'` or base priority is high. Do not require both and do not exclude urgent applicants in other beneficiary categories.

- [ ] **Step 5: Run ordering and syntax tests and verify GREEN**

Run:

```powershell
C:\xampp\php\php.exe tests/pswdo-request-ordering-contract-test.php
C:\xampp\php\php.exe -l Pswdo/php_files/pswdo-data-lib.php
```

Expected: ordering test passes and PHP reports no syntax errors.

### Task 3: Present Smart Priority Clearly in PSWDO Screens

**Files:**
- Modify: `Pswdo/php_files/request-management.php`
- Modify: `Pswdo/php_files/prescreening-queue.php`
- Modify: `Pswdo/css files/pswdo-pages.css` only if the existing priority classes cannot represent urgent and review-needed states

**Interfaces:**
- Consumes: `aics_smart_priority_label()` and updated badge metadata from Task 1
- Produces: consistent row/detail labels and Smart Priority filter copy

- [ ] **Step 1: Add failing UI contract assertions to the existing Smart Priority regression**

Assert literal staff-facing labels for urgent medical plus Senior/PWD, urgent medical only, Senior/PWD standard priority, standard, and review-needed cases.

- [ ] **Step 2: Run the regression and verify RED**

Run: `C:\xampp\php\php.exe tests/smart-priority-regression.php`

Expected: FAIL until the pages call the effective-priority label and badge interfaces.

- [ ] **Step 3: Update Request Management and Screening Queue rendering**

Pass both base priority and urgency to the label and badge helpers. Rename the visible sort to `Smart Priority` and the group action to `Priority Cases Only`; keep existing query parameter keys for bookmark compatibility.

- [ ] **Step 4: Run focused and adjacent regression checks**

Run:

```powershell
C:\xampp\php\php.exe tests/smart-priority-regression.php
C:\xampp\php\php.exe tests/pswdo-request-ordering-contract-test.php
C:\xampp\php\php.exe tests/pswdo-request-management-refinement-test.php
C:\xampp\php\php.exe tests/pswdo-screening-decision-contract-test.php
C:\xampp\php\php.exe -l Pswdo/php_files/request-management.php
C:\xampp\php\php.exe -l Pswdo/php_files/prescreening-queue.php
```

Expected: all scripts exit 0 and both pages have no PHP syntax errors.

### Task 4: Verify Document Evidence and Full Feature

**Files:**
- Verify: `ai_processor/field_extractor.py`
- Verify: `ai_processor/tests/test_core.py`
- Verify: all modified PHP files

**Interfaces:**
- Consumes: explicit timing extraction already implemented for `med_cert` and `billing_statement`
- Produces: evidence that document extraction and PSWDO ordering work together without diagnosis-based urgency

- [ ] **Step 1: Run medical extraction tests**

Run: `python -m unittest ai_processor.tests.test_core`

Expected: explicit scheduled surgery produces `urgency_evidence`; diagnosis alone remains `not_present`; full module passes.

- [ ] **Step 2: Run all focused PHP tests and syntax checks again**

Run the Task 3 PHP suite plus syntax checks for `includes/beneficiary-priority.php`, `Pswdo/php_files/pswdo-common.php`, and `Pswdo/php_files/pswdo-data-lib.php`.

- [ ] **Step 3: Review the diff against every acceptance criterion**

Confirm that all three PSWDO ordering consumers use the shared rule, the filter includes both urgent and vulnerable cases, no applicant self-report is used, and no unrelated user changes were modified.

