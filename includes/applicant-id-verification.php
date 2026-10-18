<?php
declare(strict_types=1);

/**
 * Applicant sign-up ID verification: private storage, the AICS AI ID check,
 * name/birth-date matching, and the verification record.
 *
 * ID photos never go under the web root. No ID numbers are read or stored.
 */

const AICS_ID_SCAN_LIMIT_PER_ATTEMPT = 6;
const AICS_ID_SCAN_LIMIT_PER_HOUR = 20;
const AICS_ID_SIGNUP_SCAN_TTL = 86400;

function aics_id_private_root(): string
{
    $configured = trim((string) getenv('AICS_PRIVATE_ROOT'));
    return rtrim($configured !== '' ? $configured : 'C:\\xampp\\aics_private', '\\/');
}

/** Folder for one sign-up attempt's photos, or null for an invalid attempt id. */
function aics_id_signup_dir(string $attemptId): ?string
{
    $attemptId = strtolower(trim($attemptId));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $attemptId) !== 1) {
        return null;
    }
    return aics_id_private_root() . DIRECTORY_SEPARATOR . 'signup_ids' . DIRECTORY_SEPARATOR . $attemptId;
}

function aics_id_account_dir(int $accountId): string
{
    return aics_id_private_root() . DIRECTORY_SEPARATOR . 'applicant_ids' . DIRECTORY_SEPARATOR . max(0, $accountId);
}

/** Reject an account folder that resolves through a link to another location. */
function aics_id_resolved_root_matches_account(int $accountId, string $resolvedRoot, string $resolvedParent): bool
{
    return $accountId > 0
        && basename($resolvedRoot) === (string) $accountId
        && strcasecmp(dirname($resolvedRoot), $resolvedParent) === 0;
}

/** Resolve one saved ID photo only when it belongs to this account folder. */
function aics_id_photo_path_for_account(int $accountId, array $record, string $side): ?string
{
    if ($accountId <= 0 || !in_array($side, ['front', 'back'], true)) return null;
    $stored = (string) ($record[$side . '_path'] ?? '');
    $parent = realpath(aics_id_private_root() . DIRECTORY_SEPARATOR . 'applicant_ids');
    $root = realpath(aics_id_account_dir($accountId));
    $file = $stored !== '' ? realpath($stored) : false;
    if ($parent === false || $root === false || $file === false || !is_file($file)
        || !aics_id_resolved_root_matches_account($accountId, $root, $parent)) return null;
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'png', 'webp'], true)
        || strtolower(basename($file)) !== $side . '.' . $extension) return null;
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with(strtolower($file), strtolower($prefix)) ? $file : null;
}

/** Uppercase letters-only name tokens; accents and Ñ compare as plain letters. */
function aics_id_name_tokens(?string $value): array
{
    $map = ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ñ' => 'N', 'ñ' => 'n'];
    $plain = strtoupper(strtr((string) $value, $map));
    $plain = (string) preg_replace('/[^A-Z]+/', ' ', $plain);
    return array_values(array_filter(explode(' ', $plain), static fn (string $token): bool => $token !== ''));
}

/** Equal, or one substituted letter in a name of 5+ letters (a typical misread). */
function aics_id_tokens_close(string $left, string $right): bool
{
    if ($left === $right) return true;
    if (strlen($left) !== strlen($right) || strlen($left) < 5) return false;
    $differences = 0;
    for ($index = 0; $index < strlen($left); $index++) {
        if ($left[$index] !== $right[$index]) $differences++;
    }
    return $differences === 1;
}

/**
 * Does the entered name belong to the person on the ID?
 *
 * @param array{last_name:?string,given_names:?string,middle_name:?string} $id
 * @return array{match:bool,reason:string}
 */
function aics_id_names_match(array $id, string $first, ?string $middle, string $last): array
{
    $idLast = implode('', aics_id_name_tokens($id['last_name'] ?? ''));
    $idGiven = aics_id_name_tokens($id['given_names'] ?? '');
    $idMiddle = aics_id_name_tokens($id['middle_name'] ?? '');
    $enteredLast = implode('', aics_id_name_tokens($last));
    $enteredFirst = aics_id_name_tokens($first);
    $enteredMiddle = aics_id_name_tokens($middle);
    $shown = static fn (?string $value): string => trim((string) $value);

    if ($idLast === '' || $idGiven === []) {
        return ['match' => false, 'reason' => 'The name on your ID could not be read.'];
    }
    if (!aics_id_tokens_close($enteredLast, $idLast)) {
        return ['match' => false, 'reason' => 'Your last name must match your ID (' . $shown($id['last_name']) . ').'];
    }
    $firstOk = $enteredFirst !== [] && aics_id_tokens_close($enteredFirst[0], $idGiven[0]);
    foreach ($enteredFirst as $token) {
        $firstOk = $firstOk && array_filter($idGiven, static fn (string $given): bool => aics_id_tokens_close($token, $given)) !== [];
    }
    if (!$firstOk) {
        return ['match' => false, 'reason' => 'Your first name must match your ID (' . $shown($id['given_names']) . ').'];
    }
    if ($idMiddle !== [] && $enteredMiddle !== []) {
        $enteredJoined = implode('', $enteredMiddle);
        $idJoined = implode('', $idMiddle);
        $middleMatches = aics_id_tokens_close($enteredJoined, $idJoined)
            || ((strlen($enteredJoined) === 1 || strlen($idJoined) === 1)
                && $enteredJoined[0] === $idJoined[0]);
        if (!$middleMatches) {
            return ['match' => false, 'reason' => 'Your middle name must match your ID (' . $shown($id['middle_name']) . ').'];
        }
    }
    // An ID without a middle name cannot contradict one; a middle typed from the
    // given names (e.g. "Juan" + "Santos" for "JUAN SANTOS") is also fine.
    return ['match' => true, 'reason' => 'The name matches your ID.'];
}

function aics_id_birthdates_match(?string $idDate, string $entered): bool
{
    return $idDate === null || $idDate === '' || $idDate === $entered;
}

/** Each scan owns immutable filenames, even when another request uses the same attempt. */
function aics_id_new_scan_paths(string $folder, array $extensions): array
{
    $nonce = bin2hex(random_bytes(16));
    $paths = [];
    foreach (['front', 'back'] as $side) {
        $extension = (string) ($extensions[$side] ?? '');
        if (!in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new InvalidArgumentException('Invalid ID photo extension.');
        }
        $paths[$side] = $folder . DIRECTORY_SEPARATOR . $side . '-' . $nonce . '.' . $extension;
    }
    return $paths;
}

/** A second, server-side gate over the trusted scanner result kept in the session. */
function aics_id_result_is_verified(array $result): bool
{
    if (($result['status'] ?? null) !== 'completed' || ($result['decision'] ?? null) !== 'ok'
        || ($result['document_detected'] ?? null) !== true
        || ($result['is_identification_card'] ?? null) !== true
        || ($result['is_supported_id'] ?? null) !== true
        || ($result['confidence'] ?? null) !== 'high') return false;

    $issuers = [
        'philsys_national_id' => ['philippine statistics authority', 'philsys', 'psa'],
        'passport' => ['department of foreign affairs', 'dfa'],
        'drivers_license' => ['land transportation office', 'lto'],
        'umid' => ['social security system', 'sss', 'government service insurance system', 'gsis'],
        'sss_id' => ['social security system', 'sss'], 'gsis_id' => ['government service insurance system', 'gsis'],
        'philhealth_id' => ['philippine health insurance', 'philhealth'],
        'prc_id' => ['professional regulation commission', 'prc'],
        'pagibig_id' => ['pag-ibig', 'hdmf', 'home development mutual fund'],
        'owwa_id' => ['overseas workers welfare administration', 'owwa'],
        'dole_id' => ['department of labor and employment', 'dole'],
        'voters_id' => ['commission on elections', 'comelec'],
        'voters_certification' => ['commission on elections', 'comelec'],
        'postal_id' => ['philippine postal', 'phlpost'],
        'nbi_clearance' => ['national bureau of investigation', 'nbi'],
        'four_ps_id' => ['department of social welfare', 'dswd'],
        'pwd_id' => ['city government', 'municipal government', 'persons with disability affairs', 'pdao', 'dswd'],
        'solo_parent_id' => ['city government', 'municipal government', 'dswd', 'department of social welfare'],
        'senior_citizen_id' => ['office of senior citizens affairs', 'osca', 'city government', 'municipal government'],
        'city_municipal_id' => ['city government', 'municipal government', 'city hall', 'municipal hall'],
        'barangay_id' => ['barangay ', 'barangay government'],
        'police_clearance' => ['philippine national police', 'police station', 'pnp'],
    ];
    $kind = (string) ($result['id_type'] ?? '');
    $issuer = strtolower(trim((string) ($result['issuer'] ?? '')));
    if ($kind === 'other_government_id') {
        return in_array($issuer, [
            'department of agriculture', 'department of education', 'department of health',
            'department of justice', 'department of finance', 'department of public works and highways',
            'department of science and technology', 'department of trade and industry',
            'department of tourism', 'department of transportation', 'department of energy',
            'department of environment and natural resources', 'department of budget and management',
            'department of national defense', 'department of information and communications technology',
            'department of migrant workers', 'department of human settlements and urban development',
            'department of the interior and local government', 'office of civil defense',
            'office of the president', 'office of the vice president', 'civil service commission',
            'commission on human rights', 'bureau of immigration', 'land registration authority',
        ], true);
    }
    if ($issuer === '' || !isset($issuers[$kind])) return false;
    foreach ($issuers[$kind] as $marker) {
        if (str_contains($issuer, $marker)) return true;
    }
    return false;
}

function aics_id_ensure_schema(mysqli $connection): void
{
    $connection->query("CREATE TABLE IF NOT EXISTS aics_applicant_id_verifications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        id_type VARCHAR(40) DEFAULT NULL,
        id_type_label VARCHAR(80) DEFAULT NULL,
        id_last_name VARCHAR(80) DEFAULT NULL,
        id_given_names VARCHAR(80) DEFAULT NULL,
        id_middle_name VARCHAR(80) DEFAULT NULL,
        id_birth_date DATE DEFAULT NULL,
        flags VARCHAR(255) DEFAULT NULL,
        front_path VARCHAR(255) NOT NULL,
        back_path VARCHAR(255) NOT NULL,
        ai_model VARCHAR(80) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_applicant_id_verification_account (account_id),
        KEY idx_applicant_id_verification_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** @return array<string,mixed>|null */
function aics_id_verification_for_account(mysqli $connection, int $accountId): ?array
{
    aics_id_ensure_schema($connection);
    $statement = $connection->prepare('SELECT * FROM aics_applicant_id_verifications WHERE account_id = ? LIMIT 1');
    $statement->bind_param('i', $accountId);
    $statement->execute();
    $row = $statement->get_result()?->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}

/**
 * Move the attempt's photos into the account folder and record the result.
 *
 * @param array<string,mixed> $scan Session copy of the ID check
 */
function aics_id_record_verification(mysqli $connection, int $accountId, array $scan): void
{
    aics_id_ensure_schema($connection);
    $target = aics_id_account_dir($accountId);
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException('ID storage is unavailable.');
    }
    $paths = [];
    foreach (['front', 'back'] as $side) {
        $source = (string) ($scan['files'][$side] ?? '');
        $destination = $target . DIRECTORY_SEPARATOR . $side . '.' . pathinfo($source, PATHINFO_EXTENSION);
        if ($source === '' || !is_file($source) || !rename($source, $destination)) {
            throw new RuntimeException('ID photo is unavailable.');
        }
        $paths[$side] = $destination;
    }
    $result = is_array($scan['result'] ?? null) ? $scan['result'] : [];
    $status = ($result['decision'] ?? '') === 'ok' ? (($result['flags'] ?? []) === [] ? 'verified' : 'flagged') : 'pending_staff';
    $flags = implode(',', array_map('strval', (array) ($result['flags'] ?? [])));
    $birth = is_string($result['birth_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $result['birth_date']) === 1 ? $result['birth_date'] : null;
    $values = [$accountId, $status, $result['id_type'] ?? null, $result['id_type_label'] ?? null, $result['last_name'] ?? null,
        $result['given_names'] ?? null, $result['middle_name'] ?? null, $birth, $flags, $paths['front'], $paths['back'], $result['model'] ?? null];
    $statement = $connection->prepare('INSERT INTO aics_applicant_id_verifications
        (account_id, status, id_type, id_type_label, id_last_name, id_given_names, id_middle_name, id_birth_date, flags, front_path, back_path, ai_model)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), id_type = VALUES(id_type), id_type_label = VALUES(id_type_label),
        id_last_name = VALUES(id_last_name), id_given_names = VALUES(id_given_names), id_middle_name = VALUES(id_middle_name),
        id_birth_date = VALUES(id_birth_date), flags = VALUES(flags), front_path = VALUES(front_path), back_path = VALUES(back_path), ai_model = VALUES(ai_model)');
    $statement->bind_param('isssssssssss', ...$values);
    $statement->execute();
    $statement->close();
    @rmdir(dirname((string) ($scan['files']['front'] ?? '')));
}

/** Session-based limits so the AI check cannot be called endlessly. */
function aics_id_rate_limited(array &$session, string $attemptId): bool
{
    $now = time();
    $recent = array_values(array_filter((array) ($session['aics_id_scan_times'] ?? []), static fn ($time): bool => is_int($time) && $time > $now - 3600));
    $attemptCount = (int) ($session['aics_signup_id'][$attemptId]['scan_count'] ?? 0);
    if (count($recent) >= AICS_ID_SCAN_LIMIT_PER_HOUR || $attemptCount >= AICS_ID_SCAN_LIMIT_PER_ATTEMPT) {
        $session['aics_id_scan_times'] = $recent;
        return true;
    }
    $recent[] = $now;
    $session['aics_id_scan_times'] = $recent;
    return false;
}

/** Delete photos from sign-ups that were never finished. */
function aics_id_cleanup_abandoned_scans(): void
{
    $base = aics_id_private_root() . DIRECTORY_SEPARATOR . 'signup_ids';
    foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $folder) {
        if (filemtime($folder) !== false && filemtime($folder) < time() - AICS_ID_SIGNUP_SCAN_TTL) {
            foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
            @rmdir($folder);
        }
    }
}

/**
 * Run `python -m ai_processor.id_check` and return its JSON result. Never
 * throws: a missing Python, timeout, or bad output means "unavailable", and
 * the applicant is asked to try again (verification is automatic).
 *
 * @return array<string,mixed>
 */
function aics_id_run_check(string $front, string $back, int $timeoutSeconds = 75): array
{
    $unavailable = ['status' => 'unavailable', 'decision' => 'unavailable', 'flags' => [],
        'message' => "We couldn't read your ID right now. Please try again in a moment."];
    $python = trim((string) getenv('AICS_PYTHON_EXECUTABLE'));
    $root = trim((string) getenv('AICS_PROJECT_ROOT')) ?: dirname(__DIR__);
    if ($python === '' || !is_file($python) || !function_exists('proc_open')) {
        error_log('AICS ID check unavailable: Python is not configured.');
        return $unavailable;
    }
    $command = [$python];
    $version = trim((string) getenv('AICS_PYTHON_VERSION'));
    if (preg_match('/^-\d+(\.\d+)?$/', $version) === 1) $command[] = $version;
    array_push($command, '-m', 'ai_processor.id_check', '--front', $front, '--back', $back);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) return $unavailable;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $started = time();
    while (true) {
        $output .= (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $output .= (string) stream_get_contents($pipes[1]);
            break;
        }
        if (time() - $started > $timeoutSeconds) {
            proc_terminate($process);
            error_log('AICS ID check timed out.');
            return $unavailable;
        }
        usleep(150000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
    $decoded = json_decode((string) end($lines), true);
    if (!is_array($decoded) || !in_array($decoded['decision'] ?? '', ['ok', 'retake', 'edited', 'unavailable', 'unsupported_id', 'unknown_id'], true)) {
        error_log('AICS ID check returned no usable result.');
        return $unavailable;
    }
    return $decoded;
}
