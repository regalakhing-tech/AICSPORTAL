<?php
declare(strict_types=1);

// Every applicant logout link asks "Are you sure?" first. On phones the sidebar
// is hidden and the header avatar menu's "Log Out" item was not covered.

function logout_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$js = (string) file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js');
$start = (int) strpos($js, 'function initLogoutConfirm()');
$body = substr($js, $start, (int) strpos($js, "\nfunction ", $start + 10) - $start);
logout_assert(str_contains($body, 'document.addEventListener("click"'), 'Logout confirmation must listen for clicks on the whole page (links added later included).');
logout_assert(str_contains($body, 'closest(\'a[href*="auth-logout.php"]\')'), 'Any link to auth-logout.php must ask for confirmation, not only .logout-btn.');
logout_assert(!str_contains($body, 'logoutLinks.forEach'), 'No per-link binding that misses the avatar menu.');
echo "Applicant logout confirmation checks passed.\n";
