<?php
declare(strict_types=1);

function responsive_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
$notifications = file_get_contents($root . '/Applicants/php_files/notifications.php');
responsive_assert(is_string($notifications), 'Cannot read applicant notifications page.');
responsive_assert(
    !str_contains($notifications, 'Stay updated on the real-time progress of your assistance requests'),
    'The removed notification hero description is still rendered.'
);
foreach ([
    '--app-gutter',
    '--touch-target',
    '.app-scroll-region',
    '@media (max-width: 992px)',
    '@media (max-width: 768px)',
    '@media (max-width: 480px)',
] as $needle) {
    responsive_assert(str_contains($css, $needle), "Missing shared responsive contract: {$needle}");
}

$applicantJs = file_get_contents($root . '/Applicants/js files/applicant-pages.js');
responsive_assert(is_string($applicantJs), 'Cannot read applicant JavaScript.');
responsive_assert(str_contains($applicantJs, 'function openApplicantDrawer()'), 'Shared drawer opener is missing.');
responsive_assert(str_contains($applicantJs, 'function closeApplicantDrawer('), 'Shared drawer closer is missing.');
responsive_assert(str_contains($applicantJs, "window.matchMedia('(max-width: 992px)')"), 'Drawer breakpoint is not synchronized with CSS.');
responsive_assert(str_contains($applicantJs, "document.body.classList.add('sidebar-drawer-open')"), 'Drawer does not lock the page.');
responsive_assert(str_contains($applicantJs, "event.key === 'Tab'"), 'Drawer does not contain keyboard focus.');

foreach (array_slice($pages, 2) as $page) {
    $source = file_get_contents($root . '/' . $page);
    foreach (['id="sidebar"', 'id="toggle-btn"', 'id="overlay"', 'id="main-content"'] as $hook) {
        responsive_assert(substr_count($source, $hook) === 1, "Invalid {$hook} count in {$page}.");
    }
}

$loginCss = file_get_contents($root . '/Applicants/css files/login.css');
responsive_assert(is_string($loginCss), 'Cannot read login stylesheet.');
$loginPage = file_get_contents($root . '/Applicants/login.html');
responsive_assert(is_string($loginPage), 'Cannot read login page.');
responsive_assert(
    str_contains($loginPage, 'css files/login.css?v=redesign-v7'),
    'The applicant login page must invalidate the pre-viewport-fix stylesheet cache.'
);
foreach (['100dvh', 'env(safe-area-inset-left)', '.signup-otp-controls', 'font-size: 16px', 'min-height: 44px'] as $needle) {
    responsive_assert(str_contains($loginCss, $needle), "Login mobile contract is missing {$needle}.");
}

foreach (['.overview-stats-executive', '.profile-layout-senior', '.docs-gallery-grid', '.status-progress-tracker', '.notifications-page'] as $selector) {
    responsive_assert(str_contains($css, $selector), "Responsive page selector is missing: {$selector}");
}
responsive_assert(str_contains($css, 'grid-template-columns: 1fr;'), 'Single-column phone layout is missing.');
responsive_assert(str_contains($css, 'overflow-wrap: anywhere;'), 'Long applicant content is not protected from overflow.');

$submit = file_get_contents($root . '/Applicants/php_files/submit-request.php');
responsive_assert(is_string($submit), 'Cannot read submit-request template.');
responsive_assert(str_contains($submit, 'class="mobile-step-summary mobile-only"'), 'Mobile request step summary is missing.');
responsive_assert(str_contains($submit, 'aria-live="polite"'), 'Mobile step changes are not announced.');
responsive_assert(str_contains($applicantJs, 'function syncMobileStepSummary('), 'Mobile step summary controller is missing.');
responsive_assert(str_contains($css, '.stepper-senior.app-scroll-region'), 'Phone stepper is not a bounded component scroller.');

foreach (['.responsive-actions > *', 'body.sidebar-drawer-open', '100dvh', 'env(safe-area-inset-bottom)', 'min-height: var(--touch-target)'] as $needle) {
    responsive_assert(str_contains($css, $needle), "Shared mobile layout is missing {$needle}.");
}

responsive_assert(str_contains($applicantJs, 'class="preview-modal-card" role="dialog" aria-modal="true"'), 'Document preview lacks dialog semantics.');
responsive_assert(str_contains($applicantJs, 'aria-label="Close document preview"'), 'Document preview close control lacks an accessible name.');

$chatbotCss = file_get_contents($root . '/chatbot/chatbot.css');
$chatbotJs = file_get_contents($root . '/chatbot/chatbot.js');
responsive_assert(is_string($chatbotCss) && is_string($chatbotJs), 'Cannot read chatbot assets.');
foreach (['100dvh', 'env(safe-area-inset-top)', 'env(safe-area-inset-bottom)'] as $needle) {
    responsive_assert(str_contains($chatbotCss, $needle), "Chatbot phone layout is missing {$needle}.");
}
responsive_assert(str_contains($chatbotJs, 'window.visualViewport'), 'Chatbot does not adapt to the mobile keyboard viewport.');

$blankGis = file_get_contents($root . '/Applicants/blank-gis.php');
responsive_assert(is_string($blankGis), 'Cannot read blank GIS page.');
responsive_assert(str_contains($blankGis, 'class="gis-preview-scroll"'), 'Blank GIS lacks a bounded narrow-screen preview.');
responsive_assert(str_contains($blankGis, 'aria-label="Scrollable General Intake Sheet preview"'), 'Blank GIS preview is not accessibly labeled.');
responsive_assert(str_contains($blankGis, '@media screen and (max-width: 768px)'), 'Blank GIS lacks narrow-screen controls.');
responsive_assert(str_contains($css, '.submit-hero-executive-main { width: 100%; max-width: none; }'), 'Submit hero does not release its desktop width on mobile.');
responsive_assert(str_contains($css, '.submit-hero-executive-actions > * { flex: 1 1 100%;'), 'Submit hero actions do not stack on phones.');

$documents = file_get_contents($root . '/Applicants/php_files/documents.php');
responsive_assert(is_string($documents), 'Cannot read documents page.');
responsive_assert(str_contains($documents, 'class="docs-filter-bar app-scroll-region"'), 'Document tabs are not contained on narrow screens.');
responsive_assert(str_contains($documents, "scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' })"), 'Selected document tab is not brought into view.');

foreach (['toggleBtnHome', 'createApplicantDrawerClose', "sidebar.removeAttribute('aria-hidden')"] as $needle) {
    responsive_assert(str_contains($applicantJs, $needle), "Accessible drawer lifecycle is missing {$needle}.");
}
foreach (['role="dialog" aria-modal="true" aria-labelledby="submit-confirm-title"', 'aria-label="Close final review"'] as $needle) {
    responsive_assert(str_contains($submit, $needle), "Submit confirmation accessibility is missing {$needle}.");
}
foreach (['function openSubmitConfirmModal()', 'function closeSubmitConfirmModal(', 'trapFocusInside(confirmModal', "document.body.classList.add('modal-open')"] as $needle) {
    responsive_assert(str_contains($applicantJs, $needle), "Submit confirmation behavior is missing {$needle}.");
}
foreach (['function labelFamilyCompositionRows()', 'data-family-field-label', 'Family member ${rowNumber}'] as $needle) {
    responsive_assert(str_contains($applicantJs, $needle), "Family mobile labels are missing {$needle}.");
}
responsive_assert(str_contains($css, '.family-mobile-label'), 'Family mobile labels lack responsive styling.');
responsive_assert(str_contains($css, '.family-member-card'), 'Family members are not grouped into responsive cards.');
responsive_assert(str_contains($submit, 'family-action-head'), 'The desktop family grid lacks an action column for removing members.');
responsive_assert(str_contains($css, '.sidebar .close-sidebar-btn'), 'The mobile drawer close control lacks a visible responsive rule.');
responsive_assert(str_contains($css, '.top-nav .toggle-btn'), 'The mobile navigation opener lacks a top-bar layout rule.');
responsive_assert(str_contains($chatbotCss, 'body.aics-chatbot-is-open'), 'Open phone chatbot does not lock background scrolling.');
responsive_assert(str_contains($chatbotJs, 'trapChatbotFocus'), 'Phone chatbot does not contain keyboard focus.');
responsive_assert(str_contains($applicantJs, 'remove-family-member'), 'Family members cannot be removed after being added.');
responsive_assert(substr_count($applicantJs, 'trapFocusInside(modal, event)') >= 2, 'Profile and logout dialogs do not both trap keyboard focus.');
responsive_assert(str_contains($applicantJs, 'trapFocusInside(draftResumePanel, event)'), 'Draft recovery dialog does not trap keyboard focus.');

$phoneCompactMarker = '/* Phone-only compact request gate and five-step navigation. */';
$phoneCompactStart = strpos($css, $phoneCompactMarker);
responsive_assert($phoneCompactStart !== false, 'Phone-only compact request layout is missing.');
$phoneCompactCss = substr($css, (int) $phoneCompactStart);
foreach ([
    '@media (max-width: 480px)',
    '.cooldown-gate-notice-executive',
    '.stepper-senior.app-scroll-region',
    'grid-template-columns: repeat(5, minmax(0, 1fr))',
    'overflow-x: hidden',
] as $needle) {
    responsive_assert(str_contains($phoneCompactCss, $needle), "Phone-only request layout is missing {$needle}.");
}
foreach (['closeDocumentPreview(', 'title="PDF document preview"', 'Open PDF in a new tab'] as $needle) {
    responsive_assert(str_contains($applicantJs, $needle), "Document preview behavior is missing {$needle}.");
}

echo "Applicant responsive UI contract passed.\n";
