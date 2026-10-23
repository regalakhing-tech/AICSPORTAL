<?php
declare(strict_types=1);

/**
 * Duplicate-application checks shared by the applicant submit step and the
 * PSWDO request screen. Every match is a review flag; PSWDO staff decide.
 *
 * 1. Same person in another active request. Names are compared part by part,
 *    so "Juan S. Dela Cruz", "Juan Santos Delacruz" and "JUAN DELA CRUZ" are
 *    the same name, "Ma." is Maria, and a one-letter typo in a longer first
 *    name is tolerated. The birth date must match, or be one typo away
 *    (one digit, day and month swapped, or one day off) for a possible match.
 * 2. Same document file in another request: identical bytes (SHA-256), or
 *    the same photo re-saved, forwarded, or edited (256-bit difference hash
 *    saved by the AI worker, ai_processor/fingerprint.py). On the labelled
 *    test set re-saved copies stayed within 16 bits and different documents,
 *    even on the same printed form, were at least 64 bits apart.
 */

const AICS_DUP_PHOTO_MAX_DISTANCE = 24;
const AICS_DUP_HASHES_PER_PAGE = 12;
const AICS_DUP_CLOSED_STATUSES = ['not_approved', 'not approved', 'rejected', 'denied', 'cancelled', 'canceled'];
const AICS_DUP_ID_TYPES = ['valid_id', 'valid_id_back'];

function aics_dup_ensure_schema(mysqli $connection): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $existing = $connection->query("SHOW COLUMNS FROM aics_request_documents LIKE 'image_dhash'");
        if ($existing instanceof mysqli_result && $existing->num_rows > 0) return;
        $connection->query('ALTER TABLE aics_request_documents ADD COLUMN IF NOT EXISTS file_sha256 CHAR(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS image_dhash CHAR(64) DEFAULT NULL');
        $connection->query('CREATE INDEX IF NOT EXISTS idx_aics_request_documents_sha256 ON aics_request_documents (file_sha256)');
    } catch (Throwable $exception) {
        error_log('Duplicate check schema update failed: ' . $exception->getMessage());
    }
}

/** Lowercase ASCII words: accents folded, punctuation removed. */
function aics_dup_fold(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ñ' => 'n', 'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ó' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u']);
    $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

/** @return array<int,string> name words without suffixes, common abbreviations expanded */
function aics_dup_words(string $value): array
{
    $expand = ['ma' => 'maria', 'mª' => 'maria', 'sto' => 'santo', 'sta' => 'santa', 'jo' => 'jose'];
    $words = [];
    foreach (explode(' ', aics_dup_fold($value)) as $word) {
        if ($word === '' || in_array($word, ['jr', 'sr', 'ii', 'iii', 'iv', 'v'], true)) continue;
        $words[] = $expand[$word] ?? $word;
    }
    return $words;
}

/** @return array{first:array<int,string>,middle:string,last:string,label:string}|null */
function aics_dup_person(string $first, string $middle, string $last, string $fallbackFullName = ''): ?array
{
    $firstWords = aics_dup_words($first);
    $lastKey = implode('', aics_dup_words($last));
    if ($firstWords === [] || $lastKey === '') {
        // Older rows may only have the complete name: last word is the surname.
        $words = aics_dup_words($fallbackFullName);
        if (count($words) < 2) return null;
        $lastKey = (string) array_pop($words);
        $firstWords = [array_shift($words)];
        $middle = implode(' ', $words);
    }
    $label = trim(preg_replace('/\s+/', ' ', trim($first . ' ' . $middle . ' ' . $last)) ?? '');
    return ['first' => $firstWords, 'middle' => implode('', aics_dup_words($middle)), 'last' => $lastKey,
        'label' => $label !== '' ? $label : trim($fallbackFullName)];
}

/** 'exact', 'close' (one typo or a missing second given name), or null. */
function aics_dup_name_relation(?array $a, ?array $b): ?string
{
    if ($a === null || $b === null) return null;
    $close = false;
    if ($a['last'] !== $b['last']) {
        if (min(strlen($a['last']), strlen($b['last'])) < 6 || levenshtein($a['last'], $b['last']) > 1) return null;
        $close = true;
    }
    $firstA = implode(' ', $a['first']);
    $firstB = implode(' ', $b['first']);
    if ($firstA !== $firstB) {
        if ($a['first'][0] === $b['first'][0]) {
            $close = true; // "Maria Luisa" and "Maria"
        } elseif (min(strlen($a['first'][0]), strlen($b['first'][0])) >= 5 && levenshtein($a['first'][0], $b['first'][0]) <= 1) {
            $close = true; // "Nicasio" and "Nicacio"
        } else {
            return null;
        }
    }
    if ($a['middle'] !== '' && $b['middle'] !== '' && $a['middle'] !== $b['middle']) {
        $initialOnly = strlen($a['middle']) === 1 || strlen($b['middle']) === 1;
        if (!$initialOnly || $a['middle'][0] !== $b['middle'][0]) return null;
    }
    return $close ? 'close' : 'exact';
}

/** 'same', 'near' (one typo away), 'different', or 'unknown'. */
function aics_dup_birth_relation(?string $a, ?string $b): string
{
    $a = trim((string) $a);
    $b = trim((string) $b);
    $valid = static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date !== '0000-00-00';
    if (!$valid($a) || !$valid($b)) return 'unknown';
    if ($a === $b) return 'same';
    $different = [];
    for ($i = 0; $i < 10; $i++) if ($a[$i] !== $b[$i]) $different[] = $i;
    if (count($different) === 1) return 'near';
    [$left, $right] = count($different) === 2 ? $different : [0, 0];
    if ($right === $left + 1 && $a[$left] === $b[$right] && $a[$right] === $b[$left]) return 'near'; // "10" typed "01"
    if (substr($a, 0, 4) === substr($b, 0, 4) && substr($a, 5, 2) === substr($b, 8, 2) && substr($a, 8, 2) === substr($b, 5, 2)) return 'near';
    $days = abs((strtotime($a) ?: 0) - (strtotime($b) ?: 0)) / 86400;
    return $days > 0 && $days <= 1 ? 'near' : 'different';
}

/** Assistance names for a request's assistance_types_json, for the staff note. */
function aics_dup_assistance_names(string $json): array
{
    $names = ['medical' => 'Medical', 'burial' => 'Burial', 'disaster_victims' => 'Disaster', 'victim_of_disaster' => 'Disaster',
        'special_cases' => 'Special Cases', 'educational_basic' => 'Educational', 'educational' => 'Educational',
        'high_inflation_affected' => 'High Inflation'];
    $codes = json_decode($json, true);
    $output = [];
    foreach (is_array($codes) ? $codes : [] as $code) {
        $code = strtolower(trim((string) $code));
        if ($code !== '') $output[] = $names[$code] ?? ucwords(str_replace('_', ' ', $code));
    }
    return array_values(array_unique($output));
}

function aics_dup_date_label(string $date): string
{
    $time = strtotime($date);
    return $time ? date('F j, Y', $time) : $date;
}

/** @return array<int,string> */
function aics_dup_contacts(string $contactNumber): array
{
    $digits = preg_replace('/\D+/', '', $contactNumber) ?? '';
    if ($digits === '') return [];
    if (str_starts_with($digits, '63') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
    if (str_starts_with($digits, '9') && strlen($digits) === 10) $digits = '0' . $digits;
    return [$digits];
}

/**
 * The photo fingerprint as eight 32-bit numbers, or null for a blank or
 * nearly uniform page (almost all zeros or ones), which would match any blank.
 *
 * @return array<int,int>|null
 */
function aics_dup_photo_ints(?string $hash): ?array
{
    if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) return null;
    $ints = [];
    $ones = 0;
    for ($i = 0; $i < 64; $i += 8) {
        $value = (int) hexdec(substr($hash, $i, 8));
        $ints[] = $value;
        $ones += substr_count(decbin($value), '1');
    }
    return $ones >= 24 && $ones <= 232 ? $ints : null;
}

/** @param array<int,int> $left @param array<int,int> $right */
function aics_dup_photo_distance(array $left, array $right): int
{
    $distance = 0;
    for ($i = 0; $i < 8; $i++) $distance += substr_count(decbin($left[$i] ^ $right[$i]), '1');
    return $distance;
}

/**
 * The fingerprint cut into 25 pieces, each taking every 25th bit so a piece
 * covers the whole page (blank margins would otherwise crowd one piece). Two
 * photos within 24 bits differ in at most 24 pieces, so they share at least
 * one piece exactly: looking pieces up finds every close photo without
 * comparing all pairs.
 *
 * @param array<int,int> $ints
 * @return array<int,string>
 */
function aics_dup_photo_keys(array $ints): array
{
    $bits = '';
    foreach ($ints as $value) $bits .= str_pad(decbin($value), 32, '0', STR_PAD_LEFT);
    $pieces = AICS_DUP_PHOTO_MAX_DISTANCE + 1;
    $keys = array_fill(0, $pieces, '');
    for ($position = 0; $position < 256; $position++) $keys[$position % $pieces] .= $bits[$position];
    foreach ($keys as $piece => $key) $keys[$piece] = $piece . ':' . $key;
    return $keys;
}

function aics_dup_upload_root(): string
{
    $configured = trim((string) getenv('AICS_UPLOAD_ROOT'));
    return $configured !== '' ? $configured : __DIR__ . '/../Applicants/uploads';
}

/**
 * Every request and document fingerprint, read once per page (see
 * aics_dup_snapshot_ref), with lookup indexes so each check only compares
 * requests that could match.
 */
function aics_dup_load_snapshot(mysqli $connection): array
{
    aics_dup_ensure_schema($connection);
    $requests = [];
    $result = $connection->query('SELECT r.id, r.reference_no, r.date_applied, r.assistance_types_json, r.request_status, r.contact_number,
            r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name, r.applicant_birth_date,
            r.beneficiary_first_name, r.beneficiary_middle_name, r.beneficiary_last_name, r.beneficiary_complete_name, r.beneficiary_birth_date,
            MIN(ar.account_id) AS account_id
        FROM aics_requests r LEFT JOIN aics_account_requests ar ON ar.request_id = r.id GROUP BY r.id');
    while ($row = $result?->fetch_assoc()) {
        $id = (int) $row['id'];
        $requests[$id] = [
            'id' => $id,
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'date_applied' => (string) ($row['date_applied'] ?? ''),
            'assistance_types_json' => (string) ($row['assistance_types_json'] ?? '[]'),
            'request_status' => (string) ($row['request_status'] ?? 'pending'),
            'closed' => in_array(strtolower(trim((string) ($row['request_status'] ?? ''))), AICS_DUP_CLOSED_STATUSES, true),
            'account_id' => (int) ($row['account_id'] ?? 0),
            'contacts' => aics_dup_contacts((string) ($row['contact_number'] ?? '')),
            'applicant' => aics_dup_person((string) ($row['applicant_first_name'] ?? ''), (string) ($row['applicant_middle_name'] ?? ''), (string) ($row['applicant_last_name'] ?? '')),
            'applicant_birth' => trim((string) ($row['applicant_birth_date'] ?? '')),
            'beneficiary' => aics_dup_person((string) ($row['beneficiary_first_name'] ?? ''), (string) ($row['beneficiary_middle_name'] ?? ''), (string) ($row['beneficiary_last_name'] ?? ''), (string) ($row['beneficiary_complete_name'] ?? '')),
            'beneficiary_birth' => trim((string) ($row['beneficiary_birth_date'] ?? '')),
            'documents' => [],
        ];
    }
    $index = ['surname' => [], 'birth' => [], 'account' => [], 'sha' => [], 'photo' => []];
    $docs = [];
    $documents = $connection->query('SELECT id, request_id, document_type, stored_name, file_sha256, image_dhash FROM aics_request_documents');
    while ($row = $documents?->fetch_assoc()) {
        $requestId = (int) $row['request_id'];
        if (!isset($requests[$requestId])) continue;
        $id = (int) $row['id'];
        $photo = aics_dup_photo_ints(is_string($row['image_dhash'] ?? null) ? strtolower((string) $row['image_dhash']) : null);
        $docs[$id] = [
            'id' => $id,
            'request_id' => $requestId,
            'type' => (string) ($row['document_type'] ?? ''),
            'stored_name' => (string) ($row['stored_name'] ?? ''),
            'sha256' => is_string($row['file_sha256'] ?? null) ? strtolower((string) $row['file_sha256']) : '',
            'photo' => $photo,
            'keys' => $photo !== null ? aics_dup_photo_keys($photo) : [],
        ];
        $requests[$requestId]['documents'][] = $id;
        if ($docs[$id]['sha256'] !== '') $index['sha'][$docs[$id]['sha256']][] = $id;
        foreach ($docs[$id]['keys'] as $key) $index['photo'][$key][] = $id;
    }
    foreach ($requests as $id => $request) {
        foreach (['beneficiary' => 'beneficiary_birth', 'applicant' => 'applicant_birth'] as $person => $birthKey) {
            if ($request[$person] !== null) $index['surname'][$person . ':' . $request[$person]['last']][] = $id;
            if ($request[$birthKey] !== '') $index['birth'][$person . ':' . $request[$birthKey]][] = $id;
        }
        if ($request['account_id'] > 0) $index['account'][$request['account_id']][] = $id;
    }
    return ['requests' => $requests, 'docs' => $docs, 'index' => $index, 'hashed' => 0, 'results' => []];
}

/** Hash this request's files that the AI worker has not fingerprinted yet (bounded per page). */
function aics_dup_hash_missing(mysqli $connection, array &$snapshot, int $requestId): void
{
    $request = $snapshot['requests'][$requestId];
    foreach ($request['documents'] as $docId) {
        $document = &$snapshot['docs'][$docId];
        if ($document['sha256'] === '' && $snapshot['hashed'] < AICS_DUP_HASHES_PER_PAGE) {
            $file = rtrim(aics_dup_upload_root(), '/\\') . DIRECTORY_SEPARATOR . basename($request['reference_no'])
                . DIRECTORY_SEPARATOR . basename($document['stored_name']);
            $hash = is_file($file) ? hash_file('sha256', $file) : false;
            if (is_string($hash)) {
                $snapshot['hashed']++;
                $document['sha256'] = $hash;
                $snapshot['index']['sha'][$hash][] = $docId;
                try {
                    $stmt = $connection->prepare('UPDATE aics_request_documents SET file_sha256 = ? WHERE id = ? AND file_sha256 IS NULL');
                    $stmt->bind_param('si', $hash, $docId);
                    $stmt->execute();
                    $stmt->close();
                } catch (Throwable) {
                    // The fingerprint is kept for this page even if it cannot be saved.
                }
            }
        }
        unset($document);
    }
}

/**
 * Documents of other requests that are the same file or the same photo as one of this request's.
 *
 * @return array<int,array<int,array{0:array,1:array,2:string}>> other request id => [mine, theirs, 'file'|'photo']
 */
function aics_dup_document_matches(array $snapshot, int $requestId): array
{
    $matches = [];
    foreach ($snapshot['requests'][$requestId]['documents'] as $mineId) {
        $mine = $snapshot['docs'][$mineId];
        $found = [];
        if ($mine['sha256'] !== '') {
            foreach ($snapshot['index']['sha'][$mine['sha256']] ?? [] as $docId) $found[$docId] = 'file';
        }
        foreach ($mine['keys'] as $key) {
            foreach ($snapshot['index']['photo'][$key] ?? [] as $docId) $found[$docId] ??= 'photo';
        }
        foreach ($found as $docId => $kind) {
            $theirs = $snapshot['docs'][$docId];
            if ($theirs['request_id'] === $requestId) continue;
            if ($kind === 'photo' && ($theirs['photo'] === null
                || aics_dup_photo_distance($mine['photo'], $theirs['photo']) > AICS_DUP_PHOTO_MAX_DISTANCE)) continue;
            $matches[$theirs['request_id']][] = [$mine, $theirs, $kind];
        }
    }
    return $matches;
}

/** Requests whose people could match: same surname, same birth date, or same account. */
function aics_dup_identity_candidates(array $snapshot, array $self): array
{
    $index = $snapshot['index'];
    $ids = [];
    foreach (['beneficiary' => 'beneficiary_birth', 'applicant' => 'applicant_birth'] as $person => $birthKey) {
        if ($self[$person] !== null) foreach ($index['surname'][$person . ':' . $self[$person]['last']] ?? [] as $id) $ids[$id] = true;
        if ($self[$birthKey] !== '') foreach ($index['birth'][$person . ':' . $self[$birthKey]] ?? [] as $id) $ids[$id] = true;
    }
    if ($self['account_id'] > 0) foreach ($index['account'][$self['account_id']] ?? [] as $id) $ids[$id] = true;
    unset($ids[$self['id']]);
    return array_keys($ids);
}

/**
 * Find possible duplicates of one request.
 *
 * @return array{has_duplicate:bool,matches:array<int,array<string,mixed>>,summary:string}
 */
function aics_find_application_duplicates(mysqli $connection, int $requestId, bool $refresh = false): array
{
    $empty = ['has_duplicate' => false, 'matches' => [], 'summary' => ''];
    if ($requestId <= 0) return $empty;
    $snapshot = &aics_dup_snapshot_ref($connection, $refresh);
    if (!isset($snapshot['requests'][$requestId])) return $empty;
    if (isset($snapshot['results'][$requestId])) return $snapshot['results'][$requestId];
    aics_dup_hash_missing($connection, $snapshot, $requestId);
    $self = $snapshot['requests'][$requestId];
    $labels = aics_document_type_labels_for_duplicates();
    $documentMatches = aics_dup_document_matches($snapshot, $requestId);
    $candidates = array_unique(array_merge(aics_dup_identity_candidates($snapshot, $self), array_keys($documentMatches)));
    $matches = [];
    foreach ($candidates as $otherId) {
        $other = $snapshot['requests'][$otherId];
        $reasons = [];
        $strong = false;
        $applicantMatch = $beneficiaryMatch = $documentMatch = false;
        $sameAccount = $self['account_id'] > 0 && $self['account_id'] === $other['account_id'];
        $sameContact = array_intersect($self['contacts'], $other['contacts']) !== [];

        if (!$other['closed']) {
            foreach ([['beneficiary', 'beneficiary_birth', 'beneficiary'], ['applicant', 'applicant_birth', 'applicant']] as [$person, $birthKey, $role]) {
                $name = aics_dup_name_relation($self[$person], $other[$person]);
                if ($name === null) continue;
                $birth = aics_dup_birth_relation($self[$birthKey], $other[$birthKey]);
                $label = $other[$person]['label'];
                if ($birth === 'same') {
                    $reasons[] = ucfirst($role) . ($name === 'exact' ? ' is the same person' : ' is very likely the same person')
                        . ": {$label}, born " . aics_dup_date_label($other[$birthKey]) . '.';
                    $strong = $strong || $name === 'exact';
                } elseif ($birth === 'near' && $name === 'exact') {
                    $reasons[] = "Possibly the same {$role}: {$label}, but the birth date differs slightly ("
                        . aics_dup_date_label($self[$birthKey]) . ' here, ' . aics_dup_date_label($other[$birthKey]) . ' there).';
                } elseif ($birth === 'unknown' && $sameContact && $name === 'exact') {
                    $reasons[] = "Same {$role} name ({$label}) and the same contact number.";
                } else {
                    continue;
                }
                if ($role === 'beneficiary') $beneficiaryMatch = true; else $applicantMatch = true;
            }
            if ($sameAccount && !$applicantMatch) {
                $applicantMatch = true;
                $reasons[] = 'Filed from the same applicant account.';
            }
        }

        // A re-application by the same applicant after a closed request, and the
        // applicant's own ID reused across their requests, are normal.
        $reported = [];
        foreach ($documentMatches[$otherId] ?? [] as [$mine, $theirs, $kind]) {
            if ($sameAccount && (in_array($mine['type'], AICS_DUP_ID_TYPES, true) || $other['closed'])) continue;
            if (isset($reported[$mine['id']])) continue;
            $reported[$mine['id']] = true;
            $documentMatch = true;
            $strong = true;
            $mineLabel = $labels[$mine['type']] ?? 'document';
            $theirLabel = $labels[$theirs['type']] ?? 'document';
            $where = $mineLabel === $theirLabel ? "the {$mineLabel}" : "this {$mineLabel} and their {$theirLabel}";
            $reasons[] = $kind === 'file'
                ? "The same file was uploaded as {$where}."
                : "The same photo (re-saved, forwarded, or edited copy) was uploaded as {$where}.";
        }
        if ($reasons === []) continue;
        $mineHelp = aics_dup_assistance_names($self['assistance_types_json']);
        $theirHelp = aics_dup_assistance_names($other['assistance_types_json']);
        if ($mineHelp !== [] && $theirHelp !== [] && $mineHelp !== $theirHelp) {
            $reasons[] = 'That request is for ' . implode(' and ', $theirHelp) . ' assistance; this one is for '
                . implode(' and ', $mineHelp) . ' assistance.';
        }
        $matches[] = [
            'request_id' => $otherId,
            'reference_no' => $other['reference_no'],
            'date_applied' => $other['date_applied'],
            'assistance_types_json' => $other['assistance_types_json'],
            'request_status' => $other['request_status'],
            'applicant_match' => $applicantMatch,
            'beneficiary_match' => $beneficiaryMatch,
            'document_match' => $documentMatch,
            'strength' => $strong ? 'strong' : 'possible',
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
    usort($matches, static fn (array $a, array $b): int => [$b['strength'] === 'strong', $b['request_id']] <=> [$a['strength'] === 'strong', $a['request_id']]);
    $summaryLabels = [];
    foreach ($matches as $match) {
        if ($match['document_match']) $summaryLabels[] = 'Possible Duplicate – Same Document';
        if ($match['applicant_match'] && $match['beneficiary_match']) $summaryLabels[] = 'Possible Duplicate – Applicant & Beneficiary Match';
        elseif ($match['beneficiary_match']) $summaryLabels[] = 'Possible Duplicate – Beneficiary Match';
        elseif ($match['applicant_match']) $summaryLabels[] = 'Possible Duplicate – Applicant Match';
    }
    $references = array_values(array_unique(array_filter(array_map(static fn (array $m): string => trim($m['reference_no']), $matches))));
    $summary = implode('; ', array_values(array_unique($summaryLabels))) . ($references ? '. Matching: ' . implode(', ', array_slice($references, 0, 5)) . '.' : '');
    return $snapshot['results'][$requestId] = ['has_duplicate' => $matches !== [], 'matches' => $matches, 'summary' => $summary];
}

/** The page's snapshot, by reference so hashes added on this page are kept. */
function &aics_dup_snapshot_ref(mysqli $connection, bool $refresh): array
{
    static $holder = null;
    if ($holder === null || $refresh) $holder = aics_dup_load_snapshot($connection);
    return $holder;
}

/** @return array<string,string> */
function aics_document_type_labels_for_duplicates(): array
{
    if (function_exists('aics_document_requirement_label')) {
        static $labels = null;
        if ($labels === null) {
            $labels = [];
            foreach (['valid_id', 'valid_id_back', 'death_cert', 'funeral_contract', 'med_cert', 'billing_statement', 'report_police_med',
                'school_id', 'registration_form', 'school_assessment', 'mdrrmo_cert', 'damaged_house_photo', 'brgy_cert', 'brgy_indigency'] as $type) {
                $labels[$type] = aics_document_requirement_label($type);
            }
        }
        return $labels;
    }
    return ['valid_id' => 'Valid ID', 'valid_id_back' => 'Valid ID (back)', 'death_cert' => 'Death Certificate',
        'funeral_contract' => 'Funeral Contract', 'med_cert' => 'Medical Certificate', 'billing_statement' => 'Billing Statement'];
}
