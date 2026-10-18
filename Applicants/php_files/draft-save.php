<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/application-access.php';
aics_start_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !aics_is_logged_in_applicat()) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit; }
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Invalid draft data.']); exit; }
try {
    $connection = aics_db_connect(); aics_ensure_auth_schema($connection); aics_ensure_draft_schema($connection);
    $state = aics_applicant_application_access_state($connection, (int) $_SESSION['aics_account_id']);
    if (!$state['profile_complete']) throw new RuntimeException('Complete your profile before submitting an application.');
    if (!$state['application_allowed']) throw new RuntimeException('You cannot save a draft while an active request or application cooldown applies.');
    $draft = aics_save_draft($connection, (int) $_SESSION['aics_account_id'], isset($input['draft_id']) ? (string) $input['draft_id'] : null, (int) ($input['version'] ?? 0), (int) ($input['current_step'] ?? 0), (array) ($input['data'] ?? []));
    $connection->close(); echo json_encode(['ok'=>true,'draft'=>$draft], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) { http_response_code(409); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); }
catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Unable to save draft.']); }
