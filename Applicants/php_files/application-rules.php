<?php
declare(strict_types=1);

require_once __DIR__ . '/dashboard-data-lib.php';

if (!defined('AICS_APPLICATION_COOLDOWN_YEARS')) {
    define('AICS_APPLICATION_COOLDOWN_YEARS', 1);
}
if (!defined('AICS_CANCELLATION_COOLDOWN_HOURS')) {
    define('AICS_CANCELLATION_COOLDOWN_HOURS', 24);
}

/**
 * Returns the cross-type cooldown state for an applicant account.
 *
 * Shape:
 *   [
 *     'last_date'      => 'YYYY-MM-DD' or '',
 *     'eligible_on'    => 'YYYY-MM-DD' or '',
 *     'days_remaining' => int,
 *     'is_in_cooldown' => bool,
 *     'cooldown_years' => int,
 *   ]
 *
 * Every submitted request linked to the applicant anchors the cooldown.
 * A later PSWDO decision (including rejection) never erases submission history.
 *
 * @return array{
 *   last_date:string,
 *   eligible_on:string,
 *   days_remaining:int,
 *   is_in_cooldown:bool,
 *   cooldown_years:int
 * }
 */
function aics_application_cooldown_state(mysqli $connection, int $accountId): array
{
    $cooldownYears = (int) AICS_APPLICATION_COOLDOWN_YEARS;
    $empty = [
        'last_date'      => '',
        'eligible_on'    => '',
        'days_remaining' => 0,
        'is_in_cooldown' => false,
        'cooldown_years' => $cooldownYears,
    ];

    if ($accountId <= 0) {
        return $empty;
    }

    $sql = <<<SQL
        SELECT MAX(DATE(COALESCE(r.date_applied, r.created_at))) AS last_date
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
    SQL;

    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        return $empty;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $lastDate = is_array($row) ? trim((string) ($row['last_date'] ?? '')) : '';
    if ($lastDate === '' || $lastDate === '0000-00-00') {
        return $empty;
    }

    try {
        $lastDateTime = new DateTimeImmutable($lastDate, aics_dashboard_timezone());
        $eligibleDateTime = $lastDateTime->modify('+' . $cooldownYears . ' year');
    } catch (Throwable $exception) {
        return $empty;
    }

    $now = new DateTimeImmutable('today', aics_dashboard_timezone());
    $lastTs = $lastDateTime->getTimestamp();
    $eligibleTs = $eligibleDateTime->getTimestamp();
    if ($eligibleTs === false) {
        return $empty;
    }

    $isInCooldown  = $now->getTimestamp() < $eligibleTs;
    $secondsLeft   = $eligibleTs - $now->getTimestamp();
    $daysRemaining = $isInCooldown ? (int) ceil($secondsLeft / 86400) : 0;

    return [
        'last_date'      => $lastDateTime->format('Y-m-d'),
        'eligible_on'    => $eligibleDateTime->format('Y-m-d'),
        'days_remaining' => $daysRemaining,
        'is_in_cooldown' => $isInCooldown,
        'cooldown_years' => $cooldownYears,
    ];
}

/**
 * Formats a YYYY-MM-DD date as "Month D, YYYY" for display.
 */
function aics_format_cooldown_date(string $isoDate): string
{
    if ($isoDate === '') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($isoDate, aics_dashboard_timezone());
    } catch (Throwable) {
        return $isoDate;
    }
    return $date->format('F j, Y');
}

/**
 * Separately throttles cancellation actions. A cancelled request is already
 * terminal; this additionally prevents an account from cancelling another
 * request within the next 24 hours.
 *
 * @return array{eligible_on:string,is_in_cooldown:bool}
 */
function aics_cancellation_cooldown_state(mysqli $connection, int $accountId): array
{
    $empty = ['eligible_on' => '', 'is_in_cooldown' => false];
    if ($accountId <= 0) return $empty;
    $stmt = $connection->prepare(<<<SQL
        SELECT MAX(r.updated_at) AS cancelled_at
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
          AND LOWER(TRIM(COALESCE(r.request_status, ''))) IN ('cancelled', 'canceled')
        SQL);
    if (!$stmt) return $empty;
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    $cancelledAt = is_array($row) ? trim((string) ($row['cancelled_at'] ?? '')) : '';
    if ($cancelledAt === '') return $empty;
    try {
        $eligible = (new DateTimeImmutable($cancelledAt, aics_dashboard_timezone()))->modify('+' . AICS_CANCELLATION_COOLDOWN_HOURS . ' hours');
        $active = (new DateTimeImmutable('now', aics_dashboard_timezone())) < $eligible;
        return ['eligible_on' => $eligible->format('Y-m-d H:i:s'), 'is_in_cooldown' => $active];
    } catch (Throwable) {
        return $empty;
    }
}
