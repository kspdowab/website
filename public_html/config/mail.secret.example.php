<?php
/**
 * KSPDOWA Mail Secret Configuration — EXAMPLE / TEMPLATE
 * ============================================================
 * Copy this file to config/mail.secret.php and fill in real SMTP
 * credentials ONLY when you are ready to send real emails.
 *
 *   cp config/mail.secret.example.php config/mail.secret.php
 *
 * mail.secret.php is listed in .gitignore and must NEVER be committed
 * to any version control system or shared publicly.
 *
 * Until that file exists, the application uses the safe 'log' mail
 * driver automatically (see config/mail.php) -- emails are written to
 * storage/mail.log instead of being sent, which is enough to develop
 * and test the authentication/eligibility logic without any SMTP
 * account.
 * ============================================================
 */

define('MAIL_DRIVER', 'smtp');            // switches on real sending
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');         // 'tls' (STARTTLS) | 'ssl' | 'none'
define('SMTP_USER', 'your_smtp_username');
define('SMTP_PASS', 'your_smtp_password');
define('MAIL_FROM_ADDRESS', 'noreply@kspdowa.org');
define('MAIL_FROM_NAME', 'KSPDOWA');
