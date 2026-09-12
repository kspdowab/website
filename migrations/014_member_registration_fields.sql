-- ============================================================
-- KSPDOWA Migration 014 — Member Registration Fields
-- Created: Phase 3 (Member Registration + Validation + Payment +
-- Membership Location Rules)
--
-- Adds the fields required by the approved self-service member
-- registration form that do not yet exist in the schema:
--   - member_profiles.kgid_no          (KGID No., unique)
--   - members.gp_working               ("Currently working in a
--                                        Gram Panchayati?" Yes/No)
--   - members.organization_type/name/address
--                                       (non-GP-working members only)
--   - members.working_district_id/working_taluk_id/working_gp_id
--                                       (the PDO's actual current
--                                        posting — kept distinct from
--                                        district_id/taluk_id/gp_id,
--                                        which now represent the
--                                        MEMBERSHIP location: either
--                                        auto-assigned+locked to the
--                                        working location, or manually
--                                        selected, per the approved
--                                        conditional rules)
--
-- gender and father_spouse_name already exist on member_profiles
-- (migration 002) and are reused as-is; personal_email/personal_mobile
-- (also migration 002/013) are reused as the registered login email
-- and phone number.
-- ============================================================

ALTER TABLE `member_profiles`
  ADD COLUMN `kgid_no` VARCHAR(50) NULL COMMENT 'Karnataka Government ID number',
  ADD UNIQUE KEY `uk_profile_kgid` (`kgid_no`);

ALTER TABLE `members`
  ADD COLUMN `gp_working` ENUM('yes','no') NULL
    COMMENT 'Registration question: currently working in a Gram Panchayati?',
  ADD COLUMN `organization_type` ENUM(
    'secretariat','rdpr','commissionerate','zilla_panchayat',
    'taluk_panchayat','mp_mla_mlc_pa','other'
  ) NULL COMMENT 'Office/Organization type (only when gp_working = no)',
  ADD COLUMN `organization_name` VARCHAR(200) NULL
    COMMENT 'Office/Organization name (only when gp_working = no)',
  ADD COLUMN `organization_address` TEXT NULL
    COMMENT 'Optional office address (only when gp_working = no)',
  ADD COLUMN `working_district_id` BIGINT UNSIGNED NULL
    COMMENT 'Actual current posting district (PDO working location)',
  ADD COLUMN `working_taluk_id` BIGINT UNSIGNED NULL
    COMMENT 'Actual current posting taluk (PDO working location)',
  ADD COLUMN `working_gp_id` BIGINT UNSIGNED NULL
    COMMENT 'Actual current posting GP, optional (PDO working location)',
  ADD KEY `idx_member_working_district` (`working_district_id`),
  ADD KEY `idx_member_working_taluk` (`working_taluk_id`),
  ADD KEY `idx_member_working_gp` (`working_gp_id`),
  ADD CONSTRAINT `fk_members_working_district`
    FOREIGN KEY (`working_district_id`) REFERENCES `districts` (`id`),
  ADD CONSTRAINT `fk_members_working_taluk`
    FOREIGN KEY (`working_taluk_id`) REFERENCES `taluks` (`id`),
  ADD CONSTRAINT `fk_members_working_gp`
    FOREIGN KEY (`working_gp_id`) REFERENCES `gram_panchayatis` (`id`);

-- Clarify the now-dual-purpose meaning of the existing location columns:
-- these are the MEMBERSHIP (association) location, auto-assigned+locked
-- to the working location for GP-working / Zilla Panchayati / Taluk
-- Panchayati members, or manually selected for other organization types.
ALTER TABLE `members`
  MODIFY COLUMN `gp_id` BIGINT UNSIGNED NULL
    COMMENT 'Membership GP (association membership unit)',
  MODIFY COLUMN `taluk_id` BIGINT UNSIGNED NULL
    COMMENT 'Membership Taluk (association membership unit)',
  MODIFY COLUMN `district_id` BIGINT UNSIGNED NULL
    COMMENT 'Membership District (association membership unit)';
