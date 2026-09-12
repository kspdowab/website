-- ============================================================
-- KSPDOWA Migration 011 — News Categories uniqueness guard
--
-- Same issue as migration 010: news_categories had no unique key,
-- so seeds/005_news_categories.sql's "INSERT IGNORE" would not
-- actually be idempotent -- re-running seeds would duplicate the
-- 'General' category every time.
-- ============================================================

ALTER TABLE `news_categories`
  ADD UNIQUE KEY `uk_news_categories_name` (`name`);
