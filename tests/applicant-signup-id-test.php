<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/applicant-id-verification.php';
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function signup_id_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$id = ['last_name' => 'DELA CRUZ', 'given_names' => 'JUAN SANTOS', 'middle_name' => 'REYES'];

// Accepted: same person, normal differences in how people type their name.
foreach ([
    ['Juan', 'Reyes', 'Dela Cruz', 'case and only the first given name'],
    ['Juan Santos', 'reyes', 'dela cruz', 'all given names and a lowercase middle name'],
    ['JUAN', 'REYES', 'DE LA CRUZ', 'spacing in the surname'],
    ['Juan', 'Reyez', 'Dela Cruz', 'one-letter misread in a 5+ letter name'],
    ['Juan', 'R', 'Dela Cruz', 'matching middle initial'],
    ['Juan', 'R.', 'Dela Cruz', 'matching middle initial with punctuation'],
    ['Juan', '', 'Dela Cruz', 'omitted optional middle name'],
    ['Juan', null, 'Dela Cruz', 'null optional middle name'],
] as [$first, $middle, $last, $case]) {
    $result = aics_id_names_match($id, $first, $middle, $last);
    signup_id_assert($result['match'] === true, "Should accept: $case ({$result['reason']})");
}

// Rejected: a different person.
foreach ([
    ['Pedro', 'Reyes', 'Dela Cruz', 'first name'],
    ['Juan', 'Reyes', 'Villanueva', 'last name'],
    ['Juan', 'Mendoza', 'Dela Cruz', 'middle name'],
    ['Juana', 'Reyes', 'Dela Cruz', 'first name'],
    ['Juan', 'M', 'Dela Cruz', 'middle name'],
] as [$first, $middle, $last, $field]) {
    $result = aics_id_names_match($id, $first, $middle, $last);
    signup_id_assert($result['match'] === false && str_contains($result['reason'], $field), "Should reject a different $field.");
}

// Ñ and other accents compare equal to their plain letters.
$accented = ['last_name' => 'MUÑOZ', 'given_names' => 'JOSÉ', 'middle_name' => null];
signup_id_assert(aics_id_names_match($accented, 'Jose', '', 'Munoz')['match'], 'Accents must not block a match.');
// When the ID shows no middle name, a middle name that is one of the given names is fine.
signup_id_assert(aics_id_names_match(['last_name' => 'DELA CRUZ', 'given_names' => 'JUAN SANTOS', 'middle_name' => null], 'Juan', 'Santos', 'Dela Cruz')['match'], 'Middle typed from given names must be accepted.');
signup_id_assert(aics_id_names_match(['last_name' => 'DELA CRUZ', 'given_names' => 'JUAN', 'middle_name' => 'R'], 'Juan', 'Reyes', 'Dela Cruz')['match'], 'A full middle name must match an initial shown on the ID.');
signup_id_assert(aics_validate_person_name('R.', false) === 'R', 'A dotted middle initial must be accepted and normalized for sign-up.');
signup_id_assert(aics_validate_person_name('R.', true) === null, 'First and last names must not accept a dotted initial.');

// Birth date must equal the ID's when the ID shows one.
signup_id_assert(aics_id_birthdates_match('1975-03-12', '1975-03-12'), 'Same birth date must match.');
signup_id_assert(!aics_id_birthdates_match('1975-03-12', '1975-12-03'), 'A different birth date must not match.');
signup_id_assert(aics_id_birthdates_match(null, '1975-03-12'), 'IDs without a birth date cannot block sign-up.');
signup_id_assert(aics_id_birthdates_match('2005-04-03', '2005-04-03'), 'April 3 must match its ISO date.');
signup_id_assert(aics_id_birthdates_match('2005-03-04', '2005-03-04'), 'March 4 must match its ISO date.');
signup_id_assert(!aics_id_birthdates_match('2005-04-03', '2005-03-04'), 'April 3 must not silently match March 4.');
signup_id_assert(!aics_id_birthdates_match('2005-03-04', '2005-04-03'), 'March 4 must not silently match April 3.');

$verified = ['status' => 'completed', 'decision' => 'ok', 'document_detected' => true,
    'is_identification_card' => true, 'is_supported_id' => true, 'id_type' => 'philsys_national_id',
    'issuer' => 'Philippine Statistics Authority', 'confidence' => 'high'];
signup_id_assert(aics_id_result_is_verified($verified), 'Supported, high-confidence government ID must pass.');
signup_id_assert(aics_id_result_is_verified(array_merge($verified, ['issuer' => 'PSA'])), 'PhilSys ID with PSA issuer must pass.');
signup_id_assert(!aics_id_result_is_verified(array_merge($verified, [
    'id_type' => 'other_government_id', 'issuer' => 'Department of Campus Affairs',
])), 'A generic department name must not establish a government issuer.');
signup_id_assert(aics_id_result_is_verified(array_merge($verified, [
    'id_type' => 'other_government_id', 'issuer' => 'Department of Agriculture',
])), 'A specifically recognized government issuer must remain eligible.');
foreach ([
    'drivers_license' => 'Land Transportation Office', 'passport' => 'Department of Foreign Affairs',
    'philhealth_id' => 'Philippine Health Insurance Corporation',
    'pwd_id' => 'City Government of San Jose de Buenavista',
    'senior_citizen_id' => 'Office of Senior Citizens Affairs',
    'barangay_id' => 'Barangay Government of San Angel',
] as $kind => $issuer) {
    signup_id_assert(aics_id_result_is_verified(array_merge($verified, ['id_type' => $kind, 'issuer' => $issuer])), "$kind must pass the server gate.");
}
foreach (['school_id', 'student_id', 'unknown_id', 'unsupported_id', 'company_id', 'not_an_id'] as $kind) {
    signup_id_assert(!aics_id_result_is_verified(array_merge($verified, ['id_type' => $kind])), "$kind must fail despite an ok decision and matching name.");
}
foreach (['document_detected' => false, 'is_identification_card' => false, 'is_supported_id' => false,
          'confidence' => 'low', 'issuer' => 'Republic of the Philippines', 'decision' => 'retake'] as $field => $value) {
    signup_id_assert(!aics_id_result_is_verified(array_merge($verified, [$field => $value])), "$field must fail closed.");
}

// Private storage stays outside the web root and attempt ids cannot escape it.
$root = aics_id_private_root();
signup_id_assert(!str_starts_with(str_replace('\\', '/', strtolower($root)), str_replace('\\', '/', strtolower(realpath(__DIR__ . '/..')))), 'ID images must not be stored in the web root.');
signup_id_assert(aics_id_signup_dir('../../etc') === null && aics_id_signup_dir('0b6f7a3c-1d2e-4f50-8a9b-0c1d2e3f4a5b') !== null, 'Attempt ids must be validated before building paths.');
$scanPathsOne = aics_id_new_scan_paths('C:\\test-id-attempt', ['front' => 'jpg', 'back' => 'png']);
$scanPathsTwo = aics_id_new_scan_paths('C:\\test-id-attempt', ['front' => 'jpg', 'back' => 'png']);
signup_id_assert($scanPathsOne['front'] !== $scanPathsTwo['front'] && $scanPathsOne['back'] !== $scanPathsTwo['back'],
    'Concurrent scans must not overwrite each other\'s photos.');
signup_id_assert(str_ends_with($scanPathsOne['front'], '.jpg') && str_ends_with($scanPathsOne['back'], '.png'),
    'Unique scan paths must preserve validated image extensions.');

// Sign-up flow wiring.
$signup = (string) file_get_contents(__DIR__ . '/../Applicants/php_files/auth-signup.php');
foreach (["'id_required'", "'id_name_mismatch'", "'id_birthdate_mismatch'", "'id_not_verified'", 'aics_id_record_verification('] as $needle) {
    signup_id_assert(str_contains($signup, $needle), "auth-signup.php must handle $needle.");
}
$endpoint = (string) file_get_contents(__DIR__ . '/../Applicants/php_files/signup-id-scan.php');
foreach (['aics_id_run_check(', 'aics_id_rate_limited(', "'image/jpeg'", 'MAX_ID_PHOTO_BYTES', 'session_write_close();'] as $needle) {
    signup_id_assert(str_contains($endpoint, $needle), "signup-id-scan.php is missing $needle.");
}
signup_id_assert(!str_contains($endpoint, 'id_number'), 'The scan endpoint must not handle ID numbers.');
$page = (string) file_get_contents(__DIR__ . '/../Applicants/login.php');
foreach (['id="signup-step-id"', 'name="id_front"', 'name="id_back"', 'id="btn-scan-id"', 'capture="environment"'] as $needle) {
    signup_id_assert(str_contains($page, $needle), "Sign-up page is missing $needle.");
}
// Order: 1 Your details -> 2 Valid ID -> 3 Security.
$details = strpos($page, 'id="signup-step-1"'); $idStep = strpos($page, 'id="signup-step-id"'); $security = strpos($page, 'id="signup-step-2"');
signup_id_assert($details !== false && $idStep !== false && $security !== false && $details < $idStep && $idStep < $security, 'Sign-up steps must be details, then ID, then security.');
signup_id_assert(preg_match('/login\.css\?v=[a-z0-9-]+-v\d+/', $page) === 1 && preg_match('/login\.js\?v=[a-z0-9-]+-v\d+/', $page) === 1, 'Sign-up assets need a version so browsers do not use cached files.');
signup_id_assert(str_contains($endpoint, "'check'") && str_contains($endpoint, 'aics_id_scan_match('), 'The ID step must re-check edited details against the ID.');
$script = (string) file_get_contents(__DIR__ . '/../Applicants/js files/login.js');
signup_id_assert(str_contains($script, "setSignupStep('id')") && str_contains($script, 'Use the details on my ID'), 'The ID step flow is not wired in the script.');
// Automatic verification: no staff hand-off when the AI check cannot run.
signup_id_assert(!str_contains($signup, "'unavailable'") && !str_contains($endpoint, "['ok', 'unavailable']"), 'Accounts must only be created from an ID that was read and matched.');
signup_id_assert(!str_contains($script, 'staff will check') && !str_contains((string) file_get_contents(__DIR__ . '/../includes/applicant-id-verification.php'), 'PSWDO staff will check'), 'Sign-up must not promise a staff check.');
$profile = (string) file_get_contents(__DIR__ . '/../Applicants/php_files/profile-save.php');
signup_id_assert(str_contains($profile, 'aics_id_verification_for_account('), 'Profile saving must keep the ID-verified name and birth date.');

echo "Applicant sign-up ID test passed.\n";
