-- ============================================================
-- KSPDOWA Migration 027 — Office Bearer Designations Master
-- Created: Phase 4
-- Tables: office_bearer_designations
--
-- Official designations per Bye-laws:
-- State: ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 46 ರ ಪ್ರಕಾರ ರಾಜ್ಯ ಸಂಘದ ಸಮಿತಿ
-- District: ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 26 ರ ಪ್ರಕಾರ ಜಿಲ್ಲಾ ಸಂಘದ ಸಮಿತಿ
-- Taluk: ತಾಲ್ಲೂಕು ಸಂಘದ ಸಮಿತಿ
-- ============================================================

CREATE TABLE IF NOT EXISTS `office_bearer_designations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level` ENUM('state', 'district', 'taluk') NOT NULL,
    `designation_kn` VARCHAR(255) NOT NULL,
    `designation_en` VARCHAR(255) NULL,
    `seats` INT UNSIGNED NOT NULL DEFAULT 1,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_level_sort` (`level`, `sort_order`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- State Committee Designations (ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 46)
-- ------------------------------------------------------------
INSERT INTO `office_bearer_designations` (`level`, `designation_kn`, `designation_en`, `seats`, `sort_order`, `status`) VALUES
('state', 'ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ', 'President', 1, 1, 'active'),
('state', 'ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) ಸ್ಥಾನ', 'Vice President (General)', 1, 2, 'active'),
('state', 'ಉಪಾಧ್ಯಕ್ಷರ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Vice President (Women Reserved)', 1, 3, 'active'),
('state', 'ಕಾರ್ಯಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) ಸ್ಥಾನ', 'Working President (General)', 4, 4, 'active'),
('state', 'ಕಾರ್ಯಾಧ್ಯಕ್ಷರ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Working President (Women Reserved)', 4, 5, 'active'),
('state', 'ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Organizing Secretary', 4, 6, 'active'),
('state', 'ಕ್ರೀಡಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Sports Secretary', 1, 7, 'active'),
('state', 'ಸಾಂಸ್ಕೃತಿಕ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Cultural Secretary', 1, 8, 'active'),
('state', 'ಖಜಾಂಚಿ ಸ್ಥಾನ', 'Treasurer', 1, 9, 'active');

-- ------------------------------------------------------------
-- District Committee Designations (ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 26)
-- ------------------------------------------------------------
INSERT INTO `office_bearer_designations` (`level`, `designation_kn`, `designation_en`, `seats`, `sort_order`, `status`) VALUES
('district', 'ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ', 'President', 1, 1, 'active'),
('district', 'ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ', 'Vice President', 1, 2, 'active'),
('district', 'ಉಪಾಧ್ಯಕ್ಷರ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Vice President (Women Reserved)', 1, 3, 'active'),
('district', 'ಖಜಾಂಚಿ ಸ್ಥಾನ', 'Treasurer', 1, 4, 'active'),
('district', 'ರಾಜ್ಯ ಪರಿಷತ್ ಸದಸ್ಯ ಸ್ಥಾನಕ್ಕೆ', 'State Council Member', 1, 5, 'active'),
('district', 'ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Organizing Secretary', 1, 6, 'active'),
('district', 'ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Organizing Secretary (Women Reserved)', 1, 7, 'active'),
('district', 'ಕ್ರೀಡಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Sports Secretary', 1, 8, 'active'),
('district', 'ಸಾಂಸ್ಕೃತಿಕ ಕಾರ್ಯದರ್ಶಿ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Cultural Secretary (Women Reserved)', 1, 9, 'active');

-- ------------------------------------------------------------
-- Taluk Committee Designations
-- ------------------------------------------------------------
INSERT INTO `office_bearer_designations` (`level`, `designation_kn`, `designation_en`, `seats`, `sort_order`, `status`) VALUES
('taluk', 'ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ', 'President', 1, 1, 'active'),
('taluk', 'ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ', 'Vice President', 1, 2, 'active'),
('taluk', 'ಉಪಾಧ್ಯಕ್ಷರ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Vice President (Women Reserved)', 1, 3, 'active'),
('taluk', 'ಖಜಾಂಚಿ ಸ್ಥಾನ', 'Treasurer', 1, 4, 'active'),
('taluk', 'ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Organizing Secretary', 1, 5, 'active'),
('taluk', 'ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ (ಮಹಿಳಾ ಮೀಸಲು) ಸ್ಥಾನ', 'Organizing Secretary (Women Reserved)', 1, 6, 'active'),
('taluk', 'ಕ್ರೀಡಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Sports Secretary', 1, 7, 'active'),
('taluk', 'ಸಾಂಸ್ಕೃತಿಕ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ', 'Cultural Secretary', 1, 8, 'active');
