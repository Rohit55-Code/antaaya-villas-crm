-- migration_v16.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds the Requirement Understood "Post-Contact WhatsApp Greeting" fields:
-- the drafted message text, and a Yes/No flag that gates progression to
-- the Brochure Sent stage until the message is confirmed sent.

ALTER TABLE clients
  ADD COLUMN req_whatsapp_message TEXT NULL AFTER finance,
  ADD COLUMN req_whatsapp_sent VARCHAR(10) NULL AFTER req_whatsapp_message;
