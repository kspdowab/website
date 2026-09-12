-- ============================================================
-- KSPDOWA Migration 018 — Receipt Logo Settings
-- ------------------------------------------------------------
-- Adds `receipt_logo_left` / `receipt_logo_right` to system_settings
-- (paths relative to public_html/, e.g. "assets/images/abcd1234.png").
-- Lets an admin replace the receipt letterhead logos by uploading a
-- new file via admin/settings.php instead of a developer overwriting
-- a fixed asset filename. Empty by default: Receipt.php falls back
-- to the static assets/images/receipt-logo-left.png /
-- receipt-logo-right.png files (the ones cropped from the
-- association's letterhead image) until an admin uploads a
-- replacement.
-- ============================================================

INSERT IGNORE INTO `system_settings`
  (`setting_key`, `setting_value`, `setting_type`) VALUES
  ('receipt_logo_left',  '', 'string'),
  ('receipt_logo_right', '', 'string');
