<?php
/**
 * KSPDOWA — Donation Payment Gateway Orchestration (Razorpay Standard
 * Checkout)
 * ============================================================
 * Ties a `donations` row (owned by Donation.php) to a Razorpay
 * Order/Payment (owned by RazorpayClient.php) -- the donation
 * equivalent of PaymentGateway.php for membership_payments. This is
 * the ONLY place allowed to mark a donations row 'completed';
 * donate.php, donate-payment.php, donate-verify.php and
 * donate-failed.php must all go through this class rather than
 * touching donations.status directly.
 *
 * Deliberately simpler than PaymentGateway.php: a donation amount is
 * fixed by the donor at creation time (Donation::create()) and never
 * needs "refreshing" against a current financial year the way an
 * annual membership fee does, and there is no login/activation step
 * afterward. Everything else -- signature verification, authoritative
 * status fetch, order/amount match (including the same Razorpay
 * Convenience Fee reconciliation), row-level locking, exactly-once
 * completion -- mirrors PaymentGateway::confirmPayment() precisely, so
 * a donation gets the exact same server-side verification rigor as a
 * membership payment.
 * ============================================================
 */

declare(strict_types=1);

class DonationGateway
{
    /**
     * Ensure a Razorpay Order exists for the given donation attempt,
     * creating one only if this attempt has never had one. Idempotent
     * by construction, same as PaymentGateway::ensureOrder().
     *
     * @param int $donationId A `donations.id` the caller has already
     *                        established belongs to this session
     *                        (donate-payment.php reads this only from
     *                        Session, never from a query string).
     * @return array{success:bool, order_id?:string, amount_paise?:int, currency?:string, key_id?:string, amount?:float, donor_name?:string, error?:string}
     */
    public static function ensureOrder(int $donationId): array
    {
        if (!RazorpayClient::isConfigured()) {
            return ['success' => false, 'error' => 'Online payment is temporarily unavailable. Please contact the Association.'];
        }

        try {
            return Database::transaction(function () use ($donationId) {
                $row = Database::fetchOne('SELECT * FROM donations WHERE id = ? FOR UPDATE', [$donationId]);
                if ($row === false) {
                    return ['success' => false, 'error' => 'Donation record not found.'];
                }
                if ($row['status'] !== 'pending') {
                    return ['success' => false, 'error' => 'This donation attempt is no longer active.'];
                }

                if ($row['gateway_order_id'] !== null && $row['gateway_order_id'] !== '') {
                    // Already has an order -- reuse it, no API call.
                    return [
                        'success'      => true,
                        'order_id'     => $row['gateway_order_id'],
                        'amount_paise' => (int) round(((float) $row['amount']) * 100),
                        'currency'     => 'INR',
                        'key_id'       => RAZORPAY_KEY_ID,
                        'amount'       => (float) $row['amount'],
                        'donor_name'   => (string) $row['donor_name'],
                    ];
                }

                $amount      = (float) $row['amount'];
                $amountPaise = (int) round($amount * 100);

                $created = RazorpayClient::createOrder($amountPaise, (string) $row['idempotency_key'], [
                    'donor_name' => (string) $row['donor_name'],
                    'purpose'    => (string) $row['purpose'],
                ]);

                if (!$created['success']) {
                    return ['success' => false, 'error' => $created['error'] ?? 'Could not initiate payment. Please try again.'];
                }

                $orderId = (string) ($created['order']['id'] ?? '');
                if ($orderId === '') {
                    error_log('[KSPDOWA][DonationGateway] Razorpay createOrder() returned no order id for donation ' . $donationId);
                    return ['success' => false, 'error' => 'Could not initiate payment. Please try again.'];
                }

                Database::execute(
                    'UPDATE donations SET gateway_order_id = ? WHERE id = ? AND gateway_order_id IS NULL',
                    [$orderId, $donationId]
                );
                AuditLogger::log('UPDATE', 'donations', $donationId, null, [
                    'gateway_order_id' => $orderId, 'source' => 'razorpay_order_created',
                ]);

                return [
                    'success'      => true,
                    'order_id'     => $orderId,
                    'amount_paise' => $amountPaise,
                    'currency'     => 'INR',
                    'key_id'       => RAZORPAY_KEY_ID,
                    'amount'       => $amount,
                    'donor_name'   => (string) $row['donor_name'],
                ];
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][DonationGateway] ensureOrder() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not initiate payment due to a system error. Please try again.'];
        }
    }

    /**
     * The single shared path for confirming a Razorpay donation
     * payment. Currently called only by donate-verify.php (browser
     * post-checkout callback, source='callback'); structured the same
     * way as PaymentGateway::confirmPayment() so a webhook handler
     * could be added later without changing this method's contract.
     *
     * @param string      $orderId   razorpay_order_id
     * @param string      $paymentId razorpay_payment_id
     * @param string|null $signature razorpay_signature (only present for source='callback')
     * @param string      $source    'callback' | 'webhook' -- audit trail only
     * @return array{success:bool, already_completed?:bool, donation_id?:int, error?:string}
     */
    public static function confirmPayment(string $orderId, string $paymentId, ?string $signature, string $source): array
    {
        if ($source === 'callback') {
            if ($signature === null || !RazorpayClient::verifyPaymentSignature($orderId, $paymentId, $signature)) {
                AuditLogger::log('PAYMENT_SIGNATURE_INVALID', 'donations', null, null, [
                    'order_id' => $orderId, 'payment_id' => $paymentId, 'source' => $source,
                ]);
                return ['success' => false, 'error' => 'Payment verification failed. If an amount was debited, please contact the Association with your payment reference.'];
            }
        }

        $donationRow = Database::fetchOne('SELECT id FROM donations WHERE gateway_order_id = ?', [$orderId]);
        if ($donationRow === false) {
            AuditLogger::log('PAYMENT_ORDER_UNKNOWN', 'donations', null, null, [
                'order_id' => $orderId, 'payment_id' => $paymentId, 'source' => $source,
            ]);
            return ['success' => false, 'error' => 'Unknown payment order.'];
        }
        $donationId = (int) $donationRow['id'];

        try {
            return Database::transaction(function () use ($donationId, $orderId, $paymentId, $signature, $source) {
                $row = Database::fetchOne('SELECT * FROM donations WHERE id = ? FOR UPDATE', [$donationId]);
                if ($row === false) {
                    return ['success' => false, 'error' => 'Donation record not found.'];
                }

                if ($row['status'] === 'completed') {
                    // Duplicate callback for an already-confirmed donation
                    // -- safely ignored, not an error.
                    return ['success' => true, 'already_completed' => true, 'donation_id' => $donationId];
                }
                if ($row['status'] !== 'pending') {
                    return ['success' => false, 'error' => 'This donation attempt is no longer pending.'];
                }

                $fetch = RazorpayClient::fetchPayment($paymentId);
                if (!$fetch['success']) {
                    return ['success' => false, 'error' => $fetch['error'] ?? 'Could not verify payment with the gateway.'];
                }
                $p = $fetch['payment'];

                if (($p['order_id'] ?? null) !== $orderId) {
                    AuditLogger::log('PAYMENT_ORDER_MISMATCH', 'donations', $row['id'], null, [
                        'expected_order_id' => $orderId, 'gateway_reported_order_id' => $p['order_id'] ?? null, 'source' => $source,
                    ]);
                    return ['success' => false, 'error' => 'Payment order mismatch.'];
                }

                if (($p['status'] ?? '') !== 'captured') {
                    return ['success' => false, 'error' => 'Payment has not been captured yet.'];
                }

                // Same Razorpay Convenience Fee reconciliation as
                // PaymentGateway::confirmPayment() -- see that method's
                // doc block for the full rationale. The donation amount
                // credited is always exactly $expectedPaise, never the
                // total the donor was actually charged.
                $expectedPaise = (int) round(((float) $row['amount']) * 100);
                $grossPaise    = (int) ($p['amount'] ?? -1);
                $feePaise      = (int) ($p['fee'] ?? 0);
                $taxPaise      = (int) ($p['tax'] ?? 0);
                $excessPaise   = $grossPaise - $expectedPaise;

                $amountOk = ($p['currency'] ?? '') === 'INR'
                    && $grossPaise >= $expectedPaise
                    && ($excessPaise === 0 || $excessPaise === ($feePaise + $taxPaise));

                if (!$amountOk) {
                    AuditLogger::log('PAYMENT_AMOUNT_MISMATCH', 'donations', $row['id'], null, [
                        'expected_paise' => $expectedPaise, 'gateway_amount' => $p['amount'] ?? null,
                        'gateway_fee' => $p['fee'] ?? null, 'gateway_tax' => $p['tax'] ?? null,
                        'gateway_currency' => $p['currency'] ?? null, 'source' => $source,
                    ]);
                    return ['success' => false, 'error' => 'Payment amount mismatch.'];
                }

                Database::execute(
                    "UPDATE donations
                     SET status = 'completed', gateway_payment_id = ?, gateway_signature = ?, paid_at = NOW(),
                         gateway_amount_paise = ?, gateway_fee_paise = ?, gateway_tax_paise = ?
                     WHERE id = ? AND status = 'pending'",
                    [$paymentId, $signature ?? '', $grossPaise, $feePaise, $taxPaise, $row['id']]
                );

                AuditLogger::log('UPDATE', 'donations', $row['id'], ['status' => 'pending'], [
                    'status' => 'completed', 'gateway_payment_id' => $paymentId, 'source' => $source,
                ]);

                return ['success' => true, 'already_completed' => false, 'donation_id' => $donationId];
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // Unique-key race (e.g. duplicate concurrent callback) --
                // treat as an already-completed duplicate, never a hard error.
                return ['success' => true, 'already_completed' => true, 'donation_id' => $donationId];
            }
            error_log('[KSPDOWA][DonationGateway] confirmPayment() DB error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Payment could not be confirmed due to a system error.'];
        } catch (Throwable $e) {
            error_log('[KSPDOWA][DonationGateway] confirmPayment() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Payment could not be confirmed due to a system error.'];
        }
    }

    // ------------------------------------------------------------------
    // Failure handling / retry (thin delegation to Donation.php, which
    // owns the donations row lifecycle)
    // ------------------------------------------------------------------

    public static function markFailed(int $donationId, string $reason = ''): void
    {
        Donation::markFailed($donationId, $reason);
    }

    public static function startNewAttempt(int $existingDonationId): array
    {
        return Donation::startNewAttempt($existingDonationId);
    }
}
