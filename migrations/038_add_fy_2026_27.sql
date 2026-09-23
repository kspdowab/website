-- ============================================================
-- KSPDOWA Migration 038 — Add Financial Year 2026-27
-- ============================================================
-- Inserts the 2026-27 membership year into membership_years.
-- Fee amount defaults to 300.00 (standard annual fee for 2026-27).
-- Status is set to 'active' so it is available for payments.
-- Uses INSERT IGNORE so re-running is safe.
-- ============================================================

INSERT IGNORE INTO `membership_years`
    (`financial_year`, `start_date`, `end_date`, `fee_amount`, `status`)
VALUES
    ('2026-27', '2026-04-01', '2027-03-31', 300.00, 'active');
