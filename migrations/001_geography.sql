-- ============================================================
-- KSPDOWA Migration 001 — Geography Tables
-- Created: Phase 0
-- Tables: districts, taluks, gram_panchayatis
--
-- NOTE: Actual Karnataka district/taluk/GP data is NOT seeded here.
-- Geography master data must be imported by the association
-- from verified official records. Do not invent geography.
-- ============================================================

-- Districts
CREATE TABLE IF NOT EXISTS `districts` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)     NOT NULL,
  `code`       VARCHAR(20)      NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_district_code` (`code`),
  KEY `idx_district_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Karnataka districts';

-- Taluks (belong to districts)
CREATE TABLE IF NOT EXISTS `taluks` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `district_id` BIGINT UNSIGNED  NOT NULL,
  `name`        VARCHAR(100)     NOT NULL,
  `code`        VARCHAR(20)      NOT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_taluk_code` (`code`),
  KEY `idx_taluk_district` (`district_id`),
  KEY `idx_taluk_status`   (`status`),
  CONSTRAINT `fk_taluks_district`
    FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Taluks within Karnataka districts';

-- Gram Panchayatis (belong to taluks)
CREATE TABLE IF NOT EXISTS `gram_panchayatis` (
  `id`        BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `taluk_id`  BIGINT UNSIGNED  NOT NULL,
  `name`      VARCHAR(150)     NOT NULL,
  `code`      VARCHAR(20)      NOT NULL,
  `status`    ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gp_code` (`code`),
  KEY `idx_gp_taluk`  (`taluk_id`),
  KEY `idx_gp_status` (`status`),
  CONSTRAINT `fk_gps_taluk`
    FOREIGN KEY (`taluk_id`) REFERENCES `taluks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Gram Panchayatis within Karnataka taluks';
