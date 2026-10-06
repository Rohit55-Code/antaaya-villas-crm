-- migration_v3.sql — run this via phpMyAdmin's SQL tab (not Import).
-- Safe to run on your existing database — only ADDS columns, doesn't drop anything.
-- Existing clients/users/followups/visits rows are untouched.

ALTER TABLE users
    ADD COLUMN desk ENUM('entry','broker','owner') NULL AFTER role;
    -- 'broker' = Broker desk, 'owner' = Owner desk, 'entry' = front-desk/entry-level, NULL = admin

ALTER TABLE clients
    ADD COLUMN category ENUM('Broker','Owner') NULL AFTER interest,
    ADD COLUMN entry_channel ENUM('Form','CRM') NOT NULL DEFAULT 'CRM' AFTER category,
    ADD COLUMN entry_type ENUM('Enquiry','Broker Visit','Site Visit','Manual') NOT NULL DEFAULT 'Manual' AFTER entry_channel,
    ADD COLUMN created_by INT NULL AFTER entry_type,
    ADD COLUMN cancelled_reason VARCHAR(255) NULL AFTER stage,
    ADD CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id);

-- Add 'Cancelled' handling note: stage is already a free-text VARCHAR(50) in your schema,
-- so 'Cancelled' can just be used as a value directly — no ALTER needed for that.

-- Set desks for your two salespeople. Replace the emails with their real login emails.
-- (Run these two lines AFTER confirming the exact emails in your users table.)
-- UPDATE users SET desk = 'broker' WHERE email = 'broker@example.com';
-- UPDATE users SET desk = 'owner'  WHERE email = 'owner@example.com';
