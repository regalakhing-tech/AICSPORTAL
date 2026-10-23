<?php
declare(strict_types=1);

// Duplicate check: the same beneficiary filed by two applicants (spelling and
// birth-date typos, different assistance), and the same document file or photo.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function dup_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

dup_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');

aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_dup_ensure_schema($c);
$uploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-dup-' . bin2hex(random_bytes(3));
mkdir($uploads);
putenv('AICS_UPLOAD_ROOT=' . $uploads);

$tag = bin2hex(random_bytes(3));
$created = [];
$accounts = [];
$request = static function (array $beneficiary, string $birth, array $options = []) use ($c, $tag, &$created): int {
    $reference = 'DUP-' . $tag . '-' . count($created);
    $stmt = $c->prepare("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_middle_name, beneficiary_last_name, beneficiary_birth_date, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_birth_date, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status) VALUES (?, CURDATE(), ?, ?, ?, ?, 76, ?, ?, ?, ?, ?, 40, 'Poblacion', 'Sibalom', 'male', ?, '[]', 'unclaimed', ?)");
    $complete = trim(implode(' ', $beneficiary));
    $contact = $options['contact'] ?? ('0918' . random_int(1000000, 9999999));
    $applicant = $options['applicant'] ?? ['Pedro' . count($created), 'Garcia' . $tag];
    $applicantBirth = $options['applicant_birth'] ?? '1985-0' . (1 + count($created) % 9) . '-11';
    $assistance = json_encode([$options['assistance'] ?? 'medical']);
    $status = $options['status'] ?? 'pending';
    $stmt->bind_param('ssssssssssss', $reference, $beneficiary[0], $beneficiary[1], $beneficiary[2], $birth, $complete, $contact, $applicant[0], $applicant[1], $applicantBirth, $assistance, $status);
    $stmt->execute();
    $id = (int) $c->insert_id;
    $created[] = $id;
    if (isset($options['account'])) {
        $account = (int) $options['account'];
        $c->query("INSERT INTO aics_account_requests (account_id, request_id) VALUES ({$account}, {$id})");
    }
    return $id;
};
$document = static function (int $requestId, string $type, ?string $sha = null, ?string $dhash = null, string $stored = 'doc.jpg') use ($c): void {
    $stmt = $c->prepare("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size, file_sha256, image_dhash) VALUES (?, ?, 'x.jpg', ?, 'x', 'image/jpeg', 10, ?, ?)");
    $stmt->bind_param('issss', $requestId, $type, $stored, $sha, $dhash);
    $stmt->execute();
};
$matchWith = static function (int $requestId, int $otherId) use ($c): ?array {
    foreach (aics_find_application_duplicates($c, $requestId, true)['matches'] as $match) {
        if ((int) $match['request_id'] === $otherId) return $match;
    }
    return null;
};

try {
    $role = (int) ($c->query('SELECT MIN(role_id) FROM aics_accounts')->fetch_row()[0] ?? 1);
    $statusId = (int) ($c->query('SELECT MIN(account_status_id) FROM aics_accounts')->fetch_row()[0] ?? 1);
    for ($account = 9001; $account <= 9007; $account++) {
        $c->query("INSERT IGNORE INTO aics_accounts (id, role_id, account_status_id, email, password_hash) VALUES ({$account}, {$role}, {$statusId}, 'dup{$account}-{$tag}@example.test', 'x')");
    }
    $juan = ['Juan' . $tag, 'Santos', 'Dela Cruz'];
    $first = $request($juan, '1950-01-01', ['applicant' => ['Maria', 'Reyes'], 'account' => 9001, 'assistance' => 'medical']);

    // Day 2: another applicant files Education for the same beneficiary.
    $education = $request($juan, '1950-01-01', ['account' => 9002, 'assistance' => 'educational_basic']);
    $match = $matchWith($education, $first);
    dup_assert($match !== null && $match['beneficiary_match'] && $match['strength'] === 'strong', 'The same beneficiary filed by a second applicant for another assistance was not flagged.');
    dup_assert(str_contains(implode(' ', $match['reasons']), 'Medical assistance; this one is for Educational'), 'The note does not say the assistance types differ.');

    // Every assistance type, against every other: the person is the same whatever was requested.
    $codes = [];
    $result = $c->query('SELECT assistance_code FROM aics_assistance_types ORDER BY id');
    while ($row = $result?->fetch_row()) $codes[] = (string) $row[0];
    dup_assert(count($codes) >= 6, 'The assistance types were not loaded.');
    $everyone = ['Lorna' . $tag, 'Ruiz', 'Villanueva'];
    $byCode = [];
    foreach ($codes as $index => $code) {
        $byCode[$code] = $request($everyone, '1958-04-12', ['account' => 9001 + ($index % 7), 'assistance' => $code]);
    }
    foreach ($byCode as $code => $id) {
        foreach ($byCode as $otherCode => $otherId) {
            if ($id === $otherId) continue;
            $found = $matchWith($id, $otherId);
            dup_assert($found !== null && $found['beneficiary_match'], "The same beneficiary under {$code} and {$otherCode} was not flagged.");
        }
    }

    foreach (['middle initial' => ['Juan' . $tag, 'S.', 'Dela Cruz'], 'no middle name' => ['JUAN' . strtoupper($tag), '', 'DELA CRUZ'],
              'surname written together' => ['Juan' . $tag, 'Santos', 'Delacruz']] as $case => $name) {
        $id = $request($name, '1950-01-01');
        $found = $matchWith($id, $first);
        dup_assert($found !== null && $found['beneficiary_match'] && $found['strength'] === 'strong', "Same beneficiary with {$case} was missed.");
    }

    $typo = $request($juan, '1950-01-10');
    $found = $matchWith($typo, $first);
    dup_assert($found !== null && $found['strength'] === 'possible' && str_contains(implode(' ', $found['reasons']), 'differs slightly'), 'A birth-date typo was not reported as a possible match.');
    $swapped = $request($juan, '1950-01-01');
    dup_assert($matchWith($swapped, $first) !== null, 'An exact repeat was missed.');

    $brother = $request(['Pedro' . $tag, 'Santos', 'Dela Cruz'], '1952-03-04');
    dup_assert($matchWith($brother, $first) === null, 'A different person with the same surname was flagged.');
    $namesake = $request($juan, '1971-08-22');
    dup_assert($matchWith($namesake, $first) === null, 'A namesake with a different birth date was flagged.');

    // The same death certificate file reused by a different applicant.
    $sha = hash('sha256', 'death-certificate-' . $tag);
    $document($first, 'death_cert', $sha);
    $other = $request(['Rosa' . $tag, '', 'Lim'], '1940-02-02', ['account' => 9003]);
    $document($other, 'death_cert', $sha);
    $found = $matchWith($other, $first);
    dup_assert($found !== null && $found['document_match'] && str_contains(implode(' ', $found['reasons']), 'same file'), 'The same file in two requests was not flagged.');

    // The same photo, re-saved (a few bits differ) or a different document (many differ).
    $photo = str_repeat('a5', 32);
    $document($first, 'med_cert', null, $photo);
    $resaved = $request(['Ana' . $tag, '', 'Tan'], '1960-06-06', ['account' => 9004]);
    $document($resaved, 'med_cert', null, substr($photo, 0, 60) . 'a4a4');
    $found = $matchWith($resaved, $first);
    dup_assert($found !== null && $found['document_match'] && str_contains(implode(' ', $found['reasons']), 'same photo'), 'A re-saved copy of the same photo was not flagged.');
    $differentPhoto = $request(['Lito' . $tag, '', 'Go'], '1961-07-07', ['account' => 9005]);
    $document($differentPhoto, 'med_cert', null, str_repeat('5a', 32));
    dup_assert($matchWith($differentPhoto, $first) === null, 'A different photo was flagged as the same.');

    // An applicant's own ID reused across their requests is normal.
    $idSha = hash('sha256', 'own-id-' . $tag);
    $document($first, 'valid_id', $idSha);
    $again = $request(['Liza' . $tag, '', 'Ong'], '1990-09-09', ['applicant' => ['Maria', 'Reyes'], 'account' => 9001]);
    $document($again, 'valid_id', $idSha);
    $found = $matchWith($again, $first);
    dup_assert($found === null || !$found['document_match'], "The applicant's own ID reused in their next request was flagged as a duplicate document.");

    // Files without a fingerprint are hashed on the spot.
    $closed = $request(['Nena' . $tag, '', 'Uy'], '1945-05-05', ['account' => 9006, 'status' => 'not_approved']);
    $late = $request(['Nilo' . $tag, '', 'Sy'], '1946-05-05', ['account' => 9007]);
    foreach ([$closed, $late] as $id) {
        $reference = (string) $c->query("SELECT reference_no FROM aics_requests WHERE id = {$id}")->fetch_row()[0];
        mkdir($uploads . DIRECTORY_SEPARATOR . $reference);
        file_put_contents($uploads . DIRECTORY_SEPARATOR . $reference . DIRECTORY_SEPARATOR . 'bill.jpg', 'same bill bytes ' . $tag);
        $document($id, 'billing_statement', null, null, 'bill.jpg');
    }
    aics_find_application_duplicates($c, $closed, true);
    $found = $matchWith($late, $closed);
    dup_assert($found !== null && $found['document_match'] && !$found['beneficiary_match'], 'A file reused from a closed request of another applicant was not flagged.');
    $saved = (string) ($c->query("SELECT file_sha256 FROM aics_request_documents WHERE request_id = {$late}")->fetch_row()[0] ?? '');
    dup_assert($saved === hash('sha256', 'same bill bytes ' . $tag), 'The computed fingerprint was not saved.');

    // The PSWDO library uses the shared check and says why.
    $summary = pswdo_find_application_duplicates($c, $education);
    dup_assert($summary['has_duplicate'] && str_contains($summary['summary'], 'Beneficiary Match'), 'The PSWDO duplicate summary does not use the shared check.');

    echo "Duplicate detection DB checks passed.\n";
} finally {
    foreach ($created as $id) $c->query("DELETE FROM aics_requests WHERE id = {$id}");
    $c->query("DELETE FROM aics_account_requests WHERE account_id BETWEEN 9001 AND 9007");
    $c->query("DELETE FROM aics_accounts WHERE id BETWEEN 9001 AND 9007 AND email LIKE 'dup%@example.test'");
    foreach (glob($uploads . '/*/*') ?: [] as $file) unlink($file);
    foreach (glob($uploads . '/*') ?: [] as $folder) rmdir($folder);
    rmdir($uploads);
}
