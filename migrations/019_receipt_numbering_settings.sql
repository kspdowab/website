-- ============================================================
-- KSPDOWA Migration 019 — Receipt Numbering Settings
-- ------------------------------------------------------------
-- Makes the receipt number format admin-configurable, mirroring the
-- existing grievance_no_prefix / grievance_no_year_format /
-- grievance_no_pad_length pattern already seeded in
-- seeds/003_system_settings.sql (grievances module not yet built).
--
-- Defaults reproduce the previously hard-coded RECEIPT_PREFIX
-- constant (config/app.php) exactly: KSPDOWA-RCP-YYYY-NNNNN.
-- Receipt::generate() reads these via Settings::get() and falls back
-- to RECEIPT_PREFIX / 'Y' / '5' if a row is ever missing.
-- ============================================================

INSERT IGNORE INTO `system_settings`
  (`setting_key`, `setting_value`, `setting_type`) VALUES
  ('receipt_no_prefix',      'KSPDOWA-RCP', 'string'),
  ('receipt_no_year_format', 'Y',           'string'),
  ('receipt_no_pad_length',  '5',           'integer');
