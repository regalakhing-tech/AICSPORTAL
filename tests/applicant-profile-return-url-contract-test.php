<?php
declare(strict_types=1);

function return_url_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$profile = file_get_contents(__DIR__ . '/../Applicants/php_files/my-profile.php');
$profileJs = file_get_contents(__DIR__ . '/../Applicants/js files/my-profile.js');
$sharedJs = file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js');
foreach ([$profile, $profileJs, $sharedJs] as $source) return_url_assert(is_string($source), 'Unable to read profile return-flow source.');

return_url_assert(str_contains($profile, 'parse_url($returnCandidate)'), 'Profile return URL is not parsed before use.');
return_url_assert(str_contains($profile, '!isset($parts[\'scheme\'], $parts[\'host\'])'), 'External return URLs are not rejected.');
return_url_assert(str_contains($profile, '$allowedPages') && str_contains($profile, '$allowedDirectPages'), 'Profile return URL is not restricted to applicant routes.');
return_url_assert(str_contains($sharedJs, "profileUrl.searchParams.set('return_to', returnTo)"), 'Submit Request dialog does not preserve the origin URL.');
return_url_assert(str_contains($profileJs, 'payload.profile_complete === true && returnTo !== \'\''), 'Profile save does not return only after successful completion.');

echo "Applicant profile return URL contract test passed.\n";
