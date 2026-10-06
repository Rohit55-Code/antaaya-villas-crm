-- migration_v34.sql — OPTIONAL, run once in phpMyAdmin (no schema change).
--
-- Villa Inventory now shows a villa as SOLD once its lead reaches Registered (and stays sold
-- through Construction / Payment / Possession), and BOOKED from Booking Confirmed until then.
-- New saves apply this automatically; this one-off just brings villas of leads that were
-- already past registration from "booked" to "sold" right away.

UPDATE villas v
JOIN clients c ON c.id = v.linked_client_id
SET v.status = 'sold'
WHERE v.status = 'booked'
  AND c.stage IN ('Registered', 'Construction Customer', 'Payment In Progress',
                  'Possession Due', 'Possession Offered', 'Handover Completed');
