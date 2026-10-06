-- Migration v9 — cross-desk lead transfer requests
-- Run this in phpMyAdmin (SQL tab) on your existing database.
--
-- Backs the new "Request Lead" flow: when a Broker (or Owner) desk salesperson tries to add a
-- lead that already exists on the other desk, instead of a dead-end
-- duplicate message they can send a transfer request to the current owner.
-- If the owner confirms, the lead auto-reassigns; if rejected, nothing
-- changes. No duplicate lead is ever created either way.

CREATE TABLE lead_transfer_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    from_user_id INT NOT NULL,   -- who is requesting the lead
    to_user_id INT NOT NULL,     -- current owner who must confirm/reject
    status ENUM('Pending','Confirmed','Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id),
    FOREIGN KEY (to_user_id) REFERENCES users(id)
);
