<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

/** @return array{status:int,location:string,cookie:string} */
function applicant_auth_request(string $url, array $form): array
{
    $content = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($content),
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 20,
    ]]);
    file_get_contents($url, false, $context);

    $status = 0;
    $location = '';
    $cookie = '';
    foreach ($http_response_header ?? [] as $index => $header) {
        if ($index === 0 && preg_match('/\s(\d{3})\s/', $header, $match) === 1) {
            $status = (int) $match[1];
        } elseif (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        } elseif (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = trim(explode(';', substr($header, 11))[0] ?? '');
        }
    }

    return ['status' => $status, 'location' => $location, 'cookie' => $cookie];
}

function applicant_auth_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function applicant_auth_phone(mysqli $connection): string
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $phone = '09' . random_int(100000000, 999999999);
        $stmt = $connection->prepare('SELECT 1 FROM aics_applicant_profiles WHERE contact_number = ? LIMIT 1');
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $exists = ($stmt->get_result()?->num_rows ?? 0) > 0;
        $stmt->close();
        if (!$exists) return $phone;
    }
    throw new RuntimeException('Unable to allocate a test phone number.');
}

$connection = aics_db_connect();
aics_ensure_auth_schema($connection);
$baseUrl = rtrim((string) (getenv('AICS_HTTP_BASE_URL') ?: 'http://localhost/AICS/AICS/Applicants'), '/');
$password = 'Regression2026';
$wrongPassword = 'Incorrect2026';
$phones = [applicant_auth_phone($connection), applicant_auth_phone($connection), applicant_auth_phone($connection)];
while (count(array_unique($phones)) !== 3) {
    $phones = [applicant_auth_phone($connection), applicant_auth_phone($connection), applicant_auth_phone($connection)];
}
$email = 'phone.regression.' . bin2hex(random_bytes(5)) . '@example.com';
$accountIds = [];
$identifiers = [];

$signup = static fn (string $phone, ?string $emailValue, string $lastName): array => applicant_auth_request(
    $baseUrl . '/php_files/auth-signup.php',
    [
        'first_name' => 'Phone',
        'last_name' => $lastName,
        'contact_number' => $phone,
        'email' => $emailValue ?? '',
        'password' => $password,
        'confirm_password' => $password,
    ]
);
$login = static fn (string $identifier, string $passwordValue): array => applicant_auth_request(
    $baseUrl . '/php_files/auth-login.php',
    ['login_identifier' => $identifier, 'password' => $passwordValue]
);

try {
    applicant_auth_assert(aics_normalize_mobile_number($phones[0]) === $phones[0], 'Local phone normalization failed.');
    applicant_auth_assert(aics_normalize_mobile_number('+63' . substr($phones[0], 1)) === $phones[0], '+63 phone normalization failed.');
    applicant_auth_assert(aics_normalize_mobile_number('63' . substr($phones[0], 1)) === $phones[0], '63 phone normalization failed.');
    applicant_auth_assert(aics_normalize_mobile_number('+' . $phones[0]) === null, 'Invalid +09 format was accepted.');

    // A: phone-only signup and local-format login.
    $response = $signup($phones[0], null, 'OnlyOne');
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'signup_success'), 'A: phone-only signup failed.');
    $response = $login($phones[0], $password);
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'dashboard.php'), 'A: local phone login failed.');

    // B: the same account accepts the equivalent international format.
    $international = '+63' . substr($phones[0], 1);
    $response = $login($international, $password);
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'dashboard.php'), 'B: +63 phone login failed.');

    // E: canonical duplicate detection rejects a different input format.
    $countryCode = '63' . substr($phones[0], 1);
    $response = $signup($countryCode, null, 'Duplicate');
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'phone_exists'), 'E: equivalent duplicate phone was not rejected.');

    // F: a second blank-email account remains independent.
    $response = $signup($phones[1], null, 'OnlyTwo');
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'signup_success'), 'F: second blank-email signup failed.');
    $response = $login($phones[1], $password);
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'dashboard.php'), 'F: second blank-email account login failed.');

    // C/D: an optional-email account can use either identifier.
    $response = $signup($phones[2], strtoupper($email), 'WithEmail');
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'signup_success'), 'C: phone-plus-email signup failed.');
    $response = $login($phones[2], $password);
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'dashboard.php'), 'C: phone login for email account failed.');
    $response = $login(strtoupper($email), $password);
    applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'dashboard.php'), 'D: normalized email login failed.');

    // G: both identifier types use the same generic authentication error.
    foreach ([$phones[2], $email] as $identifier) {
        $response = $login($identifier, $wrongPassword);
        applicant_auth_assert($response['status'] === 302 && str_contains($response['location'], 'invalid_credentials'), 'G: wrong password did not return the generic error.');
    }

    $placeholders = implode(',', array_fill(0, count($phones), '?'));
    $types = str_repeat('s', count($phones));
    $lookup = $connection->prepare("SELECT a.id, a.email, p.contact_number FROM aics_accounts a JOIN aics_applicant_profiles p ON p.account_id = a.id WHERE p.contact_number IN ({$placeholders})");
    $lookup->bind_param($types, ...$phones);
    $lookup->execute();
    foreach ($lookup->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [] as $row) {
        $accountIds[] = (int) $row['id'];
        applicant_auth_assert(aics_normalize_mobile_number((string) $row['contact_number']) === $row['contact_number'], 'Database phone is not canonical.');
    }
    $lookup->close();
    applicant_auth_assert(count($accountIds) === 3, 'Expected three independent accounts after regression scenarios.');

    $index = $connection->query("SHOW INDEX FROM aics_applicant_profiles WHERE Key_name = 'uq_aics_applicant_profiles_contact_number'");
    applicant_auth_assert(($index?->num_rows ?? 0) === 1, 'Unique phone database index is missing.');

    echo "Applicant phone authentication regression checks A-G passed.\n";
} finally {
    foreach ($phones as $phone) {
        $lookup = $connection->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number = ?');
        $lookup->bind_param('s', $phone);
        $lookup->execute();
        foreach ($lookup->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [] as $row) {
            $accountIds[] = (int) $row['account_id'];
        }
        $lookup->close();
    }
    foreach (array_unique($accountIds) as $accountId) {
        $delete = $connection->prepare('DELETE FROM aics_accounts WHERE id = ? LIMIT 1');
        $delete->bind_param('i', $accountId);
        $delete->execute();
        $delete->close();
    }
    foreach (array_merge($phones, ['+63' . substr($phones[0], 1), $email, strtoupper($email)]) as $identifier) {
        $delete = $connection->prepare("DELETE FROM aics_admin_login_attempts WHERE email = ? AND login_portal = 'applicant'");
        $delete->bind_param('s', $identifier);
        $delete->execute();
        $delete->close();
    }
    $connection->close();
}
