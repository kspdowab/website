-- ============================================================
-- KSPDOWA Migration 009 — Audit Logs & System Settings
-- Created: Phase 0
-- Tables: audit_logs, system_settings
--
-- audit_logs: append-only — never update or delete rows.
-- system_settings: key-value store for administrator-configurable values.
-- ============================================================

-- Audit logs — append-only record of all important actions
-- Per spec §7: every important administrative action must be logged.
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED  NULL COMMENT 'NULL for unauthenticated/system events',
  `action`     VARCHAR(100)     NOT NULL COMMENT 'LOGIN|LOGOUT|CREATE|UPDATE|DELETE|ACCESS_DENIED|…',
  `module`     VARCHAR(100)     NOT NULL COMMENT 'users|members|grievances|payments|…',
  `record_id`  BIGINT UNSIGNED  NULL,
  `old_data`   JSON             NULL COMMENT 'Previous state (for UPDATE/DELETE)',
  `new_data`   JSON             NULL COMMENT 'New state (for CREATE/UPDATE)',
  `ip_address` VARCHAR(45)      NOT NULL DEFAULT '0.0.0.0',
  `user_agent` VARCHAR(255)     NOT NULL DEFAULT '',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_user`    (`user_id`),
  KEY `idx_al_module`  (`module`, `action`),
  KEY `idx_al_created` (`created_at`),
  KEY `idx_al_record`  (`module`, `record_id`),
  CONSTRAINT `fk_al_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only audit trail — never edit or delete rows';

-- System settings — administrator-configurable key-value store
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(150)     NOT NULL,
  `setting_value` TEXT             NOT NULL,
  `setting_type`  ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
  `updated_by`    BIGINT UNSIGNED  NULL,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`),
  CONSTRAINT `fk_ss_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Administrator-configurable system settings';
