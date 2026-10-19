<?php
declare(strict_types=1);

// The release amount is set when PSWDO schedules a release and goes into the
// applicant's SMS. "Complete Release" only confirms it; it asks for an amount
// only when the schedule was saved without one (Set Schedule for All, older
// schedules).
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function amount_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// SMS text.
$item = ['beneficiary_name' => 'Maria Santos', 'reference_no' => 'AICS-2026-123456', 'scheduled_date_label' => 'October 5, 2026', 'scheduled_time_label' => '9:00 AM', 'venue' => 'PSWDO Office', 'amount_given' => 5000.0];
amount_assert(str_contains(pswdo_build_release_schedule_sms_message($item), "Venue: PSWDO Office\nAmount: PHP 5,000.00\nPlease arrive"), 'The release SMS states the amount.');
amount_assert(!str_contains(pswdo_build_release_schedule_sms_message(['amount_given' => null] + $item), 'Amount:'), 'No amount line when none is set.');
amount_assert(str_contains(pswdo_apply_release_schedule_sms_tokens("Venue: {venue}\n{amount_line}Thank you.", $item), "Venue: PSWDO Office\nAmount: PHP 5,000.00\nThank you."), 'Bulk notices fill in each applicant\'s amount.');

// Page and script.
$page = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/release-scheduling.php');
$script = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
$formStart = (int) strpos($page, 'id="release-schedule-modal-form"');
$scheduleForm = substr($page, $formStart, (int) strpos($page, 'id="bulk-schedule-modal"') - $formStart);
amount_assert(str_contains($scheduleForm, 'id="release-modal-amount"') && str_contains($scheduleForm, 'name="amount_given"'), 'Schedule Release asks for the amount to release.');
amount_assert(substr_count($page, 'data-amount-given="') >= 2, 'The Schedule and Release buttons carry the saved amount.');
amount_assert(str_contains($script, 'const amountInput = document.getElementById("release-modal-amount");') && str_contains($script, 'Amount: PHP ${'), 'The Schedule Release SMS fills in the amount.');
amount_assert(str_contains($script, '"{amount_line}"'), 'SMS templates can include the amount.');
$releaseModal = substr($page, (int) strpos($page, 'id="mark-released-modal"'), 3000);
amount_assert(str_contains($releaseModal, 'id="mark-released-amount-summary"'), 'Complete Release shows the scheduled amount instead of asking for it.');
amount_assert(str_contains($page, "amountWrap.hidden = hasAmount;"), 'The amount field appears only when no amount was scheduled.');

// Database.
amount_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
$account = (int) ($c->query('SELECT id FROM aics_accounts ORDER BY id LIMIT 1')->fetch_row()[0] ?? 0);
if ($account <= 0) {
    $c->query("INSERT INTO aics_accounts (role_id, account_status_id, password_hash) SELECT r.id, s.id, 'x' FROM aics_roles r, aics_account_statuses s WHERE s.status_code = 'active' LIMIT 1");
    $account = (int) $c->insert_id;
}
$insert = static function (mysqli $c, string $ref): int {
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, request_status) VALUES ('{$ref}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 40, 'X', 'Sibalom', 'female', '[\"medical\"]', 'unclaimed', 'approved')");
    return (int) $c->insert_id;
};
$amountOf = static fn (int $id): ?string => $c->query("SELECT amount_given FROM aics_request_release_schedule WHERE request_id = {$id}")->fetch_row()[0] ?? null;
$refused = static function (callable $action): string {
    try {
        $action();
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
    return '';
};

$scheduled = $insert($c, 'AMOUNT-1');
amount_assert(str_contains($refused(fn () => pswdo_save_release_schedule_action($c, $scheduled, $account, '2026-10-21', '09:00', 'PSWDO Office', 10, '', 0.0)), 'amount'), 'A schedule without an amount is refused.');
pswdo_save_release_schedule_action($c, $scheduled, $account, '2026-10-21', '09:00', 'PSWDO Office', 10, '', 5000.0);
amount_assert($amountOf($scheduled) === '5000.00', 'The scheduled amount is saved.');
$record = pswdo_fetch_release_schedule_record($c, $scheduled);
amount_assert(is_array($record) && (float) ($record['amount_given'] ?? 0) === 5000.0, 'The release queue shows the scheduled amount.');
pswdo_update_release_schedule_status_action($c, $scheduled, 'released', $account, '');
amount_assert($amountOf($scheduled) === '5000.00', 'Confirm Release keeps the scheduled amount.');
amount_assert($c->query("SELECT claim_status FROM aics_requests WHERE id = {$scheduled}")->fetch_row()[0] === 'claimed', 'Confirm Release completes the release.');

// Set Schedule for All saves no amount: Complete Release must ask for it.
$bulk = $insert($c, 'AMOUNT-2');
pswdo_bulk_save_release_schedule_action($c, [$bulk], $account, '2026-10-21', '09:00', 'PSWDO Office', 10, '');
amount_assert($amountOf($bulk) === null, 'Bulk schedules have no amount yet.');
amount_assert(str_contains($refused(fn () => pswdo_update_release_schedule_status_action($c, $bulk, 'released', $account, '')), 'amount'), 'A release without any amount is refused.');
pswdo_update_release_schedule_status_action($c, $bulk, 'released', $account, '', 3000.0);
amount_assert($amountOf($bulk) === '3000.00', 'The amount entered at release is saved.');
$c->close();
echo "PSWDO release amount checks passed.\n";
