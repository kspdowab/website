-- ============================================================
-- KSPDOWA Migration 017 — Association Website Setting
-- ------------------------------------------------------------
-- Adds the `site_website` key to system_settings, alongside the
-- existing site_email / site_phone (seeds/003_system_settings.sql).
-- Needed so the payment receipt's letterhead (Phase 3 Receipts
-- unit) can show a website address that an admin can edit, rather
-- than hard-coding it in Receipt.php -- matching the existing
-- pattern for site_email/site_phone, which the seed file already
-- documents as "must be set by the association" via the admin
-- interface (admin/settings.php, added in this same unit).
--
-- INSERT IGNORE: safe to re-run, and does not disturb a value an
-- admin may already have set directly in the database.
-- ============================================================

INSERT IGNORE INTO `system_settings`
  (`setting_key`, `setting_value`, `setting_type`) VALUES
  ('site_website', '', 'string');
