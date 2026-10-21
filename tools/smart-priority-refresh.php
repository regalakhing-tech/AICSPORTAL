<?php
declare(strict_types=1);

/**
 * CLI-only background job: run the AICS AI medical urgency (Smart Priority)
 * analysis for requests with no saved findings (or with changed documents) and
 * save the findings, so PSWDO pages never wait for it or re-check it. The
 * applicant's priority and expected processing time then follow PSWDO (up or
 * down), and the applicant is told when it changes.
 *
 *   php tools/smart-priority-refresh.php            analyse up to 3 requests
 *   php tools/smart-priority-refresh.php --quiet    print only when something ran
 *
 * The container runs it every 2 minutes (deploy/start.sh).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI execution only.\n");
}

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

const SMART_PRIORITY_REFRESH_LIMIT = 3;
const SMART_PRIORITY_REFRESH_SECONDS = 240;

$quiet = in_array('--quiet', array_slice($argv, 1), true);
$deadline = microtime(true) + SMART_PRIORITY_REFRESH_SECONDS;
$analysed = [];
$updated = 0;

try {
    $connection = aics_db_connect();
    aics_ai_gate_ensure_schema($connection);
    if (!aics_smart_priority_analysis_enabled()) exit(0);
    $saved = 0;
    // Requests with no saved findings, or whose documents changed since.
    foreach (pswdo_fetch_requests_needing_urgency_analysis($connection, 50) as $row) {
        if (count($analysed) >= SMART_PRIORITY_REFRESH_LIMIT || microtime(true) > $deadline) break;
        $categories = json_decode((string) ($row['client_categories_json'] ?? '[]'), true);
        $categories = is_array($categories) ? array_map(static fn ($value): string => strtolower(trim((string) $value)), $categories) : [];
        $check = aics_smart_priority_for_request($connection, (int) $row['id'], $categories, 'medical', false);
        // A recent failed analysis is retried once its short cache expires.
        if (empty($check['pending']) && ($check['final'] ?? false) !== true) continue;
        // Runs the AICS AI analysis (or uses a cached one), saves the findings and
        // gives the applicant the same priority.
        pswdo_refresh_request_scoring($connection, $row, null, true);
        if (!empty($check['pending'])) $analysed[] = (string) $row['reference_no'];
        else $saved++;
    }
    if ($analysed !== [] || $saved > 0) pswdo_recalculate_priority_ranks($connection);
    // Applicant priorities that still differ from PSWDO (older requests) are fixed here.
    $updated = aics_ai_gate_reconcile_priorities($connection);
    $connection->close();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Smart Priority refresh failed: ' . $exception::class . "\n");
    exit(1);
}

if ($quiet && $analysed === [] && $saved === 0 && $updated === 0) exit(0);
echo 'Smart Priority refresh: analysed=' . count($analysed) . ($analysed !== [] ? ' (' . implode(', ', $analysed) . ')' : '') . ' saved=' . $saved
    . ' priority_updates=' . $updated . "\n";
