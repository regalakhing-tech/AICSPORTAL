<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$action = (string) ($argv[1] ?? '');
$database = (string) ($argv[2] ?? '');
if (preg_match('/^aics_structured_e2e_[a-z0-9_]+$/', $database) !== 1) {
    throw new RuntimeException('Unsafe E2E database name.');
}

function e2e_server(): mysqli
{
    $server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
    $server->set_charset('utf8mb4');
    return $server;
}

function e2e_connection(string $database): mysqli
{
    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    return $connection;
}

if ($action === 'setup') {
    $relativeUpload = trim((string) ($argv[3] ?? ''), '/\\');
    if (preg_match('#^Applicants/uploads/AICS-E2E-[A-Z0-9-]+$#', str_replace('\\', '/', $relativeUpload)) !== 1) {
        throw new RuntimeException('Unsafe E2E upload path.');
    }
    $server = e2e_server();
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->close();
    $connection = e2e_connection($database);
    $connection->query('CREATE TABLE aics_roles (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, role_code VARCHAR(30) NOT NULL, role_name VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $connection->query('CREATE TABLE aics_accounts (id INT UNSIGNED NOT NULL PRIMARY KEY, role_id TINYINT UNSIGNED NOT NULL, CONSTRAINT fk_e2e_role FOREIGN KEY (role_id) REFERENCES aics_roles(id)) ENGINE=InnoDB');
    $connection->query("CREATE TABLE aics_requests (
        id INT UNSIGNED NOT NULL PRIMARY KEY, reference_no VARCHAR(32) NOT NULL,
        beneficiary_complete_name VARCHAR(255) NOT NULL, beneficiary_birth_date DATE NULL,
        applicant_first_name VARCHAR(100) NULL, applicant_middle_name VARCHAR(100) NULL,
        applicant_last_name VARCHAR(100) NULL, applicant_birth_date DATE NULL,
        gender VARCHAR(20) NULL, beneficiary_barangay VARCHAR(120) NULL, beneficiary_sitio_purok VARCHAR(120) NULL, beneficiary_municipality VARCHAR(120) NULL, beneficiary_province VARCHAR(120) NULL, barangay VARCHAR(120) NULL, sitio_purok VARCHAR(120) NULL,
        municipality VARCHAR(120) NULL, province VARCHAR(120) NULL, assistance_types_json JSON NOT NULL
    ) ENGINE=InnoDB");
    $connection->query("CREATE TABLE aics_request_documents (
        id INT UNSIGNED NOT NULL PRIMARY KEY, request_id INT UNSIGNED NOT NULL,
        document_type VARCHAR(100) NOT NULL, original_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL, mime_type VARCHAR(100) NOT NULL,
        CONSTRAINT fk_e2e_request FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $connection->query("INSERT INTO aics_roles VALUES (1, 'pswdo', 'PSWDO Personnel')");
    $connection->query('INSERT INTO aics_accounts VALUES (1, 1)');
    $connection->query("INSERT INTO aics_requests (id, reference_no, beneficiary_complete_name, beneficiary_birth_date, applicant_first_name, applicant_middle_name, applicant_last_name, applicant_birth_date, gender, beneficiary_barangay, beneficiary_municipality, beneficiary_province, barangay, municipality, province, assistance_types_json) VALUES (100, 'AICS-E2E-STRUCTURED', 'Juan Dela Cruz', '1950-01-02', 'Test', NULL, 'Operator', '1990-01-01', 'male', 'Poblacion 4', 'San Jose de Buenavista', 'Antique', 'Poblacion 4', 'San Jose de Buenavista', 'Antique', '[\"medical\"]')");
    $relativeUpload = str_replace('\\', '/', $relativeUpload);
    $stmt = $connection->prepare('INSERT INTO aics_request_documents (id, request_id, document_type, original_name, file_path, mime_type) VALUES (?, 100, ?, ?, ?, "application/pdf")');
    foreach ([
        [101, 'valid_id', 'valid-id.pdf'],
        [102, 'med_cert', 'medical-certificate.pdf'],
        [103, 'billing_statement', 'billing-statement.pdf'],
    ] as [$id, $type, $name]) {
        $path = $relativeUpload . '/' . $name;
        $stmt->bind_param('isss', $id, $type, $name, $path);
        $stmt->execute();
    }
    $stmt->close();
    aics_ensure_ai_review_schema($connection);
    foreach ([[101, 'valid_id'], [102, 'med_cert'], [103, 'billing_statement']] as [$documentId, $type]) {
        aics_create_ai_review($connection, $documentId, $type);
    }
    $connection->close();
    echo "setup\n";
    exit(0);
}

if ($action === 'reprocess') {
    $documentId = (int) ($argv[3] ?? 0);
    $connection = e2e_connection($database);
    $stmt = $connection->prepare('SELECT id FROM document_ai_reviews WHERE request_document_id = ? LIMIT 1');
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $reviewId = (int) ($stmt->get_result()?->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    $result = aics_reprocess_ai_review($connection, $reviewId, 1, 100);
    $connection->close();
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($action === 'inspect') {
    $connection = e2e_connection($database);
    $reviews = [];
    $result = $connection->query('SELECT id, request_document_id, status, expected_document_type, detected_document_type, confidence_score, extracted_fields, findings, last_error_code, last_error_message, processed_at FROM document_ai_reviews ORDER BY request_document_id');
    while ($row = $result->fetch_assoc()) {
        $row['extracted_fields'] = $row['extracted_fields'] ? json_decode((string) $row['extracted_fields'], true) : null;
        $row['findings'] = $row['findings'] ? json_decode((string) $row['findings'], true) : null;
        $reviews[] = $row;
    }
    $history = [];
    $result = $connection->query('SELECT id, ai_review_id, request_document_id, previous_status, previous_confidence_score, previous_extracted_fields, previous_findings, previous_error_code, previous_error_message, archive_reason, archived_at FROM document_ai_review_history ORDER BY id');
    while ($row = $result->fetch_assoc()) {
        $row['previous_extracted_fields'] = $row['previous_extracted_fields'] ? json_decode((string) $row['previous_extracted_fields'], true) : null;
        $row['previous_findings'] = $row['previous_findings'] ? json_decode((string) $row['previous_findings'], true) : null;
        $history[] = $row;
    }
    $connection->close();
    echo json_encode(['reviews' => $reviews, 'history' => $history], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($action === 'cleanup') {
    $server = e2e_server();
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
    echo "cleanup\n";
    exit(0);
}

throw new RuntimeException('Unsupported E2E action.');
