<?php
/**
 * KSPDOWA — Donation Row Lifecycle
 * ============================================================
 * Phase 3 unit (approved build order: Payment History -> Receipts ->
 * Donations). Public, no-login donation flow -- approved schema
 * (docs/02_DATABASE_SCHEMA.md): donations(id, member_id NULL,
 * donor_name, purpose, amount, gateway_payment_id, status, paid_at,
 * receipt_no), with member_id explicitly nullable ("may be from
 * members or non-members"). This unit always creates member_id = NULL
 * rows -- a donor is never required to log in or be a member; linking
 * a donation to a logged-in member's account is out of scope for this
 * unit and can be added later without changing this table.
 *
 * Owns the `donations` row lifecycle only (create / retry / mark
 * failed) -- mirrors includes/Registration.php's equivalent
 * responsibilities for membership_payments, but with no member/
 * profile/eligibility rules to enforce, since a donation is not tied
 * to annual membership. All actual Razorpay order/verification logic
 * lives in DonationGateway.php, exactly as PaymentGateway.php is kept
 * separate from Registration.php.
 *
 * "Purpose" is free text entered by the donor (optional) -- no fixed
 * category list exists anywhere in the approved docs, so none is
 * invented here.
 * ============================================================
 */

declare(strict_types=1);

class Donation
{
    /**
     * Create a new pending donation row (one payment ATTEMPT). Each
     * retry after a failed attempt creates a fresh row rather than
     * reusing the old one -- same "preserve previous failed attempts"
     * convention as Registration.php's membership_payments handling.
     *
     * @param string $donorName Required, already validated by the caller.
     * @param string $purpose   Optional free text (may be '').
     * @param float  $amount    Required, already validated (> 0) by the caller.
     * @return array{success:bool, donation_id?:int, error?:string}
     */
    public static function create(string $donorName, string $purpose, float $amount): array
    {
        if ($donorName === '' || $amount <= 0) {
            return ['success' => false, 'error' => 'Please provide your name and a valid donation amount.'];
        }

        try {
            $idempotencyKey = self::newIdempotencyKey();

            Database::execute(
                "INSERT INTO donations (idempotency_key, member_id, donor_name, purpose, amount, status)
                 VALUES (?, NULL, ?, ?, ?, 'pending')",
                [$idempotencyKey, $donorName, $purpose, $amount]
            );
            $donationId = (int) Database::lastInsertId();

            AuditLogger::log('CREATE', 'donations', $donationId, null, [
                'donor_name' => $donorName,
                'purpose'    => $purpose,
                'amount'     => $amount,
            ]);

            return ['success' => true, 'donation_id' => $donationId];
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Donation] create() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not start your donation due to a system error. Please try again.'];
        }
    }

    /**
     * Start a fresh attempt after a failed/cancelled one, reusing the
     * same donor_name/purpose/amount already on record for the
     * existing row -- the donor is not asked to re-type the form.
     */
    public static function startNewAttempt(int $existingDonationId): array
    {
        $existing = Database::fetchOne('SELECT donor_name, purpose, amount FROM donations WHERE id = ?', [$existingDonationId]);
        if ($existing === false) {
            return ['success' => false, 'error' => 'Original donation attempt not found.'];
        }

        return self::create((string) $existing['donor_name'], (string) $existing['purpose'], (float) $existing['amount']);
    }

    /**
     * Mark a still-pending donation attempt as failed (declined card,
     * cancelled/dismissed Standard Checkout modal, or an explicit
     * failure callback from Razorpay). No-op if the row is not
     * currently 'pending' -- a 'completed' donation must never be
     * downgraded by a late/duplicate failure signal.
     */
    public static function markFailed(int $donationId, string $reason = ''): void
    {
        try {
            $row = Database::fetchOne('SELECT id, status FROM donations WHERE id = ?', [$donationId]);
            if ($row === false || $row['status'] !== 'pending') {
                return;
            }

            Database::execute("UPDATE donations SET status = 'failed' WHERE id = ? AND status = 'pending'", [$donationId]);

            AuditLogger::log('UPDATE', 'donations', $donationId, ['status' => 'pending'], [
                'status' => 'failed', 'reason' => $reason,
            ]);
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Donation] markFailed() failed for donation ' . $donationId . ': ' . $e->getMessage());
        }
    }

    /**
     * Generate a cryptographically secure UUID v4 -- identical scheme
     * to Registration::newIdempotencyKey(), duplicated here rather
     * than shared since that method is private to Registration.
     */
    private static function newIdempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10xx

        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
