-- migration_v32.sql — run ONCE in phpMyAdmin, after migration_v31.sql.
--
-- (1) New optional "Villa Blocked Follow-up" stage between Unit Selection and
--     Unit Blocked — same vb_* column pattern as sv_/psv_/rv_/nv_.
-- (2) Multiple final villas per lead: `villa` now holds a comma-separated list
--     ("ALARA 1 - A1-01, ALARA 2 - A1-02"), so it's widened; per-villa offered
--     prices are kept as JSON in `villa_offers` (offered_price keeps a readable summary).
-- (3) Sales → Post Sales handover: set when the salesperson clicks
--     "Complete Sales & Transfer to Post Sales" at the end of Unit Blocked.

ALTER TABLE clients
    ADD COLUMN vb_followup_date DATE,
    ADD COLUMN vb_followup_mode VARCHAR(30),
    ADD COLUMN vb_followup_note TEXT,
    ADD COLUMN vb_refollow VARCHAR(10),
    ADD COLUMN vb_refollow_date DATE;

ALTER TABLE clients MODIFY COLUMN villa VARCHAR(500) NULL;
ALTER TABLE clients MODIFY COLUMN offered_price VARCHAR(500) NULL;
ALTER TABLE clients ADD COLUMN villa_offers TEXT NULL AFTER offered_price;
ALTER TABLE visits MODIFY COLUMN villa VARCHAR(500) NULL;

ALTER TABLE clients
    ADD COLUMN sales_handover_at DATETIME NULL,
    ADD COLUMN sales_handover_by INT NULL;
