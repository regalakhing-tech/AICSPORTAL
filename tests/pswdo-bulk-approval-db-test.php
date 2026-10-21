<?php
declare(strict_types=1);

// Bulk approval approves without per-document checks and reports each request.
// Only cases that send no SMS are used: one request without its documents
// (moved to Incomplete Requirements) and one AICS AI has not released yet.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function bulk_db_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

bulk_db_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');

aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
aics_ai_gate_ensure_schema($c);

$request = static function (string $gateStatus) use ($c): int {
    $reference = 'BULK-' . bin2hex(random_bytes(3));
    $c->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, client_categories_json, claim_status, request_status, ai_gate_status) VALUES ('{$reference}', CURDATE(), 'Ana', 'Cruz', 70, 'Ana Cruz', '09170000000', 'Maria', 'Reyes', 40, 'X', 'Sibalom', 'female', '[\"medical\"]', '[\"senior_citizen\"]', 'unclaimed', 'pending', '{$gateStatus}')");
    $id = (int) $c->insert_id;
    $c->query("INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT {$id}, id FROM aics_assistance_types WHERE assistance_code = 'medical'");
    return $id;
};

try {
    $missingDocuments = $request('released');
    $stillChecking = $request('pending');

    $outcome = pswdo_bulk_approve_requests_action($c, [(string) $missingDocuments, $stillChecking, 0, 'x', $missingDocuments], 1);

    bulk_db_assert($outcome['approved'] === [], 'A request without its documents was approved.');
    bulk_db_assert(count($outcome['incomplete']) === 1 && $outcome['incomplete'][0]['name'] === 'Maria Reyes', 'The request without documents is not reported once under the applicant name.');
    bulk_db_assert($outcome['incomplete'][0]['sms_sent'] === false, 'An incomplete request reported an approval SMS.');
    $status = $c->query("SELECT request_status FROM aics_requests WHERE id = {$missingDocuments}")->fetch_row()[0] ?? '';
    bulk_db_assert($status === 'incomplete_requirements', 'Approval still waits for every document to be checked by staff.');

    bulk_db_assert(count($outcome['failed']) === 1 && $outcome['failed'][0]['reason'] === 'This request is still being checked by AICS AI.', 'A request AICS AI has not released was approved or not reported.');
    $pendingStatus = $c->query("SELECT request_status FROM aics_requests WHERE id = {$stillChecking}")->fetch_row()[0] ?? '';
    bulk_db_assert($pendingStatus === 'pending', 'A request still being checked by AICS AI changed status.');

    $threw = false;
    try { pswdo_bulk_approve_requests_action($c, ['0', 'abc'], 1); } catch (RuntimeException) { $threw = true; }
    bulk_db_assert($threw, 'An empty selection was accepted.');

    // The approval SMS lists the applicant's ID apart from the beneficiary's papers.
    foreach (['valid_id', 'valid_id_back', 'med_cert'] as $type) {
        $c->query("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES ({$missingDocuments}, '{$type}', 'x.png', 'x.png', 'x', 'image/png', 10)");
    }
    $c->query("UPDATE aics_requests SET applicant_relationship = 'Daughter' WHERE id = {$missingDocuments}");
    $requirements = pswdo_uploaded_requirements($c, $missingDocuments);
    bulk_db_assert($requirements['applicant'] === ['Valid Identification Card'] && $requirements['beneficiary'] === ['Medical Abstract or Medical Certificate'], 'The uploaded documents are not split between applicant and beneficiary.');
    bulk_db_assert($requirements['beneficiary_name'] === 'Ana Cruz' && $requirements['same_person'] === false, 'A daughter applying for her parent is treated as the same person.');
    $c->query("UPDATE aics_requests SET applicant_relationship = 'Self' WHERE id = {$missingDocuments}");
    bulk_db_assert(pswdo_uploaded_requirements($c, $missingDocuments)['same_person'] === true, 'An applicant applying for themself is listed as two people.');

    // Screening Queue: only approved requests move to Release Scheduling (and
    // get the SMS); anything else is reported and left as it was.
    $screening = pswdo_bulk_finish_prescreening_action($c, [$stillChecking], 1);
    bulk_db_assert($screening['moved'] === 0 && $screening['sms_sent'] === 0 && count($screening['failed']) === 1, 'A request that is not approved was moved to Release Scheduling.');
    $unchanged = $c->query("SELECT request_status FROM aics_requests WHERE id = {$stillChecking}")->fetch_row()[0] ?? '';
    bulk_db_assert($unchanged === 'pending', 'A request that is not approved changed status.');
} finally {
    $c->close();
}

echo "PSWDO bulk approval DB checks passed.\n";
