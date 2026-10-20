<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * @param array<string, string>|null $form
 * @return array{status: int, location: string, cookie: string, body: string}
 */
function auth_http_request(string $url, ?array $form = null, string $cookie = ''): array
{
    $headers = ['Connection: close'];
    $options = [
        'method' => $form === null ? 'GET' : 'POST',
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 20,
    ];

    if ($form !== null) {
        $content = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($content);
        $options['content'] = $content;
    }
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $options['header'] = implode("\r\n", $headers);

    $context = stream_context_create(['http' => $options]);
    $body = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $location = '';
    $responseCookie = $cookie;

    foreach ($responseHeaders as $index => $header) {
        if ($index === 0 && preg_match('/\s(\d{3})\s/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        } elseif (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, strlen('Location:')));
        } elseif (stripos($header, 'Set-Cookie:') === 0) {
            $cookieParts = explode(';', trim(substr($header, strlen('Set-Cookie:'))));
            $responseCookie = trim((string) ($cookieParts[0] ?? ''));
        }
    }

    return [
        'status' => $status,
        'location' => $location,
        'cookie' => $responseCookie,
        'body' => is_string($body) ? $body : '',
    ];
}

function auth_http_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$connection = aics_db_connect();
$baseUrl = rtrim((string) (getenv('AICS_HTTP_BASE_URL') ?: 'http://localhost/AICS/AICS/Applicants'), '/');
$password = 'Recovery2026';
$phone = '';
$accountId = 0;

try {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = '09' . (string) random_int(100000000, 999999999);
        $check = $connection->prepare(
            'SELECT COUNT(*) AS total FROM aics_applicant_profiles WHERE contact_number = ?'
        );
        $check->bind_param('s', $candidate);
        $check->execute();
        $total = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0);
        $check->close();
        if ($total === 0) {
            $phone = $candidate;
            break;
        }
    }
    auth_http_assert($phone !== '', 'Could not generate a unique test phone number.');

    $signup = auth_http_request($baseUrl . '/php_files/auth-signup.php', [
        'first_name' => 'Recovery',
        'middle_name' => 'Flow',
        'last_name' => 'Verification',
        'contact_number' => $phone,
        'password' => $password,
        'confirm_password' => $password,
    ]);
    echo 'signup.status=' . $signup['status'] . PHP_EOL;
    echo 'signup.location=' . $signup['location'] . PHP_EOL;
    auth_http_assert(
        $signup['status'] === 302 && str_contains($signup['location'], 'login.php') && str_contains($signup['location'], 'signup_success'),
        'Phone-only signup did not redirect to the applicant login page.'
    );

    $accountLookup = $connection->prepare(
        "SELECT a.id FROM aics_accounts a INNER JOIN aics_applicant_profiles p ON p.account_id = a.id WHERE p.contact_number = ? AND p.first_name = 'Recovery' AND p.last_name = 'Verification' LIMIT 1"
    );
    $accountLookup->bind_param('s', $phone);
    $accountLookup->execute();
    $accountId = (int) ($accountLookup->get_result()->fetch_assoc()['id'] ?? 0);
    $accountLookup->close();
    auth_http_assert($accountId > 0, 'Signup did not persist the temporary account.');

    $login = auth_http_request($baseUrl . '/php_files/auth-login.php', [
        'login_identifier' => $phone,
        'password' => $password,
    ]);
    echo 'login.status=' . $login['status'] . PHP_EOL;
    echo 'login.location=' . $login['location'] . PHP_EOL;
    auth_http_assert(
        $login['status'] === 302 && str_contains($login['location'], 'dashboard.php'),
        'Phone login did not redirect to the applicant dashboard.'
    );
    auth_http_assert($login['cookie'] !== '', 'Login did not establish a session cookie.');

    $blockedSubmit = auth_http_request($baseUrl . '/dashboard.php?page=submit', null, $login['cookie']);
    echo 'incomplete_submit.status=' . $blockedSubmit['status'] . PHP_EOL;
    echo 'incomplete_submit.location=' . $blockedSubmit['location'] . PHP_EOL;
    auth_http_assert(
        $blockedSubmit['status'] === 302 && str_contains($blockedSubmit['location'], 'my-profile.php?profile_notice=incomplete'),
        'An incomplete applicant could enter the application workflow through the dashboard route.'
    );

    $loginDashboard = auth_http_request($baseUrl . '/dashboard.php?page=overview', null, $login['cookie']);
    echo 'login_dashboard.status=' . $loginDashboard['status'] . PHP_EOL;
    if ($loginDashboard['status'] === 302 && $loginDashboard['location'] !== '') {
        $loginDashboard = auth_http_request(
            $baseUrl . '/' . ltrim($loginDashboard['location'], '/'),
            null,
            $login['cookie']
        );
        echo 'login_dashboard_routed.status=' . $loginDashboard['status'] . PHP_EOL;
    }
    auth_http_assert($loginDashboard['status'] === 200, 'Dashboard was not reachable after login.');

    echo "Applicant signup and login HTTP smoke test passed.\n";
} finally {
    if ($accountId > 0) {
        $delete = $connection->prepare('DELETE FROM aics_accounts WHERE id = ? LIMIT 1');
        $delete->bind_param('i', $accountId);
        $delete->execute();
        $delete->close();
    }

    if ($phone !== '') {
        $deleteAttempts = $connection->prepare(
            'DELETE FROM aics_admin_login_attempts WHERE email = ? AND login_portal = ?'
        );
        $loginPortal = 'applicant';
        $deleteAttempts->bind_param('ss', $phone, $loginPortal);
        $deleteAttempts->execute();
        $deleteAttempts->close();

        $remaining = $connection->prepare(
            "SELECT COUNT(*) AS total FROM aics_applicant_profiles WHERE contact_number = ? AND first_name = 'Recovery' AND last_name = 'Verification'"
        );
        $remaining->bind_param('s', $phone);
        $remaining->execute();
        $remainingTotal = (int) ($remaining->get_result()->fetch_assoc()['total'] ?? 0);
        $remaining->close();
        echo 'temporary_accounts_remaining=' . $remainingTotal . PHP_EOL;
    }
    $connection->close();
}
