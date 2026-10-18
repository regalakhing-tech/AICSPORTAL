# AICS Chatbot Response Coverage and UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand AICS question coverage and render Groq answers as safe, organized blue service guidance with responsive numbered process arrows.

**Architecture:** Extend the pure PHP classifier and system prompt with official AICS service categories and common application intents while retaining deterministic off-topic refusal. Replace plain `textContent` bubbles with a dependency-free allowlisted structured renderer that creates only headings, paragraphs, emphasis, lists, and callouts through DOM APIs. CSS turns ordered lists into a responsive vertical blue process flow.

**Tech Stack:** PHP 8.0, vanilla JavaScript, CSS3, XAMPP Apache, Groq Chat Completions API, `openai/gpt-oss-20b`

## Global Constraints

- Preserve the exact local off-topic refusal and refuse unrelated topics before Groq.
- Keep API credentials outside `htdocs`, JavaScript, Git, test fixtures, and responses.
- Use current official DSWD AICS guidance; never guarantee eligibility, approval, exact amounts, or release dates.
- Render structured answers without `innerHTML`, external Markdown packages, raw HTML, scripts, links, images, tables, or code execution.
- Use only the existing AICS blue accent family, navy/slate text, and white/pale-blue surfaces.
- Preserve the eight-message history, 800-character user limit, privacy notice, keyboard controls, and reduced-motion behavior.
- Preserve unrelated dirty-tree changes.

---

### Task 1: Expand AICS Scope and Answer Guidance

**Files:**
- Modify: `tests/chatbot-scope-test.php`
- Modify: `chatbot/aics-chatbot-lib.php`

**Interfaces:**
- Consumes: `aics_chatbot_is_aics_topic(string $message, array $history = []): bool`
- Produces: broader AICS topic recognition and an expanded `aics_chatbot_system_prompt(): string`

- [ ] **Step 1: Add failing coverage tests**

Add accepted questions for transportation, education, food, cash relief, psychosocial support, guarantee letters, dialysis, laboratory requests, representatives, frequency, application rejection, login, password, SMS, and Filipino process questions. Add prompt assertions for every assistance category, the direct-answer formatting contract, numbered process steps, the no-guarantee rule, and PSWDO status guidance.

```php
$expandedAicsQuestions = [
    'Can AICS help with transportation back to my province?',
    'What documents are needed for educational assistance?',
    'How often can I request food assistance?',
    'Can I get cash relief after losing my job?',
    'Does AICS provide psychosocial support or referrals?',
    'How does a guarantee letter work?',
    'Requirements for dialysis and laboratory procedures',
    'Can a representative process assistance for a patient?',
    'What can I do if my application was not approved?',
    'I forgot my applicant password',
    'When will I receive an SMS update?',
    'Paano gumagana ang proseso ng AICS?',
];
```

- [ ] **Step 2: Run RED**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'tests\chatbot-scope-test.php'
```

Expected: FAIL on newly added categories such as educational assistance, password help, or psychosocial support.

- [ ] **Step 3: Expand the allowlist and system reference**

Add stable domain terms and intents for every tested category. Expand the prompt with:

```text
Supported assistance: medical, funeral/burial, transportation, educational,
food, material, cash relief or other support, psychosocial support, and referrals.

Response format:
- Start with a direct answer.
- Use ## headings only when useful.
- Use numbered lists for processes, one action per step.
- Use bullets for requirements.
- End process guidance with "Next step:" followed by one concrete action.
```

Include typical-document guidance from the official FAQ but qualify every list as general guidance subject to current assessment and PSWDO confirmation. Increase `max_completion_tokens` from `450` to `650`.

- [ ] **Step 4: Run GREEN**

Run the scope and endpoint tests. Expected output:

```text
AICS chatbot scope tests passed.
AICS chatbot endpoint tests passed.
```

### Task 2: Safe Structured Answer Renderer

**Files:**
- Modify: `tests/chatbot-ui-smoke.php`
- Modify: `chatbot/chatbot.js`

**Interfaces:**
- Produces: `appendAicsInlineFormatting(parent: HTMLElement, text: string): void`
- Produces: `renderAicsStructuredContent(container: HTMLElement, content: string): void`
- Consumes: Groq plain-text responses containing the allowlisted Markdown subset

- [ ] **Step 1: Add failing UI safety and structure assertions**

Require the JavaScript to contain both renderer functions, create `strong`, `ol`, `ul`, heading, paragraph, and callout elements, continue using `textContent`, and contain no `.innerHTML`, Markdown CDN, or bearer credential.

```php
chatbot_ui_assert(str_contains($js, 'function renderAicsStructuredContent'), 'Structured renderer');
chatbot_ui_assert(str_contains($js, "createElement('ol')"), 'Ordered process list');
chatbot_ui_assert(str_contains($js, "createElement('strong')"), 'Safe strong emphasis');
chatbot_ui_assert(!str_contains($js, 'innerHTML'), 'No raw HTML rendering');
```

- [ ] **Step 2: Run RED**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'tests\chatbot-ui-smoke.php'
```

Expected: FAIL because the structured renderer does not exist.

- [ ] **Step 3: Implement the allowlisted DOM renderer**

`appendAicsInlineFormatting()` splits only `**bold**` spans and appends either a `strong` element or a text node using `textContent`. `renderAicsStructuredContent()` normalizes line endings, buffers paragraphs, and emits only:

```javascript
document.createElement('h3');
document.createElement('p');
document.createElement('ul');
document.createElement('ol');
document.createElement('li');
document.createElement('strong');
document.createElement('div'); // allowlisted callout shell
```

Recognize `##`/`###`, unordered items, ordered items, and `Important:`, `Note:`, or `Next step:`. Unsupported syntax remains text. Change `renderMessage()` so assistant messages call `renderAicsStructuredContent(bubble, content)` while user and error messages continue assigning `bubble.textContent = content`.

- [ ] **Step 4: Run GREEN**

Expected: `AICS chatbot UI smoke test passed.`

### Task 3: Blue Process-Flow Visual System and Verification

**Files:**
- Modify: `chatbot/chatbot.css`
- Modify: `Readme.md`
- Verify: `landingpage.html`

**Interfaces:**
- Consumes: `.aics-chatbot-richtext`, `.aics-chatbot-process`, and `.aics-chatbot-callout` classes emitted by Task 2
- Produces: responsive blue response hierarchy and step arrows

- [ ] **Step 1: Add failing CSS assertions**

Extend `tests/chatbot-ui-smoke.php` to require selectors for rich-text headings, paragraphs, strong text, lists, process markers/arrows, callouts, and the mobile breakpoint.

```php
chatbot_ui_assert(str_contains($css, '.aics-chatbot-process'), 'Process flow styles');
chatbot_ui_assert(str_contains($css, "content: '↓'"), 'Process arrow');
chatbot_ui_assert(str_contains($css, '.aics-chatbot-callout'), 'Callout styles');
```

- [ ] **Step 2: Run RED**

Expected: FAIL on the first missing process selector.

- [ ] **Step 3: Implement visual hierarchy**

Make assistant bubbles `94%` wide with a slim blue left accent. Style response headings blue, paragraphs with `1.6` line height, unordered markers blue, and strong text navy. Style ordered lists with CSS counters: a blue numbered circle, a blue down-arrow connector between list items, and natural wrapping. Use a pale-blue `Next step` callout. Keep user bubbles compact at `80%`, and set the panel to `min(420px, calc(100vw - 32px))` while preserving the full-width mobile sheet.

- [ ] **Step 4: Run complete verification**

Run PHP lint; the four chatbot tests; the six existing database/auth/dashboard regressions; Apache HTTP 200 checks; a live Groq AICS process question; and a local off-topic refusal. Require the workspace key scan to remain zero.

- [ ] **Step 5: Inspect desktop and mobile screenshots**

Open `landingpage.html#ask-aics`, ask `How does AICS work?`, and capture desktop and mobile states. Verify blue headings, numbered arrows, no visible Markdown markers, no horizontal clipping, readable controls, and a clean scrollable response.
