<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/applicant-id-verification.php';
require_once __DIR__ . '/http-regression-db.php';

function birthdate_db_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$privateRoot = (string) getenv('AICS_PRIVATE_ROOT');
$workspace = realpath(__DIR__ . '/..');
birthdate_db_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
birthdate_db_assert($workspace !== false && str_starts_with(str_replace('\\', '/', strtolower($privateRoot)), str_replace('\\', '/', strtolower($workspace)) . '/scratch/'), 'Use a private test directory inside the workspace.');

if (($argv[1] ?? '') === 'submit') {
    [$iso, $phone, $attemptId, $label] = array_slice($argv, 2) + ['', '', '', ''];
    birthdate_db_assert(in_array($iso, ['2005-04-03', '2005-03-04'], true), 'Unexpected test birth date.');
    $folder = $privateRoot . '/signup_ids/' . $attemptId;
    birthdate_db_assert(is_dir($folder), 'Missing isolated ID fixtures.');
    $sessionFolder = $privateRoot . '/sessions';
    if (!is_dir($sessionFolder)) mkdir($sessionFolder, 0700, true);
    ini_set('session.save_path', $sessionFolder);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'first_name' => 'Date', 'middle_name' => '', 'last_name' => 'Regression',
        'contact_number' => $phone, 'date_of_birth' => $iso,
        'password' => 'DateCheck2026', 'confirm_password' => 'DateCheck2026',
        'signup_attempt_id' => $attemptId, 'ajax' => '1',
    ];
    aics_start_session();
    $_SESSION['aics_signup_id'][$attemptId] = [
        'result' => [
            'status' => 'completed', 'decision' => 'ok', 'birth_date' => $iso,
            'document_detected' => true, 'is_identification_card' => true,
            'is_supported_id' => true, 'confidence' => 'high',
            'issuer' => 'Philippine Statistics Authority',
            'last_name' => 'REGRESSION', 'given_names' => 'DATE',
            'middle_name' => null, 'id_type' => 'philsys_national_id',
            'id_type_label' => 'Philippine National ID (PhilSys)', 'flags' => [],
        ],
        'files' => ['front' => $folder . '/front.jpg', 'back' => $folder . '/back.jpg'],
    ];
    require __DIR__ . '/../Applicants/php_files/auth-signup.php';
    exit;
}

$connection = aics_db_connect();
aics_http_regression_require_seed_data($connection);
$created = [];
try {
    foreach ([['2005-04-03', 'April 3'], ['2005-03-04', 'March 4']] as [$iso, $label]) {
        $phone = '09' . random_int(100000000, 999999999);
        $attemptId = sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 0, 3), substr(bin2hex(random_bytes(2)), 0, 3), bin2hex(random_bytes(6)));
        $folder = $privateRoot . '/signup_ids/' . $attemptId;
        birthdate_db_assert(mkdir($folder, 0700, true), 'Could not create isolated ID fixtures.');
        file_put_contents($folder . '/front.jpg', 'front test fixture');
        file_put_contents($folder . '/back.jpg', 'back test fixture');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' submit ' . escapeshellarg($iso) . ' ' . escapeshellarg($phone) . ' ' . escapeshellarg($attemptId) . ' ' . escapeshellarg($label);
        $lines = [];
        exec($command, $lines, $exitCode);
        $response = json_decode(implode("\n", $lines), true);
        birthdate_db_assert($exitCode === 0 && ($response['auth'] ?? '') === 'signup_success', "$label signup failed: " . implode("\n", $lines));
        $query = $connection->prepare('SELECT p.account_id, p.date_of_birth, v.id_birth_date, v.front_path, v.back_path FROM aics_applicant_profiles p JOIN aics_applicant_id_verifications v ON v.account_id = p.account_id WHERE p.contact_number = ?');
        $query->bind_param('s', $phone);
        $query->execute();
        $row = $query->get_result()->fetch_assoc();
        $query->close();
        birthdate_db_assert(is_array($row) && $row['date_of_birth'] === $iso && $row['id_birth_date'] === $iso, "$label was swapped or not persisted.");
        $created[] = $row;
        echo "PASS $label -> $iso in signup profile and ID verification\n";
    }
} finally {
    foreach ($created as $row) {
        $accountId = (int) $row['account_id'];
        $delete = $connection->prepare('DELETE FROM aics_accounts WHERE id = ?');
        $delete->bind_param('i', $accountId);
        $delete->execute();
        $delete->close();
        foreach (['front_path', 'back_path'] as $key) {
            $path = (string) $row[$key];
            if (str_starts_with(str_replace('\\', '/', strtolower($path)), str_replace('\\', '/', strtolower($privateRoot)) . '/applicant_ids/')) @unlink($path);
        }
        @rmdir($privateRoot . '/applicant_ids/' . $accountId);
    }
    $connection->close();
}
