-- migration_v29.sql — run ONCE in phpMyAdmin, after migration_v28.sql.
-- Unit Blocked gets a Note field (not shown in the Lead Summary).
-- Final Villa Price now lives in the Booking Initiated stage (same column, no data change).
ALTER TABLE clients ADD COLUMN block_note TEXT NULL AFTER final_villa_price;
