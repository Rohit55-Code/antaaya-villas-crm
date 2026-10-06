-- migration_v17.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds the Brochure Sent "Post-Brochure-Sent WhatsApp Greeting" fields:
-- the drafted message text, and a Yes/No flag that gates progression to
-- the Site Visit Scheduled stage until the message is confirmed sent.

ALTER TABLE clients
  ADD COLUMN brochure_whatsapp_message TEXT NULL AFTER brochure_mode,
  ADD COLUMN brochure_whatsapp_sent VARCHAR(10) NULL AFTER brochure_whatsapp_message;
