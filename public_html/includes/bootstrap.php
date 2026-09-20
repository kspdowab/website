<?php
/**
 * KSPDOWA — Central Bootstrap
 * ============================================================
 * Include this at the top of EVERY PHP page in the application.
 *
 * Usage:
 *   require_once __DIR__ . '/includes/bootstrap.php';        // from public_html root
 *   require_once dirname(__DIR__) . '/includes/bootstrap.php'; // from subdirs
 *
 * This file:
 *   1. Defines ROOT path constants
 *   2. Loads application and DB configuration
 *   3. Autoloads all core include classes (in dependency order)
 *   4. Registers the centralized error handler
 *   5. Starts the secure session
 * ============================================================
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// Path constants (defined here so all included files can use them)
// ------------------------------------------------------------------
if (!defined('INCLUDES_DIR')) {
    define('INCLUDES_DIR', __DIR__);                  // .../public_html/includes
}
if (!defined('PUBLIC_HTML')) {
    define('PUBLIC_HTML', dirname(__DIR__));           // .../public_html
}
if (!defined('CONFIG_DIR')) {
    define('CONFIG_DIR', PUBLIC_HTML . '/config');    // .../public_html/config
}
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', PUBLIC_HTML . '/uploads');  // .../public_html/uploads
}
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(PUBLIC_HTML));      // workspace root
}

// ------------------------------------------------------------------
// 1. Application configuration (constants, timezone, error mode)
// ------------------------------------------------------------------
require_once CONFIG_DIR . '/app.php';

// ------------------------------------------------------------------
// 2. Database credentials (loads config/db.secret.php)
// ------------------------------------------------------------------
require_once CONFIG_DIR . '/db.php';

// ------------------------------------------------------------------
// 2b. Mail configuration (loads config/mail.secret.php if present;
//     safe 'log' driver defaults apply otherwise -- see config/mail.php)
// ------------------------------------------------------------------
require_once CONFIG_DIR . '/mail.php';

// ------------------------------------------------------------------
// 2c. Razorpay configuration (loads config/razorpay.secret.php if
//     present; RAZORPAY_ENABLED defaults to false otherwise -- see
//     config/razorpay.php)
// ------------------------------------------------------------------
require_once CONFIG_DIR . '/razorpay.php';

// ------------------------------------------------------------------
// 3. Core classes (load in dependency order)
// ------------------------------------------------------------------
require_once INCLUDES_DIR . '/ErrorHandler.php';  // no deps
require_once INCLUDES_DIR . '/Database.php';      // needs DB_ constants
require_once INCLUDES_DIR . '/Session.php';       // no deps
require_once INCLUDES_DIR . '/AuditLogger.php';   // needs Database, Session
require_once INCLUDES_DIR . '/MembershipNumber.php'; // needs Database, AuditLogger
require_once INCLUDES_DIR . '/Auth.php';          // needs Database, Session, AuditLogger, MembershipNumber
require_once INCLUDES_DIR . '/RBAC.php';          // needs Database, AuditLogger, ErrorHandler
require_once INCLUDES_DIR . '/CSRF.php';          // needs Session, AuditLogger, ErrorHandler
require_once INCLUDES_DIR . '/Sanitize.php';      // no runtime deps
require_once INCLUDES_DIR . '/Settings.php';      // needs Database
require_once INCLUDES_DIR . '/Mailer.php';        // needs MAIL_ constants only
require_once INCLUDES_DIR . '/Membership.php';    // needs Database
require_once INCLUDES_DIR . '/Registration.php'; // needs Database, Sanitize, Membership, AuditLogger
require_once INCLUDES_DIR . '/RazorpayClient.php'; // needs RAZORPAY_ constants only
require_once INCLUDES_DIR . '/PaymentGateway.php'; // needs Database, RazorpayClient, Membership, Auth, Mailer, AuditLogger
require_once INCLUDES_DIR . '/Receipt.php';       // needs Database, AuditLogger; lazily requires lib/fpdf.php only when rendering
require_once INCLUDES_DIR . '/Donation.php';       // needs Database, AuditLogger
require_once INCLUDES_DIR . '/DonationGateway.php'; // needs Database, RazorpayClient, AuditLogger, Donation
require_once INCLUDES_DIR . '/DonationReceipt.php'; // needs Database, AuditLogger, Settings, Receipt (renderLetterhead/latin1)
require_once INCLUDES_DIR . '/Grievance.php';       // Phase 5 Grievance Engine
require_once INCLUDES_DIR . '/Suggestion.php';      // Section 28 Members' Suggestions Engine
require_once INCLUDES_DIR . '/ContentBulkImporter.php'; // Bulk Importer for Orders, Circulars, Documents
require_once INCLUDES_DIR . '/EmailTemplates.php';   // Phase 8 Transactional Email Templates
require_once INCLUDES_DIR . '/WhatsApp.php';         // Phase 8 WhatsApp Messaging Driver
require_once INCLUDES_DIR . '/NotificationService.php'; // Phase 8 Multi-Channel Notification Dispatcher

// ------------------------------------------------------------------
// 4. Register centralized error handler
// ------------------------------------------------------------------
ErrorHandler::register();

// ------------------------------------------------------------------
// 5. Start secure session
// ------------------------------------------------------------------
Session::start();
