-- Antaaya Villas CRM — v7: Broker Reference category + dedicated broker fields
-- Run this against the live Bluehost DB (phpMyAdmin -> SQL tab -> paste -> Go).
--
-- Purpose: a lead can now carry its own broker's name/contact/email as
-- separate fields (instead of being crammed into subsource), and the
-- category can distinguish "Broker" (broker's own visit, no client) from
-- "Broker Reference" (a client referred by a named broker).

ALTER TABLE clients
    MODIFY COLUMN category ENUM('Broker','Broker Reference','Owner') NULL,
    ADD COLUMN broker_name VARCHAR(150) AFTER category,
    ADD COLUMN broker_contact VARCHAR(20) AFTER broker_name,
    ADD COLUMN broker_email VARCHAR(150) AFTER broker_contact;
