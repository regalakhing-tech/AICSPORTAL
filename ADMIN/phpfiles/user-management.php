<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/admin-user-validation.php';
require_once __DIR__ . '/admin-user-form.php';

admin_start_session();
$paths = admin_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = admin_current_display_name();
$initials = admin_initials($displayName);

/**
 * @param array<string, scalar> $query
 */
function admin_users_redirect(array $query = []): void
{
    admin_redirect('../dashboard.php', ['page' => 'users'] + $query);
}

function admin_users_normalize_role_filter(string $value): string
{
    $normalized = strtolower(trim($value));
    if (!in_array($normalized, ['all', 'applicat', 'pswdo'], true)) {
        return 'all';
    }

    return $normalized;
}

function admin_users_filter_href(string $roleCode, string $search): string
{
    $query = ['page' => 'users'];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($roleCode !== 'all') {
        $query['role'] = $roleCode;
    }

    return '../dashboard.php?' . http_build_query($query);
}

/**
 * @param array<string, scalar|null> $payload
 */
function admin_users_json_attr(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($json)) {
        $json = '{}';
    }

    return admin_h($json);
}

/**
 * @return array<string, string>
 */
function admin_users_status_labels(): array
{
    return [
        'active' => 'Active (Unban/Unlock)',
        'restricted' => 'Restricted',
        'disabled' => 'Disabled',
        'banned' => 'Banned',
    ];
}

/**
 * @return array<int, string>
 */
function admin_users_editable_status_codes(): array
{
    return ['active', 'restricted', 'disabled', 'banned'];
}

function admin_users_can_transition_status(string $fromStatusCode, string $toStatusCode): bool
{
    $from = strtolower(trim($fromStatusCode));
    $to = strtolower(trim($toStatusCode));

    if ($from === 'banned' || $from === 'locked') {
        return $to === 'active';
    }

    return true;
}

/** New profiles must fail on unique mobile collisions rather than update another applicant. */
function admin_users_insert_profile(mysqli $connection, int $accountId, array $values): void
{
    $applicant = $values['role_code'] === 'applicat';
    $sql = $applicant
        ? 'INSERT INTO aics_applicant_profiles (account_id, first_name, middle_name, last_name, contact_number, complete_address) VALUES (?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO aics_staff_profiles (account_id, first_name, middle_name, last_name, contact_number, position_title) VALUES (?, ?, ?, ?, ?, ?)';
    $stmt = $connection->prepare($sql);
    $stmt->execute([
        $accountId, $values['first_name'], admin_clean_text($values['middle_name']), $values['last_name'],
        admin_clean_text($values['contact_number']), admin_clean_text($values[$applicant ? 'address' : 'position_title']),
    ]);
    $stmt->close();
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $connection = null;
    $action = trim((string) ($_POST['action'] ?? ''));
    $returnSearch = trim((string) ($_POST['return_q'] ?? ''));
    $returnRole = admin_users_normalize_role_filter((string) ($_POST['return_role'] ?? 'all'));

    $returnQuery = [];
    if ($returnSearch !== '') {
        $returnQuery['q'] = $returnSearch;
    }
    if ($returnRole !== 'all') {
        $returnQuery['role'] = $returnRole;
    }

    try {
        admin_require_valid_csrf();

        if ($action === 'create_user') {
            $createResult = admin_validate_create_user($_POST);
            if ($createResult['errors'] !== []) {
                throw new RuntimeException('validation_failed');
            }
        }

        $connection = aics_db_connect();
        admin_ensure_schema($connection);

        if ($action === 'create_user') {
            $values = $createResult['values'];
            $email = $values['email'];
            $password = (string) ($_POST['password'] ?? '');
            $roleCode = $values['role_code'];
            $statusCode = $values['status_code'];

            $roleId = admin_resolve_role_id($connection, $roleCode);
            $statusId = admin_resolve_status_id($connection, $statusCode);
            if ($roleId <= 0 || $statusId <= 0) {
                throw new RuntimeException('invalid_lookup');
            }

            $existingStmt = $connection->prepare('SELECT id FROM aics_accounts WHERE email = ? LIMIT 1');
            $existingStmt->bind_param('s', $email);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()?->fetch_assoc();
            $existingStmt->close();
            if (is_array($existing)) {
                throw new RuntimeException('duplicate_email');
            }

            $connection->begin_transaction();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $insertAccount = $connection->prepare(
                'INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)'
            );
            $insertAccount->bind_param('iiss', $roleId, $statusId, $email, $passwordHash);
            $insertAccount->execute();
            $newAccountId = (int) $connection->insert_id;
            $insertAccount->close();

            admin_users_insert_profile($connection, $newAccountId, $values);

            admin_log_event(
                $connection,
                'account_created',
                'success',
                'Administrator created a new user account.',
                $accountId,
                $newAccountId,
                ['email' => $email, 'role_code' => $roleCode, 'status_code' => $statusCode]
            );

            $connection->commit();
            $connection->close();

            unset($_SESSION['admin_create_user_form']);
            admin_users_redirect(array_merge($returnQuery, ['status' => 'user_created']));
        } elseif ($action === 'update_user') {
            $targetId = (int) ($_POST['target_account_id'] ?? 0);
            $email = filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $roleCode = trim((string) ($_POST['role_code'] ?? ''));
            $statusCode = strtolower(trim((string) ($_POST['status_code'] ?? '')));
            $firstName = admin_clean_text((string) ($_POST['first_name'] ?? ''));
            $middleName = admin_clean_text((string) ($_POST['middle_name'] ?? ''));
            $lastName = admin_clean_text((string) ($_POST['last_name'] ?? ''));
            $contactNumber = admin_clean_text((string) ($_POST['contact_number'] ?? ''));
            $positionTitle = admin_clean_text((string) ($_POST['position_title'] ?? ''));
            $address = admin_clean_text((string) ($_POST['address'] ?? ''));

            if ($targetId <= 0 || $email === false || $firstName === null || $lastName === null || $roleCode === '' || $statusCode === '') {
                throw new RuntimeException('missing_fields');
            }

            if ($targetId === $accountId && $statusCode !== 'active') {
                throw new RuntimeException('cannot_restrict_self');
            }

            if (!in_array($statusCode, admin_users_editable_status_codes(), true)) {
                if ($statusCode === 'banned') {
                    throw new RuntimeException('use_access_status_for_ban');
                }

                throw new RuntimeException('invalid_lookup');
            }

            if ($newPassword !== '') {
                $passwordError = admin_validate_password_strength($newPassword);
                if ($passwordError !== null) {
                    throw new RuntimeException('weak_password');
                }
            }

            $roleId = admin_resolve_role_id($connection, $roleCode);
            $statusId = admin_resolve_status_id($connection, $statusCode);
            if ($roleId <= 0 || $statusId <= 0) {
                throw new RuntimeException('invalid_lookup');
            }

            $dupStmt = $connection->prepare('SELECT id FROM aics_accounts WHERE email = ? AND id <> ? LIMIT 1');
            $dupStmt->bind_param('si', $email, $targetId);
            $dupStmt->execute();
            $duplicate = $dupStmt->get_result()?->fetch_assoc();
            $dupStmt->close();
            if (is_array($duplicate)) {
                throw new RuntimeException('duplicate_email');
            }

            $existsStmt = $connection->prepare('SELECT id FROM aics_accounts WHERE id = ? LIMIT 1');
            $existsStmt->bind_param('i', $targetId);
            $existsStmt->execute();
            $existsRow = $existsStmt->get_result()?->fetch_assoc();
            $existsStmt->close();
            if (!is_array($existsRow)) {
                throw new RuntimeException('account_not_found');
            }

            $connection->begin_transaction();

            $updateStmt = $connection->prepare(
                'UPDATE aics_accounts SET role_id = ?, account_status_id = ?, email = ?, session_invalidate_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1'
            );
            $updateStmt->bind_param('iisi', $roleId, $statusId, $email, $targetId);
            $updateStmt->execute();
            $updateStmt->close();

            if ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pwdStmt = $connection->prepare('UPDATE aics_accounts SET password_hash = ? WHERE id = ? LIMIT 1');
                $pwdStmt->bind_param('si', $passwordHash, $targetId);
                $pwdStmt->execute();
                $pwdStmt->close();
            }

            admin_upsert_profile_for_role(
                $connection,
                $targetId,
                $roleCode,
                $firstName,
                $middleName,
                $lastName,
                $contactNumber,
                $positionTitle,
                $address
            );

            admin_log_event(
                $connection,
                'account_updated',
                'success',
                'Administrator updated user account details.',
                $accountId,
                $targetId,
                ['email' => $email, 'role_code' => $roleCode, 'status_code' => $statusCode]
            );

            $connection->commit();
            $connection->close();

            if ($targetId === $accountId) {
                $_SESSION['aics_display_name'] = trim($firstName . ' ' . $lastName);
            }

            // Flash the new password (if set) so the admin can copy it before redirect.
            if ($newPassword !== '' && $targetId !== $accountId) {
                $_SESSION['admin_flash_password'] = [
                    'email' => $email,
                    'password' => $newPassword,
                    'shown_for_account_id' => $targetId,
                ];
            }

            admin_users_redirect(array_merge($returnQuery, ['status' => 'user_updated']));
        } elseif ($action === 'restrict_user') {
            $targetId = (int) ($_POST['target_account_id'] ?? 0);
            $restrictTo = strtolower(trim((string) ($_POST['restrict_to'] ?? 'restricted')));
            $restrictionReason = admin_clean_text((string) ($_POST['restriction_reason'] ?? ''));
            if ($targetId <= 0) {
                throw new RuntimeException('account_not_found');
            }

            if ($targetId === $accountId) {
                throw new RuntimeException('cannot_restrict_self');
            }

            if (!array_key_exists($restrictTo, admin_users_status_labels())) {
                throw new RuntimeException('invalid_lookup');
            }

            if ($restrictTo === 'banned' && $restrictionReason === null) {
                throw new RuntimeException('ban_reason_required');
            }

            $statusLookupStmt = $connection->prepare(
                'SELECT s.status_code
                 FROM aics_accounts a
                 INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
                 WHERE a.id = ?
                 LIMIT 1'
            );
            $statusLookupStmt->bind_param('i', $targetId);
            $statusLookupStmt->execute();
            $statusLookupRow = $statusLookupStmt->get_result()?->fetch_assoc();
            $statusLookupStmt->close();

            $currentStatusCode = strtolower(trim((string) ($statusLookupRow['status_code'] ?? '')));
            if ($currentStatusCode === '') {
                throw new RuntimeException('account_not_found');
            }

            if ($currentStatusCode === $restrictTo) {
                throw new RuntimeException('status_unchanged');
            }

            if (!admin_users_can_transition_status($currentStatusCode, $restrictTo)) {
                throw new RuntimeException('invalid_status_transition');
            }

            $statusId = admin_resolve_status_id($connection, $restrictTo);
            if ($statusId <= 0) {
                throw new RuntimeException('invalid_lookup');
            }

            $updateStmt = $connection->prepare('UPDATE aics_accounts SET account_status_id = ?, session_invalidate_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1');
            $updateStmt->bind_param('ii', $statusId, $targetId);
            $updateStmt->execute();
            $updateStmt->close();

            $eventType = 'account_restricted';
            $eventOutcome = 'warning';
            $eventMessage = 'Administrator restricted account access.';
            if ($restrictTo === 'banned') {
                $eventType = 'account_banned';
                $eventMessage = 'Administrator banned account access.';
            } elseif ($restrictTo === 'active') {
                $eventOutcome = 'success';
                if ($currentStatusCode === 'banned') {
                    $eventType = 'account_unbanned';
                    $eventMessage = 'Administrator restored account access by unbanning.';
                } elseif ($currentStatusCode === 'locked') {
                    $eventType = 'account_unlocked';
                    $eventMessage = 'Administrator restored account access by unlocking.';
                } else {
                    $eventType = 'account_reactivated';
                    $eventMessage = 'Administrator restored account access.';
                }
            }

            admin_log_event(
                $connection,
                $eventType,
                $eventOutcome,
                $eventMessage,
                $accountId,
                $targetId,
                [
                    'previous_status_code' => $currentStatusCode,
                    'status_code' => $restrictTo,
                    'restriction_reason' => $restrictionReason ?? '',
                ]
            );
            $connection->close();

            admin_users_redirect(array_merge($returnQuery, ['status' => 'user_restricted']));
        } else {
            throw new RuntimeException('invalid_action');
        }
    } catch (Throwable $exception) {
        if ($connection instanceof mysqli) {
            try {
                $connection->rollback();
            } catch (Throwable) {
                // Ignore rollback failures on already closed transactions.
            }
            $connection->close();
        }

        $errorQuery = array_merge($returnQuery, ['error' => $exception->getMessage()]);
        if ($action === 'create_user') {
            $createResult = $createResult ?? admin_validate_create_user($_POST);
            $createErrors = $createResult['errors'];
            if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062 && str_contains($exception->getMessage(), 'uq_aics_applicant_profiles_contact_number')) {
                $createErrors['contact_number'] = 'This mobile number is already in use. Enter a different mobile number.';
                $errorQuery['error'] = 'validation_failed';
            } elseif ($exception->getMessage() === 'duplicate_email' || ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062)) {
                $createErrors['email'] = 'This email address is already in use. Enter a different email.';
                $errorQuery['error'] = 'duplicate_email';
            } elseif (!in_array($exception->getMessage(), ['validation_failed', 'invalid_csrf', 'invalid_lookup'], true)) {
                $errorQuery['error'] = 'create_failed';
            }
            $_SESSION['admin_create_user_form'] = ['values' => $createResult['values'], 'errors' => $createErrors];
            $errorQuery['modal'] = 'create';
        } elseif ($action === 'update_user') {
            $errorQuery['modal'] = 'edit';
            $errorQuery['edit'] = (int) ($_POST['target_account_id'] ?? 0);
        }
        admin_users_redirect($errorQuery);
    }
}

$statusCode = trim((string) ($_GET['status'] ?? ''));
$errorCode = trim((string) ($_GET['error'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = admin_users_normalize_role_filter((string) ($_GET['role'] ?? 'all'));
$editId = (int) ($_GET['edit'] ?? 0);
$modalCode = strtolower(trim((string) ($_GET['modal'] ?? '')));

$openCreateModal = $modalCode === 'create';
$openEditModal = $modalCode === 'edit';

$createValues = admin_validate_create_user(['status_code' => 'active'])['values'];
$createErrors = [];
if ($openCreateModal && is_array($_SESSION['admin_create_user_form'] ?? null)) {
    $createValues = array_replace($createValues, $_SESSION['admin_create_user_form']['values'] ?? []);
    $createErrors = $_SESSION['admin_create_user_form']['errors'] ?? [];
}
unset($_SESSION['admin_create_user_form']);

$statusMessages = [
    'user_created' => ['type' => 'success', 'text' => 'User account created successfully.'],
    'user_updated' => ['type' => 'success', 'text' => 'User account updated successfully.'],
    'user_restricted' => ['type' => 'success', 'text' => 'User access status updated successfully.'],
];
$errorMessages = [
    'validation_failed' => 'Please check the highlighted fields in Add User Account.',
    'create_failed' => 'Unable to create the account. Please try again.',
    'missing_fields' => 'Please complete all required fields.',
    'weak_password' => 'Password policy was not met.',
    'invalid_lookup' => 'Selected role or status is invalid.',
    'duplicate_email' => 'That email address is already in use.',
    'use_access_status_for_ban' => 'Use the Access Status action to ban users so a ban reason is captured in the security log.',
    'ban_reason_required' => 'Please provide a reason before confirming the ban.',
    'account_not_found' => 'Target account does not exist.',
    'cannot_restrict_self' => 'You cannot disable/restrict your own active admin account.',
    'invalid_status_transition' => 'Banned or locked accounts can only be restored to Active (Unban/Unlock).',
    'status_unchanged' => 'Selected status is already the current status. No update was applied.',
    'invalid_action' => 'Unsupported action request.',
    'invalid_csrf' => 'Request token expired or invalid. Please retry.',
    'invalid_method' => 'Invalid request method.',
];

$statusMessage = $statusMessages[$statusCode] ?? null;
$errorMessage = $errorMessages[$errorCode] ?? ($errorCode !== '' ? 'Unable to process request.' : null);

$roles = [];
$statuses = [];
$accounts = [];
$selectedAccount = null;
$loadError = '';

try {
    $connection = aics_db_connect();
    admin_ensure_schema($connection);

    $displayName = admin_fetch_account_display_name($connection, $accountId, $displayName);
    $_SESSION['aics_display_name'] = $displayName;
    $initials = admin_initials($displayName);

    $roles = admin_fetch_roles($connection);
    $statuses = admin_fetch_statuses($connection);

    $statusLabelMap = admin_users_status_labels();
    $editableStatusCodes = admin_users_editable_status_codes();
    $statusRowsByCode = [];
    foreach ($statuses as $statusRow) {
        $statusCodeValue = strtolower(trim((string) ($statusRow['status_code'] ?? '')));
        if ($statusCodeValue === '' || !array_key_exists($statusCodeValue, $statusLabelMap)) {
            continue;
        }

        $statusRowsByCode[$statusCodeValue] = [
            'id' => (int) ($statusRow['id'] ?? 0),
            'status_code' => $statusCodeValue,
            'status_name' => $statusLabelMap[$statusCodeValue],
        ];
    }

    $statuses = [];
    foreach ($editableStatusCodes as $editableCode) {
        if (!array_key_exists($editableCode, $statusRowsByCode)) {
            continue;
        }
        $statuses[] = $statusRowsByCode[$editableCode];
    }

    $baseSql = <<<SQL
        SELECT
            a.id,
            a.email,
            a.created_at,
            a.updated_at,
            r.role_code,
            r.role_name,
            s.status_code,
            s.status_name,
            sp.first_name AS staff_first_name,
            sp.middle_name AS staff_middle_name,
            sp.last_name AS staff_last_name,
            sp.position_title,
            sp.contact_number AS staff_contact_number,
            ap.first_name AS applicant_first_name,
            ap.middle_name AS applicant_middle_name,
            ap.last_name AS applicant_last_name,
            ap.contact_number AS applicant_contact_number,
            ap.complete_address
        FROM aics_accounts a
        INNER JOIN aics_roles r ON r.id = a.role_id
        INNER JOIN aics_account_statuses s ON s.id = a.account_status_id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = a.id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = a.id
    SQL;

    $conditions = [];
    $bindTypes = '';
    $bindValues = [];

    if ($roleFilter !== 'all') {
        $conditions[] = 'r.role_code = ?';
        $bindTypes .= 's';
        $bindValues[] = $roleFilter;
    }

    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $conditions[] = '(a.email LIKE ? OR sp.first_name LIKE ? OR sp.last_name LIKE ? OR ap.first_name LIKE ? OR ap.last_name LIKE ?)';
        $bindTypes .= 'sssss';
        $bindValues[] = $searchLike;
        $bindValues[] = $searchLike;
        $bindValues[] = $searchLike;
        $bindValues[] = $searchLike;
        $bindValues[] = $searchLike;
    }

    $sql = $baseSql;
    if (count($conditions) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY a.updated_at DESC, a.id DESC';

    $stmt = null;
    if ($bindTypes !== '') {
        $stmt = $connection->prepare($sql);
        $stmt->bind_param($bindTypes, ...$bindValues);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $connection->query($sql);
    }

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $accountRoleCode = (string) ($row['role_code'] ?? '');
        $firstName = $accountRoleCode === 'applicat'
            ? (string) ($row['applicant_first_name'] ?? $row['staff_first_name'] ?? '')
            : (string) ($row['staff_first_name'] ?? $row['applicant_first_name'] ?? '');
        $middleName = $accountRoleCode === 'applicat'
            ? (string) ($row['applicant_middle_name'] ?? $row['staff_middle_name'] ?? '')
            : (string) ($row['staff_middle_name'] ?? $row['applicant_middle_name'] ?? '');
        $lastName = $accountRoleCode === 'applicat'
            ? (string) ($row['applicant_last_name'] ?? $row['staff_last_name'] ?? '')
            : (string) ($row['staff_last_name'] ?? $row['applicant_last_name'] ?? '');

        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName === '') {
            $fullName = 'No profile';
        }

        $accounts[] = [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'role_code' => $accountRoleCode,
            'role_name' => (string) ($row['role_name'] ?? ''),
            'status_code' => (string) ($row['status_code'] ?? ''),
            'status_name' => (string) ($row['status_name'] ?? ''),
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'position_title' => (string) ($row['position_title'] ?? ''),
            'contact_number' => $accountRoleCode === 'applicat'
                ? (string) ($row['applicant_contact_number'] ?? '')
                : (string) ($row['staff_contact_number'] ?? ''),
            'address' => (string) ($row['complete_address'] ?? ''),
            'display_name' => $fullName,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load user management data right now.';
}

foreach ($accounts as $account) {
    if ((int) ($account['id'] ?? 0) === $editId) {
        $selectedAccount = $account;
        break;
    }
}

if ($openEditModal && !is_array($selectedAccount)) {
    $openEditModal = false;
}

$roleFilterLabels = [
    'all' => 'All Roles',
    'applicat' => 'Applicants',
    'pswdo' => 'PSWDO',
];
$activeRoleLabel = $roleFilterLabels[$roleFilter] ?? 'All Roles';
$autoOpenModal = $openCreateModal ? 'create' : ($openEditModal ? 'edit' : '');
$totalAccounts = count($accounts);
$editableStatusCodes = admin_users_editable_status_codes();

$editSeed = [
    'id' => (int) ($selectedAccount['id'] ?? 0),
    'first_name' => (string) ($selectedAccount['first_name'] ?? ''),
    'middle_name' => (string) ($selectedAccount['middle_name'] ?? ''),
    'last_name' => (string) ($selectedAccount['last_name'] ?? ''),
    'email' => (string) ($selectedAccount['email'] ?? ''),
    'role_code' => (string) ($selectedAccount['role_code'] ?? 'pswdo'),
    'status_code' => in_array((string) ($selectedAccount['status_code'] ?? 'active'), $editableStatusCodes, true)
        ? (string) ($selectedAccount['status_code'] ?? 'active')
        : 'active',
    'position_title' => (string) ($selectedAccount['position_title'] ?? ''),
    'contact_number' => (string) ($selectedAccount['contact_number'] ?? ''),
    'address' => (string) ($selectedAccount['address'] ?? ''),
];

$roleIcons = ['admin' => 'fa-user-shield', 'pswdo' => 'fa-user-tie', 'applicat' => 'fa-user'];
$statusIcons = ['active' => 'fa-circle-check', 'restricted' => 'fa-circle-minus', 'disabled' => 'fa-circle-pause', 'locked' => 'fa-lock', 'banned' => 'fa-ban'];

admin_render_page_start('AICS ADMIN | User Management', 'users', $displayName, $initials, $paths);
admin_render_page_head(
    'fa-users-gear',
    'User Management',
    'Add, edit and restrict accounts. Every change is saved in the security log.',
    '<button type="button" class="btn-solid" data-modal-open="modal-create-user"><i class="fas fa-user-plus" aria-hidden="true"></i> Add user</button>'
);
?>

  <?php if (is_array($statusMessage)): ?>
    <div class="alert <?= admin_h((string) $statusMessage['type'] === 'success' ? 'alert-success' : 'alert-error') ?> flash-alert">
      <i class="fas fa-<?= (string) $statusMessage['type'] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= admin_h((string) $statusMessage['text']) ?></span>
    </div>
  <?php endif; ?>

  <?php
    // One-time display of newly-set password (cleared from session after first render).
    $flashPassword = $_SESSION['admin_flash_password'] ?? null;
    if (is_array($flashPassword)) { unset($_SESSION['admin_flash_password']); }
  ?>
  <?php if (is_array($flashPassword) && isset($flashPassword['password'])): ?>
    <div class="adm-secret" role="status">
      <span class="adm-secret-icon"><i class="fas fa-key" aria-hidden="true"></i></span>
      <div class="adm-secret-body">
        <strong>New password set. Copy it and send it to the user.</strong>
        <p>Account: <b><?= admin_h((string) ($flashPassword['email'] ?? '')) ?></b>. This password is shown only once.</p>
        <div class="adm-secret-row">
          <code id="flash-pw-value"><?= admin_h((string) ($flashPassword['password'] ?? '')) ?></code>
          <button type="button" class="btn-solid btn-mini" onclick="(function(){const t=document.getElementById('flash-pw-value').textContent;navigator.clipboard.writeText(t);this.innerHTML='<i class=&quot;fas fa-check&quot;></i> Copied';}).call(this)">
            <i class="fas fa-copy" aria-hidden="true"></i> Copy
          </button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($errorMessage !== null): ?>
    <div class="alert alert-error flash-alert">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($errorMessage) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($loadError !== ''): ?>
    <div class="alert alert-error">
      <i class="fas fa-triangle-exclamation"></i>
      <span><?= admin_h($loadError) ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-card user-toolbar-card" id="user-management-root" data-auto-open-modal="<?= admin_h($autoOpenModal) ?>">
    <div class="adm-filter-bar">
      <div class="role-filter-chips" role="group" aria-label="Show accounts">
        <a class="role-filter-chip<?= $roleFilter === 'all' ? ' active' : '' ?>" href="<?= admin_h(admin_users_filter_href('all', $search)) ?>"<?= $roleFilter === 'all' ? ' aria-current="true"' : '' ?>><i class="fas fa-layer-group" aria-hidden="true"></i> All</a>
        <a class="role-filter-chip<?= $roleFilter === 'applicat' ? ' active' : '' ?>" href="<?= admin_h(admin_users_filter_href('applicat', $search)) ?>"<?= $roleFilter === 'applicat' ? ' aria-current="true"' : '' ?>><i class="fas fa-user" aria-hidden="true"></i> Applicants</a>
        <a class="role-filter-chip<?= $roleFilter === 'pswdo' ? ' active' : '' ?>" href="<?= admin_h(admin_users_filter_href('pswdo', $search)) ?>"<?= $roleFilter === 'pswdo' ? ' aria-current="true"' : '' ?>><i class="fas fa-user-tie" aria-hidden="true"></i> PSWDO</a>
      </div>

      <form method="get" action="../dashboard.php" class="inline-filter user-inline-filter" role="search">
        <input type="hidden" name="page" value="users">
        <input type="hidden" name="role" value="<?= admin_h($roleFilter) ?>">
        <label class="adm-search">
          <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
          <span class="sr-only">Search accounts</span>
          <input type="text" name="q" value="<?= admin_h($search) ?>" placeholder="Search by name or email">
        </label>
        <button type="submit" class="btn-solid">Search</button>
        <?php if ($search !== '' || $roleFilter !== 'all'): ?>
          <a class="btn-outline" href="../dashboard.php?page=users"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</a>
        <?php endif; ?>
      </form>
    </div>
  </section>

  <section class="panel-card">
    <header class="panel-head">
      <h2><i class="fas fa-address-book" aria-hidden="true"></i> Accounts</h2>
      <div class="directory-meta-group">
        <span class="pill pill-info"><i class="fas fa-users" aria-hidden="true"></i> <?= (int) $totalAccounts ?> <?= $totalAccounts === 1 ? 'account' : 'accounts' ?></span>
        <span class="pill pill-neutral"><i class="fas fa-filter" aria-hidden="true"></i> <?= admin_h($activeRoleLabel) ?></span>
      </div>
    </header>

    <?php if (count($accounts) === 0): ?>
      <?php admin_render_empty('fa-user-slash', 'No accounts match this filter', 'Try another role, or clear the search.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table adm-user-table">
        <thead>
          <tr>
            <th>Account</th>
            <th>Contact</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $account): ?>
              <?php
                $rolePillClass = match ((string) $account['role_code']) {
                    'admin' => 'pill-info',
                    'pswdo' => 'pill-neutral',
                    'applicat' => 'pill-warning',
                    default => 'pill-neutral',
                };
                $statusPillClass = match ((string) $account['status_code']) {
                    'active' => 'pill-success',
                    'restricted', 'disabled' => 'pill-warning',
                    'locked', 'banned' => 'pill-danger',
                    default => 'pill-neutral',
                };
                $editPayload = [
                    'id' => (int) $account['id'],
                    'first_name' => (string) $account['first_name'],
                    'middle_name' => (string) $account['middle_name'],
                    'last_name' => (string) $account['last_name'],
                    'email' => (string) $account['email'],
                    'role_code' => (string) $account['role_code'],
                    'status_code' => in_array((string) $account['status_code'], $editableStatusCodes, true)
                        ? (string) $account['status_code']
                        : 'active',
                    'position_title' => (string) $account['position_title'],
                    'contact_number' => (string) $account['contact_number'],
                    'address' => (string) $account['address'],
                ];
                $currentStatusCode = strtolower(trim((string) $account['status_code']));
                $restrictOptions = admin_users_status_labels();
                if (in_array($currentStatusCode, ['banned', 'locked'], true)) {
                    $restrictOptions = ['active' => $restrictOptions['active']];
                }
                $restrictOptionCodes = array_keys($restrictOptions);
                $fallbackRestrictCode = count($restrictOptionCodes) > 0 ? (string) $restrictOptionCodes[0] : 'active';
                $currentRestrictCode = array_key_exists($currentStatusCode, $restrictOptions)
                    ? $currentStatusCode
                    : $fallbackRestrictCode;
                $canEditAccount = $currentStatusCode !== 'banned';
                $placeholder = static fn (string $value): string => in_array(strtolower($value), ['n/a', 'na', 'none', '-'], true) ? '' : $value;
                $accountEmail = $placeholder(trim((string) $account['email']));
                $accountPhone = $placeholder(trim((string) $account['contact_number']));
                $accountRole = (string) $account['role_code'];
              ?>
              <tr>
                <td>
                  <div class="adm-user-cell">
                    <span class="adm-user-avatar role-<?= admin_h($accountRole) ?>"><?= admin_h(admin_initials((string) $account['display_name'])) ?></span>
                    <span class="adm-user-name">
                      <strong><?= admin_h((string) $account['display_name']) ?></strong>
                      <small>#<?= (int) $account['id'] ?><?= trim((string) $account['position_title']) !== '' && trim((string) $account['position_title']) !== trim((string) $account['role_name']) ? ', ' . admin_h((string) $account['position_title']) : '' ?></small>
                      <small><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Updated <?= admin_h(admin_format_datetime((string) $account['updated_at'])) ?></small>
                    </span>
                  </div>
                </td>
                <td data-label="Contact">
                  <?php if ($accountEmail !== ''): ?>
                    <span class="adm-contact"><i class="fas fa-envelope" aria-hidden="true"></i> <?= admin_h($accountEmail) ?></span>
                  <?php endif; ?>
                  <?php if ($accountPhone !== ''): ?>
                    <span class="adm-contact"><i class="fas fa-phone" aria-hidden="true"></i> <?= admin_h($accountPhone) ?></span>
                  <?php endif; ?>
                  <?php if ($accountEmail === '' && $accountPhone === ''): ?>
                    <span class="adm-muted">Not provided</span>
                  <?php endif; ?>
                </td>
                <td data-label="Role"><span class="pill <?= admin_h($rolePillClass) ?>"><i class="fas <?= admin_h($roleIcons[$accountRole] ?? 'fa-user') ?>" aria-hidden="true"></i> <?= admin_h((string) $account['role_name']) ?></span></td>
                <td data-label="Status"><span class="pill <?= admin_h($statusPillClass) ?>"><i class="fas <?= admin_h($statusIcons[(string) $account['status_code']] ?? 'fa-circle') ?>" aria-hidden="true"></i> <?= admin_h((string) $account['status_name']) ?></span></td>
                <td data-label="Actions">
                  <div class="table-actions">
                    <?php if ($canEditAccount): ?>
                      <button
                        type="button"
                        class="btn-outline btn-mini js-edit-user-btn"
                        data-account="<?= admin_users_json_attr($editPayload) ?>">
                        <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                        Edit
                      </button>
                    <?php else: ?>
                      <button type="button" class="btn-outline btn-mini" disabled title="Unban account first to edit details.">
                        <i class="fas fa-lock" aria-hidden="true"></i>
                        Unban first
                      </button>
                    <?php endif; ?>
                    <?php if ((int) $account['id'] !== $accountId): ?>
                      <form action="" method="post" class="inline-form js-restrict-form" data-account-label="<?= admin_h((string) $account['display_name']) ?>" data-account-email="<?= admin_h((string) $account['email']) ?>" data-current-status="<?= admin_h($currentStatusCode) ?>">
                        <?= admin_csrf_input() ?>
                        <input type="hidden" name="action" value="restrict_user">
                        <input type="hidden" name="target_account_id" value="<?= (int) $account['id'] ?>">
                        <input type="hidden" name="return_q" value="<?= admin_h($search) ?>">
                        <input type="hidden" name="return_role" value="<?= admin_h($roleFilter) ?>">
                        <select name="restrict_to" aria-label="Account status for <?= admin_h((string) $account['display_name']) ?>">
                          <?php foreach ($restrictOptions as $optionCode => $optionLabel): ?>
                            <option value="<?= admin_h($optionCode) ?>"<?= $currentRestrictCode === $optionCode ? ' selected' : '' ?>>
                              <?= admin_h($optionLabel) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-outline btn-mini">Apply</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php admin_render_create_user_form($roles, $statuses, $createValues, $createErrors, $search, $roleFilter, $openCreateModal ? (string) $errorMessage : ''); ?>

    <div class="admin-modal-shell" id="modal-edit-user" aria-hidden="true">
      <div class="admin-modal-backdrop" data-modal-close></div>
      <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-edit-title">
        <header class="admin-modal-head">
          <div>
            <p class="modal-kicker">Account Control</p>
            <h3 id="modal-edit-title">Edit User Account</h3>
            <p class="modal-subcopy" id="modal-edit-subcopy">
              Update profile, role, status, or credentials.
            </p>
          </div>
          <button type="button" class="admin-modal-close" data-modal-close aria-label="Close edit user modal">
            <i class="fas fa-xmark"></i>
          </button>
        </header>

        <form action="" method="post" class="form-grid modal-form-grid" id="edit-user-form">
          <?= admin_csrf_input() ?>
          <input type="hidden" name="action" value="update_user">
          <input type="hidden" name="target_account_id" value="<?= (int) $editSeed['id'] ?>">
          <input type="hidden" name="return_q" value="<?= admin_h($search) ?>">
          <input type="hidden" name="return_role" value="<?= admin_h($roleFilter) ?>">

          <label>First Name
            <input type="text" name="first_name" value="<?= admin_h((string) $editSeed['first_name']) ?>" required>
          </label>
          <label>Middle Name <span class="field-optional">(Optional)</span>
            <input type="text" name="middle_name" value="<?= admin_h((string) $editSeed['middle_name']) ?>">
          </label>
          <label>Last Name
            <input type="text" name="last_name" value="<?= admin_h((string) $editSeed['last_name']) ?>" required>
          </label>
          <label>Email
            <input type="email" name="email" value="<?= admin_h((string) $editSeed['email']) ?>" required>
          </label>
          <label>New Password <span class="field-optional">(Optional)</span>
            <input type="password" name="new_password" placeholder="Leave blank to keep current">
          </label>
          <label>Role
            <select name="role_code" class="js-role-select" required>
              <?php foreach ($roles as $role): ?>
                <option value="<?= admin_h((string) $role['role_code']) ?>"<?= (string) $editSeed['role_code'] === (string) $role['role_code'] ? ' selected' : '' ?>>
                  <?= admin_h((string) $role['role_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php $isEditingSelf = (int) ($editSeed['id'] ?? 0) === $accountId; ?>
          <?php if ($isEditingSelf): ?>
            <input type="hidden" name="status_code" value="<?= admin_h((string) $editSeed['status_code']) ?>">
            <label>Status
              <input type="text" value="Active (locked &mdash; cannot edit own status)" disabled style="background:#f1f5f9;color:#64748b;cursor:not-allowed;">
            </label>
          <?php else: ?>
            <label>Status
              <select name="status_code" required>
                <?php foreach ($statuses as $status): ?>
                  <option value="<?= admin_h((string) $status['status_code']) ?>"<?= (string) $editSeed['status_code'] === (string) $status['status_code'] ? ' selected' : '' ?>>
                    <?= admin_h((string) $status['status_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <label>Position Title (staff) <span class="field-optional">(Optional)</span>
            <input type="text" name="position_title" value="<?= admin_h((string) $editSeed['position_title']) ?>">
          </label>
          <label>Contact Number <span class="field-optional">(Optional)</span>
            <input type="tel" name="contact_number" inputmode="numeric" data-digits-only value="<?= admin_h((string) $editSeed['contact_number']) ?>">
          </label>
          <label>Address (applicant) <span class="field-optional">(Optional)</span>
            <input type="text" name="address" value="<?= admin_h((string) $editSeed['address']) ?>">
          </label>

          <p class="role-field-hint modal-span-2" data-role-hint>
            Select role to guide which profile details are most important.
          </p>

          <div class="modal-actions modal-span-2">
            <button type="button" class="btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn-solid"><i class="fas fa-floppy-disk"></i> Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="admin-modal-shell" id="modal-restrict-confirm" aria-hidden="true">
      <div class="admin-modal-backdrop" data-modal-close></div>
      <div class="admin-modal-card admin-modal-card-compact" role="dialog" aria-modal="true" aria-labelledby="modal-restrict-title">
        <header class="admin-modal-head">
          <div>
            <p class="modal-kicker">Access Control</p>
            <h3 id="modal-restrict-title">Confirm Status Update</h3>
            <p class="modal-subcopy" id="modal-restrict-copy">This account status change will apply immediately.</p>
          </div>
          <button type="button" class="admin-modal-close" data-modal-close aria-label="Close restriction modal">
            <i class="fas fa-xmark"></i>
          </button>
        </header>

        <div class="modal-confirm-body">
          <div class="modal-confirm-banner" id="modal-restrict-banner">
            <i class="fas fa-shield-halved" id="modal-restrict-banner-icon" aria-hidden="true"></i>
            <p id="modal-restrict-banner-text">Review this status change before confirming.</p>
          </div>
          <p class="modal-confirm-target" id="modal-restrict-target">User: -</p>
          <p class="modal-confirm-status" id="modal-restrict-status">Status: -</p>

          <label class="ban-reason-field" id="ban-reason-field">
            Ban Reason
            <textarea id="ban-reason-input" placeholder="State clearly why this account is being banned..." maxlength="500"></textarea>
            <div class="ban-reason-meta">
              <small>Reason is required and will be saved in the security log.</small>
              <span id="ban-reason-counter">0 / 500</span>
            </div>
          </label>
          <p class="ban-reason-error hidden" id="ban-reason-error">Please provide a reason before continuing.</p>

          <div class="modal-actions">
            <button type="button" class="btn-outline" data-modal-close>Cancel</button>
            <button type="button" class="btn-solid" id="restrict-confirm-btn">
              <i class="fas fa-circle-check"></i>
              Confirm Update
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>
  <script src="<?= admin_h(dirname($paths['js_url'])) ?>/admin-user-form.js?v=<?= (int) filemtime(__DIR__ . '/../jsfiles/admin-user-form.js') ?>"></script>
<?php
admin_render_page_end($paths);
