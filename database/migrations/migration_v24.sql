-- migration_v24.sql
-- Run this once in phpMyAdmin against the live database.
-- 1) Renames the "Follow-up" stage to "Post Site Visit Follow-up". It now carries the same details
--    as "Site Visit Follow-up" (date, mode, re-follow-up, discussion note); leads currently in the
--    old stage have their Next Follow-up date / notes copied over so nothing is lost.
-- 2) Adds the new "Re-Visit Follow-up" stage's fields (sits after Re-Visit Scheduled).

ALTER TABLE clients
  ADD COLUMN psv_followup_date DATE NULL,
  ADD COLUMN psv_followup_mode VARCHAR(30) NULL,
  ADD COLUMN psv_followup_note TEXT NULL,
  ADD COLUMN psv_refollow VARCHAR(10) NULL,
  ADD COLUMN psv_refollow_date DATE NULL,
  ADD COLUMN rv_followup_date DATE NULL,
  ADD COLUMN rv_followup_mode VARCHAR(30) NULL,
  ADD COLUMN rv_followup_note TEXT NULL,
  ADD COLUMN rv_refollow VARCHAR(10) NULL,
  ADD COLUMN rv_refollow_date DATE NULL;

-- Carry the old stage's data into its new fields (must run before the rename below).
UPDATE clients SET psv_followup_date = nextfollow_date, psv_followup_note = nextaction WHERE stage = 'Follow-up';

-- Label the follow-up rows that stage had auto-created, so they show the new type.
UPDATE followups f JOIN clients c ON c.id = f.client_id
  SET f.followup_type = 'Post Site Visit Follow-up'
  WHERE c.stage = 'Follow-up' AND f.followup_type IS NULL
    AND f.discussion = '(Auto-synced from lead''s Next Follow-up field)';

UPDATE clients SET stage = 'Post Site Visit Follow-up' WHERE stage = 'Follow-up';
-- keep old Lead History wording in step with the new stage name
UPDATE clients SET activity_log = REPLACE(activity_log, 'Moved to Follow-up"', 'Moved to Post Site Visit Follow-up"')
  WHERE activity_log LIKE '%Moved to Follow-up"%';
