<?php
declare(strict_types=1);

/**
 * Sign-up step 1: store the front and back ID photos privately and let AICS AI
 * read them. The result is kept in the session for this sign-up attempt and
 * enforced again by auth-signup.php when the account is created.
 */

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/applicant-id-verification.php';

const MAX_ID_PHOTO_BYTES = 10 * 1024 * 1024;
const ID_PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

/**
 * Compare the details typed in step 1 with the ID read for this attempt.
 *
 * @param array<string,mixed> $result
 * @return array{name_match:array{match:bool,reason:string},birthdate_match:bool}
 */
function aics_id_scan_match(array $result): array
{
    if (($result['decision'] ?? '') !== 'ok') {
        return ['name_match' => ['match' => true, 'reason' => ''], 'birthdate_match' => true];
    }
    $first = trim((string) ($_POST['first_name'] ?? ''));
    $middle = trim((string) ($_POST['middle_name'] ?? ''));
    $last = trim((string) ($_POST['last_name'] ?? ''));
    $birth = trim((string) ($_POST['date_of_birth'] ?? ''));
    return [
        'name_match' => aics_id_names_match($result, $first, $middle, $last),
        'birthdate_match' => aics_id_birthdates_match(is_string($result['birth_date'] ?? null) ? $result['birth_date'] : null, $birth),
    ];
}

/** @param array<string,mixed> $stored */
function aics_id_scan_payload(array $stored): array
{
    $decision = (string) ($stored['decision'] ?? 'unavailable');
    return array_merge([
        'ok' => true,
        'decision' => $decision,
        // Automatic: only an ID that AICS AI read successfully can be used.
        'can_continue' => aics_id_result_is_verified($stored),
        'message' => (string) ($stored['message'] ?? ''),
        'document_detected' => ($stored['document_detected'] ?? null) === true,
        'is_identification_card' => ($stored['is_identification_card'] ?? null) === true,
        'is_supported_id' => ($stored['is_supported_id'] ?? null) === true,
        'id_type' => $stored['id_type'] ?? null,
        'id_type_label' => (string) ($stored['id_type_label'] ?? ''),
        'issuer' => $stored['issuer'] ?? null,
        'confidence' => $stored['confidence'] ?? 'low',
        'rejection_reason' => $stored['rejection_reason'] ?? null,
        'expiration_date' => $stored['expiration_date'] ?? null,
        'last_name' => $stored['last_name'] ?? null,
        'given_names' => $stored['given_names'] ?? null,
        'middle_name' => $stored['middle_name'] ?? null,
        'birth_date' => $stored['birth_date'] ?? null,
        'expired' => (bool) ($stored['expired'] ?? false),
    ], aics_id_scan_match($stored));
}

function aics_id_scan_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aics_id_scan_reply(405, ['ok' => false, 'message' => 'Invalid request.']);
}
$attemptId = aics_normalize_signup_attempt_id((string) ($_POST['signup_attempt_id'] ?? ''));
$folder = $attemptId !== null ? aics_id_signup_dir($attemptId) : null;
if ($attemptId === null || $folder === null) {
    aics_id_scan_reply(400, ['ok' => false, 'message' => 'Please refresh the page and start sign-up again.']);
}

aics_start_session();

// Re-compare edited details with the ID already read; no new photos or AI call.
if (($_POST['action'] ?? '') === 'check') {
    $existing = $_SESSION['aics_signup_id'][$attemptId]['result'] ?? null;
    if (!is_array($existing)) {
        aics_id_scan_reply(404, ['ok' => false, 'message' => 'Please scan your ID first.']);
    }
    aics_id_scan_reply(200, aics_id_scan_payload($existing));
}

if (aics_id_rate_limited($_SESSION, $attemptId)) {
    aics_id_scan_reply(429, ['ok' => false, 'message' => 'Too many ID checks. Please wait a while, then try again.']);
}
aics_id_cleanup_abandoned_scans();
if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
    error_log('AICS sign-up ID storage is unavailable.');
    aics_id_scan_reply(500, ['ok' => false, 'message' => 'We could not save your ID photos. Please try again.']);
}

$uploads = [];
$extensions = [];
$finfo = new finfo(FILEINFO_MIME_TYPE);
foreach (['front' => 'id_front', 'back' => 'id_back'] as $side => $field) {
    $upload = $_FILES[$field] ?? null;
    $label = $side === 'front' ? 'front' : 'back';
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
        aics_id_scan_reply(422, ['ok' => false, 'message' => "Please add a photo of the $label of your ID."]);
    }
    if ((int) $upload['size'] > MAX_ID_PHOTO_BYTES) {
        aics_id_scan_reply(422, ['ok' => false, 'message' => "The $label photo is larger than 10 MB. Please use a smaller photo."]);
    }
    $mime = (string) $finfo->file((string) $upload['tmp_name']);
    $size = @getimagesize((string) $upload['tmp_name']);
    if (!isset(ID_PHOTO_TYPES[$mime]) || $size === false || $size[0] < 300 || $size[1] < 200) {
        aics_id_scan_reply(422, ['ok' => false, 'message' => "The $label photo must be a clear JPG, PNG, or WebP picture of the card (at least 300 x 200 pixels)."]);
    }
    $uploads[$side] = (string) $upload['tmp_name'];
    $extensions[$side] = ID_PHOTO_TYPES[$mime];
}
$files = aics_id_new_scan_paths($folder, $extensions);
foreach ($files as $side => $target) {
    if (!move_uploaded_file($uploads[$side], $target)) {
        aics_id_scan_reply(500, ['ok' => false, 'message' => 'We could not save your ID photos. Please try again.']);
    }
}
if (hash_file('sha256', $files['front']) === hash_file('sha256', $files['back'])) {
    aics_id_scan_reply(422, ['ok' => false, 'decision' => 'retake', 'message' => 'You uploaded the same photo twice. Please add the back of your ID as the second photo.']);
}

// Release the session lock while AICS AI reads the ID (several seconds), so a
// second request in the same browser is not blocked; reopen it to save the result.
session_write_close();
$result = aics_id_run_check($files['front'], $files['back']);
aics_start_session();
$keep = ['status', 'decision', 'message', 'rejection_reason', 'id_type', 'id_type_label', 'document_detected', 'is_identification_card',
    'is_supported_id', 'issuer', 'confidence', 'last_name', 'given_names', 'middle_name', 'full_name',
    'birth_date', 'expiration_date', 'expired', 'flags', 'model'];
$stored = array_intersect_key($result, array_flip($keep));
$previous = is_array($_SESSION['aics_signup_id'][$attemptId] ?? null) ? $_SESSION['aics_signup_id'][$attemptId] : [];
$_SESSION['aics_signup_id'][$attemptId] = [
    'result' => $stored,
    'files' => $files,
    'scan_count' => (int) ($previous['scan_count'] ?? 0) + 1,
    'scanned_at' => time(),
];

aics_id_scan_reply(200, aics_id_scan_payload($stored));
