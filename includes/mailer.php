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
                'message' => "Official masterlist PDF transmitted successfully to {$toEmail} via SMTP.",
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
            'message' => "Masterlist email processed via PHPMailer and archived for {$toEmail}. (To deliver live external emails to inbox, configure SMTP App Password in config/mail.php).",
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

/**
 * Builds an official Provincial Government responsive HTML email layout for release masterlists.
 */
function aics_format_release_registry_email_html(
    string $municipality,
    int $recordCount,
    float $totalAmount,
    string $preparedBy,
    string $notes = '',
    string $filename = ''
): string {
    $cleanMuni = htmlspecialchars($municipality, ENT_QUOTES, 'UTF-8');
    $cleanStaff = htmlspecialchars($preparedBy !== '' ? $preparedBy : 'PSWDO Staff', ENT_QUOTES, 'UTF-8');
    $cleanNotes = nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'));
    $cleanFile = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
    $formattedAmount = number_format($totalAmount, 2);
    $dateStr = date('F j, Y g:i A');
    $yearStr = date('Y');

    $notesBlock = '';
    if (trim($notes) !== '') {
        $notesBlock = <<<HTML
        <!-- Remarks Callout Box -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 22px 0; background-color: #f0fdf4; border-left: 4px solid #059669; border-radius: 6px; border-top: 1px solid #dcfce7; border-right: 1px solid #dcfce7; border-bottom: 1px solid #dcfce7;">
          <tr>
            <td style="padding: 16px 20px;">
              <strong style="color: #065f46; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px;">
                &#9873; Special Remarks &amp; Instructions from PSWDO:
              </strong>
              <div style="color: #1e293b; font-size: 14px; line-height: 1.6;">{$cleanNotes}</div>
            </td>
          </tr>
        </table>
HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AICS Beneficiary Release Registry - Municipality of {$cleanMuni}</title>
</head>
<body style="margin: 0; padding: 24px 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; color: #1e293b; -webkit-font-smoothing: antialiased;">
  
  <!-- Hidden Inbox Preview Text -->
  <div style="display: none; max-height: 0px; overflow: hidden; font-size: 1px; line-height: 1px; color: #fff; opacity: 0;">
    Official AICS Beneficiary Release Registry for the Municipality of {$cleanMuni}. Total {$recordCount} beneficiaries listed (PHP {$formattedAmount}).
  </div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 660px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px rgba(0,0,0,0.06);">
    
    <!-- Top Provincial Decorative Gold Bar -->
    <tr>
      <td style="height: 5px; background: linear-gradient(90deg, #0284c7 0%, #059669 50%, #f59e0b 100%); line-height: 5px; font-size: 1px;">&nbsp;</td>
    </tr>

    <!-- Provincial Header Banner -->
    <tr>
      <td style="background: #0f172a; padding: 28px 32px 24px; text-align: center;">
        <p style="margin: 0 0 6px; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px;">
          Republic of the Philippines &bull; Province of Antique
        </p>
        <h1 style="margin: 0 0 6px; font-size: 20px; font-weight: 800; color: #ffffff; letter-spacing: 0.5px; line-height: 1.3;">
          PROVINCIAL SOCIAL WELFARE AND DEVELOPMENT OFFICE
        </h1>
        <p style="margin: 0; font-size: 13px; color: #38bdf8; font-weight: 600; letter-spacing: 0.3px;">
          Assistance to Individuals in Crisis Situations (AICS) Management System
        </p>
      </td>
    </tr>

    <!-- Target Municipality Highlight Stripe -->
    <tr>
      <td style="background: #0284c7; padding: 10px 32px; text-align: center;">
        <span style="font-size: 13px; font-weight: 800; color: #ffffff; text-transform: uppercase; letter-spacing: 1px;">
          Official Transmittal &bull; Municipality of {$cleanMuni}
        </span>
      </td>
    </tr>

    <!-- Main Message Body -->
    <tr>
      <td style="padding: 32px;">
        
        <p style="margin: 0 0 14px; font-size: 16px; font-weight: 600; color: #0f172a;">
          Good day MSWDO Officers &amp; Staff,
        </p>
        
        <p style="margin: 0 0 22px; font-size: 14.5px; line-height: 1.65; color: #334155;">
          The Provincial Social Welfare and Development Office (PSWDO) is officially transmitting the approved 
          <strong>AICS Beneficiary Release Registry</strong> for the <strong>Municipality of {$cleanMuni}</strong>.
          The complete certified masterlist with reference numbers, beneficiaries, assistance categories, and disbursement amounts is attached in official PDF format.
        </p>

        <!-- KPI 3-Box Stat Summary Cards -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
          <tr>
            <!-- Box 1: Jurisdiction -->
            <td width="32%" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 12px; text-align: center; vertical-align: top;">
              <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">Jurisdiction</span>
              <strong style="font-size: 15px; color: #0f172a; display: block; line-height: 1.3;">{$cleanMuni}</strong>
              <span style="font-size: 11px; color: #94a3b8;">Antique</span>
            </td>
            <td width="2%">&nbsp;</td>
            <!-- Box 2: Total Records -->
            <td width="32%" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 12px; text-align: center; vertical-align: top;">
              <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">Beneficiaries</span>
              <strong style="font-size: 18px; color: #0284c7; display: block; line-height: 1.3;">{$recordCount}</strong>
              <span style="font-size: 11px; color: #64748b;">Listed Records</span>
            </td>
            <td width="2%">&nbsp;</td>
            <!-- Box 3: Total Amount -->
            <td width="32%" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 16px 12px; text-align: center; vertical-align: top;">
              <span style="font-size: 11px; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">Total Disbursed</span>
              <strong style="font-size: 16px; color: #15803d; display: block; line-height: 1.3;">PHP {$formattedAmount}</strong>
              <span style="font-size: 11px; color: #166534;">Provincial Fund</span>
            </td>
          </tr>
        </table>

        <!-- Summary Specifications Table -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 22px; overflow: hidden;">
          <tr style="background: #f8fafc;">
            <td colspan="2" style="padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">
              Transmittal Metadata
            </td>
          </tr>
          <tr>
            <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #64748b; width: 40%;">Certified By:</td>
            <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; font-weight: 600;">{$cleanStaff} (PSWDO Antique)</td>
          </tr>
          <tr>
            <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #64748b;">Transmittal Date:</td>
            <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; font-weight: 600;">{$dateStr}</td>
          </tr>
          <tr>
            <td style="padding: 12px 16px; font-size: 13px; color: #64748b;">Attached Registry:</td>
            <td style="padding: 12px 16px; font-size: 13px; color: #0284c7; font-weight: 700;">{$cleanFile}</td>
          </tr>
        </table>

        {$notesBlock}

        <!-- Attached Document Card -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #ffffff; border: 2px dashed #0284c7; border-radius: 10px; margin: 24px 0;">
          <tr>
            <td style="padding: 16px 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td width="44" style="vertical-align: middle; font-size: 32px; line-height: 1;">
                    &#128196;
                  </td>
                  <td style="vertical-align: middle; padding-left: 14px;">
                    <strong style="font-size: 14px; color: #0f172a; display: block; margin-bottom: 3px;">
                      Attached: {$cleanFile}
                    </strong>
                    <span style="font-size: 12px; color: #64748b; line-height: 1.4; display: block;">
                      Official Standard Landscape A4 Document with security verification identifiers and dual-signature certification blocks.
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <!-- Municipal Action Guidance -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #f8fafc; border-radius: 8px; padding: 18px 22px; margin-bottom: 24px; border: 1px solid #e2e8f0;">
          <tr>
            <td>
              <strong style="color: #1e293b; font-size: 14px; display: block; margin-bottom: 12px; font-weight: 700;">
                Required Municipal Actions:
              </strong>
              <ul style="margin: 0; padding-left: 20px; font-size: 13.5px; line-height: 1.7; color: #334155;">
                <li style="margin-bottom: 8px;">Disseminate the beneficiary release registry and assigned release schedule to concerned beneficiaries.</li>
                <li style="margin-bottom: 8px;">Inform beneficiaries that assistance will be released at the <strong>PSWDO</strong> on the scheduled date.</li>
                <li style="margin-bottom: 8px;">Remind beneficiaries to bring a valid government-issued ID when claiming assistance.</li>
                <li style="margin-bottom: 8px;">Direct questions or concerns regarding assistance release to the PSWDO.</li>
                <li style="margin-bottom: 0;">Maintain a copy of the transmitted beneficiary registry for municipal reference and records.</li>
              </ul>
            </td>
          </tr>
        </table>

        <!-- Official Sign-off -->
        <div style="border-top: 1px solid #e2e8f0; padding-top: 20px; margin-top: 10px;">
          <p style="margin: 0 0 4px; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Prepared &amp; Certified by:</p>
          <strong style="font-size: 15px; color: #0f172a; display: block;">{$cleanStaff}</strong>
          <span style="font-size: 13px; color: #475569; display: block;">Provincial Social Welfare and Development Office</span>
          <span style="font-size: 12px; color: #94a3b8; display: block; margin-top: 2px;">Province of Antique &bull; Binirayan Hills, San Jose de Buenavista 5700</span>
        </div>

      </td>
    </tr>

    <!-- Footer -->
    <tr>
      <td style="background: #f8fafc; padding: 18px 32px; text-align: center; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 6px; font-size: 11px; color: #94a3b8; line-height: 1.5;">
          This is an official automated communication from the Antique AICS Operations Portal.<br>
          For coordination inquiries, reply directly to this email or visit the PSWDO Operations Desk.
        </p>
        <p style="margin: 0; font-size: 11px; color: #cbd5e1;">
          &copy; {$yearStr} Provincial Government of Antique &bull; All Rights Reserved
        </p>
      </td>
    </tr>

  </table>
</body>
</html>
HTML;
}
