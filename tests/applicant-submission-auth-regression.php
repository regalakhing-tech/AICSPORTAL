<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/http-regression-db.php';

/** @return array{status:int,location:string,cookie:string,body:string} */
function submission_http_request(string $url, string $method = 'GET', array $fields = [], string $cookie = '', array $files = []): array
{
    $headers = ['Connection: close'];
    $content = '';
    if ($files !== []) {
        $boundary = '----AicsRegression' . bin2hex(random_bytes(12));
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fieldName = is_array($value) ? $name . '[]' : $name;
                $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$fieldName}\"\r\n\r\n{$item}\r\n";
            }
        }
        foreach ($files as $name => $path) {
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) throw new RuntimeException("Unable to read fixture {$path}.");
            $filename = basename($path);
            $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\nContent-Type: application/pdf\r\n\r\n{$bytes}\r\n";
        }
        $content .= "--{$boundary}--\r\n";
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
    } elseif ($method === 'POST') {
        $content = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($content !== '') $headers[] = 'Content-Length: ' . strlen($content);
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 60,
    ]]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    $location = '';
    $responseCookie = $cookie;
    foreach ($http_response_header ?? [] as $index => $header) {
        if ($index === 0 && preg_match('/\s(\d{3})\s/', $header, $match) === 1) $status = (int) $match[1];
        elseif (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
        elseif (stripos($header, 'Set-Cookie:') === 0) $responseCookie = trim(explode(';', substr($header, 11))[0] ?? '');
    }
    return ['status' => $status, 'location' => $location, 'cookie' => $responseCookie, 'body' => is_string($body) ? $body : ''];
}

function submission_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function submission_temporary_pdf_fixture(): string
{
    $temporary = tempnam(sys_get_temp_dir(), 'aics-submission-');
    if ($temporary === false) throw new RuntimeException('Unable to create temporary PDF fixture.');
    $path = $temporary . '.pdf';
    if (!rename($temporary, $path)) throw new RuntimeException('Unable to name temporary PDF fixture.');
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    if (file_put_contents($path, $pdf) === false) throw new RuntimeException('Unable to write temporary PDF fixture.');
    return $path;
}

function submission_unique_phone(mysqli $connection): string
{
    do $phone = '09' . random_int(100000000, 999999999);
    while (aics_mobile_number_in_use($connection, $phone));
    return $phone;
}

function submission_complete_profile(mysqli $connection, int $accountId): void
{
    $stmt = $connection->prepare("UPDATE aics_applicant_profiles SET date_of_birth='1990-01-01', civil_status='single', highest_educational_attainment='College Graduate', occupation='Student', estimated_monthly_income='No Income', province='Antique', municipality='San Jose de Buenavista', barangay='Atabay', address_line='Purok 1', complete_address='Purok 1, Atabay, San Jose de Buenavista, Antique' WHERE account_id=?");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $stmt->close();
}

/** @return array<string,string> */
function submission_valid_fields(string $phone): array
{
    return [
        'date_applied' => date('Y-m-d'), 'beneficiary_first_name' => 'Test', 'beneficiary_last_name' => 'Beneficiary',
        'beneficiary_birth_date' => '1995-01-01', 'beneficiary_age' => '31', 'beneficiary_complete_name' => 'Test Beneficiary',
        'beneficiary_barangay' => 'Atabay', 'beneficiary_sitio_purok' => 'Purok 2', 'beneficiary_municipality' => 'San Jose de Buenavista', 'beneficiary_province' => 'Antique',
        'contact_number' => $phone, 'applicant_first_name' => 'Browser', 'applicant_last_name' => 'Supplied', 'applicant_relationship' => 'Sibling',
        'applicant_birth_date' => '1990-01-01', 'applicant_age' => '36', 'barangay' => 'Atabay', 'sitio_purok' => 'Purok 1',
        'municipality' => 'San Jose de Buenavista', 'province' => 'Antique', 'gender' => 'male', 'employment' => 'Student',
        'estimated_income' => 'No Income', 'civil_status' => 'single', 'highest_educational_attainment' => 'College Graduate',
        'problem_presented' => 'Submission authentication regression test.', 'client_category' => 'youth', 'family_members_not_applicable' => '1', 'urgency_response' => 'no',
        'assistance_type' => 'special_cases', 'assistance_type_other' => 'Submission authentication regression case', 'claim_status' => 'unclaimed', 'amount' => '0',
    ];
}

$connection = aics_db_connect();
if (strtolower(AICS_DB_NAME) === 'aicsdb') throw new RuntimeException('Refusing fixture-producing HTTP regression against the development database. Configure an isolated HTTP runtime database.');
aics_http_regression_require_seed_data($connection);
aics_ensure_auth_schema($connection);
$base = rtrim((string) (getenv('AICS_HTTP_BASE_URL') ?: 'http://localhost/AICS/AICS/Applicants'), '/');
$password = 'SubmitRegression2026';
$fixture = submission_temporary_pdf_fixture();
$accountIds = [];
$requestIds = [];
$referenceNos = [];
$identifiers = [];
$pswdoAccountId = 0;

try {
    $unauthenticated = submission_http_request($base . '/php_files/save-request.php', 'POST');
    submission_assert($unauthenticated['status'] === 401, 'D: unauthenticated submission was not rejected.');

    // A: phone-only applicant completes an actual submission.
    $phoneOnly = submission_unique_phone($connection);
    $identifiers[] = $phoneOnly;
    $signup = submission_http_request($base . '/php_files/auth-signup.php', 'POST', ['first_name'=>'Phone','last_name'=>'Only','contact_number'=>$phoneOnly,'password'=>$password,'confirm_password'=>$password]);
    submission_assert($signup['status'] === 302 && str_contains($signup['location'], 'signup_success'), 'A: phone-only signup failed.');
    $lookup = $connection->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number=?'); $lookup->bind_param('s', $phoneOnly); $lookup->execute(); $accountId=(int)($lookup->get_result()?->fetch_assoc()['account_id']??0); $lookup->close();
    $accountIds[] = $accountId; submission_complete_profile($connection, $accountId);
    $login = submission_http_request($base . '/php_files/auth-login.php', 'POST', ['login_identifier'=>$phoneOnly,'password'=>$password]);
    submission_assert($login['status'] === 302 && $login['cookie'] !== '', 'A: phone login failed.');
    $page = submission_http_request($base . '/php_files/submit-request.php', 'GET', [], $login['cookie']);
    submission_assert($page['status'] === 200, 'F/G: authenticated application form was not accessible.');
    $submissionStarted = microtime(true);
    $saved = submission_http_request($base . '/php_files/save-request.php', 'POST', submission_valid_fields($phoneOnly), $login['cookie'], ['doc_valid_id'=>$fixture,'doc_report_police_med'=>$fixture]);
    $submissionSeconds = microtime(true) - $submissionStarted;
    $savedPayload = json_decode($saved['body'], true);
    submission_assert($saved['status'] === 200 && ($savedPayload['ok'] ?? false) === true, 'A/H: phone-only submission failed: ' . $saved['body']);
    $submittedReferenceNo = (string) $savedPayload['reference_no'];
    $referenceNos[] = $submittedReferenceNo;
    $submitted = $connection->prepare('SELECT id FROM aics_requests WHERE reference_no = ? LIMIT 1');
    $submitted->bind_param('s', $submittedReferenceNo); $submitted->execute();
    $submittedRequestId = (int) ($submitted->get_result()?->fetch_assoc()['id'] ?? 0); $submitted->close();
    submission_assert($submittedRequestId > 0, 'A/H: submitted request was not persisted.');
    $reviewCount = (int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id WHERE d.request_id = ' . $submittedRequestId)?->fetch_assoc()['total'] ?? -1);
    $leaseCount = (int) ($connection->query('SELECT COUNT(*) AS total FROM document_ai_request_worker_leases WHERE request_id = ' . $submittedRequestId)?->fetch_assoc()['total'] ?? -1);
    submission_assert($reviewCount === 2 && $leaseCount <= 1, 'A/H: committed documents were not queued exactly once or duplicate request workers were created. reviews=' . $reviewCount . ' leases=' . $leaseCount);
    echo 'Applicant submission response: ' . number_format($submissionSeconds, 3) . "; AI reviews: {$reviewCount}; worker leases: {$leaseCount}.\n";

    // B/C: an email account submits by email. Cancelling that request must not
    // bypass the account-wide one-year cooldown when the applicant logs in by phone.
    $emailPhone = submission_unique_phone($connection);
    $email = 'submit.' . bin2hex(random_bytes(5)) . '@example.com';
    array_push($identifiers, $emailPhone, $email);
    $signup = submission_http_request($base . '/php_files/auth-signup.php', 'POST', ['first_name'=>'Email','last_name'=>'Applicant','contact_number'=>$emailPhone,'email'=>$email,'password'=>$password,'confirm_password'=>$password]);
    submission_assert($signup['status'] === 302 && str_contains($signup['location'], 'signup_success'), 'B: email signup failed.');
    $lookup = $connection->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number=?'); $lookup->bind_param('s', $emailPhone); $lookup->execute(); $emailAccountId=(int)($lookup->get_result()?->fetch_assoc()['account_id']??0); $lookup->close();
    $accountIds[] = $emailAccountId; submission_complete_profile($connection, $emailAccountId);
    $emailLogin = submission_http_request($base . '/php_files/auth-login.php', 'POST', ['login_identifier'=>strtoupper($email),'password'=>$password]);
    $saved = submission_http_request($base . '/php_files/save-request.php', 'POST', submission_valid_fields($emailPhone), $emailLogin['cookie'], ['doc_valid_id'=>$fixture,'doc_report_police_med'=>$fixture]);
    $savedPayload = json_decode($saved['body'], true);
    submission_assert($saved['status'] === 200 && ($savedPayload['ok'] ?? false) === true, 'B: email-login submission failed: ' . $saved['body']);
    $firstEmailReference = (string) $savedPayload['reference_no']; $referenceNos[] = $firstEmailReference;
    $cancel = $connection->prepare("UPDATE aics_requests r JOIN aics_account_requests ar ON ar.request_id=r.id SET r.request_status='cancelled' WHERE ar.account_id=? AND r.reference_no=?");
    $cancel->bind_param('is', $emailAccountId, $firstEmailReference); $cancel->execute(); $cancel->close();
    submission_http_request($base . '/php_files/auth-logout.php', 'GET', [], $emailLogin['cookie']);
    $phoneLogin = submission_http_request($base . '/php_files/auth-login.php', 'POST', ['login_identifier'=>$emailPhone,'password'=>$password]);
    $saved = submission_http_request($base . '/php_files/save-request.php', 'POST', submission_valid_fields($emailPhone), $phoneLogin['cookie'], ['doc_valid_id'=>$fixture,'doc_report_police_med'=>$fixture]);
    $savedPayload = json_decode($saved['body'], true);
    submission_assert($saved['status'] === 400 && !($savedPayload['ok'] ?? true) && str_contains((string) ($savedPayload['message'] ?? ''), 'one-year cooldown'), 'C: cancelled request bypassed the account-wide cooldown: ' . $saved['body']);

    // E: a real PSWDO session cannot submit as an applicant.
    $roleId=(int)($connection->query("SELECT id FROM aics_roles WHERE role_code='pswdo'")?->fetch_assoc()['id']??0); $statusId=(int)($connection->query("SELECT id FROM aics_account_statuses WHERE status_code='active'")?->fetch_assoc()['id']??0);
    $staffEmail='submit.pswdo.' . bin2hex(random_bytes(4)) . '@example.com'; $identifiers[]=$staffEmail; $hash=password_hash($password,PASSWORD_DEFAULT);
    $staff=$connection->prepare('INSERT INTO aics_accounts(role_id,account_status_id,email,password_hash) VALUES(?,?,?,?)'); $staff->bind_param('iiss',$roleId,$statusId,$staffEmail,$hash); $staff->execute(); $pswdoAccountId=(int)$connection->insert_id; $staff->close();
    $staffLogin=submission_http_request(dirname($base) . '/Pswdo/php_files/auth-login.php','POST',['email'=>$staffEmail,'password'=>$password]);
    $staffSubmit=submission_http_request($base . '/php_files/save-request.php','POST',[],$staffLogin['cookie']);
    submission_assert($staffSubmit['status']===401,'E: PSWDO session was accepted by applicant submission.');

    foreach ($accountIds as $id) {
        $rows=$connection->query("SELECT r.id,r.reference_no FROM aics_requests r JOIN aics_account_requests ar ON ar.request_id=r.id WHERE ar.account_id=".(int)$id);
        while($row=$rows?->fetch_assoc()){ $requestIds[]=(int)$row['id']; $referenceNos[]=(string)$row['reference_no']; }
    }
    submission_assert(count(array_unique($requestIds))===2,'H: expected two submitted test requests; the cancelled request must block a replacement during cooldown.');
    $docs=$connection->query('SELECT COUNT(*) AS total FROM aics_request_documents WHERE request_id IN ('.implode(',',array_unique($requestIds)).')')?->fetch_assoc();
    submission_assert((int)($docs['total']??0)===4,'H: uploaded document records were not preserved.');
    echo "Applicant submission authentication regression checks A-H passed.\n";
} finally {
    foreach (array_unique($accountIds) as $accountId) {
        if ($accountId <= 0) continue;
        $owned = $connection->query('SELECT r.id, r.reference_no FROM aics_requests r JOIN aics_account_requests ar ON ar.request_id=r.id WHERE ar.account_id=' . (int) $accountId);
        while ($row = $owned?->fetch_assoc()) {
            $requestIds[] = (int) $row['id'];
            $referenceNos[] = (string) $row['reference_no'];
        }
    }
    foreach (array_unique($requestIds) as $requestId) { $delete=$connection->prepare('DELETE FROM aics_requests WHERE id=?'); $delete->bind_param('i',$requestId); $delete->execute(); $delete->close(); }
    foreach (array_unique($accountIds) as $accountId) { if($accountId<=0)continue; $delete=$connection->prepare('DELETE FROM aics_accounts WHERE id=?'); $delete->bind_param('i',$accountId); $delete->execute(); $delete->close(); }
    if($pswdoAccountId>0){$delete=$connection->prepare('DELETE FROM aics_accounts WHERE id=?');$delete->bind_param('i',$pswdoAccountId);$delete->execute();$delete->close();}
    foreach(array_unique($identifiers) as $identifier){$delete=$connection->prepare('DELETE FROM aics_admin_login_attempts WHERE email=?');$delete->bind_param('s',$identifier);$delete->execute();$delete->close();}
    if (is_file($fixture)) @unlink($fixture);
    foreach(array_unique($referenceNos) as $referenceNo) aics_http_regression_remove_upload_directory($referenceNo);
    $connection->close();
}
