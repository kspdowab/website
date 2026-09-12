-- ============================================================
-- KSPDOWA Migration 016 — Razorpay Convenience Fee Reconciliation
-- Created: Phase 3 follow-up (Razorpay Convenience Fee amount-
-- mismatch investigation and fix)
--
-- Bug this migration supports fixing:
--   KSPDOWA creates the Razorpay Order for the exact configured
--   membership fee (e.g. Rs 1,000). When the merchant's Razorpay
--   dashboard has a "Convenience Fee" (fee bearer: customer) enabled,
--   Razorpay Checkout collects an ADDITIONAL fee from the member on
--   top of the Order amount -- confirmed empirically against
--   Razorpay's live Orders/Payments API for a real captured test
--   payment:
--     GET /orders/{id}            -> amount=100000, amount_paid=100000
--     GET /orders/{id}/payments   -> amount=102000, fee=2000, tax=0
--   i.e. the Order (and its amount_paid) always reflects the
--   membership fee ALONE; the captured Payment's `amount` is the
--   TOTAL charged to the member, and Razorpay itself reports the
--   fee/tax that explains the difference. PaymentGateway::confirmPayment()
--   was comparing the Payment's raw `amount` directly against the
--   membership fee and rejecting every such payment as a "mismatch."
--
-- The fix (PaymentGateway.php) verifies
--   payment.amount - payment.fee - payment.tax == membership fee
-- using ONLY Razorpay-reported figures (KSPDOWA never computes or
-- hard-codes the fee), and always credits exactly the configured
-- membership fee toward eligibility regardless of the total charged.
--
-- This migration adds columns to record what Razorpay actually
-- reported for a completed payment, for support/reconciliation only
-- -- never used to compute membership eligibility or amounts owed.
-- ============================================================

ALTER TABLE `membership_payments`
  ADD COLUMN `gateway_amount_paise` INT UNSIGNED NULL
    COMMENT 'Actual total amount captured by Razorpay, in paise (payment.amount -- may exceed the membership fee via a Razorpay Convenience Fee). Reconciliation only.'
    AFTER `gateway_signature`,
  ADD COLUMN `gateway_fee_paise` INT UNSIGNED NULL
    COMMENT 'Razorpay-reported fee component of the captured payment, in paise (payment.fee). Reconciliation only.'
    AFTER `gateway_amount_paise`,
  ADD COLUMN `gateway_tax_paise` INT UNSIGNED NULL
    COMMENT 'Razorpay-reported tax (GST) component of the captured payment, in paise (payment.tax). Reconciliation only.'
    AFTER `gateway_fee_paise`;
