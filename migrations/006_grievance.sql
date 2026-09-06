-- ============================================================
-- KSPDOWA Migration 006 — Grievance Tables
-- Created: Phase 0
-- Tables: grievance_categories, grievance_services,
--         grievance_authorities, grievances,
--         grievance_assignments, grievance_events,
--         grievance_documents
--
-- LOCKED ARCHITECTURE (03_GRIEVANCE_WORKFLOW.md §1):
--   One Master Grievance + Hierarchical Access +
--   Controlled Forwarding + Immutable Timeline + Authority Tracking
--
-- CRITICAL RULES:
--   1. grievance_events rows MUST NEVER be edited or deleted.
--   2. Forwarding MUST NOT create a new grievance — update the
--      existing record and create a grievance_event.
--   3. grievance_no is unique and permanent throughout the lifecycle.
--   4. Grievance number format: KSPDOWA-GRV-YYYY-NNNNN
-- ============================================================

-- Grievance categories (configurable by admin per spec §3)
CREATE TABLE IF NOT EXISTS `grievance_categories` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200)     NOT NULL,
  `description` TEXT             NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order`  INT UNSIGNED     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_gc_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grievance service categories (configurable)';

-- Grievance services within categories (configurable by admin per spec §3)
CREATE TABLE IF NOT EXISTS `grievance_services` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED  NOT NULL,
  `name`        VARCHAR(200)     NOT NULL,
  `description` TEXT             NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order`  INT UNSIGNED     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_gs_category`   (`category_id`),
  KEY `idx_gs_status_sort`(`status`, `sort_order`),
  CONSTRAINT `fk_gs_category`
    FOREIGN KEY (`category_id`) REFERENCES `grievance_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grievance services within categories (configurable)';

-- Government authority hierarchy (per spec §4)
-- Hierarchy is self-referencing via parent_id.
CREATE TABLE IF NOT EXISTS `grievance_authorities` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `parent_id`      BIGINT UNSIGNED  NULL COMMENT 'NULL = root authority',
  `authority_type` VARCHAR(100)     NOT NULL,
  `name`           VARCHAR(200)     NOT NULL,
  `code`           VARCHAR(50)      NOT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_authority_code` (`code`),
  KEY `idx_ga_parent` (`parent_id`),
  KEY `idx_ga_status` (`status`),
  CONSTRAINT `fk_ga_parent`
    FOREIGN KEY (`parent_id`) REFERENCES `grievance_authorities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Government authority hierarchy for grievance tracking';

-- Master grievance record (one permanent record per complaint)
CREATE TABLE IF NOT EXISTS `grievances` (
  `id`                         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `grievance_no`               VARCHAR(50)      NOT NULL
                                 COMMENT 'Format: KSPDOWA-GRV-YYYY-NNNNN — permanent, never changes',
  `member_id`                  BIGINT UNSIGNED  NOT NULL,
  `category_id`                BIGINT UNSIGNED  NOT NULL,
  `service_id`                 BIGINT UNSIGNED  NOT NULL,
  `subject`                    VARCHAR(500)     NOT NULL,
  `description`                TEXT             NOT NULL,
  `current_association_level`  ENUM('member','taluk','district','state') NOT NULL DEFAULT 'taluk',
  `current_assignee`           BIGINT UNSIGNED  NULL COMMENT 'Currently responsible officer user_id',
  `current_authority`          BIGINT UNSIGNED  NULL COMMENT 'Current government authority being pursued',
  `current_status`             VARCHAR(50)      NOT NULL DEFAULT 'Submitted'
                                 COMMENT 'Submitted|Under Verification|Accepted|Under Review|'
                                         'Forwarded|Pending|Clarification Required|'
                                         'Action Taken|Resolved|Rejected|Closed|Reopened',
  `submitted_at`               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `closed_at`                  DATETIME         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grievance_no`       (`grievance_no`),
  KEY `idx_grv_member`   (`member_id`),
  KEY `idx_grv_status`   (`current_status`),
  KEY `idx_grv_level`    (`current_association_level`),
  KEY `idx_grv_assignee` (`current_assignee`),
  KEY `idx_grv_authority`(`current_authority`),
  CONSTRAINT `fk_grv_member`
    FOREIGN KEY (`member_id`)       REFERENCES `members`              (`id`),
  CONSTRAINT `fk_grv_category`
    FOREIGN KEY (`category_id`)     REFERENCES `grievance_categories` (`id`),
  CONSTRAINT `fk_grv_service`
    FOREIGN KEY (`service_id`)      REFERENCES `grievance_services`   (`id`),
  CONSTRAINT `fk_grv_assignee`
    FOREIGN KEY (`current_assignee`)  REFERENCES `users`              (`id`),
  CONSTRAINT `fk_grv_authority`
    FOREIGN KEY (`current_authority`) REFERENCES `grievance_authorities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master grievance records — one row per complaint, never duplicated on forwarding';

-- Grievance assignments (tracks current and past responsible officers)
CREATE TABLE IF NOT EXISTS `grievance_assignments` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `grievance_id`      BIGINT UNSIGNED  NOT NULL,
  `assigned_to`       BIGINT UNSIGNED  NOT NULL,
  `association_level` ENUM('taluk','district','state') NOT NULL,
  `assigned_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at`       DATETIME         NULL COMMENT 'Set when assignment is transferred/released',
  `is_current`        TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_ga_grievance` (`grievance_id`),
  KEY `idx_ga_assignee`  (`assigned_to`),
  KEY `idx_ga_current`   (`is_current`),
  CONSTRAINT `fk_ga_grievance`
    FOREIGN KEY (`grievance_id`) REFERENCES `grievances` (`id`),
  CONSTRAINT `fk_ga_assignee`
    FOREIGN KEY (`assigned_to`)  REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grievance assignment history';

-- Grievance events — IMMUTABLE timeline (per spec §10)
-- NEVER edit or delete rows from this table.
CREATE TABLE IF NOT EXISTS `grievance_events` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `grievance_id`      BIGINT UNSIGNED  NOT NULL,
  `performed_by`      BIGINT UNSIGNED  NOT NULL,
  `association_level` ENUM('member','taluk','district','state') NOT NULL,
  `event_type`        VARCHAR(100)     NOT NULL
                        COMMENT 'SUBMIT|VERIFY|ACCEPT|REVIEW|FORWARD|ESCALATE|ASSIGN|'
                                'REMARK|STATUS_CHANGE|CLOSE|REOPEN|DOCUMENT_UPLOAD',
  `old_status`        VARCHAR(50)      NULL,
  `new_status`        VARCHAR(50)      NULL,
  `old_authority`     BIGINT UNSIGNED  NULL,
  `new_authority`     BIGINT UNSIGNED  NULL,
  `remarks`           TEXT             NULL,
  `is_member_visible` TINYINT(1)       NOT NULL DEFAULT 0
                        COMMENT '1 = member can see this remark in their timeline view',
  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ge_grievance`    (`grievance_id`),
  KEY `idx_ge_performed_by` (`performed_by`),
  KEY `idx_ge_created`      (`created_at`),
  CONSTRAINT `fk_ge_grievance`
    FOREIGN KEY (`grievance_id`)  REFERENCES `grievances`            (`id`),
  CONSTRAINT `fk_ge_performed_by`
    FOREIGN KEY (`performed_by`)  REFERENCES `users`                 (`id`),
  CONSTRAINT `fk_ge_old_authority`
    FOREIGN KEY (`old_authority`) REFERENCES `grievance_authorities` (`id`),
  CONSTRAINT `fk_ge_new_authority`
    FOREIGN KEY (`new_authority`) REFERENCES `grievance_authorities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IMMUTABLE grievance event timeline — never edit or delete rows';

-- Grievance documents (supporting documents uploaded at any stage)
CREATE TABLE IF NOT EXISTS `grievance_documents` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `grievance_id`      BIGINT UNSIGNED  NOT NULL,
  `event_id`          BIGINT UNSIGNED  NULL COMMENT 'Linked event if uploaded during an action',
  `uploaded_by`       BIGINT UNSIGNED  NOT NULL,
  `document_type`     VARCHAR(100)     NOT NULL DEFAULT 'attachment',
  `file_path`         VARCHAR(500)     NOT NULL COMMENT 'Relative to uploads/ — served via auth PHP endpoint',
  `original_filename` VARCHAR(255)     NOT NULL,
  `uploaded_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gd_grievance`  (`grievance_id`),
  KEY `idx_gd_event`      (`event_id`),
  KEY `idx_gd_uploader`   (`uploaded_by`),
  CONSTRAINT `fk_gd_grievance`
    FOREIGN KEY (`grievance_id`) REFERENCES `grievances`        (`id`),
  CONSTRAINT `fk_gd_event`
    FOREIGN KEY (`event_id`)     REFERENCES `grievance_events`  (`id`),
  CONSTRAINT `fk_gd_uploaded_by`
    FOREIGN KEY (`uploaded_by`)  REFERENCES `users`             (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grievance supporting documents (served via authenticated endpoint)';
