<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';

admin_set_security_headers(false);

$runId = (int) ($_GET['id'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    echo 'Invalid backup id.';
    exit;
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $sql = <<<SQL
        SELECT id, file_name, file_path, status
        FROM aics_admin_backup_runs
        WHERE id = ?
        LIMIT 1
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $run = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!is_array($run) || (string) ($run['status'] ?? '') !== 'success') {
        $connection->close();
        http_response_code(404);
        echo 'Backup file not found.';
        exit;
    }

    $fileName = (string) ($run['file_name'] ?? '');
    $filePath = (string) ($run['file_path'] ?? '');

    $backupDir = admin_backup_directory();
    if (
        $fileName === '' ||
        $filePath === '' ||
        !is_file($filePath) ||
        !admin_path_is_within($filePath, $backupDir)
    ) {
        $connection->close();
        http_response_code(404);
        echo 'Backup file unavailable.';
        exit;
    }

    admin_log_event(
        $connection,
        'backup_downloaded',
        'info',
        'Administrator downloaded a backup file.',
        $accountId,
        null,
        ['run_id' => $runId, 'file_name' => $fileName]
    );
    $connection->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: ('backup-' . $runId . '.json');
    $fileSize = (int) filesize($filePath);

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . $fileSize);
    header('X-Download-Options: noopen');
    readfile($filePath);
    exit;
} catch (Throwable) {
    http_response_code(500);
    echo 'Unable to process backup download.';
    exit;
}
