<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function release_mswdo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$connection = aics_db_connect();
$fixture = $connection->query(
    "SELECT r.id, r.municipality
     FROM aics_requests r
     INNER JOIN aics_request_release_schedule rs ON rs.request_id = r.id
     WHERE TRIM(COALESCE(r.municipality, '')) <> ''
       AND rs.release_status IN ('scheduled', 'rescheduled', 'ready_for_release', 'missed')
     ORDER BY r.id DESC
     LIMIT 1"
)->fetch_assoc();

release_mswdo_assert(is_array($fixture), 'A scheduled release fixture with a municipality is required.');

$record = pswdo_fetch_release_schedule_record($connection, (int) $fixture['id']);
$connection->close();

release_mswdo_assert(is_array($record), 'The scheduled release record could not be fetched.');
release_mswdo_assert(
    trim((string) ($record['municipality'] ?? '')) === trim((string) $fixture['municipality']),
    'The release record dropped or changed the municipality required for MSWDO notification.'
);
release_mswdo_assert(
    pswdo_resolve_mswdo_municipality((string) $record['municipality']) !== '',
    'The release record municipality cannot resolve to an MSWDO destination.'
);

echo "PSWDO release MSWDO municipality regression passed.\n";
