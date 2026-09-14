-- ============================================================
-- KSPDOWA Seed 004 — State Office Bearers (2026-2029 term)
-- Source: "KSPDOWA 26-29 Elected Representatives.pdf" — declaration
--         of unopposed election results dated 19-04-2026, issued by
--         the Association's Election Officer (Raghu R.), Bengaluru.
--
-- Term: 19-04-2026 to 19-04-2029 (document states "next three years").
-- All rows are STATE-level (district_id/taluk_id NULL). The four
-- "divisional" posts (Bengaluru/Mysuru/Belagavi/Kalaburagi) are part
-- of the state committee, not district-level entries -- Karnataka's
-- revenue divisions are not represented in the districts/taluks
-- geography tables, so the division is recorded as part of the
-- designation text rather than as district_id.
--
-- District/Taluk-level office bearers are NOT seeded here -- they
-- depend on geography master data (districts, taluks) which has not
-- been imported yet per migration 001_geography.sql's own notice.
-- Add/edit any of this through /admin/office-bearers.php.
-- ============================================================

INSERT IGNORE INTO `office_bearers`
  (`name`, `association_designation`, `term_start`, `term_end`, `status`, `sort_order`) VALUES

  ('ದಿಲೀಪ್ ಕುಮಾರ ಬಿ.ಎಂ',              'ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ',                                            '2026-05-17', '2029-05-16', 'active', 1),
  ('ರಿಯಾಜ್ ಕೆ. ಖಿಲೇದಾರ್',              'ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ)',                                '2026-05-17', '2029-05-16', 'active', 2),
  ('ದೀಪಾ.ಎನ್',                        'ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಮಹಿಳಾ ಮೀಸಲು)',                            '2026-05-17', '2029-05-16', 'active', 3),

  ('ಅಂಜಿನಪ್ಪ .ಆರ್',                   'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಬೆಂಗಳೂರು ವಿಭಾಗ',   '2026-05-17', '2029-05-16', 'active', 4),
  ('ಚೇತನ್ ಎಚ್.ಎಂ',                    'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಮೈಸೂರು ವಿಭಾಗ',     '2026-05-17', '2029-05-16', 'active', 5),
  ('ಬಸವರಾಜ ಎನ್',                      'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಕಲಬುರ್ಗಿ ವಿಭಾಗ',    '2026-05-17', '2029-05-16', 'active', 6),
  ('ಸಿದ್ಧಾರ್ಥ ಗೋಳೆ',                  'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಬೆಳಗಾವಿ ವಿಭಾಗ',     '2026-05-17', '2029-05-16', 'active', 7),

  ('ಆನುಪಮಾ ಎಂ',                       'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಮಹಿಳಾ ಮೀಸಲು) — ಕಲಬುರ್ಗಿ ವಿಭಾಗ', '2026-05-17', '2029-05-16', 'active', 8),
  ('ಕಾತ್ಯಾಯಿನಿ ಎಂ.ಕೆ',                 'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಮಹಿಳಾ ಮೀಸಲು) — ಬೆಂಗಳೂರು ವಿಭಾಗ', '2026-05-17', '2029-05-16', 'active', 9),
  ('ವಸಂತ ಎನ್',                        'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಮಹಿಳಾ ಮೀಸಲು) — ಮೈಸೂರು ವಿಭಾಗ',   '2026-05-17', '2029-05-16', 'active', 10),
  ('ಶಿವಲೀಲಾ ಅಂಗಡಿ',                   'ಉಪವಿಭಾಗವಾರು ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಮಹಿಳಾ ಮೀಸಲು) — ಬೆಳಗಾವಿ ವಿಭಾಗ',   '2026-05-17', '2029-05-16', 'active', 11),

  ('ಲಿಂಗಪ್ಪ ಮೂಲಿಮನಿ',                 'ಉಪವಿಭಾಗವಾರು ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಕಲಬುರ್ಗಿ ವಿಭಾಗ', '2026-05-17', '2029-05-16', 'active', 12),
  ('ಮೌಲಾಸಾಬ ಮದರಸಾಬ ಯಲಮನೆ',            'ಉಪವಿಭಾಗವಾರು ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಬೆಳಗಾವಿ ವಿಭಾಗ',  '2026-05-17', '2029-05-16', 'active', 13),
  ('ವೆಂಕಟೇಶ ಡಿ.ವಿ',                   'ಉಪವಿಭಾಗವಾರು ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಬೆಂಗಳೂರು ವಿಭಾಗ', '2026-05-17', '2029-05-16', 'active', 14),
  ('ಸುರೇಶ್',                          'ಉಪವಿಭಾಗವಾರು ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ) — ಮೈಸೂರು ವಿಭಾಗ',   '2026-05-17', '2029-05-16', 'active', 15),

  ('ನಂಜುಂಡ ಸ್ವಾಮಿ .ಡಿ',                'ಕ್ರೀಡಾ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ',                                    '2026-05-17', '2029-05-16', 'active', 16),
  ('ಚಿದಾನಂದ ಎಸ್',                     'ಸಾಂಸ್ಕೃತಿಕ ಕಾರ್ಯದರ್ಶಿ ಸ್ಥಾನ',                                  '2026-05-17', '2029-05-16', 'active', 17),
  ('ಶರತ್‌ಕುಮಾರ ಅಭಿಮಾನ',                'ಖಜಾಂಚಿ ಸ್ಥಾನ',                                              '2026-05-17', '2029-05-16', 'active', 18);
