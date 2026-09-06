-- ============================================================
-- KSPDOWA Migration 005 — Content Tables
-- Created: Phase 0
-- Tables: news_categories, news, document_categories, documents,
--         orders, circulars, activities, events,
--         meetings, meeting_minutes, resolutions
--
-- access_level controls public/member/officer/admin visibility.
-- Server-side enforcement is mandatory — access_level alone in DB
-- does not protect content; PHP code must also check.
-- ============================================================

-- News categories
CREATE TABLE IF NOT EXISTS `news_categories` (
  `id`     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`   VARCHAR(150)     NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='News article categories';

-- News articles
CREATE TABLE IF NOT EXISTS `news` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(300)     NOT NULL,
  `slug`           VARCHAR(320)     NOT NULL,
  `content`        LONGTEXT         NOT NULL,
  `language`       ENUM('en','kn')  NOT NULL DEFAULT 'en'
                     COMMENT 'en=English, kn=Kannada',
  `category_id`    BIGINT UNSIGNED  NULL,
  `featured_image` VARCHAR(500)     NULL,
  `status`         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `published_at`   DATETIME         NULL,
  `created_by`     BIGINT UNSIGNED  NOT NULL,
  `updated_by`     BIGINT UNSIGNED  NULL,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_news_slug`      (`slug`),
  KEY `idx_news_category`  (`category_id`),
  KEY `idx_news_status`    (`status`),
  KEY `idx_news_published` (`published_at`),
  CONSTRAINT `fk_news_category`
    FOREIGN KEY (`category_id`) REFERENCES `news_categories` (`id`),
  CONSTRAINT `fk_news_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_news_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='News articles (public and member-level)';

-- Document categories
CREATE TABLE IF NOT EXISTS `document_categories` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(150)     NOT NULL,
  `access_level` ENUM('public','member','officer','admin') NOT NULL DEFAULT 'member',
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Document library categories';

-- Documents (metadata only; files stored in uploads/)
CREATE TABLE IF NOT EXISTS `documents` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(300)     NOT NULL,
  `category_id`  BIGINT UNSIGNED  NULL,
  `description`  TEXT             NULL,
  `file_path`    VARCHAR(500)     NOT NULL COMMENT 'Relative to uploads/ — never a public URL',
  `access_level` ENUM('public','member','officer','admin') NOT NULL DEFAULT 'member',
  `published_at` DATETIME         NULL,
  `uploaded_by`  BIGINT UNSIGNED  NOT NULL,
  `status`       ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_category` (`category_id`),
  KEY `idx_doc_access`   (`access_level`),
  KEY `idx_doc_status`   (`status`),
  CONSTRAINT `fk_doc_category`
    FOREIGN KEY (`category_id`) REFERENCES `document_categories` (`id`),
  CONSTRAINT `fk_doc_uploaded_by`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association documents — file served via authenticated PHP endpoint only';

-- Government orders (member-only by default per spec)
CREATE TABLE IF NOT EXISTS `orders` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(300)     NOT NULL,
  `order_no`     VARCHAR(100)     NOT NULL,
  `order_date`   DATE             NULL,
  `department`   VARCHAR(200)     NULL,
  `description`  TEXT             NULL,
  `document_id`  BIGINT UNSIGNED  NULL,
  `access_level` ENUM('public','member','officer','admin') NOT NULL DEFAULT 'member',
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no`     (`order_no`),
  KEY `idx_order_access`  (`access_level`),
  KEY `idx_order_date`    (`order_date`),
  CONSTRAINT `fk_order_document`
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Government orders (member-only per spec §4)';

-- Circulars (member-only by default per spec)
CREATE TABLE IF NOT EXISTS `circulars` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(300)     NOT NULL,
  `circular_no`   VARCHAR(100)     NOT NULL,
  `circular_date` DATE             NULL,
  `department`    VARCHAR(200)     NULL,
  `description`   TEXT             NULL,
  `document_id`   BIGINT UNSIGNED  NULL,
  `access_level`  ENUM('public','member','officer','admin') NOT NULL DEFAULT 'member',
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_circular_no`   (`circular_no`),
  KEY `idx_circular_access` (`access_level`),
  KEY `idx_circular_date`   (`circular_date`),
  CONSTRAINT `fk_circular_document`
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association circulars (member-only per spec §4)';

-- Activities (member-only by default per spec)
CREATE TABLE IF NOT EXISTS `activities` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(300)     NOT NULL,
  `description`   TEXT             NULL,
  `activity_date` DATE             NULL,
  `location`      VARCHAR(300)     NULL,
  `access_level`  ENUM('public','member','officer','admin') NOT NULL DEFAULT 'member',
  `created_by`    BIGINT UNSIGNED  NOT NULL,
  `status`        ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_access` (`access_level`),
  KEY `idx_activity_date`   (`activity_date`),
  CONSTRAINT `fk_activity_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association activities (member-only per spec §4)';

-- Events
CREATE TABLE IF NOT EXISTS `events` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(300)     NOT NULL,
  `description`  TEXT             NULL,
  `event_date`   DATETIME         NULL,
  `location`     VARCHAR(300)     NULL,
  `access_level` ENUM('public','member','officer','admin') NOT NULL DEFAULT 'member',
  `created_by`   BIGINT UNSIGNED  NOT NULL,
  `status`       ENUM('upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_date`   (`event_date`),
  KEY `idx_event_access` (`access_level`),
  KEY `idx_event_status` (`status`),
  CONSTRAINT `fk_event_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association events';

-- Meetings
CREATE TABLE IF NOT EXISTS `meetings` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(300)     NOT NULL,
  `meeting_date` DATETIME         NULL,
  `location`     VARCHAR(300)     NULL,
  `agenda`       TEXT             NULL,
  `access_level` ENUM('public','member','officer','admin') NOT NULL DEFAULT 'officer',
  `created_by`   BIGINT UNSIGNED  NOT NULL,
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_meeting_date`   (`meeting_date`),
  KEY `idx_meeting_access` (`access_level`),
  CONSTRAINT `fk_meeting_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Association meetings';

-- Meeting minutes (1:1 with meetings)
CREATE TABLE IF NOT EXISTS `meeting_minutes` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `meeting_id`  BIGINT UNSIGNED  NOT NULL,
  `content`     LONGTEXT         NOT NULL,
  `document_id` BIGINT UNSIGNED  NULL COMMENT 'Optional uploaded minutes document',
  `approved_by` BIGINT UNSIGNED  NULL,
  `approved_at` DATETIME         NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_meeting_minutes` (`meeting_id`),
  CONSTRAINT `fk_minutes_meeting`
    FOREIGN KEY (`meeting_id`)  REFERENCES `meetings`  (`id`),
  CONSTRAINT `fk_minutes_document`
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `fk_minutes_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `users`     (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Meeting minutes (1:1 with meetings)';

-- Resolutions (belong to meetings)
CREATE TABLE IF NOT EXISTS `resolutions` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `meeting_id`     BIGINT UNSIGNED  NOT NULL,
  `resolution_no`  VARCHAR(100)     NOT NULL,
  `title`          VARCHAR(300)     NOT NULL,
  `content`        TEXT             NOT NULL,
  `status`         ENUM('passed','rejected','deferred') NOT NULL DEFAULT 'passed',
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resolution_meeting` (`meeting_id`),
  CONSTRAINT `fk_resolution_meeting`
    FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Meeting resolutions';
