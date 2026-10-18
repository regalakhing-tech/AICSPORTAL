<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/applicant-id-verification.php';

aics_start_session();

if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
if ($accountId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid session',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);

    $sql = <<<SQL
        SELECT
            a.email,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.extension_name, p.date_of_birth, p.civil_status, p.civil_status_other, p.highest_educational_attainment,
            p.contact_number,
            p.occupation, p.occupation_other, p.estimated_monthly_income, p.province, p.municipality, p.barangay, p.address_line,
            p.complete_address
        FROM aics_accounts a
        LEFT JOIN aics_applicant_profiles p ON p.account_id = a.id
        WHERE a.id = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Profile not found',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $firstName = trim((string) ($row['first_name'] ?? ''));
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    $contactNumber = aics_normalize_mobile_number((string) ($row['contact_number'] ?? '')) ?? '';
    $completeAddress = trim((string) ($row['complete_address'] ?? ''));

    $displayName = trim(implode(' ', array_filter([$firstName, $lastName])));
    if ($displayName === '') {
        $displayName = trim((string) ($_SESSION['aics_display_name'] ?? 'Applicant'));
    }
    if ($displayName === '') {
        $displayName = 'Applicant';
    }

    $completion = aics_applicant_profile_completion_state($connection, $accountId);
    $idVerification = aics_id_verification_for_account($connection, $accountId);
    $idPhotosAvailable = is_array($idVerification)
        && aics_id_photo_path_for_account($accountId, $idVerification, 'front') !== null
        && aics_id_photo_path_for_account($accountId, $idVerification, 'back') !== null;
    $connection->close();
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'profile_complete' => $completion['complete'],
        'profile_saved' => $completion['complete'],
        'missing_profile_fields' => $completion['missing_fields'],
        'identity_locked' => is_array($idVerification) && in_array($idVerification['status'] ?? '', ['verified', 'flagged'], true),
        'id_type_label' => is_array($idVerification) ? (string) ($idVerification['id_type_label'] ?? '') : '',
        'id_photos_available' => $idPhotosAvailable,
        'profile' => [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'extension_name' => trim((string) ($row['extension_name'] ?? '')),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'civil_status' => aics_normalize_civil_status($row['civil_status'] ?? null) ?? '',
            'civil_status_other' => trim((string) ($row['civil_status_other'] ?? '')),
            'highest_educational_attainment' => trim((string) ($row['highest_educational_attainment'] ?? '')),
            'email' => $email,
            'contact_number' => $contactNumber,
            'complete_address' => $completeAddress,
            'occupation' => trim((string) ($row['occupation'] ?? '')),
            'occupation_other' => trim((string) ($row['occupation_other'] ?? '')),
            'estimated_monthly_income' => aics_income_value($row['estimated_monthly_income'] ?? null) ?? '',
            'province' => trim((string) ($row['province'] ?? '')),
            'municipality' => trim((string) ($row['municipality'] ?? '')),
            'barangay' => trim((string) ($row['barangay'] ?? '')),
            'address_line' => trim((string) ($row['address_line'] ?? '')),
            'display_name' => $displayName,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    error_log(sprintf('AICS profile-get failed for account %d: %s', $accountId, $exception->getMessage()));
    if (isset($connection) && $connection instanceof mysqli) {
        try {
            $connection->close();
        } catch (Throwable $closeException) {
            // The original error is the useful diagnostic.
        }
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => "We couldn't load your profile information. Please refresh and try again.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
