-- ============================================================
-- KSPDOWA Migration 007 — Governance Tables
-- Created: Phase 0
-- Tables: office_bearers, constitution_versions, annual_reports
-- ============================================================

-- Office bearers (current and former)
CREATE TABLE IF NOT EXISTS `office_bearers` (
  `id`                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_id`             BIGINT UNSIGNED  NULL COMMENT 'NULL if not a registered member in system',
  `name`                  VARCHAR(200)     NOT NULL,
  `association_designation` VARCHAR(200)   NOT NULL COMMENT 'e.g. State President, District Secretary',
  `official_designation`  VARCHAR(200)     NULL COMMENT 'Government designation, e.g. PDO',
  `district_id`           BIGINT UNSIGNED  NULL,
  `taluk_id`              BIGINT UNSIGNED  NULL,
  `photo_path`            VARCHAR(500)     NULL,
  `term_start`            DATE             NULL,
  `term_end`              DATE             NULL,
  `status`                ENUM('active','former') NOT NULL DEFAULT 'active',
  `sort_order`            INT UNSIGNED     NOT NULL DEFAULT 0,
  `created_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ob_status`     (`status`),
  KEY `idx_ob_district`   (`district_id`),
  KEY `idx_ob_sort`       (`sort_order`),
  CONSTRAINT `fk_ob_member`
    FOREIGN KEY (`member_id`)   REFERENCES `members`   (`id`),
  CONSTRAINT `fk_ob_district`
    FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`),
  CONSTRAINT `fk_ob_taluk`
    FOREIGN KEY (`taluk_id`)    REFERENCES `taluks`    (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association office bearers (current and historical)';

-- Constitution versions (versioned official association constitution)
CREATE TABLE IF NOT EXISTS `constitution_versions` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `version`        VARCHAR(50)      NOT NULL,
  `effective_date` DATE             NULL,
  `title`          VARCHAR(300)     NOT NULL,
  `document_id`    BIGINT UNSIGNED  NULL COMMENT 'References documents table for the actual file',
  `status`         ENUM('active','superseded','draft') NOT NULL DEFAULT 'draft',
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cv_status` (`status`),
  CONSTRAINT `fk_cv_document`
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned association constitution documents';

-- Annual reports
CREATE TABLE IF NOT EXISTS `annual_reports` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `financial_year` VARCHAR(20)      NOT NULL COMMENT 'e.g. 2024-25',
  `title`          VARCHAR(300)     NOT NULL,
  `document_id`    BIGINT UNSIGNED  NULL,
  `status`         ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ar_year` (`financial_year`),
  CONSTRAINT `fk_ar_document`
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association annual reports';
