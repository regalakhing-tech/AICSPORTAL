<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/../includes/ai-submission-gate.php';
require_once __DIR__ . '/http-regression-db.php';

function visibility_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function visibility_function_body(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');
    visibility_assert($start !== false, "Missing function {$name}.");
    $end = strpos($source, "\nfunction ", $start + 10);
    return substr($source, $start, $end === false ? null : $end - $start);
}

$root = __DIR__ . '/..';
$dataLib = (string) file_get_contents($root . '/Pswdo/php_files/pswdo-data-lib.php');
foreach ([
    'pswdo_fetch_dashboard_metrics', 'pswdo_fetch_latest_request_date', 'pswdo_fetch_period_metrics',
    'pswdo_fetch_period_trend', 'pswdo_fetch_request_queue', 'pswdo_fetch_prescreening_counts',
    'pswdo_fetch_prescreening_queue', 'pswdo_count_requests_by_status_values', 'pswdo_count_document_review_queue',
    'pswdo_fetch_workflow_lane_requests', 'pswdo_fetch_report_summary', 'pswdo_fetch_ai_operational_metrics',
    'pswdo_fetch_top_priority_cases',
] as $function) {
    visibility_assert(str_contains(visibility_function_body($dataLib, $function), 'aics_ai_gate_visible_sql('), "{$function} must hide requests that have not passed AICS AI.");
}
visibility_assert(substr_count((string) file_get_contents($root . '/includes/geo-analytics.php'), 'aics_ai_gate_visible_sql(') === 2, 'Both map queries must hide unchecked requests.');
foreach (['workflow-action.php', 'ai-ensure-request-current.php', 'ai-ensure-current.php'] as $file) {
    visibility_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/' . $file), 'aics_ai_gate_is_released('), "{$file} must refuse requests still being checked.");
}
foreach (['overview-dashboard.php', 'request-management.php', 'prescreening-queue.php'] as $file) {
    visibility_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/' . $file), 'aics_ai_gate_sync('), "{$file} must run the AICS AI gate.");
}

visibility_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$setup = aics_db_connect();
aics_http_regression_require_seed_data($setup);

// A database from before the gate: drop the gate columns, add an old request, then upgrade.
foreach (['ai_gate_status', 'ai_gate_reason', 'ai_gate_updated_at', 'ai_gate_priority', 'ai_gate_eta_days'] as $column) {
    $setup->query("ALTER TABLE aics_requests DROP COLUMN IF EXISTS {$column}");
}
$insert = static function (mysqli $c, string $ref, ?string $gate): int {
    $gateColumn = $gate === null ? '' : ', ai_gate_status';
    $gateValue = $gate === null ? '' : ", '{$gate}'";
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status{$gateColumn})
        VALUES ('{$ref}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana {$ref}', '09170000000', 'Ana', 'Cruz', 40, 'Poblacion', 'Sibalom', 'female', '[\"medical\"]', '[]', 'unclaimed', 'pending'{$gateValue})");
    return (int) $c->insert_id;
};
$legacy = $insert($setup, 'VIS-LEGACY', null);
$setup->close();

$c = aics_db_connect();
aics_ai_gate_ensure_schema($c);
pswdo_ensure_schema($c);
visibility_assert(aics_ai_gate_is_released($c, $legacy), 'legacy rows remain visible');
$released = $insert($c, 'VIS-RELEASED', 'released');
$hidden = [$insert($c, 'VIS-HELD', 'held'), $insert($c, 'VIS-REVIEW', 'admin_review'), $insert($c, 'VIS-PENDING', 'pending')];
visibility_assert((string) $c->query("SELECT ai_gate_status FROM aics_requests WHERE id = {$hidden[2]}")->fetch_row()[0] === 'pending', 'new rows can be pending');
$default = $c->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aics_requests' AND COLUMN_NAME = 'ai_gate_status'")->fetch_row()[0];
visibility_assert(trim((string) $default, "'") === 'pending', 'new requests start pending');

$queueIds = array_map(static fn (array $row): int => (int) $row['id'], pswdo_fetch_request_queue($c, []));
visibility_assert(in_array($legacy, $queueIds, true) && in_array($released, $queueIds, true), 'released and legacy requests are listed');
visibility_assert(array_intersect($hidden, $queueIds) === [], 'held, admin review and pending requests are not listed');
$detailIds = array_map(static fn (array $row): int => (int) $row['id'], pswdo_fetch_request_queue($c, ['detail_request_id' => $hidden[0]]));
visibility_assert(!in_array($hidden[0], $detailIds, true), 'a hidden request cannot be opened by id');
visibility_assert(pswdo_fetch_dashboard_metrics($c)['total_requests'] === 2, 'dashboard counts only visible requests');
visibility_assert(pswdo_count_requests_by_status_values($c, ['pending']) === 2, 'status counts only visible requests');
$c->close();
echo "PSWDO AICS AI gate visibility checks passed.\n";
