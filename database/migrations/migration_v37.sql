-- migration_v37.sql — run ONCE in phpMyAdmin, after migration_v36.sql.
--
-- Accounts desk: construction-linked payment demands.
-- Demands and payments are strictly on the Final Villa Price (the final price — no GST / TDS here;
-- GST is collected manually and only applies to stamp duty in Legal's Agreement In Process).
--
-- Construction & Payment stages are now the construction milestones of the payment schedule
-- (Percentage of total consideration — 10 / 20 / 15 / 15 / 10 / 10 / 10 / 5 / 5):
--   Construction Customer (agreement instalment) → Plinth Completed → First Slab Completed →
--   Second Slab Completed → Brickwork Completed → Plaster & Flooring Completed → Fittings Completed
--   → Possession Due (final instalment, on OC / CC) → Possession Offered → Handover Completed.
-- "Payment In Progress" is gone — payments are logged throughout, against the schedule.
--
-- New tables: one payment plan per lead, its schedule rows (one per instalment), the demands
-- raised against them and every payment received. clients.received / dueamount / duedate /
-- last_payment_date / pay_demanded are kept in sync from these by api/payments.php so the
-- dashboards and tables keep working.

CREATE TABLE IF NOT EXISTS payment_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    base_price DECIMAL(14,2) NOT NULL,          -- Final Villa Price when the schedule was made
    due_days INT NOT NULL DEFAULT 15,           -- default days to pay after a demand
    plan_name VARCHAR(60) NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_plan_client (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payment_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    seq TINYINT NOT NULL,                       -- 1 = booking (Legal), 2 = agreement … 9 = possession
    code VARCHAR(30) NOT NULL,
    label VARCHAR(191) NOT NULL,
    pct DECIMAL(6,3) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    milestone_date DATE NULL,                   -- stage of work completed on (set when its demand is raised)
    UNIQUE KEY uq_sched (client_id, seq),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payment_demands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    schedule_id INT NOT NULL,
    demand_no INT NOT NULL,
    demand_date DATE NOT NULL,
    due_date DATE NOT NULL,
    instalment DECIMAL(14,2) NOT NULL,          -- this stage's amount
    carried_forward DECIMAL(14,2) NOT NULL DEFAULT 0,  -- unpaid from earlier demands, added to this one
    advance_adjusted DECIMAL(14,2) NOT NULL DEFAULT 0, -- paid ahead, taken off this one
    total_due DECIMAL(14,2) NOT NULL,           -- instalment + carried_forward − advance_adjusted
    status VARCHAR(20) NOT NULL DEFAULT 'Open', -- Open | Withdrawn
    note TEXT NULL,
    email_sent_at DATETIME NULL,
    email_to VARCHAR(191) NULL,
    email_count INT NOT NULL DEFAULT 0,
    whatsapp_sent_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dem_client (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS client_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    demand_id INT NULL,                         -- the demand open when it was received (for reference)
    pay_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,              -- received towards the villa price
    mode VARCHAR(40) NULL,
    ref_no VARCHAR(100) NULL,
    note TEXT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'accounts',    -- booking (collected by Legal) | accounts
    doc_id INT NULL,                            -- payment proof in client_documents
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pay_client (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

ALTER TABLE clients ADD COLUMN pay_demanded DECIMAL(14,2) NULL;

-- Leads that were at the old "Payment In Progress" stage go back to Construction Customer
-- (create their payment schedule there, then move on through the construction stages).
UPDATE clients SET stage = 'Construction Customer' WHERE stage = 'Payment In Progress';
