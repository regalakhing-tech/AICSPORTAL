<?php
declare(strict_types=1);

// Every PSWDO table lists URGENT requests first, then HIGH, then the rest. A
// page's own sort (classification, assistance type, release status) applies
// within each level. Before, the Screening Queue's other sorts and the Release
// Scheduling table put an urgent request below routine ones.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function priority_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

priority_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');
aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_ai_gate_ensure_schema($c);

/** A medical request with saved AICS AI findings. */
$insert = static function (mysqli $c, string $ref, string $categories, string $urgency, int $daysAgo, string $status): int {
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status, ai_gate_status, urgency_indicator, urgency_basis, urgency_analyzed_at)
        VALUES ('{$ref}', CURDATE() - INTERVAL {$daysAgo} DAY, 'Test', '{$ref}', 40, 'Test {$ref}', '09170000000', 'Test', '{$ref}', 40, 'X', 'Sibalom', 'female', '[\"medical\"]', '{$categories}', 'unclaimed', '{$status}', 'released', '{$urgency}', 'Saved test finding.', NOW())");
    $id = (int) $c->insert_id;
    $c->query("INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT {$id}, id FROM aics_assistance_types WHERE assistance_code = 'medical'");
    return $id;
};
$refsIn = static function (array $rows, array $wanted): array {
    return array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['reference_no'] ?? ''), $rows), static fn (string $ref): bool => in_array($ref, $wanted, true)));
};

// Screening Queue: the urgent request is the newest and its category sorts last.
$screening = [
    'S-URG' => $insert($c, 'S-URG', '["youth"]', 'URGENT', 0, 'approved'),
    'S-HIGH' => $insert($c, 'S-HIGH', '["senior_citizen"]', 'HIGH', 1, 'approved'),
    'S-STD' => $insert($c, 'S-STD', '["youth"]', 'STANDARD', 5, 'approved'),
];
foreach ($screening as $id) $c->query("INSERT INTO aics_request_prescreening (request_id, decision_status) VALUES ({$id}, 'for_screening')");

// Release Scheduling: the urgent request is still unscheduled; the routine one is ready.
$release = [
    'R-URG' => $insert($c, 'R-URG', '["youth"]', 'URGENT', 0, 'for release'),
    'R-STD' => $insert($c, 'R-STD', '["youth"]', 'STANDARD', 5, 'for release'),
];
// Request Management (incoming applications): same order of dates and categories.
$insert($c, 'M-URG', '["youth"]', 'URGENT', 0, 'pending');
$insert($c, 'M-HIGH', '["senior_citizen"]', 'HIGH', 1, 'pending');
$insert($c, 'M-STD', '["youth"]', 'STANDARD', 5, 'pending');
$c->query("INSERT INTO aics_request_release_schedule (request_id, scheduled_date, scheduled_time, venue, release_batch, release_status) VALUES ({$release['R-STD']}, CURDATE(), '09:00:00', 'PSWDO Office', 'Batch 1', 'ready_for_release')");
pswdo_refresh_smart_priorities($c);
pswdo_recalculate_priority_ranks($c);

$expectedScreening = ['S-URG', 'S-HIGH', 'S-STD'];
foreach (['default', 'client_classification', 'type_of_assistance', 'high_priority'] as $sort) {
    $order = $refsIn(pswdo_fetch_prescreening_queue($c, 'for_screening', '', '', $sort), $expectedScreening);
    priority_assert($order === $expectedScreening, "Screening Queue ({$sort}) lists urgent, then high, then the rest: got " . implode(', ', $order));
}
foreach (['default', 'client_classification', 'type_of_assistance'] as $sort) {
    $order = $refsIn(pswdo_fetch_release_schedule_queue($c, 'all', 50, $sort), ['R-URG', 'R-STD']);
    priority_assert($order === ['R-URG', 'R-STD'], "Release Scheduling ({$sort}) lists the urgent request first: got " . implode(', ', $order));
}
$order = $refsIn(pswdo_fetch_request_queue($c, []), ['M-URG', 'M-HIGH', 'M-STD']);
priority_assert($order === ['M-URG', 'M-HIGH', 'M-STD'], 'Request Management lists urgent, then high, then the rest: got ' . implode(', ', $order));
$lane = $refsIn(pswdo_fetch_workflow_lane_requests($c, 'release_ready', 12), ['R-URG', 'R-STD']);
priority_assert($lane === ['R-URG', 'R-STD'], 'Overview lists put urgent requests first: got ' . implode(', ', $lane));
$c->close();
echo "PSWDO priority-on-top checks passed.\n";
