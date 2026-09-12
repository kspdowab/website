<?php
/**
 * KSPDOWA — Payment Gateway Orchestration (Razorpay Standard Checkout)
 * ============================================================
 * Ties a `membership_payments` row (owned by Registration.php) to a
 * Razorpay Order/Payment (owned by RazorpayClient.php). This is the
 * ONLY place that is allowed to mark a membership_payments row
 * 'completed' or trigger login activation -- payment.php,
 * payment-verify.php and razorpay-webhook.php must all go through
 * this class rather than touching membership_payments status
 * directly, so there is exactly one place implementing:
 *
 *   - "Create Razorpay Orders server-side for the exact current-year
 *     payable amount."
 *   - "Verify payment signature and captured/paid status server-side."
 *   - "Match payment to member, financial year, order and amount
 *     before activation."
 *   - "Prevent duplicate payment/member/membership activation using
 *     DB transaction/locking."
 *   - "Duplicate callback/webhook must be safely ignored."
 *   - "Generate temporary password and activate login exactly once
 *     after verified payment."
 * ============================================================
 */

declare(strict_types=1);

class PaymentGateway
{
    // ------------------------------------------------------------------
    // Order creation / reuse
    // ------------------------------------------------------------------

    /**
     * Ensure a Razorpay Order exists for the given payment attempt,
     * creating one only if this attempt has never had one. Idempotent
     * by construction: reloading the payment page, or the browser
     * retrying after a slow response, never creates a second Razorpay
     * Order for the same attempt -- it always returns the one already
     * stored on the row.
     *
     * Locks the row (`FOR UPDATE`) for the duration of this call,
     * including the Razorpay API call when a new order must actually
     * be created, so two concurrent requests for the same attempt
     * cannot both create an Order. Acceptable for this application's
     * scale (a small association, not a high-concurrency storefront);
     * the alternative (release the lock before the network call) would
     * reopen the exact race this method exists to close.
     *
     * Also refreshes the row to the CURRENT financial year's exact
     * payable amount if no order has been created yet and the year
     * has rolled over since the row was created -- approved spec:
     * "Create Razorpay Orders server-side for the exact current-year
     * payable amount" / "Never hard-code 2026-27." Once an order has
     * been created, the amount is frozen (a live checkout session must
     * never have its price change under the member).
     *
     * @param int $paymentId A `membership_payments.id` the caller has
     *                        already established belongs to this
     *                        session (payment.php reads this only from
     *                        Session, never from a query string).
     * @return array{success:bool, order_id?:string, amount_paise?:int, currency?:string, key_id?:string, amount?:float, financial_year?:string, member?:array, error?:string}
     */
    public static function ensureOrder(int $paymentId): array
    {
        if (!RazorpayClient::isConfigured()) {
            return ['success' => false, 'error' => 'Online payment is temporarily unavailable. Please contact the Association.'];
        }

        try {
            return Database::transaction(function () use ($paymentId) {
                $row = Database::fetchOne('SELECT * FROM membership_payments WHERE id = ? FOR UPDATE', [$paymentId]);
                if ($row === false) {
                    return ['success' => false, 'error' => 'Payment record not found.'];
                }
                if ($row['status'] !== 'pending') {
                    return ['success' => false, 'error' => 'This payment attempt is no longer active.'];
                }

                $member = Database::fetchOne(
                    'SELECT m.id, m.name, mp.personal_email, mp.personal_mobile
                     FROM members m JOIN member_profiles mp ON mp.member_id = m.id
                     WHERE m.id = ?',
                    [$row['member_id']]
                );
                if ($member === false) {
                    return ['success' => false, 'error' => 'Member record not found.'];
                }

                if ($row['gateway_order_id'] !== null && $row['gateway_order_id'] !== '') {
                    // Already has an order -- reuse it, no API call.
                    return [
                        'success'        => true,
                        'order_id'       => $row['gateway_order_id'],
                        'amount_paise'   => (int) round(((float) $row['amount']) * 100),
                        'currency'       => 'INR',
                        'key_id'         => RAZORPAY_KEY_ID,
                        'amount'         => (float) $row['amount'],
                        'member'         => $member,
                    ];
                }

                // No order yet -- refresh to the exact current-year
                // amount before creating one (see doc block above).
                $year = Membership::getCurrentYear();
                if ($year === null) {
                    return ['success' => false, 'error' => 'The current annual membership fee has not been configured yet. Please contact the Association.'];
                }

                $amount = (float) $row['amount'];
                if ((int) $row['membership_year_id'] !== (int) $year['id']) {
                    $amount = (float) $year['fee_amount'];
                    Database::execute(
                        'UPDATE membership_payments SET membership_year_id = ?, amount = ? WHERE id = ?',
                        [$year['id'], $amount, $paymentId]
                    );
                    AuditLogger::log('UPDATE', 'membership_payments', $paymentId,
                        ['membership_year_id' => $row['membership_year_id'], 'amount' => $row['amount']],
                        ['membership_year_id' => $year['id'], 'amount' => $amount, 'reason' => 'financial_year_rolled_over_before_payment']
                    );
                }

                $amountPaise = (int) round($amount * 100);

                $created = RazorpayClient::createOrder($amountPaise, (string) $row['idempotency_key'], [
                    'member_id'      => (string) $row['member_id'],
                    'financial_year' => $year['financial_year'],
                ]);

                if (!$created['success']) {
                    return ['success' => false, 'error' => $created['error'] ?? 'Could not initiate payment. Please try again.'];
                }

                $orderId = (string) ($created['order']['id'] ?? '');
                if ($orderId === '') {
                    error_log('[KSPDOWA][PaymentGateway] Razorpay createOrder() returned no order id for payment ' . $paymentId);
                    return ['success' => false, 'error' => 'Could not initiate payment. Please try again.'];
                }

                Database::execute(
                    'UPDATE membership_payments SET gateway_order_id = ? WHERE id = ? AND gateway_order_id IS NULL',
                    [$orderId, $paymentId]
                );
                AuditLogger::log('UPDATE', 'membership_payments', $paymentId, null, [
                    'gateway_order_id' => $orderId, 'source' => 'razorpay_order_created',
                ]);

                return [
                    'success'        => true,
                    'order_id'       => $orderId,
                    'amount_paise'   => $amountPaise,
                    'currency'       => 'INR',
                    'key_id'         => RAZORPAY_KEY_ID,
                    'amount'         => $amount,
                    'member'         => $member,
                ];
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][PaymentGateway] ensureOrder() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not initiate payment due to a system error. Please try again.'];
        }
    }

    // ------------------------------------------------------------------
    // Verification + activation
    // ------------------------------------------------------------------

    /**
     * The single shared path for confirming a Razorpay payment and
     * activating member login, called by BOTH payment-verify.php (the
     * browser's post-checkout callback, source='callback') and
     * razorpay-webhook.php (source='webhook', which must have already
     * verified the raw-body webhook signature via
     * RazorpayClient::verifyWebhookSignature() before calling this).
     *
     * Every check below runs regardless of source -- a webhook is not
     * trusted any more than a browser callback beyond the one
     * signature scheme each uses to prove authenticity; both still go
     * through the same order/amount/status match and the same
     * row-level lock.
     *
     * @param string      $orderId   razorpay_order_id
     * @param string      $paymentId razorpay_payment_id
     * @param string|null $signature razorpay_signature (only present for source='callback')
     * @param string      $source    'callback' | 'webhook' -- audit trail only
     * @return array{success:bool, already_completed?:bool, member_id?:int, activation?:array, error?:string}
     */
    public static function confirmPayment(string $orderId, string $paymentId, ?string $signature, string $source): array
    {
        if ($source === 'callback') {
            if ($signature === null || !RazorpayClient::verifyPaymentSignature($orderId, $paymentId, $signature)) {
                AuditLogger::log('PAYMENT_SIGNATURE_INVALID', 'membership_payments', null, null, [
                    'order_id' => $orderId, 'payment_id' => $paymentId, 'source' => $source,
                ]);
                return ['success' => false, 'error' => 'Payment verification failed. If an amount was debited, please contact the Association with your payment reference.'];
            }
        }

        $paymentRow = Database::fetchOne('SELECT id FROM membership_payments WHERE gateway_order_id = ?', [$orderId]);
        if ($paymentRow === false) {
            AuditLogger::log('PAYMENT_ORDER_UNKNOWN', 'membership_payments', null, null, [
                'order_id' => $orderId, 'payment_id' => $paymentId, 'source' => $source,
            ]);
            return ['success' => false, 'error' => 'Unknown payment order.'];
        }
        $paymentRowId = (int) $paymentRow['id'];

        try {
            $result = Database::transaction(function () use ($paymentRowId, $orderId, $paymentId, $signature, $source) {
                $row = Database::fetchOne('SELECT * FROM membership_payments WHERE id = ? FOR UPDATE', [$paymentRowId]);
                if ($row === false) {
                    return ['success' => false, 'error' => 'Payment record not found.'];
                }

                if ($row['status'] === 'completed') {
                    // Duplicate callback/webhook for an already-confirmed
                    // payment -- safely ignored, not an error.
                    return ['success' => true, 'already_completed' => true, 'member_id' => (int) $row['member_id']];
                }
                if ($row['status'] !== 'pending') {
                    return ['success' => false, 'error' => 'This payment attempt is no longer pending.'];
                }

                $fetch = RazorpayClient::fetchPayment($paymentId);
                if (!$fetch['success']) {
                    return ['success' => false, 'error' => $fetch['error'] ?? 'Could not verify payment with the gateway.'];
                }
                $p = $fetch['payment'];

                if (($p['order_id'] ?? null) !== $orderId) {
                    AuditLogger::log('PAYMENT_ORDER_MISMATCH', 'membership_payments', $row['id'], null, [
                        'expected_order_id' => $orderId, 'gateway_reported_order_id' => $p['order_id'] ?? null, 'source' => $source,
                    ]);
                    return ['success' => false, 'error' => 'Payment order mismatch.'];
                }

                if (($p['status'] ?? '') !== 'captured') {
                    return ['success' => false, 'error' => 'Payment has not been captured yet.'];
                }

                // KSPDOWA's Order is always created for the exact configured
                // membership fee alone (Razorpay Orders are immutable in
                // amount once created -- see PaymentGateway::ensureOrder()).
                // Razorpay may still collect an ADDITIONAL Convenience Fee
                // from the member on top of that, entirely at Razorpay's own
                // discretion (merchant dashboard setting outside this
                // application's control) -- confirmed empirically against
                // Razorpay's live Orders/Payments API: a captured Payment's
                // `amount` is the TOTAL charged to the member, while
                // Razorpay itself reports the fee/tax that explains any
                // amount above the membership fee via `fee`/`tax` on the
                // same Payment object (e.g. amount=102000, fee=2000, tax=0
                // for a Rs 1,000 order -- 102000-2000-0 = 100000). KSPDOWA
                // NEVER computes or hard-codes that fee -- it only accepts
                // Razorpay's own reported figures and requires them to
                // exactly explain any excess. Underpayment, or an excess
                // Razorpay's own fee/tax fields do not fully account for,
                // is rejected as a mismatch either way. The membership fee
                // credited toward eligibility is always exactly $expectedPaise,
                // never the total the member was actually charged.
                $expectedPaise = (int) round(((float) $row['amount']) * 100);
                $grossPaise    = (int) ($p['amount'] ?? -1);
                $feePaise      = (int) ($p['fee'] ?? 0);
                $taxPaise      = (int) ($p['tax'] ?? 0);
                $excessPaise   = $grossPaise - $expectedPaise;

                $amountOk = ($p['currency'] ?? '') === 'INR'
                    && $grossPaise >= $expectedPaise
                    && ($excessPaise === 0 || $excessPaise === ($feePaise + $taxPaise));

                if (!$amountOk) {
                    AuditLogger::log('PAYMENT_AMOUNT_MISMATCH', 'membership_payments', $row['id'], null, [
                        'expected_paise' => $expectedPaise, 'gateway_amount' => $p['amount'] ?? null,
                        'gateway_fee' => $p['fee'] ?? null, 'gateway_tax' => $p['tax'] ?? null,
                        'gateway_currency' => $p['currency'] ?? null, 'source' => $source,
                    ]);
                    return ['success' => false, 'error' => 'Payment amount mismatch.'];
                }

                Database::execute(
                    "UPDATE membership_payments
                     SET status = 'completed', gateway_payment_id = ?, gateway_signature = ?, paid_at = NOW(),
                         gateway_amount_paise = ?, gateway_fee_paise = ?, gateway_tax_paise = ?
                     WHERE id = ? AND status = 'pending'",
                    [$paymentId, $signature ?? '', $grossPaise, $feePaise, $taxPaise, $row['id']]
                );

                AuditLogger::log('UPDATE', 'membership_payments', $row['id'], ['status' => 'pending'], [
                    'status' => 'completed', 'gateway_payment_id' => $paymentId, 'source' => $source,
                ]);

                return ['success' => true, 'already_completed' => false, 'member_id' => (int) $row['member_id']];
            });
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation. Most likely
            // migration 012's uk_mp_completed_member_year generated-
            // column unique key, meaning a DIFFERENT payment row for
            // the same member/year was completed a moment ago by a
            // concurrent callback/webhook -- treat as an already-
            // completed duplicate, never as a hard error.
            if ($e->getCode() === '23000') {
                $row = Database::fetchOne('SELECT member_id FROM membership_payments WHERE id = ?', [$paymentRowId]);
                return ['success' => true, 'already_completed' => true, 'member_id' => $row !== false ? (int) $row['member_id'] : null];
            }
            error_log('[KSPDOWA][PaymentGateway] confirmPayment() DB error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Payment could not be confirmed due to a system error.'];
        } catch (Throwable $e) {
            error_log('[KSPDOWA][PaymentGateway] confirmPayment() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Payment could not be confirmed due to a system error.'];
        }

        if (!$result['success'] || ($result['already_completed'] ?? false) === true) {
            return $result;
        }

        // Activation happens OUTSIDE the transaction above (Auth::
        // activateMemberPortalAccess() runs its own transaction --
        // Database does not support nesting). "Generate temporary
        // password and activate login exactly once after verified
        // payment": the payment row is already locked-and-completed by
        // this point, so this call, member-login.php's, or a
        // concurrent webhook's can never issue two temporary passwords
        // for the same member (see Auth::activateMemberPortalAccess()).
        $member = Database::fetchOne(
            'SELECT m.id, m.name, mp.personal_email
             FROM members m JOIN member_profiles mp ON mp.member_id = m.id
             WHERE m.id = ?',
            [$result['member_id']]
        );

        if ($member === false || empty($member['personal_email'])) {
            error_log('[KSPDOWA][PaymentGateway] confirmPayment(): payment completed but member/email missing for member_id=' . $result['member_id']);
            $result['activation'] = ['created' => false, 'temp_password' => null];
            return $result;
        }

        $activation = Auth::activateMemberPortalAccess($member, $member['personal_email']);

        if ($activation['created'] && $activation['temp_password'] !== null) {
            $mailSent = Mailer::send(
                $member['personal_email'],
                'Payment Received -- Your ' . APP_SHORT_NAME . ' Member Portal Access',
                "Dear " . $member['name'] . ",\n\n"
                    . "Your annual membership fee payment has been received and verified.\n\n"
                    . "Your " . APP_SHORT_NAME . " member portal account has been activated.\n\n"
                    . "Registered email: " . $member['personal_email'] . "\n"
                    . "Temporary password: " . $activation['temp_password'] . "\n\n"
                    . "Sign in at " . (defined('BASE_URL') ? BASE_URL : '') . "login.php and you will be "
                    . "asked to set your own password before continuing.\n\n"
                    . "If you did not make this payment, please contact the association office immediately.\n"
            );

            if (!$mailSent) {
                error_log('[KSPDOWA][PaymentGateway] confirmPayment(): activation email failed to send for member_id=' . $member['id']);
            }
        }

        $result['activation'] = $activation;
        return $result;
    }

    // ------------------------------------------------------------------
    // Failure handling / retry (thin delegation to Registration.php,
    // which owns membership_payments row lifecycle)
    // ------------------------------------------------------------------

    public static function markFailed(int $paymentId, string $reason = ''): void
    {
        Registration::markPaymentFailed($paymentId, $reason);
    }

    public static function startNewAttempt(int $memberId): array
    {
        return Registration::startNewPaymentAttempt($memberId);
    }
}
