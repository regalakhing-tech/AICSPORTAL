<?php
declare(strict_types=1);

/**
 * CLI-only background job: run the AICS AI medical urgency (Smart Priority)
 * analysis for requests that have none cached yet, so PSWDO pages never wait for
 * it. Urgent results upgrade the request and tell the applicant.
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
$urgent = [];

try {
    $connection = aics_db_connect();
    aics_ai_gate_ensure_schema($connection);
    if (!aics_smart_priority_analysis_enabled()) exit(0);
    $rows = $connection->query("SELECT r.id, r.reference_no, r.client_categories_json, r.assistance_types_json, at.assistance_code
        FROM aics_requests r
        LEFT JOIN (SELECT request_id, MIN(assistance_type_id) AS assistance_type_id FROM aics_request_assistance GROUP BY request_id) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        WHERE " . aics_ai_gate_visible_sql('r') . " AND " . aics_ai_gate_active_sql('r') . "
          AND LOWER(TRIM(r.request_status)) NOT IN ('released', 'not approved', 'not_approved', 'rejected', 'denied', 'completed', 'closed')
        ORDER BY r.id DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        if (count($analysed) >= SMART_PRIORITY_REFRESH_LIMIT || microtime(true) > $deadline) break;
        $assistance = trim((string) ($row['assistance_code'] ?? ''));
        if ($assistance === '') $assistance = pswdo_extract_first_assistance_from_json((string) ($row['assistance_types_json'] ?? '[]'));
        if ($assistance !== 'medical') continue;
        $row['assistance_code'] = $assistance;
        $categories = json_decode((string) ($row['client_categories_json'] ?? '[]'), true);
        $categories = is_array($categories) ? array_map(static fn ($value): string => strtolower(trim((string) $value)), $categories) : [];
        $check = aics_smart_priority_for_request($connection, (int) $row['id'], $categories, $assistance, false);
        if (empty($check['pending'])) continue;
        // Runs the AICS AI analysis (cached for a day) and stores the result.
        pswdo_refresh_request_scoring($connection, $row, null, true);
        $analysed[] = (string) $row['reference_no'];
        $indicator = (string) ($connection->query('SELECT urgency_indicator FROM aics_requests WHERE id = ' . (int) $row['id'])?->fetch_row()[0] ?? '');
        if (aics_ai_gate_note_priority($connection, (int) $row['id'], $indicator)) $urgent[] = (string) $row['reference_no'];
    }
    if ($analysed !== []) pswdo_recalculate_priority_ranks($connection);
    $connection->close();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Smart Priority refresh failed: ' . $exception::class . "\n");
    exit(1);
}

if ($quiet && $analysed === []) exit(0);
echo 'Smart Priority refresh: analysed=' . count($analysed) . ($analysed !== [] ? ' (' . implode(', ', $analysed) . ')' : '')
    . ' urgent=' . count($urgent) . ($urgent !== [] ? ' (' . implode(', ', $urgent) . ')' : '') . "\n";
