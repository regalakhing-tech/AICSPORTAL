<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function period_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

// Rolling ranges end today, so the first days of a month still show recent activity.
period_assert(pswdo_overview_period_range('day', '2026-10-01') === ['2026-10-01', '2026-10-01'], 'Day must be today.');
period_assert(pswdo_overview_period_range('week', '2026-10-01') === ['2026-09-25', '2026-10-01'], 'Week must be the last 7 days.');
period_assert(pswdo_overview_period_range('month', '2026-10-01') === ['2026-09-02', '2026-10-01'], 'Month must be the last 30 days.');
period_assert(pswdo_overview_period_range('month', '2026-03-01') === ['2026-01-31', '2026-03-01'], 'Month must cross month boundaries.');
period_assert(pswdo_overview_period_range('unknown', '2026-10-01') === ['2026-10-01', '2026-10-01'], 'Unknown periods fall back to today.');

$manilaToday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
period_assert(pswdo_overview_period_range('day')[1] === $manilaToday, 'Rolling ranges must end on today in Philippine time.');

period_assert(pswdo_overview_period_label('day') === 'Today', 'Day label.');
period_assert(pswdo_overview_period_label('week') === 'Last 7 Days', 'Week label.');
period_assert(pswdo_overview_period_label('month') === 'Last 30 Days', 'Month label.');

// The empty state explains when the latest request happened.
$page = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/overview-dashboard.php');
$js = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
$data = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/overview-dashboard-data.php');
period_assert(str_contains($data, "'latest_request_date'"), 'Data endpoint does not return the latest request date.');
period_assert(str_contains($page, 'Latest request:') && str_contains($js, 'Latest request:'), 'Empty state does not show the latest request date.');
period_assert(str_contains($js, 'queuedPeriod'), 'A period click during a background refresh must be queued, not dropped.');
period_assert(!str_contains($page, "'This Month'") && !str_contains($js, '"This Month"'), 'Calendar-month label fallback remains.');

// Live database: the trend points and the metrics count the same requests.
$connection = aics_db_connect();
pswdo_ensure_schema($connection);
foreach (['day', 'week', 'month'] as $period) {
    $metrics = pswdo_fetch_period_metrics($connection, $period);
    $trend = pswdo_fetch_period_trend($connection, $period);
    $trendTotal = array_sum(array_map(static fn (array $point): int => (int) $point['submitted'], $trend['points']));
    period_assert($trendTotal === (int) $metrics['metrics']['total_submitted'], "Trend and metrics disagree for $period.");
    period_assert([$metrics['date_from'], $metrics['date_to']] === pswdo_overview_period_range($period), "Metrics range is not rolling for $period.");
}
$latest = pswdo_fetch_latest_request_date($connection);
period_assert($latest === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $latest) === 1, 'Latest request date format is wrong.');
$connection->close();

echo "PSWDO overview period range test passed.\n";
