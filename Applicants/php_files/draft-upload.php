<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/application-access.php';
aics_start_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !aics_is_logged_in_applicat()) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit; }
try { $connection=aics_db_connect(); aics_ensure_auth_schema($connection); aics_ensure_draft_schema($connection); $state=aics_applicant_application_access_state($connection, (int) $_SESSION['aics_account_id']); if (!$state['profile_complete']) throw new RuntimeException('Complete your profile before submitting an application.'); if (!$state['application_allowed']) throw new RuntimeException('You cannot update a draft while an active request or application cooldown applies.'); $doc=aics_store_draft_document($connection,(int)$_SESSION['aics_account_id'],(string)($_POST['draft_id']??''),(string)($_POST['document_type']??''),(array)($_FILES['document']??[])); $connection->close(); echo json_encode(['ok'=>true,'document'=>$doc]); }
catch (Throwable $e) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); }
