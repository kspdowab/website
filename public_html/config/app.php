<?php
/**
 * KSPDOWA Application Configuration
 * ============================================================
 * Site-wide constants and PHP runtime settings.
 * This file MUST be loaded before any database activity.
 *
 * IMPORTANT: Set APP_ENV to 'production' on the live server.
 * ============================================================
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// Environment: 'development' | 'production'
// ------------------------------------------------------------------
define('APP_ENV', 'development');

// ------------------------------------------------------------------
// Application identity
// ------------------------------------------------------------------
define('APP_NAME', 'KSPDOWA Digital Association Platform');
define('APP_FULL_NAME', 'Karnataka State Panchayat Development Officer Welfare Association (R)');
define('APP_SHORT_NAME', 'KSPDOWA');
define('APP_VERSION', '1.0.0');

// Set to your actual domain (with trailing slash) once confirmed.
// Example: 'https://kspdowa.org/'
// Local development value below (APP_ENV=development, above) -- update
// this to the real domain before any production deployment.
define('BASE_URL', 'http://localhost/');

// ------------------------------------------------------------------
// Paths (all absolute, no trailing slash)
// Guarded with defined() checks: bootstrap.php (the standard entry
// point for every page) already defines these constants before
// requiring this file. Redefining them unconditionally here throws
// "Constant already defined" warnings on every single request, and
// in APP_ENV=development (where such warnings are echoed to output)
// this happens early enough to break session cookie handling for
// the rest of the request. Mirrors the guard pattern in bootstrap.php.
// ------------------------------------------------------------------
if (!defined('PUBLIC_HTML')) {
    define('PUBLIC_HTML', dirname(__DIR__));    // .../public_html
}
if (!defined('CONFIG_DIR')) {
    define('CONFIG_DIR', __DIR__);              // .../public_html/config
}
if (!defined('INCLUDES_DIR')) {
    define('INCLUDES_DIR', PUBLIC_HTML . '/includes');
}
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', PUBLIC_HTML . '/uploads');
}
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(PUBLIC_HTML)); // workspace root
}

// ------------------------------------------------------------------
// Timezone and locale
// ------------------------------------------------------------------
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_LOCALE', 'en_IN');
date_default_timezone_set(APP_TIMEZONE);

// ------------------------------------------------------------------
// Session
// ------------------------------------------------------------------
define('SESSION_NAME',     'KSPDOWA_SESS');
define('SESSION_LIFETIME', 3600);   // seconds (1 hour idle timeout)

// ------------------------------------------------------------------
// Security
// ------------------------------------------------------------------
define('CSRF_TOKEN_LENGTH', 32);          // bytes (produces 64-char hex token)
define('PASSWORD_MIN_LENGTH', 8);
define('BCRYPT_COST', 12);

// ------------------------------------------------------------------
// File uploads
// ------------------------------------------------------------------
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5 MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_DOC_TYPES',   ['application/pdf', 'image/jpeg', 'image/png']);

// ------------------------------------------------------------------
// Grievance number format (per spec: KSPDOWA-GRV-YYYY-NNNNN)
// ------------------------------------------------------------------
define('GRIEVANCE_PREFIX', 'KSPDOWA-GRV');

// ------------------------------------------------------------------
// Payment receipt number format (approved: KSPDOWA-RCP-YYYY-NNNNN)
// ------------------------------------------------------------------
define('RECEIPT_PREFIX', 'KSPDOWA-RCP');

// ------------------------------------------------------------------
// PHP error display — controlled by environment
// ------------------------------------------------------------------
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Log errors always
ini_set('log_errors', '1');
