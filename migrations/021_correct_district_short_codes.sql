-- ============================================================
-- KSPDOWA Migration 021 — Correct District Short Codes
--
-- Migration 020 assigned district short codes, but 12 of the 31 did
-- not match the user-approved list, and 3 of those 12 (the Bengaluru
-- cluster) genuinely need a 4th letter to stay distinct:
--   BENGALURU        -> BLRU
--   BENGALURU RURAL  -> BLRR
--   BENGALURU SOUTH  -> BLRS
-- So short_code is widened from CHAR(3) to CHAR(4) here (existing
-- 3-letter codes are unaffected -- CHAR just allows up to 4 now).
--
-- Safe to do now: as of this migration no district has issued any
-- real Membership Number yet (last_member_serial = 0 everywhere,
-- verified before writing this), so no already-assigned
-- KSPDOWA-XXX-NNNN number is affected by widening or by the code
-- corrections below.
--
-- Only the 12 districts whose code actually changes are touched by
-- the UPDATEs; the other 19 already match the approved list.
-- ============================================================

ALTER TABLE `districts`
  MODIFY COLUMN `short_code` CHAR(4) NOT NULL
    COMMENT 'Permanent short code (3-4 letters), used in Membership Numbers';

UPDATE `districts` SET `short_code` = 'BGV'  WHERE `name` = 'BELAGAVI';
UPDATE `districts` SET `short_code` = 'BLRU' WHERE `name` = 'BENGALURU';
UPDATE `districts` SET `short_code` = 'BLRR' WHERE `name` = 'BENGALURU RURAL';
UPDATE `districts` SET `short_code` = 'BLRS' WHERE `name` = 'BENGALURU SOUTH';
UPDATE `districts` SET `short_code` = 'CMN'  WHERE `name` = 'CHAMARAJANAGARA';
UPDATE `districts` SET `short_code` = 'CMG'  WHERE `name` = 'CHIKKAMAGALURU';
UPDATE `districts` SET `short_code` = 'CTA'  WHERE `name` = 'CHITRADURGA';
UPDATE `districts` SET `short_code` = 'DKN'  WHERE `name` = 'DAKSHINA KANNADA';
UPDATE `districts` SET `short_code` = 'DWD'  WHERE `name` = 'DHARWAR';
UPDATE `districts` SET `short_code` = 'RCR'  WHERE `name` = 'RAICHUR';
UPDATE `districts` SET `short_code` = 'SMG'  WHERE `name` = 'SHIVAMOGGA';
UPDATE `districts` SET `short_code` = 'UKN'  WHERE `name` = 'UTTARA KANNADA';
