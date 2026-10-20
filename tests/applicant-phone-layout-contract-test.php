<?php
declare(strict_types=1);

// The applicant pages get a phone layout (bottom tabs, account sheet, Apply bar)
// that only applies below 769px. Desktop, the login page and the colours stay as
// they were, and it is a responsive layout, not an installable web app.

function phone_layout_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$pages = ['overview-dashboard', 'my-profile', 'submit-request', 'documents', 'application-status', 'cancel-request', 'notifications'];
foreach ($pages as $page) {
    $html = (string) file_get_contents("{$root}/Applicants/php_files/{$page}.php");
    phone_layout_assert(str_contains($html, "require_once __DIR__ . '/app-shell.php';"), "{$page} loads the phone layout helper.");
    phone_layout_assert(substr_count($html, '<?= aics_app_shell_head() ?>') === 1 && strpos($html, '<?= aics_app_shell_head() ?>') < strpos($html, '</head>'), "{$page} links the phone stylesheet in the head.");
    $script = strpos($html, '<?= aics_app_shell_script() ?>');
    phone_layout_assert($script !== false && $script > strpos($html, "applicant-widget.php'; ?>"), "{$page} loads the phone script after the page scripts and Ask AICS.");
    phone_layout_assert(!str_contains($html, 'manifest'), "{$page} is not an installable web app.");
}

$login = (string) file_get_contents("{$root}/Applicants/login.php");
phone_layout_assert(!str_contains($login, 'applicant-app'), 'The login page is unchanged.');

// Every layout rule is inside the phone media query; outside it the added
// elements are only hidden, so desktop renders exactly as before.
$css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents("{$root}/Applicants/css files/applicant-app.css"));
$depth = 0;
$buffer = '';
$topLevel = [];
foreach (str_split($css) as $char) {
    if ($char === '{') {
        if ($depth === 0) $topLevel[] = trim($buffer);
        $depth++;
        $buffer = '';
    } elseif ($char === '}') {
        $depth--;
        $buffer = '';
    } else {
        $buffer .= $char;
    }
}
phone_layout_assert($depth === 0, 'The phone stylesheet is balanced.');
$outside = array_values(array_filter($topLevel, static fn (string $selector): bool => !str_starts_with($selector, '@media (max-width: 768px)')));
phone_layout_assert(count($outside) === 1 && str_starts_with($outside[0], '.app-tabbar'), 'Only the hide-by-default rule is outside the phone media query.');
preg_match('#^\.app-tabbar[^{]*\{([^}]*)\}#m', $css, $hideRule);
phone_layout_assert(trim($hideRule[1] ?? '') === 'display: none;', 'Outside phones the added elements are only hidden.');
$desktopCss = strtolower((string) file_get_contents("{$root}/Applicants/css files/applicant-pages.css"));
preg_match_all('/#[0-9a-f]{3,6}\b/i', $css, $colours);
foreach (array_unique(array_map('strtolower', $colours[0])) as $colour) {
    phone_layout_assert(str_contains($desktopCss, $colour), "The phone colour {$colour} is one the PC pages already use.");
}

$js = (string) file_get_contents("{$root}/Applicants/js files/applicant-app.js");
phone_layout_assert(str_contains($js, "window.matchMedia('(max-width: 768px)')"), 'The script checks for a phone screen.');
phone_layout_assert(str_contains($js, 'submitLink.click()'), 'Apply goes through the real Submit link so profile and cooldown rules still apply.');
phone_layout_assert(str_contains($js, 'if (!phone.matches) return;'), 'On desktop the avatar keeps its existing account menu.');
phone_layout_assert(!str_contains($js, 'removeAttribute'), 'The script never removes attributes the existing pages set.');
phone_layout_assert(!str_contains($js, 'serviceWorker'), 'It is a responsive layout, not an installable web app.');

echo "applicant phone layout contract test passed\n";
