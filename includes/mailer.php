<?php
declare(strict_types=1);

/**
 * AICS Automated Portal - Central PHPMailer Dispatcher
 */

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Instantiates and configures a new PHPMailer instance based on application configuration.
 */
function aics_create_phpmailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    if (aics_is_smtp_configured()) {
        $mail->isSMTP();
        $mail->Host = AICS_SMTP_HOST;
        $mail->Port = AICS_SMTP_PORT;
        $mail->SMTPAuth = AICS_SMTP_AUTH;
        $mail->Username = AICS_SMTP_USER;
        $mail->Password = AICS_SMTP_PASS;

        if (AICS_SMTP_SECURE === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (AICS_SMTP_SECURE === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        // Local development SSL certificate flexibility
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->Timeout = 15;
    }

    $mail->setFrom(AICS_MAIL_FROM_EMAIL, AICS_MAIL_FROM_NAME);
    $mail->addReplyTo(AICS_MAIL_REPLY_TO, AICS_MAIL_FROM_NAME);

    return $mail;
}

/**
 * Sends an email with optional attachments using PHPMailer.
 *
 * @param array<int, array{binary?: string, path?: string, filename: string, type?: string}> $attachments
 * @return array{success: bool, mode: string, message: string, error: ?string, archive_path: ?string}
 */
function aics_send_email_with_attachment(
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyHtml,
    string $bodyText,
    array $attachments = []
): array {
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'mode' => 'invalid_email',
            'message' => 'The provided recipient email address is invalid.',
            'error' => 'Invalid email address: ' . $toEmail,
            'archive_path' => null,
        ];
    }

    $isLiveSmtp = aics_is_smtp_configured();

    try {
        $mail = aics_create_phpmailer();
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->Subject = $subject;

        if ($bodyHtml !== '') {
            $mail->isHTML(true);
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyText;
        } else {
            $mail->isHTML(false);
            $mail->Body = $bodyText;
        }

        // Attach files or binary strings
        foreach ($attachments as $att) {
            $filename = $att['filename'] ?? 'attachment.pdf';
            $mime = $att['type'] ?? 'application/pdf';

            if (isset($att['binary']) && is_string($att['binary'])) {
                $mail->addStringAttachment($att['binary'], $filename, 'base64', $mime);
            } elseif (isset($att['path']) && is_file($att['path'])) {
                $mail->addAttachment($att['path'], $filename, 'base64', $mime);
            }
        }

        if ($isLiveSmtp) {
            $mail->send();
            return [
                'success' => true,
                'mode' => 'smtp',
                'message' => "Email with attachment sent successfully to {$toEmail} via SMTP.",
                'error' => null,
                'archive_path' => null,
            ];
        }

        // Simulated / Local Development Mode:
        // Validate MIME construction and archive message
        $archiveDir = __DIR__ . '/../storage/emails/' . date('Y-m');
        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0777, true);
        }

        $archivePrefix = date('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $toEmail);
        $archivePath = $archiveDir . '/' . $archivePrefix . '.eml';

        // Pre-render the complete MIME email via PHPMailer
        $mail->preSend();
        $emlContent = $mail->getSentMIMEMessage();
        if ($emlContent !== '') {
            @file_put_contents($archivePath, $emlContent);
        }

        return [
            'success' => true,
            'mode' => 'simulated',
            'message' => "Email processed via PHPMailer and archived for {$toEmail}. (To deliver live external emails to inbox, configure SMTP App Password in config/mail.php).",
            'error' => null,
            'archive_path' => $archivePath,
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'mode' => $isLiveSmtp ? 'smtp_error' : 'phpmailer_error',
            'message' => 'PHPMailer error: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'archive_path' => null,
        ];
    } catch (Throwable $t) {
        return [
            'success' => false,
            'mode' => 'runtime_error',
            'message' => 'Mail delivery exception: ' . $t->getMessage(),
            'error' => $t->getMessage(),
            'archive_path' => null,
        ];
    }
}
