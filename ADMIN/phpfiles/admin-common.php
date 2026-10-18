<?php
declare(strict_types=1);

require_once __DIR__ . '/../../Applicants/php_files/auth-common.php';

const ADMIN_SESSION_TIMEOUT_SECONDS = 1800;
const ADMIN_LOGIN_MAX_ATTEMPTS = 5;
const ADMIN_LOGIN_WINDOW_SECONDS = 900;

function admin_set_security_headers(bool $disableCache = true): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");

    if ($disableCache) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

function admin_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    $httpsOn = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if ($httpsOn) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('AICSADMINSESSID');
    session_start();

    if (!isset($_SESSION['admin_session_bootstrap'])) {
        session_regenerate_id(true);
        $_SESSION['admin_session_bootstrap'] = time();
    }

    if (!isset($_SESSION['admin_fingerprint'])) {
        $_SESSION['admin_fingerprint'] = admin_session_fingerprint();
    }

    if (!isset($_SESSION['admin_last_activity'])) {
        $_SESSION['admin_last_activity'] = time();
    }
}

function admin_session_fingerprint(): string
{
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent');
    $ipAddress = admin_client_ip();

    return hash('sha256', $userAgent . '|' . $ipAddress);
}

function admin_client_ip(): string
{
    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $segments = explode(',', $forwarded);
        $candidate = trim((string) ($segments[0] ?? ''));
        if ($candidate !== '') {
            return substr($candidate, 0, 45);
        }
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote !== '' ? substr($remote, 0, 45) : '0.0.0.0';
}

function admin_user_agent(): string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'));
    return substr($userAgent, 0, 255);
}

function admin_destroy_session(): void
{
    admin_start_session();

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

function admin_refresh_session_state(): void
{
    admin_start_session();

    $currentFingerprint = admin_session_fingerprint();
    $storedFingerprint = (string) ($_SESSION['admin_fingerprint'] ?? '');
    if ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $currentFingerprint)) {
        throw new RuntimeException('session_mismatch');
    }

    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > ADMIN_SESSION_TIMEOUT_SECONDS) {
        throw new RuntimeException('session_expired');
    }

    $_SESSION['admin_fingerprint'] = $currentFingerprint;
    $_SESSION['admin_last_activity'] = time();
}

/**
 * @param array<string, scalar> $query
 */
function admin_redirect(string $path, array $query = []): void
{
    $suffix = '';
    if (count($query) > 0) {
        $suffix = '?' . http_build_query($query);
    }

    header('Location: ' . $path . $suffix, true, 302);
    exit;
}

/**
 * @param array<string, scalar> $extra
 */
function admin_redirect_login(string $authCode, array $extra = []): void
{
    $query = ['auth' => $authCode] + $extra;
    admin_redirect('../login.php', $query);
}

function admin_is_logged_in(): bool
{
    admin_start_session();

    return isset($_SESSION['aics_account_id']) && ((string) ($_SESSION['aics_role_code'] ?? '') === 'admin');
}

function admin_require_login(): void
{
    admin_set_security_headers();

    try {
        admin_refresh_session_state();
    } catch (RuntimeException $exception) {
        admin_destroy_session();
        admin_redirect_login($exception->getMessage() === 'session_expired' ? 'session_expired' : 'login_required');
    }

    if (!isset($_SESSION['aics_account_id'])) {
        admin_redirect_login('login_required');
    }

    if ((string) ($_SESSION['aics_role_code'] ?? '') !== 'admin') {
        admin_try_log_event(
            'unauthorized_access',
            'warning',
            'Non-admin account attempted to access ADMIN pages.',
            (int) ($_SESSION['aics_account_id'] ?? 0),
            null,
            ['path' => (string) ($_SERVER['REQUEST_URI'] ?? '')]
        );

        admin_destroy_session();
        admin_redirect_login('admin_only');
    }

    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId <= 0) {
        admin_destroy_session();
        admin_redirect_login('login_required');
    }

    $statusCode = null;
    $sessionInvalidated = false;
    try {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);
        $statusCode = aics_fetch_account_status_for_role($connection, $accountId, 'admin');
        $sessionInvalidated = aics_session_is_invalidated($connection, $accountId);
        $connection->close();
    } catch (Throwable) {
        admin_destroy_session();
        admin_redirect_login('login_required');
    }

    if ($sessionInvalidated) {
        admin_destroy_session();
        admin_redirect_login('session_invalidated');
    }

    if ($statusCode === null) {
        admin_destroy_session();
        admin_redirect_login('login_required');
    }

    if ($statusCode !== 'active') {
        admin_try_log_event(
            'login_denied_status',
            'warning',
            'Admin access denied due to account status.',
            $accountId,
            $accountId,
            ['status_code' => $statusCode]
        );
        admin_destroy_session();
        admin_redirect_login(aics_account_status_auth_code($statusCode));
    }
}

function admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @return array<string, string>
 */
function admin_paths(): array
{
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $scriptDirectory = rtrim($scriptDirectory, '/');

    $isPhpFilesRoute = str_ends_with($scriptDirectory, '/phpfiles');
    $adminBaseUrl = $isPhpFilesRoute ? dirname($scriptDirectory) : $scriptDirectory;
    $phpFilesBaseUrl = $isPhpFilesRoute ? $scriptDirectory : $scriptDirectory . '/phpfiles';

    if ($adminBaseUrl === '') {
        $adminBaseUrl = '/AICS/ADMIN';
    }

    if ($phpFilesBaseUrl === '') {
        $phpFilesBaseUrl = '/AICS/ADMIN/phpfiles';
    }

    $projectRootUrl = dirname(dirname($adminBaseUrl));

    $cssPath = __DIR__ . '/../cssfiles/admin-pages.css';
    $jsPath = __DIR__ . '/../jsfiles/admin-pages.js';

    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
    $jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : '1';

    return [
        'admin_base_url' => $adminBaseUrl,
        'phpfiles_base_url' => $phpFilesBaseUrl,
        'dashboard_url' => $adminBaseUrl . '/dashboard.php',
        'project_root_url' => $projectRootUrl,
        'logo_url' => $projectRootUrl . '/asset/logo.png',
        'css_url' => $adminBaseUrl . '/cssfiles/admin-pages.css?v=' . $cssVersion,
        'js_url' => $adminBaseUrl . '/jsfiles/admin-pages.js?v=' . $jsVersion,
        'logout_url' => $phpFilesBaseUrl . '/auth-logout.php',
        'download_backup_url' => $phpFilesBaseUrl . '/download-backup.php',
    ];
}

function admin_current_display_name(): string
{
    admin_start_session();

    $displayName = trim((string) ($_SESSION['aics_display_name'] ?? 'System Administrator'));
    return $displayName !== '' ? $displayName : 'System Administrator';
}

function admin_initials(string $displayName): string
{
    $parts = preg_split('/\s+/', trim($displayName)) ?: [];
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

    return $initials !== '' ? $initials : 'SA';
}

function admin_clean_text(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $clean = trim($value);
    return $clean === '' ? null : $clean;
}

function admin_fetch_account_display_name(mysqli $connection, int $accountId, string $fallback = 'System Administrator'): string
{
    if ($accountId <= 0) {
        return $fallback;
    }

    $sql = <<<SQL
        SELECT
            COALESCE(
                NULLIF(CONCAT_WS(' ', sp.first_name, sp.last_name), ''),
                NULLIF(CONCAT_WS(' ', ap.first_name, ap.last_name), ''),
                ?
            ) AS display_name
        FROM aics_accounts a
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = a.id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = a.id
        WHERE a.id = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('si', $fallback, $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $displayName = trim((string) ($row['display_name'] ?? $fallback));
    return $displayName !== '' ? $displayName : $fallback;
}

function admin_csrf_token(): string
{
    admin_start_session();

    $token = (string) ($_SESSION['admin_csrf_token'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['admin_csrf_token'] = $token;
    }

    return $token;
}

function admin_csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . admin_h(admin_csrf_token()) . '">';
}

function admin_validate_csrf(?string $submittedToken): bool
{
    $sessionToken = (string) ($_SESSION['admin_csrf_token'] ?? '');
    if ($sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function admin_require_valid_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('invalid_method');
    }

    if (!admin_validate_csrf((string) ($_POST['_csrf'] ?? ''))) {
        throw new RuntimeException('invalid_csrf');
    }
}

function admin_table_exists(mysqli $connection, string $tableName): bool
{
    $sql = <<<SQL
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function admin_repair_lookup_auto_increment(mysqli $connection, string $tableName): void
{
    $maxResult = $connection->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM ' . $tableName);
    $maxRow = $maxResult?->fetch_assoc();
    $maxId = (int) ($maxRow['max_id'] ?? 0);
    $nextId = max(1, $maxId + 1);

    // Lookup ids are tinyint unsigned in the current schema (1..255).
    if ($nextId > 255) {
        $nextId = 255;
    }

    $connection->query('ALTER TABLE ' . $tableName . ' AUTO_INCREMENT = ' . $nextId);
}

function admin_insert_role_if_missing(mysqli $connection, string $roleCode, string $roleName): void
{
    $existsStmt = $connection->prepare('SELECT id FROM aics_roles WHERE role_code = ? LIMIT 1');
    $existsStmt->bind_param('s', $roleCode);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()?->fetch_assoc();
    $existsStmt->close();

    if (is_array($exists) && (int) ($exists['id'] ?? 0) > 0) {
        return;
    }

    $insertStmt = $connection->prepare('INSERT INTO aics_roles (role_code, role_name) VALUES (?, ?)');
    $insertStmt->bind_param('ss', $roleCode, $roleName);
    $insertStmt->execute();
    $insertStmt->close();
}

function admin_insert_status_if_missing(mysqli $connection, string $statusCode, string $statusName): void
{
    $existsStmt = $connection->prepare('SELECT id FROM aics_account_statuses WHERE status_code = ? LIMIT 1');
    $existsStmt->bind_param('s', $statusCode);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()?->fetch_assoc();
    $existsStmt->close();

    if (is_array($exists) && (int) ($exists['id'] ?? 0) > 0) {
        return;
    }

    $insertStmt = $connection->prepare('INSERT INTO aics_account_statuses (status_code, status_name) VALUES (?, ?)');
    $insertStmt->bind_param('ss', $statusCode, $statusName);
    $insertStmt->execute();
    $insertStmt->close();
}

function admin_ensure_schema(mysqli $connection): void
{
    aics_ensure_auth_schema($connection);

    $queries = [
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_staff_profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id INT UNSIGNED NOT NULL,
                first_name VARCHAR(120) NOT NULL,
                middle_name VARCHAR(120) DEFAULT NULL,
                last_name VARCHAR(120) NOT NULL,
                position_title VARCHAR(120) DEFAULT NULL,
                contact_number VARCHAR(20) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_staff_profiles_account_id (account_id),
                CONSTRAINT fk_aics_staff_profiles_account_id FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(180) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                is_success TINYINT(1) NOT NULL DEFAULT 0,
                attempt_reason VARCHAR(80) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_admin_login_attempts_email (email),
                KEY idx_aics_admin_login_attempts_ip (ip_address),
                KEY idx_aics_admin_login_attempts_success (is_success),
                KEY idx_aics_admin_login_attempts_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_account_id INT UNSIGNED DEFAULT NULL,
                target_account_id INT UNSIGNED DEFAULT NULL,
                event_type VARCHAR(80) NOT NULL,
                outcome ENUM('success', 'failure', 'warning', 'info') NOT NULL DEFAULT 'info',
                event_message VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                context_json JSON DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_admin_audit_logs_actor (actor_account_id),
                KEY idx_aics_admin_audit_logs_target (target_account_id),
                KEY idx_aics_admin_audit_logs_event_type (event_type),
                KEY idx_aics_admin_audit_logs_created (created_at),
                CONSTRAINT fk_aics_admin_audit_logs_actor FOREIGN KEY (actor_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL,
                CONSTRAINT fk_aics_admin_audit_logs_target FOREIGN KEY (target_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_backup_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                initiated_by_account_id INT UNSIGNED DEFAULT NULL,
                backup_type ENUM('incremental', 'full', 'archive') NOT NULL DEFAULT 'incremental',
                status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
                file_name VARCHAR(255) DEFAULT NULL,
                file_path VARCHAR(500) DEFAULT NULL,
                file_size BIGINT UNSIGNED DEFAULT NULL,
                checksum_sha256 CHAR(64) DEFAULT NULL,
                error_message VARCHAR(500) DEFAULT NULL,
                started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_aics_admin_backup_runs_status (status),
                KEY idx_aics_admin_backup_runs_started (started_at),
                KEY idx_aics_admin_backup_runs_initiated_by (initiated_by_account_id),
                CONSTRAINT fk_aics_admin_backup_runs_initiated_by FOREIGN KEY (initiated_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_maintenance_checks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                performed_by_account_id INT UNSIGNED DEFAULT NULL,
                check_type VARCHAR(80) NOT NULL,
                check_status ENUM('pass', 'fail') NOT NULL,
                details_json JSON DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_admin_maintenance_checks_status (check_status),
                KEY idx_aics_admin_maintenance_checks_created (created_at),
                KEY idx_aics_admin_maintenance_checks_by (performed_by_account_id),
                CONSTRAINT fk_aics_admin_maintenance_checks_by FOREIGN KEY (performed_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_flow_steps (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                step_no SMALLINT UNSIGNED NOT NULL,
                action_text VARCHAR(255) NOT NULL,
                frequency_text VARCHAR(120) NOT NULL,
                success_criteria_text VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_admin_flow_steps_step_no (step_no),
                KEY idx_aics_admin_flow_steps_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_admin_exception_flows (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                exception_name VARCHAR(120) NOT NULL,
                trigger_text VARCHAR(255) NOT NULL,
                response_flow_text VARCHAR(500) NOT NULL,
                frequency_text VARCHAR(120) NOT NULL,
                success_criteria_text VARCHAR(255) NOT NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_admin_exception_flows_order (sort_order),
                KEY idx_aics_admin_exception_flows_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    ];

    foreach ($queries as $query) {
        $connection->query($query);
    }

    admin_repair_lookup_auto_increment($connection, 'aics_roles');
    admin_repair_lookup_auto_increment($connection, 'aics_account_statuses');
    admin_seed_reference_data($connection);

    // Session invalidation timestamp — set when admin changes a user's role/status, forcing re-login.
    $connection->query('ALTER TABLE aics_accounts ADD COLUMN IF NOT EXISTS session_invalidate_at TIMESTAMP NULL DEFAULT NULL');
    // Reset any prior force_password_change flag (no longer enforced).
    $connection->query("UPDATE aics_accounts SET force_password_change = 0 WHERE force_password_change = 1");

    admin_seed_default_admin($connection);
    admin_seed_governance_templates($connection);
}

/**
 * Returns true if the given admin account must change its password before
 * accessing protected pages.
 */
function admin_user_must_change_password(mysqli $connection, int $accountId): bool
{
    if ($accountId <= 0) {
        return false;
    }
    $stmt = $connection->prepare('SELECT force_password_change FROM aics_accounts WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return (int) ($row['force_password_change'] ?? 0) === 1;
}

function admin_seed_reference_data(mysqli $connection): void
{
    admin_insert_role_if_missing($connection, 'admin', 'System Administrator');
    admin_insert_role_if_missing($connection, 'pswdo', 'PSWDO Personnel');

    admin_insert_status_if_missing($connection, 'active', 'Active');
    admin_insert_status_if_missing($connection, 'disabled', 'Disabled');
    admin_insert_status_if_missing($connection, 'restricted', 'Restricted');
    admin_insert_status_if_missing($connection, 'locked', 'Locked');
    admin_insert_status_if_missing($connection, 'banned', 'Banned');
}

function admin_seed_default_admin(mysqli $connection): void
{
    $roleId = admin_resolve_role_id($connection, 'admin');
    $statusId = admin_resolve_status_id($connection, 'active');

    if ($roleId <= 0 || $statusId <= 0) {
        return;
    }

    $existingSql = <<<SQL
        SELECT a.id
        FROM aics_accounts a
        WHERE a.role_id = ?
        LIMIT 1
    SQL;

    $existingStmt = $connection->prepare($existingSql);
    $existingStmt->bind_param('i', $roleId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()?->fetch_assoc();
    $existingStmt->close();

    if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
        return;
    }

    $email = 'admin@aics.local';
    $passwordHash = password_hash('Admin123!', PASSWORD_DEFAULT);

    $insertAccount = $connection->prepare(
        'INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)'
    );
    $insertAccount->bind_param('iiss', $roleId, $statusId, $email, $passwordHash);
    $insertAccount->execute();
    $accountId = (int) $connection->insert_id;
    $insertAccount->close();

    if ($accountId <= 0) {
        return;
    }

    $firstName = 'System';
    $middleName = null;
    $lastName = 'Administrator';
    $position = 'System Administrator';
    $contact = 'N/A';

    $insertProfile = $connection->prepare(
        'INSERT INTO aics_staff_profiles (account_id, first_name, middle_name, last_name, position_title, contact_number) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertProfile->bind_param('isssss', $accountId, $firstName, $middleName, $lastName, $position, $contact);
    $insertProfile->execute();
    $insertProfile->close();

    admin_log_event(
        $connection,
        'admin_seeded',
        'info',
        'Default admin account was seeded for first-time access.',
        $accountId,
        $accountId,
        ['email' => $email]
    );
}

function admin_seed_governance_templates(mysqli $connection): void
{
    $flowRows = (int) (($connection->query('SELECT COUNT(*) AS total FROM aics_admin_flow_steps')?->fetch_assoc()['total'] ?? 0));
    if ($flowRows === 0) {
        $flowTemplates = [
            [1, 'Open admin login page using a trusted internet-connected workstation.', 'Daily / every session', 'Login page is available and loads within acceptable time.'],
            [2, 'Enter authorized administrator username and password.', 'Daily / every session', 'Credentials are submitted securely using HTTPS.'],
            [3, 'System validates credentials and confirms administrator privilege.', 'Every login attempt', 'Only valid active admin accounts are authenticated.'],
            [4, 'System redirects administrator to dashboard.', 'Every successful login', 'Dashboard loads with current system summaries.'],
            [5, 'Review and enforce secure access settings.', 'Daily', 'No unauthorized account is active in admin scope.'],
            [6, 'Create new user accounts and assign role/permissions.', 'As needed', 'New account is complete, role-correct, and usable at first sign-in.'],
            [7, 'Update user account details, role, or status.', 'As needed', 'Changes persist accurately and are reflected immediately.'],
            [8, 'Disable/restrict accounts that should no longer access the system.', 'As needed / immediate', 'Restricted account cannot enter protected modules.'],
            [9, 'Monitor activity and security logs for accountability.', 'Daily', 'Critical user/admin events are logged and reviewable.'],
            [10, 'Review dashboard summaries for requests, approvals, alerts, and trends.', 'Daily', 'Operational trends are visible and actionable.'],
            [11, 'Check stored records for completeness and accessibility.', 'Weekly', 'Records are organized, searchable, and retrievable.'],
            [12, 'Initiate database backup for critical datasets.', 'Daily incremental, Weekly full, Monthly archive', 'Backup jobs include all critical tables and run on schedule.'],
            [13, 'System stores backup output and confirms integrity.', 'Every backup run', 'Backup file is created, stored, and checksum-verifiable.'],
            [14, 'Perform routine maintenance and health checks.', 'Weekly / Monthly', 'Core system checks pass and issues are documented/escalated.'],
            [15, 'Confirm data protection, security controls, and service availability.', 'Daily monitoring / Monthly review', 'Security controls remain active and uptime target is tracked.'],
        ];

        $insertFlowSql = <<<SQL
            INSERT INTO aics_admin_flow_steps (
                step_no,
                action_text,
                frequency_text,
                success_criteria_text,
                is_active
            ) VALUES (?, ?, ?, ?, 1)
        SQL;
        $insertFlowStmt = $connection->prepare($insertFlowSql);
        foreach ($flowTemplates as $row) {
            [$stepNo, $actionText, $frequencyText, $successText] = $row;
            $insertFlowStmt->bind_param('isss', $stepNo, $actionText, $frequencyText, $successText);
            $insertFlowStmt->execute();
        }
        $insertFlowStmt->close();
    }

    $exceptionRows = (int) (($connection->query('SELECT COUNT(*) AS total FROM aics_admin_exception_flows')?->fetch_assoc()['total'] ?? 0));
    if ($exceptionRows === 0) {
        $exceptionTemplates = [
            ['Invalid login', 'Wrong admin email or password submitted', 'Deny login, show generic error, log attempt, and lock account/IP window after repeated failures.', 'Every failed attempt; daily review', '100% failed attempts are logged and lock policy is enforced.', 1],
            ['Unauthorized access', 'Non-admin account requests admin page/action', 'Block with deny response, log event with actor/IP/path context, then alert on repeated attempts.', 'Every unauthorized attempt; daily review', '100% unauthorized attempts are blocked and logged with context.', 2],
            ['Backup failure', 'Backup process error, timeout, or corrupted output', 'Record failed run, keep last known good backup, trigger alert/escalation, and start recovery workflow.', 'Every failed backup; immediate response', 'Failure is detected immediately and recovery starts within SLA.', 3],
        ];

        $insertExceptionSql = <<<SQL
            INSERT INTO aics_admin_exception_flows (
                exception_name,
                trigger_text,
                response_flow_text,
                frequency_text,
                success_criteria_text,
                sort_order,
                is_active
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        SQL;
        $insertExceptionStmt = $connection->prepare($insertExceptionSql);
        foreach ($exceptionTemplates as $row) {
            [$exceptionName, $triggerText, $responseText, $frequencyText, $successText, $sortOrder] = $row;
            $insertExceptionStmt->bind_param('sssssi', $exceptionName, $triggerText, $responseText, $frequencyText, $successText, $sortOrder);
            $insertExceptionStmt->execute();
        }
        $insertExceptionStmt->close();
    }
}

/**
 * @return array<int, array{step: int, action: string, frequency: string, criteria: string}>
 */
function admin_fetch_flow_steps(mysqli $connection): array
{
    $steps = [];
    $sql = <<<SQL
        SELECT step_no, action_text, frequency_text, success_criteria_text
        FROM aics_admin_flow_steps
        WHERE is_active = 1
        ORDER BY step_no ASC
    SQL;
    $result = $connection->query($sql);
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $steps[] = [
            'step' => (int) ($row['step_no'] ?? 0),
            'action' => (string) ($row['action_text'] ?? ''),
            'frequency' => (string) ($row['frequency_text'] ?? ''),
            'criteria' => (string) ($row['success_criteria_text'] ?? ''),
        ];
    }

    return $steps;
}

/**
 * @return array<int, array{exception: string, trigger: string, response: string, frequency: string, criteria: string}>
 */
function admin_fetch_exception_flows(mysqli $connection): array
{
    $flows = [];
    $sql = <<<SQL
        SELECT exception_name, trigger_text, response_flow_text, frequency_text, success_criteria_text
        FROM aics_admin_exception_flows
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    SQL;
    $result = $connection->query($sql);
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $flows[] = [
            'exception' => (string) ($row['exception_name'] ?? ''),
            'trigger' => (string) ($row['trigger_text'] ?? ''),
            'response' => (string) ($row['response_flow_text'] ?? ''),
            'frequency' => (string) ($row['frequency_text'] ?? ''),
            'criteria' => (string) ($row['success_criteria_text'] ?? ''),
        ];
    }

    return $flows;
}

function admin_resolve_role_id(mysqli $connection, string $roleCode): int
{
    $stmt = $connection->prepare('SELECT id FROM aics_roles WHERE role_code = ? LIMIT 1');
    $stmt->bind_param('s', $roleCode);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function admin_resolve_status_id(mysqli $connection, string $statusCode): int
{
    $stmt = $connection->prepare('SELECT id FROM aics_account_statuses WHERE status_code = ? LIMIT 1');
    $stmt->bind_param('s', $statusCode);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

/**
 * @return array<int, array<string, scalar>>
 */
function admin_fetch_roles(mysqli $connection): array
{
    $roles = [];
    $result = $connection->query('SELECT id, role_code, role_name FROM aics_roles ORDER BY role_name ASC');

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $roles[] = [
            'id' => (int) ($row['id'] ?? 0),
            'role_code' => (string) ($row['role_code'] ?? ''),
            'role_name' => (string) ($row['role_name'] ?? ''),
        ];
    }

    return $roles;
}

/**
 * @return array<int, array<string, scalar>>
 */
function admin_fetch_statuses(mysqli $connection): array
{
    $statuses = [];
    $result = $connection->query('SELECT id, status_code, status_name FROM aics_account_statuses ORDER BY status_name ASC');

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $statuses[] = [
            'id' => (int) ($row['id'] ?? 0),
            'status_code' => (string) ($row['status_code'] ?? ''),
            'status_name' => (string) ($row['status_name'] ?? ''),
        ];
    }

    return $statuses;
}

function admin_upsert_profile_for_role(
    mysqli $connection,
    int $accountId,
    string $roleCode,
    string $firstName,
    ?string $middleName,
    string $lastName,
    ?string $contactNumber,
    ?string $positionTitle,
    ?string $address
): void {
    if ($roleCode === 'applicat') {
        $sql = <<<SQL
            INSERT INTO aics_applicant_profiles (
                account_id,
                first_name,
                middle_name,
                last_name,
                contact_number,
                complete_address
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                first_name = VALUES(first_name),
                middle_name = VALUES(middle_name),
                last_name = VALUES(last_name),
                contact_number = VALUES(contact_number),
                complete_address = VALUES(complete_address)
        SQL;

        $stmt = $connection->prepare($sql);
        $stmt->bind_param('isssss', $accountId, $firstName, $middleName, $lastName, $contactNumber, $address);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $sql = <<<SQL
        INSERT INTO aics_staff_profiles (
            account_id,
            first_name,
            middle_name,
            last_name,
            position_title,
            contact_number
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            middle_name = VALUES(middle_name),
            last_name = VALUES(last_name),
            position_title = VALUES(position_title),
            contact_number = VALUES(contact_number)
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('isssss', $accountId, $firstName, $middleName, $lastName, $positionTitle, $contactNumber);
    $stmt->execute();
    $stmt->close();
}

function admin_record_login_attempt(mysqli $connection, string $email, bool $isSuccess, string $reason): void
{
    aics_record_login_attempt($connection, $email, $isSuccess, $reason, 'admin', 'admin');
}

/**
 * @return array{locked: bool, failed_attempts: int, retry_after_seconds: int}
 */
function admin_login_lock_state(mysqli $connection, string $email): array
{
    $threshold = date('Y-m-d H:i:s', time() - ADMIN_LOGIN_WINDOW_SECONDS);
    $ipAddress = admin_client_ip();

    $sql = <<<SQL
        SELECT COUNT(*) AS failed_attempts, MIN(created_at) AS first_failed_at
        FROM aics_admin_login_attempts
        WHERE is_success = 0
          AND created_at >= ?
          AND (email = ? OR ip_address = ?)
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('sss', $threshold, $email, $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $failedAttempts = (int) ($row['failed_attempts'] ?? 0);
    $firstFailedAt = (string) ($row['first_failed_at'] ?? '');

    if ($failedAttempts < ADMIN_LOGIN_MAX_ATTEMPTS || $firstFailedAt === '') {
        return [
            'locked' => false,
            'failed_attempts' => $failedAttempts,
            'retry_after_seconds' => 0,
        ];
    }

    $elapsed = max(0, time() - strtotime($firstFailedAt));
    $retryAfter = max(0, ADMIN_LOGIN_WINDOW_SECONDS - $elapsed);

    return [
        'locked' => $retryAfter > 0,
        'failed_attempts' => $failedAttempts,
        'retry_after_seconds' => $retryAfter,
    ];
}

/**
 * @param array<string, scalar|null> $context
 */
function admin_log_event(
    mysqli $connection,
    string $eventType,
    string $outcome,
    string $eventMessage,
    ?int $actorAccountId = null,
    ?int $targetAccountId = null,
    array $context = []
): void {
    $normalizedOutcome = strtolower(trim($outcome));
    if (!in_array($normalizedOutcome, ['success', 'failure', 'warning', 'info'], true)) {
        $normalizedOutcome = 'info';
    }

    $message = trim($eventMessage);
    if ($message === '') {
        $message = 'No event message supplied.';
    }

    if (strlen($message) > 255) {
        $message = substr($message, 0, 252) . '...';
    }

    $contextJson = null;
    if (count($context) > 0) {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $contextJson = $encoded;
        }
    }

    $ipAddress = admin_client_ip();
    $userAgent = admin_user_agent();

    $sql = <<<SQL
        INSERT INTO aics_admin_audit_logs (
            actor_account_id,
            target_account_id,
            event_type,
            outcome,
            event_message,
            ip_address,
            user_agent,
            context_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param(
        'iissssss',
        $actorAccountId,
        $targetAccountId,
        $eventType,
        $normalizedOutcome,
        $message,
        $ipAddress,
        $userAgent,
        $contextJson
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * @param array<string, scalar|null> $context
 */
function admin_try_log_event(
    string $eventType,
    string $outcome,
    string $eventMessage,
    ?int $actorAccountId = null,
    ?int $targetAccountId = null,
    array $context = []
): void {
    try {
        $connection = aics_db_connect();
        admin_ensure_schema($connection);
        admin_log_event($connection, $eventType, $outcome, $eventMessage, $actorAccountId, $targetAccountId, $context);
        $connection->close();
    } catch (Throwable) {
        // Best effort logging only.
    }
}

function admin_validate_password_strength(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }

    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character.';
    }

    return null;
}

function admin_backup_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
}

/**
 * @return array<string, mixed>
 */
function admin_collect_backup_payload(mysqli $connection): array
{
    $tables = [
        'aics_roles',
        'aics_account_statuses',
        'aics_accounts',
        'aics_staff_profiles',
        'aics_applicant_profiles',
        'aics_requests',
        'aics_account_requests',
        'aics_request_documents',
        'aics_request_status_history',
        'aics_request_notifications',
        'aics_admin_login_attempts',
        'aics_admin_audit_logs',
        'aics_admin_backup_runs',
        'aics_admin_maintenance_checks',
        'aics_admin_flow_steps',
        'aics_admin_exception_flows',
    ];

    $dbRow = $connection->query('SELECT DATABASE() AS db_name')?->fetch_assoc();

    $payload = [
        'meta' => [
            'generated_at' => date('c'),
            'database' => (string) ($dbRow['db_name'] ?? ''),
            'generator' => 'AICS ADMIN Backup Utility',
            'version' => '1.0.0',
        ],
        'tables' => [],
    ];

    foreach ($tables as $tableName) {
        if (!admin_table_exists($connection, $tableName)) {
            continue;
        }

        $rows = [];
        $result = $connection->query('SELECT * FROM `' . $connection->real_escape_string($tableName) . '`');
        while ($row = $result?->fetch_assoc()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = $row;
        }

        $payload['tables'][$tableName] = [
            'row_count' => count($rows),
            'rows' => $rows,
        ];
    }

    return $payload;
}

/**
 * @return array{ok: bool, message: string, run_id: int, file_name?: string, file_size?: int, checksum?: string}
 */
function admin_run_backup(mysqli $connection, int $initiatedByAccountId, string $backupType): array
{
    $backupType = strtolower(trim($backupType));
    if (!in_array($backupType, ['incremental', 'full', 'archive'], true)) {
        $backupType = 'incremental';
    }

    $backupDir = admin_backup_directory();
    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        return [
            'ok' => false,
            'message' => 'Backup directory could not be created.',
            'run_id' => 0,
        ];
    }

    if (!is_writable($backupDir)) {
        return [
            'ok' => false,
            'message' => 'Backup directory is not writable.',
            'run_id' => 0,
        ];
    }

    $runningSql = <<<SQL
        INSERT INTO aics_admin_backup_runs (
            initiated_by_account_id,
            backup_type,
            status
        ) VALUES (?, ?, 'running')
    SQL;

    $runStmt = $connection->prepare($runningSql);
    $runStmt->bind_param('is', $initiatedByAccountId, $backupType);
    $runStmt->execute();
    $runId = (int) $connection->insert_id;
    $runStmt->close();

    try {
        $payload = admin_collect_backup_payload($connection);
        $timestamp = date('Ymd-His');
        $fileName = sprintf('aics-backup-%s-%s.json', $backupType, $timestamp);
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode backup payload.');
        }

        $bytesWritten = file_put_contents($filePath, $json, LOCK_EX);
        if ($bytesWritten === false || $bytesWritten <= 0) {
            throw new RuntimeException('Unable to write backup file to disk.');
        }

        $fileSize = (int) (filesize($filePath) ?: $bytesWritten);
        $checksum = (string) hash_file('sha256', $filePath);

        $successSql = <<<SQL
            UPDATE aics_admin_backup_runs
            SET status = 'success',
                file_name = ?,
                file_path = ?,
                file_size = ?,
                checksum_sha256 = ?,
                error_message = NULL,
                completed_at = NOW()
            WHERE id = ?
            LIMIT 1
        SQL;

        $successStmt = $connection->prepare($successSql);
        $successStmt->bind_param('ssisi', $fileName, $filePath, $fileSize, $checksum, $runId);
        $successStmt->execute();
        $successStmt->close();

        admin_log_event(
            $connection,
            'backup_run',
            'success',
            'Backup completed successfully.',
            $initiatedByAccountId,
            null,
            ['run_id' => $runId, 'backup_type' => $backupType, 'file_name' => $fileName, 'file_size' => $fileSize]
        );

        return [
            'ok' => true,
            'message' => 'Backup completed successfully.',
            'run_id' => $runId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'checksum' => $checksum,
        ];
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();

        $failSql = <<<SQL
            UPDATE aics_admin_backup_runs
            SET status = 'failed',
                error_message = ?,
                completed_at = NOW()
            WHERE id = ?
            LIMIT 1
        SQL;

        $failStmt = $connection->prepare($failSql);
        $failStmt->bind_param('si', $errorMessage, $runId);
        $failStmt->execute();
        $failStmt->close();

        admin_log_event(
            $connection,
            'backup_failed',
            'failure',
            'Backup failed to complete.',
            $initiatedByAccountId,
            null,
            ['run_id' => $runId, 'backup_type' => $backupType, 'error' => $errorMessage]
        );

        return [
            'ok' => false,
            'message' => $errorMessage,
            'run_id' => $runId,
        ];
    }
}

/**
 * @return array{ok: bool, total_checks: int, passed_checks: int, failed_checks: int}
 */
function admin_run_maintenance_checks(mysqli $connection, int $performedByAccountId): array
{
    $backupDir = admin_backup_directory();
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0750, true);
    }

    $checks = [
        [
            'type' => 'database_connectivity',
            'status' => $connection->ping() ? 'pass' : 'fail',
            'details' => ['message' => 'Database ping check executed.'],
        ],
        [
            'type' => 'backup_directory',
            'status' => (is_dir($backupDir) && is_writable($backupDir)) ? 'pass' : 'fail',
            'details' => ['path' => $backupDir, 'writable' => is_writable($backupDir)],
        ],
        [
            'type' => 'audit_logging_table',
            'status' => admin_table_exists($connection, 'aics_admin_audit_logs') ? 'pass' : 'fail',
            'details' => ['table' => 'aics_admin_audit_logs'],
        ],
        [
            'type' => 'backup_runs_table',
            'status' => admin_table_exists($connection, 'aics_admin_backup_runs') ? 'pass' : 'fail',
            'details' => ['table' => 'aics_admin_backup_runs'],
        ],
    ];

    $insertSql = <<<SQL
        INSERT INTO aics_admin_maintenance_checks (
            performed_by_account_id,
            check_type,
            check_status,
            details_json
        ) VALUES (?, ?, ?, ?)
    SQL;

    $insertStmt = $connection->prepare($insertSql);

    $totalChecks = 0;
    $passedChecks = 0;

    foreach ($checks as $check) {
        $totalChecks++;
        if ((string) $check['status'] === 'pass') {
            $passedChecks++;
        }

        $detailsJson = json_encode($check['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($detailsJson)) {
            $detailsJson = '{}';
        }

        $checkType = (string) $check['type'];
        $checkStatus = (string) $check['status'];

        $insertStmt->bind_param('isss', $performedByAccountId, $checkType, $checkStatus, $detailsJson);
        $insertStmt->execute();
    }

    $insertStmt->close();

    $failedChecks = $totalChecks - $passedChecks;

    admin_log_event(
        $connection,
        'maintenance_check',
        $failedChecks > 0 ? 'warning' : 'success',
        $failedChecks > 0
            ? 'Maintenance check completed with failures.'
            : 'Maintenance check completed successfully.',
        $performedByAccountId,
        null,
        ['total_checks' => $totalChecks, 'passed_checks' => $passedChecks, 'failed_checks' => $failedChecks]
    );

    return [
        'ok' => $failedChecks === 0,
        'total_checks' => $totalChecks,
        'passed_checks' => $passedChecks,
        'failed_checks' => $failedChecks,
    ];
}

function admin_format_datetime(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return 'Not available';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return $raw;
    }

    return date('M d, Y h:i A', $timestamp);
}

function admin_path_is_within(string $path, string $baseDir): bool
{
    $realPath = realpath($path);
    $realBase = realpath($baseDir);

    if ($realPath === false || $realBase === false) {
        return false;
    }

    $realPathNormalized = str_replace('\\', '/', $realPath);
    $realBaseNormalized = rtrim(str_replace('\\', '/', $realBase), '/') . '/';

    return str_starts_with($realPathNormalized, $realBaseNormalized);
}

function admin_normalize_period(string $period): string
{
    $normalized = strtolower(trim($period));
    if (!in_array($normalized, ['day', 'week', 'month'], true)) {
        return 'day';
    }

    return $normalized;
}

/**
 * @return array{
 *   period_code: string,
 *   period_label: string,
 *   date_from: string,
 *   date_to: string,
 *   datetime_from: string,
 *   datetime_to: string,
 *   bucket: string
 * }
 */
function admin_period_bounds(string $period): array
{
    $periodCode = admin_normalize_period($period);
    $today = date('Y-m-d');

    if ($periodCode === 'week') {
        $dateFrom = date('Y-m-d', strtotime('-6 days'));
        $dateTo = $today;
        return [
            'period_code' => 'week',
            'period_label' => 'Last 7 Days',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'datetime_from' => $dateFrom . ' 00:00:00',
            'datetime_to' => $dateTo . ' 23:59:59',
            'bucket' => 'day',
        ];
    }

    if ($periodCode === 'month') {
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        return [
            'period_code' => 'month',
            'period_label' => 'This Month',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'datetime_from' => $dateFrom . ' 00:00:00',
            'datetime_to' => $dateTo . ' 23:59:59',
            'bucket' => 'day',
        ];
    }

    return [
        'period_code' => 'day',
        'period_label' => 'Today',
        'date_from' => $today,
        'date_to' => $today,
        'datetime_from' => $today . ' 00:00:00',
        'datetime_to' => $today . ' 23:59:59',
        'bucket' => 'hour',
    ];
}

/**
 * @return array{
 *   period_code: string,
 *   period_label: string,
 *   date_from: string,
 *   date_to: string,
 *   metrics: array{
 *     registered_applicants: int,
 *     submitted_requests: int,
 *     pending_review: int,
 *     interview_pending: int,
 *     under_review: int,
 *     pending_total: int,
 *     approved_total: int,
 *     not_approved_total: int,
 *     released_total: int
 *   }
 * }
 */
function admin_fetch_activity_metrics(mysqli $connection, string $period): array
{
    $bounds = admin_period_bounds($period);
    $datetimeFrom = $bounds['datetime_from'];
    $datetimeTo = $bounds['datetime_to'];

    $metrics = [
        'registered_applicants' => 0,
        'submitted_requests' => 0,
        'pending_review' => 0,
        'interview_pending' => 0,
        'under_review' => 0,
        'pending_total' => 0,
        'approved_total' => 0,
        'not_approved_total' => 0,
        'released_total' => 0,
    ];

    if (admin_table_exists($connection, 'aics_accounts') && admin_table_exists($connection, 'aics_roles')) {
        $appSql = <<<SQL
            SELECT COUNT(*) AS registered_applicants
            FROM aics_accounts a
            INNER JOIN aics_roles r ON r.id = a.role_id
            WHERE r.role_code = 'applicat'
              AND a.created_at BETWEEN ? AND ?
        SQL;
        $appStmt = $connection->prepare($appSql);
        $appStmt->bind_param('ss', $datetimeFrom, $datetimeTo);
        $appStmt->execute();
        $appRow = $appStmt->get_result()?->fetch_assoc();
        $appStmt->close();

        $metrics['registered_applicants'] = (int) ($appRow['registered_applicants'] ?? 0);
    }

    if (admin_table_exists($connection, 'aics_requests')) {
        $reqSql = <<<SQL
            SELECT
                COUNT(*) AS submitted_requests,
                SUM(CASE WHEN request_status IN ('pending', 'pending_review', 'for_review', 'incomplete_requirements') THEN 1 ELSE 0 END) AS pending_review,
                SUM(CASE WHEN request_status IN ('for_interview') THEN 1 ELSE 0 END) AS interview_pending,
                SUM(CASE WHEN request_status IN ('under_review') THEN 1 ELSE 0 END) AS under_review,
                SUM(CASE WHEN request_status IN ('approved', 'for_release') THEN 1 ELSE 0 END) AS approved_total,
                SUM(CASE WHEN request_status IN ('not_approved', 'rejected', 'denied', 'cancelled', 'canceled') THEN 1 ELSE 0 END) AS not_approved_total,
                SUM(CASE WHEN request_status IN ('released', 'distributed', 'completed', 'closed') THEN 1 ELSE 0 END) AS released_total
            FROM aics_requests
            WHERE created_at BETWEEN ? AND ?
        SQL;

        $reqStmt = $connection->prepare($reqSql);
        $reqStmt->bind_param('ss', $datetimeFrom, $datetimeTo);
        $reqStmt->execute();
        $reqRow = $reqStmt->get_result()?->fetch_assoc();
        $reqStmt->close();

        $metrics['submitted_requests'] = (int) ($reqRow['submitted_requests'] ?? 0);
        $metrics['pending_review'] = (int) ($reqRow['pending_review'] ?? 0);
        $metrics['interview_pending'] = (int) ($reqRow['interview_pending'] ?? 0);
        $metrics['under_review'] = (int) ($reqRow['under_review'] ?? 0);
        $metrics['approved_total'] = (int) ($reqRow['approved_total'] ?? 0);
        $metrics['not_approved_total'] = (int) ($reqRow['not_approved_total'] ?? 0);
        $metrics['released_total'] = (int) ($reqRow['released_total'] ?? 0);
    }

    $metrics['pending_total'] = $metrics['pending_review'] + $metrics['interview_pending'] + $metrics['under_review'];

    return [
        'period_code' => $bounds['period_code'],
        'period_label' => $bounds['period_label'],
        'date_from' => $bounds['date_from'],
        'date_to' => $bounds['date_to'],
        'metrics' => $metrics,
    ];
}

/**
 * @return array{
 *   period_code: string,
 *   period_label: string,
 *   date_from: string,
 *   date_to: string,
 *   points: array<int, array{
 *     key: string,
 *     label: string,
 *     registered_applicants: int,
 *     submitted_requests: int
 *   }>,
 *   max_count: int
 * }
 */
function admin_fetch_activity_trend(mysqli $connection, string $period): array
{
    $bounds = admin_period_bounds($period);
    $datetimeFrom = $bounds['datetime_from'];
    $datetimeTo = $bounds['datetime_to'];
    $bucket = $bounds['bucket'];

    $registrationLookup = [];
    $requestLookup = [];
    $points = [];

    if (admin_table_exists($connection, 'aics_accounts') && admin_table_exists($connection, 'aics_roles')) {
        if ($bucket === 'hour') {
            $appSql = <<<SQL
                SELECT HOUR(a.created_at) AS bucket_key, COUNT(*) AS total
                FROM aics_accounts a
                INNER JOIN aics_roles r ON r.id = a.role_id
                WHERE r.role_code = 'applicat'
                  AND a.created_at BETWEEN ? AND ?
                GROUP BY HOUR(a.created_at)
            SQL;
        } else {
            $appSql = <<<SQL
                SELECT DATE(a.created_at) AS bucket_key, COUNT(*) AS total
                FROM aics_accounts a
                INNER JOIN aics_roles r ON r.id = a.role_id
                WHERE r.role_code = 'applicat'
                  AND a.created_at BETWEEN ? AND ?
                GROUP BY DATE(a.created_at)
            SQL;
        }

        $appStmt = $connection->prepare($appSql);
        $appStmt->bind_param('ss', $datetimeFrom, $datetimeTo);
        $appStmt->execute();
        $appResult = $appStmt->get_result();
        while ($row = $appResult?->fetch_assoc()) {
            if (!is_array($row)) {
                break;
            }
            $registrationLookup[(string) ($row['bucket_key'] ?? '')] = (int) ($row['total'] ?? 0);
        }
        $appStmt->close();
    }

    if (admin_table_exists($connection, 'aics_requests')) {
        if ($bucket === 'hour') {
            $reqSql = <<<SQL
                SELECT HOUR(created_at) AS bucket_key, COUNT(*) AS total
                FROM aics_requests
                WHERE created_at BETWEEN ? AND ?
                GROUP BY HOUR(created_at)
            SQL;
        } else {
            $reqSql = <<<SQL
                SELECT DATE(created_at) AS bucket_key, COUNT(*) AS total
                FROM aics_requests
                WHERE created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at)
            SQL;
        }

        $reqStmt = $connection->prepare($reqSql);
        $reqStmt->bind_param('ss', $datetimeFrom, $datetimeTo);
        $reqStmt->execute();
        $reqResult = $reqStmt->get_result();
        while ($row = $reqResult?->fetch_assoc()) {
            if (!is_array($row)) {
                break;
            }
            $requestLookup[(string) ($row['bucket_key'] ?? '')] = (int) ($row['total'] ?? 0);
        }
        $reqStmt->close();
    }

    if ($bucket === 'hour') {
        for ($hour = 0; $hour < 24; $hour++) {
            $key = (string) $hour;
            $hourForLabel = $hour % 12;
            if ($hourForLabel === 0) {
                $hourForLabel = 12;
            }
            $suffix = $hour >= 12 ? 'P' : 'A';
            $label = $hourForLabel . $suffix;
            $points[] = [
                'key' => $key,
                'label' => $label,
                'registered_applicants' => (int) ($registrationLookup[$key] ?? 0),
                'submitted_requests' => (int) ($requestLookup[$key] ?? 0),
                'submitted' => (int) ($requestLookup[$key] ?? 0),
            ];
        }
    } else {
        $startTs = strtotime($bounds['date_from']);
        $endTs = strtotime($bounds['date_to']);
        if ($startTs === false || $endTs === false) {
            $startTs = time();
            $endTs = time();
        }

        for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
            $key = date('Y-m-d', $ts);
            $label = $bounds['period_code'] === 'week' ? date('D', $ts) : date('d', $ts);
            $points[] = [
                'key' => $key,
                'label' => $label,
                'registered_applicants' => (int) ($registrationLookup[$key] ?? 0),
                'submitted_requests' => (int) ($requestLookup[$key] ?? 0),
                'submitted' => (int) ($requestLookup[$key] ?? 0),
            ];
        }
    }

    $maxCount = 1;
    $maxSubmitted = 1;
    foreach ($points as $point) {
        $maxCount = max(
            $maxCount,
            (int) ($point['registered_applicants'] ?? 0),
            (int) ($point['submitted_requests'] ?? 0)
        );
        $maxSubmitted = max(
            $maxSubmitted,
            (int) ($point['submitted'] ?? 0)
        );
    }

    return [
        'period_code' => $bounds['period_code'],
        'period_label' => $bounds['period_label'],
        'date_from' => $bounds['date_from'],
        'date_to' => $bounds['date_to'],
        'points' => $points,
        'max_count' => $maxCount,
        'max_submitted' => $maxSubmitted,
    ];
}
