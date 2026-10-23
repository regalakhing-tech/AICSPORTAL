<?php
declare(strict_types=1);

/**
 * CLI-only: remove one AICS request and everything linked to it, so a test
 * application can be submitted again from the same account.
 *
 *   php tools/delete-request.php AICS-2026-8B0782            preview only, changes nothing
 *   php tools/delete-request.php AICS-2026-8B0782 --confirm  save a backup, then delete
 *
 * It removes the request, its documents and AICS AI results, its status
 * history, every applicant/PSWDO/MSWDO notification for it (and their "read"
 * marks), its admin audit log entries, its uploaded files and its AI review
 * images. The applicant account itself stays.
 *
 * Before deleting, every removed row is saved as JSON in
 * <private root>/backups/deleted-requests/.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI execution only.\n");
}

require_once __DIR__ . '/../config/database.php';

$arguments = array_slice($argv, 1);
$confirm = in_array('--confirm', $arguments, true);
$references = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--confirm'));
if (count($references) !== 1 || !preg_match('/^AICS-\d{4}-[A-Z0-9]{4,12}$/D', strtoupper($references[0]))) {
    fwrite(STDERR, "Usage: php tools/delete-request.php AICS-2026-XXXXXX [--confirm]\n");
    exit(2);
}
$reference = strtoupper($references[0]);

/** @return list<array<string,mixed>> */
function delete_request_rows(mysqli $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($params !== []) {
        $stmt->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** @param list<int> $ids */
function delete_request_id_list(array $ids): string
{
    return implode(',', array_map('intval', $ids));
}

$db = aics_db_connect();
$db->set_charset('utf8mb4');
$schema = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];

$requests = delete_request_rows($db, 'SELECT * FROM aics_requests WHERE reference_no = ?', [$reference]);
if ($requests === []) {
    echo "No request {$reference} in database {$schema}. Nothing to delete.\n";
    exit(0);
}
$request = $requests[0];
$requestId = (int) $request['id'];

// Every table that points at this request, its documents or their AICS AI reviews.
$linkedColumns = delete_request_rows($db, "
    SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLUMN_NAME IN ('request_id', 'request_document_id', 'ai_review_id')
      AND TABLE_NAME <> 'aics_requests'
    ORDER BY TABLE_NAME
");
$tablesByColumn = ['ai_review_id' => [], 'request_document_id' => [], 'request_id' => []];
foreach ($linkedColumns as $column) {
    $tablesByColumn[(string) $column['column_name']][] = (string) $column['table_name'];
}

$documentIds = array_map('intval', array_column(delete_request_rows($db, 'SELECT id FROM aics_request_documents WHERE request_id = ?', [$requestId]), 'id'));
$reviewIds = [];
if ($documentIds !== [] && in_array('document_ai_reviews', $tablesByColumn['request_document_id'], true)) {
    $reviewIds = array_map('intval', array_column(delete_request_rows($db, 'SELECT id FROM document_ai_reviews WHERE request_document_id IN (' . delete_request_id_list($documentIds) . ')'), 'id'));
}
$idsByColumn = ['ai_review_id' => $reviewIds, 'request_document_id' => $documentIds, 'request_id' => [$requestId]];

// Children first, the request last: review-level, then document-level, then request-level rows.
$plan = [];
$claimed = [];
foreach (['ai_review_id', 'request_document_id', 'request_id'] as $column) {
    foreach ($tablesByColumn[$column] as $table) {
        if (isset($claimed[$table]) || $idsByColumn[$column] === []) continue;
        $claimed[$table] = true;
        $plan[] = ['table' => $table, 'where' => "`{$column}` IN (" . delete_request_id_list($idsByColumn[$column]) . ')'];
    }
}
// Documents and AI reviews are parents of other rows, so they go after their children.
usort($plan, static function (array $a, array $b): int {
    $rank = static fn (string $table): int => $table === 'aics_request_documents' ? 2 : ($table === 'document_ai_reviews' ? 1 : 0);
    return $rank($a['table']) <=> $rank($b['table']);
});

$tableExists = static fn (string $table): bool => delete_request_rows($db, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== [];

// "Read" marks of this request's notifications (no foreign key, so listed by hand).
if ($tableExists('aics_applicant_notification_reads') && $tableExists('aics_request_notifications')) {
    array_unshift($plan, ['table' => 'aics_applicant_notification_reads', 'where' => "notification_id IN (SELECT id FROM aics_request_notifications WHERE request_id = {$requestId})"]);
}
// Admin audit log entries about this request.
if ($tableExists('aics_admin_audit_logs')) {
    $safeReference = $db->real_escape_string($reference);
    $plan[] = ['table' => 'aics_admin_audit_logs', 'where' => "(JSON_VALUE(context_json, '$.request_id') = '{$requestId}'"
        . " OR event_message REGEXP 'request #{$requestId}([^0-9]|$)'"
        . " OR event_message LIKE '%{$safeReference}%' OR context_json LIKE '%{$safeReference}%')"];
}
$plan[] = ['table' => 'aics_requests', 'where' => "id = {$requestId}"];

$backup = ['reference_no' => $reference, 'database' => $schema, 'saved_at' => date('c'), 'tables' => []];
$name = trim((string) ($request['applicant_first_name'] ?? '') . ' ' . (string) ($request['applicant_last_name'] ?? ''));
echo "Request {$reference} (id {$requestId}) in database {$schema}\n";
echo "  Applicant:   {$name}\n";
echo '  Beneficiary: ' . (string) ($request['beneficiary_complete_name'] ?? '') . "\n";
echo '  Status:      ' . (string) ($request['request_status'] ?? '') . "\n";
echo "Rows to delete:\n";
foreach ($plan as $step) {
    $rows = delete_request_rows($db, "SELECT * FROM `{$step['table']}` WHERE {$step['where']}");
    $backup['tables'][$step['table']] = $rows;
    printf("  %-42s %d\n", $step['table'], count($rows));
}

$uploadRoot = trim((string) getenv('AICS_UPLOAD_ROOT')) ?: __DIR__ . '/../Applicants/uploads';
$privateRoot = trim((string) getenv('AICS_PRIVATE_ROOT')) ?: 'C:\\xampp\\aics_private';
$artifactRoot = trim((string) getenv('AICS_AI_REVIEW_ARTIFACT_ROOT')) ?: $privateRoot . DIRECTORY_SEPARATOR . 'ai_review_artifacts';
$folders = [rtrim($uploadRoot, '/\\') . DIRECTORY_SEPARATOR . $reference];
foreach ($reviewIds as $reviewId) {
    $folders[] = rtrim($artifactRoot, '/\\') . DIRECTORY_SEPARATOR . 'review_' . $reviewId;
}
$folders = array_values(array_filter($folders, 'is_dir'));
echo 'Folders to delete: ' . ($folders === [] ? "none\n" : "\n  " . implode("\n  ", $folders) . "\n");

if (!$confirm) {
    echo "\nPreview only. Nothing was changed. Add --confirm to delete.\n";
    exit(0);
}

$backupDir = rtrim($privateRoot, '/\\') . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'deleted-requests';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    throw new RuntimeException("Cannot create backup folder {$backupDir}.");
}
$backupFile = $backupDir . DIRECTORY_SEPARATOR . $reference . '-' . date('Ymd-His') . '.json';
if (file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
    throw new RuntimeException("Cannot write backup {$backupFile}.");
}
echo "\nBackup saved: {$backupFile}\n";

$db->begin_transaction();
try {
    foreach ($plan as $step) {
        $db->query("DELETE FROM `{$step['table']}` WHERE {$step['where']}");
        printf("  deleted %-34s %d\n", $step['table'], $db->affected_rows);
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollback();
    fwrite(STDERR, 'Delete failed, nothing was removed: ' . $error->getMessage() . "\n");
    exit(1);
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    rmdir($path);
};
foreach ($folders as $folder) {
    $removeTree($folder);
    echo "  deleted folder {$folder}\n";
}
echo "Done. {$reference} is gone; the applicant can submit a new application.\n";
