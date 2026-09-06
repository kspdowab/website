-- ============================================================
-- KSPDOWA Migration 008 — Notifications Table
-- Created: Phase 0
--
-- Notification delivery (email/SMS/WhatsApp) is Phase 8.
-- This table stores in-app notification records linked to users.
-- ============================================================

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED  NOT NULL,
  `type`           VARCHAR(100)     NOT NULL
                     COMMENT 'e.g. GRIEVANCE_STATUS_CHANGE, FEE_DUE, CIRCULAR_PUBLISHED',
  `title`          VARCHAR(300)     NOT NULL,
  `message`        TEXT             NOT NULL,
  `related_module` VARCHAR(100)     NULL COMMENT 'e.g. grievances, payments, circulars',
  `related_id`     BIGINT UNSIGNED  NULL COMMENT 'ID of the related record in related_module',
  `read_at`        DATETIME         NULL COMMENT 'NULL = unread',
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user`     (`user_id`),
  KEY `idx_notif_unread`   (`user_id`, `read_at`),
  KEY `idx_notif_created`  (`created_at`),
  CONSTRAINT `fk_notif_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='In-app notification records';
