<?php
/**
 * KSPDOWA Database Configuration Loader
 * ============================================================
 * Loads database credentials from db.secret.php.
 *
 * SETUP INSTRUCTIONS:
 *   1. Copy config/db.secret.example.php → config/db.secret.php
 *   2. Fill in your Hostinger MySQL credentials in db.secret.php
 *   3. db.secret.php is excluded from version control (.gitignore)
 *   4. NEVER commit db.secret.php to any repository
 * ============================================================
 */

declare(strict_types=1);

$_secretFile = __DIR__ . '/db.secret.php';

if (!file_exists($_secretFile)) {
    $message = 'Database configuration file not found. '
             . 'Copy config/db.secret.example.php to config/db.secret.php '
             . 'and fill in your database credentials.';

    // In development, show the message. In production, log it only.
    if (defined('APP_ENV') && APP_ENV === 'production') {
        error_log('[KSPDOWA] ' . $message);
        http_response_code(503);
        exit('Service temporarily unavailable.');
    }

    exit($message);
}

require_once $_secretFile;
unset($_secretFile);

// Validate that all required constants are defined
$_required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
foreach ($_required as $_const) {
    if (!defined($_const)) {
        $msg = "Database configuration is incomplete: constant {$_const} is not defined.";
        error_log('[KSPDOWA] ' . $msg);
        exit($msg);
    }
}
unset($_required, $_const, $_msg);
