<?php
declare(strict_types=1);

// A scheduled release completes on confirmation alone, without a cash amount.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function release_confirmation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

release_confirmation_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$db = aics_db_connect();
pswdo_ensure_schema($db);
$db->query("INSERT INTO aics_accounts (role_id, account_status_id, password_hash) SELECT r.id, s.id, 'x' FROM aics_roles r, aics_account_statuses s WHERE s.status_code = 'active' LIMIT 1");
$accountId = (int) $db->insert_id;

foreach (['bulk', 'individual', 'legacy'] as $kind) {
    $reference = 'CONFIRM-' . $kind;
    $db->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, request_status) VALUES ('{$reference}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 40, 'X', 'Sibalom', 'female', '[\"medical\"]', 'unclaimed', 'approved')");
    $requestId = (int) $db->insert_id;
    if ($kind === 'bulk') {
        pswdo_bulk_save_release_schedule_action($db, [$requestId], $accountId, '2026-10-24', '09:00', 'PSWDO Office', 10, '');
    } else {
        pswdo_save_release_schedule_action($db, $requestId, $accountId, '2026-10-24', '09:00', 'PSWDO Office', 10, '');
    }
    if ($kind === 'legacy') {
        $db->query("UPDATE aics_request_release_schedule SET amount_given = 4000 WHERE request_id = {$requestId}");
    }
    pswdo_update_release_schedule_status_action($db, $requestId, 'released', $accountId, '');
    $row = $db->query("SELECT r.request_status, r.claim_status, rs.release_status, rs.amount_given FROM aics_requests r JOIN aics_request_release_schedule rs ON rs.request_id = r.id WHERE r.id = {$requestId}")->fetch_assoc();
    release_confirmation_assert($row['request_status'] === 'released' && $row['claim_status'] === 'claimed' && $row['release_status'] === 'released', "Confirming the {$kind} release did not complete it.");
    release_confirmation_assert($row['amount_given'] === ($kind === 'legacy' ? '4000.00' : null), 'Confirmation changed a historical cash value or invented a new one.');
    release_confirmation_assert((int) $db->query("SELECT COUNT(*) FROM aics_request_notifications WHERE request_id = {$requestId} AND message_text LIKE '%Released%'")->fetch_row()[0] > 0, 'The release update was not recorded for the applicant.');
    $record = pswdo_fetch_release_schedule_record($db, $requestId);
    release_confirmation_assert(is_array($record) && !array_key_exists('amount_given', $record), 'The release record still exposes a cash amount.');
}
$report = pswdo_fetch_released_report($db, '2026-10-01', '2026-10-31');
release_confirmation_assert(count($report) === 3, 'Confirmed releases are missing from the released report.');
foreach ($report as $item) {
    release_confirmation_assert(!array_key_exists('amount_given', $item), 'The released report still exposes a cash amount.');
}
$db->close();
echo "PSWDO confirmation-only release checks passed.\n";
