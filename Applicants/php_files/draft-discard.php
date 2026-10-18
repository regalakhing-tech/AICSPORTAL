<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/draft-lib.php';
aics_start_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !aics_is_logged_in_applicat()) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit; }
$input = json_decode((string) file_get_contents('php://input'), true);
try {
    $connection = aics_db_connect(); aics_ensure_auth_schema($connection); aics_ensure_draft_schema($connection);
    aics_discard_draft($connection, (int) $_SESSION['aics_account_id'], (string) ($input['draft_id'] ?? '')); $connection->close();
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Unable to discard draft.']); }
