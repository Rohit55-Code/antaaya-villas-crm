-- Migration v8 — profile picture support
-- Run this in phpMyAdmin (SQL tab) on your existing database.

ALTER TABLE users ADD COLUMN photo VARCHAR(255) NULL AFTER desk;
