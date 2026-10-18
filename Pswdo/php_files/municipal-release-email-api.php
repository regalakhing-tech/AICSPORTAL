<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-common.php';
require_once __DIR__ . '/pswdo-data-lib.php';
require_once __DIR__ . '/release-pdf-export.php';
require_once __DIR__ . '/../../includes/mailer.php';

pswdo_start_session();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();

// Read input from JSON or POST fields
$rawInput = file_get_contents('php://input');
$json = json_decode($rawInput, true);
$data = is_array($json) ? $json : $_POST;

$municipality = trim((string) ($data['municipality'] ?? 'Hamtic'));
if ($municipality === '') {
    $municipality = 'Hamtic';
}

$recipientEmail = trim((string) ($data['recipient_email'] ?? ''));
if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    // fallback to municipal canonical email
    $recipientEmail = pswdo_municipality_mswdo_email($municipality);
}

$subject = trim((string) ($data['subject'] ?? ''));
if ($subject === '') {
    $subject = 'AICS Beneficiary Release Registry - Municipality of ' . $municipality;
}

$notes = trim((string) ($data['notes'] ?? ''));
$status = trim((string) ($data['status'] ?? 'all'));
$rawIds = $data['request_ids'] ?? [];
$requestIds = [];
if (is_array($rawIds)) {
    $requestIds = array_values(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0));
} elseif (is_string($rawIds) && $rawIds !== '') {
    $requestIds = array_values(array_filter(array_map('intval', explode(',', $rawIds)), static fn($id) => $id > 0));
}

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);

    // Generate the PDF
    $pdfData = pswdo_generate_release_pdf_binary(
        $connection,
        $municipality,
        [
            'status' => $status,
            'request_ids' => $requestIds,
        ],
        $displayName
    );

    $recordCount = $pdfData['record_count'];
    $filename = $pdfData['filename'];
    $pdfBinary = $pdfData['binary'];
    $totalAmount = $pdfData['total_amount'];

    if ($recordCount === 0) {
        $connection->close();
        echo json_encode([
            'success' => false,
            'error' => 'No release records were found to email for ' . htmlspecialchars($municipality, ENT_QUOTES, 'UTF-8') . '.',
        ]);
        exit;
    }

    // Build the email body
    $bodyText = "Good day,\n\n"
        . "The Provincial Social Welfare and Development Office (PSWDO) of Antique is officially transmitting the AICS Beneficiary Release Masterlist for the Municipality of {$municipality}.\n\n"
        . "SUMMARY DETAILS:\n"
        . "- Target Municipality: {$municipality}\n"
        . "- Total Listed Beneficiaries: {$recordCount}\n"
        . "- Total Amount: PHP " . number_format($totalAmount, 2) . "\n"
        . "- Prepared By: {$displayName} (PSWDO Antique)\n"
        . "- Date Transmitted: " . date('F j, Y g:i A') . "\n\n";

    if ($notes !== '') {
        $bodyText .= "SPECIAL REMARKS / INSTRUCTIONS FROM PSWDO:\n" . $notes . "\n\n";
    }

    $bodyText .= "REQUIRED MUNICIPAL ACTIONS:\n"
        . "• Disseminate the beneficiary release registry and assigned release schedule to concerned beneficiaries.\n"
        . "• Inform beneficiaries that assistance will be released at the PSWDO on the scheduled date.\n"
        . "• Remind beneficiaries to bring a valid government-issued ID when claiming assistance.\n"
        . "• Direct questions or concerns regarding assistance release to the PSWDO.\n"
        . "• Maintain a copy of the transmitted beneficiary registry for municipal reference and records.\n\n"
        . "Please find the complete official masterlist attached in PDF format (" . $filename . ").\n\n"
        . "Thank you,\n"
        . "Provincial Social Welfare and Development Office\n"
        . "Province of Antique\n";

    // Build responsive HTML email and PlainText fallback
    $bodyHtml = aics_format_release_registry_email_html(
        $municipality,
        $recordCount,
        $totalAmount,
        $displayName,
        $notes,
        $filename
    );

    // Dispatch via PHPMailer with PDF Attachment
    $mailResult = aics_send_email_with_attachment(
        $recipientEmail,
        'MSWDO - ' . $municipality,
        $subject,
        $bodyHtml,
        $bodyText,
        [
            [
                'binary' => $pdfBinary,
                'filename' => $filename,
                'type' => 'application/pdf',
            ],
        ]
    );

    $mailSent = (bool) ($mailResult['success'] ?? false);
    $deliveryStatus = (string) ($mailResult['mode'] ?? 'unknown');
    $deliveryMessage = (string) ($mailResult['message'] ?? 'Release registry processed.');
    $deliveryMode = aics_is_smtp_configured() ? 'live' : 'simulate';

    // Audit Logging into aics_request_notifications
    $targetRequestId = $requestIds[0] ?? 0;
    if ($targetRequestId <= 0) {
        // query first matching request id
        $q = $connection->prepare("SELECT id FROM aics_requests WHERE LOWER(TRIM(municipality)) = LOWER(TRIM(?)) LIMIT 1");
        $q->bind_param('s', $municipality);
        $q->execute();
        $qRow = $q->get_result()?->fetch_assoc();
        $q->close();
        $targetRequestId = (int) ($qRow['id'] ?? 0);
    }

    $notificationStage = 'MSWDO Masterlist PDF Email';
    $channel = 'mswdo_email_pdf';
    $batchKey = 'pdf_transmittal:' . substr(hash('sha256', $municipality . '|' . $recipientEmail . '|' . time()), 0, 32);
    $dedupeKey = hash('sha256', $batchKey . '|' . $recordCount . '|' . time());
    $messageSummary = "Official release registry PDF ({$recordCount} records) transmitted via PHPMailer ({$deliveryStatus}) to {$recipientEmail} for {$municipality}.";
    $fullTemplate = "Subject: {$subject}\nRecipient: {$recipientEmail}\nAttachment: {$filename} (" . strlen($pdfBinary) . " bytes)\nDelivery Status: {$deliveryStatus}\n\n" . $bodyText;

    if ($targetRequestId > 0) {
        $logStmt = $connection->prepare(
            'INSERT INTO aics_request_notifications (request_id, notification_stage, channel, delivery_mode, delivery_status, municipality, batch_key, message_text, notification_template, dedupe_key, sent_by_account_id, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        if ($logStmt) {
            $logStmt->bind_param(
                'isssssssssi',
                $targetRequestId,
                $notificationStage,
                $channel,
                $deliveryMode,
                $deliveryStatus,
                $municipality,
                $batchKey,
                $messageSummary,
                $fullTemplate,
                $dedupeKey,
                $accountId
            );
            $logStmt->execute();
            $logStmt->close();
        }
    }

    $connection->close();

    echo json_encode([
        'success' => $mailSent,
        'message' => $deliveryMessage,
        'recipient' => $recipientEmail,
        'municipality' => $municipality,
        'filename' => $filename,
        'record_count' => $recordCount,
        'total_amount' => $totalAmount,
        'delivery_status' => $deliveryStatus,
        'phpmailer' => true,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while processing the email transmittal: ' . $e->getMessage(),
    ]);
}
