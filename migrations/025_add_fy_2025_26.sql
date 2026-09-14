-- ============================================================
-- KSPDOWA Migration 025 — Add Financial Year 2025-26
-- ============================================================
-- Inserts the 2025-26 membership year into membership_years.
-- Fee amount defaults to 0.00 — update via Admin > Membership Setup.
-- Status is set to 'active' so it is available for imports/payments.
-- Uses INSERT IGNORE so re-running is safe.
-- ============================================================

INSERT IGNORE INTO `membership_years`
    (`financial_year`, `start_date`, `end_date`, `fee_amount`, `status`)
VALUES
    ('2025-26', '2025-04-01', '2026-03-31', 0.00, 'active');
