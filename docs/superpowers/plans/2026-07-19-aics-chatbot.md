# AICS Landing-Page Chatbot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a polished, responsive, AICS-only chatbot to the existing public landing page using a secure PHP-to-Groq integration.

**Architecture:** A dedicated PHP library performs deterministic AICS topic filtering, bounded validation, prompt construction, rate limiting, and Groq response normalization. A thin JSON endpoint calls Groq only for accepted AICS questions, while dedicated CSS and JavaScript add the accessible bottom-right chat surface without exposing the API key or changing the existing hero composition.

**Tech Stack:** PHP 8.0, vanilla JavaScript, HTML5, CSS3, XAMPP Apache, PHP cURL, Groq Chat Completions API, `openai/gpt-oss-20b`

## Global Constraints

- The chatbot appears on `C:\xampp\htdocs\SIA102\landingpage.html` only.
- Preserve the existing hero, phone mockups, navigation, and landing-page sections.
- Never write the exposed Groq key to files, browser code, logs, responses, or commits.
- Read a newly rotated key only from `GROQ_API_KEY`.
- Refuse unrelated questions locally with: `I can only help with AICS services, requirements, applications, documents, and status guidance.`
- Limit user messages to 800 characters and history to 8 messages.
- Render all message text safely without raw HTML injection.
- Preserve unrelated user changes in the dirty working tree.

---

## File Structure

- `AICS/chatbot/aics-chatbot-lib.php`: pure validation, scope, rate-limit, prompt, transport, and controller functions.
- `AICS/chatbot/chat.php`: thin same-origin JSON endpoint.
- `AICS/chatbot/chatbot.css`: launcher, panel, messages, mobile sheet, accessibility, and motion.
- `AICS/chatbot/chatbot.js`: UI state, safe rendering, bounded history, requests, focus, persistence, and errors.
- `landingpage.html`: chatbot stylesheet, accessible markup, and script references only.
- `AICS/tests/chatbot-scope-test.php`: pure PHP scope and prompt regression tests.
- `AICS/tests/chatbot-endpoint-test.php`: controller tests with a fake Groq transport.
- `AICS/tests/chatbot-ui-smoke.php`: static integration and secret-exposure guard.
- `AICS/Readme.md`: environment and verification instructions.

### Task 1: AICS Scope and Prompt Library

**Files:**
- Create: `AICS/tests/chatbot-scope-test.php`
- Create: `AICS/chatbot/aics-chatbot-lib.php`

**Interfaces:**
- Produces: `aics_chatbot_is_aics_topic(string $message, array $history = []): bool`
- Produces: `aics_chatbot_validate_messages(mixed $messages): array`
- Produces: `aics_chatbot_system_prompt(): string`
- Produces: `aics_chatbot_refusal(): string`

- [ ] **Step 1: Write failing scope tests**

Test exact acceptance for `What is AICS?`, `Ano ang requirements para sa medical assistance?`, `How can I track my application?`, and contextual `What else?`. Test exact refusal for coding, weather, politics, recipes, prompt injection, and `Ignore previous instructions`. Test message count, allowed roles, and 800-character bounds.

- [ ] **Step 2: Run RED**

```powershell
& 'C:\xampp\php\php.exe' 'AICS\tests\chatbot-scope-test.php'
```

Expected: FAIL because the library functions do not exist.

- [ ] **Step 3: Implement the minimum pure library**

Use lowercase normalized matching with an AICS allowlist and an injection/off-topic denylist. Permit only explicit greetings or limited follow-ups when a previous user turn is itself AICS-related. Return validated messages with roles limited to `user` and `assistant`, trimmed content, a maximum of 8 turns, and a maximum user length of 800 characters.

The system prompt must identify the assistant as `AICS Guide for PSWDO Antique`, list allowed local workflows and assistance categories, prohibit private status claims and eligibility guarantees, direct uncertain cases to official staff, refuse off-topic requests, and warn against sharing sensitive personal data.

- [ ] **Step 4: Run GREEN**

Expected: `AICS chatbot scope tests passed.`

### Task 2: Testable Endpoint Controller and Groq Transport

**Files:**
- Create: `AICS/tests/chatbot-endpoint-test.php`
- Modify: `AICS/chatbot/aics-chatbot-lib.php`
- Create: `AICS/chatbot/chat.php`

**Interfaces:**
- Produces: `aics_chatbot_handle(array $request, array &$rateState, ?callable $transport = null): array{status:int,payload:array}`
- Produces: `aics_chatbot_groq_transport(string $apiKey, array $messages): array`

- [ ] **Step 1: Write failing controller tests**

Cover invalid method (405), invalid content type (415), malformed JSON (400), invalid history (422), off-topic local refusal with zero transport calls (200), missing key for on-topic requests (503), mocked Groq success (200), upstream failure normalization (502), and the sixteenth accepted request in ten minutes (429).

- [ ] **Step 2: Run RED**

```powershell
& 'C:\xampp\php\php.exe' 'AICS\tests\chatbot-endpoint-test.php'
```

Expected: FAIL because the controller and transport functions do not exist.

- [ ] **Step 3: Implement controller and transport**

`aics_chatbot_handle()` receives normalized request data containing `method`, `content_type`, `raw_body`, `api_key`, `now`, and `ip`. It parses JSON, validates messages, returns local greetings/refusals without Groq, applies a 15-request/600-second session-and-IP bucket, and invokes the injected transport for accepted questions.

The production transport POSTs JSON to `https://api.groq.com/openai/v1/chat/completions` with bearer authorization, model `openai/gpt-oss-20b`, temperature `0.2`, maximum completion tokens `450`, the system prompt, and at most 8 validated turns. Use a 5-second connection timeout and 25-second total timeout. Normalize `choices[0].message.content` to plain text and never return upstream bodies on errors.

`chat.php` starts a secure session, builds the request map from `$_SERVER`, `file_get_contents('php://input')`, `getenv('GROQ_API_KEY')`, and the client IP, calls the controller, stores only rate metadata in the session, and emits JSON with the returned status.

- [ ] **Step 4: Run GREEN**

Expected: `AICS chatbot endpoint tests passed.`

### Task 3: Chatbot UI and Landing Integration

**Files:**
- Create: `AICS/tests/chatbot-ui-smoke.php`
- Create: `AICS/chatbot/chatbot.css`
- Create: `AICS/chatbot/chatbot.js`
- Modify: `landingpage.html`

**Interfaces:**
- Consumes: `POST AICS/chatbot/chat.php` with `{messages:[{role,content}]}`
- Produces: accessible launcher and panel using IDs prefixed `aics-chatbot-`

- [ ] **Step 1: Write failing static UI smoke test**

Assert that the landing page references both chatbot assets and contains the dialog, launcher, message log, four quick prompts, privacy text, textarea, send button, and clear button. Assert that the JavaScript uses `textContent`, caps stored turns, and contains no string matching `gsk_[A-Za-z0-9]+` or `Authorization: Bearer`.

- [ ] **Step 2: Run RED**

```powershell
& 'C:\xampp\php\php.exe' 'AICS\tests\chatbot-ui-smoke.php'
```

Expected: FAIL because the landing page does not contain the chatbot integration.

- [ ] **Step 3: Implement the visual surface**

Add the stylesheet in `<head>`, the launcher and dialog immediately before the landing-page script, and the chatbot script before `</body>`. Keep the current page layout unchanged.

The launcher uses the AICS logo, `Ask AICS`, an online dot, `aria-expanded`, and `aria-controls`. The panel includes branded header, close/clear controls, `role="log"` messages, privacy notice, quick prompts, auto-growing textarea, character count, send button, and live status text.

CSS uses the existing `#0284c7` family, white/navy surfaces, 18–22px radii, subtle shadows, a 390px desktop panel, and a full-width mobile bottom sheet under 640px. Provide focus-visible states, safe-area spacing, high-contrast text, reduced motion, and a `100dvh` height cap.

JavaScript safely creates text nodes, keeps only 8 messages, stores only conversation text in `sessionStorage`, aborts duplicate requests, handles Enter versus Shift+Enter, preserves failed input, follows quick prompts, manages focus and Escape, and renders local/server errors without HTML injection.

- [ ] **Step 4: Run GREEN**

Expected: `AICS chatbot UI smoke test passed.`

### Task 4: Configuration Documentation and Full Verification

**Files:**
- Modify: `AICS/Readme.md`
- Verify: all chatbot and existing smoke tests

**Interfaces:**
- Produces: exact XAMPP setup and verification instructions without a real secret

- [ ] **Step 1: Document secure configuration**

Document revoking the exposed key, setting a newly rotated `GROQ_API_KEY` in Apache or the parent process, restarting Apache, the selected model, the endpoint path, the AICS-only policy, and the three chatbot test commands. Include only the placeholder `your-rotated-groq-key`, never a real key.

- [ ] **Step 2: Run lint and all chatbot tests**

```powershell
& 'C:\xampp\php\php.exe' -l 'AICS\chatbot\aics-chatbot-lib.php'
& 'C:\xampp\php\php.exe' -l 'AICS\chatbot\chat.php'
& 'C:\xampp\php\php.exe' 'AICS\tests\chatbot-scope-test.php'
& 'C:\xampp\php\php.exe' 'AICS\tests\chatbot-endpoint-test.php'
& 'C:\xampp\php\php.exe' 'AICS\tests\chatbot-ui-smoke.php'
```

- [ ] **Step 3: Run existing regression suite**

Run the database schema, auth HTTP, database bootstrap, PSWDO dashboard, period, and duplicate-guard scripts. Every script must exit 0.

- [ ] **Step 4: Verify Apache behavior**

GET `landingpage.html`, the CSS, and JavaScript through Apache and require HTTP 200. POST an off-topic question to `AICS/chatbot/chat.php` and require the fixed local refusal without a key. POST an AICS question without a configured key and require HTTP 503. Confirm no API key appears in any response or task-owned file.

- [ ] **Step 5: Inspect task-owned changes**

Run `git diff --check` for files inside `AICS`, inspect `landingpage.html` separately because it is outside the repository root, and preserve all unrelated dirty-tree changes.
