<?php
declare(strict_types=1);

function cooldown_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rules = file_get_contents(__DIR__ . '/../Applicants/php_files/application-rules.php');
$save = file_get_contents(__DIR__ . '/../Applicants/php_files/save-request.php');

cooldown_contract_assert(is_string($rules) && is_string($save), 'Cooldown sources could not be read.');
cooldown_contract_assert(str_contains($rules, 'INNER JOIN aics_account_requests ar ON ar.request_id = r.id'), 'Cooldown is not anchored to submitted applicant requests.');
cooldown_contract_assert(!str_contains($rules, "'not_approved', 'not approved'"), 'Rejected requests are still excluded from cooldown eligibility.');
cooldown_contract_assert(!str_contains($rules, "'rejected', 'denied'"), 'Rejected requests are still excluded from cooldown eligibility.');
cooldown_contract_assert(str_contains($save, 'enforce_application_cooldown($connection, $accountId);'), 'New submissions are not protected by the authoritative cooldown check.');

echo "Rejected application cooldown contract test passed.\n";
