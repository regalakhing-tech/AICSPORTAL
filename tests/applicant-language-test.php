<?php
declare(strict_types=1);

// Applicants can switch the page content to English, Tagalog or Hiligaynon from
// My Profile. The choice is saved to the account; the navigation stays in English.
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function language_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

language_assert(aics_applicant_languages() === ['en', 'tl', 'hil'], 'Three languages: English, Tagalog, Hiligaynon.');
language_assert(aics_normalize_applicant_language('TL ') === 'tl' && aics_normalize_applicant_language('hil') === 'hil', 'Language codes are normalized.');
language_assert(aics_normalize_applicant_language('fr') === '' && aics_normalize_applicant_language('') === '', 'Unknown languages are refused.');

$root = __DIR__ . '/..';
$auth = (string) file_get_contents("{$root}/Applicants/php_files/auth-common.php");
language_assert(str_contains($auth, 'ADD COLUMN IF NOT EXISTS preferred_language VARCHAR(8) DEFAULT NULL'), 'Existing databases get the language column.');
language_assert(str_contains((string) file_get_contents("{$root}/database/AICSdb.sql"), '`preferred_language` VARCHAR(8) DEFAULT NULL'), 'New databases have the language column.');

$save = (string) file_get_contents("{$root}/Applicants/php_files/language-save.php");
language_assert(str_contains($save, 'aics_is_logged_in_applicat()') && str_contains($save, "\$_SERVER['REQUEST_METHOD'] !== 'POST'"), 'Only a signed-in applicant can save, with POST.');
language_assert(str_contains($save, 'aics_normalize_applicant_language(') && str_contains($save, 'aics_save_applicant_language('), 'Only known languages are saved to the account.');

$shell = (string) file_get_contents("{$root}/Applicants/php_files/app-shell.php");
language_assert(str_contains($shell, 'applicant-i18n-dict.js') && str_contains($shell, 'applicant-i18n.js') && str_contains($shell, 'aics-i18n-pending'), 'Every applicant page loads the translations and hides content until it is translated.');

$engine = (string) file_get_contents("{$root}/Applicants/js files/applicant-i18n.js");
foreach (['.sidebar', '.top-nav', '.app-tabbar', '.app-sheet', '.aics-chatbot-panel', 'textarea', '[data-no-translate]'] as $selector) {
    language_assert(str_contains($engine, $selector), "Translation skips {$selector} (navigation and typed text stay as they are).");
}
language_assert(str_contains($engine, "option.setAttribute('value'"), 'Options keep submitting their English value.');

$profile = (string) file_get_contents("{$root}/Applicants/php_files/my-profile.php");
language_assert(str_contains($profile, 'data-language-picker') && substr_count($profile, 'data-language-option=') === 3, 'My Profile has the three-language picker.');
language_assert(preg_match_all('/data-language-option="(en|tl|hil)"[^>]*data-no-translate/', $profile) === 3, 'Language names are always shown in their own language.');

// Every dictionary entry has a Tagalog and a Hiligaynon text, with no duplicates.
$dict = str_replace("\r\n", "\n", (string) file_get_contents("{$root}/Applicants/js files/applicant-i18n-dict.js"));
preg_match_all('/^  ("(?:[^"\\\\]|\\\\.)*"): \[("(?:[^"\\\\]|\\\\.)*"), ("(?:[^"\\\\]|\\\\.)*")\],?$/m', $dict, $rows, PREG_SET_ORDER);
language_assert(count($rows) > 400, 'The dictionary covers the applicant pages.');
$keys = [];
foreach ($rows as $row) {
    $english = json_decode($row[1]);
    language_assert(!isset($keys[$english]), "Duplicate entry: {$english}");
    $keys[$english] = true;
    language_assert(trim((string) json_decode($row[2])) !== '' && trim((string) json_decode($row[3])) !== '', "Missing translation for: {$english}");
}
foreach (['Language', 'Choose the language for the content of your pages. The menu stays in English.', 'Language saved.'] as $text) {
    language_assert(isset($keys[$text]), "The picker text is translated: {$text}");
}
language_assert(substr_count($dict, "\n  \"") === count($rows), 'Every dictionary line is one complete entry.');

echo "applicant language test passed\n";
