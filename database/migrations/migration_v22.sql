-- migration_v22.sql
-- Run this once in phpMyAdmin against the live database.
-- 1) Renames the "Negotiation" stage to "Negotiation Visit" (and gives it a visit date/time).
-- 2) Adds calendar-invite + WhatsApp "sent" tracking for Re-Visit Scheduled and Negotiation Visit,
--    same as Site Visit Scheduled has (migration_v19 / v21).

UPDATE clients SET stage = 'Negotiation Visit' WHERE stage = 'Negotiation';
-- keep old Lead History wording in step with the new stage name
UPDATE clients SET activity_log = REPLACE(activity_log, 'Moved to Negotiation"', 'Moved to Negotiation Visit"')
  WHERE activity_log LIKE '%Moved to Negotiation"%';

ALTER TABLE clients
  ADD COLUMN negotiation_visit_date DATE NULL,
  ADD COLUMN negotiation_visit_time TIME NULL,
  ADD COLUMN revisit_whatsapp_sent VARCHAR(10) NULL,
  ADD COLUMN negotiation_whatsapp_sent VARCHAR(10) NULL,
  ADD COLUMN revisit_invite_uid VARCHAR(80) NULL,
  ADD COLUMN revisit_invite_seq INT NOT NULL DEFAULT 0,
  ADD COLUMN revisit_invite_sent_at DATETIME NULL,
  ADD COLUMN revisit_invite_for VARCHAR(80) NULL,
  ADD COLUMN negotiation_invite_uid VARCHAR(80) NULL,
  ADD COLUMN negotiation_invite_seq INT NOT NULL DEFAULT 0,
  ADD COLUMN negotiation_invite_sent_at DATETIME NULL,
  ADD COLUMN negotiation_invite_for VARCHAR(80) NULL;
