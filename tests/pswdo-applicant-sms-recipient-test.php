<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function applicant_recipient_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$differentNumbers = [
    'contact_number' => '09171111111',
    'applicant_contact_number' => '09282222222',
    'profile_contact_number' => '09393333333',
];
applicant_recipient_assert(
    pswdo_applicant_sms_contact($differentNumbers) === '09282222222',
    'SMS must use the saved applicant number when the beneficiary has a different number.'
);

$differentNumbers['applicant_contact_number'] = '  ';
applicant_recipient_assert(
    pswdo_applicant_sms_contact($differentNumbers) === '09393333333',
    'An older request must use its applicant account profile number.'
);

$differentNumbers['profile_contact_number'] = '';
applicant_recipient_assert(
    pswdo_applicant_sms_contact($differentNumbers) === '',
    'A beneficiary number must never substitute for a missing applicant number.'
);

echo "PSWDO applicant SMS recipient test passed.\n";
