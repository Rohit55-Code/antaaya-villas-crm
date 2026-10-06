-- migration_v30.sql — run ONCE in phpMyAdmin, after migration_v29.sql.
--
-- The Notification Log (assignment_log table) only ever recorded lead
-- assignment/reassignment. Source Lead events (a new website/social
-- submission arriving, one being Assigned into a real lead, one being
-- Rejected) never landed there, so admin had no permanent record of them.
--
-- A source-lead event doesn't always have a real client yet (client_id is
-- NULL until it's Assigned) and a brand-new submission isn't aimed at any
-- one salesperson (to_user_id NULL too) — so both columns become nullable,
-- `type` is loosened from a fixed ENUM to plain text so new types can be
-- added without another migration, and two columns are added: a link back
-- to the source_leads row, and a plain-text label to show when there's no
-- client row to join against yet.

ALTER TABLE assignment_log MODIFY COLUMN client_id INT NULL;
ALTER TABLE assignment_log MODIFY COLUMN to_user_id INT NULL;
ALTER TABLE assignment_log MODIFY COLUMN type VARCHAR(30) NOT NULL DEFAULT 'assigned';
ALTER TABLE assignment_log ADD COLUMN source_lead_id INT NULL AFTER client_id;
ALTER TABLE assignment_log ADD COLUMN label VARCHAR(191) NULL AFTER source_lead_id;
ALTER TABLE assignment_log ADD FOREIGN KEY (source_lead_id) REFERENCES source_leads(id);
