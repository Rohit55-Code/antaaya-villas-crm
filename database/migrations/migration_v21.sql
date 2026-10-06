-- migration_v21.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds the "Site visit WhatsApp message sent?" flag (Yes/No) shown in the
-- Site Visit Scheduled stage and on the Lead Summary popup.

ALTER TABLE clients
  ADD COLUMN visit_whatsapp_sent VARCHAR(10) NULL AFTER visit_pickup;
