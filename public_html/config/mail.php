<?php
/**
 * KSPDOWA Mail Configuration Loader
 * ============================================================
 * Mirrors the config/db.php + db.secret.php split, but mail is NOT
 * mandatory to run the application: unlike the database, there is
 * no real SMTP account for this project yet, and the core
 * authentication/eligibility logic (Phase 3) must be fully testable
 * locally without one.
 *
 * SETUP INSTRUCTIONS (only needed once real SMTP credentials exist):
 *   1. Copy config/mail.secret.example.php → config/mail.secret.php
 *   2. Fill in real SMTP credentials and set MAIL_DRIVER to 'smtp'
 *   3. mail.secret.php is excluded from version control (.gitignore)
 *   4. NEVER commit mail.secret.php to any repository
 *
 * Until that file exists, MAIL_DRIVER defaults to 'log': Mailer::send()
 * writes the message to storage/mail.log (outside the web root) instead
 * of sending it. This is sufficient to develop and test every part of
 * the authentication/eligibility flow that depends on "an email was
 * sent" without ever touching a real mail server.
 * ============================================================
 */

declare(strict_types=1);

$_mailSecretFile = CONFIG_DIR . '/mail.secret.php';
if (file_exists($_mailSecretFile)) {
    require_once $_mailSecretFile;
}
unset($_mailSecretFile);

// Safe defaults -- applied whenever mail.secret.php is absent, or present
// but doesn't override a given constant.
if (!defined('MAIL_DRIVER')) {
    define('MAIL_DRIVER', 'log'); // 'log' (safe, default) | 'smtp'
}
if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', 'no-reply@kspdowa.local');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'KSPDOWA');
}
if (!defined('MAIL_LOG_PATH')) {
    // Outside PUBLIC_HTML deliberately -- this log can contain
    // one-time temporary passwords in clear text during local testing
    // and must never be web-accessible.
    define('MAIL_LOG_PATH', PROJECT_ROOT . '/storage/mail.log');
}
