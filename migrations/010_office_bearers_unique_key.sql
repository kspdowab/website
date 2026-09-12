-- ============================================================
-- KSPDOWA Migration 010 — Office Bearers uniqueness guard
--
-- office_bearers had no unique key at all, so the idempotent
-- "INSERT IGNORE" pattern the seed runner relies on (see
-- seeds/run_seeds.php's own doc comment: "Seeds are idempotent
-- (INSERT IGNORE) -- safe to run multiple times") could not
-- actually prevent duplicate rows for this table -- re-running
-- seeds/004_state_office_bearers.sql would insert the same 18
-- state office bearers again on every run.
--
-- (name, association_designation, term_start) uniquely identifies
-- one person's one term in a given post for this dataset.
-- ============================================================

ALTER TABLE `office_bearers`
  ADD UNIQUE KEY `uk_ob_name_designation_term` (`name`(100), `association_designation`(100), `term_start`);
