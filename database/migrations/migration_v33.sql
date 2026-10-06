-- migration_v33.sql — run ONCE in phpMyAdmin, after migration_v32.sql.
--
-- Post Sales team desks: 'legal' and 'accounts'. Users on these desks (role = sales)
-- only see leads transferred to Post Sales and work Booking & Legal → Possession.
-- Create their logins from User Management (admin) and pick the Legal / Accounts desk.

ALTER TABLE users MODIFY COLUMN desk ENUM('entry','broker','owner','legal','accounts') NULL;
