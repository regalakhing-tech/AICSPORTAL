<?php
declare(strict_types=1);

// Draft uploads accept the same sizes as the page and the final submit:
// documents up to 25 MB, ID photos up to 10 MB, with a message that says which.
function draft_limit_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$draft = (string) file_get_contents($root . '/Applicants/php_files/draft-lib.php');
$submit = (string) file_get_contents($root . '/Applicants/php_files/save-request.php');
$page = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');

draft_limit_assert(str_contains($submit, 'const MAX_UPLOAD_SIZE = 26_214_400;') && str_contains($page, 'file.size > 25 * 1024 * 1024'), 'The page or the final submit no longer allows 25 MB documents.');
draft_limit_assert(!str_contains($draft, '$size > 10485760'), 'Draft uploads still refuse documents over 10 MB.');
draft_limit_assert(str_contains($draft, '$maxBytes = $isIdPhoto ? 10 * 1024 * 1024 : 25 * 1024 * 1024;'), 'Draft uploads do not use the 25 MB document / 10 MB ID photo limits.');
draft_limit_assert(str_contains($draft, "'ID photo must be 10 MB or smaller.' : 'File exceeds the 25 MB limit.'"), 'An oversized draft upload does not say what the limit is.');

echo "Applicant draft upload limit test passed.\n";
