<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';

header('Content-Type: application/json; charset=utf-8');

// Token comes from config/database.php (environment variable or config/secrets.local.php).
const PHILSMS_API_TOKEN = AICS_PHILSMS_API_TOKEN;
const PHILSMS_API_URL   = 'https://dashboard.philsms.com/api/v3/sms/send';
const PHILSMS_SENDER_ID = 'PhilSMS';
const OTP_COOLDOWN_SECS = 300; // 5 minutes cooldown

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only POST requests are allowed.'
    ]);
    exit;
}

aics_start_session();

// Parse phone number from POST form data or JSON body
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);
$contactNumber = $_POST['contact_number'] ?? $jsonData['contact_number'] ?? $_GET['contact_number'] ?? '';
$contactNumber = trim((string) $contactNumber);
$attemptId = aics_normalize_signup_attempt_id(
    (string) ($_POST['signup_attempt_id'] ?? $jsonData['signup_attempt_id'] ?? $_GET['signup_attempt_id'] ?? '')
);

if ($attemptId === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'invalid_signup_attempt',
        'message' => 'This signup attempt is invalid. Please reload the registration page.'
    ]);
    exit;
}

$normalizedPhone = aics_normalize_mobile_number($contactNumber);
if ($normalizedPhone === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'invalid_contact',
        'message' => 'Please provide a valid Philippine mobile number (e.g. 09XXXXXXXXX).'
    ]);
    exit;
}

$now = time();
aics_signup_otp_migrate_legacy_attempt($_SESSION);

// Enforce the 5-minute cooldown only for this signup phone number.
$remaining = aics_signup_otp_cooldown_remaining($_SESSION, $attemptId, $normalizedPhone, $now, OTP_COOLDOWN_SECS);
if ($remaining > 0) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'cooldown_active',
        'message' => "Please wait before requesting a new OTP. Cooldown active ({$remaining}s remaining).",
        'remaining_seconds' => $remaining,
        'cooldown_seconds' => OTP_COOLDOWN_SECS
    ]);
    exit;
}

// Generate secure 6-digit numeric OTP
try {
    $otpCode = sprintf('%06d', random_int(100000, 999999));
} catch (Throwable) {
    $otpCode = sprintf('%06d', mt_rand(100000, 999999));
}

// Format phone number for PhilSMS (expects 639xxxxxxxxx)
$philsmsRecipient = $normalizedPhone;
if (str_starts_with($philsmsRecipient, '09')) {
    $philsmsRecipient = '63' . substr($philsmsRecipient, 1);
} elseif (str_starts_with($philsmsRecipient, '+63')) {
    $philsmsRecipient = substr($philsmsRecipient, 1);
}

$smsMessage = "AICS Portal: Your verification code is {$otpCode}. It is valid for 5 minutes. Please do not share this code with anyone.";

// Send via PhilSMS API v3
$smsSent = false;
$philsmsResponse = null;
$philsmsError = null;

$postPayload = json_encode([
    'recipient' => $philsmsRecipient,
    'sender_id' => PHILSMS_SENDER_ID,
    'type' => 'plain',
    'message' => $smsMessage,
]);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => PHILSMS_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $postPayload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . PHILSMS_API_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => false, // local development compatibility
]);

$rawResponse = curl_exec($curl);
$curlError = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($rawResponse !== false) {
    $philsmsResponse = json_decode($rawResponse, true);
    if (isset($philsmsResponse['status']) && $philsmsResponse['status'] === 'success') {
        $smsSent = true;
    } else {
        $philsmsError = $philsmsResponse['message'] ?? 'PhilSMS returned an unsuccessful response.';
    }
} else {
    $philsmsError = 'cURL error: ' . $curlError;
}

// Store an independent OTP attempt for this phone number.
aics_signup_otp_store_attempt($_SESSION, $attemptId, $normalizedPhone, $otpCode, $now, OTP_COOLDOWN_SECS);

// Return structured response (OTP code is sent to user's phone via PhilSMS, not exposed on the website)
echo json_encode([
    'success' => true,
    'sms_delivered' => $smsSent,
    'message' => $smsSent 
        ? "Verification code sent to {$normalizedPhone}. Please check your phone SMS." 
        : "Failed to send SMS: " . ($philsmsError ?? 'Service error'),
    'phone' => $normalizedPhone,
    'philsms_uid' => $philsmsResponse['data']['uid'] ?? null,
    'cooldown_seconds' => OTP_COOLDOWN_SECS,
    'expires_in' => OTP_COOLDOWN_SECS
]);
