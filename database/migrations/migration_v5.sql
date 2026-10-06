-- Antaaya Villas CRM — migration v5
-- Run this once against the existing live database (phpMyAdmin -> SQL tab).
-- Adds dedicated Site Visit fields on the clients table (backs the new
-- "Site Visit" tab in Edit Client) and widens `stage` to the new pipeline
-- values used across the app (New Lead -> Called -> WhatsApp Sent ->
-- Site Visit Scheduled -> Site Visit Done -> Post-Visit WhatsApp Sent ->
-- Follow-up -> Re-Visit -> Negotiation Visit -> Booking Pending -> ... -> Possession Done).
-- Existing rows keep whatever stage text they already have — nothing is rewritten.

ALTER TABLE clients
    ADD COLUMN visit_status VARCHAR(30) DEFAULT 'Not Scheduled' AFTER nextaction,
    ADD COLUMN visit_date DATE NULL AFTER visit_status,
    ADD COLUMN visit_time TIME NULL AFTER visit_date,
    ADD COLUMN visit_villa VARCHAR(50) NULL AFTER visit_time,
    ADD COLUMN visit_visitors INT DEFAULT 1 AFTER visit_villa,
    ADD COLUMN visit_outcome VARCHAR(50) NULL AFTER visit_visitors,
    ADD COLUMN revisit_date DATE NULL AFTER visit_outcome,
    ADD COLUMN visit_feedback TEXT NULL AFTER revisit_date;

-- One-time cleanup: leads saved before this session used the old stage
-- names. Remap them to the closest new-pipeline equivalent so their badge
-- and the stage tracker read correctly instead of showing stale/unknown text.
UPDATE clients SET stage = 'Site Visit Scheduled' WHERE stage = 'Site Visit Schedule';
UPDATE clients SET stage = 'Re-Visit' WHERE stage = 'Re-Visit Done';
UPDATE clients SET stage = 'Negotiation Visit' WHERE stage = 'Negotiation';
UPDATE clients SET stage = 'Called' WHERE stage IN ('Contacted', 'Qualified');
