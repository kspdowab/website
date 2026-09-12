-- ============================================================
-- KSPDOWA Migration 020 — District Short Codes + Per-District
-- Membership Number Serials
-- ------------------------------------------------------------
-- Adds:
--   1. districts.short_code -- a permanent 3-letter code per
--      district, approved by the association (2 examples given
--      directly: BAGALKOTE=BGK, VIJAYAPURA=VJP; the remaining 29
--      derived and explicitly confirmed with the association before
--      this migration was written). District NAMES themselves are
--      untouched -- they must continue to match docs/gp_master.json
--      exactly (per migration 001 / seeds/006_geography_master_data.sql).
--   2. districts.last_member_serial -- the highest membership-number
--      serial issued so far within that district (0 = none yet).
--      Incremented under a row lock (SELECT ... FOR UPDATE) by
--      MembershipNumber::assignIfPlaceholder() inside the same
--      transaction as the existing member-activation flow
--      (Auth::activateMemberPortalAccess()), so two concurrent
--      activations in the same district can never receive the same
--      serial. This column belongs on `districts` rather than a
--      separate counters table because the lock, the code, and the
--      counter all need to move together as one row.
--
-- Membership Number format (approved): KSPDOWA-{SHORT_CODE}-{4-digit
-- serial}, e.g. KSPDOWA-BGK-0001. Assigned into the EXISTING
-- members.member_no column -- not a new column -- and only replaces
-- that column's value when it still holds the auto-generated
-- "REG-<member id>" placeholder that Registration::register() writes
-- at self-registration time (see that method's own comment: "No
-- numbering scheme is documented for self-registration ... a
-- provisional, guaranteed-unique placeholder"). An admin-typed
-- member_no (admin/members.php's free-text field) is never touched,
-- and a member_no that has already been assigned a real number is
-- never reassigned -- "Existing Membership Numbers must not be
-- changed automatically" per the approved spec.
-- ============================================================

ALTER TABLE `districts`
  ADD COLUMN `short_code` CHAR(3) NULL COMMENT 'Permanent 3-letter code, used in Membership Numbers' AFTER `code`,
  ADD COLUMN `last_member_serial` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Highest Membership Number serial issued in this district so far' AFTER `short_code`;

-- Approved mapping (association-confirmed) -- district NAMES below
-- are copied verbatim from seeds/006_geography_master_data.sql /
-- docs/gp_master.json, not renamed or reformatted in any way.
UPDATE `districts` SET `short_code` = 'BGK' WHERE `name` = 'BAGALKOTE';
UPDATE `districts` SET `short_code` = 'BLY' WHERE `name` = 'BALLARI';
UPDATE `districts` SET `short_code` = 'BLG' WHERE `name` = 'BELAGAVI';
UPDATE `districts` SET `short_code` = 'BLR' WHERE `name` = 'BENGALURU';
UPDATE `districts` SET `short_code` = 'BNR' WHERE `name` = 'BENGALURU RURAL';
UPDATE `districts` SET `short_code` = 'BNS' WHERE `name` = 'BENGALURU SOUTH';
UPDATE `districts` SET `short_code` = 'BDR' WHERE `name` = 'BIDAR';
UPDATE `districts` SET `short_code` = 'CRN' WHERE `name` = 'CHAMARAJANAGARA';
UPDATE `districts` SET `short_code` = 'CBP' WHERE `name` = 'CHIKKABALLAPURA';
UPDATE `districts` SET `short_code` = 'CKM' WHERE `name` = 'CHIKKAMAGALURU';
UPDATE `districts` SET `short_code` = 'CTD' WHERE `name` = 'CHITRADURGA';
UPDATE `districts` SET `short_code` = 'DKK' WHERE `name` = 'DAKSHINA KANNADA';
UPDATE `districts` SET `short_code` = 'DVG' WHERE `name` = 'DAVANAGERE';
UPDATE `districts` SET `short_code` = 'DWR' WHERE `name` = 'DHARWAR';
UPDATE `districts` SET `short_code` = 'GDG' WHERE `name` = 'GADAG';
UPDATE `districts` SET `short_code` = 'HSN' WHERE `name` = 'HASSAN';
UPDATE `districts` SET `short_code` = 'HVR' WHERE `name` = 'HAVERI';
UPDATE `districts` SET `short_code` = 'KLB' WHERE `name` = 'KALABURAGI';
UPDATE `districts` SET `short_code` = 'KDG' WHERE `name` = 'KODAGU';
UPDATE `districts` SET `short_code` = 'KLR' WHERE `name` = 'KOLAR';
UPDATE `districts` SET `short_code` = 'KPL' WHERE `name` = 'KOPPAL';
UPDATE `districts` SET `short_code` = 'MDY' WHERE `name` = 'MANDYA';
UPDATE `districts` SET `short_code` = 'MYS' WHERE `name` = 'MYSURU';
UPDATE `districts` SET `short_code` = 'RCH' WHERE `name` = 'RAICHUR';
UPDATE `districts` SET `short_code` = 'SHM' WHERE `name` = 'SHIVAMOGGA';
UPDATE `districts` SET `short_code` = 'TMK' WHERE `name` = 'TUMAKURU';
UPDATE `districts` SET `short_code` = 'UDP' WHERE `name` = 'UDUPI';
UPDATE `districts` SET `short_code` = 'UTK' WHERE `name` = 'UTTARA KANNADA';
UPDATE `districts` SET `short_code` = 'VJN' WHERE `name` = 'VIJAYANAGAR';
UPDATE `districts` SET `short_code` = 'VJP' WHERE `name` = 'VIJAYAPURA';
UPDATE `districts` SET `short_code` = 'YDG' WHERE `name` = 'YADGIR';

-- Now that every existing row has a value, lock the column down:
-- NOT NULL + UNIQUE, so it is impossible to add a future district
-- without a code, or for two districts to ever share one.
ALTER TABLE `districts`
  MODIFY COLUMN `short_code` CHAR(3) NOT NULL COMMENT 'Permanent 3-letter code, used in Membership Numbers',
  ADD UNIQUE KEY `uk_district_short_code` (`short_code`);
