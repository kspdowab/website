-- ============================================================
-- KSPDOWA Migration 013 — Member login eligibility foundation
--
-- Requirements (approved Phase 3 spec — member authentication +
-- annual membership eligibility):
--
-- 1. "Login is through the member's registered email address" plus
--    "Prevent duplicate member records" together require
--    member_profiles.personal_email to be a reliable, unique
--    identifier. It has no uniqueness constraint today (migration
--    002), so two member profiles could silently share one email,
--    which would make login ambiguous and could let one member
--    reach another member's account. Fixed with a UNIQUE KEY
--    (NULL allowed unlimited times -- most members still have no
--    profile email yet).
--
-- 2. "On first login, force the member to change/reset the
--    temporary password before accessing the member portal" needs
--    somewhere to record that an account is still on its
--    system-issued temporary password. No such column exists.
--    Added: users.must_change_password (default 0).
--
-- 3. The spec's "activate account" language for a member's first
--    login implies one member has at most one login account.
--    users.member_id currently has only a non-unique index, so two
--    accounts could accidentally be created for the same member.
--    Replaced idx_user_member with a UNIQUE KEY (NULL, i.e.
--    non-member admin/officer accounts, still allowed unlimited
--    times).
--
-- No new tables. Uses the existing users / members / member_profiles
-- / membership_years / membership_payments schema throughout.
-- ============================================================

ALTER TABLE `member_profiles`
  ADD UNIQUE KEY `uk_profile_personal_email` (`personal_email`);

ALTER TABLE `users`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = must change the system-issued temporary password before member portal access'
    AFTER `password_hash`,
  DROP INDEX `idx_user_member`,
  ADD UNIQUE KEY `uk_user_member` (`member_id`);
