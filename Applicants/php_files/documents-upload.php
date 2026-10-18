<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/document-ai-reviews.php';

const DOC_MAX_UPLOAD_SIZE = 26_214_400; // 25 MB
const DOC_ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const DOC_ALLOWED_MIME_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_documents('Invalid request method.', false);
}

aics_start_session();

if (!aics_is_logged_in_applicat()) {
    redirect_documents('Please log in first.', false);
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
if ($accountId <= 0) {
    redirect_documents('Invalid session.', false);
}

$connection = null;

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    ensure_documents_schema($connection);
    aics_ensure_ai_review_schema($connection);

    $actionMode = strtolower(trim((string) ($_POST['action_mode'] ?? 'full_upload')));
    if ($actionMode === 'reupload_single') {
        $targetRequestId = (int) ($_POST['request_id'] ?? 0);
        $targetDocType = aics_normalize_document_type((string) ($_POST['document_type'] ?? ''));
        if ($targetRequestId <= 0 || $targetDocType === '') {
            throw new RuntimeException('Invalid re-upload request.');
        }
        [$requestId, $referenceNo] = find_owned_request_by_id($connection, $accountId, $targetRequestId);
        if ($requestId <= 0 || $referenceNo === '') {
            throw new RuntimeException('Request not found for re-upload.');
        }

        $uploadDirectory = __DIR__ . '/../uploads/' . $referenceNo;
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Unable to create upload directory.');
        }

        $connection->begin_transaction();
        $normalized = normalize_single_upload($_FILES['reupload_file'] ?? null, $targetDocType);

        // A replacement is a new document record; retain prior review history.

        $savedName = sprintf('%s-%s-%s.%s', $targetDocType, date('YmdHis'), bin2hex(random_bytes(4)), $normalized['extension']);
        $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $savedName;
        $relativePath = 'AICS/Applicants/uploads/' . $referenceNo . '/' . $savedName;
        if (!move_uploaded_file($normalized['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Failed to store file: ' . $normalized['original_name']);
        }

        $insert = $connection->prepare(
            'INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param('isssssi', $requestId, $targetDocType, $normalized['original_name'], $savedName, $relativePath, $normalized['mime_type'], $normalized['size']);
        $insert->execute();
        $newDocId = (int) $connection->insert_id;
        $insert->close();

        if ($newDocId > 0) {
            $reviewStatus = 'pending';
            $resetReview = $connection->prepare(
                'INSERT INTO aics_request_document_reviews (request_document_id, reviewed_by_account_id, is_checked, review_status, remarks, reviewed_at, notified_at)
                 VALUES (?, NULL, 0, ?, ?, CURRENT_TIMESTAMP, NULL)
                 ON DUPLICATE KEY UPDATE is_checked = 0, review_status = VALUES(review_status), remarks = VALUES(remarks), reviewed_at = CURRENT_TIMESTAMP, notified_at = NULL'
            );
            $remarks = 'Applicant re-uploaded this document for PSWDO re-review.';
            $resetReview->bind_param('iss', $newDocId, $reviewStatus, $remarks);
            $resetReview->execute();
            $resetReview->close();
        }

        $connection->commit();
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        aics_queue_ai_reviews_after_commit($connection, $requestId);
        $connection->close();
        redirect_status('Document re-uploaded. Verification is queued for PSWDO review.', true);
    }

    [$requestId, $referenceNo, $assistanceTypes] = find_latest_request($connection, $accountId);

    if ($requestId <= 0 || $referenceNo === '') {
        redirect_documents('Submit a request first before uploading documents.', false);
    }

    $assistanceType = (string) ($assistanceTypes[0] ?? '');
    $requirements = aics_document_requirements_for($assistanceType);
    if (count($requirements) === 0) throw new RuntimeException('This assistance type has no configured document requirements.');

    // Each field name is tied to the persisted slot code, never to its label.
    $requiredMap = [];
    $optionalTypes = [];
    foreach ($requirements as $requirement) {
        $documentType = $requirement['code'];
        $requiredMap['doc_' . $documentType] = $documentType;
        if (!$requirement['required']) {
            $optionalTypes[$documentType] = true;
        }
    }

    $existingTypes = fetch_existing_typed_documents($connection, $requestId);

    $uploadDirectory = __DIR__ . '/../uploads/' . $referenceNo;
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $connection->begin_transaction();

    foreach ($requiredMap as $inputName => $documentType) {
        $uploadedFile = $_FILES[$inputName] ?? null;
        $hasNewUpload = is_array($uploadedFile)
            && isset($uploadedFile['error'])
            && (int) $uploadedFile['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$hasNewUpload) {
            if (!isset($optionalTypes[$documentType]) && !isset($existingTypes[$documentType])) {
                throw new RuntimeException('Missing required file: ' . format_document_type($documentType));
            }
            continue;
        }

        $normalized = normalize_single_upload($uploadedFile, $documentType);

        // A replacement is a new document record; retain prior review history.

        $savedName = sprintf(
            '%s-%s-%s.%s',
            $documentType,
            date('YmdHis'),
            bin2hex(random_bytes(4)),
            $normalized['extension']
        );

        $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $savedName;
        $relativePath = 'AICS/Applicants/uploads/' . $referenceNo . '/' . $savedName;

        if (!move_uploaded_file($normalized['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Failed to store file: ' . $normalized['original_name']);
        }

        $insert = $connection->prepare(
            'INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param(
            'isssssi',
            $requestId,
            $documentType,
            $normalized['original_name'],
            $savedName,
            $relativePath,
            $normalized['mime_type'],
            $normalized['size']
        );
        $insert->execute();
        $insert->close();
    }

    $connection->commit();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    aics_queue_ai_reviews_after_commit($connection, $requestId);
    $connection->close();

    redirect_documents('Documents uploaded successfully. Verification is queued for PSWDO review.', true);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        try {
            $connection->rollback();
            $connection->close();
        } catch (Throwable $innerException) {
            // no-op
        }
    }

    redirect_documents($exception->getMessage(), false);
}

function redirect_documents(string $message, bool $success): void
{
    $status = $success ? 'success' : 'error';
    $location = '../dashboard.php?page=documents&upload=' . urlencode($status) . '&message=' . urlencode($message);
    header('Location: ' . $location);
    exit;
}

function redirect_status(string $message, bool $success): void
{
    $status = $success ? 'success' : 'error';
    $location = '../dashboard.php?page=status&upload=' . urlencode($status) . '&message=' . urlencode($message);
    header('Location: ' . $location);
    exit;
}

function ensure_documents_schema(mysqli $connection): void
{
    $connection->query(
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_documents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(60) DEFAULT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_request_documents_request_id (request_id),
                KEY idx_aics_request_documents_type (document_type),
                CONSTRAINT fk_aics_request_documents_request_id
                    FOREIGN KEY (request_id) REFERENCES aics_requests(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $connection->query("ALTER TABLE aics_request_documents ADD COLUMN IF NOT EXISTS document_type VARCHAR(60) DEFAULT NULL AFTER request_id");
    $connection->query("CREATE INDEX IF NOT EXISTS idx_aics_request_documents_type ON aics_request_documents (document_type)");
}

/**
 * @return array{0:int,1:string,2:array<int,string>}
 */
function find_latest_request(mysqli $connection, int $accountId): array
{
    $sql = <<<SQL
        SELECT r.id, r.reference_no, r.assistance_types_json
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
        ORDER BY r.id DESC
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return [0, '', []];
    }

    $assistanceJson = (string) ($row['assistance_types_json'] ?? '[]');
    $decoded = json_decode($assistanceJson, true);
    $assistanceTypes = is_array($decoded)
        ? array_values(array_filter(array_map(static fn ($value) => strtolower(trim((string) $value)), $decoded)))
        : [];

    return [
        (int) ($row['id'] ?? 0),
        (string) ($row['reference_no'] ?? ''),
        $assistanceTypes,
    ];
}

/**
 * @return array{0:int,1:string}
 */
function find_owned_request_by_id(mysqli $connection, int $accountId, int $requestId): array
{
    $stmt = $connection->prepare(
        'SELECT r.id, r.reference_no
         FROM aics_requests r
         INNER JOIN aics_account_requests ar ON ar.request_id = r.id
         WHERE ar.account_id = ? AND r.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $accountId, $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return [0, ''];
    }
    return [(int) ($row['id'] ?? 0), (string) ($row['reference_no'] ?? '')];
}

/**
 * @return array<string, bool>
 */
function fetch_existing_typed_documents(mysqli $connection, int $requestId): array
{
    $stmt = $connection->prepare('SELECT DISTINCT document_type FROM aics_request_documents WHERE request_id = ? AND document_type IS NOT NULL');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();

    $types = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!is_array($row)) {
            break;
        }
        $type = aics_normalize_document_type((string) ($row['document_type'] ?? ''));
        if ($type !== '') {
            $types[$type] = true;
        }
    }

    $stmt->close();
    return $types;
}

function determine_case_specific_type(array $assistanceTypes): ?string
{
    if (in_array('medical', $assistanceTypes, true)) {
        return 'medical_certificate';
    }
    if (in_array('burial', $assistanceTypes, true)) {
        return 'death_certificate';
    }
    return null;
}

function format_document_type(string $type): string
{
    $prototypeLabel = aics_document_requirement_label($type);
    if ($prototypeLabel !== ucwords(str_replace('_', ' ', $type))) {
        return $prototypeLabel;
    }

    $map = [
        'valid_id' => 'Valid ID',
    ];

    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/**
 * @param mixed $uploaded
 * @return array{original_name:string,tmp_name:string,size:int,mime_type:string,extension:string}
 */
function normalize_single_upload($uploaded, string $documentType): array
{
    if (!is_array($uploaded)) {
        throw new RuntimeException('Invalid upload payload for ' . format_document_type($documentType));
    }

    $name = trim((string) ($uploaded['name'] ?? ''));
    $tmpName = (string) ($uploaded['tmp_name'] ?? '');
    $error = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
    $size = (int) ($uploaded['size'] ?? 0);

    if ($error !== UPLOAD_ERR_OK || $name === '') {
        throw new RuntimeException('Upload failed for ' . format_document_type($documentType));
    }
    if ($size <= 0 || $size > DOC_MAX_UPLOAD_SIZE) {
        throw new RuntimeException(format_document_type($documentType) . ' exceeds the 25 MB limit.');
    }

    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, DOC_ALLOWED_EXTENSIONS, true)) {
        throw new RuntimeException('Please upload this document as PDF, JPG, or PNG.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    if (!in_array($mimeType, DOC_ALLOWED_MIME_TYPES, true)) {
        throw new RuntimeException('Unsupported file type for ' . format_document_type($documentType));
    }
    $extensionMimeMap = [
        'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
    if (!in_array($mimeType, $extensionMimeMap[$extension] ?? [], true)) {
        throw new RuntimeException('File extension and content type do not match.');
    }

    return [
        'original_name' => sanitize_filename($name),
        'tmp_name' => $tmpName,
        'size' => $size,
        'mime_type' => $mimeType,
        'extension' => $extension,
    ];
}

function sanitize_filename(string $name): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9._\-\s]/', '', $name);
    $trimmed = trim((string) $sanitized);
    return $trimmed !== '' ? $trimmed : 'document';
}

function delete_existing_file(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    $projectRoot = dirname(__DIR__, 3);
    $absolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}
