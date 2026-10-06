-- migration_v28.sql — run ONCE in phpMyAdmin, after migration_v27.sql.
-- Adds a `kind` column to `visits` so Site Visit / Re-Visit / Negotiation
-- Visit can be told apart there (previously every real logged row displayed
-- as "Site Visit" regardless of which kind it actually was). Existing rows
-- all default to 'site' since there was no way to know otherwise before now.
--
-- Also: Re-Visit Completed and Negotiation Visit now get their own real
-- `visits` rows once completed (api/clients.php — sync_revisit_visit,
-- sync_negotiation_visit), same as Site Visit already did. Negotiation
-- Visit is logged only once the lead reaches "Unit Selection" or beyond
-- (not merely "Negotiation Visit Follow-up").

ALTER TABLE visits ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'site' AFTER client_id;
