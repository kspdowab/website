-- 024_member_management.sql
-- KSPDOWA Database Migration
-- Implements schema changes for comprehensive Member Management module:
-- 1. Offline membership payments
-- 2. Member lifecycle (Retirements, Terminations)
-- 3. Member location transfers

-- A. Update membership_payments to support offline records
-- The project rules explicitly forbid creating fake Razorpay IDs,
-- so we add explicit columns for offline payment records.
ALTER TABLE `membership_payments`
ADD COLUMN `payment_mode` ENUM('online', 'offline') NOT NULL DEFAULT 'online' AFTER `status`,
ADD COLUMN `offline_reference` VARCHAR(100) NULL AFTER `payment_mode`,
ADD COLUMN `offline_remarks` TEXT NULL AFTER `offline_reference`,
ADD COLUMN `offline_proof_path` VARCHAR(500) NULL AFTER `offline_remarks`;

-- Backfill existing payments to 'online' (as they are Razorpay checkouts)
UPDATE `membership_payments` SET `payment_mode` = 'online' WHERE `payment_mode` != 'online';

-- B. Member Lifecycle Events
-- Tracks state changes like RETIRED or TERMINATED, ensuring auditability
-- without overwriting the historical status blindly.
CREATE TABLE IF NOT EXISTS `member_lifecycle_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `member_id` BIGINT UNSIGNED NOT NULL,
    `action` ENUM('retired', 'terminated') NOT NULL,
    `effective_date` DATE NOT NULL,
    `reason_category` VARCHAR(100) NOT NULL,
    `remarks` TEXT NULL,
    `document_path` VARCHAR(500) NULL,
    `approved_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL,
    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure membership_status can accept the new lifecycle states
ALTER TABLE `members`
MODIFY COLUMN `membership_status` VARCHAR(50) NOT NULL DEFAULT 'active';

-- C. Member Transfers
-- Records when a member requests to transfer to a different district/taluk.
-- Approved transfers update members.district_id/taluk_id, but the old
-- values are preserved here.
CREATE TABLE IF NOT EXISTS `member_transfers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `member_id` BIGINT UNSIGNED NOT NULL,
    `old_district_id` BIGINT UNSIGNED NULL,
    `old_taluk_id` BIGINT UNSIGNED NULL,
    `old_gp_id` BIGINT UNSIGNED NULL,
    `new_district_id` BIGINT UNSIGNED NULL,
    `new_taluk_id` BIGINT UNSIGNED NULL,
    `new_gp_id` BIGINT UNSIGNED NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `requested_by` BIGINT UNSIGNED NULL,
    `approved_by` BIGINT UNSIGNED NULL,
    `reason` TEXT NULL,
    `remarks` TEXT NULL,
    `requested_at` DATETIME NOT NULL,
    `actioned_at` DATETIME NULL,
    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
    FOREIGN KEY (`old_district_id`) REFERENCES `districts` (`id`),
    FOREIGN KEY (`old_taluk_id`) REFERENCES `taluks` (`id`),
    FOREIGN KEY (`old_gp_id`) REFERENCES `gram_panchayatis` (`id`),
    FOREIGN KEY (`new_district_id`) REFERENCES `districts` (`id`),
    FOREIGN KEY (`new_taluk_id`) REFERENCES `taluks` (`id`),
    FOREIGN KEY (`new_gp_id`) REFERENCES `gram_panchayatis` (`id`),
    FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
