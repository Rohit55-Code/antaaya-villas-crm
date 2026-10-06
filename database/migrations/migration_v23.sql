-- migration_v23.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds the new "Site Visit Follow-up" stage's fields to clients, and a follow-up
-- "type" label to followups (shown on the Follow-ups page + dashboard).

ALTER TABLE clients
  ADD COLUMN sv_followup_date DATE NULL,
  ADD COLUMN sv_followup_mode VARCHAR(30) NULL,
  ADD COLUMN sv_followup_note TEXT NULL,
  ADD COLUMN sv_refollow VARCHAR(10) NULL,
  ADD COLUMN sv_refollow_date DATE NULL;

ALTER TABLE followups
  ADD COLUMN followup_type VARCHAR(50) NULL AFTER mode;
