<?php
/**
 * KSPDOWA — Razorpay API Client (Standard Checkout)
 * ============================================================
 * Thin, dependency-free wrapper over the Razorpay REST API (Orders +
 * Payments) and its signature schemes. No Composer/SDK is used
 * anywhere in this project (flat, framework-free architecture), so
 * this talks to the API directly over cURL with Basic Auth
 * (RAZORPAY_KEY_ID : RAZORPAY_KEY_SECRET), exactly as Razorpay's own
 * REST documentation describes for server-side integrations.
 *
 * Security rules this class exists to enforce:
 *   - RAZORPAY_KEY_SECRET and RAZORPAY_WEBHOOK_SECRET are read from
 *     config only (config/razorpay.secret.php, never committed) and
 *     are NEVER returned to a caller, logged, or placed in any array
 *     that could end up echoed to the browser. Only RAZORPAY_KEY_ID
 *     (not a secret) is meant to reach client-side JS.
 *   - Every method here is a pure gateway call / pure signature
 *     check. It has no knowledge of `membership_payments` rows,
 *     members, or login activation -- that orchestration lives in
 *     PaymentGateway.php, which is what callers should normally use.
 *     register.php / payment.php / payment-verify.php /
 *     razorpay-webhook.php should not need to call this class
 *     directly except via PaymentGateway.
 * ============================================================
 */

declare(strict_types=1);

class RazorpayClient
{
    /**
     * Whether real credentials are configured. Every other method on
     * this class must be treated as unusable when this is false --
     * callers (PaymentGateway) must check it first and show a friendly
     * "payment gateway not configured" message instead of attempting
     * (and failing) a real API call.
     */
    public static function isConfigured(): bool
    {
        return defined('RAZORPAY_ENABLED') && RAZORPAY_ENABLED === true;
    }

    public static function isWebhookConfigured(): bool
    {
        return defined('RAZORPAY_WEBHOOK_SECRET') && RAZORPAY_WEBHOOK_SECRET !== '';
    }

    // ------------------------------------------------------------------
    // Orders API
    // ------------------------------------------------------------------

    /**
     * Create a Razorpay Order for the exact amount to be charged.
     * `payment_capture: 1` is set explicitly so a successful
     * authorization is auto-captured -- without it a payment can sit
     * in 'authorized' state and never actually settle, which would
     * make "server-side verified captured/paid status" impossible to
     * satisfy automatically.
     *
     * @param int    $amountPaise Amount in the smallest currency unit (paise for INR). Must be > 0.
     * @param string $receipt     Merchant reference shown on the Razorpay dashboard -- pass the
     *                            membership_payments row's idempotency_key so the two can always
     *                            be cross-referenced by a human during support/reconciliation.
     * @param array  $notes       Optional free-form key/value metadata (member name, financial year, ...).
     * @return array{success:bool, order?:array, error?:string}
     */
    public static function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        if ($amountPaise <= 0) {
            return ['success' => false, 'error' => 'Invalid amount.'];
        }

        return self::request('POST', '/orders', [
            'amount'          => $amountPaise,
            'currency'        => 'INR',
            'receipt'         => $receipt,
            'payment_capture' => 1,
            'notes'           => $notes,
        ]);
    }

    // ------------------------------------------------------------------
    // Payments API
    // ------------------------------------------------------------------

    /**
     * Fetch the authoritative state of a payment directly from
     * Razorpay's servers. Never trust a client-supplied "it succeeded"
     * signal without this -- the approved spec explicitly requires
     * server-side verification of captured/paid status.
     *
     * @return array{success:bool, payment?:array, error?:string}
     */
    public static function fetchPayment(string $paymentId): array
    {
        if ($paymentId === '') {
            return ['success' => false, 'error' => 'Missing payment ID.'];
        }

        return self::request('GET', '/payments/' . rawurlencode($paymentId));
    }

    // ------------------------------------------------------------------
    // Signature verification
    // ------------------------------------------------------------------

    /**
     * Verify the Standard Checkout success-handler signature:
     *   generated = HMAC-SHA256(order_id + "|" + payment_id, key_secret)
     * per Razorpay's documented Standard Checkout verification scheme.
     * Uses hash_equals() for constant-time comparison.
     */
    public static function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        if (!self::isConfigured() || $orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify a Razorpay webhook's X-Razorpay-Signature header against
     * the RAW request body, using the separate webhook secret (never
     * the API key secret -- Razorpay issues these independently).
     */
    public static function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        if (!self::isWebhookConfigured() || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, RAZORPAY_WEBHOOK_SECRET);
        return hash_equals($expected, $signature);
    }

    // ------------------------------------------------------------------
    // HTTP transport
    // ------------------------------------------------------------------

    /**
     * @return array{success:bool, order?:array, payment?:array, error?:string}
     */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'Razorpay is not configured.'];
        }

        $url = RAZORPAY_API_BASE . $path;
        $ch  = curl_init($url);

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Razorpay's API is HTTPS-only; never disable peer/host
            // verification even in development.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== null) {
            $headers[]                   = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw     = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errmsg  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log('[KSPDOWA][RazorpayClient] cURL error calling ' . $path . ': ' . $errmsg);
            return ['success' => false, 'error' => 'Could not reach the payment gateway. Please try again.'];
        }

        $decoded = json_decode((string) $raw, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiMessage = is_array($decoded) ? ($decoded['error']['description'] ?? null) : null;
            error_log('[KSPDOWA][RazorpayClient] API error ' . $httpCode . ' calling ' . $path . ': ' . (string) $raw);
            return ['success' => false, 'error' => $apiMessage ?? 'The payment gateway rejected the request.'];
        }

        if (!is_array($decoded)) {
            error_log('[KSPDOWA][RazorpayClient] Unexpected non-JSON response from ' . $path);
            return ['success' => false, 'error' => 'Unexpected response from the payment gateway.'];
        }

        // Callers key off 'order' or 'payment' depending on which
        // endpoint they called; provide both under a generic 'data'
        // key too so request() itself never needs to know the shape.
        return ['success' => true, 'order' => $decoded, 'payment' => $decoded, 'data' => $decoded];
    }
}
