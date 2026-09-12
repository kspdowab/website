-- ============================================================
-- KSPDOWA Migration 022 — Set Site Tagline (header eyebrow line)
--
-- The header.php "eyebrow" line above the association's full name
-- reads from system_settings.site_tagline. Explicitly set to the
-- exact phrase requested, overwriting whatever value is currently
-- there (seed 003's original placeholder, or anything else).
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`)
VALUES ('site_tagline', 'Government-Recognized Service Association', 'string')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
