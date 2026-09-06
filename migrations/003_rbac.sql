-- ============================================================
-- KSPDOWA Migration 003 — RBAC Tables
-- Created: Phase 0
-- Tables: association_units, roles, permissions,
--         role_permissions, user_roles
--
-- Per 06_ROLES_PERMISSIONS_MATRIX.md:
--   - Do NOT use a single is_admin flag.
--   - Role scopes: state | district | taluk | member
--   - Geographic scope enforced via association_unit_id in user_roles.
-- ============================================================

-- Association Units (state | district | taluk bodies)
CREATE TABLE IF NOT EXISTS `association_units` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `unit_type`   ENUM('state','district','taluk') NOT NULL,
  `district_id` BIGINT UNSIGNED  NULL COMMENT 'Set for district and taluk units',
  `taluk_id`    BIGINT UNSIGNED  NULL COMMENT 'Set for taluk units only',
  `name`        VARCHAR(200)     NOT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_unit_type`     (`unit_type`),
  KEY `idx_unit_district` (`district_id`),
  KEY `idx_unit_taluk`    (`taluk_id`),
  CONSTRAINT `fk_units_district`
    FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`),
  CONSTRAINT `fk_units_taluk`
    FOREIGN KEY (`taluk_id`)    REFERENCES `taluks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='State / District / Taluk association bodies for RBAC scoping';

-- Roles (as defined in 06_ROLES_PERMISSIONS_MATRIX.md)
CREATE TABLE IF NOT EXISTS `roles` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)     NOT NULL,
  `scope_type` ENUM('state','district','taluk','member') NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_name` (`name`),
  KEY `idx_role_scope`   (`scope_type`),
  KEY `idx_role_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RBAC roles';

-- Permissions (module + action pairs)
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `module` VARCHAR(100)     NOT NULL COMMENT 'e.g. grievances, members, payments',
  `action` VARCHAR(100)     NOT NULL COMMENT 'e.g. view, create, forward, manage',
  `name`   VARCHAR(200)     NOT NULL COMMENT 'Human-readable label',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_action` (`module`, `action`),
  KEY `idx_perm_module` (`module`),
  KEY `idx_perm_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RBAC permissions (module.action atoms)';

-- Role–Permission mapping
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role`
    FOREIGN KEY (`role_id`)       REFERENCES `roles`       (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission`
    FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Role-to-permission assignments';

-- User–Role mapping (with optional geographic scope)
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `role_id`             BIGINT UNSIGNED NOT NULL,
  `association_unit_id` BIGINT UNSIGNED NULL
    COMMENT 'Scopes district/taluk officers to their unit; NULL = statewide',
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_ur_unit` (`association_unit_id`),
  CONSTRAINT `fk_ur_user`
    FOREIGN KEY (`user_id`)             REFERENCES `users`             (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role`
    FOREIGN KEY (`role_id`)             REFERENCES `roles`             (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_unit`
    FOREIGN KEY (`association_unit_id`) REFERENCES `association_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User-to-role assignments with optional geographic scoping';
