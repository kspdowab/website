-- Migration 029: Add contact_number to office_bearers table
-- Gated by login: only displayed to authenticated members, hidden from public.

ALTER TABLE `office_bearers`
    ADD COLUMN `contact_number` VARCHAR(20) NULL DEFAULT NULL AFTER `photo_path`;
