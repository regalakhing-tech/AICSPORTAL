<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$connection = aics_db_connect();

foreach (['Hamtic', 'all'] as $municipality) {
    $records = pswdo_fetch_all_release_records($connection, [
        'municipality' => $municipality,
        'limit' => 500,
    ]);
    if (count($records) < 2) {
        throw new RuntimeException("Need at least two {$municipality} release records to verify ordering.");
    }

    $dates = [];
    $result = $connection->query('SELECT id, date_applied, created_at FROM aics_requests');
    while ($row = $result->fetch_assoc()) {
        $dates[(int) $row['id']] = $row;
    }

    $actualIds = array_map(static fn(array $row): int => (int) $row['id'], $records);
    $expectedIds = $actualIds;
    usort($expectedIds, static function (int $left, int $right) use ($dates): int {
        $a = $dates[$left];
        $b = $dates[$right];
        $aDate = $a['date_applied'] !== '0000-00-00' ? $a['date_applied'] : substr($a['created_at'], 0, 10);
        $bDate = $b['date_applied'] !== '0000-00-00' ? $b['date_applied'] : substr($b['created_at'], 0, 10);
        return strcmp($aDate, $bDate)
            ?: strcmp($a['created_at'], $b['created_at'])
            ?: ($left <=> $right);
    });

    if ($actualIds !== $expectedIds) {
        throw new RuntimeException("MSWDO {$municipality} records must be ordered from oldest application to newest.");
    }
}

$connection->close();
echo "MSWDO application ordering test passed.\n";
