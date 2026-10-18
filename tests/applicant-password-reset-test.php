<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-password-reset-common.php';

function reset_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now = 1_000;
$row = [
    'code_hash' => password_hash('123456', PASSWORD_DEFAULT),
    'expires_at' => $now + 300,
    'failed_attempts' => 0,
];
reset_assert(aics_reset_otp_status(null, '123456', $now) === 'invalid_otp', 'Missing request accepted.');
reset_assert(aics_reset_otp_status($row, '123456', $now) === 'valid', 'Correct code rejected.');
reset_assert(aics_reset_otp_status($row, '654321', $now) === 'invalid_otp', 'Wrong code accepted.');
reset_assert(aics_reset_otp_status($row, '123456', $now + 300) === 'otp_expired', 'Expired code accepted.');
$row['failed_attempts'] = 5;
reset_assert(aics_reset_otp_status($row, '123456', $now) === 'otp_locked', 'Attempt limit bypassed.');
reset_assert(aics_reset_otp_status($row, '12345x', $now) === 'invalid_otp', 'Malformed code accepted.');
$row['expires_at'] = 0;
$row['code_hash'] = '';
reset_assert(aics_reset_otp_status($row, '123456', $now) === 'otp_expired', 'Consumed code accepted.');
reset_assert(aics_password_policy_error('Reset2026') === null, 'Valid password rejected.');
reset_assert(aics_password_policy_error('short') !== null, 'Weak password accepted.');
$send = aics_reset_send_decision(null, 0, $now);
reset_assert($send['allowed'] && $send['send_count'] === 1, 'First SMS send was blocked.');
$previous = ['sent_at' => $now - 61, 'window_start' => $now - 600, 'send_count' => 4, 'failed_attempts' => 2];
$send = aics_reset_send_decision($previous, 4, $now);
reset_assert($send['allowed'] && $send['send_count'] === 5 && $send['failed_attempts'] === 2, 'Resend reset the OTP guess budget.');
reset_assert(!aics_reset_send_decision($previous, 5, $now)['allowed'], 'IP send limit was bypassed.');
$previous['send_count'] = 5;
reset_assert(!aics_reset_send_decision($previous, 0, $now)['allowed'], 'Account send limit was bypassed.');
$previous['send_count'] = 1;
$previous['failed_attempts'] = 5;
reset_assert(!aics_reset_send_decision($previous, 0, $now)['allowed'], 'Locked OTP attempts allowed another send.');
$previous['window_start'] = $now - 901;
reset_assert(aics_reset_send_decision($previous, 0, $now)['allowed'], 'Expired rate window did not clear limits.');

echo "Applicant password reset test passed.\n";
