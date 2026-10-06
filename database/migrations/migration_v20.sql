-- migration_v20.sql
-- Run this once in phpMyAdmin against the live database.
-- Lets each salesperson's own Gmail send their site-visit calendar invites.
-- Stores the user's Gmail App Password (encrypted by config/mail.php).

ALTER TABLE users
  ADD COLUMN smtp_app_password VARCHAR(400) NULL;
