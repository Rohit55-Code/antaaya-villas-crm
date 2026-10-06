-- migration_v13.sql — remap existing clients.stage values to the new
-- 24-point controlled stage list (New Lead redesign). Run once in
-- phpMyAdmin (Bluehost) after deploying the new index.php/app.js.
--
-- Old stage values (pre-redesign) never had "controlled" enforcement, so
-- this maps every value that could exist on a live row to its nearest
-- equivalent in the new list. Anything already matching a new stage name
-- (or already 'Closed'/'Cancelled') is left untouched.

UPDATE clients SET stage = CASE stage
    WHEN 'Called'                    THEN 'Contact Attempted'
    WHEN 'WhatsApp Sent'              THEN 'Contacted'
    WHEN 'Site Visit Done'            THEN 'Site Visit Completed'
    WHEN 'Post-Visit WhatsApp Sent'   THEN 'Follow-up'
    WHEN 'Re-Visit'                   THEN 'Re-Visit Scheduled'
    WHEN 'Negotiation Visit'          THEN 'Negotiation'
    WHEN 'Booking Pending'            THEN 'Booking Initiated'
    WHEN 'Booked'                     THEN 'Booking Confirmed'
    WHEN 'Agreement'                  THEN 'Agreement In Process'
    WHEN 'Registration'               THEN 'Registered'
    WHEN 'Payment Completed'          THEN 'Payment In Progress'
    WHEN 'Possession Pending'         THEN 'Possession Due'
    WHEN 'Possession Done'            THEN 'Handover Completed'
    ELSE stage
END;
