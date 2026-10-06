-- migration_v15.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds a JSON activity log (used by the new "Notes" tab in Edit Client —
-- auto-logs each Contact Attempted result with date/time, plus manual notes)
-- so this log can be reused in other places later.

ALTER TABLE clients
  ADD COLUMN activity_log TEXT NULL AFTER notes;
