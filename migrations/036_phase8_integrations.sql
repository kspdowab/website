-- ============================================================
-- KSPDOWA Migration 036 — Phase 8: Advanced Integrations
-- Created: Phase 8
--
-- Adds:
--   1. `notification_logs` table for tracking email, WhatsApp,
--      and in-app notification dispatches without storing secrets.
--   2. Default system settings for Email/SMTP and WhatsApp integrations.
-- ============================================================

CREATE TABLE IF NOT EXISTS `notification_logs` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED  NULL COMMENT 'Optional recipient user ID',
  `channel`        VARCHAR(30)      NOT NULL COMMENT 'in_app, email, whatsapp',
  `recipient`      VARCHAR(255)     NOT NULL COMMENT 'Email address, phone number, or user ID',
  `event_type`     VARCHAR(100)     NOT NULL COMMENT 'e.g. GRIEVANCE_STATUS_CHANGE, PAYMENT_RECEIPT, MEMBER_REGISTERED',
  `title`          VARCHAR(300)     NOT NULL,
  `body_preview`   TEXT             NULL COMMENT 'Truncated preview of notification message',
  `status`         VARCHAR(30)      NOT NULL DEFAULT 'logged' COMMENT 'sent, failed, logged, disabled',
  `error_message`  VARCHAR(500)     NULL,
  `related_module` VARCHAR(100)     NULL COMMENT 'grievances, payments, suggestions, members',
  `related_id`     BIGINT UNSIGNED  NULL,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_log_user`    (`user_id`),
  KEY `idx_notif_log_channel` (`channel`),
  KEY `idx_notif_log_status`  (`status`),
  KEY `idx_notif_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log for multi-channel notification dispatches';

-- Seed default configuration for Email and WhatsApp integrations
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
  ('email_notifications_enabled', 'false', 'boolean'),
  ('mail_driver',                 'log',   'string'),
  ('smtp_host',                   '',      'string'),
  ('smtp_port',                   '587',   'integer'),
  ('smtp_encryption',             'tls',   'string'),
  ('smtp_user',                   '',      'string'),
  ('smtp_pass',                   '',      'string'),
  ('mail_from_address',           'no-reply@kspdowa.org', 'string'),
  ('mail_from_name',              'KSPDOWA', 'string'),

  ('whatsapp_enabled',            'false', 'boolean'),
  ('whatsapp_driver',             'log',   'string'),
  ('whatsapp_endpoint',           'https://graph.facebook.com/v18.0', 'string'),
  ('whatsapp_phone_number_id',    '',      'string'),
  ('whatsapp_token',              '',      'string'),

  ('notif_event_membership',      'true',  'boolean'),
  ('notif_event_payment',         'true',  'boolean'),
  ('notif_event_grievance',       'true',  'boolean'),
  ('notif_event_suggestion',      'true',  'boolean'),
  ('pwa_enabled',                 'true',  'boolean');
