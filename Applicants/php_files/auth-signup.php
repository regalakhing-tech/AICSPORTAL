<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/applicant-id-verification.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aics_redirect_with_message('../login.html', 'invalid_method', 'register');
}

$firstName = aics_validate_person_name(isset($_POST['first_name']) ? (string) $_POST['first_name'] : null);
$middleName = aics_validate_person_name(isset($_POST['middle_name']) ? (string) $_POST['middle_name'] : null, false);
$lastName = aics_validate_person_name(isset($_POST['last_name']) ? (string) $_POST['last_name'] : null);
$contactNumber = aics_normalize_mobile_number(isset($_POST['contact_number']) ? (string) $_POST['contact_number'] : null);
$emailRaw = trim((string) ($_POST['email'] ?? ''));
$email = $emailRaw === '' ? null : aics_normalize_email_address($emailRaw);
$birthdateRaw = trim((string) ($_POST['date_of_birth'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$attemptId = aics_normalize_signup_attempt_id((string) ($_POST['signup_attempt_id'] ?? ''));

if ($firstName === null || $middleName === null || $lastName === null) {
    aics_redirect_with_message('../login.html', 'invalid_name', 'register');
}
if ($contactNumber === null || $password === '' || $birthdateRaw === '' || $attemptId === null) {
    aics_redirect_with_message('../login.html', 'missing_fields', 'register');
}
if ($emailRaw !== '' && $email === null) {
    aics_redirect_with_message('../login.html', 'invalid_email', 'register');
}

$dob = DateTime::createFromFormat('Y-m-d', $birthdateRaw);
if (!$dob || $dob->format('Y-m-d') !== $birthdateRaw) {
    aics_redirect_with_message('../login.html', 'invalid_birthdate', 'register');
}

$today = new DateTime('today');
if ($dob > $today) {
    aics_redirect_with_message('../login.html', 'invalid_birthdate', 'register');
}

$age = (int) $dob->diff($today)->y;
if ($age < 18) {
    aics_redirect_with_message('../login.html', 'underage', 'register');
}

if (aics_password_policy_error($password) !== null) {
    aics_redirect_with_message('../login.html', 'weak_password', 'register');
}
if ($password !== $confirmPassword) {
    aics_redirect_with_message('../login.html', 'password_mismatch', 'register');
}

aics_start_session();
aics_signup_otp_migrate_legacy_attempt($_SESSION);
$submittedOtp = preg_replace('/\D/', '', (string) ($_POST['otp_code'] ?? ''));
$otpAttempt = aics_signup_otp_attempt($_SESSION, $attemptId, $contactNumber);
if ($otpAttempt !== null) {
    $expectedOtp = $otpAttempt['code'];
    $otpExpiry = $otpAttempt['expires_at'];
    $now = time();

    if ($now > $otpExpiry) {
        aics_redirect_with_message('../login.html', 'otp_expired', 'register');
    }
    if ($submittedOtp === '' || $submittedOtp !== $expectedOtp) {
        aics_redirect_with_message('../login.html', 'invalid_otp', 'register');
    }
}

// Step 1 of sign-up: the valid ID read by AICS AI must belong to this person.
$idScan = is_array($_SESSION['aics_signup_id'][$attemptId] ?? null) ? $_SESSION['aics_signup_id'][$attemptId] : null;
$idResult = is_array($idScan['result'] ?? null) ? $idScan['result'] : null;
if ($idResult === null) {
    aics_redirect_with_message('../login.html', 'id_required', 'register');
}
// Verification is automatic: only an ID AICS AI read successfully, with the
// same name and birth date, can create an account.
if (!aics_id_result_is_verified($idResult)) {
    aics_redirect_with_message('../login.html', 'id_not_verified', 'register');
}
if (!aics_id_names_match($idResult, $firstName, $middleName, $lastName)['match']) {
    aics_redirect_with_message('../login.html', 'id_name_mismatch', 'register');
}
if (!aics_id_birthdates_match(is_string($idResult['birth_date'] ?? null) ? $idResult['birth_date'] : null, $birthdateRaw)) {
    aics_redirect_with_message('../login.html', 'id_birthdate_mismatch', 'register');
}

$connection = null;
try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    $connection->begin_transaction();

    if (aics_mobile_number_in_use($connection, $contactNumber)) {
        $connection->rollback();
        aics_redirect_with_message('../login.html', 'phone_exists', 'register');
    }

    if ($email !== null) {
        $emailCheck = $connection->prepare('SELECT id FROM aics_accounts WHERE email = ? LIMIT 1');
        $emailCheck->bind_param('s', $email);
        $emailCheck->execute();
        if (($emailCheck->get_result()?->num_rows ?? 0) > 0) {
            $emailCheck->close();
            $connection->rollback();
            aics_redirect_with_message('../login.html', 'email_exists', 'register');
        }
        $emailCheck->close();
    }

    $role = $connection->query("SELECT id FROM aics_roles WHERE role_code = 'applicat' LIMIT 1")?->fetch_assoc();
    $status = $connection->query("SELECT id FROM aics_account_statuses WHERE status_code = 'active' LIMIT 1")?->fetch_assoc();
    $roleId = (int) ($role['id'] ?? 0);
    $statusId = (int) ($status['id'] ?? 0);
    if ($roleId <= 0 || $statusId <= 0) {
        throw new RuntimeException('Required account settings are unavailable.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $account = $connection->prepare('INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)');
    $account->bind_param('iiss', $roleId, $statusId, $email, $passwordHash);
    $account->execute();
    $accountId = (int) $connection->insert_id;
    $account->close();

    $profile = $connection->prepare('INSERT INTO aics_applicant_profiles (account_id, first_name, middle_name, last_name, contact_number, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)');
    $profile->bind_param('isssss', $accountId, $firstName, $middleName, $lastName, $contactNumber, $birthdateRaw);
    $profile->execute();
    $profile->close();
    aics_id_record_verification($connection, $accountId, $idScan);
    $connection->commit();
    $connection->close();
    aics_signup_otp_forget_attempt($_SESSION, $attemptId);
    unset($_SESSION['aics_signup_id'][$attemptId]);
    aics_redirect_with_message('../login.html', 'signup_success');
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        @$connection->rollback();
        $connection->close();
    }
    if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
        $constraint = strtolower($exception->getMessage());
        if (str_contains($constraint, 'contact_number')) {
            aics_redirect_with_message('../login.html', 'phone_exists', 'register');
        }
        if (str_contains($constraint, 'email')) {
            aics_redirect_with_message('../login.html', 'email_exists', 'register');
        }
    }
    aics_redirect_with_message('../login.html', 'signup_failed', 'register');
}
