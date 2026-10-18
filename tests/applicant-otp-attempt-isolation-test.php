<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function otp_attempt_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    'aics_signup_otp_store_attempt',
    'aics_signup_otp_attempt',
    'aics_signup_otp_cooldown_remaining',
    'aics_signup_otp_forget_attempt',
    'aics_signup_otp_migrate_legacy_attempt',
] as $function) {
    otp_attempt_assert(function_exists($function), "Missing OTP attempt helper: {$function}");
}

$session = [];
$phoneA = '09171234567';
$phoneB = '09181234567';
$attemptA = '0f0f96d7-9a4d-4bf1-a4dc-986456c879a1';
$attemptB = 'db88a962-2aec-472f-831d-a0062cab36e5';
$attemptC = '1b606a1a-7fe9-4e29-a559-16af1c363a81';
$now = 1_000;

aics_signup_otp_store_attempt($session, $attemptA, $phoneA, '111111', $now, 300);
otp_attempt_assert(aics_signup_otp_cooldown_remaining($session, $attemptA, $phoneA, $now, 300) === 300, 'Attempt A did not receive its own cooldown.');
otp_attempt_assert(aics_signup_otp_cooldown_remaining($session, $attemptB, $phoneA, $now, 300) === 0, 'Attempt A cooldown leaked to attempt B using the same phone.');
otp_attempt_assert(aics_signup_otp_cooldown_remaining($session, $attemptC, $phoneB, $now, 300) === 0, 'Attempt A cooldown leaked to another phone and attempt.');

aics_signup_otp_store_attempt($session, $attemptB, $phoneA, '222222', $now + 20, 300);
aics_signup_otp_store_attempt($session, $attemptC, $phoneB, '333333', $now + 40, 300);
aics_signup_otp_store_attempt($session, $attemptA, $phoneB, '444444', $now + 60, 300);
otp_attempt_assert(aics_signup_otp_cooldown_remaining($session, $attemptA, $phoneA, $now + 40, 300) === 260, 'A later attempt changed attempt A cooldown.');
otp_attempt_assert(aics_signup_otp_cooldown_remaining($session, $attemptB, $phoneA, $now + 40, 300) === 280, 'Attempt B did not retain an independent same-phone cooldown.');
otp_attempt_assert(aics_signup_otp_cooldown_remaining($session, $attemptC, $phoneB, $now + 40, 300) === 300, 'Attempt C did not receive an independent cooldown.');
otp_attempt_assert((aics_signup_otp_attempt($session, $attemptA, $phoneA)['code'] ?? '') === '111111', 'Attempt A OTP was overwritten.');
otp_attempt_assert((aics_signup_otp_attempt($session, $attemptA, $phoneB)['code'] ?? '') === '444444', 'A second phone in attempt A was not stored independently.');
otp_attempt_assert((aics_signup_otp_attempt($session, $attemptB, $phoneA)['code'] ?? '') === '222222', 'Attempt B OTP was not stored independently for the same phone.');
otp_attempt_assert(aics_signup_otp_attempt($session, $attemptB, $phoneB) === null, 'An attempt accepted a different phone number.');

aics_signup_otp_forget_attempt($session, $attemptA);
otp_attempt_assert(aics_signup_otp_attempt($session, $attemptA, $phoneA) === null, 'Completed attempt A was not removed.');
otp_attempt_assert((aics_signup_otp_attempt($session, $attemptB, $phoneA)['code'] ?? '') === '222222', 'Removing attempt A also removed attempt B.');

$legacySession = [
    'aics_signup_otp' => '333333',
    'aics_otp_sent_time' => $now,
    'aics_otp_phone' => $phoneA,
    'aics_otp_expires' => $now + 300,
];
aics_signup_otp_migrate_legacy_attempt($legacySession);
otp_attempt_assert(!isset($legacySession['aics_otp_sent_time']), 'Legacy global cooldown remained after migration.');
otp_attempt_assert(($legacySession['aics_signup_otp_attempts'] ?? []) === [], 'Legacy global OTP was incorrectly assigned to a new attempt.');

echo "Applicant OTP attempt isolation test passed.\n";
