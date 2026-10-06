-- Antaaya Villas CRM — v6: Source Leads staging table
-- Run this against the live Bluehost DB (phpMyAdmin -> SQL tab -> paste -> Go).
--
-- Purpose: website/social-media form submissions land here first, completely
-- separate from `clients`. Entry Desk / Admin review + confirm each one; only
-- on "Assign" does it become a real lead (gets a Lead ID, enters clients,
-- follows the normal desk pipeline). Rejected/unconfirmed rows never touch
-- `clients` and never affect the main CRM counts or flow.

CREATE TABLE source_leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site VARCHAR(30) NOT NULL,           -- 'antaaya' or 'akruti'
    form_type VARCHAR(40) NOT NULL,      -- 'enquiry' | 'broker' | 'sitevisit' | 'akruti_contact' ...

    name VARCHAR(150),
    mobile VARCHAR(20),
    alt_mobile VARCHAR(20),
    email VARCHAR(150),
    city VARCHAR(100),
    source VARCHAR(50),
    subsource VARCHAR(100),
    budget VARCHAR(50),
    config VARCHAR(50),
    purpose VARCHAR(50),
    category ENUM('Broker','Owner') NULL,
    notes TEXT,                          -- human-readable summary of the submission
    raw_data LONGTEXT,                   -- full submitted payload as JSON (audit trail)

    status ENUM('New','Assigned','Rejected') NOT NULL DEFAULT 'New',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    assigned_lead_id INT NULL,           -- clients.id once confirmed
    assigned_salesperson_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (assigned_lead_id) REFERENCES clients(id),
    FOREIGN KEY (assigned_salesperson_id) REFERENCES users(id)
);
