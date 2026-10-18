# Applicant-Side Responsive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every applicant-facing screen fully usable and visually consistent on desktop, tablet, and phone without changing application business behavior.

**Architecture:** Consolidate responsive behavior into the existing applicant, authentication, and chatbot stylesheets, using a shared viewport contract and minimal semantic hooks in the PHP/HTML templates. Extend the shared applicant JavaScript for accessible drawer, modal, tab, and mobile-stepper behavior, then protect the result with source-contract and browser viewport smoke tests.

**Tech Stack:** PHP 8, HTML5, CSS, vanilla JavaScript, existing Bootstrap/Font Awesome assets, PowerShell, local Chrome/CDP, XAMPP PHP CLI.

**Spec:** `docs/superpowers/specs/2026-09-20-applicant-responsive-design.md`

## Global Constraints

- Preserve all current applicant workflows, server endpoints, request payloads, database behavior, branding, and desktop information hierarchy.
- Support every width from `360px` upward, with required checks at `1440`, `1024`, `768`, `430`, `390`, and `360` CSS pixels plus `844x390` landscape.
- The document viewport must never have unintended horizontal scrolling; only explicitly labeled component scrollers may scroll horizontally.
- Use the shared tiers `1200+`, `993-1199`, `769-992`, `481-768`, and `<=480` pixels.
- Touch targets must be at least `44x44px`; touch-layout form controls must use at least `16px` text.
- Preserve keyboard operation, visible focus, semantic labels, reduced-motion behavior, and focus restoration.
- Do not add a frontend framework, CSS preprocessor, package manager, or production dependency.
- Preserve unrelated working-tree changes and review the diff of every touched file before committing.

---

### Task 1: Responsive Contract Test and Shared CSS Foundation

**Files:**
- Create: `tests/applicant-responsive-ui-contract-test.php`
- Modify: `Applicants/css files/applicant-pages.css`

**Interfaces:**
- Consumes: Existing applicant templates and stylesheet paths.
- Produces: Shared CSS tokens/classes `.app-scroll-region`, `.mobile-only`, `.desktop-only`, `.responsive-actions`, and the canonical breakpoint contract used by later tasks.

- [ ] **Step 1: Write the failing source-contract test**

Create a PHP test that loads every included applicant template and the three responsive stylesheets. Start with these exact assertions:

```php
<?php
declare(strict_types=1);

function responsive_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$pages = [
    'Applicants/login.html',
    'Applicants/blank-gis.php',
    'Applicants/php_files/overview-dashboard.php',
    'Applicants/php_files/my-profile.php',
    'Applicants/php_files/submit-request.php',
    'Applicants/php_files/documents.php',
    'Applicants/php_files/application-status.php',
    'Applicants/php_files/cancel-request.php',
    'Applicants/php_files/notifications.php',
];

foreach ($pages as $page) {
    $source = file_get_contents($root . '/' . $page);
    responsive_assert(is_string($source), "Cannot read {$page}.");
    responsive_assert(
        str_contains($source, 'name="viewport"') && str_contains($source, 'width=device-width'),
        "Missing responsive viewport in {$page}."
    );
}

$css = file_get_contents($root . '/Applicants/css files/applicant-pages.css');
responsive_assert(is_string($css), 'Cannot read applicant stylesheet.');
foreach (['--app-gutter', '--touch-target', '.app-scroll-region', '@media (max-width: 992px)', '@media (max-width: 768px)', '@media (max-width: 480px)'] as $needle) {
    responsive_assert(str_contains($css, $needle), "Missing shared responsive contract: {$needle}");
}

echo "Applicant responsive UI contract passed.\n";
```

- [ ] **Step 2: Run the contract test and verify it fails**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
```

Expected: FAIL on the first missing shared token or utility.

- [ ] **Step 3: Add the shared responsive foundation**

In `applicant-pages.css`, define the canonical tokens and non-page-specific safeguards near `:root`:

```css
:root {
  --app-gutter: clamp(16px, 2.4vw, 36px);
  --app-section-gap: clamp(16px, 2vw, 28px);
  --app-card-padding: clamp(16px, 2vw, 28px);
  --touch-target: 44px;
  --app-content-max: 1440px;
}

html { max-width: 100%; overflow-x: clip; }
body { max-width: 100%; overflow-x: hidden; }
img, svg, video, canvas, iframe { max-width: 100%; }
.main-content, .content, .page-content { min-width: 0; }
.app-scroll-region { max-width: 100%; overflow-x: auto; overscroll-behavior-inline: contain; }
.mobile-only { display: none !important; }
.responsive-actions { display: flex; flex-wrap: wrap; gap: 12px; }
```

Add narrow-tier utilities at `768px` and `480px`, including `16px` inputs, full-width action groups, safe wrapping, `scroll-margin-top`, and safe-area padding. Consolidate duplicate generic rules only when their computed behavior is identical; leave page-specific selectors with their owning page section.

- [ ] **Step 4: Run the contract test**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 5: Review CSS cascade and commit**

Run:

```powershell
git diff --check
git diff -- 'Applicants/css files/applicant-pages.css' 'tests/applicant-responsive-ui-contract-test.php'
```

Then commit only these files:

```powershell
git add -- 'Applicants/css files/applicant-pages.css' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "test: define applicant responsive contract"
```

---

### Task 2: Accessible Responsive Navigation Shell

**Files:**
- Modify: `Applicants/js files/applicant-pages.js`
- Modify: `Applicants/css files/applicant-pages.css`
- Modify: `Applicants/php_files/overview-dashboard.php`
- Modify: `Applicants/php_files/my-profile.php`
- Modify: `Applicants/php_files/submit-request.php`
- Modify: `Applicants/php_files/documents.php`
- Modify: `Applicants/php_files/application-status.php`
- Modify: `Applicants/php_files/cancel-request.php`
- Modify: `Applicants/php_files/notifications.php`
- Test: `tests/applicant-responsive-ui-contract-test.php`

**Interfaces:**
- Consumes: Existing IDs `sidebar`, `toggle-btn`, `close-btn`, `overlay`, and `main-content`.
- Produces: `openApplicantDrawer()`, `closeApplicantDrawer({ restoreFocus })`, body class `sidebar-drawer-open`, and consistent ARIA hooks on every authenticated page.

- [ ] **Step 1: Extend the failing contract assertions**

Add assertions that every authenticated page has one each of the shared shell hooks and that the JavaScript contains the two named drawer functions:

```php
$authenticated = array_slice($pages, 2);
foreach ($authenticated as $page) {
    $source = file_get_contents($root . '/' . $page);
    foreach (['id="sidebar"', 'id="toggle-btn"', 'id="close-btn"', 'id="overlay"', 'id="main-content"'] as $hook) {
        responsive_assert(substr_count($source, $hook) === 1, "Invalid {$hook} count in {$page}.");
    }
    responsive_assert(str_contains($source, 'aria-controls="sidebar"'), "Menu control is not labeled in {$page}.");
}
$js = file_get_contents($root . '/Applicants/js files/applicant-pages.js');
responsive_assert(str_contains($js, 'function openApplicantDrawer()'), 'Shared drawer opener is missing.');
responsive_assert(str_contains($js, 'function closeApplicantDrawer('), 'Shared drawer closer is missing.');
```

- [ ] **Step 2: Run the test and confirm failure**

Run the responsive contract test. Expected: FAIL on shared drawer functions or template ARIA hooks.

- [ ] **Step 3: Normalize the repeated shell markup**

On all seven authenticated templates, keep the existing IDs and add consistent attributes:

```html
<button id="toggle-btn" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation menu">
<aside id="sidebar" aria-label="Applicant navigation" aria-hidden="false">
<button id="close-btn" type="button" aria-label="Close navigation menu">
<div id="overlay" class="sidebar-overlay" aria-hidden="true"></div>
```

Do not alter route URLs, logout links, active-page classes, notification hooks, or account data.

- [ ] **Step 4: Implement one drawer controller**

Replace the overlapping open/close branches with named functions that:

- use `window.matchMedia('(max-width: 992px)')`;
- save the opener before opening;
- toggle `open`, `sidebar-drawer-open`, `aria-expanded`, `aria-hidden`, and overlay state together;
- focus the close button on open;
- close on overlay click, navigation click, and `Escape`;
- restore focus to the opener;
- close and clear body lock when crossing above `992px`.

Use this focus helper:

```js
const drawerFocusable = () => Array.from(sidebar.querySelectorAll(
  'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
)).filter((item) => !item.hidden && item.getClientRects().length > 0);
```

Trap `Tab` within the drawer only while it is open on the mobile/tablet tier.

- [ ] **Step 5: Consolidate shell CSS**

At `992px` and below, place the toggle inside the top-nav flow, make the sidebar a fixed drawer with `width: min(320px, calc(100vw - 32px))`, keep the overlay below the drawer, and set `.main-content { margin-left: 0; width: 100%; }`. At `480px`, reduce top-nav gaps and hide secondary labels before any primary control.

- [ ] **Step 6: Run tests and commit**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
C:\xampp\php\php.exe tests\applicant-profile-navigation-regression.php
git diff --check
```

Expected: both tests PASS.

Commit:

```powershell
git add -- 'Applicants/js files/applicant-pages.js' 'Applicants/css files/applicant-pages.css' 'Applicants/php_files' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "feat: make applicant navigation responsive"
```

---

### Task 3: Login and Signup Responsiveness

**Files:**
- Modify: `Applicants/login.html`
- Modify: `Applicants/css files/login.css`
- Modify: `Applicants/js files/login.js`
- Test: `tests/applicant-responsive-ui-contract-test.php`
- Test: `tests/applicant-phone-auth-regression.php`

**Interfaces:**
- Consumes: Existing `toggleAuth()` and signup step IDs.
- Produces: Stable stacked auth layout, responsive OTP action group, and focus movement between visible auth/step headings.

- [ ] **Step 1: Add failing auth layout assertions**

Assert `login.css` contains the canonical `880px`, `540px`, and `480px` auth transitions, `min-height: 100dvh`, safe-area padding, `font-size: 16px` for phone inputs, and `.signup-otp-controls` wrapping. Assert `login.js` focuses the visible form heading after auth-mode and step changes without changing submission behavior.

- [ ] **Step 2: Verify the new assertions fail**

Run the responsive contract test and expect failure on `100dvh`, safe areas, or focus behavior.

- [ ] **Step 3: Refine auth markup and styles**

Keep the two-panel desktop layout. At `880px`, stack hero/auth sections and remove card clipping. At `480px`, limit the hero to a compact header, set phone gutters to `16px`, collapse signup fields, and use:

```css
@media (max-width: 480px) {
  .auth-panel { padding-inline: max(16px, env(safe-area-inset-left)); }
  .input-wrapper input, .input-wrapper select { min-height: var(--touch-target, 44px); font-size: 16px; }
  .signup-otp-controls { display: grid; grid-template-columns: 1fr; gap: 10px; }
  .signup-otp-button, .btn-step-action { width: 100%; min-height: 44px; }
}
```

Remove only redundant mobile declarations whose replacement is equivalent. Preserve OTP cooldown, validation, password toggles, auth API requests, and existing element IDs.

- [ ] **Step 4: Add focus-safe mode and step changes**

After a form mode or signup step becomes visible, focus its heading with temporary `tabindex="-1"`; do not focus on initial page load. Preserve the currently focused invalid input during validation.

- [ ] **Step 5: Run auth and syntax checks**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
C:\xampp\php\php.exe tests\applicant-phone-auth-regression.php
node --check 'Applicants/js files/login.js'
git diff --check
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```powershell
git add -- 'Applicants/login.html' 'Applicants/css files/login.css' 'Applicants/js files/login.js' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "feat: refine applicant authentication on mobile"
```

---

### Task 4: Overview and Profile Responsive Layouts

**Files:**
- Modify: `Applicants/php_files/overview-dashboard.php`
- Modify: `Applicants/php_files/my-profile.php`
- Modify: `Applicants/css files/applicant-pages.css`
- Modify: `Applicants/js files/my-profile.js`
- Test: `tests/applicant-responsive-ui-contract-test.php`
- Test: `tests/applicant-profile-completion-regression.php`

**Interfaces:**
- Consumes: Shared shell and `.responsive-actions` from Tasks 1-2.
- Produces: Four-to-two-to-one dashboard grids, vertical phone progress, stacked profile layout, and first-invalid-field visibility.

- [ ] **Step 1: Add failing page-contract assertions**

Assert overview KPI and progress containers expose stable page-specific classes, profile form actions use `.responsive-actions`, and profile form controls retain labels/IDs. Assert CSS contains the `1024px` two-column and `640px` one-column grid states plus phone action stacking.

- [ ] **Step 2: Run the test and confirm failure**

Run the responsive contract test. Expected: FAIL on the new layout hooks.

- [ ] **Step 3: Implement overview layout behavior**

Keep the desktop hero and card hierarchy. Make hero actions wrap by tablet width, statistics use two columns at `1024px` and one at `640px`, and convert the request stepper/activity feed to a vertical reading order on phones. Ensure references and dates use `overflow-wrap: anywhere` only when natural wrapping cannot fit.

- [ ] **Step 4: Implement profile layout behavior**

Stack profile summary/edit columns at `1080px`, collapse all form spans to one column at `768px`, set phone controls to `16px`, and stack Save/Cancel actions at `480px`. In `my-profile.js`, use:

```js
const firstInvalid = form.querySelector(':invalid');
if (firstInvalid instanceof HTMLElement) {
  firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
  firstInvalid.focus({ preventScroll: true });
}
```

Call this only from the existing invalid-submit path; do not change save payloads or completion redirects.

- [ ] **Step 5: Verify and commit**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
C:\xampp\php\php.exe tests\applicant-profile-completion-regression.php
C:\xampp\php\php.exe tests\applicant-profile-navigation-regression.php
node --check 'Applicants/js files/my-profile.js'
git diff --check
```

Expected: all PASS.

Commit:

```powershell
git add -- 'Applicants/php_files/overview-dashboard.php' 'Applicants/php_files/my-profile.php' 'Applicants/css files/applicant-pages.css' 'Applicants/js files/my-profile.js' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "feat: adapt applicant overview and profile layouts"
```

---

### Task 5: Submit and Edit Request Mobile Flow

**Files:**
- Modify: `Applicants/php_files/submit-request.php`
- Modify: `Applicants/css files/applicant-pages.css`
- Modify: `Applicants/js files/applicant-pages.js`
- Test: `tests/applicant-responsive-ui-contract-test.php`
- Test: `tests/applicant-submit-ui-refinement-contract-test.php`
- Test: `tests/applicant-edit-hydration-regression.php`

**Interfaces:**
- Consumes: Existing draft, step navigation, family member, document upload, validation, and submit modal functions.
- Produces: `.mobile-step-summary`, `.family-row-card`, responsive upload actions, and mobile-safe request actions without payload changes.

- [ ] **Step 1: Add failing request-flow assertions**

Require submit markup to include a live mobile step summary and accessible family table region:

```php
responsive_assert(str_contains($submit, 'class="mobile-step-summary"'), 'Mobile step summary is missing.');
responsive_assert(str_contains($submit, 'aria-live="polite"'), 'Mobile step changes are not announced.');
responsive_assert(str_contains($submit, 'class="family-table-shell app-scroll-region"'), 'Family editor lacks a bounded fallback scroller.');
responsive_assert(str_contains($applicantJs, 'function syncMobileStepSummary('), 'Mobile step summary controller is missing.');
```

Also assert the CSS contains phone family-row cards, single-column uploads, stacked review sections, and safe modal actions.

- [ ] **Step 2: Run focused tests and verify failure**

Run the responsive and submit refinement contract tests. Expected: responsive test FAIL; existing refinement test remains PASS.

- [ ] **Step 3: Add the mobile step summary**

Add this before the existing stepper:

```html
<div class="mobile-step-summary mobile-only" aria-live="polite">
  <span class="mobile-step-count">Step 1 of 5</span>
  <strong class="mobile-step-label">Applicant and Beneficiary</strong>
  <span class="mobile-step-percent">20% complete</span>
</div>
```

Implement `syncMobileStepSummary(step)` and call it from the existing single step-rendering path. Do not create a second source of step state.

- [ ] **Step 4: Make each step content-responsive**

- Collapse form grids to one column by `768px`.
- Change five step pills into a contained horizontal scroller or expandable compact list below `640px`; keep completed back-navigation and the current-step semantics.
- Convert each generated family row into a phone row-card layout using existing inputs and remove button. Add `data-label` attributes when rows are created so every field retains its visible column meaning.
- Make assistance choices two columns on tablet and one on phone.
- Make document uploads one column by `768px`; wrap filenames and place preview/reselect/remove controls in a responsive action row.
- Stack review summary rows and modal actions on phones.
- Keep Back/Next/Save/Submit actions in document flow; if made sticky, reserve matching bottom padding and account for safe-area inset.

- [ ] **Step 5: Preserve validation and focus across responsive controls**

When navigation rejects a step, focus the first invalid control and scroll it to center. When a step changes, move focus to the step-panel heading only for keyboard/explicit navigation, not draft hydration. Preserve all existing draft-save calls, checkbox restoration, upload validation, and edit-mode hydration.

- [ ] **Step 6: Run focused and workflow regressions**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
C:\xampp\php\php.exe tests\applicant-submit-ui-refinement-contract-test.php
C:\xampp\php\php.exe tests\applicant-edit-hydration-regression.php
C:\xampp\php\php.exe tests\applicant-submission-auth-regression.php
node --check 'Applicants/js files/applicant-pages.js'
git diff --check
```

Expected: all PASS.

- [ ] **Step 7: Commit**

```powershell
git add -- 'Applicants/php_files/submit-request.php' 'Applicants/css files/applicant-pages.css' 'Applicants/js files/applicant-pages.js' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "feat: make applicant request flow mobile friendly"
```

---

### Task 6: Documents, Status, Notifications, and Request Management

**Files:**
- Modify: `Applicants/php_files/documents.php`
- Modify: `Applicants/php_files/application-status.php`
- Modify: `Applicants/php_files/notifications.php`
- Modify: `Applicants/php_files/cancel-request.php`
- Modify: `Applicants/blank-gis.php`
- Modify: `Applicants/css files/applicant-pages.css`
- Modify: `Applicants/js files/applicant-pages.js`
- Test: `tests/applicant-responsive-ui-contract-test.php`
- Test: `tests/applicant-document-reselect-ui-contract-test.php`

**Interfaces:**
- Consumes: Shared scrolling, cards, responsive actions, modal, and shell contracts.
- Produces: `.app-tab-scroller`, responsive record cards, vertical status timeline, viewport-safe document preview, and narrow-screen GIS preview wrapper.

- [ ] **Step 1: Add failing record-page assertions**

Assert filter/tab containers use `.app-tab-scroller`, dense regions expose accessible labels, preview modal has `role="dialog"` and `aria-modal="true"`, status stages retain textual labels, and notification unread state includes text or an accessible label rather than color alone.

- [ ] **Step 2: Confirm failure**

Run the responsive contract and document reselect tests. Expected: responsive test FAIL; document behavior remains PASS.

- [ ] **Step 3: Implement tabs, cards, and status timeline**

- Wrap document and notification filters in `.app-tab-scroller` with `role="region"`, a descriptive `aria-label`, `tabindex="0"`, and scroll padding.
- Add `scrollIntoView({ inline: 'nearest', block: 'nearest' })` when an active filter changes.
- Use one-column document/request cards by `768px`; wrap filename, status, metadata, and remark content.
- At `768px`, render status stages as a vertical timeline with icons, labels, and state text preserved.
- Stack release details and request edit/cancel actions in logical reading order.
- Make notification message content precede metadata and add screen-reader text for unread items.

- [ ] **Step 4: Make document previews and dialogs viewport-safe**

Give generated preview markup dialog semantics and a labeled close button. Size the card with `width: min(960px, calc(100vw - 32px))` and `max-height: calc(100dvh - 32px)`. Make its body scroll, constrain media to the remaining height, and provide a normal link to open PDFs when embedding fails.

- [ ] **Step 5: Make blank GIS browser preview narrow-safe**

Keep print CSS unchanged. Under `@media screen and (max-width: 768px)`, make the action bar wrap, reduce body padding, and wrap the fixed-format form in a labeled `.gis-preview-scroll` container. Preserve the legal-page print/PDF dimensions instead of collapsing the printable form.

- [ ] **Step 6: Run page tests and commit**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
C:\xampp\php\php.exe tests\applicant-document-reselect-ui-contract-test.php
C:\xampp\php\php.exe tests\rejected-application-cooldown-contract-test.php
C:\xampp\php\php.exe -l Applicants\blank-gis.php
node --check 'Applicants/js files/applicant-pages.js'
git diff --check
```

Expected: all PASS.

Commit:

```powershell
git add -- 'Applicants/php_files/documents.php' 'Applicants/php_files/application-status.php' 'Applicants/php_files/notifications.php' 'Applicants/php_files/cancel-request.php' 'Applicants/blank-gis.php' 'Applicants/css files/applicant-pages.css' 'Applicants/js files/applicant-pages.js' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "feat: adapt applicant records and status pages"
```

---

### Task 7: Responsive Modals, Popovers, and Applicant Chatbot

**Files:**
- Modify: `Applicants/css files/applicant-pages.css`
- Modify: `Applicants/js files/applicant-pages.js`
- Modify: `chatbot/chatbot.css`
- Modify: `chatbot/chatbot.js`
- Test: `tests/applicant-responsive-ui-contract-test.php`
- Test: `tests/chatbot-applicant-ui-test.php`
- Test: `tests/chatbot-ui-smoke.php`

**Interfaces:**
- Consumes: Existing dynamically created logout/profile/banned/preview dialogs, account/notification popovers, and chatbot widget hooks.
- Produces: `openAccessibleDialog(dialog, opener)`, `closeAccessibleDialog(dialog)`, mobile bottom-sheet rules, and keyboard-safe chatbot sizing.

- [ ] **Step 1: Add failing overlay assertions**

Require dynamically created modal cards to contain `role="dialog"`, `aria-modal="true"`, and a label reference. Require chatbot CSS to use `100dvh`, safe-area insets, and a phone breakpoint. Require chatbot JS to respond to `window.visualViewport` when available.

- [ ] **Step 2: Run responsive and chatbot tests**

Expected: responsive contract FAIL on the new accessibility/mobile requirements; existing chatbot tests remain PASS.

- [ ] **Step 3: Centralize modal focus management**

Implement helpers that save the opener, focus the first useful control, trap `Tab`, close on `Escape` where allowed, clear `modal-open`, and restore focus. Reuse them for logout, profile completion, submit confirmation, cancellation, and preview dialogs without changing their confirm actions.

- [ ] **Step 4: Apply responsive modal and popover CSS**

At `480px`, use a bottom-sheet card with rounded top corners, `max-height: calc(100dvh - 16px)`, scrollable body, and safe-area-aware stacked actions. Keep banned-account behavior non-dismissible. Constrain account and notification popovers with `inset-inline: 12px` and anchor them below the top bar on phones.

- [ ] **Step 5: Implement phone chatbot layout**

At `640px`, size the panel using fixed insets rather than a hard width/height:

```css
@media (max-width: 640px) {
  .aics-chatbot-panel {
    inset: max(8px, env(safe-area-inset-top)) max(8px, env(safe-area-inset-right)) max(8px, env(safe-area-inset-bottom)) max(8px, env(safe-area-inset-left));
    width: auto;
    height: auto;
    max-height: none;
    border-radius: 18px;
  }
}
```

Use `visualViewport.height` to set a CSS custom property while the keyboard is open, keep the message list scrollable, and keep the composer visible. Remove the property on close and on unsupported browsers fall back to `100dvh`.

- [ ] **Step 6: Verify and commit**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
C:\xampp\php\php.exe tests\chatbot-applicant-ui-test.php
C:\xampp\php\php.exe tests\chatbot-ui-smoke.php
node --check 'Applicants/js files/applicant-pages.js'
node --check chatbot\chatbot.js
git diff --check
```

Expected: all PASS.

Commit:

```powershell
git add -- 'Applicants/css files/applicant-pages.css' 'Applicants/js files/applicant-pages.js' 'chatbot/chatbot.css' 'chatbot/chatbot.js' 'tests/applicant-responsive-ui-contract-test.php'
git commit -m "feat: make applicant overlays and chatbot responsive"
```

---

### Task 8: Browser Viewport Smoke Harness and Full Regression

**Files:**
- Create: `tests/applicant-responsive-browser-smoke.ps1`
- Modify: `tests/applicant-responsive-ui-contract-test.php`
- Modify: `Readme.md`

**Interfaces:**
- Consumes: XAMPP PHP executable, a dedicated local test port, installed Chrome/Chromium with CDP, and existing isolated HTTP test mode.
- Produces: Repeatable viewport checks, screenshots under a temporary run directory, and documented responsive verification commands.

- [ ] **Step 1: Add a failing harness-presence assertion**

Add to the PHP contract test:

```php
$browserHarness = file_get_contents($root . '/tests/applicant-responsive-browser-smoke.ps1');
responsive_assert(is_string($browserHarness), 'Responsive browser smoke harness is missing.');
foreach (['1440', '1024', '768', '430', '390', '360', 'scrollWidth', 'clientWidth'] as $needle) {
    responsive_assert(str_contains($browserHarness, $needle), "Browser harness is missing {$needle} coverage.");
}
```

- [ ] **Step 2: Verify the contract fails**

Run the responsive contract test. Expected: FAIL because the harness does not exist.

- [ ] **Step 3: Create the isolated browser smoke harness**

Model server startup/cleanup on `tests/run-http-regressions.ps1`. The script must:

- refuse a non-isolated database;
- refuse an occupied test port;
- start PHP with `-WindowStyle Hidden` and temp session/upload paths;
- start Chrome headless with a new temporary user-data directory and remote-debugging port;
- drive the login screen and authenticated fixture account through CDP;
- visit login/signup plus all seven authenticated routes at every required viewport;
- evaluate `document.documentElement.scrollWidth <= document.documentElement.clientWidth`;
- ignore only descendants explicitly marked `.app-scroll-region`;
- open/close the drawer, account/notification controls, representative modal, document preview, and chatbot;
- capture screenshots for desktop, tablet, and phone;
- stop only processes it started and delete only its validated temporary directory.

Use a viewport table shaped like:

```powershell
$viewports = @(
    @{ Name = 'desktop'; Width = 1440; Height = 900 },
    @{ Name = 'tablet-landscape'; Width = 1024; Height = 768 },
    @{ Name = 'tablet-portrait'; Width = 768; Height = 1024 },
    @{ Name = 'phone-large'; Width = 430; Height = 932 },
    @{ Name = 'phone'; Width = 390; Height = 844 },
    @{ Name = 'phone-narrow'; Width = 360; Height = 800 },
    @{ Name = 'phone-landscape'; Width = 844; Height = 390 }
)
```

- [ ] **Step 4: Document local verification**

Add a `Responsive applicant portal checks` section to `Readme.md` with the PHP contract command, browser smoke command, isolated DB warning, screenshot location, and the manual iOS Safari/Android Chrome/200%-zoom checklist.

- [ ] **Step 5: Run the responsive suite**

Run:

```powershell
C:\xampp\php\php.exe tests\applicant-responsive-ui-contract-test.php
powershell -ExecutionPolicy Bypass -File tests\applicant-responsive-browser-smoke.ps1
```

Expected: all routes pass all viewport overflow and interaction checks, and screenshots are generated in the temporary report directory.

- [ ] **Step 6: Run the full applicant regression set**

Run:

```powershell
$tests = @(
  'applicant-phone-auth-regression.php',
  'applicant-profile-completion-regression.php',
  'applicant-profile-navigation-regression.php',
  'applicant-submit-ui-refinement-contract-test.php',
  'applicant-document-reselect-ui-contract-test.php',
  'applicant-edit-hydration-regression.php',
  'applicant-submission-auth-regression.php',
  'chatbot-applicant-ui-test.php',
  'chatbot-ui-smoke.php',
  'rejected-application-cooldown-contract-test.php'
)
foreach ($test in $tests) {
  & C:\xampp\php\php.exe (Join-Path 'tests' $test)
  if ($LASTEXITCODE -ne 0) { throw "Failed: $test" }
}
node --check 'Applicants/js files/applicant-pages.js'
node --check 'Applicants/js files/login.js'
node --check 'Applicants/js files/my-profile.js'
node --check chatbot\chatbot.js
git diff --check
```

Expected: every test and syntax check PASS with no whitespace errors.

- [ ] **Step 7: Review final scope and commit**

Compare the implementation against every acceptance criterion in the spec, inspect `git diff --stat` and the full diff for unrelated changes, then commit:

```powershell
git add -- 'Applicants' 'chatbot/chatbot.css' 'chatbot/chatbot.js' 'tests/applicant-responsive-ui-contract-test.php' 'tests/applicant-responsive-browser-smoke.ps1' 'Readme.md'
git commit -m "feat: complete responsive applicant portal"
```
