<?php
declare(strict_types=1);

/**
 * One exceptional, operator-confirmed maintenance path for a bounded live
 * validation batch. It is intentionally not included by any HTTP route.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI execution only.\n");
}

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

const AICS_ONE_TIME_CONFIRMATION = 'REPROCESS CURRENT AICS REVIEWS ONCE';

function maintenance_fail(string $message): never { throw new RuntimeException($message); }

/** @return array<string,string|bool|int> */
function maintenance_options(array $arguments): array
{
    $options = ['dry_run' => false, 'snapshot' => false, 'execute' => false, 'monitor' => false, 'database' => '', 'actor_id' => 0, 'confirm' => '', 'manifest' => '', 'backup_file' => '', 'poll_seconds' => 5, 'timeout_seconds' => 7200];
    foreach ($arguments as $argument) {
        if ($argument === '--dry-run') $options['dry_run'] = true;
        elseif ($argument === '--snapshot') $options['snapshot'] = true;
        elseif ($argument === '--execute') $options['execute'] = true;
        elseif ($argument === '--monitor') $options['monitor'] = true;
        elseif (str_starts_with($argument, '--database=')) $options['database'] = substr($argument, 11);
        elseif (str_starts_with($argument, '--actor-id=')) $options['actor_id'] = (int) substr($argument, 11);
        elseif (str_starts_with($argument, '--confirm=')) $options['confirm'] = substr($argument, 10);
        elseif (str_starts_with($argument, '--manifest=')) $options['manifest'] = substr($argument, 11);
        elseif (str_starts_with($argument, '--backup-file=')) $options['backup_file'] = substr($argument, 14);
        elseif (str_starts_with($argument, '--poll-seconds=')) $options['poll_seconds'] = max(1, min(60, (int) substr($argument, 15)));
        elseif (str_starts_with($argument, '--timeout-seconds=')) $options['timeout_seconds'] = max(60, min(28800, (int) substr($argument, 18)));
        else maintenance_fail('Unsupported option: ' . $argument);
    }
    $modeCount = (int) $options['dry_run'] + (int) $options['snapshot'] + (int) $options['execute'];
    if ($modeCount !== 1) maintenance_fail('Specify exactly one of --dry-run, --snapshot, or --execute.');
    if ((string) $options['database'] !== 'aicsdb') maintenance_fail('Require exact --database=aicsdb acknowledgement.');
    if ((int) $options['actor_id'] <= 0) maintenance_fail('Require an explicit --actor-id for a validated PSWDO actor.');
    if ((bool) $options['execute'] && ((string) $options['confirm'] !== AICS_ONE_TIME_CONFIRMATION || (string) $options['manifest'] === '' || (string) $options['backup_file'] === '')) {
        maintenance_fail('--execute requires the exact confirmation phrase, --manifest, and --backup-file.');
    }
    if ((bool) $options['monitor'] && !(bool) $options['execute']) maintenance_fail('--monitor requires --execute.');
    return $options;
}

/** @return array{id:int,role:string} */
function maintenance_actor(mysqli $db, int $actorId): array
{
    $statement = $db->prepare('SELECT a.id, r.role_code FROM aics_accounts a INNER JOIN aics_roles r ON r.id = a.role_id WHERE a.id = ? AND r.role_code = ? LIMIT 1');
    if (!$statement) maintenance_fail('Unable to validate maintenance actor.');
    $role = 'pswdo'; $statement->bind_param('is', $actorId, $role); $statement->execute();
    $row = $statement->get_result()?->fetch_assoc(); $statement->close();
    if (!is_array($row)) maintenance_fail('The supplied actor is not an authorized PSWDO account.');
    return ['id' => (int) $row['id'], 'role' => (string) $row['role_code']];
}

function maintenance_database_name(mysqli $db): string
{
    $row = $db->query('SELECT DATABASE() AS database_name')?->fetch_assoc();
    return strtolower(trim((string) ($row['database_name'] ?? '')));
}

/** @return array<int,array<string,mixed>> */
function maintenance_snapshot_rows(mysqli $db): array
{
    $sql = 'SELECT r.id AS request_id, r.reference_no AS application_reference, d.id AS document_id, d.document_type, ai.id AS review_id, ai.status, ai.attempt_count, ai.findings '
        . 'FROM aics_requests r INNER JOIN aics_request_documents d ON d.request_id = r.id '
        . 'LEFT JOIN document_ai_reviews ai ON ai.request_document_id = d.id ORDER BY r.id, d.id';
    $result = $db->query($sql); if (!$result) maintenance_fail('Unable to read the target population.');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $expected = aics_normalize_document_type((string) ($row['document_type'] ?? ''));
        if (!aics_document_type_is_verifiable($expected)) continue;
        $rows[] = ['request_id' => (int) $row['request_id'], 'application_reference' => (string) ($row['application_reference'] ?? ''), 'document_id' => (int) $row['document_id'], 'requirement_slot' => $expected, 'review_id' => (int) ($row['review_id'] ?? 0), 'status' => strtolower(trim((string) ($row['status'] ?? 'missing'))), 'attempt_count' => (int) ($row['attempt_count'] ?? 0)];
    }
    return $rows;
}

/** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
function maintenance_summary(mysqli $db, array $rows): array
{
    $states = array_fill_keys(['completed', 'failed', 'unavailable', 'queued', 'processing', 'missing_review'], 0);
    $slots = []; $requests = [];
    foreach ($rows as $row) {
        $requests[(int) $row['request_id']] = (string) $row['application_reference'];
        $slot = (string) $row['requirement_slot']; $slots[$slot] = ($slots[$slot] ?? 0) + 1;
        $state = (string) $row['status'];
        if ((int) $row['review_id'] <= 0) $states['missing_review']++;
        elseif ($state === 'processing') $states[(int) $row['attempt_count'] > 0 ? 'processing' : 'queued']++;
        elseif ($state === 'failed') $states['failed']++;
        else {
            // A completed document can carry an unavailable Image Review; it
            // remains a terminal document-review state for reprocessing.
            $states['completed']++;
        }
    }
    $ids = array_keys($requests); $leaseCount = 0;
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $leaseCount = (int) (($db->query("SELECT COUNT(*) AS total FROM document_ai_request_worker_leases WHERE request_id IN ($in)")?->fetch_assoc()['total'] ?? 0));
    }
    return ['applications' => count($requests), 'documents' => count($rows), 'states' => $states, 'slots' => $slots, 'leases' => $leaseCount, 'references' => array_values($requests)];
}

/** @param array<int,array<string,mixed>> $rows */
function maintenance_write_manifest(array $rows, array $actor): string
{
    $directory = 'C:\\xampp\\backups';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) maintenance_fail('Unable to create the protected backup directory.');
    $path = $directory . DIRECTORY_SEPARATOR . 'aics_one_time_ai_reprocess_snapshot_' . gmdate('Ymd_His') . '.json';
    $manifest = ['kind' => 'one_time_current_ai_reprocess_snapshot', 'database' => 'aicsdb', 'created_at_utc' => gmdate('c'), 'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'documents' => $rows];
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) maintenance_fail('Unable to write the immutable target manifest.');
    return $path;
}

/** @return array<int,array<string,mixed>> */
function maintenance_read_manifest(string $path): array
{
    if (!is_file($path) || filesize($path) <= 0) maintenance_fail('Target manifest is missing or empty.');
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || ($data['kind'] ?? '') !== 'one_time_current_ai_reprocess_snapshot' || ($data['database'] ?? '') !== 'aicsdb' || !is_array($data['documents'] ?? null)) maintenance_fail('Target manifest is invalid.');
    return $data['documents'];
}

/** @param array<int,array<string,mixed>> $rows */
function maintenance_assert_quiescent(mysqli $db, array $rows): void
{
    $documentIds = array_values(array_unique(array_map(static fn($row): int => (int) $row['document_id'], $rows)));
    $requestIds = array_values(array_unique(array_map(static fn($row): int => (int) $row['request_id'], $rows)));
    if ($documentIds === []) return;
    $documents = implode(',', array_map('intval', $documentIds)); $requests = implode(',', array_map('intval', $requestIds));
    $active = (int) (($db->query("SELECT COUNT(*) AS total FROM document_ai_reviews WHERE request_document_id IN ($documents) AND status = 'processing'")?->fetch_assoc()['total'] ?? 0));
    $leases = (int) (($db->query("SELECT COUNT(*) AS total FROM document_ai_request_worker_leases WHERE request_id IN ($requests)")?->fetch_assoc()['total'] ?? 0));
    if ($active > 0 || $leases > 0) maintenance_fail("Target batch is not quiescent (processing=$active, active_leases=$leases).");
}

/** @param array<int,array<string,mixed>> $rows */
function maintenance_assert_manifest_still_exact(mysqli $db, array $rows): void
{
    $expected = [];
    foreach ($rows as $row) $expected[(int) $row['request_id']][] = (int) $row['document_id'];
    foreach ($expected as $requestId => $documentIds) {
        sort($documentIds);
        $actual = $db->query('SELECT id FROM aics_request_documents WHERE request_id = ' . (int) $requestId . ' ORDER BY id')?->fetch_all(MYSQLI_ASSOC) ?: [];
        $actualIds = array_map(static fn($row): int => (int) $row['id'], $actual);
        if ($actualIds !== $documentIds) maintenance_fail('A snapshotted request changed its document set; aborting before dispatch.');
    }
}

/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
function maintenance_requeue_and_dispatch(mysqli $db, array $rows, int $actorId): array
{
    $byRequest = [];
    foreach ($rows as $row) $byRequest[(int) $row['request_id']][] = $row;
    $result = ['archived' => 0, 'first_generation' => 0, 'requests_dispatched' => 0];
    foreach ($byRequest as $requestId => $documents) {
        foreach ($documents as $document) {
            $reviewId = (int) $document['review_id'];
            if ($reviewId > 0) {
                aics_requeue_archived_ai_review($db, $reviewId, $actorId, 'one_time_maintenance_reprocess', $requestId);
                $result['archived']++;
            } else {
                aics_ensure_current_ai_review($db, (int) $document['document_id'], (string) $document['requirement_slot']);
                $result['first_generation']++;
            }
        }
        $queue = aics_ensure_current_ai_reviews_for_request($db, $requestId);
        if (!(bool) $queue['worker_needed'] || !is_string($queue['worker_lease_token']) || $queue['worker_lease_token'] === '') maintenance_fail('Unable to acquire the request worker lease.');
        if (!aics_launch_request_ai_processor($requestId, $queue['worker_lease_token'])) maintenance_fail('Request worker dispatch failed.');
        $result['requests_dispatched']++;
    }
    return $result;
}

/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
function maintenance_monitor(mysqli $db, array $rows, int $pollSeconds, int $timeoutSeconds): array
{
    $ids = implode(',', array_map(static fn($row): int => (int) $row['document_id'], $rows));
    $deadline = time() + $timeoutSeconds;
    do {
        $result = $db->query("SELECT status, COUNT(*) AS total FROM document_ai_reviews WHERE request_document_id IN ($ids) GROUP BY status");
        $counts = ['completed' => 0, 'failed' => 0, 'unavailable' => 0, 'processing' => 0];
        while ($row = $result?->fetch_assoc()) {
            $status = strtolower((string) $row['status']);
            if ($status === 'processing') $counts['processing'] += (int) $row['total'];
            elseif ($status === 'failed') $counts['failed'] += (int) $row['total'];
            else $counts['completed'] += (int) $row['total'];
        }
        echo 'Monitor: terminal ' . ($counts['completed'] + $counts['failed']) . '/' . count($rows) . ', processing ' . $counts['processing'] . "\n";
        if ($counts['processing'] === 0) return $counts;
        sleep($pollSeconds);
    } while (time() < $deadline);
    maintenance_fail('Monitoring timed out; no additional workers were launched.');
}

try {
    $options = maintenance_options(array_slice($argv, 1));
    $db = aics_db_connect();
    if (maintenance_database_name($db) !== 'aicsdb') maintenance_fail('Connected database is not aicsdb.');
    aics_ensure_ai_review_schema($db);
    $actor = maintenance_actor($db, (int) $options['actor_id']);
    if ((bool) $options['dry_run']) {
        $summary = maintenance_summary($db, maintenance_snapshot_rows($db));
        echo json_encode(['database' => 'aicsdb', 'authorized_actor' => $actor, 'dry_run' => $summary, 'mutation' => 'none'], JSON_PRETTY_PRINT), "\n";
        exit(0);
    }
    if ((bool) $options['snapshot']) {
        $rows = maintenance_snapshot_rows($db); maintenance_assert_quiescent($db, $rows);
        $manifest = maintenance_write_manifest($rows, $actor);
        echo json_encode(['database' => 'aicsdb', 'manifest' => $manifest, 'snapshot' => maintenance_summary($db, $rows), 'mutation' => 'none'], JSON_PRETTY_PRINT), "\n";
        exit(0);
    }
    $backup = (string) $options['backup_file'];
    if (!is_file($backup) || filesize($backup) <= 0) maintenance_fail('Verified pre-mutation backup file is required.');
    $rows = maintenance_read_manifest((string) $options['manifest']);
    maintenance_assert_quiescent($db, $rows); maintenance_assert_manifest_still_exact($db, $rows);
    $health = aics_ai_environment_health(true);
    if (($health['status'] ?? '') !== 'operational') maintenance_fail('AI health is not operational: ' . json_encode($health['components'] ?? []));
    $dispatch = maintenance_requeue_and_dispatch($db, $rows, (int) $actor['id']);
    $output = ['database' => 'aicsdb', 'actor' => $actor, 'manifest' => (string) $options['manifest'], 'backup_file' => $backup, 'backup_size' => filesize($backup), 'dispatch' => $dispatch];
    if ((bool) $options['monitor']) $output['final_counts'] = maintenance_monitor($db, $rows, (int) $options['poll_seconds'], (int) $options['timeout_seconds']);
    echo json_encode($output, JSON_PRETTY_PRINT), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Maintenance reprocess aborted: ' . $exception->getMessage() . "\n");
    exit(1);
}
