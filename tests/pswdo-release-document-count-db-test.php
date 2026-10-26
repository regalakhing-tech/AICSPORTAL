<?php
declare(strict_types=1);
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

function release_count_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
release_count_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$db = aics_db_connect();
try {
    pswdo_ensure_schema($db);
    $cases = [
        'medical-four' => [['medical'], ['valid_id', 'valid_id_back', 'med_cert', 'billing_statement'], '4 / 4 files', 4, 0, true],
        'medical-three' => [['medical'], ['valid_id', 'med_cert', 'billing_statement'], '3 / 3 files', 3, 0, true],
        'missing-bill' => [['medical'], ['valid_id', 'valid_id_back', 'med_cert'], '3 / 4 files', 4, 1, false],
        'no-files' => [['medical'], [], '0 / 3 files', 3, 3, false],
        'historical-extra' => [['medical'], ['valid_id', 'valid_id_back', 'med_cert', 'billing_statement', 'old_supporting_paper'], '5 / 5 files', 5, 0, true],
        'historical-alias' => [['medical'], ['valid_identification_card', 'valid_id_back', 'medical_certificate', 'billing_statement_or_prescription'], '4 / 4 files', 4, 0, true],
        'special-case' => [['special_cases'], ['valid_id', 'valid_id_back', 'report_police_med'], '3 / 3 files', 3, 0, true],
        'shared-id' => [['medical', 'burial'], ['valid_id', 'valid_id_back', 'med_cert', 'billing_statement', 'death_cert', 'funeral_contract'], '6 / 6 files', 6, 0, true],
    ];
    $expected = [];
    foreach ($cases as $name => [$assistance, $documents, $label, $total, $missing, $complete]) {
        $reference = 'DOC-' . $name;
        $db->query("INSERT INTO aics_requests (reference_no, date_applied, beneficiary_first_name, beneficiary_last_name, beneficiary_age, beneficiary_complete_name, contact_number, applicant_first_name, applicant_last_name, applicant_age, barangay, municipality, gender, assistance_types_json, claim_status, request_status) VALUES ('{$reference}', CURDATE(), 'Ana', 'Cruz', 40, 'Ana Cruz', '09170000000', 'Ana', 'Cruz', 40, 'X', 'Sibalom', 'female', '[\"medical\"]', 'unclaimed', 'for_release')");
        $id = (int) $db->insert_id;
        foreach ($assistance as $code) {
            $stmt = $db->prepare('INSERT INTO aics_request_assistance (request_id, assistance_type_id) SELECT ?, id FROM aics_assistance_types WHERE assistance_code=?');
            $stmt->bind_param('is', $id, $code); $stmt->execute(); $stmt->close();
        }
        foreach ($documents as $type) {
            $stmt = $db->prepare("INSERT INTO aics_request_documents (request_id, document_type, original_name, stored_name, file_path, mime_type, file_size) VALUES (?, ?, 'test.png', 'test.png', 'test-only', 'image/png', 10)");
            $stmt->bind_param('is', $id, $type); $stmt->execute(); $stmt->close();
        }
        $expected[$id] = [$label, $total, $missing, $complete, count($documents)];
    }
    $queue = pswdo_fetch_release_schedule_queue($db, 'all', 100);
    release_count_assert(count($queue) === count($cases), 'Every release fixture must be returned.');
    $byId = array_column($queue, null, 'request_id');
    foreach ($expected as $id => [$label, $total, $missing, $complete, $uploaded]) {
        $item = $byId[$id];
        release_count_assert($item['requirements_label'] === $label, $item['reference_no'] . ': expected ' . $label . ', got ' . $item['requirements_label']);
        release_count_assert(($item['expected_file_total'] ?? null) === $total, 'Expected file total does not include extra uploaded files and missing requirements.');
        release_count_assert(($item['missing_total'] ?? null) === $missing, 'Missing required documents must remain visible.');
        release_count_assert(($item['requirements_complete'] ?? null) === $complete, 'The completeness state does not match the required documents.');
        release_count_assert($item['uploaded_total'] === $uploaded, 'Actual uploaded file counts must be preserved.');
    }
    echo "PSWDO release file-count database checks passed (8 cases).\n";
} finally {
    $db->close();
    aics_http_regression_drop_database();
}
