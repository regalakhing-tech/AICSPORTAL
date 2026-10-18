<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function recovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recovery_drop_database(mysqli $server, string $database): void
{
    recovery_assert(
        preg_match('/^aics_recovery_test_[a-z0-9_]+$/', $database) === 1,
        'Refusing to drop an unexpected database name.'
    );
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
}

/**
 * @param list<string> $requiredColumns
 */
function recovery_assert_columns(mysqli $connection, string $table, array $requiredColumns): void
{
    $stmt = $connection->prepare(
        'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $actualColumns = [];
    while ($row = $result->fetch_assoc()) {
        $actualColumns[] = (string) $row['column_name'];
    }
    $stmt->close();

    foreach ($requiredColumns as $column) {
        recovery_assert(in_array($column, $actualColumns, true), 'Missing canonical column: ' . $table . '.' . $column);
    }
}

$suffix = strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$schemaDatabase = 'aics_recovery_test_schema_' . $suffix;
$authDatabase = 'aics_recovery_test_auth_' . $suffix;
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');

try {
    foreach ([$schemaDatabase, $authDatabase] as $database) {
        $server->query(
            'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    $schema = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $schemaDatabase, AICS_DB_PORT);
    $schema->set_charset('utf8mb4');
    $sql = (string) file_get_contents(__DIR__ . '/../database/AICSdb.sql');
    $sql = preg_replace('/^CREATE DATABASE[^;]+;\s*/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^USE\s+`?AICSdb`?;\s*/mi', '', $sql) ?? $sql;
    recovery_assert($schema->multi_query($sql), 'Canonical schema import failed: ' . $schema->error);
    do {
        if ($result = $schema->store_result()) {
            $result->free();
        }
    } while ($schema->more_results() && $schema->next_result());
    recovery_assert($schema->errno === 0, 'Canonical schema import failed: ' . $schema->error);

    $requiredTables = [
        'aics_accounts',
        'aics_applicant_profiles',
        'aics_requests',
        'aics_request_prescreening',
        'aics_request_release_schedule',
        'aics_admin_login_attempts',
        'aics_admin_audit_logs',
        'aics_admin_backup_runs',
        'aics_admin_maintenance_checks',
        'aics_admin_flow_steps',
        'aics_admin_exception_flows',
        'document_ai_reviews',
        'document_ai_review_history',
    ];
    foreach ($requiredTables as $table) {
        $stmt = $schema->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        recovery_assert($total === 1, 'Missing canonical table: ' . $table);
    }

    $releaseAmount = $schema->query(
        "SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aics_request_release_schedule' AND column_name = 'amount_given'"
    )?->fetch_assoc();
    recovery_assert((int) ($releaseAmount['total'] ?? 0) === 1, 'Missing release amount column.');

    recovery_assert_columns($schema, 'aics_requests', [
        'beneficiary_extension',
        'beneficiary_birth_date',
        'beneficiary_barangay',
        'beneficiary_sitio_purok',
        'beneficiary_municipality',
        'beneficiary_province',
        'applicant_extension',
        'applicant_birth_date',
        'applicant_contact_number',
        'applicant_email',
        'applicant_occupation',
        'applicant_estimated_monthly_income',
        'estimated_income',
        'employment',
        'civil_status',
        'civil_status_other',
        'highest_educational_attainment',
        'problem_presented',
        'client_category_other',
        'assistance_type_other',
        'family_composition_json',
        'urgency_response',
        'urgency_indicator',
    ]);
    recovery_assert_columns($schema, 'aics_request_document_reviews', ['review_status', 'notified_at']);
    recovery_assert_columns($schema, 'aics_request_prioritization', ['urgency_indicator']);
    $documentSlotIndex = $schema->query(
        "SELECT COUNT(DISTINCT column_name) AS total FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'aics_request_documents' AND index_name = 'uq_aics_request_documents_request_type' AND non_unique = 0"
    )?->fetch_assoc();
    recovery_assert((int) ($documentSlotIndex['total'] ?? 0) === 2, 'Missing submitted-document request/slot uniqueness constraint.');
    recovery_assert_columns($schema, 'document_ai_review_history', [
        'ai_review_id', 'request_document_id', 'previous_status', 'previous_extracted_fields',
        'previous_findings', 'archive_reason', 'archived_by_account_id', 'archived_at',
    ]);

    $accountColumns = [];
    $accountColumnResult = $schema->query(
        "SELECT column_name, is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aics_accounts'"
    );
    while ($column = $accountColumnResult->fetch_assoc()) {
        $accountColumns[(string) $column['column_name']] = (string) $column['is_nullable'];
    }
    $accountColumnResult->free();
    recovery_assert(($accountColumns['email'] ?? '') === 'YES', 'Canonical applicant email must be nullable.');
    recovery_assert(isset($accountColumns['session_invalidate_at']), 'Missing session invalidation column.');
    recovery_assert(isset($accountColumns['force_password_change']), 'Missing password-change flag column.');
    $schema->close();

    $auth = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $authDatabase, AICS_DB_PORT);
    $auth->set_charset('utf8mb4');
    aics_ensure_auth_schema($auth);
    $emailColumn = $auth->query(
        "SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aics_accounts' AND column_name = 'email'"
    )?->fetch_assoc();
    recovery_assert(($emailColumn['IS_NULLABLE'] ?? '') === 'YES', 'Applicant email must be nullable.');
    foreach (['session_invalidate_at', 'force_password_change'] as $columnName) {
        $stmt = $auth->prepare(
            "SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aics_accounts' AND column_name = ?"
        );
        $stmt->bind_param('s', $columnName);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        recovery_assert($total === 1, 'Missing auth account column: ' . $columnName);
    }
    $auth->close();

    echo "Database schema regression test passed.\n";
} finally {
    recovery_drop_database($server, $authDatabase);
    recovery_drop_database($server, $schemaDatabase);
    $server->close();
}
