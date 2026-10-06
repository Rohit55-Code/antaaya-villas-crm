-- v10: log every lead reassignment so the person a lead lands on on gets a
-- "X assigned lead Y to you" notification (shown in the bell dropdown).
-- Run this once via phpMyAdmin on top of your existing database.

CREATE TABLE assignment_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    from_user_id INT NULL,
    to_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (to_user_id) REFERENCES users(id)
);
