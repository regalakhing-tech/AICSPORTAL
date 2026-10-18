<?php
declare(strict_types=1);

putenv('AICS_HTTP_TEST_MODE=1');
putenv('AICS_TEST_DB_HOST=127.0.0.1');
putenv('AICS_TEST_DB_USER=root');
putenv('AICS_TEST_DB_PASSWORD=');
putenv('AICS_TEST_DB_NAME=aics_http_regression');
putenv('AICS_TEST_DB_PORT=3306');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/http-regression-db.php';
require_once __DIR__ . '/../chatbot/applicant-context.php';

function applicant_context_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label . ' failed.');
}

function applicant_context_insert_request(mysqli $db, int $accountId, string $reference, string $date, string $status): int
{
    $sql = "INSERT INTO aics_requests
        (reference_no,date_applied,beneficiary_first_name,beneficiary_last_name,beneficiary_age,beneficiary_complete_name,contact_number,
         applicant_first_name,applicant_last_name,applicant_age,barangay,municipality,province,gender,assistance_types_json,claim_status,request_status)
        VALUES (?,?,'Private','Person',30,'Private Person','09999999999','Private','Person',30,'Secret Barangay','Hamtic','Antique','female','[\"medical\"]','unclaimed',?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('sss', $reference, $date, $status);
    $stmt->execute();
    $requestId = (int) $db->insert_id;
    $stmt->close();
    $link = $db->prepare('INSERT INTO aics_account_requests (account_id, request_id) VALUES (?, ?)');
    $link->bind_param('ii', $accountId, $requestId);
    $link->execute();
    $link->close();
    return $requestId;
}

aics_http_regression_create_database();
try {
    $db = aics_db_connect();
    aics_http_regression_require_seed_data($db);
    $roleId = (int) ($db->query("SELECT id FROM aics_roles WHERE role_code='applicat'")->fetch_assoc()['id'] ?? 0);
    $statusId = (int) ($db->query("SELECT id FROM aics_account_statuses WHERE status_code='active'")->fetch_assoc()['id'] ?? 0);
    $accountIds = [];
    foreach (['context-a@example.test', 'context-b@example.test'] as $email) {
        $stmt = $db->prepare("INSERT INTO aics_accounts (role_id,account_status_id,email,password_hash) VALUES (?,?,?,'test-hash')");
        $stmt->bind_param('iis', $roleId, $statusId, $email);
        $stmt->execute();
        $accountIds[] = (int) $db->insert_id;
        $stmt->close();
    }

    $olderId = applicant_context_insert_request($db, $accountIds[0], 'AICS-2026-AAA001', '2026-01-10', 'pending_review');
    $newerId = applicant_context_insert_request($db, $accountIds[0], 'AICS-2026-AAA002', '2026-09-18', 'approved');
    applicant_context_insert_request($db, $accountIds[1], 'AICS-2026-BBB001', '2026-09-19', 'pending_review');

    $doc = $db->prepare("INSERT INTO aics_request_documents (request_id,document_type,original_name,stored_name,file_path,mime_type,file_size) VALUES (?,'valid_id','raw-secret-id.jpg','stored-secret.jpg','C:/private/raw-secret-id.jpg','image/jpeg',123)");
    $doc->bind_param('i', $newerId);
    $doc->execute();
    $documentId = (int) $db->insert_id;
    $doc->close();
    $review = $db->prepare("INSERT INTO aics_request_document_reviews (request_document_id,is_checked,review_status,remarks) VALUES (?,1,'checked','<b>Verified</b> for staff review')");
    $review->bind_param('i', $documentId);
    $review->execute();
    $review->close();
    $schedule = $db->prepare("INSERT INTO aics_request_release_schedule (request_id,scheduled_date,scheduled_time,venue,release_batch,amount_given,release_status) VALUES (?,'2026-09-30','09:00:00','PSWDO Office','Batch 2',5000,'scheduled')");
    $schedule->bind_param('i', $newerId);
    $schedule->execute();
    $schedule->close();
    $notice = $db->prepare("INSERT INTO aics_request_notifications (request_id,notification_stage,message_text) VALUES (?,'Schedule Update','Private message body must not be retrieved')");
    $notice->bind_param('i', $newerId);
    $notice->execute();
    $notice->close();

    applicant_context_assert(aics_chatbot_extract_reference('check aics-2026-aaa001 please') === 'AICS-2026-AAA001', 'Canonical reference extraction');
    applicant_context_assert(aics_chatbot_sanitize_remark(" <b>Needs   review</b>\n") === 'Needs review', 'Remark sanitization');

    $latest = aics_chatbot_fetch_applicant_context($db, $accountIds[0], '');
    applicant_context_assert(count($latest['applications']) === 2, 'All owned applications retrieved');
    applicant_context_assert(!str_contains(json_encode($latest['applications']), 'BBB001'), 'Other account excluded from index');
    applicant_context_assert(($latest['selected_application']['reference'] ?? '') === 'AICS-2026-AAA002', 'Newest owned request selected');
    applicant_context_assert(($latest['selected_application']['release']['venue'] ?? '') === 'PSWDO Office', 'Release venue included');
    applicant_context_assert(($latest['selected_application']['documents'][0]['label'] ?? '') === 'Valid Identification Card', 'Safe document label included');
    applicant_context_assert(($latest['selected_application']['documents'][0]['review_status'] ?? '') === 'checked', 'Review status included');
    applicant_context_assert(($latest['cooldown']['years'] ?? 0) === 1, 'One-year cooldown included');

    $older = aics_chatbot_fetch_applicant_context($db, $accountIds[0], 'AICS-2026-AAA001');
    applicant_context_assert(($older['selected_application']['reference'] ?? '') === 'AICS-2026-AAA001', 'Owned older reference selected');
    $foreign = aics_chatbot_fetch_applicant_context($db, $accountIds[0], 'AICS-2026-BBB001');
    applicant_context_assert(($foreign['reference_found'] ?? true) === false, 'Foreign reference hidden');
    applicant_context_assert(($foreign['selected_application'] ?? null) === null, 'Foreign request absent');

    $serialized = json_encode($latest, JSON_THROW_ON_ERROR);
    foreach (['raw-secret-id.jpg', 'stored-secret.jpg', 'C:/private', 'Private Person', '09999999999', 'Secret Barangay', '5000', 'Private message body'] as $forbidden) {
        applicant_context_assert(!str_contains($serialized, $forbidden), 'Private value excluded: ' . $forbidden);
    }
    applicant_context_assert(str_contains($serialized, 'Verified for staff review'), 'Sanitized staff remark included');
    $db->close();
    echo "Applicant chatbot context tests passed.\n";
} finally {
    aics_http_regression_drop_database();
}
