-- ============================================================
-- KSPDOWA Seed 005 — Default News Category
--
-- news_categories had zero rows, which would have left the News
-- admin form (admin/news.php) with an empty, unusable category
-- dropdown. This is a plain organizational label, not a claim
-- about the association, so seeding one sensible default is safe
-- (unlike districts/taluks/office-bearer content, which are real
-- facts that must come from the association, not be invented).
-- More categories can be added the same way, or via a future
-- admin screen, without code changes.
-- ============================================================

INSERT IGNORE INTO `news_categories` (`name`, `status`) VALUES
  ('General', 'active');
