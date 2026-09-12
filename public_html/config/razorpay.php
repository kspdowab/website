<?php
/**
 * KSPDOWA Razorpay Configuration Loader
 * ============================================================
 * Mirrors the config/db.php + db.secret.php split (and config/mail.php):
 * real credentials live only in config/razorpay.secret.php, which is
 * excluded from git via .gitignore and must NEVER be committed.
 *
 * SETUP INSTRUCTIONS (needed before Standard Checkout can take a real
 * payment):
 *   1. Copy config/razorpay.secret.example.php -> config/razorpay.secret.php
 *   2. Fill in your real Razorpay Key ID / Key Secret / Webhook Secret
 *      (Razorpay Dashboard -> Settings -> API Keys / Webhooks)
 *   3. razorpay.secret.php is excluded from version control
 *   4. NEVER commit razorpay.secret.php, and NEVER echo RAZORPAY_KEY_SECRET
 *      or RAZORPAY_WEBHOOK_SECRET anywhere the browser can see them --
 *      only RAZORPAY_KEY_ID is safe to send to the browser (Standard
 *      Checkout requires it there; it is not a secret).
 *
 * Until razorpay.secret.php exists (or is only partially filled in),
 * RAZORPAY_ENABLED is false and RazorpayClient refuses to create
 * orders or call the Razorpay API -- payment.php shows a clear
 * "payment gateway not configured" message instead of a fatal error,
 * the same safe-default pattern config/mail.php uses for MAIL_DRIVER.
 * ============================================================
 */

declare(strict_types=1);

$_razorpaySecretFile = CONFIG_DIR . '/razorpay.secret.php';
if (file_exists($_razorpaySecretFile)) {
    require_once $_razorpaySecretFile;
}
unset($_razorpaySecretFile);

if (!defined('RAZORPAY_KEY_ID')) {
    define('RAZORPAY_KEY_ID', '');
}
if (!defined('RAZORPAY_KEY_SECRET')) {
    define('RAZORPAY_KEY_SECRET', '');
}
if (!defined('RAZORPAY_WEBHOOK_SECRET')) {
    define('RAZORPAY_WEBHOOK_SECRET', '');
}

// Base URL for the Razorpay REST API. Not a secret; kept as a constant
// only so RazorpayClient never hardcodes it inline.
if (!defined('RAZORPAY_API_BASE')) {
    define('RAZORPAY_API_BASE', 'https://api.razorpay.com/v1');
}

/**
 * True only when both a Key ID and Key Secret are actually configured
 * (not left as empty strings / example placeholders). RazorpayClient
 * and payment.php must check this before attempting any gateway call.
 */
define('RAZORPAY_ENABLED', RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '');
