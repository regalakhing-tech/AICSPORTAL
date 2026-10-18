<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$connection = aics_db_connect();
pswdo_ensure_schema($connection);

$periods = ['day', 'week', 'month'];
foreach ($periods as $period) {
    $payload = pswdo_fetch_period_metrics($connection, $period);
    $metrics = (array) ($payload['metrics'] ?? []);

    echo 'period=' . (string) ($payload['period_code'] ?? $period) . PHP_EOL;
    echo 'label=' . (string) ($payload['period_label'] ?? '') . PHP_EOL;
    echo 'range=' . (string) ($payload['date_from'] ?? '') . ' -> ' . (string) ($payload['date_to'] ?? '') . PHP_EOL;
    echo 'submitted=' . (int) ($metrics['total_submitted'] ?? 0) . PHP_EOL;
    echo 'pending=' . (int) ($metrics['pending_review'] ?? 0) . PHP_EOL;
    echo 'interview_initial=' . (int) ($metrics['interview_initial_total'] ?? 0) . PHP_EOL;
    echo 'approved=' . (int) ($metrics['approved'] ?? 0) . PHP_EOL;
    echo 'not_approved=' . (int) ($metrics['not_approved'] ?? 0) . PHP_EOL;
    echo 'released=' . (int) ($metrics['released'] ?? 0) . PHP_EOL;
    echo "---" . PHP_EOL;
}

echo "Period metrics smoke test passed.\n";
$connection->close();
