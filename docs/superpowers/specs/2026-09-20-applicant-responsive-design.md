# Applicant-Side Responsive Design

**Date:** September 20, 2026

## 1. Goal

Make every applicant-facing screen comfortable and complete on desktop, tablet, and phone without changing the existing workflows, visual identity, or server behavior. The result must have consistent navigation, spacing, typography, touch targets, forms, tabs, cards, tables, steppers, dialogs, upload controls, and chatbot behavior across the whole applicant portal.

## 2. Scope

### Included screens

- Login and both signup steps in `Applicants/login.html`.
- Authenticated overview dashboard.
- My Profile summary and edit form.
- Submit Request and Edit Request, including the five-step intake flow, family composition, document uploads, review, draft recovery, and confirmation dialogs.
- Documents gallery, request groupings, filters, and document preview.
- Application Status, including request progress, document states, release information, and cooldown messages.
- Notifications list and notification popover.
- Manage/Cancel Request, including confirmation dialogs.
- Shared sidebar, top navigation, account menu, toast messages, loading and empty states, and applicant chatbot.
- `Applicants/blank-gis.php` as a simple public applicant utility page.

### Excluded

- PSWDO and administrator screens.
- Redesigning business rules, PHP endpoints, database behavior, or application states.
- Replacing Bootstrap, adding a frontend framework, or introducing a build tool.
- Converting printable request exports into the interactive applicant application shell. The export remains print-first, but its browser preview must not overflow unnecessarily on a narrow viewport.
- Changing established colors, logos, content, or desktop information hierarchy except where needed for responsive clarity.

## 3. Selected Approach

Use a unified, content-driven responsive system within the existing PHP, HTML, CSS, and JavaScript structure.

Shared shell and component rules will be defined once in `applicant-pages.css`; page-specific rules will only describe behavior unique to that page. Login/signup remains in `login.css`, and the chatbot remains in `chatbot.css`, but all three stylesheets will use the same viewport tiers, safe-area behavior, touch-target policy, and overflow rules. Small markup and JavaScript changes are allowed when CSS alone cannot preserve semantics or accessibility.

This approach was selected over adding isolated media-query patches because the current stylesheet already contains many overlapping breakpoint blocks. A full frontend rewrite was rejected because it would add risk without improving the applicant workflows.

## 4. Responsive Viewport Contract

The layout uses content behavior rather than device names, with these standard tiers:

- Wide desktop: `1200px` and above.
- Compact desktop: `993px` through `1199px`.
- Tablet: `769px` through `992px`.
- Large phone/small tablet: `481px` through `768px`.
- Phone: `480px` and below.

Critical verification widths are `1440`, `1024`, `768`, `430`, `390`, and `360` CSS pixels. Landscape phone behavior is also checked at `844x390`. Pages must remain usable between those widths, not only at the exact checkpoints.

The document viewport must not scroll horizontally at any supported width. Deliberately scrollable controls such as a tab strip or data table must contain their own horizontal scrolling, show that more content is available, and never push the page wider than the viewport.

## 5. Shared Layout System

### Spacing and containers

- The content area uses a centered fluid container with a readable maximum width.
- Page gutters scale from spacious desktop gutters to `16px` on phones; `12px` is allowed at `360px` only where necessary.
- Section spacing, card padding, radii, headings, and body text use a small shared set of CSS custom properties.
- Every flex and grid child that contains user data receives `min-width: 0`; long references, filenames, addresses, and status text wrap safely.
- Images, SVGs, iframes, and document previews never exceed their containing block.
- `100dvh` is used where viewport-height behavior matters, with a safe fallback for older browsers.
- Fixed elements account for `env(safe-area-inset-*)` on notched phones.

### Navigation shell

- At desktop widths, preserve the current fixed sidebar and content offset.
- At `992px` and below, use the existing sidebar as an off-canvas drawer over the full content area. The menu button remains in the top bar instead of floating outside the drawer.
- Opening the drawer locks background scrolling, shows the overlay, moves focus into the drawer, and exposes a visible close button. Overlay click, close button, navigation selection, and `Escape` all close it. Focus returns to the menu button.
- The drawer width is capped but becomes `calc(100vw - 32px)` on narrow screens so a visible edge remains.
- The top bar keeps the menu control, concise current-page title, notification control, and account control visible without collision. Secondary text and nonessential decoration collapse first.
- Interactive controls have a minimum `44x44px` touch area and a visible keyboard focus state.

## 6. Component Behavior

### Typography and buttons

- Headings scale with `clamp()` so they do not jump abruptly between widths.
- Body text remains at least `14px`; form controls use at least `16px` on touch layouts to prevent iOS input zoom.
- Primary actions remain visually dominant. Button groups wrap on tablet and become full-width stacks on phones when labels would otherwise compress.
- Icon-only buttons retain accessible names and `44x44px` hit areas.

### Cards, grids, and statistics

- Four-column summary grids become two columns on compact desktop/tablet and one column on phones.
- Three-column grids become two columns when space permits and one column by `640px`.
- Cards reduce padding without removing borders, labels, or important status information.
- Empty and error states remain centered and readable without fixed heights.

### Tabs, filters, and segmented controls

- Short two- or three-item controls may wrap into equal-width rows.
- Longer tab/filter sets use a single-line, keyboard-accessible horizontal scroller with scroll padding and a visible active state.
- The selected tab is scrolled into view when the page loads or the selection changes.
- No tab label is truncated to the point that its meaning is lost.

### Forms

- Desktop forms may use two or three columns; tablet forms use at most two; phone forms use one.
- Labels, help text, errors, and conditional fields stay attached to their input.
- Inputs, selects, textareas, file controls, and date controls fill their grid cell and cannot exceed it.
- Checkbox/radio choice cards use two columns on tablet when content fits and one column on phones.
- Validation scrolls and focuses the first invalid field without hiding it under the sticky header.
- Mobile keyboards must not cover the active field or the next/submit action.

### Steppers

- The login/signup two-step indicator remains compact and fully visible.
- The five-step request stepper stays horizontal on larger screens.
- On tablets it uses compact labels and equal-width steps.
- On phones it becomes a concise progress header showing the current step number, current label, and completion percentage; previously completed steps remain reachable through an accessible expandable step list or horizontally scrollable list.
- Step state cannot depend on color alone.

### Tables and dense records

- Tabular layouts that need column comparison use a contained horizontal scroller on tablet, with the first key column remaining readable.
- On phones, request/document/notification records become stacked cards when comparison is not essential.
- The editable family-composition table uses responsive row cards on phones, preserving every field, remove control, validation message, and keyboard order.
- Any table that remains scrollable includes an accessible label and a visual scroll affordance.

### Uploads and previews

- Upload drop zones become full width on phones and retain native file-picker behavior.
- Selected-file cards wrap filenames and place preview/reselect/remove actions in a touch-friendly row or stack.
- Preview dialogs constrain images and PDFs to the available dynamic viewport height.
- PDF iframe fallback text/link remains reachable if inline preview is unavailable.

### Dialogs, popovers, and toast messages

- Desktop dialogs remain centered.
- Phone dialogs use a near-full-width bottom-sheet presentation with a scrollable body and visible actions; destructive confirmation remains visually distinct.
- Dialog actions stack on narrow phones and stay reachable above the safe-area inset.
- Background scrolling is locked while a modal is open, focus is trapped inside, and focus returns to the opener on close.
- Account and notification popovers stay inside the viewport and may become full-width anchored panels below the top bar.
- Toasts fit between phone gutters, wrap long text, and do not cover primary navigation or modal actions.

### Chatbot

- Desktop keeps the compact floating panel.
- Tablet constrains the panel to the viewport and avoids covering core actions.
- Phone uses an inset full-screen panel with safe-area padding, a fixed header, scrollable messages, and a composer that remains visible when the on-screen keyboard opens.
- The launcher does not overlap sticky actions or destructive confirmation controls.

## 7. Screen-Specific Layout

### Login and signup

- Desktop keeps the current two-panel scenic presentation.
- Tablet stacks the scenic branding above the authentication card while reducing decorative height.
- Phone uses a compact branded header so login fields and the main action appear early in the viewport.
- Signup step-one fields collapse to one column; OTP input and send control wrap without shrinking below usable sizes.
- Toasts and authentication dialogs remain visible above the mobile keyboard.

### Overview

- Hero actions wrap on tablet and stack on phone.
- KPI cards follow the four-to-two-to-one pattern.
- Request progress becomes vertical on phones.
- Activity metadata moves below its description without losing dates or statuses.

### Profile

- Summary and edit panels stack by tablet size.
- All edit fields become one column on phones.
- Long address values wrap and action buttons remain reachable after validation.

### Submit and edit request

- The introduction, progress control, step panels, choice cards, family composition, document uploads, review summary, and sticky actions adapt independently.
- Phone layouts prioritize one question or logical field group at a time.
- Back/Next/Save/Submit controls become a clear full-width mobile action area without obscuring content.
- Draft recovery and submit-confirmation dialogs follow the shared mobile dialog rules.

### Documents

- Filters scroll horizontally when needed.
- Request groups and document cards become single-column by phone width.
- Filenames, MIME labels, review remarks, and status badges wrap without clipping.
- Document preview uses the available viewport rather than fixed pixel dimensions.

### Status and request management

- Progress stages change from multi-column to a vertical timeline on phones.
- Request headers, status badges, release details, remarks, and action controls stack in reading order.
- Edit and cancel controls remain clearly separated to prevent accidental destructive actions.

### Notifications

- Notification rows become cards with message content first and date/read state second.
- Header filters/actions wrap or scroll without hiding the active filter.
- Unread state remains apparent without relying only on background color.

## 8. Accessibility and Interaction Requirements

- Maintain semantic headings, labels, landmarks, tables, and form controls.
- All functionality is keyboard-operable at desktop and responsive widths.
- Drawer, modal, account menu, notification popover, tabs, and chatbot expose correct expanded/selected/dialog state.
- Focus indicators are not clipped by overflow containers.
- Text and controls remain usable at 200% browser zoom and with larger default text.
- Respect `prefers-reduced-motion`; layout and state changes cannot depend on animation.
- Maintain sufficient color contrast and never use color as the only status indicator.
- Touch targets are at least `44x44px`, with adequate spacing between destructive and safe actions.

## 9. Error and Edge-Case Behavior

- Long names, references, filenames, addresses, translated text, validation errors, and server messages wrap rather than overflow.
- Empty lists and one-item lists do not leave broken grids.
- Loading states preserve layout and do not make controls jump off-screen.
- Network and server errors remain visible near the affected form or component.
- The portal remains usable when an image is missing or a PDF cannot be embedded.
- Resizing across the `992px` navigation boundary closes stale drawer state and restores document scrolling.
- Orientation changes preserve the current form step and entered values.

## 10. File Boundaries

- `Applicants/css files/applicant-pages.css`: responsive tokens, shell, shared components, and page-specific responsive behavior; overlapping legacy media rules are consolidated in place.
- `Applicants/js files/applicant-pages.js`: drawer focus/scroll management, responsive tab visibility, modal focus behavior, and mobile stepper interaction.
- `Applicants/login.html`: only the semantic hooks required for responsive signup/login behavior.
- `Applicants/css files/login.css`: login/signup responsive tiers and safe mobile viewport behavior.
- `Applicants/js files/login.js`: only interaction changes required for focus, step visibility, or keyboard-safe behavior.
- Applicant page PHP templates: minimal classes, wrappers, accessible labels, or `data-label` attributes needed for responsive layouts; no business-rule changes.
- `chatbot/chatbot.css` and `chatbot/chatbot.js`: mobile panel sizing, viewport/keyboard behavior, and focus management.
- `Applicants/blank-gis.php`: narrow-screen document/download presentation if its current markup requires it.
- `tests/applicant-responsive-ui-contract-test.php`: structural and stylesheet contract checks for every applicant screen.
- `tests/applicant-responsive-browser-smoke.*`: browser-driven viewport and interaction checks using existing local Chrome tooling, without adding a production dependency.

## 11. Testing Strategy

Implementation follows test-driven development.

### Automated contract coverage

- Assert every applicant HTML page contains a viewport meta tag.
- Assert every authenticated page uses the shared shell stylesheet/script and exposes the expected navigation hooks.
- Assert responsive record/table markup includes accessible labels and mobile rendering hooks.
- Assert dialogs and drawer controls include their required accessibility attributes.
- Assert the standardized breakpoint and overflow safeguards exist in the relevant stylesheets.
- Run all existing applicant authentication, profile, submission, document, status, notification, and chatbot regression tests after the responsive changes.

### Browser smoke coverage

At `1440`, `1024`, `768`, `430`, `390`, and `360` pixels:

- Load login, both signup steps, and each authenticated applicant route.
- Verify `document.documentElement.scrollWidth <= document.documentElement.clientWidth` except inside explicitly marked horizontal scrollers.
- Open and close the navigation drawer, account menu, notification popover, representative confirmation modal, document preview, and chatbot.
- Exercise each submit-request step with representative long values and validation errors.
- Confirm important actions remain visible and clickable.
- Confirm focus movement, `Escape`, overlay close, and background-scroll locking.
- Capture screenshots for desktop, tablet, and phone review.

### Manual checks

- Android Chrome and iOS Safari behavior, including keyboard opening, safe-area padding, file selection, and orientation change.
- Browser zoom at 200%.
- Reduced-motion mode and keyboard-only navigation.

## 12. Acceptance Criteria

- All included applicant screens are usable at every width from `360px` upward and at the named verification widths.
- No included page has unintended horizontal document scrolling, clipped text, overlapping controls, or off-screen primary actions.
- Desktop behavior and visual identity remain recognizable and functionally unchanged.
- Tablet navigation uses a stable accessible drawer and full-width content.
- Phone layouts use readable single-column content where appropriate, with touch targets of at least `44x44px`.
- Forms, all request steps, uploads, family composition, validation, and submit/edit flows remain complete and usable.
- Tabs and filters remain understandable and expose every option.
- Tables and dense records either transform into readable cards or scroll within a clearly bounded region.
- Modals, popovers, file previews, toast messages, and the chatbot stay within the viewport and remain keyboard accessible.
- Long dynamic content and server errors wrap without breaking layout.
- Existing applicant regression tests pass, and the new responsive contract and browser smoke tests pass.
