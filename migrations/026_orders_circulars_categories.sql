-- ============================================================
-- KSPDOWA Migration 026 — Orders & Circulars Categories
-- Created: Phase 4
-- Tables/Data: document_categories
--
-- Mandatory categories per Phase 4 specification:
-- 1. 16th Finance
-- 2. VB-G RAM G
-- 3. eSwathu
-- 4. eGramSwaraj
-- 5. GP Staff
-- 6. Act/Rules
-- 7. OSR
-- 8. SC/ST
-- 9. PH
-- ============================================================

INSERT IGNORE INTO `document_categories` (`name`, `access_level`, `status`) VALUES
  ('16th Finance', 'member', 'active'),
  ('VB-G RAM G',   'member', 'active'),
  ('eSwathu',      'member', 'active'),
  ('eGramSwaraj',  'member', 'active'),
  ('GP Staff',     'member', 'active'),
  ('Act/Rules',    'member', 'active'),
  ('OSR',          'member', 'active'),
  ('SC/ST',        'member', 'active'),
  ('PH',           'member', 'active');
