-- ============================================================
-- KSPDOWA Migration 012 — Prevent duplicate completed
-- annual-fee payments for the same member/year
--
-- Requirement (Phase 3 approved spec): "Prevent duplicate member
-- records and duplicate annual-fee records for the same
-- member/year."
--
-- A plain UNIQUE KEY on (member_id, membership_year_id) would be
-- wrong here: membership_payments intentionally allows more than
-- one row per member/year (e.g. a first attempt that failed,
-- followed by a successful retry) -- migrations/004_finance.sql's
-- own status enum ('pending','completed','failed','refunded')
-- assumes multiple rows are normal. Blocking that would make a
-- failed-then-retried payment impossible to record.
--
-- What must actually never duplicate is a *completed* (i.e.
-- verified-paid) record for the same member/year. MySQL has no
-- native partial/conditional unique index, so this uses the
-- standard MySQL idiom: a generated column that is NULL unless
-- status = 'completed' (unique keys allow unlimited NULLs), with
-- a unique key on that column.
-- ============================================================

ALTER TABLE `membership_payments`
  ADD COLUMN `completed_member_year` VARCHAR(64)
    GENERATED ALWAYS AS (
      IF(`status` = 'completed', CONCAT(`member_id`, '-', `membership_year_id`), NULL)
    ) STORED
    COMMENT 'NULL unless status=completed; enforces at most one completed payment per member per year',
  ADD UNIQUE KEY `uk_mp_completed_member_year` (`completed_member_year`);
