<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

/**
 * @param array<string,string> $cookies
 * @return array{status:int,location:string,body:string,cookies:array<string,string>}
 */
function portal_request(string $url, string $method, array $fields, array $cookies, bool $json = false): array
{
    $headers = ['Connection: close'];
    $content = '';
    if ($method === 'POST') {
        $content = $json
            ? json_encode($fields, JSON_THROW_ON_ERROR)
            : http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded');
        $headers[] = 'Content-Length: ' . strlen($content);
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 30,
    ]]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $index => $header) {
        if ($index === 0 && preg_match('/\s(\d{3})\s/', $header, $match) === 1) {
            $status = (int) $match[1];
        } elseif (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        } elseif (stripos($header, 'Set-Cookie:') === 0) {
            $cookieHeader = trim(substr($header, 11));
            $pair = trim(explode(';', $cookieHeader, 2)[0] ?? '');
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($name !== '') {
                if ($value === '' || strtolower($value) === 'deleted' || stripos($cookieHeader, 'expires=') !== false) {
                    unset($cookies[$name]);
                } else {
                    $cookies[$name] = $value;
                }
            }
        }
    }

    return ['status' => $status, 'location' => $location, 'body' => is_string($body) ? $body : '', 'cookies' => $cookies];
}

function portal_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$connection = aics_db_connect();
aics_ensure_auth_schema($connection);
$applicantBase = rtrim((string) (getenv('AICS_HTTP_BASE_URL') ?: 'http://localhost/AICS/AICS/Applicants'), '/');
$pswdoBase = dirname($applicantBase) . '/Pswdo';
$password = 'PortalIsolation2026';
$applicantId = 0;
$pswdoId = 0;
$phone = '';
$staffEmail = 'portal.isolation.' . bin2hex(random_bytes(5)) . '@example.com';

try {
    do $phone = '09' . random_int(100000000, 999999999);
    while (aics_mobile_number_in_use($connection, $phone));

    $signup = portal_request($applicantBase . '/php_files/auth-signup.php', 'POST', [
        'first_name' => 'Portal', 'last_name' => 'Isolation', 'contact_number' => $phone,
        'password' => $password, 'confirm_password' => $password,
    ], []);
    portal_assert($signup['status'] === 302, 'A/H: phone-only Applicant signup failed.');
    $stmt = $connection->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number = ? LIMIT 1');
    $stmt->bind_param('s', $phone); $stmt->execute();
    $applicantId = (int) ($stmt->get_result()?->fetch_assoc()['account_id'] ?? 0); $stmt->close();
    portal_assert($applicantId > 0, 'A/H: temporary Applicant was not persisted.');
    $stmt = $connection->prepare("UPDATE aics_applicant_profiles SET date_of_birth='1990-01-01', civil_status='single', highest_educational_attainment='College Graduate', occupation='Student', estimated_monthly_income='No Income', province='Antique', municipality='San Jose de Buenavista', barangay='Atabay', address_line='Purok 1', complete_address='Purok 1, Atabay, San Jose de Buenavista, Antique' WHERE account_id=?");
    $stmt->bind_param('i', $applicantId); $stmt->execute(); $stmt->close();

    $roleId = (int) ($connection->query("SELECT id FROM aics_roles WHERE role_code='pswdo'")?->fetch_assoc()['id'] ?? 0);
    $statusId = (int) ($connection->query("SELECT id FROM aics_account_statuses WHERE status_code='active'")?->fetch_assoc()['id'] ?? 0);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $connection->prepare('INSERT INTO aics_accounts(role_id,account_status_id,email,password_hash) VALUES(?,?,?,?)');
    $stmt->bind_param('iiss', $roleId, $statusId, $staffEmail, $hash); $stmt->execute();
    $pswdoId = (int) $connection->insert_id; $stmt->close();

    // A/B/H: Applicant login, autosave, then PSWDO login in the same browser cookie jar.
    $login = portal_request($applicantBase . '/php_files/auth-login.php', 'POST', ['login_identifier'=>$phone,'password'=>$password], []);
    $cookies = $login['cookies'];
    portal_assert(isset($cookies['AICSAPPLICANTSESSID']) && !isset($cookies['AICSPSWDOSESSID']), 'A: Applicant did not receive only its portal cookie.');
    $draft = portal_request($applicantBase . '/php_files/draft-save.php', 'POST', ['current_step'=>1,'version'=>0,'data'=>['problem_presented'=>'session isolation']], $cookies, true);
    $cookies = $draft['cookies'];
    portal_assert($draft['status'] === 200, 'A: Applicant draft autosave failed before PSWDO login: ' . $draft['body']);

    $staffLogin = portal_request($pswdoBase . '/php_files/auth-login.php', 'POST', ['email'=>$staffEmail,'password'=>$password], $cookies);
    $cookies = $staffLogin['cookies'];
    portal_assert(isset($cookies['AICSAPPLICANTSESSID'], $cookies['AICSPSWDOSESSID']), 'B: same-browser portal cookies were not retained independently.');
    $draftAfter = portal_request($applicantBase . '/php_files/draft-get.php', 'GET', [], $cookies);
    portal_assert($draftAfter['status'] === 200, 'B/H: PSWDO login invalidated the Applicant session.');
    $applicationPage = portal_request($applicantBase . '/php_files/submit-request.php', 'GET', [], $cookies);
    portal_assert($applicationPage['status'] === 200, 'B/H: Applicant navigation failed after PSWDO login.');
    $submissionAuthProbe = portal_request($applicantBase . '/php_files/save-request.php', 'POST', [], $cookies);
    portal_assert($submissionAuthProbe['status'] !== 401, 'B/H: final submission endpoint lost Applicant authentication after PSWDO login.');
    $staffData = portal_request($pswdoBase . '/php_files/overview-dashboard-data.php', 'GET', [], $cookies);
    portal_assert($staffData['status'] === 200, 'B: PSWDO session was not authenticated independently.');

    // C: re-login Applicant while PSWDO remains authenticated.
    $applicantAgain = portal_request($applicantBase . '/php_files/auth-login.php', 'POST', ['login_identifier'=>$phone,'password'=>$password], $cookies);
    $cookies = $applicantAgain['cookies'];
    $staffAfterApplicant = portal_request($pswdoBase . '/php_files/overview-dashboard-data.php', 'GET', [], $cookies);
    portal_assert($staffAfterApplicant['status'] === 200, 'C: Applicant login invalidated the PSWDO session.');

    // D: Applicant logout removes only the Applicant cookie/session.
    $applicantLogout = portal_request($applicantBase . '/php_files/auth-logout.php', 'GET', [], $cookies);
    $cookies = $applicantLogout['cookies'];
    portal_assert(!isset($cookies['AICSAPPLICANTSESSID']) && isset($cookies['AICSPSWDOSESSID']), 'D: Applicant logout did not isolate its cookie.');
    portal_assert(portal_request($applicantBase . '/php_files/draft-get.php', 'GET', [], $cookies)['status'] === 401, 'D: Applicant remained authenticated after logout.');
    portal_assert(portal_request($pswdoBase . '/php_files/overview-dashboard-data.php', 'GET', [], $cookies)['status'] === 200, 'D: Applicant logout ended PSWDO session.');

    // E: PSWDO logout removes only PSWDO after Applicant logs back in.
    $applicantLogin = portal_request($applicantBase . '/php_files/auth-login.php', 'POST', ['login_identifier'=>$phone,'password'=>$password], $cookies);
    $cookies = $applicantLogin['cookies'];
    $pswdoLogout = portal_request($pswdoBase . '/php_files/auth-logout.php', 'GET', [], $cookies);
    $cookies = $pswdoLogout['cookies'];
    portal_assert(isset($cookies['AICSAPPLICANTSESSID']) && !isset($cookies['AICSPSWDOSESSID']), 'E: PSWDO logout did not isolate its cookie.');
    portal_assert(portal_request($applicantBase . '/php_files/draft-get.php', 'GET', [], $cookies)['status'] === 200, 'E: PSWDO logout ended Applicant session.');
    portal_assert(portal_request($pswdoBase . '/php_files/overview-dashboard-data.php', 'GET', [], $cookies)['status'] === 401, 'E: PSWDO remained authenticated after logout.');

    // F: a cookie from one portal cannot authorize the other portal.
    $applicantOnly = ['AICSAPPLICANTSESSID' => $cookies['AICSAPPLICANTSESSID']];
    portal_assert(portal_request($pswdoBase . '/php_files/overview-dashboard-data.php', 'GET', [], $applicantOnly)['status'] === 401, 'F: Applicant cookie authorized PSWDO endpoint.');
    $staffOnlyLogin = portal_request($pswdoBase . '/php_files/auth-login.php', 'POST', ['email'=>$staffEmail,'password'=>$password], []);
    $staffOnly = ['AICSPSWDOSESSID' => $staffOnlyLogin['cookies']['AICSPSWDOSESSID'] ?? ''];
    portal_assert(portal_request($applicantBase . '/php_files/save-request.php', 'POST', [], $staffOnly)['status'] === 401, 'F: PSWDO cookie authorized Applicant submission.');

    // G: a deleted account's still-present session is rejected by the authoritative DB lookup.
    $staleCookies = $applicantOnly;
    $stmt = $connection->prepare('DELETE FROM aics_accounts WHERE id=?'); $stmt->bind_param('i', $applicantId); $stmt->execute(); $stmt->close();
    $applicantId = 0;
    portal_assert(portal_request($applicantBase . '/php_files/save-request.php', 'POST', [], $staleCookies)['status'] === 401, 'G: stale Applicant account session was accepted.');

    echo "Portal session isolation regression checks A-H passed.\n";
} finally {
    if ($applicantId > 0) { $stmt=$connection->prepare('DELETE FROM aics_accounts WHERE id=?'); $stmt->bind_param('i',$applicantId); $stmt->execute(); $stmt->close(); }
    if ($pswdoId > 0) { $stmt=$connection->prepare('DELETE FROM aics_accounts WHERE id=?'); $stmt->bind_param('i',$pswdoId); $stmt->execute(); $stmt->close(); }
    foreach ([$phone, $staffEmail] as $identifier) {
        if ($identifier === '') continue;
        $stmt=$connection->prepare('DELETE FROM aics_admin_login_attempts WHERE email=?'); $stmt->bind_param('s',$identifier); $stmt->execute(); $stmt->close();
    }
    $connection->close();
}
