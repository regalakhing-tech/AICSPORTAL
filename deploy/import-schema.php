<?php
// Database setup on Railway: imports database/AICSdb.sql into MariaDB over the private
// network, so the database never needs a public address. deploy/start.sh runs it with
// --if-empty on every start, which only imports into a database without AICS tables.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// The database may still be starting when the app container boots.
for ($attempt = 1; ; $attempt++) {
    try {
        $db = new mysqli(
            getenv('AICS_DB_HOST') ?: 'localhost',
            getenv('AICS_DB_USER') ?: 'root',
            (string) getenv('AICS_DB_PASS'),
            '',
            (int) (getenv('AICS_DB_PORT') ?: 3306)
        );
        break;
    } catch (mysqli_sql_exception $exception) {
        if ($attempt >= 15) {
            throw $exception;
        }
        sleep(2);
    }
}
$db->set_charset('utf8mb4');

$hasAccounts = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'AICSdb' AND table_name = 'aics_accounts'")->fetch_row()[0] > 0;
if ($hasAccounts && in_array('--if-empty', $argv, true)) {
    echo "Database already set up; schema import skipped.\n";
    exit(0);
}

// Optional file argument: a one-time data import dropped on the volume by deploy/start.sh.
$sqlFile = __DIR__ . '/../database/AICSdb.sql';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--if-empty') {
        $sqlFile = $argument;
    }
}
$db->multi_query((string) file_get_contents($sqlFile));
$statements = 0;
do {
    if ($result = $db->store_result()) {
        $result->free();
    }
    $statements++;
} while ($db->more_results() && $db->next_result());

$tables = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'AICSdb'")->fetch_row()[0];
echo 'Imported ' . basename($sqlFile) . ": {$statements} statements, {$tables} tables in AICSdb.\n";
