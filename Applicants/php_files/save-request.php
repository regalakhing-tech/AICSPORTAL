<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/draft-lib.php';
require_once __DIR__ . '/application-rules.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/document-ai-reviews.php';
require_once __DIR__ . '/../../includes/beneficiary-priority.php';

const MAX_UPLOAD_SIZE = 26_214_400; // 25 MB
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const ALLOWED_MIME_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.', 405);
}

$connection = null;
$createdSubmissionFiles = [];
$obsoleteSubmissionFiles = [];

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    $authenticatedAccount = aics_authenticated_account_for_role($connection, 'applicat');
    if (!is_array($authenticatedAccount)) {
        respond_json(false, 'Unauthorized. Please log in.', 401);
    }
    $accountId = (int) $authenticatedAccount['id'];

    $isEditMode = ((string) ($_POST['edit_mode'] ?? '0')) === '1';
    $editRequestId = (int) ($_POST['edit_request_id'] ?? 0);

    aics_ensure_draft_schema($connection);
    ensure_tables_exist($connection);
    $draftId = trim((string) ($_POST['draft_id'] ?? ''));
    if ($draftId !== '') {
        $activeDraft = aics_get_active_draft($connection, $accountId);
        if (!is_array($activeDraft) || !hash_equals((string) $activeDraft['id'], $draftId)) {
            throw new RuntimeException('Draft not found or no longer available.');
        }
    }
    $draftDocuments = $draftId !== '' ? aics_draft_documents($connection, $accountId, $draftId) : [];

    $profileCompletion = aics_applicant_profile_completion_state($connection, $accountId);
    if (!$profileCompletion['complete']) {
        throw new RuntimeException('Your profile is incomplete. Complete the required profile information before submitting an application. Missing: ' . implode(', ', $profileCompletion['missing_fields']) . '.');
    }

    $editContext = null;
    if ($isEditMode) {
        if ($editRequestId <= 0) {
            throw new RuntimeException('Invalid request selected for editing.');
        }
        $editGrantExpiry = (int) ($_SESSION['aics_edit_request_grants'][(string) $editRequestId] ?? 0);
        if ($editGrantExpiry < time()) {
            throw new RuntimeException('This edit session is no longer authorized. Open the application from Manage Applications and try again.');
        }
        $editContext = load_edit_request_context($connection, $accountId, $editRequestId);
    } else {
        $existingRequest = load_latest_active_account_request_summary($connection, $accountId);
        if (is_array($existingRequest)) {
            $referenceNo = trim((string) ($existingRequest['reference_no'] ?? ''));
            $statusCode = strtolower(trim((string) ($existingRequest['request_status'] ?? 'pending')));
            $nonEditableStatuses = [
                'approved',
                'validated',
                'for_release',
                'for release',
                'released',
                'distributed',
                'completed',
                'closed',
                'rejected',
                'denied',
                'not_approved',
                'not approved',
                'cancelled',
                'canceled',
            ];
            $canEdit = !in_array($statusCode, $nonEditableStatuses, true);
            $message = $referenceNo !== ''
                ? ('You already submitted request ' . $referenceNo . '. ')
                : 'You already submitted a request. ';
            $message .= $canEdit
                ? 'Please edit your current request instead of creating a new one.'
                : 'Please wait for PSWDO notification if there are document issues.';
            throw new RuntimeException($message);
        }
    }

    // Applicant identity and address are account-profile data, never intake
    // data. Replace all client-supplied values before validating or saving so
    // beneficiary POST/session/draft values cannot cross into applicant fields.
    $requestPost = apply_authenticated_profile_to_request(
        $_POST,
        $connection,
        $accountId,
        $isEditMode ? $editRequestId : null
    );
    // The application date belongs to the server, not the browser. New
    // requests receive today's configured local application date; edits retain
    // the existing historical date even if a client manipulates its POST body.
    $requestPost['date_applied'] = $isEditMode && is_array($editContext)
        ? (string) ($editContext['date_applied'] ?? '')
        : aics_current_application_date();
    $payload = validate_request_payload($requestPost, $_FILES, $isEditMode, $editContext, $draftDocuments);
    if (!$isEditMode) {
        enforce_application_cooldown($connection, $accountId);
    }

    if (
        $isEditMode &&
        is_array($editContext) &&
        count((array) ($editContext['locked_document_types'] ?? [])) > 0 &&
        (string) ($editContext['original_assistance_type'] ?? '') !== '' &&
        (string) ($payload['assistance_type'][0] ?? '') !== (string) $editContext['original_assistance_type']
    ) {
        throw new RuntimeException('Assistance type cannot be changed while PSWDO-checked documents are locked.');
    }

    $connection->begin_transaction();

    if (!$isEditMode && $draftId !== '') {
        // A draft can be claimed only once. This stops a retry or second tab
        // from creating a duplicate official request during final submission.
        aics_claim_draft_for_submission($connection, $accountId, $draftId);
    }

    if ($isEditMode && is_array($editContext)) {
        $requestId = (int) ($editContext['request_id'] ?? 0);
        $referenceNo = (string) ($editContext['reference_no'] ?? '');
        if ($requestId <= 0 || $referenceNo === '') {
            throw new RuntimeException('Invalid request context for edit mode.');
        }

        $clientCategoriesJson = json_encode($payload['client_category'], JSON_UNESCAPED_UNICODE);
        $assistanceTypesJson = json_encode($payload['assistance_type'], JSON_UNESCAPED_UNICODE);
        if ($clientCategoriesJson === false || $assistanceTypesJson === false) {
            throw new RuntimeException('Unable to encode request data.');
        }

        $amountClaimed = $payload['amount_claimed'];
        $chequeDate = $payload['cheque_date'];

        $updateRequestSql = <<<SQL
            UPDATE aics_requests
            SET
                date_applied = ?,
                beneficiary_first_name = ?,
                beneficiary_middle_name = ?,
                beneficiary_last_name = ?,
                beneficiary_extension = ?,
                beneficiary_birth_date = ?,
                beneficiary_age = ?,
                beneficiary_complete_name = ?,
                contact_number = ?,
                applicant_first_name = ?,
                applicant_middle_name = ?,
                applicant_last_name = ?,
                applicant_extension = ?,
                applicant_birth_date = ?,
                applicant_age = ?,
                barangay = ?,
                sitio_purok = ?,
                municipality = ?,
                province = ?,
                gender = ?,
                estimated_income = ?,
                employment = ?,
                civil_status = ?,
                civil_status_other = ?,
                highest_educational_attainment = ?,
                problem_presented = ?,
                client_category_other = ?,
                assistance_type_other = ?,
                family_composition_json = ?,
                client_categories_json = ?,
                assistance_types_json = ?,
                amount = ?,
                claim_status = ?,
                amount_claimed = ?,
                cheque_date = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
            LIMIT 1
        SQL;

        $updateRequest = $connection->prepare($updateRequestSql);
        if (!$updateRequest) {
            throw new RuntimeException('Unable to prepare request update.');
        }

        $beneficiaryAge = (string) $payload['beneficiary_age'];
        $applicantAge = (string) $payload['applicant_age'];
        $beneficiaryBirthDate = $payload['beneficiary_birth_date'];
        $applicantBirthDate = $payload['applicant_birth_date'];
        $estimatedIncome = $payload['estimated_income'];
        $familyCompositionJson = json_encode($payload['family_composition'], JSON_UNESCAPED_UNICODE);
        $amount = (string) $payload['amount'];
        $requestIdString = (string) $requestId;
        if ($familyCompositionJson === false) {
            throw new RuntimeException('Unable to encode family composition.');
        }

        $updateRequest->bind_param(
            str_repeat('s', 36),
            $payload['date_applied'],
            $payload['beneficiary_first_name'],
            $payload['beneficiary_middle_name'],
            $payload['beneficiary_last_name'],
            $payload['beneficiary_extension'],
            $beneficiaryBirthDate,
            $beneficiaryAge,
            $payload['beneficiary_complete_name'],
            $payload['contact_number'],
            $payload['applicant_first_name'],
            $payload['applicant_middle_name'],
            $payload['applicant_last_name'],
            $payload['applicant_extension'],
            $applicantBirthDate,
            $applicantAge,
            $payload['barangay'],
            $payload['sitio_purok'],
            $payload['municipality'],
            $payload['province'],
            $payload['gender'],
            $estimatedIncome,
            $payload['employment'],
            $payload['civil_status'],
            $payload['civil_status_other'],
            $payload['highest_educational_attainment'],
            $payload['problem_presented'],
            $payload['client_category_other'],
            $payload['assistance_type_other'],
            $familyCompositionJson,
            $clientCategoriesJson,
            $assistanceTypesJson,
            $amount,
            $payload['claim_status'],
            $amountClaimed,
            $chequeDate,
            $requestIdString
        );
        $updateRequest->execute();
        $updateRequest->close();

        persist_applicant_request_snapshot($connection, $requestId, $payload);
        persist_beneficiary_address_snapshot($connection, $requestId, $payload);
        persist_structured_priority_and_urgency($connection, $requestId, $payload);

        sync_request_assistance_mapping($connection, $requestId, (string) ($payload['assistance_type'][0] ?? ''));
        record_possible_duplicate_warning($connection, $requestId, $accountId);
        $savedDocuments = save_uploaded_documents(
            $connection,
            $payload['documents'],
            $requestId,
            $referenceNo,
            (array) ($editContext['existing_docs_by_type'] ?? []),
            $createdSubmissionFiles,
            $obsoleteSubmissionFiles
        );

        $connection->commit();
        unset($_SESSION['aics_edit_request_grants'][(string) $requestId]);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        aics_queue_ai_reviews_after_commit($connection, $requestId);
        $connection->close();
        aics_remove_submission_files($obsoleteSubmissionFiles);

        respond_json(true, 'Your application changes have been saved successfully.', 200, [
            'reference_no' => $referenceNo,
            'documents_saved' => $savedDocuments,
            'mode' => 'edit',
        ]);
    }

    $referenceNo = generate_reference_no($connection);

    $insertRequestSql = <<<SQL
        INSERT INTO aics_requests (
            reference_no, date_applied,
            beneficiary_first_name, beneficiary_middle_name, beneficiary_last_name, beneficiary_extension, beneficiary_birth_date, beneficiary_age,
            beneficiary_complete_name, contact_number,
            applicant_first_name, applicant_middle_name, applicant_last_name, applicant_extension, applicant_birth_date, applicant_age,
            barangay, sitio_purok, municipality, province,
            gender, estimated_income, employment, civil_status, civil_status_other,
            highest_educational_attainment, problem_presented, client_category_other, assistance_type_other,
            family_composition_json, client_categories_json, assistance_types_json,
            amount, claim_status, amount_claimed, cheque_date, request_status
        ) VALUES (
            ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, 'pending'
        )
    SQL;

    $insertRequest = $connection->prepare($insertRequestSql);
    if (!$insertRequest) {
        throw new RuntimeException('Unable to prepare request insert.');
    }

    $clientCategoriesJson = json_encode($payload['client_category'], JSON_UNESCAPED_UNICODE);
    $assistanceTypesJson = json_encode($payload['assistance_type'], JSON_UNESCAPED_UNICODE);
    $familyCompositionJson = json_encode($payload['family_composition'], JSON_UNESCAPED_UNICODE);
    if ($clientCategoriesJson === false || $assistanceTypesJson === false || $familyCompositionJson === false) {
        throw new RuntimeException('Unable to encode request data.');
    }

    $insertRequest->bind_param(
        str_repeat('s', 32) . 'dsss',
        $referenceNo,
        $payload['date_applied'],
        $payload['beneficiary_first_name'],
        $payload['beneficiary_middle_name'],
        $payload['beneficiary_last_name'],
        $payload['beneficiary_extension'],
        $payload['beneficiary_birth_date'],
        $payload['beneficiary_age'],
        $payload['beneficiary_complete_name'],
        $payload['contact_number'],
        $payload['applicant_first_name'],
        $payload['applicant_middle_name'],
        $payload['applicant_last_name'],
        $payload['applicant_extension'],
        $payload['applicant_birth_date'],
        $payload['applicant_age'],
        $payload['barangay'],
        $payload['sitio_purok'],
        $payload['municipality'],
        $payload['province'],
        $payload['gender'],
        $payload['estimated_income'],
        $payload['employment'],
        $payload['civil_status'],
        $payload['civil_status_other'],
        $payload['highest_educational_attainment'],
        $payload['problem_presented'],
        $payload['client_category_other'],
        $payload['assistance_type_other'],
        $familyCompositionJson,
        $clientCategoriesJson,
        $assistanceTypesJson,
        $payload['amount'],
        $payload['claim_status'],
        $payload['amount_claimed'],
        $payload['cheque_date']
    );
    $insertRequest->execute();
    $requestId = (int) $connection->insert_id;
    $insertRequest->close();

    persist_structured_priority_and_urgency($connection, $requestId, $payload);

    persist_applicant_request_snapshot($connection, $requestId, $payload);
    persist_beneficiary_address_snapshot($connection, $requestId, $payload);

    $insertRelation = $connection->prepare('INSERT IGNORE INTO aics_account_requests (account_id, request_id) VALUES (?, ?)');
    if (!$insertRelation) {
        throw new RuntimeException('Unable to link account to request.');
    }
    $insertRelation->bind_param('ii', $accountId, $requestId);
    $insertRelation->execute();
    $insertRelation->close();

    sync_request_assistance_mapping($connection, $requestId, (string) ($payload['assistance_type'][0] ?? ''));
    record_possible_duplicate_warning($connection, $requestId, $accountId);
    $savedDocuments = save_uploaded_documents($connection, $payload['documents'], $requestId, $referenceNo, [], $createdSubmissionFiles, $obsoleteSubmissionFiles);
    if (!$isEditMode && $draftId !== '') {
        $allowedDraftDocumentTypes = array_map(
            static fn (string $code): string => aics_normalize_document_type($code),
            aics_allowed_document_codes((string) ($payload['assistance_type'][0] ?? ''))
        );
        $savedDocuments += aics_finalize_draft_documents($connection, $accountId, $draftId, $requestId, $referenceNo, $allowedDraftDocumentTypes, $createdSubmissionFiles);
    }

    if (!$isEditMode && $draftId !== '') {
        aics_consume_claimed_draft($connection, $accountId, $draftId);
    }
    $connection->commit();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    aics_queue_ai_reviews_after_commit($connection, $requestId);
    $connection->close();
    if ($draftId !== '') aics_delete_draft_files($draftId);
    aics_remove_submission_files($obsoleteSubmissionFiles);

    respond_json(true, 'Request submitted successfully.', 200, [
        'reference_no' => $referenceNo,
        'documents_saved' => $savedDocuments,
        'mode' => 'create',
    ]);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli && $connection->connect_errno === 0) {
        @$connection->rollback();
        try {
            $connection->close();
        } catch (Throwable $ignored) {
            // no-op
        }
    }
    aics_remove_submission_files($createdSubmissionFiles);
    $message = trim($exception->getMessage());
    $isDatabaseFailure = $exception instanceof mysqli_sql_exception
        || str_contains(strtolower($message), 'database connection failed');
    if ($isDatabaseFailure || !($exception instanceof RuntimeException)) {
        error_log('Applicant request submission failed: category=' . $exception::class);
        respond_json(false, 'Unable to submit the request right now. Please try again.', 500);
    }

    respond_json(false, $message !== '' ? $message : 'Unable to submit the request.', 400);
}

/**
 * Applies the authenticated account's profile to request fields that describe
 * the applicant. This is intentionally performed server-side because browser
 * form values, edit bootstraps, and any future draft mechanism are untrusted.
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function apply_authenticated_profile_to_request(array $post, mysqli $connection, int $accountId, ?int $editRequestId = null): array
{
    $stmt = $connection->prepare(
        'SELECT p.first_name, p.middle_name, p.last_name, p.extension_name, p.date_of_birth,
                p.civil_status, p.civil_status_other, p.highest_educational_attainment,
                p.contact_number, p.occupation, p.occupation_other, p.estimated_monthly_income,
                p.province, p.municipality, p.barangay, p.address_line, a.email
         FROM aics_applicant_profiles p
         INNER JOIN aics_accounts a ON a.id = p.account_id
         WHERE p.account_id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load the authenticated applicant profile.');
    }

    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $profile = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!is_array($profile)) {
        throw new RuntimeException('Applicant profile not found. Complete your profile before submitting a request.');
    }

    $requestApplicant = null;
    if ($editRequestId !== null && $editRequestId > 0) {
        $requestStmt = $connection->prepare(
            'SELECT r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name, r.applicant_extension,
                    r.applicant_birth_date, r.applicant_age, r.civil_status, r.civil_status_other,
                    r.highest_educational_attainment, r.applicant_contact_number, r.applicant_email,
                    r.applicant_occupation, r.applicant_estimated_monthly_income, r.province,
                    r.municipality, r.barangay, r.sitio_purok
             FROM aics_requests r
             INNER JOIN aics_account_requests ar ON ar.request_id = r.id
             WHERE r.id = ? AND ar.account_id = ? LIMIT 1'
        );
        if (!$requestStmt) {
            throw new RuntimeException('Unable to load the request Applicant snapshot.');
        }
        $requestStmt->bind_param('ii', $editRequestId, $accountId);
        $requestStmt->execute();
        $requestApplicant = $requestStmt->get_result()?->fetch_assoc();
        $requestStmt->close();
        if (!is_array($requestApplicant)) {
            throw new RuntimeException('Request not found or not allowed.');
        }
    }

    $birthDate = trim((string) ($profile['date_of_birth'] ?? ''));
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
    if (!$birth || $birth->format('Y-m-d') !== $birthDate) {
        throw new RuntimeException('Applicant profile has an invalid date of birth. Update your profile before submitting a request.');
    }
    $today = new DateTimeImmutable('today');
    $age = $birth->diff($today)->y;

    $legacyRequest = is_array($requestApplicant);
    if ($legacyRequest) {
        foreach (['applicant_contact_number', 'applicant_email', 'applicant_occupation', 'applicant_estimated_monthly_income'] as $snapshotField) {
            if (($requestApplicant[$snapshotField] ?? null) !== null && trim((string) $requestApplicant[$snapshotField]) !== '') {
                $legacyRequest = false;
                break;
            }
        }
    }
    $snapshotOrProfile = static function (string $requestField, string $profileValue) use ($requestApplicant, $legacyRequest): string {
        if (!is_array($requestApplicant)) {
            return $profileValue;
        }
        $snapshotValue = trim((string) ($requestApplicant[$requestField] ?? ''));
        if ($snapshotValue !== '') {
            return $snapshotValue;
        }
        return $legacyRequest ? $profileValue : '';
    };

    $profileOccupation = trim((string) ($profile['occupation'] ?? ''));
    if ($profileOccupation === 'Other') {
        $otherOccupation = trim((string) ($profile['occupation_other'] ?? ''));
        $profileOccupation = $otherOccupation !== '' ? 'Other: ' . $otherOccupation : 'Other';
    }

    $post['applicant_first_name'] = $snapshotOrProfile('applicant_first_name', trim((string) ($profile['first_name'] ?? '')));
    $post['applicant_middle_name'] = $snapshotOrProfile('applicant_middle_name', trim((string) ($profile['middle_name'] ?? '')));
    $post['applicant_last_name'] = $snapshotOrProfile('applicant_last_name', trim((string) ($profile['last_name'] ?? '')));
    $post['applicant_extension'] = $snapshotOrProfile('applicant_extension', trim((string) ($profile['extension_name'] ?? '')));
    $post['applicant_birth_date'] = $snapshotOrProfile('applicant_birth_date', $birthDate);
    $post['applicant_age'] = $snapshotOrProfile('applicant_age', (string) $age);
    $post['civil_status'] = $snapshotOrProfile('civil_status', aics_normalize_civil_status($profile['civil_status'] ?? null) ?? '');
    $post['highest_educational_attainment'] = $snapshotOrProfile('highest_educational_attainment', trim((string) ($profile['highest_educational_attainment'] ?? '')));
    $post['province'] = $snapshotOrProfile('province', trim((string) ($profile['province'] ?? '')));
    $post['municipality'] = $snapshotOrProfile('municipality', trim((string) ($profile['municipality'] ?? '')));
    $post['barangay'] = $snapshotOrProfile('barangay', trim((string) ($profile['barangay'] ?? '')));
    $post['sitio_purok'] = $snapshotOrProfile('sitio_purok', trim((string) ($profile['address_line'] ?? '')));

    $post['applicant_contact_number'] = aics_normalize_mobile_number($snapshotOrProfile('applicant_contact_number', (string) ($profile['contact_number'] ?? ''))) ?? '';
    $post['applicant_email'] = aics_normalize_email_address($snapshotOrProfile('applicant_email', (string) ($profile['email'] ?? ''))) ?? '';
    $post['applicant_occupation'] = $snapshotOrProfile('applicant_occupation', $profileOccupation);
    $post['applicant_estimated_monthly_income'] = aics_income_value($snapshotOrProfile('applicant_estimated_monthly_income', aics_income_value($profile['estimated_monthly_income'] ?? null) ?? '')) ?? '';

    // contact_number, employment, and estimated_income are Step 1
    // beneficiary fields. They deliberately remain POST values and must never
    // fall back to the authenticated applicant profile.

    $post['civil_status_other'] = $post['civil_status'] === 'other'
        ? $snapshotOrProfile('civil_status_other', trim((string) ($profile['civil_status_other'] ?? '')))
        : '';

    return $post;
}

/**
 * @return array{
 *   date_applied:string,
 *   beneficiary_first_name:string,
 *   beneficiary_middle_name:?string,
 *   beneficiary_last_name:string,
 *   beneficiary_extension:?string,
 *   beneficiary_birth_date:string,
 *   beneficiary_age:int,
 *   beneficiary_complete_name:string,
 *   contact_number:string,
 *   applicant_first_name:string,
 *   applicant_middle_name:?string,
 *   applicant_last_name:string,
 *   applicant_extension:?string,
 *   applicant_birth_date:string,
 *   applicant_age:int,
 *   applicant_contact_number:string,
 *   applicant_email:?string,
 *   applicant_occupation:string,
 *   applicant_estimated_monthly_income:string,
 *   barangay:string,
 *   sitio_purok:?string,
  *   municipality:string,
  *   province:string,
  *   gender:string,
 *   estimated_income:?string,
 *   employment:string,
 *   civil_status:string,
 *   civil_status_other:?string,
 *   highest_educational_attainment:string,
 *   problem_presented:string,
 *   client_category_other:?string,
 *   assistance_type_other:?string,
 *   family_composition:array<int,array<string,mixed>>,
 *   client_category:array<int,string>,
 *   assistance_type:array<int,string>,
 *   amount:float,
 *   claim_status:string,
 *   amount_claimed:?float,
 *   cheque_date:?string,
 *   documents:array<int,array<string,mixed>>
 * }
 */
function validate_request_payload(array $post, array $files, bool $isEditMode = false, ?array $editContext = null, array $draftDocuments = []): array
{
    $required = [
        'date_applied',
        'beneficiary_first_name',
        'beneficiary_last_name',
        'beneficiary_birth_date',
        'beneficiary_age',
        'beneficiary_complete_name',
        'beneficiary_barangay', 'beneficiary_municipality', 'beneficiary_province',
        'contact_number',
        'applicant_first_name',
        'applicant_last_name',
        'applicant_birth_date',
        'applicant_age',
        'barangay',
        'municipality',
        'province',
        'gender', 'employment', 'estimated_income',
        'civil_status',
        'highest_educational_attainment',
        'problem_presented',
        'assistance_type',
    ];

    foreach ($required as $field) {
        if (!isset($post[$field]) || aics_display_value($post[$field]) === '') {
            throw new RuntimeException('Missing required field: ' . $field);
        }
    }

    $gender = sanitize_choice((string) $post['gender'], ['male', 'female'], 'gender');
    $claimStatus = sanitize_choice((string) ($post['claim_status'] ?? 'unclaimed'), ['claimed', 'unclaimed'], 'claim_status');
    $assistanceType = sanitize_choice((string) $post['assistance_type'], ['medical', 'burial', 'disaster_victims', 'special_cases', 'educational_basic', 'high_inflation_affected'], 'assistance_type');
    $civilStatus = aics_normalize_civil_status((string) $post['civil_status']);
    if ($civilStatus === null) throw new RuntimeException('Invalid civil status.');
    $educationValue = sanitize_choice((string) $post['highest_educational_attainment'], aics_education_options(), 'highest_educational_attainment');
    $employmentType = sanitize_choice((string) $post['employment'], aics_occupation_options(), 'employment');
    $employmentOther = sanitize_nullable_string($post['employment_other'] ?? null);
    if ($employmentType === 'Other' && $employmentOther === null) {
        throw new RuntimeException('Specify Occupation / Source of Income when Other is selected.');
    }
    $employment = $employmentType === 'Other' ? 'Other: ' . $employmentOther : $employmentType;

    if (!preg_match('/^09\d{9}$/', trim((string) $post['contact_number']))) {
        throw new RuntimeException('Contact number must be 09XXXXXXXXX.');
    }

    $rawBeneficiaryCategory = $post['client_category'] ?? '';
    if (!is_string($rawBeneficiaryCategory)) {
        throw new RuntimeException('Select exactly one valid beneficiary category.');
    }
    $beneficiaryCategory = trim($rawBeneficiaryCategory);
    $allowedBeneficiaryCategories = ['youth', 'senior_citizen', 'pwd', 'pantawid', 'ips', 'family_head', 'others'];
    if (!in_array($beneficiaryCategory, $allowedBeneficiaryCategories, true)) {
        throw new RuntimeException('Select exactly one valid beneficiary category.');
    }
    // Legacy JSON storage remains an array for existing reports; new requests
    // always contain exactly one beneficiary category.
    $clientCategories = [$beneficiaryCategory];
    $beneficiaryAgeForCategory = sanitize_age((string) $post['beneficiary_age'], 'beneficiary_age');
    $beneficiaryAgeForValidation = $beneficiaryAgeForCategory;
    $beneficiaryBirthDateForCategory = normalize_date((string) $post['beneficiary_birth_date'], 'beneficiary_birth_date');
    try {
        $ageFromBeneficiaryDob = (new DateTimeImmutable($beneficiaryBirthDateForCategory))->diff(new DateTimeImmutable('today'))->y;
        if ($ageFromBeneficiaryDob >= 0) $beneficiaryAgeForValidation = $ageFromBeneficiaryDob;
    } catch (Throwable) {
        // The validated submitted age remains the fallback for legacy date edge cases.
    }
    $beneficiaryCategoryAgeError = aics_beneficiary_category_age_error($beneficiaryAgeForValidation, $beneficiaryCategory);
    if ($beneficiaryCategoryAgeError !== null) throw new RuntimeException($beneficiaryCategoryAgeError);
    $amountInput = isset($post['amount']) ? trim((string) $post['amount']) : '';
    $amount = $amountInput === '' ? 0.0 : (float) $amountInput;
    $estimatedIncome = isset($post['estimated_income']) ? trim((string) $post['estimated_income']) : '';
    if (!aics_is_valid_income_value($estimatedIncome)) {
        throw new RuntimeException('Select a valid estimated monthly income.');
    }

    $amountClaimed = null;
    $chequeDate = null;
    if ($claimStatus === 'claimed') {
        $amountClaimed = (float) ($post['amount_claimed'] ?? 0);
        $chequeDate = normalize_date((string) ($post['cheque_date'] ?? ''), 'cheque_date');
    }

    $existingDocsByType = [];
    $lockedDocTypes = [];
    if ($isEditMode && is_array($editContext)) {
        $existingDocsByType = (array) ($editContext['existing_docs_by_type'] ?? []);
        $lockedDocTypes = array_map('strval', (array) ($editContext['locked_document_types'] ?? []));
    }

    $documents = normalize_dynamic_documents(
        $files,
        $assistanceType,
        $isEditMode,
        $existingDocsByType,
        $lockedDocTypes,
        $draftDocuments
    );

    $clientCategoryOther = sanitize_nullable_string($post['client_category_other'] ?? null);
    if (in_array('others', $clientCategories, true) && $clientCategoryOther === null) {
        throw new RuntimeException('Please specify the beneficiary category marked as Others.');
    }
    if (!in_array('others', $clientCategories, true)) {
        $clientCategoryOther = null;
    }

    $assistanceTypeOther = sanitize_nullable_string($post['assistance_type_other'] ?? null);
    if ($assistanceType === 'special_cases' && $assistanceTypeOther === null) {
        throw new RuntimeException('Specify the Special Case or present need.');
    }
    if ($assistanceType !== 'special_cases') {
        $assistanceTypeOther = null;
    }

    $civilStatusOther = sanitize_nullable_string($post['civil_status_other'] ?? null);
    if ($civilStatus === 'other' && $civilStatusOther === null) throw new RuntimeException('Specify Civil Status when Others is selected.');
    if ($civilStatus !== 'other') $civilStatusOther = null;
    if ($civilStatus === 'other' && $civilStatusOther === null) {
        throw new RuntimeException('Please specify the civil status marked as Other.');
    }

    $familyNotApplicable = (string) ($post['family_members_not_applicable'] ?? '') === '1';
    $familyComposition = normalize_family_composition($post, $familyNotApplicable);
    if (!$familyNotApplicable && $familyComposition === []) {
        throw new RuntimeException('Family Composition is required unless Not Applicable is selected.');
    }

    return [
        'date_applied' => normalize_date((string) $post['date_applied'], 'date_applied'),
        'beneficiary_first_name' => sanitize_string((string) $post['beneficiary_first_name']),
        'beneficiary_middle_name' => sanitize_nullable_string($post['beneficiary_middle_name'] ?? null),
        'beneficiary_last_name' => sanitize_string((string) $post['beneficiary_last_name']),
        'beneficiary_extension' => sanitize_nullable_string($post['beneficiary_extension'] ?? null),
        'beneficiary_birth_date' => normalize_date((string) $post['beneficiary_birth_date'], 'beneficiary_birth_date'),
        'beneficiary_age' => $beneficiaryAgeForCategory,
        'beneficiary_complete_name' => sanitize_string((string) $post['beneficiary_complete_name']),
        'beneficiary_barangay' => sanitize_string((string) $post['beneficiary_barangay']),
        'beneficiary_sitio_purok' => sanitize_nullable_string($post['beneficiary_sitio_purok'] ?? null),
        'beneficiary_municipality' => sanitize_string((string) $post['beneficiary_municipality']),
        'beneficiary_province' => sanitize_string((string) $post['beneficiary_province']),
        'contact_number' => trim((string) $post['contact_number']),
        'applicant_first_name' => sanitize_string((string) $post['applicant_first_name']),
        'applicant_middle_name' => sanitize_nullable_string($post['applicant_middle_name'] ?? null),
        'applicant_last_name' => sanitize_string((string) $post['applicant_last_name']),
        'applicant_extension' => sanitize_nullable_string($post['applicant_extension'] ?? null),
        'applicant_birth_date' => normalize_date((string) $post['applicant_birth_date'], 'applicant_birth_date'),
        'applicant_age' => sanitize_age((string) $post['applicant_age'], 'applicant_age'),
        'applicant_contact_number' => sanitize_string((string) ($post['applicant_contact_number'] ?? '')),
        'applicant_email' => sanitize_nullable_string($post['applicant_email'] ?? null),
        'applicant_occupation' => sanitize_string((string) ($post['applicant_occupation'] ?? '')),
        'applicant_estimated_monthly_income' => sanitize_string((string) ($post['applicant_estimated_monthly_income'] ?? '')),
        'barangay' => sanitize_string((string) $post['barangay']),
        'sitio_purok' => sanitize_nullable_string($post['sitio_purok'] ?? null),
        'municipality' => sanitize_string((string) $post['municipality']),
        'province' => sanitize_string((string) $post['province']),
        'gender' => $gender,
        'estimated_income' => $estimatedIncome,
        'employment' => $employment,
        'civil_status' => $civilStatus,
        'civil_status_other' => $civilStatusOther,
        'highest_educational_attainment' => $educationValue,
        'problem_presented' => sanitize_string((string) $post['problem_presented']),
        'client_category_other' => $clientCategoryOther,
        'assistance_type_other' => $assistanceTypeOther,
        'family_composition' => $familyComposition,
        'client_category' => $clientCategories,
        'assistance_type' => [$assistanceType],
        'amount' => $amount,
        'claim_status' => $claimStatus,
        'amount_claimed' => $amountClaimed,
        'cheque_date' => $chequeDate,
        'documents' => $documents,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function load_latest_active_account_request_summary(mysqli $connection, int $accountId): ?array
{
    $sql = <<<SQL
        SELECT r.id, r.reference_no, r.request_status
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
          AND LOWER(TRIM(r.request_status)) NOT IN (
            'rejected', 'denied', 'not_approved', 'not approved',
            'cancelled', 'canceled', 'released', 'distributed', 'completed', 'closed'
          )
        ORDER BY r.id DESC
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to verify existing request records.');
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function enforce_application_cooldown(mysqli $connection, int $accountId): void
{
    if ($accountId <= 0) {
        return;
    }

    $state = aics_application_cooldown_state($connection, $accountId);
    if (!($state['is_in_cooldown'] ?? false)) {
        return;
    }

    $eligibleOn = aics_format_cooldown_date((string) ($state['eligible_on'] ?? ''));
    $daysRemaining = (int) ($state['days_remaining'] ?? 0);

    $message = sprintf(
        'You may file your next application on %s. A one-year cooldown applies between applications (about %d day%s remaining).',
        $eligibleOn !== '' ? $eligibleOn : 'a later date',
        $daysRemaining,
        $daysRemaining === 1 ? '' : 's'
    );

    throw new RuntimeException($message);
}

/**
 * @param array<string,mixed> $files
 * @param array<string,mixed> $existingDocsByType
 * @param array<int,string> $lockedDocTypes
 * @return array<int,array<string,mixed>>
 */
function normalize_dynamic_documents(
    array $files,
    string $assistanceType,
    bool $isEditMode = false,
    array $existingDocsByType = [],
    array $lockedDocTypes = [],
    array $draftDocuments = []
): array {
    $requiredDocs = aics_required_document_codes($assistanceType);
    if (count($requiredDocs) === 0) {
        throw new RuntimeException('Invalid assistance type requirements.');
    }

    $lockedLookup = [];
    foreach ($lockedDocTypes as $lockedType) {
        $lockedLookup[strtolower(trim((string) $lockedType))] = true;
    }

    // The canonical matrix currently contains applicant-required slots only.
    $optionalDocs = array_values(array_map(
        static fn (array $requirement): string => $requirement['code'],
        array_filter(aics_document_requirements_for($assistanceType), static fn (array $requirement): bool => !$requirement['required'])
    ));
    $documentIds = array_values(array_unique(array_merge(aics_allowed_document_codes($assistanceType), $optionalDocs)));
    $documents = [];
    foreach ($documentIds as $docId) {
        $inputName = 'doc_' . $docId;
        $uploadedFile = $files[$inputName] ?? null;
        $hasUpload = is_array($uploadedFile)
            && ((int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        $docIdKey = strtolower(trim((string) $docId));
        $hasExisting = isset($existingDocsByType[$docIdKey]);
        $hasDraftDocument = isset($draftDocuments[$docIdKey]);
        $isLocked = isset($lockedLookup[$docIdKey]);

        if ($hasUpload) {
            if ($isEditMode && $isLocked) {
                throw new RuntimeException('Document "' . ucwords(str_replace('_', ' ', $docId)) . '" is already checked by PSWDO and cannot be replaced.');
            }
            /** @var array<string,mixed> $uploadedFile */
            $documents[] = normalize_single_upload($uploadedFile, $docId);
            continue;
        }

        if (in_array($docId, $optionalDocs, true)) {
            continue;
        }

        if ($isEditMode && $docId === 'valid_id_back' && !$hasExisting) {
            // Older applications predate the second ID photo.
            continue;
        }

        if ($isEditMode) {
            if (!$hasExisting) {
                throw new RuntimeException('Missing required file: ' . ucwords(str_replace('_', ' ', $docId)));
            }
            continue;
        }

        if ($hasDraftDocument) {
            continue;
        }

        throw new RuntimeException('Missing required file: ' . ucwords(str_replace('_', ' ', $docId)));
    }

    return $documents;
}

/**
 * @param array<string,mixed> $uploaded
 * @return array{document_type:string,original_name:string,tmp_name:string,size:int,mime_type:string,extension:string}
 */
function normalize_single_upload(array $uploaded, string $docType): array
{
    $isIdPhoto = in_array($docType, ['valid_id', 'valid_id_back'], true);
    $error = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed for ' . ucwords(str_replace('_', ' ', $docType)) . '.');
    }

    $name = trim((string) ($uploaded['name'] ?? ''));
    $tmpName = (string) ($uploaded['tmp_name'] ?? '');
    $size = (int) ($uploaded['size'] ?? 0);

    if ($size <= 0 || $size > ($isIdPhoto ? 10 * 1024 * 1024 : MAX_UPLOAD_SIZE)) {
        throw new RuntimeException($isIdPhoto ? 'ID photo must be 10 MB or smaller.' : 'File exceeds the 25 MB limit: ' . $name);
    }

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, $isIdPhoto ? ['jpg', 'jpeg', 'png', 'webp'] : ALLOWED_EXTENSIONS, true)) {
        throw new RuntimeException($isIdPhoto ? 'Please upload your ID as a JPG, PNG, or WebP photo.' : 'Please upload this document as PDF, JPG, or PNG.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    if (!in_array($mimeType, $isIdPhoto ? ['image/jpeg', 'image/png', 'image/webp'] : ALLOWED_MIME_TYPES, true)) {
        throw new RuntimeException('Unsupported MIME type: ' . $mimeType);
    }
    $extensionMimeMap = [
        'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
    ];
    if (!in_array($mimeType, $extensionMimeMap[$extension] ?? [], true)) {
        throw new RuntimeException('File extension and content type do not match.');
    }

    return [
        'document_type' => $docType,
        'original_name' => preg_replace('/[^a-zA-Z0-9._-]/', '_', $name),
        'tmp_name' => $tmpName,
        'size' => $size,
        'mime_type' => $mimeType,
        'extension' => $extension,
    ];
}

/**
 * @return array{
 *   request_id:int,
 *   reference_no:string,
 *   date_applied:string,
 *   request_status:string,
 *   original_assistance_type:string,
 *   existing_docs_by_type:array<string,array<string,mixed>>,
 *   locked_document_types:array<int,string>
 * }
 */
function load_edit_request_context(mysqli $connection, int $accountId, int $requestId): array
{
    $requestSql = <<<SQL
        SELECT r.id, r.reference_no, r.date_applied, r.request_status, r.assistance_types_json
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ? AND r.id = ?
        LIMIT 1
    SQL;

    $requestStmt = $connection->prepare($requestSql);
    if (!$requestStmt) {
        throw new RuntimeException('Unable to prepare request lookup.');
    }
    $requestStmt->bind_param('ii', $accountId, $requestId);
    $requestStmt->execute();
    $requestRow = $requestStmt->get_result()?->fetch_assoc();
    $requestStmt->close();

    if (!is_array($requestRow)) {
        throw new RuntimeException('Request not found or not allowed.');
    }

    $status = strtolower(trim((string) ($requestRow['request_status'] ?? 'pending')));
    if (status_locked_for_edit($status)) {
        throw new RuntimeException('This application can no longer be edited at its current status.');
    }

    $assistanceType = '';
    $decodedAssistance = json_decode((string) ($requestRow['assistance_types_json'] ?? '[]'), true);
    if (is_array($decodedAssistance) && count($decodedAssistance) > 0) {
        $assistanceType = strtolower(trim((string) $decodedAssistance[0]));
    }

    $hasDocReviewTable = ($connection->query("SHOW TABLES LIKE 'aics_request_document_reviews'")?->num_rows ?? 0) > 0;
    $docsSql = $hasDocReviewTable
        ? <<<SQL
            SELECT
                d.id,
                d.document_type,
                d.original_name,
                d.stored_name,
                d.file_path,
                d.mime_type,
                d.file_size,
                d.uploaded_at,
                COALESCE(dr.is_checked, 0) AS is_checked
            FROM aics_request_documents d
            LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
            WHERE d.request_id = ?
            ORDER BY d.uploaded_at DESC, d.id DESC
        SQL
        : <<<SQL
            SELECT
                d.id,
                d.document_type,
                d.original_name,
                d.stored_name,
                d.file_path,
                d.mime_type,
                d.file_size,
                d.uploaded_at,
                0 AS is_checked
            FROM aics_request_documents d
            WHERE d.request_id = ?
            ORDER BY d.uploaded_at DESC, d.id DESC
        SQL;

    $docsStmt = $connection->prepare($docsSql);
    if (!$docsStmt) {
        throw new RuntimeException('Unable to prepare documents lookup.');
    }
    $docsStmt->bind_param('i', $requestId);
    $docsStmt->execute();
    $docsResult = $docsStmt->get_result();

    $existingDocsByType = [];
    $lockedDocTypes = [];
    while ($docRow = $docsResult ? $docsResult->fetch_assoc() : null) {
        if (!is_array($docRow)) {
            break;
        }

        $docType = aics_normalize_document_type((string) ($docRow['document_type'] ?? ''));
        if ($docType === '') {
            continue;
        }

        if (!isset($existingDocsByType[$docType])) {
            $existingDocsByType[$docType] = [
                'document_id' => (int) ($docRow['id'] ?? 0),
                'file_name' => (string) ($docRow['original_name'] ?? ''),
                'stored_name' => (string) ($docRow['stored_name'] ?? ''),
                'file_path' => (string) ($docRow['file_path'] ?? ''),
                'mime_type' => (string) ($docRow['mime_type'] ?? ''),
                'file_size' => (int) ($docRow['file_size'] ?? 0),
                'uploaded_at' => (string) ($docRow['uploaded_at'] ?? ''),
                'is_checked' => ((int) ($docRow['is_checked'] ?? 0)) === 1,
            ];
        }

        if (((int) ($docRow['is_checked'] ?? 0)) === 1) {
            $lockedDocTypes[] = $docType;
        }
    }
    $docsStmt->close();

    return [
        'request_id' => (int) ($requestRow['id'] ?? 0),
        'reference_no' => (string) ($requestRow['reference_no'] ?? ''),
        'date_applied' => (string) ($requestRow['date_applied'] ?? ''),
        'request_status' => $status,
        'original_assistance_type' => $assistanceType,
        'existing_docs_by_type' => $existingDocsByType,
        'locked_document_types' => array_values(array_unique($lockedDocTypes)),
    ];
}

/** Uses the established dashboard/application timezone (Asia/Manila). */
function aics_current_application_date(): string
{
    return (new DateTimeImmutable('today', aics_dashboard_timezone()))->format('Y-m-d');
}

/**
 * @param array<int,array<string,mixed>> $documents
 */
function save_uploaded_documents(mysqli $connection, array $documents, int $requestId, string $referenceNo, array $existingDocsByType = [], array &$createdFilePaths = [], array &$obsoleteFilePaths = []): int
{
    if (count($documents) === 0) {
        return 0;
    }

    $uploadDirectory = __DIR__ . '/../uploads/' . $referenceNo;
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Failed to create upload directory.');
    }

    $insertDocSql = <<<SQL
        INSERT INTO aics_request_documents (
            request_id, document_type, original_name, stored_name, file_path, mime_type, file_size
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    SQL;
    $insertDocument = $connection->prepare($insertDocSql);
    if (!$insertDocument) {
        throw new RuntimeException('Unable to prepare document insert.');
    }

    $savedDocuments = 0;
    foreach ($documents as $document) {
        $docType = aics_normalize_document_type((string) ($document['document_type'] ?? ''));
        if ($docType === '') {
            continue;
        }

        $existing = $existingDocsByType[$docType] ?? null;
        if (is_array($existing)) {
            $existingId = (int) ($existing['document_id'] ?? 0);
            if ($existingId > 0) {
                $deleteExisting = $connection->prepare('DELETE FROM aics_request_documents WHERE id = ? AND request_id = ?');
                $deleteExisting->bind_param('ii', $existingId, $requestId);
                $deleteExisting->execute();
                if ($deleteExisting->affected_rows !== 1) {
                    $deleteExisting->close();
                    throw new RuntimeException('Unable to replace the existing document.');
                }
                $deleteExisting->close();
                $oldStoredName = basename((string) ($existing['stored_name'] ?? ''));
                if ($oldStoredName !== '') $obsoleteFilePaths[] = $uploadDirectory . DIRECTORY_SEPARATOR . $oldStoredName;
            }
        }

        $savedName = sprintf(
            '%s-%s-%s.%s',
            $docType,
            date('YmdHis'),
            bin2hex(random_bytes(4)),
            (string) ($document['extension'] ?? 'pdf')
        );

        $targetAbsolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $savedName;
        $relativeFilePath = 'AICS/Applicants/uploads/' . $referenceNo . '/' . $savedName;
        $tmpName = (string) ($document['tmp_name'] ?? '');

        if (!move_uploaded_file($tmpName, $targetAbsolutePath)) {
            throw new RuntimeException('Failed to store document: ' . (string) ($document['original_name'] ?? 'Unknown file'));
        }
        $createdFilePaths[] = $targetAbsolutePath;

        $originalName = (string) ($document['original_name'] ?? $savedName);
        $mimeType = (string) ($document['mime_type'] ?? 'application/octet-stream');
        $size = (int) ($document['size'] ?? 0);

        $insertDocument->bind_param(
            'isssssi',
            $requestId,
            $docType,
            $originalName,
            $savedName,
            $relativeFilePath,
            $mimeType,
            $size
        );
        $insertDocument->execute();
        $savedDocuments++;
    }

    $insertDocument->close();
    return $savedDocuments;
}

/** @param list<string> $paths */
function aics_remove_submission_files(array $paths): void
{
    foreach (array_unique($paths) as $path) {
        if (!is_string($path) || $path === '') continue;
        if (is_file($path)) @unlink($path);
        $directory = dirname($path);
        if (is_dir($directory) && count(glob($directory . DIRECTORY_SEPARATOR . '*') ?: []) === 0) @rmdir($directory);
    }
}

function sync_request_assistance_mapping(mysqli $connection, int $requestId, string $assistanceCode): void
{
    $code = strtolower(trim($assistanceCode));
    if ($requestId <= 0 || $code === '') {
        return;
    }

    try {
        $hasMappingTable = $connection->query("SHOW TABLES LIKE 'aics_request_assistance'")?->num_rows ?? 0;
        $hasAssistanceTable = $connection->query("SHOW TABLES LIKE 'aics_assistance_types'")?->num_rows ?? 0;

        if ($hasMappingTable <= 0 || $hasAssistanceTable <= 0) {
            return;
        }

        $deleteStmt = $connection->prepare('DELETE FROM aics_request_assistance WHERE request_id = ?');
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $requestId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insertStmt = $connection->prepare(
            'INSERT IGNORE INTO aics_request_assistance (request_id, assistance_type_id) SELECT ?, id FROM aics_assistance_types WHERE assistance_code = ? LIMIT 1'
        );
        if ($insertStmt) {
            $insertStmt->bind_param('is', $requestId, $code);
            $insertStmt->execute();
            $insertStmt->close();
        }
    } catch (Throwable $ignored) {
        // Keep request save successful even if optional mapping tables are unavailable.
    }
}

/**
 * Records an applicant/beneficiary match as a review warning.  Detection is
 * deliberately best-effort: it must never reject or roll back a valid intake.
 */
function record_possible_duplicate_warning(mysqli $connection, int $requestId, int $accountId): void
{
    try {
        $selfStmt = $connection->prepare('SELECT applicant_first_name, applicant_middle_name, applicant_last_name, applicant_birth_date, beneficiary_complete_name, beneficiary_birth_date FROM aics_requests WHERE id = ? LIMIT 1');
        $selfStmt->bind_param('i', $requestId);
        $selfStmt->execute();
        $self = $selfStmt->get_result()?->fetch_assoc();
        $selfStmt->close();
        if (!is_array($self)) return;

        $otherStmt = $connection->prepare('SELECT r.id, r.reference_no, r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name, r.applicant_birth_date, r.beneficiary_complete_name, r.beneficiary_birth_date, ar.account_id FROM aics_requests r LEFT JOIN aics_account_requests ar ON ar.request_id = r.id WHERE r.id <> ? ORDER BY r.id DESC LIMIT 50');
        $otherStmt->bind_param('i', $requestId);
        $otherStmt->execute();
        $otherRows = $otherStmt->get_result();

        $name = static function (array $row, string $prefix): string {
            if ($prefix === 'beneficiary') return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($row['beneficiary_complete_name'] ?? '')) ?? ''));
            return strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', [
                (string) ($row['applicant_first_name'] ?? ''),
                (string) ($row['applicant_middle_name'] ?? ''),
                (string) ($row['applicant_last_name'] ?? ''),
            ])) ?? ''));
        };
        $selfApplicant = $name($self, 'applicant');
        $selfBeneficiary = $name($self, 'beneficiary');
        $matches = [];
        while ($other = $otherRows?->fetch_assoc()) {
            if (!is_array($other)) break;
            $applicantMatch = ((int) ($other['account_id'] ?? 0) === $accountId && $accountId > 0)
                || ($selfApplicant !== '' && $selfApplicant === $name($other, 'applicant') && (string) ($self['applicant_birth_date'] ?? '') !== '' && (string) ($self['applicant_birth_date'] ?? '') === (string) ($other['applicant_birth_date'] ?? ''));
            $beneficiaryMatch = $selfBeneficiary !== '' && $selfBeneficiary === $name($other, 'beneficiary')
                && (string) ($self['beneficiary_birth_date'] ?? '') !== ''
                && (string) ($self['beneficiary_birth_date'] ?? '') === (string) ($other['beneficiary_birth_date'] ?? '');
            if ($applicantMatch || $beneficiaryMatch) {
                $basis = $applicantMatch && $beneficiaryMatch ? 'Applicant + Beneficiary' : ($applicantMatch ? 'Applicant' : 'Beneficiary');
                $matches[] = $basis . ': ' . trim((string) ($other['reference_no'] ?? 'previous request'));
            }
        }
        $otherStmt->close();

        $flag = count($matches) > 0 ? 1 : 0;
        $note = $flag ? ('Possible duplicate match — ' . implode('; ', array_slice(array_values(array_unique($matches)), 0, 5))) : 'No duplicate found.';
        $writeStmt = $connection->prepare('INSERT INTO aics_request_validation_results (request_id, duplicate_flag, validation_note) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE duplicate_flag = VALUES(duplicate_flag), validation_note = VALUES(validation_note), updated_at = CURRENT_TIMESTAMP');
        $writeStmt->bind_param('iis', $requestId, $flag, $note);
        $writeStmt->execute();
        $writeStmt->close();
    } catch (Throwable) {
        // A review warning must not interfere with applicant submission.
    }
}

function status_locked_for_edit(string $status): bool
{
    $lockedStatuses = [
        'approved',
        'validated',
        'for_release',
        'for release',
        'released',
        'distributed',
        'completed',
        'closed',
        'rejected',
        'denied',
        'not_approved',
        'not approved',
        'cancelled',
        'canceled',
    ];

    return in_array(strtolower(trim($status)), $lockedStatuses, true);
}

function sanitize_choice(string $value, array $allowed, string $field): string
{
    $trimmed = trim($value);
    if (!in_array($trimmed, $allowed, true)) {
        throw new RuntimeException('Invalid choice: ' . $field);
    }
    return $trimmed;
}

function sanitize_age(string $value, string $field): int
{
    $age = (int) $value;
    if ($age < 0 || $age > 120) {
        throw new RuntimeException('Invalid age: ' . $field);
    }
    return $age;
}

function sanitize_string(string $value): string
{
    $clean = trim(aics_display_value($value));
    if ($clean === '') {
        throw new RuntimeException('Required text field is empty.');
    }
    return $clean;
}

function sanitize_nullable_string($value): ?string
{
    $clean = trim(aics_display_value($value));
    return $clean === '' ? null : $clean;
}

function normalize_date(string $value, string $field): string
{
    $date = DateTime::createFromFormat('Y-m-d', trim($value));
    if (!$date) {
        throw new RuntimeException('Invalid date format: ' . $field);
    }
    return $date->format('Y-m-d');
}

/**
 * @return array<int,string>
 */
function normalize_multiselect($value): array
{
    if (is_string($value)) {
        $value = [$value];
    }
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('trim', $value))));
}

function generate_reference_no(mysqli $connection): string
{
    for ($i = 0; $i < 5; $i++) {
        $ref = 'AICS-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $res = $connection->query("SELECT id FROM aics_requests WHERE reference_no = '" . $connection->real_escape_string($ref) . "'");
        if ($res && $res->num_rows === 0) {
            return $ref;
        }
    }
    throw new RuntimeException('Unique reference generation failed.');
}

function ensure_tables_exist(mysqli $connection): void
{
    // Preserve the old column for historical rows but make it optional now
    // that the logged-in account identifies the submitter.
    $connection->query("ALTER TABLE aics_requests MODIFY COLUMN applicant_role ENUM('self', 'representative') DEFAULT NULL COMMENT 'Deprecated legacy intake role; no longer used'");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS sitio_purok VARCHAR(120) DEFAULT NULL AFTER barangay");
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
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_contact_number VARCHAR(15) DEFAULT NULL AFTER applicant_age");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_email VARCHAR(180) DEFAULT NULL AFTER applicant_contact_number");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_occupation VARCHAR(150) DEFAULT NULL AFTER applicant_email");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_estimated_monthly_income VARCHAR(40) DEFAULT NULL AFTER applicant_occupation");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS urgency_response VARCHAR(3) DEFAULT NULL AFTER assistance_types_json");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS urgency_indicator VARCHAR(20) DEFAULT NULL AFTER urgency_response");
    $connection->query("ALTER TABLE aics_request_documents ADD COLUMN IF NOT EXISTS document_type VARCHAR(60) DEFAULT NULL AFTER request_id");
    if (!aics_table_has_index($connection, 'aics_request_documents', 'uq_aics_request_documents_request_type')) {
        $connection->query('ALTER TABLE aics_request_documents ADD UNIQUE KEY uq_aics_request_documents_request_type (request_id, document_type)');
    }
    // Assistance types are master/reference data. Applicant submission only
    // looks up their existing IDs in sync_request_assistance_mapping().
    $connection->query("CREATE TABLE IF NOT EXISTS aics_request_validation_results (request_id INT UNSIGNED NOT NULL, duplicate_flag TINYINT(1) NOT NULL DEFAULT 0, repeated_claim_flag TINYINT(1) NOT NULL DEFAULT 0, inconsistency_flag TINYINT(1) NOT NULL DEFAULT 0, risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0, validation_note VARCHAR(500) DEFAULT NULL, updated_by_account_id INT UNSIGNED DEFAULT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    aics_ensure_ai_review_schema($connection);
}

/** @param array<string,mixed> $payload */
function persist_structured_priority_and_urgency(mysqli $connection, int $requestId, array $payload): void
{
    // Applicant input is not evidence for Smart Prioritization. Await verified documents.
    $urgencyResponse = null;
    $isMedicalAssistance = in_array('medical', (array) ($payload['assistance_type'] ?? []), true);
    $urgencyIndicator = $isMedicalAssistance ? 'REQUIRES_STAFF_REVIEW' : 'STANDARD';
    $requestStmt = $connection->prepare('UPDATE aics_requests SET urgency_response = ?, urgency_indicator = ? WHERE id = ? LIMIT 1');
    if (!$requestStmt) throw new RuntimeException('Unable to save the urgency indicator.');
    $requestStmt->bind_param('ssi', $urgencyResponse, $urgencyIndicator, $requestId);
    $requestStmt->execute(); $requestStmt->close();

    $priority = aics_priority_from_beneficiary_categories((array) ($payload['client_category'] ?? []));
    $connection->query("ALTER TABLE aics_request_prioritization ADD COLUMN IF NOT EXISTS urgency_indicator VARCHAR(20) DEFAULT NULL AFTER urgency_score");
    $connection->query("ALTER TABLE aics_request_prioritization ADD COLUMN IF NOT EXISTS priority_basis VARCHAR(700) DEFAULT NULL AFTER priority_level");
    $basis = $isMedicalAssistance ? 'Medical document findings pending verification.' : 'No Medical Assistance urgency rule applies.';
    $stmt = $connection->prepare('INSERT INTO aics_request_prioritization (request_id, urgency_score, urgency_indicator, vulnerability_score, readiness_score, risk_penalty, priority_score, priority_level, priority_basis) VALUES (?, 0, ?, 0, 0, 0, ?, ?, ?) ON DUPLICATE KEY UPDATE urgency_score = 0, urgency_indicator = VALUES(urgency_indicator), vulnerability_score = 0, readiness_score = 0, risk_penalty = 0, priority_score = VALUES(priority_score), priority_level = VALUES(priority_level), priority_basis = VALUES(priority_basis), updated_at = CURRENT_TIMESTAMP');
    if (!$stmt) throw new RuntimeException('Unable to save request priority.');
    $stmt->bind_param('isiss', $requestId, $urgencyIndicator, $priority['score'], $priority['label'], $basis);
    $stmt->execute(); $stmt->close();
}

/** @param array<string,mixed> $payload */
function persist_applicant_request_snapshot(mysqli $connection, int $requestId, array $payload): void
{
    $stmt = $connection->prepare(
        'UPDATE aics_requests
         SET applicant_contact_number = ?, applicant_email = ?, applicant_occupation = ?, applicant_estimated_monthly_income = ?
         WHERE id = ? LIMIT 1'
    );
    if (!$stmt) throw new RuntimeException('Unable to prepare the Applicant snapshot.');
    $contact = (string) ($payload['applicant_contact_number'] ?? '');
    $email = $payload['applicant_email'] ?? null;
    $occupation = (string) ($payload['applicant_occupation'] ?? '');
    $income = (string) ($payload['applicant_estimated_monthly_income'] ?? '');
    $stmt->bind_param('ssssi', $contact, $email, $occupation, $income, $requestId);
    $stmt->execute();
    if ($stmt->affected_rows < 0) {
        $stmt->close();
        throw new RuntimeException('Unable to save the Applicant snapshot.');
    }
    $stmt->close();
}

/** Stores the beneficiary's submitted address independently of the applicant profile. */
function persist_beneficiary_address_snapshot(mysqli $connection, int $requestId, array $payload): void
{
    $stmt = $connection->prepare('UPDATE aics_requests SET beneficiary_barangay = ?, beneficiary_sitio_purok = ?, beneficiary_municipality = ?, beneficiary_province = ? WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to prepare the Beneficiary address snapshot.');
    $barangay = (string) $payload['beneficiary_barangay'];
    $sitio = $payload['beneficiary_sitio_purok'];
    $municipality = (string) $payload['beneficiary_municipality'];
    $province = (string) $payload['beneficiary_province'];
    $stmt->bind_param('ssssi', $barangay, $sitio, $municipality, $province, $requestId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array<int,array<string,mixed>>
 */
function normalize_family_composition(array $post, bool $notApplicable = false): array
{
    if ($notApplicable) return [['not_applicable' => true]];
    $names = $post['family_name'] ?? [];
    $ages = $post['family_age'] ?? [];
    $relationships = $post['family_relationship'] ?? [];
    $occupations = $post['family_occupation'] ?? [];
    $occupationOthers = $post['family_occupation_other'] ?? [];
    $educations = $post['family_education'] ?? [];
    $incomes = $post['family_estimated_income'] ?? [];

    $maxRows = max(
        is_array($names) ? count($names) : 0,
        is_array($ages) ? count($ages) : 0,
        is_array($relationships) ? count($relationships) : 0,
        is_array($occupations) ? count($occupations) : 0,
        is_array($educations) ? count($educations) : 0,
        is_array($incomes) ? count($incomes) : 0
    );

    $rows = [];
    for ($i = 0; $i < $maxRows; $i++) {
        $name = sanitize_nullable_string(is_array($names) ? ($names[$i] ?? null) : null);
        $ageRaw = trim((string) (is_array($ages) ? ($ages[$i] ?? '') : ''));
        $relationship = sanitize_nullable_string(is_array($relationships) ? ($relationships[$i] ?? null) : null);
        $occupation = sanitize_nullable_string(is_array($occupations) ? ($occupations[$i] ?? null) : null);
        $occupationOther = sanitize_nullable_string(is_array($occupationOthers) ? ($occupationOthers[$i] ?? null) : null);
        $education = sanitize_nullable_string(is_array($educations) ? ($educations[$i] ?? null) : null);
        $income = trim((string) (is_array($incomes) ? ($incomes[$i] ?? '') : ''));

        if ($name === null && $ageRaw === '' && $relationship === null && $occupation === null && $education === null && $income === '') {
            continue;
        }
        if ($relationship !== null && !in_array($relationship, ['Spouse', 'Child', 'Parent', 'Sibling', 'Grandparent', 'Grandchild', 'Other Relative', 'Non-relative'], true)) {
            throw new RuntimeException('Select a valid relationship to beneficiary for each household member.');
        }

        $age = null;
        if ($ageRaw !== '') {
            $age = sanitize_age($ageRaw, 'family_age');
        }
        if ($occupation !== null && !in_array($occupation, aics_occupation_options(), true)) {
            throw new RuntimeException('Choose a valid family-member occupation.');
        }
        if ($education !== null && !in_array($education, aics_education_options(), true)) {
            throw new RuntimeException('Choose a valid family-member educational attainment.');
        }
        if ($occupation === 'Other' && $occupationOther === null) {
            throw new RuntimeException('Specify the occupation for each family member marked Other.');
        }
        if ($name !== null && !aics_is_valid_income_value($income)) {
            throw new RuntimeException('Select a valid estimated monthly income for each household member.');
        }

        $rows[] = [
            'name' => $name ?? '',
            'age' => $age,
            'relationship' => $relationship ?? '',
            'occupation' => $occupation ?? '',
            'occupation_other' => $occupation === 'Other' ? ($occupationOther ?? '') : '',
            'educational_attainment' => $education ?? '',
            'estimated_monthly_income' => aics_is_valid_income_value($income) ? $income : '',
        ];
    }

    return $rows;
}

function respond_json(bool $ok, string $message, int $statusCode, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
