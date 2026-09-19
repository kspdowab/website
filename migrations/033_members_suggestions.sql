-- ============================================================
-- KSPDOWA Migration 033 — Members' Suggestions to Association
-- ============================================================
-- Implements Section 28 Specification:
--   Separate authenticated Member feature: "Members' Suggestions to Association"
--   - Distinct from Grievances (separate workflow, numbering, lifecycle, tables)
--   - Tables: suggestions, suggestion_events, suggestion_documents
--   - Configurable numbering (default: KSPDOWA-SUG-YYYY-NNNNN)
--   - RBAC permissions & role assignments
--   - System settings defaults
-- ============================================================

-- 1. Master suggestions table
CREATE TABLE IF NOT EXISTS `suggestions` (
  `id`                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `suggestion_no`         VARCHAR(50)      NOT NULL
                            COMMENT 'Format: KSPDOWA-SUG-YYYY-NNNNN — permanent reference number',
  `member_id`             BIGINT UNSIGNED  NOT NULL,
  `subject`               VARCHAR(500)     NOT NULL,
  `description`           TEXT             NOT NULL,
  `current_status`        VARCHAR(50)      NOT NULL DEFAULT 'Submitted'
                            COMMENT 'Submitted|Under Review|Accepted|Under Consideration|Implemented|Not Accepted|Closed',
  `association_response`  TEXT             NULL
                            COMMENT 'Official public response from the Association visible to member',
  `responded_by`          BIGINT UNSIGNED  NULL
                            COMMENT 'Officer user_id who provided the response',
  `responded_at`          DATETIME         NULL,
  `admin_notes`           TEXT             NULL
                            COMMENT 'Internal remarks — never visible to ordinary members',
  `submitted_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `closed_at`             DATETIME         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_suggestion_no` (`suggestion_no`),
  KEY `idx_sug_member`     (`member_id`),
  KEY `idx_sug_status`     (`current_status`),
  KEY `idx_sug_submitted`  (`submitted_at`),
  KEY `idx_sug_responder`  (`responded_by`),
  CONSTRAINT `fk_sug_member`
    FOREIGN KEY (`member_id`)    REFERENCES `members` (`id`),
  CONSTRAINT `fk_sug_responder`
    FOREIGN KEY (`responded_by`) REFERENCES `users`   (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Members suggestions, ideas and recommendations';

-- 2. Suggestion events — IMMUTABLE timeline
-- NEVER edit or delete rows from this table.
CREATE TABLE IF NOT EXISTS `suggestion_events` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `suggestion_id`     BIGINT UNSIGNED  NOT NULL,
  `performed_by`      BIGINT UNSIGNED  NOT NULL,
  `event_type`        VARCHAR(100)     NOT NULL
                        COMMENT 'SUBMIT|STATUS_CHANGE|REVIEW|RESPONSE|NOTE|CLOSE',
  `old_status`        VARCHAR(50)      NULL,
  `new_status`        VARCHAR(50)      NULL,
  `remarks`           TEXT             NULL,
  `is_member_visible` TINYINT(1)       NOT NULL DEFAULT 0
                        COMMENT '1 = member can see this remark in their timeline view',
  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_se_suggestion`   (`suggestion_id`),
  KEY `idx_se_performed_by` (`performed_by`),
  KEY `idx_se_created`      (`created_at`),
  CONSTRAINT `fk_se_suggestion`
    FOREIGN KEY (`suggestion_id`) REFERENCES `suggestions` (`id`),
  CONSTRAINT `fk_se_performed_by`
    FOREIGN KEY (`performed_by`)  REFERENCES `users`       (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IMMUTABLE suggestion timeline — never edit or delete rows';

-- 3. Suggestion documents (supporting documents uploaded at submission or review)
CREATE TABLE IF NOT EXISTS `suggestion_documents` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `suggestion_id`     BIGINT UNSIGNED  NOT NULL,
  `event_id`          BIGINT UNSIGNED  NULL,
  `uploaded_by`       BIGINT UNSIGNED  NOT NULL,
  `document_type`     VARCHAR(100)     NOT NULL DEFAULT 'supporting_document',
  `file_path`         VARCHAR(500)     NOT NULL COMMENT 'Relative to uploads/ — served via auth endpoint',
  `original_filename` VARCHAR(255)     NOT NULL,
  `file_size`         INT UNSIGNED     NOT NULL DEFAULT 0,
  `mime_type`         VARCHAR(100)     NOT NULL DEFAULT '',
  `uploaded_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sd_suggestion` (`suggestion_id`),
  KEY `idx_sd_event`      (`event_id`),
  KEY `idx_sd_uploader`   (`uploaded_by`),
  CONSTRAINT `fk_sd_suggestion`
    FOREIGN KEY (`suggestion_id`) REFERENCES `suggestions`       (`id`),
  CONSTRAINT `fk_sd_event`
    FOREIGN KEY (`event_id`)      REFERENCES `suggestion_events` (`id`),
  CONSTRAINT `fk_sd_uploader`
    FOREIGN KEY (`uploaded_by`)   REFERENCES `users`             (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Suggestion supporting documents (served via authenticated endpoint)';

-- 4. Permissions for suggestions module
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`, `status`) VALUES
  ('suggestions', 'view',   'View Suggestions',   'active'),
  ('suggestions', 'create', 'Submit Suggestion',  'active'),
  ('suggestions', 'manage', 'Manage Suggestions', 'active');

-- 5. Role-Permission mappings
-- State Super Admin: gets all permissions (via CROSS JOIN or explicit insert)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  CROSS JOIN `permissions` p
  WHERE r.name = 'State Super Admin'
    AND p.module = 'suggestions';

-- Regular Member: view and create self-service permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.module = 'suggestions' AND p.action IN ('view', 'create')
  WHERE r.name = 'Regular Member';

-- State / District / Taluk Association Leadership: view and manage
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  CROSS JOIN `permissions` p
  WHERE r.name IN (
    'State President',
    'State General Secretary',
    'State Committee Member',
    'State Grievance Officer',
    'District President',
    'District Secretary',
    'District Grievance Officer',
    'Taluk President',
    'Taluk Secretary',
    'Taluk Grievance Officer'
  )
  AND p.module = 'suggestions';

-- 6. System settings for Suggestion Numbering
INSERT IGNORE INTO `system_settings`
  (`setting_key`, `setting_value`, `setting_type`) VALUES
  ('suggestion_no_prefix',      'KSPDOWA-SUG', 'string'),
  ('suggestion_no_year_format', 'Y',           'string'),
  ('suggestion_no_pad_length',  '5',           'integer');
