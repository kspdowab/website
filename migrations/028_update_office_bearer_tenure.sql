-- ============================================================
-- KSPDOWA Migration 028 — Office Bearer Tenure Update
-- Created: Phase 4
-- Description: Sets office bearer tenure to 3 years from State
-- Association's first meeting held on 17-05-2026 (2026-05-17 to 2029-05-16).
-- ============================================================

UPDATE `office_bearers`
SET `term_start` = '2026-05-17',
    `term_end`   = '2029-05-16'
WHERE `term_start` = '2026-04-19' OR `term_start` IS NULL;
