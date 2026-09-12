<?php
/**
 * KSPDOWA Razorpay Secret Configuration — EXAMPLE / TEMPLATE
 * ============================================================
 * Copy this file to config/razorpay.secret.php and fill in your real
 * Razorpay credentials.
 *
 *   cp config/razorpay.secret.example.php config/razorpay.secret.php
 *
 * razorpay.secret.php is listed in .gitignore and must NEVER be
 * committed to any version control system or shared publicly.
 *
 * Where to find these (Razorpay Dashboard):
 *   - RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET:
 *       Settings -> API Keys -> Generate Key (use TEST mode keys,
 *       rzp_test_..., for local development; switch to live keys
 *       only on the production Hostinger deployment).
 *   - RAZORPAY_WEBHOOK_SECRET:
 *       Settings -> Webhooks -> Add New Webhook
 *         URL:    https://<your-domain>/razorpay-webhook.php
 *         Events: payment.captured, payment.failed, order.paid
 *       Razorpay shows the webhook secret once, at creation time --
 *       copy it here immediately.
 *
 * Until this file exists, RAZORPAY_ENABLED is false and the
 * application shows a clear "payment gateway not configured" message
 * instead of attempting (and failing) a real API call -- see
 * config/razorpay.php.
 * ============================================================
 */

define('RAZORPAY_KEY_ID',        'rzp_test_your_key_id_here');
define('RAZORPAY_KEY_SECRET',    'your_key_secret_here');
define('RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here');
