<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../Applicants/php_files/application-rules.php';

function cooldown_boundary_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{0:int,1:string} */
function cooldown_boundary_account(mysqli $connection): array
{
    $role = $connection->query("SELECT id FROM aics_roles WHERE role_code = 'applicat' LIMIT 1")?->fetch_assoc();
    $status = $connection->query("SELECT id FROM aics_account_statuses WHERE status_code = 'active' LIMIT 1")?->fetch_assoc();
    $roleId = (int) ($role['id'] ?? 0); $statusId = (int) ($status['id'] ?? 0);
    cooldown_boundary_assert($roleId > 0 && $statusId > 0, 'Applicant test fixtures are unavailable.');
    $email = 'cooldown.boundary.' . bin2hex(random_bytes(6)) . '@example.test';
    $hash = password_hash('BoundaryTest2026', PASSWORD_DEFAULT);
    $stmt = $connection->prepare('INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $roleId, $statusId, $email, $hash); $stmt->execute(); $id = (int) $connection->insert_id; $stmt->close();
    return [$id, $email];
}

function cooldown_boundary_request(mysqli $connection, int $accountId, string $dateApplied, string $createdAt, string $updatedAt, string $status): void
{
    $reference = 'BOUNDARY-' . bin2hex(random_bytes(8));
    $stmt = $connection->prepare("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_birth_date, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_birth_date, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, request_status, created_at, updated_at) VALUES (?, ?, 'Test', 'Beneficiary', '1990-01-01', 36, 'Test Beneficiary', '09123456789', 'Test', 'Applicant', '1990-01-01', 36, 'Atabay', 'San Jose de Buenavista', 'female', '[\"medical\"]', 'unclaimed', ?, ?, ?)");
    $stmt->bind_param('sssss', $reference, $dateApplied, $status, $createdAt, $updatedAt); $stmt->execute(); $requestId = (int) $connection->insert_id; $stmt->close();
    $link = $connection->prepare('INSERT INTO aics_account_requests (account_id, request_id) VALUES (?, ?)'); $link->bind_param('ii', $accountId, $requestId); $link->execute(); $link->close();
}

$connection = aics_db_connect();
$accountIds = [];
try {
    aics_ensure_auth_schema($connection);
    // Boundary fixtures exercise database rules but must never persist in the
    // normal application database. All fixture writes are rolled back even if
    // an assertion fails midway through the test.
    $connection->begin_transaction();
    $timezone = aics_dashboard_timezone();
    $today = new DateTimeImmutable('today', $timezone);
    cooldown_boundary_assert($timezone->getName() === 'Asia/Manila', 'Cooldown calculations are not using the dashboard/system timezone.');

    // date_applied is the authoritative request anchor: 364 days/one calendar
    // year boundary behavior must not depend on the MySQL session timezone.
    [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
    cooldown_boundary_request($connection, $accountId, $today->modify('-1 year +1 day')->format('Y-m-d'), $today->modify('-2 years')->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s'), 'pending');
    $state = aics_application_cooldown_state($connection, $accountId);
    cooldown_boundary_assert($state['is_in_cooldown'] === true, '364 days, 23:59:59-equivalent request boundary was not blocked.');
    cooldown_boundary_assert($state['last_date'] === $today->modify('-1 year +1 day')->format('Y-m-d'), 'date_applied was not used as the request cooldown anchor.');

    [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
    cooldown_boundary_request($connection, $accountId, $today->modify('-1 year')->format('Y-m-d'), $today->modify('-2 years')->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s'), 'pending');
    cooldown_boundary_assert(aics_application_cooldown_state($connection, $accountId)['is_in_cooldown'] === false, 'Exactly one calendar year later was blocked.');

    [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
    cooldown_boundary_request($connection, $accountId, $today->modify('-1 year -1 day')->format('Y-m-d'), $today->modify('-2 years')->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s'), 'pending');
    cooldown_boundary_assert(aics_application_cooldown_state($connection, $accountId)['is_in_cooldown'] === false, 'More than one year later was blocked.');

    foreach (['cancelled', 'rejected'] as $status) {
        [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
        cooldown_boundary_request($connection, $accountId, $today->format('Y-m-d'), $today->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s'), $status);
        cooldown_boundary_assert(aics_application_cooldown_state($connection, $accountId)['is_in_cooldown'] === true, ucfirst($status) . ' request did not start the one-year rule.');
    }
    [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
    cooldown_boundary_assert(aics_application_cooldown_state($connection, $accountId)['is_in_cooldown'] === false, 'No submitted request (draft/failed submission case) started the request cooldown.');

    // The schema currently requires date_applied; preserve the nullable legacy
    // fallback as a contract check without mutating production schema.
    $rules = file_get_contents(__DIR__ . '/../Applicants/php_files/application-rules.php');
    cooldown_boundary_assert(is_string($rules) && str_contains($rules, 'COALESCE(r.date_applied, r.created_at)'), 'date_applied NULL fallback to created_at is missing.');

    [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
    $now = new DateTimeImmutable('now', $timezone);
    cooldown_boundary_request($connection, $accountId, $today->format('Y-m-d'), $now->format('Y-m-d H:i:s'), $now->modify('-23 hours -59 minutes')->format('Y-m-d H:i:s'), 'cancelled');
    cooldown_boundary_assert(aics_cancellation_cooldown_state($connection, $accountId)['is_in_cooldown'] === true, 'Cancellation under 24 hours was not blocked.');
    $exactEligible = $now->modify('-24 hours')->modify('+24 hours');
    cooldown_boundary_assert($exactEligible <= $now, 'Exactly 24 cancellation hours is not eligible.');
    [$accountId] = cooldown_boundary_account($connection); $accountIds[] = $accountId;
    cooldown_boundary_request($connection, $accountId, $today->format('Y-m-d'), $now->format('Y-m-d H:i:s'), $now->modify('-25 hours')->format('Y-m-d H:i:s'), 'cancelled');
    cooldown_boundary_assert(aics_cancellation_cooldown_state($connection, $accountId)['is_in_cooldown'] === false, 'Cancellation over 24 hours was blocked.');
    cooldown_boundary_assert(!str_contains($rules, 'AICS_CANCELLATION_COOLDOWN_HOURS') || str_contains($rules, 'function aics_application_cooldown_state'), 'Cancellation and request cooldown helpers are not separate.');

    echo "Cooldown boundary tests passed.\n";
} finally {
    // Requests do not cascade when their owning account is removed: the
    // account-request link is removed first. Delete every fixture request
    // explicitly so boundary records can never leak into the application DB.
    foreach ($accountIds as $accountId) {
        $requests = $connection->prepare('SELECT request_id FROM aics_account_requests WHERE account_id = ?');
        $requests->bind_param('i', $accountId); $requests->execute(); $rows = $requests->get_result();
        $requestIds = [];
        while ($row = $rows?->fetch_assoc()) $requestIds[] = (int) $row['request_id'];
        $requests->close();
        foreach ($requestIds as $requestId) {
            $deleteRequest = $connection->prepare('DELETE FROM aics_requests WHERE id = ?');
            $deleteRequest->bind_param('i', $requestId); $deleteRequest->execute(); $deleteRequest->close();
        }
        $stmt = $connection->prepare('DELETE FROM aics_accounts WHERE id = ? LIMIT 1'); $stmt->bind_param('i', $accountId); $stmt->execute(); $stmt->close();
    }
    $connection->rollback();
    $connection->close();
}
