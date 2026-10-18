<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/document-requirements.php';

/**
 * @return array<string, mixed>
 */
function aics_fetch_applicant_dashboard_data(int $accountId, ?string $sessionDisplayName = null): array
{
    $connection = aics_db_connect();

    try {
        aics_ensure_auth_schema($connection);
        aics_ensure_dashboard_schema($connection);

        $profile = aics_dashboard_fetch_profile($connection, $accountId, $sessionDisplayName);
        $requests = aics_dashboard_fetch_requests($connection, $accountId);
        $documentsByRequest = aics_dashboard_fetch_documents_by_request($connection, $accountId);
    } finally {
        $connection->close();
    }

    return aics_dashboard_build_payload($profile, $requests, $documentsByRequest);
}

function aics_dashboard_timezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Asia/Manila');
    }

    return $timezone;
}

function aics_ensure_dashboard_schema(mysqli $connection): void
{
    $queries = [
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_no VARCHAR(32) NOT NULL,
                date_applied DATE NOT NULL,
                applicant_role ENUM('self', 'representative') DEFAULT NULL COMMENT 'Deprecated legacy intake role; no longer used',
                beneficiary_first_name VARCHAR(100) NOT NULL,
                beneficiary_middle_name VARCHAR(100) DEFAULT NULL,
                beneficiary_last_name VARCHAR(100) NOT NULL,
                beneficiary_extension VARCHAR(20) DEFAULT NULL,
                beneficiary_birth_date DATE DEFAULT NULL,
                beneficiary_age TINYINT UNSIGNED NOT NULL,
                beneficiary_complete_name VARCHAR(255) NOT NULL,
                contact_number VARCHAR(15) NOT NULL,
                applicant_first_name VARCHAR(100) NOT NULL,
                applicant_middle_name VARCHAR(100) DEFAULT NULL,
                applicant_last_name VARCHAR(100) NOT NULL,
                applicant_extension VARCHAR(20) DEFAULT NULL,
                applicant_birth_date DATE DEFAULT NULL,
                applicant_age TINYINT UNSIGNED NOT NULL,
                barangay VARCHAR(120) NOT NULL,
                sitio_purok VARCHAR(120) DEFAULT NULL,
                municipality VARCHAR(120) NOT NULL,
                province VARCHAR(120) NOT NULL DEFAULT 'Antique',
                gender ENUM('male', 'female') NOT NULL,
                estimated_income VARCHAR(40) DEFAULT NULL,
                employment VARCHAR(150) DEFAULT NULL,
                civil_status VARCHAR(30) DEFAULT NULL,
                civil_status_other VARCHAR(120) DEFAULT NULL,
                highest_educational_attainment VARCHAR(150) DEFAULT NULL,
                problem_presented TEXT DEFAULT NULL,
                client_category_other VARCHAR(150) DEFAULT NULL,
                assistance_type_other VARCHAR(150) DEFAULT NULL,
                family_composition_json JSON DEFAULT NULL,
                client_categories_json JSON DEFAULT NULL,
                assistance_types_json JSON NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                claim_status ENUM('claimed', 'unclaimed') NOT NULL,
                amount_claimed DECIMAL(12,2) DEFAULT NULL,
                cheque_date DATE DEFAULT NULL,
                request_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_requests_reference_no (reference_no),
                KEY idx_aics_requests_date_applied (date_applied),
                KEY idx_aics_requests_municipality (municipality)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_account_requests (
                account_id INT UNSIGNED NOT NULL,
                request_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, request_id),
                KEY idx_aics_account_requests_request_id (request_id),
                CONSTRAINT fk_aics_account_requests_account_id
                    FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_account_requests_request_id
                    FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
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
        SQL,
    ];

    foreach ($queries as $query) {
        $connection->query($query);
    }

    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS sitio_purok VARCHAR(120) DEFAULT NULL AFTER barangay");
    // Retain historical values without requiring new submissions to supply
    // the obsolete intake-role field.
    $connection->query("ALTER TABLE aics_requests MODIFY COLUMN applicant_role ENUM('self', 'representative') DEFAULT NULL COMMENT 'Deprecated legacy intake role; no longer used'");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_extension VARCHAR(20) DEFAULT NULL AFTER beneficiary_last_name");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_birth_date DATE DEFAULT NULL AFTER beneficiary_extension");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_barangay VARCHAR(120) DEFAULT NULL AFTER beneficiary_complete_name");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_sitio_purok VARCHAR(120) DEFAULT NULL AFTER beneficiary_barangay");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_municipality VARCHAR(120) DEFAULT NULL AFTER beneficiary_sitio_purok");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_province VARCHAR(120) DEFAULT NULL AFTER beneficiary_municipality");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_extension VARCHAR(20) DEFAULT NULL AFTER applicant_last_name");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_birth_date DATE DEFAULT NULL AFTER applicant_extension");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS estimated_income VARCHAR(40) DEFAULT NULL AFTER gender");
    aics_migrate_income_column($connection, 'aics_requests', 'estimated_income');
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS employment VARCHAR(150) DEFAULT NULL AFTER estimated_income");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS civil_status VARCHAR(30) DEFAULT NULL AFTER employment");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS civil_status_other VARCHAR(120) DEFAULT NULL AFTER civil_status");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS highest_educational_attainment VARCHAR(150) DEFAULT NULL AFTER civil_status_other");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS problem_presented TEXT DEFAULT NULL AFTER highest_educational_attainment");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS client_category_other VARCHAR(150) DEFAULT NULL AFTER problem_presented");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS assistance_type_other VARCHAR(150) DEFAULT NULL AFTER client_category_other");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS family_composition_json JSON DEFAULT NULL AFTER assistance_type_other");
    $connection->query("ALTER TABLE aics_request_documents ADD COLUMN IF NOT EXISTS document_type VARCHAR(60) DEFAULT NULL AFTER request_id");
    $connection->query("CREATE INDEX IF NOT EXISTS idx_aics_request_documents_type ON aics_request_documents (document_type)");
}

/**
 * @return array<string, string>
 */
function aics_dashboard_fetch_profile(mysqli $connection, int $accountId, ?string $sessionDisplayName): array
{
    $sql = <<<SQL
        SELECT
            a.email,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.contact_number,
            p.complete_address
        FROM aics_accounts a
        LEFT JOIN aics_applicant_profiles p ON p.account_id = a.id
        WHERE a.id = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $firstName = trim((string) ($row['first_name'] ?? ''));
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    $contactNumber = aics_normalize_mobile_number((string) ($row['contact_number'] ?? '')) ?? '';
    $completeAddress = trim((string) ($row['complete_address'] ?? ''));

    $displayName = trim(implode(' ', array_filter([$firstName, $lastName])));
    if ($displayName === '') {
        $displayName = trim((string) $sessionDisplayName);
    }
    if ($displayName === '') {
        $displayName = 'Applicant';
    }

    return [
        'display_name' => $displayName,
        'initials' => aics_dashboard_initials($displayName),
        'email' => $email,
        'contact_number' => $contactNumber,
        'complete_address' => $completeAddress,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'profile_completion' => aics_applicant_profile_completion_state($connection, $accountId),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function aics_dashboard_fetch_requests(mysqli $connection, int $accountId): array
{
    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.claim_status,
            r.amount,
            r.amount_claimed,
            r.cheque_date,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.barangay,
            r.municipality,
            r.province,
            r.assistance_types_json,
            r.created_at,
            r.updated_at
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
        ORDER BY r.created_at DESC, r.id DESC
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!is_array($row)) {
            break;
        }
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

/**
 * @return array<int, array<int, array<string, mixed>>>
 */
function aics_dashboard_fetch_documents_by_request(mysqli $connection, int $accountId): array
{
    $sql = <<<SQL
        SELECT
            d.request_id,
            d.document_type,
            d.original_name,
            d.file_size,
            d.uploaded_at
        FROM aics_request_documents d
        INNER JOIN aics_account_requests ar ON ar.request_id = d.request_id
        WHERE ar.account_id = ?
        ORDER BY d.uploaded_at DESC, d.id DESC
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $result = $stmt->get_result();

    $documentsByRequest = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!is_array($row)) {
            break;
        }

        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        if (!isset($documentsByRequest[$requestId])) {
            $documentsByRequest[$requestId] = [];
        }

        $documentsByRequest[$requestId][] = $row;
    }

    $stmt->close();
    return $documentsByRequest;
}

/**
 * @param array<string, string> $profile
 * @param array<int, array<string, mixed>> $requests
 * @param array<int, array<int, array<string, mixed>>> $documentsByRequest
 * @return array<string, mixed>
 */
function aics_dashboard_build_payload(array $profile, array $requests, array $documentsByRequest): array
{
    $totalRequests = count($requests);
    $activeRequests = 0;
    $resolvedRequests = 0;
    $activity = [];

    foreach ($requests as $request) {
        $statusMeta = aics_dashboard_status_meta((string) ($request['request_status'] ?? 'pending'));
        if ($statusMeta['is_active']) {
            $activeRequests++;
        } else {
            $resolvedRequests++;
        }

        $requestId = (int) ($request['id'] ?? 0);
        $referenceNo = (string) ($request['reference_no'] ?? '');
        $assistanceTypes = aics_dashboard_decode_list((string) ($request['assistance_types_json'] ?? '[]'));
        $assistanceLabel = aics_dashboard_assistance_label($assistanceTypes);

        $activity[] = [
            'tone' => 'brand',
            'icon' => 'file-lines',
            'title' => 'Request submitted',
            'description' => trim($assistanceLabel . ' request ' . $referenceNo . ' was submitted.'),
            'occurred_at' => aics_dashboard_iso_datetime((string) ($request['created_at'] ?? '')),
            'time_label' => aics_dashboard_datetime_label((string) ($request['created_at'] ?? '')),
        ];

        foreach ($documentsByRequest[$requestId] ?? [] as $document) {
            $documentType = trim((string) ($document['document_type'] ?? ''));
            $documentLabel = $documentType !== ''
                ? aics_dashboard_document_label($documentType)
                : 'Supporting document';

            $activity[] = [
                'tone' => 'info',
                'icon' => 'upload',
                'title' => 'Document uploaded',
                'description' => $documentLabel . ' was attached to ' . $referenceNo . '.',
                'occurred_at' => aics_dashboard_iso_datetime((string) ($document['uploaded_at'] ?? '')),
                'time_label' => aics_dashboard_datetime_label((string) ($document['uploaded_at'] ?? '')),
            ];
        }

        $createdAt = (string) ($request['created_at'] ?? '');
        $updatedAt = (string) ($request['updated_at'] ?? '');
        if ($updatedAt !== '' && $updatedAt !== $createdAt) {
            $activity[] = [
                'tone' => (string) $statusMeta['tone'],
                'icon' => (string) $statusMeta['icon'],
                'title' => 'Status updated',
                'description' => $referenceNo . ' is now marked as ' . $statusMeta['label'] . '.',
                'occurred_at' => aics_dashboard_iso_datetime($updatedAt),
                'time_label' => aics_dashboard_datetime_label($updatedAt),
            ];
        }
    }

    usort(
        $activity,
        static function (array $left, array $right): int {
            return strcmp((string) ($right['occurred_at'] ?? ''), (string) ($left['occurred_at'] ?? ''));
        }
    );

    $latestRequestRow = $requests[0] ?? null;
    $latestRequest = is_array($latestRequestRow)
        ? aics_dashboard_latest_request_payload($latestRequestRow, $documentsByRequest[(int) $latestRequestRow['id']] ?? [])
        : null;

    $actionItems = 0;
    $nextSteps = [];
    $profileIncomplete = !((bool) ($profile['profile_completion']['complete'] ?? false));
    if ($profileIncomplete) {
        $actionItems++;
        $missingProfileFields = (array) ($profile['profile_completion']['missing_fields'] ?? []);
        $nextSteps[] = [
            'title' => 'Complete your profile',
            'description' => 'Missing: ' . implode(', ', $missingProfileFields) . '. Complete these details before submitting a request.',
            'action_label' => 'Open profile',
            'page' => 'profile',
        ];
    }

    if ($latestRequest === null && !$profileIncomplete) {
        $actionItems++;
        $nextSteps[] = [
            'title' => 'Submit your first application',
            'description' => 'Start your AICS intake so your account has an active case to monitor.',
            'action_label' => 'Submit application',
            'page' => 'submit',
        ];
    } elseif ($latestRequest !== null && (bool) ($latestRequest['is_active'] ?? false)) {
            $nextSteps[] = [
                'title' => 'Track your current request',
                'description' => 'Follow the latest progress and coordinate with PSWDO if your status changes.',
                'action_label' => 'View status',
                'page' => 'status',
            ];
    }

    if (count($nextSteps) === 0) {
        $nextSteps[] = [
            'title' => 'Dashboard looks up to date',
            'description' => 'Your latest request and profile details are already in good shape. Keep monitoring for updates.',
            'action_label' => 'View notifications',
            'page' => 'notifications',
        ];
    }

    $recentUpdates = min(count($activity), 9);
    $documentsUploaded = $latestRequest !== null ? (int) $latestRequest['documents_uploaded_count'] : 0;
    $requiredDocuments = $latestRequest !== null ? (int) $latestRequest['required_documents_count'] : 0;

    return [
        'generated_at' => (new DateTimeImmutable('now', aics_dashboard_timezone()))->format(DATE_ATOM),
        'profile' => [
            'display_name' => $profile['display_name'],
            'initials' => $profile['initials'],
            'email' => $profile['email'],
            'contact_number' => $profile['contact_number'],
            'complete_address' => $profile['complete_address'],
            'is_profile_complete' => !$profileIncomplete,
            'missing_profile_fields' => (array) ($profile['profile_completion']['missing_fields'] ?? []),
        ],
        'summary' => [
            'active_requests' => $activeRequests,
            'total_requests' => $totalRequests,
            'resolved_requests' => $resolvedRequests,
            'documents_uploaded' => $documentsUploaded,
            'required_documents' => $requiredDocuments,
            'documents_progress_label' => $requiredDocuments > 0 ? ($documentsUploaded . ' / ' . $requiredDocuments) : '0 / 0',
            'recent_updates' => $recentUpdates,
            'action_items' => $actionItems,
        ],
        'latest_request' => $latestRequest,
        'recent_activity' => array_slice($activity, 0, 6),
        'next_steps' => array_slice($nextSteps, 0, 3),
    ];
}

/**
 * @param array<string, mixed> $request
 * @param array<int, array<string, mixed>> $documents
 * @return array<string, mixed>
 */
function aics_dashboard_latest_request_payload(array $request, array $documents): array
{
    $statusCode = (string) ($request['request_status'] ?? 'pending');
    $statusMeta = aics_dashboard_status_meta($statusCode);
    $assistanceTypes = aics_dashboard_decode_list((string) ($request['assistance_types_json'] ?? '[]'));
    $requiredDocuments = aics_dashboard_required_documents($assistanceTypes);

    $typedUploads = [];
    $genericUploadCount = 0;
    foreach ($documents as $document) {
        $documentType = aics_normalize_document_type((string) ($document['document_type'] ?? ''));
        if ($documentType === '') {
            $genericUploadCount++;
            continue;
        }

        if (!isset($typedUploads[$documentType])) {
            $typedUploads[$documentType] = $document;
        }
    }

    $requiredDocumentStates = [];
    $missingDocuments = [];
    foreach ($requiredDocuments as $documentType => $label) {
        $matched = $typedUploads[$documentType] ?? null;
        if ($matched === null) {
            $missingDocuments[] = $label;
        }

        $requiredDocumentStates[] = [
            'type' => $documentType,
            'label' => $label,
            'uploaded' => $matched !== null,
            'file_name' => $matched !== null ? (string) ($matched['original_name'] ?? '') : '',
            'uploaded_at' => $matched !== null ? aics_dashboard_datetime_label((string) ($matched['uploaded_at'] ?? '')) : '',
        ];
    }

    $typedRequiredMatches = count($requiredDocuments) > 0
        ? count(array_intersect(array_keys($requiredDocuments), array_keys($typedUploads)))
        : 0;
    $uploadedCount = min(max($typedRequiredMatches, count($documents)), count($requiredDocuments));

    return [
        'id' => (int) ($request['id'] ?? 0),
        'reference_no' => (string) ($request['reference_no'] ?? ''),
        'status_code' => $statusCode,
        'status_label' => (string) $statusMeta['label'],
        'status_tone' => (string) $statusMeta['tone'],
        'status_description' => (string) $statusMeta['description'],
        'status_icon' => (string) $statusMeta['icon'],
        'is_active' => (bool) $statusMeta['is_active'],
        'assistance_label' => aics_dashboard_assistance_label($assistanceTypes),
        'assistance_types' => array_map(static fn (string $type): string => aics_dashboard_titleize($type), $assistanceTypes),
        'beneficiary_name' => trim((string) ($request['beneficiary_complete_name'] ?? '')),
        'applicant_name' => trim(implode(' ', array_filter([
            trim((string) ($request['applicant_first_name'] ?? '')),
            trim((string) ($request['applicant_middle_name'] ?? '')),
            trim((string) ($request['applicant_last_name'] ?? '')),
        ]))),
        'barangay' => trim((string) ($request['barangay'] ?? '')),
        'municipality' => trim((string) ($request['municipality'] ?? '')),
        'province' => trim((string) ($request['province'] ?? '')),
        'date_applied_label' => aics_dashboard_date_label((string) ($request['date_applied'] ?? '')),
        'submitted_at_label' => aics_dashboard_datetime_label((string) ($request['created_at'] ?? '')),
        'updated_at_label' => aics_dashboard_datetime_label((string) ($request['updated_at'] ?? '')),
        'claim_status_label' => strtolower((string) ($request['claim_status'] ?? 'unclaimed')) === 'claimed' ? 'Claimed' : 'Unclaimed',
        'amount_label' => aics_dashboard_currency_label((string) ($request['amount'] ?? '0')),
        'amount_claimed_label' => aics_dashboard_currency_label((string) ($request['amount_claimed'] ?? '0')),
        'documents_uploaded_count' => $uploadedCount,
        'generic_upload_count' => $genericUploadCount,
        'required_documents_count' => count($requiredDocuments),
        'documents_progress_label' => $uploadedCount . ' / ' . count($requiredDocuments),
        'missing_documents' => $missingDocuments,
        'required_documents' => $requiredDocumentStates,
    ];
}

/**
 * @param array<int, string> $assistanceTypes
 * @return array<string, string>
 */
function aics_dashboard_required_documents(array $assistanceTypes): array
{
    if (in_array('medical', $assistanceTypes, true)) {
        return array_column(
            array_filter(aics_document_requirements_for('medical'), static fn (array $requirement): bool => $requirement['required']),
            'label',
            'code'
        );
    }
    if (in_array('burial', $assistanceTypes, true)) {
        return array_column(
            array_filter(aics_document_requirements_for('burial'), static fn (array $requirement): bool => $requirement['required']),
            'label',
            'code'
        );
    }
    return [];
}

/**
 * @return array<int, string>
 */
function aics_dashboard_decode_list(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $value) {
        $clean = strtolower(trim((string) $value));
        if ($clean !== '') {
            $items[] = $clean;
        }
    }

    return array_values(array_unique($items));
}

/**
 * @return array<string, scalar>
 */
function aics_dashboard_status_meta(string $status): array
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'pending', 'for_review', 'for validation', 'for_validation' => [
            'label' => 'Pending Review',
            'tone' => 'warning',
            'icon' => 'clock',
            'description' => 'Your request has been received and is waiting for PSWDO review.',
            'is_active' => true,
        ],
        'incomplete_requirements', 'incomplete requirements' => [
            'label' => 'Incomplete Requirements',
            'tone' => 'warning',
            'icon' => 'triangle-exclamation',
            'description' => 'PSWDO marked your request as incomplete. Please check required documents and instructions.',
            'is_active' => true,
        ],
        'for_interview', 'for interview', 'for interview/further screening', 'for interview / further screening' => [
            'label' => 'For Interview',
            'tone' => 'info',
            'icon' => 'calendar-check',
            'description' => 'Your request is ready for office appearance and further screening.',
            'is_active' => true,
        ],
        'under assessment', 'under_assessment', 'assessment', 'in_review' => [
            'label' => 'Under Assessment',
            'tone' => 'info',
            'icon' => 'spinner',
            'description' => 'Your request is currently being assessed by PSWDO personnel.',
            'is_active' => true,
        ],
        'under_review', 'under review' => [
            'label' => 'Under Review',
            'tone' => 'info',
            'icon' => 'spinner',
            'description' => 'Your request is under detailed PSWDO review.',
            'is_active' => true,
        ],
        'approved', 'validated', 'for_release', 'for release' => [
            'label' => 'Approved',
            'tone' => 'success',
            'icon' => 'circle-check',
            'description' => 'Your request has been approved and is moving toward release or distribution.',
            'is_active' => true,
        ],
        'released', 'distributed', 'completed', 'closed' => [
            'label' => 'Released',
            'tone' => 'success',
            'icon' => 'circle-check',
            'description' => 'Your request has reached its release or completion stage.',
            'is_active' => false,
        ],
        'rejected', 'denied', 'not_approved', 'not approved', 'cancelled', 'canceled' => [
            'label' => 'Needs Attention',
            'tone' => 'danger',
            'icon' => 'triangle-exclamation',
            'description' => 'Your request needs follow-up with PSWDO for clarification or next steps.',
            'is_active' => false,
        ],
        default => [
            'label' => aics_dashboard_titleize($normalized === '' ? 'status unavailable' : $normalized),
            'tone' => 'neutral',
            'icon' => 'circle-info',
            'description' => 'Your request status is being updated by PSWDO personnel.',
            'is_active' => true,
        ],
    };
}

/**
 * @param array<int, string> $assistanceTypes
 */
function aics_dashboard_assistance_label(array $assistanceTypes): string
{
    if (count($assistanceTypes) === 0) {
        return 'Assistance request';
    }

    $labels = array_map(static fn (string $type): string => aics_dashboard_titleize($type), $assistanceTypes);
    return implode(', ', $labels);
}

function aics_dashboard_document_label(string $documentType): string
{
    return match (aics_normalize_document_type($documentType)) {
        'certificate_of_indigency' => 'Certificate of Indigency',
        'valid_id' => 'Valid ID',
        'med_cert' => 'Medical Abstract / Medical Certificate',
        'death_cert' => 'Death Certificate',
        default => aics_dashboard_titleize($documentType),
    };
}

function aics_dashboard_currency_label(string $value): string
{
    $number = (float) $value;
    return 'PHP ' . number_format($number, 2);
}

function aics_dashboard_date_label(string $value): string
{
    if ($value === '') {
        return 'Not available';
    }

    try {
        return (new DateTimeImmutable($value, aics_dashboard_timezone()))->format('F d, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function aics_dashboard_datetime_label(string $value): string
{
    if ($value === '') {
        return 'Not available';
    }

    try {
        return (new DateTimeImmutable($value, aics_dashboard_timezone()))->format('F d, Y g:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

function aics_dashboard_iso_datetime(string $value): string
{
    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value, aics_dashboard_timezone()))->format(DATE_ATOM);
    } catch (Throwable $exception) {
        return $value;
    }
}

function aics_dashboard_initials(string $value): string
{
    $parts = preg_split('/\s+/', trim($value)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'AP';
}

function aics_dashboard_titleize(string $value): string
{
    return ucwords(str_replace('_', ' ', trim($value)));
}
