-- migration_v18.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds the "brochure sent after the site visit instead of before it" flag,
-- and formally documents (no schema change needed) that the Brochure Sent
-- WhatsApp "Sent?" field no longer gates the Site Visit Scheduled stage.

ALTER TABLE clients
  ADD COLUMN brochure_bypass VARCHAR(10) NULL AFTER brochure_whatsapp_sent;
