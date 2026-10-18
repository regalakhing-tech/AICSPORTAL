<?php
declare(strict_types=1);
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=false');

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function ordering_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$rows = [
    ['id' => 50, 'reference_no' => 'REVIEW-HIGH', 'date_applied' => '2026-01-01', 'priority' => ['priority_level' => 'HIGH', 'urgency_indicator' => 'REQUIRES_STAFF_REVIEW']],
    ['id' => 40, 'reference_no' => 'STANDARD-NORMAL', 'date_applied' => '2026-01-01', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'STANDARD']],
    ['id' => 30, 'reference_no' => 'STANDARD-HIGH', 'date_applied' => '2026-01-01', 'priority' => ['priority_level' => 'HIGH', 'urgency_indicator' => 'STANDARD']],
    ['id' => 20, 'reference_no' => 'URGENT-NORMAL-NEWER', 'date_applied' => '2026-02-02', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'URGENT']],
    ['id' => 10, 'reference_no' => 'URGENT-NORMAL-OLDER', 'date_applied' => '2026-02-01', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'URGENT']],
    ['id' => 5, 'reference_no' => 'URGENT-HIGH', 'date_applied' => '2026-03-01', 'priority' => ['priority_level' => 'HIGH', 'urgency_indicator' => 'URGENT']],
];

usort($rows, 'pswdo_compare_smart_priority_rows');
$actualOrder = array_column($rows, 'reference_no');
$expectedOrder = [
    'URGENT-HIGH',
    'URGENT-NORMAL-OLDER',
    'URGENT-NORMAL-NEWER',
    'STANDARD-HIGH',
    'STANDARD-NORMAL',
    'REVIEW-HIGH',
];
ordering_assert($actualOrder === $expectedOrder, 'Smart Priority must use urgency, then Senior/PWD, then oldest application.');

$sameDate = [
    ['id' => 12, 'reference_no' => 'SECOND', 'date_applied' => '2026-04-01', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'STANDARD']],
    ['id' => 11, 'reference_no' => 'FIRST', 'date_applied' => '2026-04-01', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'STANDARD']],
];
usort($sameDate, 'pswdo_compare_smart_priority_rows');
ordering_assert(array_column($sameDate, 'reference_no') === ['FIRST', 'SECOND'], 'Request ID must deterministically break identical priority/date ties.');

$fallbackSameDay = [
    ['id' => 12, 'reference_no' => 'EARLIER-TIMESTAMP-HIGHER-ID', 'date_applied' => '0000-00-00', 'created_at' => '2026-04-02 08:00:00', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'STANDARD']],
    ['id' => 11, 'reference_no' => 'LATER-TIMESTAMP-LOWER-ID', 'date_applied' => '0000-00-00', 'created_at' => '2026-04-02 17:00:00', 'priority' => ['priority_level' => 'NORMAL', 'urgency_indicator' => 'STANDARD']],
];
usort($fallbackSameDay, 'pswdo_compare_smart_priority_rows');
ordering_assert(
    array_column($fallbackSameDay, 'reference_no') === ['LATER-TIMESTAMP-LOWER-ID', 'EARLIER-TIMESTAMP-HIGHER-ID'],
    'Missing application dates must compare by DATE(created_at), then request ID, not the created-at timestamp.'
);

$sql = pswdo_smart_priority_order_case_sql('pr');
ordering_assert(trim($sql) !== '', 'Smart Priority SQL order expression must be available to database queues.');
$connection = aics_db_connect();
$sqlTierRows = $connection->query(<<<SQL
    SELECT pr.sort_order, {$sql} AS tier
    FROM (
        SELECT 1 AS sort_order, 'HIGH' AS priority_level, 'URGENT' AS urgency_indicator
        UNION ALL SELECT 2, 'HIGH PRIORITY - PWD', 'URGENT'
        UNION ALL SELECT 3, 'HIGHLIGHT', 'URGENT'
        UNION ALL SELECT 4, 'NORMAL', 'REQUIRES_STAFF_REVIEW'
    ) pr
    ORDER BY pr.sort_order ASC
SQL)->fetch_all(MYSQLI_ASSOC);
ordering_assert(
    array_column($sqlTierRows, 'tier') === ['0', '0', '1', '5'],
    'Smart Priority SQL must normalize HIGH labels and preserve normal review-needed cases as tier 5.'
);
$priorityOnlyRows = $connection->query(<<<SQL
    SELECT pr.reference_no
    FROM (
        SELECT 1 AS sort_order, 'HIGH-STANDARD' AS reference_no, 'HIGH' AS priority_level, 'STANDARD' AS urgency_indicator
        UNION ALL SELECT 2, 'URGENT-NORMAL', 'NORMAL', 'URGENT'
        UNION ALL SELECT 3, 'HIGH-REVIEW', 'HIGH', 'REQUIRES_STAFF_REVIEW'
        UNION ALL SELECT 4, 'STANDARD-NORMAL', 'NORMAL', 'STANDARD'
        UNION ALL SELECT 5, 'REVIEW-NORMAL', 'NORMAL', 'REQUIRES_STAFF_REVIEW'
        UNION ALL SELECT 6, 'HIGHLIGHT-STANDARD', 'HIGHLIGHT', 'STANDARD'
    ) pr
    WHERE {$sql} IN (0, 1, 2, 4)
    ORDER BY pr.sort_order ASC
SQL)->fetch_all(MYSQLI_ASSOC);
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');
$newSql = pswdo_smart_priority_order_case_sql('pr');
$newTierRows = $connection->query(<<<SQL
    SELECT {$newSql} AS tier
    FROM (
        SELECT 1 AS sort_order, 'URGENT' AS priority_level, 'URGENT' AS urgency_indicator
        UNION ALL SELECT 2, 'HIGH', 'HIGH'
        UNION ALL SELECT 3, 'HIGH', 'STANDARD'
        UNION ALL SELECT 4, 'HIGH', 'REQUIRES_STAFF_REVIEW'
    ) pr
    ORDER BY pr.sort_order ASC
SQL)->fetch_all(MYSQLI_ASSOC);
ordering_assert(array_column($newTierRows, 'tier') === ['0', '1', '2', '1'], 'New SQL ordering must use only urgent, high, and standard tiers.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=false');
$connection->close();
ordering_assert(
    array_column($priorityOnlyRows, 'reference_no') === ['HIGH-STANDARD', 'URGENT-NORMAL', 'HIGH-REVIEW'],
    'Priority Cases Only must include either urgent or normalized-high cases, without treating HIGHLIGHT as high priority.'
);
$rejectedUnsafeAlias = false;
try {
    pswdo_smart_priority_order_case_sql('pr; DROP TABLE requests');
} catch (InvalidArgumentException) {
    $rejectedUnsafeAlias = true;
}
ordering_assert($rejectedUnsafeAlias, 'Smart Priority SQL helper must reject unsafe aliases.');

echo "PSWDO Smart Priority ordering contract test passed.\n";
