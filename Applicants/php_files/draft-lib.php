<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/document-requirements.php';

const AICS_DRAFT_TTL_DAYS = 30;

/**
 * Draft uploads live in private storage when it is configured (on Railway it is
 * the persistent volume, so drafts survive a redeploy); otherwise in the temp folder.
 */
function aics_draft_upload_root(): string
{
    $private = trim((string) getenv('AICS_PRIVATE_ROOT'));
    if ($private !== '') return rtrim($private, '\/') . DIRECTORY_SEPARATOR . 'draft_uploads';
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'aics-draft-uploads';
}

function aics_draft_uuid(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function aics_ensure_draft_schema(mysqli $connection): void
{
    $connection->query("CREATE TABLE IF NOT EXISTS aics_application_drafts (
        id CHAR(36) NOT NULL, account_id INT UNSIGNED NOT NULL, payload_json JSON NOT NULL,
        current_step TINYINT UNSIGNED NOT NULL DEFAULT 0, version INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'active', expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_aics_application_drafts_active_account (account_id, status),
        KEY idx_aics_application_drafts_expiry (status, expires_at),
        CONSTRAINT fk_aics_application_drafts_account FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query("CREATE TABLE IF NOT EXISTS aics_application_draft_documents (
        id CHAR(36) NOT NULL, draft_id CHAR(36) NOT NULL, document_type VARCHAR(60) NOT NULL,
        original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_aics_draft_document_type (draft_id, document_type),
        CONSTRAINT fk_aics_draft_documents_draft FOREIGN KEY (draft_id) REFERENCES aics_application_drafts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    aics_expire_drafts($connection);
}

function aics_expire_drafts(mysqli $connection): void
{
    $expired = $connection->query("SELECT id FROM aics_application_drafts WHERE status IN ('active', 'finalizing') AND expires_at < NOW()");
    while ($row = $expired?->fetch_assoc()) {
        aics_delete_draft_files((string) ($row['id'] ?? ''));
    }
    $connection->query("DELETE FROM aics_application_drafts WHERE status IN ('active', 'finalizing') AND expires_at < NOW()");
}

function aics_delete_draft_files(string $draftId): void
{
    if (!preg_match('/^[a-f0-9-]{36}$/i', $draftId)) return;
    $directory = aics_draft_upload_root() . DIRECTORY_SEPARATOR . $draftId;
    if (!is_dir($directory)) return;
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($directory);
}

/** @return array<string,mixed>|null */
function aics_get_active_draft(mysqli $connection, int $accountId): ?array
{
    $stmt = $connection->prepare("SELECT id, payload_json, current_step, version, updated_at FROM aics_application_drafts WHERE account_id = ? AND status = 'active' AND expires_at >= NOW() LIMIT 1");
    $stmt->bind_param('i', $accountId); $stmt->execute();
    $draft = $stmt->get_result()?->fetch_assoc(); $stmt->close();
    if (!is_array($draft)) return null;
    $payload = json_decode((string) ($draft['payload_json'] ?? '{}'), true);
    $draft['data'] = is_array($payload) ? $payload : [];
    unset($draft['payload_json']);
    $docs = $connection->prepare('SELECT id, document_type, original_name, mime_type, file_size FROM aics_application_draft_documents WHERE draft_id = ? ORDER BY created_at ASC');
    $docs->bind_param('s', $draft['id']); $docs->execute();
    $draft['documents'] = $docs->get_result()?->fetch_all(MYSQLI_ASSOC) ?: []; $docs->close();
    return $draft;
}

/** @return array<string,mixed> */
function aics_save_draft(mysqli $connection, int $accountId, ?string $draftId, int $version, int $step, array $data): array
{
    $step = max(0, min(3, $step));
    $json = json_encode(aics_draft_payload($data), JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to save draft data.');
    $active = aics_get_active_draft($connection, $accountId);
    if ($active === null) {
        $id = aics_draft_uuid();
        $stmt = $connection->prepare("INSERT INTO aics_application_drafts (id, account_id, payload_json, current_step, version, status, expires_at) VALUES (?, ?, ?, ?, 1, 'active', DATE_ADD(NOW(), INTERVAL " . AICS_DRAFT_TTL_DAYS . " DAY))");
        $stmt->bind_param('sisi', $id, $accountId, $json, $step); $stmt->execute(); $stmt->close();
        return ['id' => $id, 'version' => 1, 'current_step' => $step];
    }
    if ($draftId !== (string) $active['id'] || $version !== (int) $active['version']) {
        throw new RuntimeException('This draft was updated in another tab. Reload it before saving again.');
    }
    $nextVersion = $version + 1;
    $stmt = $connection->prepare("UPDATE aics_application_drafts SET payload_json = ?, current_step = ?, version = ?, expires_at = DATE_ADD(NOW(), INTERVAL " . AICS_DRAFT_TTL_DAYS . " DAY) WHERE id = ? AND account_id = ? AND version = ? AND status = 'active'");
    $stmt->bind_param('siisii', $json, $step, $nextVersion, $draftId, $accountId, $version); $stmt->execute();
    if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('This draft was updated in another tab. Reload it before saving again.'); }
    $stmt->close(); return ['id' => $draftId, 'version' => $nextVersion, 'current_step' => $step];
}

/** @return array<string,mixed> */
function aics_draft_payload(array $data): array
{
    $allowed = [
        'date_applied',
        'beneficiary_first_name',
        'beneficiary_middle_name',
        'beneficiary_last_name',
        'beneficiary_extension',
        'beneficiary_birth_date',
        'beneficiary_age',
        'beneficiary_complete_name',
        'beneficiary_province',
        'beneficiary_municipality',
        'beneficiary_barangay',
        'beneficiary_sitio_purok',
        'province',
        'municipality',
        'barangay',
        'sitio_purok',
        'applicant_province',
        'applicant_municipality',
        'applicant_barangay',
        'applicant_sitio_purok',
        'applicant_is_beneficiary',
        'contact_number',
        'employment',
        'employment_other',
        'estimated_income',
        'gender',
        'client_category',
        'client_category_other',
        'family_name',
        'family_age',
        'family_relationship',
        'family_occupation',
        'family_occupation_other',
        'family_education',
        'family_estimated_income',
        'assistance_type',
        'assistance_type_other',
        'urgency_response',
        'problem_presented',
        'amount',
        'claim_status',
        'amount_claimed',
        'cheque_date',
    ];
    $clean = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $data)) continue;
        $value = $data[$key];
        $clean[$key] = is_array($value) ? array_map(static fn ($v) => trim((string) $v), $value) : trim((string) $value);
    }
    $checkboxStates = $data['checkbox_states'] ?? null;
    if (is_array($checkboxStates)) {
        $clean['checkbox_states'] = [];
        foreach ($checkboxStates as $name => $checked) {
            $name = (string) $name;
            if (preg_match('/^[a-z][a-z0-9_]{0,100}$/', $name) !== 1) continue;
            $clean['checkbox_states'][$name] = $checked === true || $checked === 1 || $checked === '1' || $checked === 'true';
        }
    }
    return $clean;
}

function aics_discard_draft(mysqli $connection, int $accountId, string $draftId): void
{
    $stmt = $connection->prepare("DELETE FROM aics_application_drafts WHERE id = ? AND account_id = ? AND status IN ('active', 'finalizing')");
    $stmt->bind_param('si', $draftId, $accountId); $stmt->execute();
    if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('Draft not found.'); }
    $stmt->close(); aics_delete_draft_files($draftId);
}

/** @return array<string,array<string,mixed>> */
function aics_draft_documents(mysqli $connection, int $accountId, string $draftId): array
{
    $stmt = $connection->prepare("SELECT d.id, d.document_type, d.original_name, d.stored_name, d.mime_type, d.file_size FROM aics_application_draft_documents d INNER JOIN aics_application_drafts a ON a.id = d.draft_id WHERE d.draft_id = ? AND a.account_id = ? AND a.status IN ('active', 'finalizing')");
    $stmt->bind_param('si', $draftId, $accountId); $stmt->execute(); $result = $stmt->get_result(); $docs = [];
    while ($row = $result?->fetch_assoc()) $docs[(string) $row['document_type']] = $row;
    $stmt->close(); return $docs;
}

/** @param array<string,mixed> $file @return array<string,mixed> */
function aics_store_draft_document(mysqli $connection, int $accountId, string $draftId, string $documentType, array $file): array
{
    $documentType = aics_normalize_document_type($documentType);
    if (!preg_match('/^[a-z0-9_]{2,60}$/', $documentType)) throw new RuntimeException('Invalid document type.');
    $active = aics_get_active_draft($connection, $accountId);
    if (!is_array($active) || (string) ($active['id'] ?? '') !== $draftId || !preg_match('/^[a-f0-9-]{36}$/i', $draftId)) throw new RuntimeException('Draft not found.');
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE); $size = (int) ($file['size'] ?? 0); $tmp = (string) ($file['tmp_name'] ?? '');
    if ($error !== UPLOAD_ERR_OK || $size < 1 || $size > 10485760 || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid upload.');
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $isIdPhoto = in_array($documentType, ['valid_id', 'valid_id_back'], true);
    if (!in_array($extension, $isIdPhoto ? ['jpg','jpeg','png','webp'] : ['pdf','jpg','jpeg','png'], true)) throw new RuntimeException($isIdPhoto ? 'Only JPG, PNG, and WebP ID photos are allowed.' : 'Only PDF, JPG, and PNG files are allowed.');
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, $isIdPhoto ? ['image/jpeg','image/png','image/webp'] : ['application/pdf','image/jpeg','image/png'], true)) throw new RuntimeException('Unsupported file type.');
    if ($isIdPhoto && $mime !== match ($extension) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' }) throw new RuntimeException('ID photo extension and content type do not match.');
    $directory = aics_draft_upload_root() . DIRECTORY_SEPARATOR . $draftId;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to store upload.');
    $existing = aics_draft_documents($connection, $accountId, $draftId)[$documentType] ?? null;
    $stored = $documentType . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $newPath = $directory . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $newPath)) throw new RuntimeException('Unable to store upload.');
    $original = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) ($file['name'] ?? 'document.' . $extension))) ?: 'document.' . $extension;
    $id = is_array($existing) ? (string) $existing['id'] : aics_draft_uuid();
    try {
        $stmt = $connection->prepare('INSERT INTO aics_application_draft_documents (id, draft_id, document_type, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE original_name=VALUES(original_name), stored_name=VALUES(stored_name), mime_type=VALUES(mime_type), file_size=VALUES(file_size)');
        $stmt->bind_param('ssssssi', $id, $draftId, $documentType, $original, $stored, $mime, $size); $stmt->execute(); $stmt->close();
    } catch (Throwable $exception) {
        @unlink($newPath);
        throw $exception;
    }
    if (is_array($existing) && (string) ($existing['stored_name'] ?? '') !== $stored) {
        @unlink($directory . DIRECTORY_SEPARATOR . basename((string) $existing['stored_name']));
    }
    return ['id'=>$id,'document_type'=>$documentType,'original_name'=>$original,'mime_type'=>$mime,'file_size'=>$size];
}

function aics_remove_draft_document(mysqli $connection, int $accountId, string $draftId, string $documentType): void
{
    $documentType = aics_normalize_document_type($documentType);
    if (!preg_match('/^[a-z0-9_]{2,60}$/', $documentType)) throw new RuntimeException('Invalid document type.');
    $documents = aics_draft_documents($connection, $accountId, $draftId);
    $document = $documents[$documentType] ?? null;
    if (!is_array($document)) throw new RuntimeException('Draft document not found.');
    $stmt = $connection->prepare('DELETE FROM aics_application_draft_documents WHERE id = ? AND draft_id = ?');
    $id = (string) $document['id']; $stmt->bind_param('ss', $id, $draftId); $stmt->execute(); $stmt->close();
    @unlink(aics_draft_upload_root() . DIRECTORY_SEPARATOR . $draftId . DIRECTORY_SEPARATOR . (string) $document['stored_name']);
}

function aics_claim_draft_for_submission(mysqli $connection, int $accountId, string $draftId): void
{
    $stmt = $connection->prepare("UPDATE aics_application_drafts SET status = 'finalizing' WHERE id = ? AND account_id = ? AND status = 'active' AND expires_at >= NOW()");
    $stmt->bind_param('si', $draftId, $accountId); $stmt->execute();
    if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('This draft is already being submitted or is no longer available.'); }
    $stmt->close();
}

/** Deletes the claimed draft rows inside the request transaction without touching source files. */
function aics_consume_claimed_draft(mysqli $connection, int $accountId, string $draftId): void
{
    $stmt = $connection->prepare("DELETE FROM aics_application_drafts WHERE id = ? AND account_id = ? AND status = 'finalizing'");
    $stmt->bind_param('si', $draftId, $accountId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Unable to consume the submitted draft.');
    }
    $stmt->close();
}

/**
 * Copies claimed draft files into request storage while the database transaction
 * is open. Source files remain intact until commit, allowing a rollback to
 * restore the draft without losing documents.
 *
 * @param list<string> $allowedDocumentTypes
 * @param list<string> $createdFilePaths
 */
function aics_finalize_draft_documents(mysqli $connection, int $accountId, string $draftId, int $requestId, string $referenceNo, array $allowedDocumentTypes, array &$createdFilePaths = []): int
{
    $documents = aics_draft_documents($connection, $accountId, $draftId);
    if (count($documents) === 0) return 0;
    $targetDirectory = __DIR__ . '/../uploads/' . $referenceNo;
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) throw new RuntimeException('Unable to finalize draft documents.');
    $sourceDirectory = aics_draft_upload_root() . DIRECTORY_SEPARATOR . $draftId;
    $allowedLookup = [];
    foreach ($allowedDocumentTypes as $allowedType) {
        $canonical = aics_normalize_document_type((string) $allowedType);
        if ($canonical !== '') $allowedLookup[$canonical] = true;
    }
    $existingLookup = [];
    $existingStmt = $connection->prepare('SELECT document_type FROM aics_request_documents WHERE request_id = ?');
    $existingStmt->bind_param('i', $requestId); $existingStmt->execute();
    $existingResult = $existingStmt->get_result();
    while ($existingRow = $existingResult?->fetch_assoc()) {
        $canonical = aics_normalize_document_type((string) ($existingRow['document_type'] ?? ''));
        if ($canonical !== '') $existingLookup[$canonical] = true;
    }
    $existingStmt->close();
    $insert = $connection->prepare('INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $count = 0;
    foreach ($documents as $document) {
        $type = aics_normalize_document_type((string) $document['document_type']);
        if ($type === '' || !isset($allowedLookup[$type]) || isset($existingLookup[$type])) continue;
        $source = $sourceDirectory . DIRECTORY_SEPARATOR . (string) $document['stored_name'];
        if (!is_file($source)) throw new RuntimeException('A temporary document is no longer available. Please upload it again.');
        $extension = strtolower(pathinfo((string) $document['stored_name'], PATHINFO_EXTENSION));
        $stored = $type . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $targetDirectory . DIRECTORY_SEPARATOR . $stored;
        if (!copy($source, $target)) throw new RuntimeException('Unable to finalize a temporary document.');
        $createdFilePaths[] = $target;
        $relative = 'AICS/Applicants/uploads/' . $referenceNo . '/' . $stored;
        $original=(string)$document['original_name']; $mime=(string)$document['mime_type']; $size=(int)$document['file_size'];
        $insert->bind_param('isssssi', $requestId, $type, $original, $stored, $relative, $mime, $size); $insert->execute();
        $existingLookup[$type] = true;
        $count++;
    }
    $insert->close(); return $count;
}
