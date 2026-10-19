<?php
declare(strict_types=1);

/**
 * CLI-only driver for the AICS AI submission gate.
 *
 *   php tools/ai-gate-sync.php                  start/retry AI checks, apply decisions
 *   php tools/ai-gate-sync.php --request-id 12  the same for one request
 *   php tools/ai-gate-sync.php --schema-only    create the gate columns/table
 *   php tools/ai-gate-sync.php --health         one-line AI health summary (no secrets)
 *   php tools/ai-gate-sync.php --quiet          print only when something changed
 *
 * The container runs it every 60 seconds and the AI worker runs it after
 * each finished request, so the next queued check starts at once.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI execution only.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-submission-gate.php';

$arguments = array_slice($argv, 1);
$requestId = null;
$position = array_search('--request-id', $arguments, true);
if ($position !== false) {
    $value = (string) ($arguments[$position + 1] ?? '');
    if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
        fwrite(STDERR, "--request-id needs a positive number.\n");
        exit(2);
    }
    $requestId = (int) $value;
}

try {
    $connection = aics_db_connect();
    if (in_array('--schema-only', $arguments, true)) {
        aics_ai_gate_ensure_schema($connection);
        echo "AICS AI gate schema ready.\n";
        exit(0);
    }
    if (in_array('--health', $arguments, true)) {
        aics_ai_gate_ensure_schema($connection);
        $health = aics_ai_environment_health(true);
        $down = array_keys(array_filter($health['components'], static fn ($state): bool => $state === 'unavailable'));
        $counts = $connection->query("SELECT ai_gate_status, COUNT(*) AS total FROM aics_requests GROUP BY ai_gate_status")?->fetch_all(MYSQLI_ASSOC) ?: [];
        echo 'AICS AI health: ' . $health['status']
            . ' | key ' . (aics_ai_gate_ai_key_configured() ? 'configured' : 'MISSING')
            . ' | unavailable: ' . ($down === [] ? 'none' : implode(',', $down))
            . ' | requests: ' . implode(' ', array_map(static fn (array $row): string => $row['ai_gate_status'] . '=' . $row['total'], $counts))
            . "\n";
        exit(0);
    }
    $totals = aics_ai_gate_sync($connection, $requestId, true, 500);
    if (in_array('--quiet', $arguments, true) && array_sum($totals) === 0) exit(0);
    echo 'AICS AI gate: ' . implode(' ', array_map(static fn (string $key, int $value): string => $key . '=' . $value, array_keys($totals), $totals)) . "\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'AICS AI gate sync failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
