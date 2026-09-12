<?php
/**
 * KSPDOWA — Razorpay Webhook Endpoint
 * ============================================================
 * Server-to-server endpoint called by Razorpay's infrastructure, not
 * a browser -- there is no session and CSRF does not apply here.
 * Authenticity comes ENTIRELY from the HMAC signature over the raw
 * request body (RazorpayClient::verifyWebhookSignature(), using
 * RAZORPAY_WEBHOOK_SECRET -- a value distinct from the API Key
 * Secret, configured separately on the Razorpay Dashboard under
 * Settings -> Webhooks when this URL is registered there).
 *
 * Exists as the resilient counterpart to payment-verify.php's
 * browser callback: if a member's browser/network drops before the
 * checkout success handler can POST back (closed tab, phone locked,
 * etc.), Razorpay still delivers this webhook once the payment
 * captures, and PaymentGateway::confirmPayment() safely completes the
 * same payment through it -- with the browser callback (if it does
 * eventually arrive) safely ignored as a duplicate, and vice versa.
 *
 * Configuring RAZORPAY_WEBHOOK_SECRET is optional -- until it is
 * configured this endpoint rejects everything with 503 rather than
 * accepting unauthenticated payloads, and the browser callback path
 * alone still fully completes payments.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');

// Must read the raw body before anything else could consume
// php://input.
$rawBody   = file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if (!RazorpayClient::isWebhookConfigured()) {
    http_response_code(503);
    echo json_encode(['status' => 'webhook_not_configured']);
    exit;
}

if ($rawBody === false || $rawBody === '' || !RazorpayClient::verifyWebhookSignature($rawBody, $signature)) {
    AuditLogger::log('WEBHOOK_SIGNATURE_INVALID', 'membership_payments', null, null, [
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    http_response_code(400);
    echo json_encode(['status' => 'invalid_signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'invalid_payload']);
    exit;
}

$event = (string) ($payload['event'] ?? '');

// Only payment.captured actually confirms a payment. Every other
// event (order.paid, payment.failed, refund.*, ...) is acknowledged
// with 200 (so Razorpay does not keep retrying an endpoint that looks
// like it's failing) but otherwise ignored -- failures are already
// handled client-side via payment-failed.php, and this project does
// not process refunds automatically.
if ($event === 'payment.captured') {
    $paymentEntity = $payload['payload']['payment']['entity'] ?? null;

    if (is_array($paymentEntity)) {
        $orderId   = (string) ($paymentEntity['order_id'] ?? '');
        $paymentId = (string) ($paymentEntity['id'] ?? '');

        if ($orderId !== '' && $paymentId !== '') {
            $result = PaymentGateway::confirmPayment($orderId, $paymentId, null, 'webhook');
            if (!$result['success']) {
                error_log('[KSPDOWA][razorpay-webhook] confirmPayment failed for order ' . $orderId . ': ' . ($result['error'] ?? ''));
            }
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
