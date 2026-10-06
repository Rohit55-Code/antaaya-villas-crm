-- migration_v12.sql — Villa Inventory table
-- Run this once in phpMyAdmin (Bluehost) after all earlier migrations.
--
-- `name`   = display name used on the website (masterlayout.html data-name)  e.g. "ALARA 1"
-- `serial` = unit serial used on the website (masterlayout.html data-type)   e.g. "A1-01"
-- Salespeople in the CRM may type EITHER the name or the serial into a
-- client's Villa field — api/clients.php resolves whichever was typed back
-- to this table's `name` before writing status, so the two never drift.

CREATE TABLE villas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    serial VARCHAR(50) NOT NULL UNIQUE,
    villa_type ENUM('rhs','lhs','combo') NOT NULL,
    status ENUM('available','blocked','negotiation','booked','sold') NOT NULL DEFAULT 'available',
    linked_client_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (linked_client_id) REFERENCES clients(id) ON DELETE SET NULL
);

INSERT INTO villas (name, serial, villa_type, status) VALUES
('ALARA 1',  'A1-01',  'rhs',   'available'),
('ALARA 2',  'A1-02',  'lhs',   'available'),
('ALARA 3',  'A2-01',  'rhs',   'available'),
('ALARA 4',  'A2-02',  'lhs',   'available'),
('ALARA 5',  'A3-01',  'rhs',   'sold'),
('ALARA 6',  'A3-02',  'lhs',   'sold'),
('EMIRA 1',  'A4-1,2', 'combo', 'available'),
('ALARA 9',  'A5-01',  'lhs',   'sold'),
('ALARA 8',  'A5-02',  'rhs',   'sold'),
('EMIRA 2',  'A6-1,2', 'combo', 'available'),
('ALARA 10', 'A7-01',  'rhs',   'available'),
('ALARA 11', 'A7-02',  'lhs',   'sold'),
('ALARA 12', 'A8-01',  'rhs',   'sold'),
('ALARA 13', 'A8-02',  'lhs',   'sold'),
('ALARA 14', 'A9-01',  'rhs',   'available'),
('ALARA 15', 'A9-02',  'lhs',   'available'),
('EMIRA 3',  'A10-1,2','combo', 'sold'),
('ALARA 16', 'A11-01', 'lhs',   'sold'),
('ALARA 17', 'A11-02', 'rhs',   'sold'),
('ALARA 7',  'B1-01',  'rhs',   'available');
-- NOTE: the current live "soldout"/"available" statuses on masterlayout.html
-- were copied in above as a starting point. Re-check against the live site
-- before going live with this table, in case either side has moved on since.
