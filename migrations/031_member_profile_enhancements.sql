-- Migration 031: Member Profile Enhancements
-- Adds Qualifications, Hobbies & Talents, Service History, and Welfare Initiatives (Housing Society & Co-Op Bank)

ALTER TABLE `member_profiles`
    ADD COLUMN `highest_qualification` VARCHAR(100) NULL DEFAULT NULL AFTER `blood_group`,
    ADD COLUMN `qualification_details` VARCHAR(255) NULL DEFAULT NULL AFTER `highest_qualification`,
    ADD COLUMN `additional_certifications` VARCHAR(255) NULL DEFAULT NULL AFTER `qualification_details`,
    ADD COLUMN `hobbies_talents` TEXT NULL DEFAULT NULL AFTER `additional_certifications`,
    ADD COLUMN `recruitment_batch` VARCHAR(50) NULL DEFAULT NULL AFTER `hobbies_talents`,
    ADD COLUMN `recruitment_type` VARCHAR(100) NULL DEFAULT NULL AFTER `recruitment_batch`,
    ADD COLUMN `native_district` VARCHAR(100) NULL DEFAULT NULL AFTER `recruitment_type`,
    ADD COLUMN `interest_housing_society` ENUM('yes', 'no', 'considering') NOT NULL DEFAULT 'considering' AFTER `native_district`,
    ADD COLUMN `housing_preferred_location` VARCHAR(150) NULL DEFAULT NULL AFTER `interest_housing_society`,
    ADD COLUMN `housing_preferred_type` VARCHAR(100) NULL DEFAULT NULL AFTER `housing_preferred_location`,
    ADD COLUMN `interest_coop_bank` ENUM('yes', 'no', 'considering') NOT NULL DEFAULT 'considering' AFTER `housing_preferred_type`,
    ADD COLUMN `coop_bank_services` VARCHAR(200) NULL DEFAULT NULL AFTER `interest_coop_bank`;
