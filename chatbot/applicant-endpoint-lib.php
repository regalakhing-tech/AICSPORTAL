<?php
declare(strict_types=1);

function aics_chatbot_private_env_path(): string
{
    $override = trim((string) (getenv('AICS_EXTERNAL_AI_ENV_FILE') ?: ''));
    return $override !== '' ? $override : 'C:\\xampp\\aics_private\\aics-ai.env';
}

function aics_chatbot_resolve_private_api_key(string $environmentKey, string $envPath, string $legacyPath): string
{
    $candidates = [trim($environmentKey)];
    if ($envPath !== '' && is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (preg_match('/^\s*AICS_EXTERNAL_AI_API_KEY\s*=\s*(.*?)\s*$/', $line, $match) === 1) {
                $value = trim($match[1]);
                if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                    $value = substr($value, 1, -1);
                }
                $candidates[] = trim($value);
                break;
            }
        }
    }
    if ($legacyPath !== '' && is_file($legacyPath) && is_readable($legacyPath)) {
        $candidates[] = trim((string) file_get_contents($legacyPath));
    }
    foreach ($candidates as $candidate) {
        if (preg_match('/^gsk_[A-Za-z0-9]+$/', $candidate) === 1) return $candidate;
    }
    return '';
}

/** @return array{ok:bool,status?:int,message?:string,account_id?:int} */
function aics_chatbot_applicant_preflight(array $request, array $session, callable $accountAuthorizer): array
{
    $accountId = (int) ($session['aics_account_id'] ?? 0);
    $role = strtolower(trim((string) ($session['aics_role_code'] ?? '')));
    if ($accountId <= 0 || $role !== 'applicat') {
        return ['ok' => false, 'status' => 401, 'message' => 'Your applicant session has expired. Please sign in again.'];
    }
    $expectedCsrf = (string) ($session['aics_applicant_chat_csrf'] ?? '');
    $receivedCsrf = (string) ($request['csrf'] ?? '');
    if ($expectedCsrf === '' || $receivedCsrf === '' || !hash_equals($expectedCsrf, $receivedCsrf)) {
        return ['ok' => false, 'status' => 403, 'message' => 'The chat session could not be verified. Please refresh the page.'];
    }
    try { $account = $accountAuthorizer($accountId, 'applicat'); }
    catch (Throwable) { return ['ok' => false, 'status' => 503, 'message' => 'I cannot check your application records right now. Please try again later.']; }
    if (!is_array($account) || (int) ($account['id'] ?? 0) !== $accountId || strtolower((string) ($account['status_code'] ?? '')) !== 'active') {
        return ['ok' => false, 'status' => 401, 'message' => 'Your applicant session has expired. Please sign in again.'];
    }
    return ['ok' => true, 'account_id' => $accountId];
}

