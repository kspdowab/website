-- ============================================================
-- KSPDOWA Migration 039 — Add Mobile & Address to Donations
-- Adds mandatory donor_mobile and optional donor_address columns
-- to the donations table.
-- ============================================================

ALTER TABLE `donations`
  ADD COLUMN `donor_mobile` VARCHAR(20) NOT NULL DEFAULT ''
    COMMENT 'Mandatory 10-digit Indian mobile number of the donor'
    AFTER `donor_name`,
  ADD COLUMN `donor_address` TEXT NULL
    COMMENT 'Optional postal address or location of the donor'
    AFTER `donor_mobile`,
  ADD KEY `idx_donation_mobile` (`donor_mobile`);
