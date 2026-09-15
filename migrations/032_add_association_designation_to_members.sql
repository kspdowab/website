-- Migration 032: Add association_designation to members table
-- Stores custom representative or association designation (e.g. Taluk Representative, Active Member)

ALTER TABLE `members`
    ADD COLUMN `association_designation` VARCHAR(150) NULL DEFAULT NULL AFTER `designation`;
