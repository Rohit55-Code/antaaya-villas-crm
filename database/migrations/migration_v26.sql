-- migration_v26.sql — run in phpMyAdmin before uploading the updated files.
-- (1) New "Negotiation Visit Follow-up" stage columns, matching the sv_/psv_/rv_ pattern.
-- (2) Renames the old "Negotiation Visit" stage to "Negotiation Visit Scheduled" on
--     existing leads, since that stage is scheduling-only now (offered price /
--     negotiation notes moved to the new follow-up stage).

ALTER TABLE clients
    ADD COLUMN nv_followup_date DATE,
    ADD COLUMN nv_followup_mode VARCHAR(30),
    ADD COLUMN nv_followup_note TEXT,
    ADD COLUMN nv_refollow VARCHAR(10),
    ADD COLUMN nv_refollow_date DATE;

UPDATE clients SET stage = 'Negotiation Visit Scheduled' WHERE stage = 'Negotiation Visit';
