-- migration_v35.sql — run ONCE in phpMyAdmin, after migration_v33.sql / v34.sql.
--
-- Post Sales split into two desks:
--   Legal desk    → Booking & Legal only (Booking Initiated → Registered).
--   Accounts desk → Construction & Payment + Possession (Construction Customer → Handover Completed).
-- At Registered the Legal desk clicks "Transfer to Accounts": the stage stays Registered
-- (Construction Customer is the Accounts desk's own first step) and these columns are set.
-- Moving the lead back before Registered (admin) clears them again.

ALTER TABLE clients
    ADD COLUMN accounts_handover_at DATETIME NULL,
    ADD COLUMN accounts_handover_by INT NULL;

-- Leads that were already in Construction / Payment / Possession before this update are
-- treated as with the Accounts desk automatically (by stage) — nothing to backfill.
