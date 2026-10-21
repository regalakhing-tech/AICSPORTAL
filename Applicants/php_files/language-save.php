<?php
declare(strict_types=1);

// Saves the applicant's page language (en, tl, hil) to their account.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth-common.php';

aics_start_session();
if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST.']);
    exit;
}

$language = aics_normalize_applicant_language((string) ($_POST['language'] ?? ''));
if ($language === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Choose English, Tagalog or Hiligaynon.']);
    exit;
}

$connection = null;
try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    if (!aics_save_applicant_language($connection, (int) ($_SESSION['aics_account_id'] ?? 0), $language)) {
        throw new RuntimeException('Language not saved.');
    }
    $_SESSION['aics_language'] = $language;
    echo json_encode(['ok' => true, 'language' => $language]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save the language right now.']);
} finally {
    if ($connection instanceof mysqli) $connection->close();
}
