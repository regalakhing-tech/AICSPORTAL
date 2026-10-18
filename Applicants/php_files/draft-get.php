<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/application-access.php';
aics_start_session();
if (!aics_is_logged_in_applicat()) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit; }
try {
    $connection = aics_db_connect(); aics_ensure_auth_schema($connection); aics_ensure_draft_schema($connection);
    $state = aics_applicant_application_access_state($connection, (int) $_SESSION['aics_account_id']);
    if (!$state['profile_complete']) { $connection->close(); http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Complete your profile before submitting an application.']); exit; }
    $draft = $state['draft']; $canRecoverDraft = (bool) $state['can_recover_draft']; $mode = (string) $state['mode'];
    $activeRequest = is_array($state['active_request'] ?? null); $cooldown = (bool) (($state['cooldown']['is_in_cooldown'] ?? false));
    $connection->close();
    echo json_encode(['ok'=>true,'can_recover_draft'=>$canRecoverDraft,'application_mode'=>$mode,'has_draft'=>is_array($draft),'draft'=>$draft,'active_request'=>$activeRequest,'cooldown_active'=>$cooldown], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Unable to load draft.']); }
