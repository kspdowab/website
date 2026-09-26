-- ============================================================
-- KSPDOWA Seed 003 — System Settings
-- Created: Phase 0
--
-- Provides safe defaults. These can be updated by the State
-- Super Admin via the admin interface (Phase 6).
--
-- Leave site_email, site_phone blank — they must be set by
-- the association with verified contact information.
-- ============================================================

INSERT IGNORE INTO `system_settings`
  (`setting_key`, `setting_value`, `setting_type`) VALUES

  -- Association identity
  ('site_name',
   'Karnataka State Panchayat Development Officer Welfare Association (R)',
   'string'),

  ('site_short_name', 'KSPDOWA BENGALURU', 'string'),

  ('site_tagline',
   'Serving Panchayat Development Officers across Karnataka',
   'string'),

  -- Contact (fill in verified association contact details)
  ('site_email',   '', 'string'),
  ('site_phone',   '', 'string'),
  ('site_website', '', 'string'),
  ('site_address', 'Bengaluru, Karnataka', 'string'),

  -- Receipt letterhead logos (paths relative to public_html/; empty =
  -- fall back to the bundled assets/images/receipt-logo-*.png files)
  ('receipt_logo_left',  '', 'string'),
  ('receipt_logo_right', '', 'string'),

  -- Receipt number format (produces KSPDOWA-RCP-YYYY-NNNNN by default)
  ('receipt_no_prefix',      'KSPDOWA-RCP', 'string'),
  ('receipt_no_year_format', 'Y',           'string'),
  ('receipt_no_pad_length',  '5',           'integer'),

  -- Grievance number format (produces KSPDOWA-GRV-YYYY-NNNNN)
  ('grievance_no_prefix',      'KSPDOWA-GRV', 'string'),
  ('grievance_no_year_format', 'Y',           'string'),
  ('grievance_no_pad_length',  '5',           'integer'),

  -- Uploads
  ('max_upload_size_mb',  '5',  'integer'),
  ('allowed_upload_types',
   '["application/pdf","image/jpeg","image/png"]',
   'json'),

  -- Maintenance mode (set to "true" to put site in maintenance)
  ('maintenance_mode', 'false', 'boolean'),

  -- Membership fee default (to be set by admin per financial year)
  ('membership_fee_default', '0', 'integer'),

  -- Pagination
  ('list_page_size', '25', 'integer');
