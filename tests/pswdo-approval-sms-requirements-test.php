<?php
declare(strict_types=1);

// The approval SMS greets the applicant (it goes to their phone), lists the
// documents they uploaded under whose document each is, and gives the PSWDO
// office hours so they can visit without waiting for a schedule.
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function approval_sms_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$owners = pswdo_requirement_owners_from_codes(['valid_id', 'valid_id_back', 'med_cert', 'med_cert', '', 'billing_statement']);
approval_sms_assert($owners === [
    'applicant' => ['Valid Identification Card'],
    'beneficiary' => ['Medical Abstract or Medical Certificate', 'Billing Statement or Prescription'],
], 'Uploaded documents are not listed once each under the applicant (their ID) and the beneficiary (the rest).');

$opening = "{greeting}, Reine Bustillo.\n"
    . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
    . "Aprubado na po ang inyong AICS application.\n"
    . "Reference No.: AICS-2026-0001\n"
    . "Maaari na po kayong pumunta sa PSWDO Office sa anumang araw ng opisina:\n"
    . "Lunes hanggang Huwebes, 8:00 AM - 11:00 AM at 1:00 PM - 4:00 PM.\n";

$forSomeoneElse = pswdo_default_sms_message('for_screening', 'AICS-2026-0001', 'Reine Bustillo', $owners + ['beneficiary_name' => 'Nicasio E. Bustillo', 'same_person' => false]);
approval_sms_assert($forSomeoneElse === $opening
    . "Pakidala po ang orihinal na kopya ng mga requirements:\n"
    . "Para sa inyo (applicant):\n"
    . "- Valid Identification Card\n"
    . "Para sa beneficiary (Nicasio E. Bustillo):\n"
    . "- Medical Abstract or Medical Certificate\n"
    . "- Billing Statement or Prescription\n"
    . "Maraming salamat po.", 'The approval SMS does not say which requirements are the applicant\'s and which are the beneficiary\'s.');

$forSelf = pswdo_default_sms_message('for_screening', 'AICS-2026-0001', 'Reine Bustillo', $owners + ['beneficiary_name' => 'Reine Bustillo', 'same_person' => true]);
approval_sms_assert($forSelf === $opening
    . "Pakidala po ang orihinal na kopya ng inyong mga requirements:\n"
    . "- Valid Identification Card\n"
    . "- Medical Abstract or Medical Certificate\n"
    . "- Billing Statement or Prescription\n"
    . "Maraming salamat po.", 'An applicant who is also the beneficiary gets the requirements split in two.');

approval_sms_assert(str_contains(pswdo_default_sms_message('for_screening', 'AICS-2026-0001', 'Reine Bustillo'), "Pakidala po ang inyong valid ID at ang orihinal na kopya ng inyong mga requirements.\n"), 'Without a document list the SMS does not ask for the ID and requirements.');

approval_sms_assert(pswdo_sms_recipient_name(['applicant_name' => 'Reine Bustillo', 'beneficiary_name' => 'Nicasio E. Bustillo']) === 'Reine Bustillo', 'The SMS is addressed to the beneficiary instead of the applicant.');
approval_sms_assert(pswdo_sms_recipient_name(['applicant_first_name' => 'Reine', 'applicant_last_name' => 'Bustillo', 'beneficiary_complete_name' => 'Nicasio E. Bustillo']) === 'Reine Bustillo', 'A request row is not addressed to its applicant.');
approval_sms_assert(pswdo_sms_recipient_name(['applicant_name' => 'Applicant', 'beneficiary_name' => 'Nicasio E. Bustillo']) === 'Nicasio E. Bustillo', 'Without an applicant name the SMS does not fall back to the beneficiary.');

$library = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
approval_sms_assert(is_string($library) && str_contains($library, "pswdo_default_sms_message('for_screening', \$referenceNo, pswdo_request_identity(\$connection, \$requestId)['name'], pswdo_uploaded_requirements(\$connection, \$requestId))"), 'Approval does not send the SMS built from the uploaded requirements, addressed to the applicant.');

echo "PSWDO approval SMS requirements test passed.\n";
