-- ============================================================
-- Migration 037: Drop unique constraint on member_profiles.personal_email
--
-- In government associations like KSPDOWA, duplicate verification is
-- strictly by KGID (Karnataka Government Insurance Department number).
-- Multiple members or Gram Panchayat staff may share an email address
-- (e.g. office/GP email), so personal_email should not be unique.
-- ============================================================

ALTER TABLE `member_profiles`
  DROP INDEX `uk_profile_personal_email`,
  ADD INDEX `idx_profile_personal_email` (`personal_email`);
