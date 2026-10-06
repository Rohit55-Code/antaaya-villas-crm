-- migration_v27.sql — run ONCE in phpMyAdmin, after migration_v26.sql.
-- Merges "Unit Shortlisted" + "Unit Selected" into one stage: "Unit Selection"
-- (shortlist a few villas, pick the final one, offered price + discussion
-- note — moved here from Negotiation Visit Follow-up, which is now a plain
-- follow-up stage like the others). Also adds "Final Villa Price" for Unit
-- Blocked.

ALTER TABLE clients
    ADD COLUMN villa_shortlist VARCHAR(255),
    ADD COLUMN final_villa_price VARCHAR(50);

-- Leads that had already reached "Unit Selected": their chosen villa is in
-- villa_selected, and their old "Unit Shortlisted" typed value is in `villa`.
-- The new merged stage's final pick lives in `villa` (unchanged column name —
-- it's what Villa Inventory booking syncs against), so swap them: old `villa`
-- becomes the shortlist text, villa_selected becomes the final `villa`.
UPDATE clients
SET villa_shortlist = villa, villa = villa_selected
WHERE villa_selected IS NOT NULL AND villa_selected <> '';

-- Leads only ever at "Unit Shortlisted" (never reached Unit Selected): carry
-- their one typed villa into the shortlist text too. Re-pick the final villa
-- explicitly in the new "Unit Selection" stage.
UPDATE clients
SET villa_shortlist = villa
WHERE (villa_selected IS NULL OR villa_selected = '') AND villa IS NOT NULL AND villa <> '';

UPDATE clients SET stage = 'Unit Selection' WHERE stage IN ('Unit Shortlisted', 'Unit Selected');
