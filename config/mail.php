<?php
declare(strict_types=1);

/**
 * AICS Automated Portal - Central Mail & SMTP Configuration
 *
 * This file configures PHPMailer for dispatching official municipal release masterlists,
 * beneficiary notifications, and administrative alerts.
 *
 * To send real emails via Gmail:
 * 1. Set SMTP_USER to your Gmail address (e.g. pswdo.antique@gmail.com).
 * 2. Generate a 16-character Google "App Password" (from Google Account Security -> 2-Step Verification -> App Passwords).
 * 3. Set SMTP_PASS to the generated App Password.
 * Use environment variables on servers, or the git-ignored config/secrets.local.php under XAMPP.
 */

$aicsLocalSecrets = is_file(__DIR__ . '/secrets.local.php') ? (array) require __DIR__ . '/secrets.local.php' : [];

if (!defined('AICS_MAIL_DRIVER')) {
    define('AICS_MAIL_DRIVER', getenv('MAIL_DRIVER') ?: 'smtp'); // 'smtp' or 'simulate'
}

if (!defined('AICS_SMTP_HOST')) {
    define('AICS_SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
}

if (!defined('AICS_SMTP_PORT')) {
    define('AICS_SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
}

if (!defined('AICS_SMTP_SECURE')) {
    define('AICS_SMTP_SECURE', strtolower((string) (getenv('SMTP_SECURE') ?: 'tls'))); // 'tls' or 'ssl'
}

if (!defined('AICS_SMTP_AUTH')) {
    define('AICS_SMTP_AUTH', true);
}

if (!defined('AICS_SMTP_USER')) {
    define('AICS_SMTP_USER', (string) (getenv('SMTP_USER') ?: ($aicsLocalSecrets['SMTP_USER'] ?? '')));
}

if (!defined('AICS_SMTP_PASS')) {
    // Google App Password (16 characters, spaces stripped for SMTP auth)
    define('AICS_SMTP_PASS', (string) (getenv('SMTP_PASS') ?: ($aicsLocalSecrets['SMTP_PASS'] ?? '')));
}

if (!defined('AICS_MAIL_FROM_EMAIL')) {
    define('AICS_MAIL_FROM_EMAIL', (string) (getenv('MAIL_FROM_ADDRESS') ?: ($aicsLocalSecrets['MAIL_FROM_ADDRESS'] ?? '')));
}

if (!defined('AICS_MAIL_FROM_NAME')) {
    define('AICS_MAIL_FROM_NAME', (string) (getenv('MAIL_FROM_NAME') ?: 'PSWDO Province of Antique'));
}

if (!defined('AICS_MAIL_REPLY_TO')) {
    define('AICS_MAIL_REPLY_TO', (string) (getenv('MAIL_REPLY_TO') ?: ($aicsLocalSecrets['MAIL_REPLY_TO'] ?? '')));
}

/**
 * Returns true if real SMTP credentials are provided and ready to transmit.
 */
function aics_is_smtp_configured(): bool
{
    return trim(AICS_SMTP_HOST) !== ''
        && trim(AICS_SMTP_USER) !== ''
        && trim(AICS_SMTP_PASS) !== '';
}
