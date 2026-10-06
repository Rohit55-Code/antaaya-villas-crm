-- migration_v19.sql
-- Run this once in phpMyAdmin against the live database.
-- Tracks the Site Visit calendar invite emailed to the client, so a
-- reschedule updates the SAME event in their calendar (same UID, higher SEQUENCE)
-- and so the CRM knows whether the current date/time was already sent.

ALTER TABLE clients
  ADD COLUMN visit_invite_uid     VARCHAR(80)  NULL,
  ADD COLUMN visit_invite_seq     INT          NOT NULL DEFAULT 0,
  ADD COLUMN visit_invite_sent_at DATETIME     NULL,
  ADD COLUMN visit_invite_for     VARCHAR(80)  NULL; -- "YYYY-MM-DD HH:MM|email" last sent
