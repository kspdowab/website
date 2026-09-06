-- ============================================================
-- KSPDOWA Migration 002 — Core User Tables
-- Created: Phase 0
-- Tables: members, users, member_profiles
--
-- Order matters: members must exist before users (FK: users.member_id).
-- member_profiles.member_id → members.id (1:1 extended profile).
--
-- NOTE: members.membership_type_id FK is deferred to migration 004
-- (after membership_types table is created).
-- ============================================================

-- Members (core association member record)
CREATE TABLE IF NOT EXISTS `members` (
  `id`                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_no`           VARCHAR(50)      NOT NULL  COMMENT 'Association-assigned member number',
  `name`                VARCHAR(200)     NOT NULL,
  `designation`         VARCHAR(150)     NOT NULL DEFAULT '',
  `gp_id`               BIGINT UNSIGNED  NULL      COMMENT 'Current posting GP',
  `taluk_id`            BIGINT UNSIGNED  NULL      COMMENT 'Current posting Taluk',
  `district_id`         BIGINT UNSIGNED  NULL      COMMENT 'Current posting District',
  `membership_type_id`  BIGINT UNSIGNED  NULL      COMMENT 'FK added in migration 004',
  `joining_date`        DATE             NULL,
  `membership_status`   VARCHAR(50)      NOT NULL DEFAULT 'active'
                          COMMENT 'active | inactive | resigned | deceased',
  `photo_path`          VARCHAR(500)     NULL,
  `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_no` (`member_no`),
  KEY `idx_member_district` (`district_id`),
  KEY `idx_member_taluk`    (`taluk_id`),
  KEY `idx_member_gp`       (`gp_id`),
  KEY `idx_member_status`   (`membership_status`),
  CONSTRAINT `fk_members_district`
    FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`),
  CONSTRAINT `fk_members_taluk`
    FOREIGN KEY (`taluk_id`) REFERENCES `taluks` (`id`),
  CONSTRAINT `fk_members_gp`
    FOREIGN KEY (`gp_id`) REFERENCES `gram_panchayatis` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association member core records';

-- Users (authentication accounts — may or may not be linked to a member)
CREATE TABLE IF NOT EXISTS `users` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_id`     BIGINT UNSIGNED  NULL      COMMENT 'NULL for non-member admin accounts',
  `username`      VARCHAR(100)     NULL,
  `email`         VARCHAR(190)     NULL,
  `mobile`        VARCHAR(20)      NULL,
  `password_hash` VARCHAR(255)     NOT NULL,
  `status`        ENUM('active','inactive','locked','pending') NOT NULL DEFAULT 'pending',
  `last_login_at` DATETIME         NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_username` (`username`),
  UNIQUE KEY `uk_user_email`    (`email`),
  UNIQUE KEY `uk_user_mobile`   (`mobile`),
  KEY `idx_user_member`   (`member_id`),
  KEY `idx_user_status`   (`status`),
  CONSTRAINT `fk_users_member`
    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Authentication accounts — linked to members where applicable';

-- Member Profiles (1:1 extended information per member)
CREATE TABLE IF NOT EXISTS `member_profiles` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_id`         BIGINT UNSIGNED  NOT NULL,
  `date_of_birth`     DATE             NULL,
  `gender`            ENUM('male','female','other') NULL,
  `father_spouse_name` VARCHAR(200)    NULL,
  `personal_address`  TEXT             NULL,
  `city`              VARCHAR(100)     NULL,
  `pin_code`          VARCHAR(10)      NULL,
  `personal_email`    VARCHAR(190)     NULL,
  `personal_mobile`   VARCHAR(20)      NULL,
  `emergency_contact_name`   VARCHAR(200) NULL,
  `emergency_contact_mobile` VARCHAR(20)  NULL,
  `bank_name`         VARCHAR(150)     NULL,
  `bank_account_no`   VARCHAR(50)      NULL,
  `bank_ifsc`         VARCHAR(20)      NULL,
  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_profile_member` (`member_id`),
  CONSTRAINT `fk_profiles_member`
    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Extended member profile information (1:1 with members)';
