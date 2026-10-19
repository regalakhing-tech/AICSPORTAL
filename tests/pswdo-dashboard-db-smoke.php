<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$connection = aics_db_connect();
pswdo_ensure_schema($connection);

$metrics = pswdo_fetch_dashboard_metrics($connection);
// PSWDO counts only requests that passed the AICS AI check (or predate it).
$dbRow = $connection->query("SELECT COUNT(*) AS total_requests FROM aics_requests WHERE ai_gate_status = 'released'")?->fetch_assoc();

$metricsTotal = (int) ($metrics['total_requests'] ?? 0);
$dbTotal = (int) ($dbRow['total_requests'] ?? 0);

echo 'metrics.total_requests=' . $metricsTotal . PHP_EOL;
echo 'db.total_requests=' . $dbTotal . PHP_EOL;
echo 'metrics.pending_review=' . (int) ($metrics['pending_review'] ?? 0) . PHP_EOL;
echo 'metrics.approved=' . (int) ($metrics['approved'] ?? 0) . PHP_EOL;
echo 'metrics.not_approved=' . (int) ($metrics['not_approved'] ?? 0) . PHP_EOL;
echo 'metrics.released=' . (int) ($metrics['released'] ?? 0) . PHP_EOL;

if ($metricsTotal !== $dbTotal) {
    $connection->close();
    fwrite(STDERR, "Mismatch: metrics.total_requests does not match database total.\n");
    exit(1);
}

echo "Dashboard DB smoke test passed.\n";
$connection->close();
