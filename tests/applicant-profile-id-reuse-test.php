<?php
declare(strict_types=1);

// A new application reuses the ID photos saved at sign-up (profile) unless the
// applicant uploads different ones.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/applicant-id-verification.php';
require_once __DIR__ . '/http-regression-db.php';

function reuse_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$save = (string) file_get_contents($root . '/Applicants/php_files/save-request.php');
reuse_assert(str_contains($save, 'aics_profile_id_documents($connection, $accountId)'), 'Submitting must look up the profile ID photos.');
reuse_assert(str_contains($save, 'aics_attach_profile_id_documents('), 'Submitting must attach the profile ID photos.');
reuse_assert(str_contains($save, 'isset($profileDocuments[$docIdKey])'), 'A profile ID photo must satisfy its required slot.');
reuse_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/submit-request.php'), 'window.AICS_PROFILE_ID_DOCUMENTS'), 'The form must know about the profile ID photos.');
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
reuse_assert(substr_count($js, 'AICS_PROFILE_ID_DOCUMENTS') >= 2, 'The ID tiles and the review count must use the profile ID photos.');

reuse_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
$privateRoot = aics_id_private_root();
reuse_assert(!str_starts_with(str_replace('\\', '/', strtolower($privateRoot)), str_replace('\\', '/', strtolower((string) realpath($root)))), 'Use a private root outside the web root.');
aics_http_regression_create_database();
$c = aics_db_connect();
aics_http_regression_require_seed_data($c);
aics_id_ensure_schema($c);

$role = (int) $c->query("SELECT id FROM aics_roles WHERE role_code = 'applicat'")->fetch_row()[0];
$status = (int) $c->query("SELECT id FROM aics_account_statuses WHERE status_code = 'active'")->fetch_row()[0];
$c->query("INSERT INTO aics_accounts (role_id, account_status_id, password_hash) VALUES ({$role}, {$status}, 'x')");
$accountId = (int) $c->insert_id;
$dir = aics_id_account_dir($accountId);
if (!is_dir($dir)) mkdir($dir, 0700, true);
$front = $dir . DIRECTORY_SEPARATOR . 'front.jpg';
$back = $dir . DIRECTORY_SEPARATOR . 'back.png';
file_put_contents($front, 'front-photo');
file_put_contents($back, 'back-photo-bytes');
$stmt = $c->prepare("INSERT INTO aics_applicant_id_verifications (account_id, status, id_type_label, front_path, back_path) VALUES (?, 'verified', 'Philippine National ID (PhilSys)', ?, ?)");
$stmt->bind_param('iss', $accountId, $front, $back);
$stmt->execute();
$stmt->close();

$docs = aics_profile_id_documents($c, $accountId);
reuse_assert(array_keys($docs) === ['valid_id', 'valid_id_back'], 'front and back become the two ID slots');
reuse_assert($docs['valid_id']['mime_type'] === 'image/jpeg' && $docs['valid_id_back']['mime_type'] === 'image/png', 'photo types are kept');
reuse_assert($docs['valid_id_back']['size'] === 16 && $docs['valid_id']['id_type_label'] === 'Philippine National ID (PhilSys)', 'size and ID type are reported');
reuse_assert(aics_profile_id_documents($c, $accountId + 999) === [], 'an account without sign-up photos gets nothing');

$c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status) VALUES ('REUSE-1', CURDATE(), 'A', 'B', 30, 'A B', '09170000000', 'A', 'B', 30, 'X', 'Sibalom', 'male', '[\"medical\"]', 'unclaimed')");
$requestId = (int) $c->insert_id;
// The applicant uploaded a new front photo; only the back comes from the profile.
$c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES ({$requestId}, 'valid_id', 'new.jpg', 'new.jpg', 'x', 'image/jpeg', 5)");
$uploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-reuse-' . bin2hex(random_bytes(4));
$created = [];
$attached = aics_attach_profile_id_documents($c, $requestId, $uploadDir, 'AICS/Applicants/uploads/REUSE-1', $docs, $created);
reuse_assert($attached === 1 && count($created) === 1, 'only the missing slot is filled from the profile');
$row = $c->query("SELECT document_type, stored_name, file_path, mime_type, file_size FROM aics_request_documents WHERE request_id = {$requestId} AND document_type = 'valid_id_back'")->fetch_assoc();
reuse_assert(is_array($row) && $row['mime_type'] === 'image/png' && (int) $row['file_size'] === 16, 'the back photo is recorded');
reuse_assert(str_starts_with($row['file_path'], 'AICS/Applicants/uploads/REUSE-1/valid_id_back-') && is_file($uploadDir . DIRECTORY_SEPARATOR . $row['stored_name']), 'the file is copied into the request folder');
reuse_assert((string) file_get_contents($uploadDir . DIRECTORY_SEPARATOR . $row['stored_name']) === 'back-photo-bytes' && is_file($back), 'it is a copy; the profile photo stays');
reuse_assert((int) $c->query("SELECT COUNT(*) FROM aics_request_documents WHERE request_id = {$requestId} AND document_type = 'valid_id'")->fetch_row()[0] === 1, 'the uploaded front photo is kept');

foreach ($created as $path) @unlink($path);
@rmdir($uploadDir);
@unlink($front); @unlink($back); @rmdir($dir);
$c->close();
echo "Applicant profile ID reuse checks passed.\n";
