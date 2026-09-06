-- ============================================================
-- KSPDOWA Migration 004 — Finance & Membership Tables
-- Created: Phase 0
-- Tables: membership_types, membership_years, membership_payments,
--         payment_receipts, donations
--
-- Also adds the deferred FK from members.membership_type_id.
--
-- Payment gateway columns store gateway-provided identifiers only.
-- Server-side verification is mandatory (per project security spec).
-- ============================================================

-- Membership types (e.g. Regular, Life, Honorary)
CREATE TABLE IF NOT EXISTS `membership_types` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150)     NOT NULL,
  `description` TEXT             NULL,
  `fee_amount`  DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categories of association membership';

-- Add the deferred FK: members.membership_type_id → membership_types.id
ALTER TABLE `members`
  ADD CONSTRAINT `fk_members_mtype`
    FOREIGN KEY (`membership_type_id`) REFERENCES `membership_types` (`id`);

-- Financial / membership years
CREATE TABLE IF NOT EXISTS `membership_years` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `financial_year` VARCHAR(20)      NOT NULL COMMENT 'e.g. 2024-25',
  `start_date`     DATE             NOT NULL,
  `end_date`       DATE             NOT NULL,
  `fee_amount`     DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `status`         ENUM('active','inactive','closed') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_financial_year` (`financial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Annual membership fee periods';

-- Membership payment records
-- IMPORTANT: gateway_payment_id must be verified server-side before
-- marking status = 'completed'. Never trust client-supplied success signals.
CREATE TABLE IF NOT EXISTS `membership_payments` (
  `id`                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_id`           BIGINT UNSIGNED  NOT NULL,
  `membership_year_id`  BIGINT UNSIGNED  NOT NULL,
  `amount`              DECIMAL(10,2)    NOT NULL,
  `gateway_order_id`    VARCHAR(200)     NULL COMMENT 'Order/reference ID from payment gateway',
  `gateway_payment_id`  VARCHAR(200)     NULL COMMENT 'Payment ID from gateway (verified server-side)',
  `status`              ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at`             DATETIME         NULL,
  `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mp_member` (`member_id`),
  KEY `idx_mp_year`   (`membership_year_id`),
  KEY `idx_mp_status` (`status`),
  CONSTRAINT `fk_mp_member`
    FOREIGN KEY (`member_id`)          REFERENCES `members`          (`id`),
  CONSTRAINT `fk_mp_year`
    FOREIGN KEY (`membership_year_id`) REFERENCES `membership_years` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Membership fee payment records';

-- Payment receipts (generated after server-side verification)
CREATE TABLE IF NOT EXISTS `payment_receipts` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `payment_id`   BIGINT UNSIGNED  NOT NULL,
  `receipt_no`   VARCHAR(100)     NOT NULL,
  `file_path`    VARCHAR(500)     NULL COMMENT 'Path to generated PDF receipt (relative to uploads/)',
  `generated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receipt_no`      (`receipt_no`),
  UNIQUE KEY `uk_payment_receipt` (`payment_id`) COMMENT 'One receipt per payment',
  CONSTRAINT `fk_pr_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `membership_payments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Receipts for completed membership payments';

-- Donations (may be from members or non-members)
CREATE TABLE IF NOT EXISTS `donations` (
  `id`                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_id`          BIGINT UNSIGNED  NULL COMMENT 'NULL if donor is not a member',
  `donor_name`         VARCHAR(200)     NOT NULL,
  `purpose`            VARCHAR(255)     NOT NULL DEFAULT '',
  `amount`             DECIMAL(10,2)    NOT NULL,
  `gateway_payment_id` VARCHAR(200)     NULL,
  `status`             ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at`            DATETIME         NULL,
  `receipt_no`         VARCHAR(100)     NULL,
  `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_donation_member` (`member_id`),
  KEY `idx_donation_status` (`status`),
  CONSTRAINT `fk_donation_member`
    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association donation records';
