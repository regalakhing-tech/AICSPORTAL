<?php
declare(strict_types=1);
if (getenv('AICS_HTTP_TEST_MODE') !== '1' || getenv('AICS_TEST_DB_NAME') !== 'aics_http_regression') {
    throw new RuntimeException('Run only against the isolated HTTP regression database.');
}
require_once __DIR__ . '/../ADMIN/phpfiles/admin-common.php';
require_once __DIR__ . '/http-regression-db.php';
$base = 'http://127.0.0.1:8092';
$cookies = [];
function create_user_http(string $method, string $path, array $data = []): array
{
    global $base, $cookies;
    $headers = ['User-Agent: AICS-User-Form-Regression', 'Connection: close'];
    if ($cookies !== []) $headers[] = 'Cookie: ' . implode('; ', $cookies);
    if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $method === 'POST' ? http_build_query($data) : '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
    $body = file_get_contents($base . $path, false, $context);
    $responseHeaders = http_get_last_response_headers();
    $location = '';
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/i', $header, $match)) $cookies[$match[1]] = $match[1] . '=' . $match[2];
        if (str_starts_with($header, 'Location: ')) $location = substr($header, 10);
    }
    return ['body' => (string) $body, 'location' => $location, 'status' => $responseHeaders[0] ?? ''];
}
function create_user_http_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function create_user_http_token(string $html): string
{
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $match);
    create_user_http_assert(isset($match[1]), 'Missing CSRF token.');
    return html_entity_decode($match[1]);
}
$server = null;
$connection = null;
try {
    aics_http_regression_create_database();
    $connection = aics_db_connect();
    admin_ensure_schema($connection);
    $role = admin_resolve_role_id($connection, 'admin');
    $status = admin_resolve_status_id($connection, 'active');
    $email = 'form-admin@example.test';
    $hash = password_hash('TestAdmin!42', PASSWORD_DEFAULT);
    $stmt = $connection->prepare('INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $role, $status, $email, $hash);
    $stmt->execute();
    $stmt->close();
    $log = __DIR__ . '/../scratch/admin-user-form-http.log';
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8092', '-t', dirname(__DIR__)], [['pipe', 'r'], ['file', $log, 'w'], ['file', $log, 'a']], $pipes);
    create_user_http_assert(is_resource($server), 'Unable to start isolated HTTP server.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8092);
        if (is_resource($socket)) { fclose($socket); break; }
        usleep(100000);
    }
    $login = create_user_http('GET', '/ADMIN/login.php');
    $loginPost = create_user_http('POST', '/ADMIN/phpfiles/auth-login.php', ['_csrf' => create_user_http_token($login['body']), 'email' => $email, 'password' => 'TestAdmin!42']);
    create_user_http_assert(str_contains($loginPost['location'], 'login_success'), 'Admin fixture must log in.');
    $page = create_user_http('GET', '/ADMIN/phpfiles/user-management.php');
    create_user_http_assert(str_contains($page['status'], '200'), 'User Management must load.');
    $valid = ['_csrf' => create_user_http_token($page['body']), 'action' => 'create_user', 'return_role' => 'all', 'first_name' => 'Ana María', 'middle_name' => '', 'last_name' => 'Dela Cruz', 'email' => 'form-new@example.test', 'password' => 'TestUser!42', 'confirm_password' => 'TestUser!42', 'role_code' => 'applicat', 'status_code' => 'active', 'contact_number' => '+63 917 123 4567', 'address' => 'Test address'];
    $invalid = create_user_http('POST', '/ADMIN/phpfiles/user-management.php', array_replace($valid, ['first_name' => 'Ana123']));
    create_user_http_assert(str_contains($invalid['location'], 'validation_failed'), 'Invalid names must be rejected server-side.');
    $failed = create_user_http('GET', '/ADMIN/phpfiles/user-management.php?modal=create&error=validation_failed');
    create_user_http_assert(str_contains($failed['body'], 'value="Ana123"') && str_contains($failed['body'], 'aria-invalid="true"'), 'A failed form must reopen with values and field errors.');
    create_user_http_assert(!str_contains($failed['body'], 'TestUser!42'), 'Password must not reappear in a failed response.');
    $created = create_user_http('POST', '/ADMIN/phpfiles/user-management.php', $valid);
    create_user_http_assert(str_contains($created['location'], 'user_created'), 'A valid applicant must be created.');
    $row = $connection->query("SELECT a.id, a.password_hash, p.first_name, p.contact_number FROM aics_accounts a JOIN aics_applicant_profiles p ON p.account_id=a.id WHERE a.email='form-new@example.test'")->fetch_assoc();
    create_user_http_assert(is_array($row) && $row['contact_number'] === '09171234567' && password_verify('TestUser!42', $row['password_hash']), 'Creation must store a normalized mobile, correct profile and password hash.');
    $duplicate = create_user_http('POST', '/ADMIN/phpfiles/user-management.php', array_replace($valid, ['email' => 'form-collision@example.test', 'first_name' => 'Different']));
    create_user_http_assert(!str_contains($duplicate['location'], 'user_created'), 'Duplicate mobile must be rejected, without overwriting another profile.');
    $unchanged = $connection->query('SELECT first_name FROM aics_applicant_profiles WHERE account_id=' . (int) $row['id'])->fetch_assoc();
    create_user_http_assert(($unchanged['first_name'] ?? '') === 'Ana María', 'Existing profile must remain intact.');
    create_user_http_assert((int) $connection->query("SELECT COUNT(*) AS n FROM aics_accounts WHERE email='form-collision@example.test'")->fetch_assoc()['n'] === 0, 'A failed profile insert must roll back the new account.');
    $duplicatePage = create_user_http('GET', '/ADMIN/phpfiles/user-management.php?modal=create&error=validation_failed');
    create_user_http_assert(str_contains($duplicatePage['body'], 'id="create-contact_number-error">This mobile number'), 'Duplicate mobile needs an inline field error.');
    $emailDuplicate = create_user_http('POST', '/ADMIN/phpfiles/user-management.php', array_replace($valid, ['contact_number' => '09181234567']));
    create_user_http_assert(str_contains($emailDuplicate['location'], 'duplicate_email'), 'Duplicate email must remain rejected.');
    $invalidCsrf = create_user_http('POST', '/ADMIN/phpfiles/user-management.php', array_replace($valid, ['_csrf' => 'invalid', 'email' => 'csrf@example.test']));
    create_user_http_assert(str_contains($invalidCsrf['location'], 'invalid_csrf'), 'CSRF protection must remain enforced.');
    $staff = create_user_http('POST', '/ADMIN/phpfiles/user-management.php', array_replace($valid, ['email' => 'staff@example.test', 'role_code' => 'pswdo', 'contact_number' => '', 'position_title' => 'Officer II']));
    create_user_http_assert(str_contains($staff['location'], 'user_created'), 'Staff creation must support optional mobile numbers.');
    $logged = $connection->query("SELECT COUNT(*) AS n FROM aics_admin_audit_logs WHERE event_type='account_created'")->fetch_assoc();
    create_user_http_assert((int) ($logged['n'] ?? 0) === 2, 'Only successful creations should have a success audit event.');
    echo "Admin Add User isolated HTTP and database checks passed.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($connection instanceof mysqli) $connection->close();
    aics_http_regression_drop_database();
}
