<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';

function aics_ensure_password_reset_schema(mysqli $connection): void
{
    $connection->query("CREATE TABLE IF NOT EXISTS aics_password_resets (
        account_id INT UNSIGNED NOT NULL PRIMARY KEY,
        code_hash VARCHAR(255) NOT NULL,
        sent_at INT UNSIGNED NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        window_start INT UNSIGNED NOT NULL,
        send_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
        request_ip VARCHAR(45) NOT NULL,
        KEY idx_aics_password_resets_ip_sent (request_ip, sent_at),
        CONSTRAINT fk_aics_password_resets_account FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query('ALTER TABLE aics_password_resets ADD COLUMN IF NOT EXISTS window_start INT UNSIGNED NOT NULL DEFAULT 0');
    $connection->query('ALTER TABLE aics_password_resets ADD COLUMN IF NOT EXISTS send_count TINYINT UNSIGNED NOT NULL DEFAULT 1');
    $connection->query("CREATE TABLE IF NOT EXISTS aics_password_reset_sends (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        request_ip VARCHAR(45) NOT NULL,
        sent_at INT UNSIGNED NOT NULL,
        KEY idx_aics_password_reset_sends_ip_time (request_ip, sent_at),
        CONSTRAINT fk_aics_password_reset_sends_account FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function aics_count_recent_reset_sends(mysqli $connection, string $ip, int $since): int
{
    $stmt = $connection->prepare('SELECT COUNT(*) AS total FROM aics_password_reset_sends WHERE request_ip = ? AND sent_at > ?');
    $stmt->bind_param('si', $ip, $since);
    $stmt->execute();
    $count = (int) ($stmt->get_result()?->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

/** @param array<string,mixed>|null $previous
 *  @return array{allowed:bool,window_start:int,send_count:int,failed_attempts:int}
 */
function aics_reset_send_decision(?array $previous, int $ipCount, int $now): array
{
    $sameWindow = $previous !== null && (int) ($previous['window_start'] ?? 0) > 0
        && $now - (int) $previous['window_start'] < 900;
    $windowStart = $sameWindow ? (int) $previous['window_start'] : $now;
    $priorSends = $sameWindow ? (int) ($previous['send_count'] ?? 0) : 0;
    $failedAttempts = $sameWindow ? (int) ($previous['failed_attempts'] ?? 0) : 0;
    $coolingDown = $previous !== null && $now - (int) ($previous['sent_at'] ?? 0) < 60;
    return [
        'allowed' => $ipCount < 5 && $priorSends < 5 && $failedAttempts < 5 && !$coolingDown,
        'window_start' => $windowStart,
        'send_count' => $priorSends + 1,
        'failed_attempts' => $failedAttempts,
    ];
}

/** @param array<string,mixed>|null $request */
function aics_reset_otp_status(?array $request, string $code, int $now): string
{
    if (preg_match('/^[0-9]{6}$/D', $code) !== 1 || $request === null) return 'invalid_otp';
    if ((int) ($request['expires_at'] ?? 0) <= $now) return 'otp_expired';
    if ((int) ($request['failed_attempts'] ?? 0) >= 5) return 'otp_locked';
    return password_verify($code, (string) ($request['code_hash'] ?? '')) ? 'valid' : 'invalid_otp';
}

function aics_send_password_reset_sms(string $phone, string $code): bool
{
    if (!function_exists('curl_init')) throw new RuntimeException('SMS service is unavailable.');
    $curl = curl_init('https://dashboard.philsms.com/api/v3/sms/send');
    if ($curl === false) throw new RuntimeException('SMS service is unavailable.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . aics_philsms_api_token(),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'recipient' => '63' . substr($phone, 1),
            'sender_id' => AICS_PHILSMS_SENDER_ID,
            'type' => 'plain',
            'message' => "AICS Portal: Your password reset code is {$code}. It expires in 5 minutes. Do not share this code.",
        ]),
    ]);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $payload = is_string($response) ? json_decode($response, true) : null;
    return $httpCode >= 200 && $httpCode < 300 && is_array($payload) && ($payload['status'] ?? '') === 'success';
}
