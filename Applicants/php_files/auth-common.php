<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

/**
 * Converts database nulls and legacy literal "NULL" values into safe UI text.
 * This is deliberately presentation-only: optional database columns may remain NULL.
 */
function aics_display_value(mixed $value): string
{
    if ($value === null || (is_string($value) && strtoupper(trim($value)) === 'NULL')) {
        return '';
    }

    return (string) $value;
}

function aics_display_h(mixed $value): string
{
    return htmlspecialchars(aics_display_value($value), ENT_QUOTES, 'UTF-8');
}

function aics_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        $httpsOn = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        if ($httpsOn) {
            ini_set('session.cookie_secure', '1');
        }

        session_name('AICSAPPLICANTSESSID');
        session_start();
    }
}

function aics_destroy_session(): void
{
    aics_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function aics_ensure_auth_schema(mysqli $connection): void
{
    $queries = [
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_roles (
                id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_code VARCHAR(30) NOT NULL,
                role_name VARCHAR(80) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_roles_role_code (role_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_account_statuses (
                id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                status_code VARCHAR(30) NOT NULL,
                status_name VARCHAR(80) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_account_statuses_status_code (status_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_id TINYINT UNSIGNED NOT NULL,
                account_status_id TINYINT UNSIGNED NOT NULL,
                email VARCHAR(180) DEFAULT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                session_invalidate_at TIMESTAMP NULL DEFAULT NULL,
                auth_revision INT UNSIGNED NOT NULL DEFAULT 0,
                force_password_change TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_accounts_email (email),
                KEY idx_aics_accounts_role_id (role_id),
                KEY idx_aics_accounts_status_id (account_status_id),
                CONSTRAINT fk_aics_accounts_role_id FOREIGN KEY (role_id) REFERENCES aics_roles(id),
                CONSTRAINT fk_aics_accounts_status_id FOREIGN KEY (account_status_id) REFERENCES aics_account_statuses(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_applicant_profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id INT UNSIGNED NOT NULL,
                first_name VARCHAR(120) NOT NULL,
                middle_name VARCHAR(120) DEFAULT NULL,
                last_name VARCHAR(120) NOT NULL,
                extension_name VARCHAR(20) DEFAULT NULL,
                date_of_birth DATE DEFAULT NULL,
                civil_status VARCHAR(30) DEFAULT NULL,
                highest_educational_attainment VARCHAR(150) DEFAULT NULL,
                contact_number VARCHAR(15) DEFAULT NULL,
                occupation VARCHAR(150) DEFAULT NULL,
                occupation_other VARCHAR(150) DEFAULT NULL,
                estimated_monthly_income VARCHAR(40) DEFAULT NULL,
                province VARCHAR(100) DEFAULT NULL,
                municipality VARCHAR(120) DEFAULT NULL,
                barangay VARCHAR(120) DEFAULT NULL,
                address_line VARCHAR(255) DEFAULT NULL,
                complete_address VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_applicant_profiles_account_id (account_id),
                UNIQUE KEY uq_aics_applicant_profiles_contact_number (contact_number),
                KEY idx_aics_applicant_profiles_last_name (last_name),
                CONSTRAINT fk_aics_applicant_profiles_account_id FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    ];

    foreach ($queries as $query) {
        $connection->query($query);
    }

    // Keep existing authentication schema revisions compatible.
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS complete_address VARCHAR(255) DEFAULT NULL AFTER contact_number");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS extension_name VARCHAR(20) DEFAULT NULL AFTER last_name");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS date_of_birth DATE DEFAULT NULL AFTER extension_name");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS civil_status VARCHAR(30) DEFAULT NULL AFTER date_of_birth");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS civil_status_other VARCHAR(120) DEFAULT NULL AFTER civil_status");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS highest_educational_attainment VARCHAR(150) DEFAULT NULL AFTER civil_status");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS occupation VARCHAR(150) DEFAULT NULL AFTER contact_number");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS occupation_other VARCHAR(150) DEFAULT NULL AFTER occupation");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS estimated_monthly_income VARCHAR(40) DEFAULT NULL AFTER occupation");
    aics_migrate_income_column($connection, 'aics_applicant_profiles', 'estimated_monthly_income');
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS province VARCHAR(100) DEFAULT NULL AFTER estimated_monthly_income");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS municipality VARCHAR(120) DEFAULT NULL AFTER province");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS barangay VARCHAR(120) DEFAULT NULL AFTER municipality");
    $connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS address_line VARCHAR(255) DEFAULT NULL AFTER barangay");
    $connection->query("ALTER TABLE aics_accounts MODIFY COLUMN email VARCHAR(180) DEFAULT NULL");
    $connection->query("ALTER TABLE aics_accounts ADD COLUMN IF NOT EXISTS session_invalidate_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    $connection->query("ALTER TABLE aics_accounts ADD COLUMN IF NOT EXISTS auth_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER session_invalidate_at");
    $connection->query("ALTER TABLE aics_accounts ADD COLUMN IF NOT EXISTS force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER session_invalidate_at");
    aics_ensure_canonical_applicant_phones($connection);

    // Seed lookup tables (3NF reference data)
    $connection->query("INSERT IGNORE INTO aics_roles (id, role_code, role_name) VALUES (1, 'applicat', 'Applicant')");
    $connection->query("INSERT IGNORE INTO aics_account_statuses (id, status_code, status_name) VALUES (1, 'active', 'Active')");
    $connection->query("INSERT IGNORE INTO aics_account_statuses (status_code, status_name) VALUES ('restricted', 'Restricted')");
    $connection->query("INSERT IGNORE INTO aics_account_statuses (status_code, status_name) VALUES ('disabled', 'Disabled')");
    $connection->query("INSERT IGNORE INTO aics_account_statuses (status_code, status_name) VALUES ('locked', 'Locked')");
    $connection->query("INSERT IGNORE INTO aics_account_statuses (status_code, status_name) VALUES ('banned', 'Banned')");

    aics_ensure_login_attempts_schema($connection);
}

function aics_normalize_mobile_number(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || preg_match('/^\+?[0-9() .-]+$/', $value) !== 1) {
        return null;
    }

    $hasInternationalPrefix = str_starts_with($value, '+');
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits)) {
        return null;
    }
    if (!$hasInternationalPrefix && preg_match('/^09\d{9}$/', $digits) === 1) {
        return $digits;
    }
    if (preg_match('/^639\d{9}$/', $digits) === 1) {
        return '0' . substr($digits, 2);
    }
    return null;
}

function aics_normalize_signup_attempt_id(?string $value): ?string
{
    $value = strtolower(trim((string) $value));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1
        ? $value
        : null;
}

/** @return array{phone:string,code:string,sent_at:int,expires_at:int}|null */
function aics_signup_otp_attempt(array $session, string $attemptId, string $phone): ?array
{
    $canonicalAttemptId = aics_normalize_signup_attempt_id($attemptId);
    $canonicalPhone = aics_normalize_mobile_number($phone);
    $attempts = $session['aics_signup_otp_attempts'] ?? null;
    if (
        $canonicalAttemptId === null
        || $canonicalPhone === null
        || !is_array($attempts)
        || !is_array($attempts[$canonicalAttemptId] ?? null)
        || !is_array($attempts[$canonicalAttemptId][$canonicalPhone] ?? null)
    ) {
        return null;
    }

    $attempt = $attempts[$canonicalAttemptId][$canonicalPhone];
    return [
        'phone' => $canonicalPhone,
        'code' => trim((string) ($attempt['code'] ?? '')),
        'sent_at' => (int) ($attempt['sent_at'] ?? 0),
        'expires_at' => (int) ($attempt['expires_at'] ?? 0),
    ];
}

function aics_signup_otp_store_attempt(
    array &$session,
    string $attemptId,
    string $phone,
    string $code,
    int $sentAt,
    int $ttlSeconds
): void {
    $canonicalAttemptId = aics_normalize_signup_attempt_id($attemptId);
    $canonicalPhone = aics_normalize_mobile_number($phone);
    if ($canonicalAttemptId === null) {
        throw new InvalidArgumentException('A valid signup attempt ID is required for an OTP attempt.');
    }
    if ($canonicalPhone === null) {
        throw new InvalidArgumentException('A valid mobile number is required for an OTP attempt.');
    }

    if (!is_array($session['aics_signup_otp_attempts'] ?? null)) {
        $session['aics_signup_otp_attempts'] = [];
    }
    if (!is_array($session['aics_signup_otp_attempts'][$canonicalAttemptId] ?? null)) {
        $session['aics_signup_otp_attempts'][$canonicalAttemptId] = [];
    }
    $session['aics_signup_otp_attempts'][$canonicalAttemptId][$canonicalPhone] = [
        'phone' => $canonicalPhone,
        'code' => trim($code),
        'sent_at' => $sentAt,
        'expires_at' => $sentAt + max(0, $ttlSeconds),
    ];
}

function aics_signup_otp_cooldown_remaining(
    array $session,
    string $attemptId,
    string $phone,
    int $now,
    int $cooldownSeconds
): int {
    $attempt = aics_signup_otp_attempt($session, $attemptId, $phone);
    if ($attempt === null || $attempt['sent_at'] <= 0) {
        return 0;
    }

    $elapsed = max(0, $now - $attempt['sent_at']);
    return max(0, $cooldownSeconds - $elapsed);
}

function aics_signup_otp_forget_attempt(array &$session, string $attemptId): void
{
    $canonicalAttemptId = aics_normalize_signup_attempt_id($attemptId);
    if ($canonicalAttemptId === null || !is_array($session['aics_signup_otp_attempts'] ?? null)) {
        return;
    }

    unset($session['aics_signup_otp_attempts'][$canonicalAttemptId]);
    if ($session['aics_signup_otp_attempts'] === []) {
        unset($session['aics_signup_otp_attempts']);
    }
}

function aics_signup_otp_migrate_legacy_attempt(array &$session): void
{
    unset(
        $session['aics_signup_otp'],
        $session['aics_otp_sent_time'],
        $session['aics_otp_phone'],
        $session['aics_otp_expires']
    );
}

function aics_normalize_email_address(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    return strtolower($value);
}

function aics_mobile_number_in_use(mysqli $connection, string $canonicalPhone, int $excludeAccountId = 0): bool
{
    $stmt = $connection->prepare(
        'SELECT account_id, contact_number FROM aics_applicant_profiles WHERE contact_number IS NOT NULL AND account_id <> ?'
    );
    $stmt->bind_param('i', $excludeAccountId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (aics_normalize_mobile_number((string) ($row['contact_number'] ?? '')) === $canonicalPhone) {
            $stmt->close();
            return true;
        }
    }

    $stmt->close();
    return false;
}

/**
 * Canonicalizes valid legacy applicant phones before enforcing database-level
 * uniqueness. Existing collisions are preserved for manual resolution.
 */
function aics_ensure_canonical_applicant_phones(mysqli $connection): void
{
    $result = $connection->query(
        "SELECT id, account_id, contact_number FROM aics_applicant_profiles WHERE contact_number IS NOT NULL AND TRIM(contact_number) <> ''"
    );
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $owners = [];
    $updates = [];
    $hasCollision = false;

    foreach ($rows as $row) {
        $canonical = aics_normalize_mobile_number((string) ($row['contact_number'] ?? ''));
        if ($canonical === null) {
            continue;
        }

        $accountId = (int) ($row['account_id'] ?? 0);
        if (isset($owners[$canonical]) && $owners[$canonical] !== $accountId) {
            $hasCollision = true;
            continue;
        }

        $owners[$canonical] = $accountId;
        if ($canonical !== (string) $row['contact_number']) {
            $updates[] = [(int) $row['id'], $canonical];
        }
    }

    if ($hasCollision) {
        error_log('AICS phone uniqueness migration skipped: existing applicant accounts share an equivalent mobile number.');
        return;
    }

    if ($updates !== []) {
        $update = $connection->prepare('UPDATE aics_applicant_profiles SET contact_number = ? WHERE id = ? LIMIT 1');
        foreach ($updates as [$profileId, $canonical]) {
            $update->bind_param('si', $canonical, $profileId);
            $update->execute();
        }
        $update->close();
    }

    if (!aics_table_has_index($connection, 'aics_applicant_profiles', 'uq_aics_applicant_profiles_contact_number')) {
        $connection->query(
            'ALTER TABLE aics_applicant_profiles ADD UNIQUE KEY uq_aics_applicant_profiles_contact_number (contact_number)'
        );
    }
}

function aics_validate_person_name(?string $value, bool $required = true): ?string
{
    $value = $value === null ? '' : trim($value);
    if ($value === '') return $required ? null : '';
    if (!$required && preg_match('/^\p{L}\.$/u', $value) === 1) $value = substr($value, 0, -1);
    $value = preg_replace('/\s+/u', ' ', $value);
    if (!is_string($value) || preg_match("/^[\\p{L}][\\p{L}\\p{M} '\\-]*$/u", $value) !== 1) return null;
    return $value;
}

/** @return array<string,string> */
function aics_civil_status_options(): array
{
    return ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated', 'annulled' => 'Annulled', 'divorced' => 'Divorced'];
}

function aics_normalize_civil_status(?string $value): ?string
{
    $value = strtolower(trim((string) $value));
    $value = match ($value) { 'widow_er', 'widow', 'widower' => 'widowed', 'others' => 'other', default => $value };
    return array_key_exists($value, aics_civil_status_options()) ? $value : null;
}

function aics_password_policy_error(string $password, int $minimumLength = 8): ?string
{
    if (strlen($password) < $minimumLength) return "Password must be at least {$minimumLength} characters.";
    if (preg_match('/\s/', $password) === 1) return 'Password cannot contain spaces.';
    if (preg_match('/[A-Z]/', $password) !== 1) return 'Password must include at least one uppercase letter.';
    if (preg_match('/[a-z]/', $password) !== 1) return 'Password must include at least one lowercase letter.';
    if (preg_match('/[0-9]/', $password) !== 1) return 'Password must include at least one number.';
    return null;
}

function aics_ensure_notification_read_schema(mysqli $connection): void
{
    $connection->query("CREATE TABLE IF NOT EXISTS aics_applicant_notification_reads (
        account_id INT UNSIGNED NOT NULL, notification_id BIGINT UNSIGNED NOT NULL,
        read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (account_id, notification_id), KEY idx_aics_notification_reads_notification (notification_id),
        CONSTRAINT fk_aics_notification_reads_account FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** SQL condition for request notifications that applicants may see. */
function aics_applicant_notification_visibility_sql(): string
{
    return "(n.channel IS NULL OR n.channel <> 'mswdo_notification')";
}

/** @return array<int,string> */
function aics_education_options(): array
{
    return ['No Formal Education', 'Elementary Undergraduate', 'Elementary Graduate', 'Junior High School', 'Senior High School', 'Vocational / Technical', 'College Undergraduate', 'College Graduate', 'Postgraduate'];
}

/**
 * How the applicant is related to the beneficiary. "Self" is not listed: it is
 * saved when "Same as Applicant" is checked.
 *
 * @return array<int,string>
 */
function aics_applicant_relationship_options(): array
{
    return ['Spouse', 'Child', 'Parent', 'Sibling', 'Grandparent', 'Grandchild', 'Other Relative', 'Non-relative'];
}

/** "Self" when the applicant is the beneficiary, otherwise the chosen relationship. */
function aics_applicant_relationship_from_post(array $post): string
{
    if ((string) ($post['applicant_is_beneficiary'] ?? '') === '1') return 'Self';
    $relationship = trim((string) ($post['applicant_relationship'] ?? ''));
    if (!in_array($relationship, aics_applicant_relationship_options(), true)) {
        throw new RuntimeException('Select your relationship to the beneficiary.');
    }
    return $relationship;
}

/** The family's one estimated monthly income (all members combined); always required. */
function aics_family_total_income_from_post(array $post): string
{
    $income = trim((string) ($post['family_total_income'] ?? ''));
    if (!aics_is_valid_income_value($income)) {
        throw new RuntimeException('Select the total estimated monthly income of the whole household.');
    }
    return $income;
}

/** Adds the applicant relationship and household income columns to older databases, once per connection. */
function aics_ensure_request_intake_columns(mysqli $connection): void
{
    static $checked = [];
    $key = spl_object_id($connection);
    if (isset($checked[$key])) return;
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_relationship VARCHAR(40) DEFAULT NULL");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS family_total_income VARCHAR(40) DEFAULT NULL");
    $checked[$key] = true;
}

/** @return array<int,string> */
function aics_occupation_options(): array
{
    return ['Student', 'Employed – Private', 'Employed – Government', 'Self-Employed', 'Farmer', 'Fisherfolk', 'Driver', 'Vendor', 'Daily Wage / Laborer', 'OFW', 'Pensioner', 'Unemployed', 'Homemaker', 'Other'];
}

/** @return array<int,string> */
function aics_income_options(): array
{
    return ['No Income', 'Below ₱10,000', '₱10,000 – ₱19,999', '₱20,000 – ₱29,999', '₱30,000 – ₱39,999', '₱40,000 – ₱49,999', '₱50,000 and above'];
}

/** Converts a legacy numeric amount to its standardized income range. */
function aics_income_value(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || strtoupper($value) === 'NULL') return null;
    if (in_array($value, aics_income_options(), true)) return $value;
    if (!is_numeric($value) || (float) $value < 0) return null;
    $income = (float) $value;
    if ($income == 0.0) return 'No Income';
    if ($income < 10000) return 'Below ₱10,000';
    if ($income < 20000) return '₱10,000 – ₱19,999';
    if ($income < 30000) return '₱20,000 – ₱29,999';
    if ($income < 40000) return '₱30,000 – ₱39,999';
    if ($income < 50000) return '₱40,000 – ₱49,999';
    return '₱50,000 and above';
}

function aics_is_valid_income_value(mixed $value): bool
{
    return in_array(trim((string) $value), aics_income_options(), true);
}

/** Makes a numeric legacy column compatible and maps only legacy numeric rows. */
function aics_migrate_income_column(mysqli $connection, string $table, string $column): void
{
    $connection->query("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` VARCHAR(40) DEFAULT NULL");
    $connection->query("UPDATE `{$table}` SET `{$column}` = CASE
        WHEN CAST(`{$column}` AS DECIMAL(12,2)) = 0 THEN 'No Income'
        WHEN CAST(`{$column}` AS DECIMAL(12,2)) < 10000 THEN 'Below ₱10,000'
        WHEN CAST(`{$column}` AS DECIMAL(12,2)) < 20000 THEN '₱10,000 – ₱19,999'
        WHEN CAST(`{$column}` AS DECIMAL(12,2)) < 30000 THEN '₱20,000 – ₱29,999'
        WHEN CAST(`{$column}` AS DECIMAL(12,2)) < 40000 THEN '₱30,000 – ₱39,999'
        WHEN CAST(`{$column}` AS DECIMAL(12,2)) < 50000 THEN '₱40,000 – ₱49,999'
        ELSE '₱50,000 and above' END
        WHERE `{$column}` REGEXP '^[0-9]+(\\.[0-9]+)?$'");
}

function aics_profile_has_value(mixed $value): bool
{
    $value = trim((string) $value);
    return $value !== '' && !preg_match('/^select\b/i', $value);
}


/** @return array{complete:bool,missing_fields:array<int,string>} */
function aics_applicant_profile_completion_state(mysqli $connection, int $accountId): array
{
    $stmt = $connection->prepare(
        'SELECT first_name, last_name, date_of_birth, civil_status, civil_status_other, highest_educational_attainment, contact_number, occupation, occupation_other, estimated_monthly_income, province, municipality, barangay, complete_address FROM aics_applicant_profiles WHERE account_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $profile = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $missing = [];
    if (!is_array($profile)) {
        return ['complete' => false, 'missing_fields' => ['First Name', 'Last Name', 'Date of Birth', 'Civil Status', 'Highest Educational Attainment', 'Phone Number', 'Occupation / Source of Income', 'Estimated Monthly Income', 'Province', 'Municipality', 'Barangay']];
    }

    if (!aics_profile_has_value($profile['first_name'] ?? null)) $missing[] = 'First Name';
    if (!aics_profile_has_value($profile['last_name'] ?? null)) $missing[] = 'Last Name';
    $dob = DateTime::createFromFormat('Y-m-d', trim((string) ($profile['date_of_birth'] ?? '')));
    if (!$dob || $dob->format('Y-m-d') !== (string) ($profile['date_of_birth'] ?? '') || $dob > new DateTime('today')) $missing[] = 'Date of Birth';
    if (aics_normalize_civil_status($profile['civil_status'] ?? null) === null) $missing[] = 'Civil Status';
    if (aics_normalize_civil_status($profile['civil_status'] ?? null) === 'other' && !aics_profile_has_value($profile['civil_status_other'] ?? null)) $missing[] = 'Specify Civil Status';
    if (!in_array(trim((string) ($profile['highest_educational_attainment'] ?? '')), aics_education_options(), true)) $missing[] = 'Highest Educational Attainment';
    if (aics_normalize_mobile_number((string) ($profile['contact_number'] ?? '')) === null) $missing[] = 'Phone Number';
    if (!in_array(trim((string) ($profile['occupation'] ?? '')), aics_occupation_options(), true)) $missing[] = 'Occupation / Source of Income';
    if ((string) ($profile['occupation'] ?? '') === 'Other' && trim((string) ($profile['occupation_other'] ?? '')) === '') $missing[] = 'Specify Occupation / Source of Income';
    if (!aics_is_valid_income_value($profile['estimated_monthly_income'] ?? null)) $missing[] = 'Estimated Monthly Income';
    if (!aics_profile_has_value($profile['province'] ?? null)) $missing[] = 'Province';
    if (!aics_profile_has_value($profile['municipality'] ?? null)) $missing[] = 'Municipality';
    if (!aics_profile_has_value($profile['barangay'] ?? null)) $missing[] = 'Barangay';

    return ['complete' => $missing === [], 'missing_fields' => $missing];
}

function aics_is_applicant_profile_complete(mysqli $connection, int $accountId): bool
{
    return aics_applicant_profile_completion_state($connection, $accountId)['complete'];
}


function aics_set_user_session(int $accountId, string $email, string $roleCode, string $displayName, ?int $authRevision = null): void
{
    aics_start_session();
    $previousAccountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $previousRevision = (int) ($_SESSION['aics_auth_revision'] ?? 0);
    $_SESSION['aics_account_id'] = $accountId;
    $_SESSION['aics_email'] = $email;
    $_SESSION['aics_role_code'] = $roleCode;
    $_SESSION['aics_display_name'] = $displayName;
    $_SESSION['aics_account_status_code'] = 'active';
    $_SESSION['aics_banned_modal_required'] = 0;
    $_SESSION['aics_auth_revision'] = $authRevision ?? ($previousAccountId === $accountId ? $previousRevision : 0);
    // Session login timestamp — compared against accounts.session_invalidate_at
    // to force re-login when admin changes a user's role or status.
    $_SESSION['aics_session_started_at'] = time();
}

/**
 * Rejects sessions from before an auth revision or status change.
 */
function aics_session_is_invalidated(mysqli $connection, int $accountId): bool
{
    if ($accountId <= 0) {
        return false;
    }
    $stmt = $connection->prepare('SELECT UNIX_TIMESTAMP(session_invalidate_at) AS invalidate_ts, auth_revision FROM aics_accounts WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    if (is_array($row) && (int) ($row['auth_revision'] ?? 0) !== (int) ($_SESSION['aics_auth_revision'] ?? 0)) {
        return true;
    }
    $startedAt = (int) ($_SESSION['aics_session_started_at'] ?? 0);
    if ($startedAt <= 0) {
        return false;
    }
    $invalidateTs = (int) ($row['invalidate_ts'] ?? 0);
    return $invalidateTs > 0 && $invalidateTs > $startedAt;
}

function aics_has_role_session(string $requiredRoleCode): bool
{
    aics_start_session();
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $sessionRoleCode = strtolower(trim((string) ($_SESSION['aics_role_code'] ?? '')));
    $requiredRoleCode = strtolower(trim($requiredRoleCode));

    return $accountId > 0 && $requiredRoleCode !== '' && $sessionRoleCode === $requiredRoleCode;
}

function aics_refresh_session_status_for_role(string $requiredRoleCode): ?string
{
    if (!aics_has_role_session($requiredRoleCode)) {
        return null;
    }

    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    try {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);
        $statusCode = aics_fetch_account_status_for_role($connection, $accountId, $requiredRoleCode);
        $invalidated = aics_session_is_invalidated($connection, $accountId);
        $connection->close();
    } catch (Throwable) {
        return null;
    }

    if ($invalidated) {
        return null;
    }
    if ($statusCode !== null) {
        $_SESSION['aics_account_status_code'] = $statusCode;
    }

    return $statusCode;
}

function aics_current_session_status_code(): string
{
    aics_start_session();
    $statusCode = strtolower(trim((string) ($_SESSION['aics_account_status_code'] ?? 'active')));
    return $statusCode !== '' ? $statusCode : 'active';
}

function aics_current_session_display_name(): string
{
    aics_start_session();
    $displayName = trim((string) ($_SESSION['aics_display_name'] ?? 'Applicant'));
    return $displayName !== '' ? $displayName : 'Applicant';
}

function aics_current_session_initials(): string
{
    $displayName = aics_current_session_display_name();
    $words = preg_split('/\s+/', $displayName) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        if ($word !== '') {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
    }
    return $initials !== '' ? $initials : 'AP';
}

function aics_table_has_column(mysqli $connection, string $tableName, string $columnName): bool
{
    $sql = <<<SQL
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function aics_table_has_index(mysqli $connection, string $tableName, string $indexName): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->bind_param('ss', $tableName, $indexName);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function aics_ensure_login_attempts_schema(mysqli $connection): void
{
    $connection->query(
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(180) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                is_success TINYINT(1) NOT NULL DEFAULT 0,
                attempt_reason VARCHAR(80) DEFAULT NULL,
                role_code VARCHAR(30) DEFAULT NULL,
                login_portal VARCHAR(30) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_admin_login_attempts_email (email),
                KEY idx_aics_admin_login_attempts_ip (ip_address),
                KEY idx_aics_admin_login_attempts_success (is_success),
                KEY idx_aics_admin_login_attempts_created (created_at),
                KEY idx_aics_admin_login_attempts_role_code (role_code),
                KEY idx_aics_admin_login_attempts_login_portal (login_portal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    if (!aics_table_has_column($connection, 'aics_admin_login_attempts', 'role_code')) {
        $connection->query('ALTER TABLE aics_admin_login_attempts ADD COLUMN role_code VARCHAR(30) DEFAULT NULL AFTER attempt_reason');
        $connection->query('ALTER TABLE aics_admin_login_attempts ADD KEY idx_aics_admin_login_attempts_role_code (role_code)');
    }

    if (!aics_table_has_column($connection, 'aics_admin_login_attempts', 'login_portal')) {
        $connection->query('ALTER TABLE aics_admin_login_attempts ADD COLUMN login_portal VARCHAR(30) DEFAULT NULL AFTER role_code');
        $connection->query('ALTER TABLE aics_admin_login_attempts ADD KEY idx_aics_admin_login_attempts_login_portal (login_portal)');
    }
}

function aics_client_ip(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $value = trim($candidate);
        if ($value === '') {
            continue;
        }

        if (strpos($value, ',') !== false) {
            $parts = explode(',', $value);
            $value = trim((string) ($parts[0] ?? ''));
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
    }

    return '0.0.0.0';
}

function aics_user_agent(): string
{
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    if ($ua === '') {
        $ua = 'unknown';
    }

    return strlen($ua) > 255 ? substr($ua, 0, 255) : $ua;
}

function aics_record_login_attempt(
    mysqli $connection,
    string $email,
    bool $isSuccess,
    string $reason,
    string $roleCode = 'unknown',
    string $portalCode = 'unknown'
): void {
    aics_ensure_login_attempts_schema($connection);

    $sql = <<<SQL
        INSERT INTO aics_admin_login_attempts (
            email,
            ip_address,
            user_agent,
            is_success,
            attempt_reason,
            role_code,
            login_portal
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    SQL;

    $normalizedEmail = trim($email);
    if ($normalizedEmail === '') {
        $normalizedEmail = 'unknown';
    }

    $normalizedRole = strtolower(trim($roleCode));
    if ($normalizedRole === '') {
        $normalizedRole = 'unknown';
    }

    $normalizedPortal = strtolower(trim($portalCode));
    if ($normalizedPortal === '') {
        $normalizedPortal = 'unknown';
    }

    $normalizedReason = trim($reason);
    if ($normalizedReason === '') {
        $normalizedReason = 'unknown';
    }
    if (strlen($normalizedReason) > 80) {
        $normalizedReason = substr($normalizedReason, 0, 80);
    }

    $ipAddress = aics_client_ip();
    $userAgent = aics_user_agent();
    $successInt = $isSuccess ? 1 : 0;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param(
        'sssisss',
        $normalizedEmail,
        $ipAddress,
        $userAgent,
        $successInt,
        $normalizedReason,
        $normalizedRole,
        $normalizedPortal
    );
    $stmt->execute();
    $stmt->close();
}

function aics_should_force_banned_modal(): bool
{
    aics_start_session();
    $statusCode = aics_current_session_status_code();
    $modalRequired = ((int) ($_SESSION['aics_banned_modal_required'] ?? 0)) === 1;

    return $statusCode === 'banned' || $modalRequired;
}

function aics_is_logged_in_applicat(): bool
{
    if (!aics_has_role_session('applicat')) {
        return false;
    }

    $statusCode = aics_refresh_session_status_for_role('applicat');
    return $statusCode === 'active';
}

function aics_require_applicat_login(): void
{
    aics_require_role_login('applicat', '../login.html');
}

function aics_is_ajax_request(): bool
{
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1');
}

function aics_redirect_with_message(string $path, string $authCode, ?string $view = null): void
{
    if (aics_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        $isSuccess = ($authCode === 'signup_success' || $authCode === 'logged_out');
        echo json_encode([
            'success' => $isSuccess,
            'auth' => $authCode,
            'error' => $isSuccess ? null : $authCode,
            'view' => $view,
        ]);
        exit;
    }

    $query = 'auth=' . urlencode($authCode);
    if ($view !== null && $view !== '') {
        $query .= '&view=' . urlencode($view);
    }
    header('Location: ' . $path . '?' . $query);
    exit;
}

function aics_account_status_auth_code(string $statusCode): string
{
    $normalized = strtolower(trim($statusCode));

    return match ($normalized) {
        'banned' => 'account_banned',
        'disabled' => 'account_disabled',
        'restricted' => 'account_restricted',
        'locked' => 'account_locked',
        default => 'account_inactive',
    };
}

function aics_fetch_account_status_for_role(mysqli $connection, int $accountId, string $roleCode): ?string
{
    if ($accountId <= 0 || trim($roleCode) === '') {
        return null;
    }

    $sql = <<<SQL
        SELECT s.status_code
        FROM aics_accounts a
        INNER JOIN aics_roles r ON r.id = a.role_id
        INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
        WHERE a.id = ?
          AND r.role_code = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('is', $accountId, $roleCode);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $statusCode = trim((string) ($row['status_code'] ?? ''));
    return $statusCode !== '' ? strtolower($statusCode) : null;
}

/**
 * Resolves the current session to an existing database account with the
 * requested role. Authentication never depends on email, phone, or a
 * browser-supplied account ID.
 *
 * @return array{id:int,email:string,role_code:string,status_code:string,profile_id:?int}|null
 */
function aics_authenticated_account_for_role(mysqli $connection, string $requiredRoleCode): ?array
{
    aics_start_session();
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $sessionRoleCode = strtolower(trim((string) ($_SESSION['aics_role_code'] ?? '')));
    $requiredRoleCode = strtolower(trim($requiredRoleCode));
    if ($accountId <= 0 || $requiredRoleCode === '' || $sessionRoleCode !== $requiredRoleCode) {
        return null;
    }

    $stmt = $connection->prepare(
        'SELECT a.id, a.email, r.role_code, s.status_code, p.id AS profile_id
         FROM aics_accounts a
         INNER JOIN aics_roles r ON r.id = a.role_id
         INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
         LEFT JOIN aics_applicant_profiles p ON p.account_id = a.id
         WHERE a.id = ? AND r.role_code = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $accountId, $requiredRoleCode);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        return null;
    }

    $statusCode = strtolower(trim((string) ($row['status_code'] ?? '')));
    if ($statusCode !== 'active' || aics_session_is_invalidated($connection, $accountId)) {
        return null;
    }

    $_SESSION['aics_account_status_code'] = $statusCode;
    $_SESSION['aics_banned_modal_required'] = 0;

    return [
        'id' => (int) $row['id'],
        'email' => trim((string) ($row['email'] ?? '')),
        'role_code' => strtolower(trim((string) $row['role_code'])),
        'status_code' => $statusCode,
        'profile_id' => isset($row['profile_id']) ? (int) $row['profile_id'] : null,
    ];
}

function aics_require_role_login(string $requiredRoleCode, string $loginPath): void
{
    aics_start_session();

    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $sessionRoleCode = strtolower(trim((string) ($_SESSION['aics_role_code'] ?? '')));
    $requiredRoleCode = strtolower(trim($requiredRoleCode));

    if ($accountId <= 0 || $sessionRoleCode !== $requiredRoleCode) {
        aics_destroy_session();
        aics_redirect_with_message($loginPath, 'login_required');
    }

    $sessionInvalidated = false;
    try {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);
        $statusCode = aics_fetch_account_status_for_role($connection, $accountId, $requiredRoleCode);
        $sessionInvalidated = aics_session_is_invalidated($connection, $accountId);
        $connection->close();
    } catch (Throwable) {
        aics_destroy_session();
        aics_redirect_with_message($loginPath, 'login_required');
    }

    if ($statusCode === null) {
        aics_destroy_session();
        aics_redirect_with_message($loginPath, 'login_required');
    }

    if ($sessionInvalidated) {
        aics_destroy_session();
        aics_redirect_with_message($loginPath, 'session_invalidated');
    }

    $_SESSION['aics_account_status_code'] = $statusCode;

    if ($statusCode === 'active') {
        $_SESSION['aics_banned_modal_required'] = 0;
        return;
    }

    if ($requiredRoleCode === 'applicat' && $statusCode === 'banned') {
        $_SESSION['aics_banned_modal_required'] = 1;
        return;
    }

    if ($statusCode !== 'active') {
        aics_destroy_session();
        aics_redirect_with_message($loginPath, aics_account_status_auth_code($statusCode));
    }
}

function aics_clean_text(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $clean = trim($value);
    return $clean === '' || strtoupper($clean) === 'NULL' ? null : $clean;
}
