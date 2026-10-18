<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$connection = aics_db_connect();

$databaseRow = $connection->query('SELECT DATABASE() AS db_name, @@hostname AS db_host, @@port AS db_port')?->fetch_assoc();
$tables = [
    'aics_accounts',
    'aics_requests',
    'aics_request_documents',
    'aics_request_statuses',
];

echo 'db.name=' . (string) ($databaseRow['db_name'] ?? '') . PHP_EOL;
echo 'db.host=' . (string) ($databaseRow['db_host'] ?? '') . PHP_EOL;
echo 'db.port=' . (string) ($databaseRow['db_port'] ?? '') . PHP_EOL;

foreach ($tables as $tableName) {
    $stmt = $connection->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $exists = ((int) ($row['total'] ?? 0)) > 0 ? 'yes' : 'no';
    echo 'table.' . $tableName . '=' . $exists . PHP_EOL;

    if ($exists !== 'yes') {
        $connection->close();
        fwrite(STDERR, 'Missing required table: ' . $tableName . PHP_EOL);
        exit(1);
    }
}

echo "Database bootstrap smoke test passed.\n";
$connection->close();
