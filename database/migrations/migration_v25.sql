-- migration_v25.sql
-- Run this once in phpMyAdmin against the live database.
-- "Re-Visit Completed" now captures the same detail as "Site Visit Completed"
-- (Villa Shown, Number of Visitors, Post-Visit Feedback) alongside the
-- existing Re-visit Outcome field. Interest Level reuses the client's own
-- `interest` column via the existing data-interest-mirror pattern — no new
-- column needed for that.

ALTER TABLE clients
  ADD COLUMN revisit_villa VARCHAR(100) NULL AFTER revisit_outcome,
  ADD COLUMN revisit_visitors INT NULL DEFAULT 1 AFTER revisit_villa,
  ADD COLUMN revisit_feedback TEXT NULL AFTER revisit_visitors;
