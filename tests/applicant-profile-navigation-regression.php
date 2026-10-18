<?php
declare(strict_types=1);

function profile_navigation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$profilePhp = file_get_contents(__DIR__ . '/../Applicants/php_files/my-profile.php');
$profileJs = file_get_contents(__DIR__ . '/../Applicants/js files/my-profile.js');
$sharedJs = file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js');
$profileSave = file_get_contents(__DIR__ . '/../Applicants/php_files/profile-save.php');
$profileCss = file_get_contents(__DIR__ . '/../Applicants/css files/applicant-pages.css');

foreach ([$profilePhp, $profileJs, $sharedJs, $profileSave, $profileCss] as $source) {
    profile_navigation_assert(is_string($source), 'Unable to read a required Applicant profile source file.');
}

$addressSection = substr($profilePhp, (int) strpos($profilePhp, 'Home Address'));
$expectedOrder = [
    'for="profile-complete-address"',
    'for="profile-municipality"',
    'for="profile-barangay"',
    'for="profile-province"',
    'for="profile-address-line"',
];
$lastPosition = -1;
foreach ($expectedOrder as $needle) {
    $position = strpos($addressSection, $needle);
    profile_navigation_assert($position !== false, "Missing address field {$needle}.");
    profile_navigation_assert($position > $lastPosition, 'Address fields are not in the required DOM/tab order.');
    $lastPosition = $position;
}

profile_navigation_assert(!str_contains($profileCss, '.profile-address-line-field { order:'), 'Address layout still uses CSS-only ordering.');
profile_navigation_assert(str_contains($sharedJs, 'function setSubmitRequestAvailability(isProfileComplete)'), 'Shared reversible navigation state function is missing.');
profile_navigation_assert(str_contains($sharedJs, 'data-submit-request-blocked'), 'Submit Request links do not expose the incomplete-profile handler.');
profile_navigation_assert(str_contains($sharedJs, 'function showIncompleteProfileDialog()'), 'The incomplete-profile dialog is missing.');
profile_navigation_assert(!str_contains($sharedJs, "link.removeAttribute('href')"), 'Submit Request is still disabled by removing its link destination.');
profile_navigation_assert(!str_contains($profileCss, '.nav-item.is-disabled,'), 'Shared CSS still disables Submit Request navigation.');
profile_navigation_assert(str_contains($profilePhp, 'House No. / Street / Sitio / Purok <span class="field-optional">(Optional)</span>'), 'House No. label is not marked optional.');
profile_navigation_assert(str_contains($profileJs, 'window.setSubmitRequestAvailability(payload.profile_complete)'), 'My Profile does not apply authoritative completion state after loading/saving.');
profile_navigation_assert(str_contains($profileSave, "'profile_complete' => \$completion['complete']"), 'Profile save response does not expose authoritative completion state.');

echo "Applicant profile layout and navigation regression checks passed.\n";
