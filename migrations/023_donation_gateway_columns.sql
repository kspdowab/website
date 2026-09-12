-- ============================================================
-- KSPDOWA Migration 023 — Donations: Razorpay Gateway Columns +
-- Receipt Storage + Numbering Settings
--
-- The `donations` table (migration 004) already has the approved
-- schema columns (member_id, donor_name, purpose, amount,
-- gateway_payment_id, status, paid_at, receipt_no) but predates the
-- Razorpay Standard Checkout + Idempotency work done for
-- membership_payments (migrations 012/015/016). This migration brings
-- donations up to the same verified-payment standard, mirroring those
-- exact columns/constraints so DonationGateway.php can reuse the same
-- signature-verification + amount-reconciliation logic as
-- PaymentGateway.php:
--
--   - idempotency_key   (mig. 015 equivalent)
--   - gateway_order_id  (membership_payments had this since mig. 004;
--                        donations never did -- required to create a
--                        Razorpay Order before checkout and to look up
--                        the row again on the verify callback)
--   - gateway_signature, gateway_amount_paise, gateway_fee_paise,
--     gateway_tax_paise  (mig. 015/016 equivalents, for the same
--                        Razorpay Convenience Fee reconciliation)
--
-- Also adds receipt_file_path (donations has no separate
-- payment_receipts-style table -- the approved schema already puts
-- receipt_no directly on the donations row, so the generated PDF's
-- path is stored the same way) and a UNIQUE key on receipt_no
-- (payment_receipts.receipt_no already has one; donations.receipt_no
-- did not).
--
-- Finally, seeds donation_receipt_no_prefix/year_format/pad_length
-- into system_settings, mirroring the existing receipt_no_* pattern
-- (migration 019) so donation receipts get their own numbering
-- sequence (KSPDOWA-DON-YYYY-NNNNN by default) distinct from
-- membership receipts, and remain admin-configurable the same way.
-- ============================================================

ALTER TABLE `donations`
  ADD COLUMN `idempotency_key` CHAR(36) NULL
    COMMENT 'UUID v4 identifying one donation payment attempt (this row). Generated once when the row is created; never reused across attempts.'
    AFTER `id`,
  ADD COLUMN `gateway_order_id` VARCHAR(200) NULL
    COMMENT 'Order ID from payment gateway'
    AFTER `amount`,
  ADD COLUMN `gateway_signature` VARCHAR(255) NULL
    COMMENT 'Razorpay HMAC signature verified for this payment (audit only -- always re-verified, never trusted from storage)'
    AFTER `gateway_payment_id`,
  ADD COLUMN `gateway_amount_paise` INT UNSIGNED NULL
    COMMENT 'Actual total amount captured by Razorpay, in paise. Reconciliation only.'
    AFTER `gateway_signature`,
  ADD COLUMN `gateway_fee_paise` INT UNSIGNED NULL
    COMMENT 'Razorpay-reported fee component of the captured payment, in paise. Reconciliation only.'
    AFTER `gateway_amount_paise`,
  ADD COLUMN `gateway_tax_paise` INT UNSIGNED NULL
    COMMENT 'Razorpay-reported tax (GST) component of the captured payment, in paise. Reconciliation only.'
    AFTER `gateway_fee_paise`,
  ADD COLUMN `receipt_file_path` VARCHAR(500) NULL
    COMMENT 'Path to generated PDF receipt (relative to uploads/), mirrors payment_receipts.file_path'
    AFTER `receipt_no`,
  ADD UNIQUE KEY `uk_donation_idempotency_key`  (`idempotency_key`),
  ADD UNIQUE KEY `uk_donation_gateway_order_id` (`gateway_order_id`),
  ADD UNIQUE KEY `uk_donation_gateway_payment_id` (`gateway_payment_id`),
  ADD UNIQUE KEY `uk_donation_receipt_no` (`receipt_no`);

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
  ('donation_receipt_no_prefix',      'KSPDOWA-DON', 'string'),
  ('donation_receipt_no_year_format', 'Y',           'string'),
  ('donation_receipt_no_pad_length',  '5',           'integer');
