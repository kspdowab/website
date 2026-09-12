-- ============================================================
-- KSPDOWA Migration 015 — Razorpay Standard Checkout + Idempotency
-- Created: Phase 3 (Member Registration + Razorpay Checkout +
-- Idempotency unit)
--
-- Approved spec (chat instruction, this unit):
--   "Generate cryptographically secure UUID v4 idempotency_key for
--    every NEW payment attempt. Store it with UNIQUE constraint."
--   "Store gateway_order_id and gateway_payment_id in existing
--    payment records."
--
-- membership_payments already has gateway_order_id/gateway_payment_id
-- (migration 004) with no uniqueness constraint. This migration:
--
--   1. Adds idempotency_key: one UUID v4 per payment ATTEMPT. This
--      project already models "one attempt" as "one membership_payments
--      row" (see includes/Registration.php's retryExistingRegistration()
--      — a failed/refunded row is left untouched as history and a
--      fresh row is created for a new attempt), so idempotency_key
--      is a column on that same row rather than a new table.
--
--   2. Adds gateway_signature to store the Razorpay HMAC signature
--      that was verified for the completed payment, for audit only
--      -- it is re-verified from scratch on every callback/webhook,
--      never trusted from storage.
--
--   3. Adds UNIQUE keys on idempotency_key, gateway_order_id and
--      gateway_payment_id (NULL allowed unlimited times -- most rows
--      have no gateway interaction yet, or none at all for very old
--      rows created before this migration). This is a DB-level
--      backstop, in addition to application-level locking, against
--      two payment rows ever being driven by the same Razorpay order
--      or payment, and against the same Razorpay payment ID being
--      applied twice.
--
-- Combined with the existing migration 012 generated-column unique
-- key (`uk_mp_completed_member_year`), the database now enforces:
--   - at most one 'completed' payment per member per year (012)
--   - at most one payment row per idempotency key / gateway order /
--     gateway payment (this migration)
-- ============================================================

ALTER TABLE `membership_payments`
  ADD COLUMN `idempotency_key` CHAR(36) NULL
    COMMENT 'UUID v4 identifying one payment attempt (this row). Generated once when the row is created; never reused across attempts.'
    AFTER `id`,
  ADD COLUMN `gateway_signature` VARCHAR(255) NULL
    COMMENT 'Razorpay HMAC signature verified for this payment (audit only -- always re-verified, never trusted from storage)'
    AFTER `gateway_payment_id`,
  ADD UNIQUE KEY `uk_mp_idempotency_key` (`idempotency_key`),
  ADD UNIQUE KEY `uk_mp_gateway_order_id` (`gateway_order_id`),
  ADD UNIQUE KEY `uk_mp_gateway_payment_id` (`gateway_payment_id`);
