-- Migration 030: Add blood_group to member_profiles table
-- Stores member's blood group (e.g., A+, A-, B+, B-, AB+, AB-, O+, O-).

ALTER TABLE `member_profiles`
    ADD COLUMN `blood_group` VARCHAR(10) NULL DEFAULT NULL AFTER `gender`;
