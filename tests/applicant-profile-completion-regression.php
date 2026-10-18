<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function profile_completion_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$connection = aics_db_connect();
aics_ensure_auth_schema($connection);
$accountId = 0;

try {
    $role = $connection->query("SELECT id FROM aics_roles WHERE role_code = 'applicat' LIMIT 1")?->fetch_assoc();
    $status = $connection->query("SELECT id FROM aics_account_statuses WHERE status_code = 'active' LIMIT 1")?->fetch_assoc();
    $roleId = (int) ($role['id'] ?? 0);
    $statusId = (int) ($status['id'] ?? 0);
    profile_completion_assert($roleId > 0 && $statusId > 0, 'Applicant role/status fixtures are unavailable.');

    do {
        $phone = '09' . random_int(100000000, 999999999);
    } while (aics_mobile_number_in_use($connection, $phone));

    $passwordHash = password_hash('ProfileRegression2026', PASSWORD_DEFAULT);
    $account = $connection->prepare('INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, NULL, ?)');
    $account->bind_param('iis', $roleId, $statusId, $passwordHash);
    $account->execute();
    $accountId = (int) $connection->insert_id;
    $account->close();

    $profile = $connection->prepare('INSERT INTO aics_applicant_profiles (account_id, first_name, last_name, contact_number) VALUES (?, ?, ?, ?)');
    $firstName = 'Profile';
    $lastName = 'Regression';
    $profile->bind_param('isss', $accountId, $firstName, $lastName, $phone);
    $profile->execute();
    $profile->close();

    $state = aics_applicant_profile_completion_state($connection, $accountId);
    profile_completion_assert($state['complete'] === false, 'Incomplete profile was incorrectly considered complete.');

    $complete = $connection->prepare(
        'UPDATE aics_applicant_profiles SET date_of_birth = ?, civil_status = ?, highest_educational_attainment = ?, occupation = ?, estimated_monthly_income = ?, province = ?, municipality = ?, barangay = ?, address_line = ?, complete_address = ? WHERE account_id = ?'
    );
    $values = ['1990-01-01', 'single', 'College Graduate', 'Student', 'No Income', 'Antique', 'San Jose de Buenavista', 'Atabay', 'Purok 1', 'Purok 1, Atabay, San Jose de Buenavista, Antique'];
    $complete->bind_param('ssssssssssi', $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7], $values[8], $values[9], $accountId);
    $complete->execute();
    $complete->close();

    $state = aics_applicant_profile_completion_state($connection, $accountId);
    profile_completion_assert($state['complete'] === true, 'Completed profile did not become complete.');

    $connection->query("UPDATE aics_applicant_profiles SET address_line = NULL WHERE account_id = {$accountId}");
    $state = aics_applicant_profile_completion_state($connection, $accountId);
    profile_completion_assert($state['complete'] === true, 'Optional House No./address line incorrectly makes a profile incomplete.');

    echo "Applicant authoritative profile-completion regression checks passed.\n";
} finally {
    if ($accountId > 0) {
        $delete = $connection->prepare('DELETE FROM aics_accounts WHERE id = ? LIMIT 1');
        $delete->bind_param('i', $accountId);
        $delete->execute();
        $delete->close();
    }
    $connection->close();
}
