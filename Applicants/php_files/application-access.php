<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/draft-lib.php';
require_once __DIR__ . '/application-rules.php';

/** @return array{profile_complete:bool,application_allowed:bool,can_recover_draft:bool,mode:string,has_valid_draft:bool,draft:?array<string,mixed>,missing_fields:array<int,string>,cooldown:array<string,mixed>,active_request:?array<string,mixed>} */
function aics_applicant_application_access_state(mysqli $connection, int $accountId): array
{
    $profile = aics_applicant_profile_completion_state($connection, $accountId);
    $cooldown = aics_application_cooldown_state($connection, $accountId);
    $activeRequest = aics_active_account_request($connection, $accountId);
    $applicationAllowed = $profile['complete'] && !$cooldown['is_in_cooldown'] && $activeRequest === null;
    $mode = !$profile['complete'] ? 'INCOMPLETE_PROFILE'
        : ($activeRequest !== null ? 'READ_ONLY_ACTIVE'
        : (!$applicationAllowed ? 'COOLDOWN_READ_ONLY' : 'NEW_APPLICATION'));
    // A draft is recoverable only while its owner is currently allowed to
    // create an application. A leftover active row during an active request
    // or cooldown is stale state, not a recoverable application; delete it
    // (and its temporary files) so it cannot reappear after a refresh.
    $draft = null;
    if ($applicationAllowed) {
        $draft = aics_get_active_draft($connection, $accountId);
    } elseif ($profile['complete']) {
        $staleDraft = aics_get_active_draft($connection, $accountId);
        if (is_array($staleDraft)) {
            aics_discard_draft($connection, $accountId, (string) $staleDraft['id']);
        }
    }
    return [
        'profile_complete' => $profile['complete'],
        'application_allowed' => $applicationAllowed,
        'can_recover_draft' => is_array($draft),
        'mode' => $mode,
        'has_valid_draft' => is_array($draft),
        'draft' => $draft,
        'missing_fields' => $profile['missing_fields'],
        'cooldown' => $cooldown,
        'active_request' => $activeRequest,
    ];
}

/** @return array<string,mixed>|null */
function aics_active_account_request(mysqli $connection, int $accountId): ?array
{
    if ($accountId <= 0) return null;
    $stmt = $connection->prepare(<<<SQL
        SELECT r.id, r.reference_no, r.request_status
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
          AND LOWER(TRIM(COALESCE(r.request_status, ''))) NOT IN (
            'rejected', 'denied', 'not_approved', 'not approved',
            'cancelled', 'canceled', 'released', 'distributed', 'completed', 'closed'
          )
        ORDER BY r.id DESC
        LIMIT 1
        SQL);
    if (!$stmt) throw new RuntimeException('Unable to verify active application state.');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $request = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return is_array($request) ? $request : null;
}

function aics_require_applicant_application_access(string $profileUrl = '../dashboard.php?page=profile'): void
{
    aics_start_session();
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $connection = aics_db_connect();
    try {
        aics_ensure_auth_schema($connection); aics_ensure_draft_schema($connection);
        $state = aics_applicant_application_access_state($connection, $accountId);
    } finally { $connection->close(); }
    if (!$state['profile_complete']) {
        header('Location: ' . $profileUrl . (str_contains($profileUrl, '?') ? '&' : '?') . 'profile_notice=incomplete', true, 302);
        exit;
    }
}
