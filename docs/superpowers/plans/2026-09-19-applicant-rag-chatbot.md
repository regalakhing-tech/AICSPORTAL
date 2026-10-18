# Applicant-Scoped RAG Chatbot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a private, account-scoped applicant chatbot that explains only the logged-in user's AICS applications while preserving the public AICS-only landing-page assistant.

**Architecture:** Retrieve a bounded application snapshot through parameterized SQL constrained by the authenticated session account, answer exact status/schedule/document/cooldown questions deterministically, and use Groq only to explain the safe snapshot. Reuse the existing chatbot UI in a configurable public/applicant mode with separate browser histories and a local fallback.

**Tech Stack:** PHP 8/MySQLi, vanilla JavaScript, CSS, existing applicant sessions, Groq OpenAI-compatible chat completions, XAMPP PHP regression scripts.

**Spec:** `docs/superpowers/specs/2026-09-19-applicant-rag-chatbot-design.md`

## Global Constraints

- Never accept an account ID, request ID, system prompt, or retrieved context from the browser.
- Every private application query must join `aics_account_requests` and bind the authenticated session account ID.
- Never send files, paths, original filenames, OCR, extracted identity fields, contacts, addresses, beneficiary names, or assistance amounts to Groq.
- The landing-page assistant remains public and cannot access private records.
- Exact database facts remain authoritative; Groq is wording-only and provider failure falls back locally.
- The one-year cooldown uses `aics_application_cooldown_state()`.
- No new package, vector database, or conversation table.
- Work in the current dirty workspace because the feature depends on existing uncommitted chatbot/auth/provider work; stage or commit nothing automatically.

---

### Task 1: Account-scoped retrieval and safe snapshot

**Files:**
- Create: `chatbot/applicant-context.php`
- Create: `tests/chatbot-applicant-context-test.php`

**Interfaces:**
- Produces: `aics_chatbot_extract_reference(string): string`
- Produces: `aics_chatbot_sanitize_remark(string): string`
- Produces: `aics_chatbot_fetch_applicant_context(mysqli, int, string): array`
- Consumes: existing `aics_document_requirements()`, status helpers, and `aics_application_cooldown_state()`.

- [ ] **Step 1: Write a failing context test**

Create a real temporary MySQL fixture with two applicant accounts and requests. Assert that account A defaults to its newest request, can select its owned older reference, cannot resolve account B's reference, and receives document/release/cooldown fields without paths, filenames, OCR, contacts, names, addresses, IDs, or amounts.

- [ ] **Step 2: Run the context test and confirm RED**

Run: `C:\xampp\php\php.exe tests\chatbot-applicant-context-test.php`

Expected: FAIL because `chatbot/applicant-context.php` and its public functions do not exist.

- [ ] **Step 3: Implement bounded retrieval**

Implement reference parsing with canonical `AICS-YYYY-XXXXXX` normalization; use prepared statements with `ar.account_id = ?`; return one selected request, its requirement-state rows, five recent notifications, and cooldown data. Sanitize remarks with `strip_tags`, normalized whitespace, and a 240-character cap.

- [ ] **Step 4: Run the context test and confirm GREEN**

Run: `C:\xampp\php\php.exe tests\chatbot-applicant-context-test.php`

Expected: PASS with account isolation and privacy assertions.

### Task 2: Private assistant orchestration and local fallback

**Files:**
- Create: `chatbot/applicant-assistant-lib.php`
- Create: `tests/chatbot-applicant-assistant-test.php`
- Modify: `chatbot/aics-chatbot-lib.php`

**Interfaces:**
- Consumes: `aics_chatbot_fetch_applicant_context()` from Task 1.
- Produces: `aics_chatbot_private_system_prompt(array): string`
- Produces: `aics_chatbot_local_application_answer(string, array): string`
- Produces: `aics_chatbot_handle_applicant(array, array&, callable, ?callable): array`
- Produces: shared Groq transport accepting a server-created system prompt.

- [ ] **Step 1: Write failing orchestration tests**

Test status, missing/scheduled release, document completeness, cooldown, in-chat report, owned/unowned reference handling, English/Filipino behavior, Groq success, Groq failure fallback, malformed requests, rate limiting, prompt-injection text, and provider payload privacy. Assert literal user-visible facts rather than implementation strings.

- [ ] **Step 2: Run the assistant test and confirm RED**

Run: `C:\xampp\php\php.exe tests\chatbot-applicant-assistant-test.php`

Expected: FAIL because private orchestration functions do not exist.

- [ ] **Step 3: Implement deterministic intent answers and grounded Groq prompting**

Build local report/status/document/release/cooldown answers from the snapshot. For broader application questions, send only the safe JSON snapshot and validated chat history to Groq with fixed grounding and injection-resistance instructions. Refactor the shared transport to accept an optional server-owned prompt while preserving existing public calls.

- [ ] **Step 4: Run public and private assistant tests**

Run:

```powershell
C:\xampp\php\php.exe tests\chatbot-applicant-assistant-test.php
C:\xampp\php\php.exe tests\chatbot-endpoint-test.php
C:\xampp\php\php.exe tests\chatbot-scope-test.php
```

Expected: all PASS.

### Task 3: Authenticated endpoint, CSRF, and private Groq configuration

**Files:**
- Create: `Applicants/php_files/chat-assistant.php`
- Create: `tests/chatbot-applicant-endpoint-test.php`
- Modify: `chatbot/aics-chatbot-lib.php`

**Interfaces:**
- Consumes: applicant auth/session helpers and Task 2 orchestration.
- Produces: POST JSON endpoint returning `{message, local}` with 401/403/4xx boundaries.
- Produces: private environment key resolution from `C:\xampp\aics_private\aics-ai.env` with legacy key-file fallback.

- [ ] **Step 1: Write failing endpoint tests**

Exercise the controller seam with valid session state, absent/wrong role, invalid CSRF, invalid JSON, browser-supplied `account_id`, database failure, and a mocked transport. Assert that only the session account reaches the context loader and that secrets/provider bodies never enter responses.

- [ ] **Step 2: Run endpoint tests and confirm RED**

Run: `C:\xampp\php\php.exe tests\chatbot-applicant-endpoint-test.php`

Expected: FAIL because the endpoint controller seam does not exist.

- [ ] **Step 3: Implement the endpoint**

Use `aics_require_applicat_login()` semantics without redirects for JSON, bind session account ID, validate a constant-time CSRF token, close the session lock before database/provider work after copying required state, resolve Groq configuration server-side, and emit no-store JSON with safe errors.

- [ ] **Step 4: Run endpoint and key regressions**

Run:

```powershell
C:\xampp\php\php.exe tests\chatbot-applicant-endpoint-test.php
C:\xampp\php\php.exe tests\chatbot-local-key-test.php
powershell -ExecutionPolicy Bypass -File tests\external-ai-secret-regression.ps1
```

Expected: all PASS.

### Task 4: Applicant widget and configurable loading experience

**Files:**
- Create: `Applicants/php_files/applicant-chat-widget.php`
- Create: `tests/chatbot-applicant-ui-test.php`
- Modify: `chatbot/chatbot.js`
- Modify: `chatbot/chatbot.css`
- Modify: `Applicants/css files/applicant-pages.css`
- Modify: authenticated applicant page controllers in `Applicants/php_files/`

**Interfaces:**
- Consumes: `data-chatbot-mode`, `data-chatbot-endpoint`, `data-chatbot-csrf`, login URL, welcome copy, and prompt text emitted by the widget.
- Produces: one reusable applicant chatbot on overview, profile, submit, documents, status, manage request, and notifications pages.

- [ ] **Step 1: Write failing UI behavior tests**

Render each authenticated page fixture and assert one applicant-mode launcher/dialog, the private endpoint, CSRF bootstrap, four private prompts, distinct storage key, safe renderer, loading status sequence, disabled send behavior, Retry action, 401 login handling, responsive CSS, and absence of browser credentials.

- [ ] **Step 2: Run UI tests and confirm RED**

Run:

```powershell
C:\xampp\php\php.exe tests\chatbot-applicant-ui-test.php
C:\xampp\php\php.exe tests\chatbot-ui-smoke.php
```

Expected: private UI test FAILS while the existing public smoke test remains PASS.

- [ ] **Step 3: Implement shared applicant widget and mode-aware JavaScript**

Render the reusable widget from each authenticated page, import shared chatbot styling into applicant CSS, and make `chatbot.js` derive endpoint/storage/welcome/prompts/loading copy from server-rendered data. Preserve the public endpoint and markup behavior. Use DOM text APIs only; never render raw provider HTML.

- [ ] **Step 4: Run UI tests and confirm GREEN**

Run:

```powershell
C:\xampp\php\php.exe tests\chatbot-applicant-ui-test.php
C:\xampp\php\php.exe tests\chatbot-ui-smoke.php
```

Expected: both PASS.

### Implementation outcome (September 19, 2026)

Follow-up: applicant chat now uses Groq-generated answers with retrieved sources instead of keyword gating and section-only selection. Each message retrieves the entire owned application index plus selected details; classification and canonical requirements are included. Source IDs and application references are validated; source labels are displayed. Typo routing/privacy tests pass. The previous section-only behavior described below is superseded by this follow-up. Loading text and the My applications shortcut were updated.

- Implemented account-owned context retrieval, authenticated/CSRF-protected endpoint, private Groq configuration, deterministic factual answers and provider fallback.
- Added a shared applicant widget to all seven applicant pages, with quick questions, loading dots, timeout handling, mobile layout, and session-separated history.
- Groq selects a response section for broader application questions; the server renders current facts. Raw model prose is rejected to prevent invented application outcomes. Staff notes and previous local assistant replies are excluded from private provider requests.
- Eight `chatbot-*.php` suites pass, including database ownership tests and rendered widget integration. Modified PHP files pass syntax checks. The live synthetic-data Groq probe returned a valid structured response. Secret regression passed.
- Fixed test-mode database selection and removed precisely identified synthetic fixtures from the earlier test run.
- Browser visual inspection remains unverified; Node and Playwright are not available in this environment. Existing unrelated whitespace warnings remain in the dirty worktree. Broader Python worker tests were not rerun because this change does not modify that worker.
- Operator instructions: `chatbot/README.md`. Live diagnostic: `tools/probe-chatbot.php`.
- Applicant phone-auth regression passed and an unauthenticated HTTP request to the private chat endpoint returned 401. The separate submission HTTP regression refused to run without an isolated HTTP runtime; it was not bypassed.

### Task 5: Integrated verification and documentation

**Files:**
- Modify: `chatbot/README.md` if present, otherwise `Readme.md`
- Modify: `docs/superpowers/plans/2026-09-19-applicant-rag-chatbot.md` checklist state

**Interfaces:**
- Consumes: all prior tasks.
- Produces: verified setup and usage instructions without credentials.

- [ ] **Step 1: Run chatbot and applicant regression suite**

Run every `tests/chatbot-*.php`, relevant applicant auth/status tests, and PHP syntax checks for every created/modified PHP file using `C:\xampp\php\php.exe`.

- [ ] **Step 2: Run AI/provider and secret regressions**

Run the Python external-AI tests, live privacy-safe text probe, and secret regression. The live probe must output only provider status.

- [ ] **Step 3: Run browser/static safety checks**

Confirm no `innerHTML`, API key, bearer header, account ID override, raw file path, or OCR field is present in applicant browser assets/provider payload builders. Run `git diff --check` on the touched files.

- [ ] **Step 4: Update operator documentation**

Document that the public bot answers general AICS questions, the applicant bot uses the current login only, latest request is default, older owned references are supported, cooldown is one year, raw documents are excluded, and Groq outage uses a local answer.

- [ ] **Step 5: Record fresh verification evidence**

Capture commands, pass counts, live probe status, and any environment limitation without claiming unrun checks.
