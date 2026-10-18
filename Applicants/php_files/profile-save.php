<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/applicant-id-verification.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid request method',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

aics_start_session();

if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$decoded = is_string($rawInput) && trim($rawInput) !== '' ? json_decode($rawInput, true) : null;
$data = is_array($decoded) ? $decoded : $_POST;

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
if ($accountId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid session',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Browser form submissions normally contain the complete profile, but API
// callers may send only a changed field. Preserve stored values for keys that
// are absent; an explicitly supplied empty value remains an intentional edit.
// This prevents a new optional field from blanking unrelated saved data.
try {
    $existingConnection = aics_db_connect();
    aics_ensure_auth_schema($existingConnection);
    $existingStmt = $existingConnection->prepare(
        'SELECT a.email, p.first_name, p.middle_name, p.last_name, p.extension_name, p.date_of_birth, p.civil_status, p.civil_status_other, p.highest_educational_attainment, p.contact_number, p.occupation, p.occupation_other, p.estimated_monthly_income, p.province, p.municipality, p.barangay, p.address_line, p.complete_address FROM aics_accounts a LEFT JOIN aics_applicant_profiles p ON p.account_id = a.id WHERE a.id = ? LIMIT 1'
    );
    $existingStmt->bind_param('i', $accountId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()?->fetch_assoc();
    $existingStmt->close();
    $idVerification = aics_id_verification_for_account($existingConnection, $accountId);
    $existingConnection->close();
    // Name and birth date come from the ID verified at sign-up and are not editable here.
    if (is_array($existing) && is_array($idVerification) && in_array($idVerification['status'] ?? '', ['verified', 'flagged'], true)) {
        foreach (['first_name', 'middle_name', 'last_name', 'date_of_birth'] as $lockedField) {
            $data[$lockedField] = $existing[$lockedField];
        }
    }
    if (is_array($existing)) {
        foreach (array_keys($existing) as $field) {
            if (!array_key_exists($field, $data)) {
                $data[$field] = $existing[$field];
            }
        }
    }
} catch (Throwable $exception) {
    error_log(sprintf('AICS profile-save preload failed for account %d: %s', $accountId, $exception->getMessage()));
    if (isset($existingConnection) && $existingConnection instanceof mysqli) {
        try {
            $existingConnection->close();
        } catch (Throwable $closeException) {
            // Preserve the original preload error.
        }
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save profile right now.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$firstName = aics_clean_text(isset($data['first_name']) ? (string) $data['first_name'] : null);
$middleName = aics_clean_text(isset($data['middle_name']) ? (string) $data['middle_name'] : null);
$lastName = aics_clean_text(isset($data['last_name']) ? (string) $data['last_name'] : null);
$extensionName = aics_clean_text((string) ($data['extension_name'] ?? ''));
$dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
$civilStatus = aics_clean_text((string) ($data['civil_status'] ?? ''));
$civilStatusOther = aics_clean_text((string) ($data['civil_status_other'] ?? ''));
$education = aics_clean_text((string) ($data['highest_educational_attainment'] ?? ''));
$emailRaw = trim((string) ($data['email'] ?? ''));
$email = $emailRaw === '' ? null : aics_normalize_email_address($emailRaw);
$contactNumber = aics_normalize_mobile_number(isset($data['contact_number']) ? (string) $data['contact_number'] : null);
$completeAddress = aics_clean_text(isset($data['complete_address']) ? (string) $data['complete_address'] : null);
$occupation = aics_clean_text((string) ($data['occupation'] ?? ''));
$occupationOther = aics_clean_text((string) ($data['occupation_other'] ?? ''));
$incomeRaw = trim((string) ($data['estimated_monthly_income'] ?? ''));
$income = aics_is_valid_income_value($incomeRaw) ? $incomeRaw : null;
$province = aics_clean_text((string) ($data['province'] ?? ''));
$municipality = aics_clean_text((string) ($data['municipality'] ?? ''));
$barangay = aics_clean_text((string) ($data['barangay'] ?? ''));
$addressLine = aics_clean_text((string) ($data['address_line'] ?? ''));

// The displayed complete address is derived from the authoritative components.
if ($barangay !== null && $municipality !== null && $province !== null) {
    $completeAddress = implode(', ', array_filter([$addressLine, $barangay, $municipality, $province], static fn ($value) => $value !== null && $value !== ''));
}

if ($firstName === null || $lastName === null || $dateOfBirth === '' || $civilStatus === null || $education === null || $contactNumber === null || $occupation === null || $income === null || $province === null || $municipality === null || $barangay === null || ($emailRaw !== '' && $email === null)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Complete all required profile fields. Email is optional, but must be valid when provided.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
 $civilStatus = aics_normalize_civil_status($civilStatus);
if ($civilStatus === null || !in_array((string) $education, aics_education_options(), true) || !in_array((string) $occupation, aics_occupation_options(), true)) {
    http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Choose a valid educational attainment and occupation / source of income.'], JSON_UNESCAPED_UNICODE); exit;
}
if ($civilStatus === 'other' && $civilStatusOther === null) {
    http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Specify Civil Status when Others is selected.'], JSON_UNESCAPED_UNICODE); exit;
}
if ($civilStatus !== 'other') $civilStatusOther = null;
if ($occupation === 'Other' && $occupationOther === null) {
    http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Specify Occupation / Source of Income when Other is selected.'], JSON_UNESCAPED_UNICODE); exit;
}
if ($occupation !== 'Other') $occupationOther = null;
foreach (['Province' => $province, 'Municipality' => $municipality, 'Barangay' => $barangay] as $label => $value) {
    if (!aics_profile_has_value($value)) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Select or enter a valid ' . $label . '.'], JSON_UNESCAPED_UNICODE); exit;
    }
}

$dob = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
if (!$dob || $dob->format('Y-m-d') !== $dateOfBirth || $dob > new DateTime('today')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Enter a valid date of birth.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($contactNumber !== null && !preg_match('/^09\d{9}$/', $contactNumber)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Contact number must be in 09XXXXXXXXX format.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($completeAddress !== null && strlen($completeAddress) > 255) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Complete address must be 255 characters or less.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$connection = null;

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);

    // The persistence layer, rather than a stale browser snapshot, decides
    // which one success message is appropriate for this save.
    $completionBeforeSave = aics_applicant_profile_completion_state($connection, $accountId);

    $connection->begin_transaction();

    if (aics_mobile_number_in_use($connection, $contactNumber, $accountId)) {
        $connection->rollback();
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Mobile number is already used by another account.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($email !== null) {
        $emailCheck = $connection->prepare('SELECT id FROM aics_accounts WHERE email = ? AND id <> ? LIMIT 1');
        $emailCheck->bind_param('si', $email, $accountId);
        $emailCheck->execute();
        $emailExists = $emailCheck->get_result();
        if ($emailExists && $emailExists->num_rows > 0) {
            $emailCheck->close();
            $connection->rollback();

            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'message' => 'Email is already used by another account.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $emailCheck->close();
    }

    $updateAccount = $connection->prepare('UPDATE aics_accounts SET email = ? WHERE id = ? LIMIT 1');
    $updateAccount->bind_param('si', $email, $accountId);
    $updateAccount->execute();
    $updateAccount->close();

    $upsertProfile = $connection->prepare(
        'INSERT INTO aics_applicant_profiles (account_id, first_name, middle_name, last_name, extension_name, date_of_birth, civil_status, civil_status_other, highest_educational_attainment, contact_number, occupation, occupation_other, estimated_monthly_income, province, municipality, barangay, address_line, complete_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            middle_name = VALUES(middle_name),
            last_name = VALUES(last_name),
            extension_name = VALUES(extension_name), date_of_birth = VALUES(date_of_birth), civil_status = VALUES(civil_status), civil_status_other = VALUES(civil_status_other), highest_educational_attainment = VALUES(highest_educational_attainment),
            contact_number = VALUES(contact_number),
            occupation = VALUES(occupation), occupation_other = VALUES(occupation_other), estimated_monthly_income = VALUES(estimated_monthly_income), province = VALUES(province), municipality = VALUES(municipality), barangay = VALUES(barangay), address_line = VALUES(address_line),
            complete_address = VALUES(complete_address)'
    );
    $incomeValue = $income;
    $upsertProfile->bind_param('isssssssssssssssss', $accountId, $firstName, $middleName, $lastName, $extensionName, $dateOfBirth, $civilStatus, $civilStatusOther, $education, $contactNumber, $occupation, $occupationOther, $incomeValue, $province, $municipality, $barangay, $addressLine, $completeAddress);
    $upsertProfile->execute();
    $upsertProfile->close();

    $connection->commit();
    $completion = aics_applicant_profile_completion_state($connection, $accountId);
    $connection->close();

    $displayName = trim($firstName . ' ' . $lastName);
    if ($displayName === '') {
        $displayName = 'Applicant';
    }

    aics_set_user_session($accountId, (string) ($email ?? ''), 'applicat', $displayName);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => $completion['complete']
            ? 'Profile updated successfully. You can now submit a request.'
            : ('Profile saved, but it is incomplete. Missing: ' . implode(', ', $completion['missing_fields']) . '.'),
        'profile' => [
            'first_name' => $firstName,
            'middle_name' => $middleName ?? '',
            'last_name' => $lastName,
            'extension_name' => $extensionName ?? '', 'date_of_birth' => $dateOfBirth, 'civil_status' => $civilStatus, 'civil_status_other' => $civilStatusOther ?? '', 'highest_educational_attainment' => $education,
            'email' => (string) ($email ?? ''),
            'contact_number' => $contactNumber ?? '',
            'complete_address' => $completeAddress ?? '',
            'occupation' => $occupation, 'occupation_other' => $occupationOther ?? '', 'estimated_monthly_income' => $incomeValue, 'province' => $province, 'municipality' => $municipality, 'barangay' => $barangay, 'address_line' => $addressLine,
            'display_name' => $displayName,
        ],
        'profile_complete' => $completion['complete'],
        'profile_complete_before_save' => $completionBeforeSave['complete'],
        'profile_complete_after_save' => $completion['complete'],
        'missing_profile_fields' => $completion['missing_fields'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        try {
            $connection->rollback();
            $connection->close();
        } catch (Throwable $innerException) {
            // no-op
        }
    }

    if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
        http_response_code(409);
        $constraint = strtolower($exception->getMessage());
        $message = str_contains($constraint, 'contact_number')
            ? 'Mobile number is already used by another account.'
            : 'Email is already used by another account.';
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to save profile right now.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
