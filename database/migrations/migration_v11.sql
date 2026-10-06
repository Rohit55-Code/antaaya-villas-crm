-- v11: distinguish "assigned" (new lead) vs "reassigned" (existing lead moved)
-- in assignment_log, and make notification-clear persistent server-side (per
-- user) instead of only in browser localStorage — so a cleared notification
-- stays cleared for that salesperson everywhere, while admin can still see
-- every assignment/reassignment ever logged (cleared or not) for auditing.
-- Run this once via phpMyAdmin on top of your existing database.

ALTER TABLE assignment_log
    ADD COLUMN type ENUM('assigned','reassigned') NOT NULL DEFAULT 'assigned' AFTER to_user_id;

CREATE TABLE notification_clears (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    assignment_log_id INT NOT NULL,
    cleared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_log (user_id, assignment_log_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assignment_log_id) REFERENCES assignment_log(id)
);
