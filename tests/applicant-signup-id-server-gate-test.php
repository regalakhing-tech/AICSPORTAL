<?php
declare(strict_types=1);

// The child submits a matching name and birth date, with forged client-side
// approval fields. A rejected scanner result must stop before database access.
if (($argv[1] ?? '') === 'submit') {
    ini_set('session.save_path', (string) ($argv[2] ?? ''));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $attempt = '0b6f7a3c-1d2e-4f50-8a9b-0c1d2e3f4a5b';
    $_POST = [
        'first_name' => 'Juan', 'middle_name' => '', 'last_name' => 'Dela Cruz',
        'date_of_birth' => '1975-03-12', 'contact_number' => '09123456789',
        'password' => 'ValidPassword2026', 'confirm_password' => 'ValidPassword2026',
        'signup_attempt_id' => $attempt, 'ajax' => '1',
        'is_supported_id' => 'true', 'decision' => 'ok',
    ];
    require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
    aics_start_session();
    $_SESSION['aics_signup_id'][$attempt] = ['result' => [
        'status' => 'completed', 'decision' => 'ok', 'document_detected' => true,
        'is_identification_card' => true, 'is_supported_id' => true,
        'confidence' => 'high', 'id_type' => 'school_id',
        'issuer' => 'University of the Philippines',
        'last_name' => 'DELA CRUZ', 'given_names' => 'JUAN', 'middle_name' => null,
        'birth_date' => '1975-03-12',
    ]];
    require __DIR__ . '/../Applicants/php_files/auth-signup.php';
    exit(1);
}

$sessionDir = __DIR__ . '/../scratch/id-gate-' . bin2hex(random_bytes(6));
if (!mkdir($sessionDir, 0700, true)) throw new RuntimeException('Could not create isolated test sessions.');
$process = proc_open([PHP_BINARY, __FILE__, 'submit', $sessionDir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not start sign-up gate test.');
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
foreach (glob($sessionDir . '/sess_*') ?: [] as $file) unlink($file);
rmdir($sessionDir);
$response = json_decode((string) $output, true);
if ($code !== 0 || ($response['auth'] ?? null) !== 'id_not_verified') {
    throw new RuntimeException('Matching public university student ID reached registration: ' . $output . ' ' . $error);
}
echo "Applicant sign-up server ID gate test passed.\n";
