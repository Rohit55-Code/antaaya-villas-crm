-- migration_v36.sql — run ONCE in phpMyAdmin, after migration_v35.sql.
--
-- User Management roles: Admin, Sales, Legal, Accounts, IT.
-- Legal / Accounts users keep their matching desk (desk = legal / accounts) — that is what
-- the Post Sales screens check — so existing Legal / Accounts desk logins just get the new role.
-- IT has full admin access (IT-only powers to be added later); only an IT user can add / change
-- / delete IT users. The chosen IT user (it@example.com — use your own) becomes the IT user.

ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sales', 'legal', 'accounts', 'it') NOT NULL DEFAULT 'sales';

UPDATE users SET role = desk WHERE desk IN ('legal', 'accounts') AND role = 'sales';

UPDATE users SET role = 'it', desk = NULL WHERE email = 'it@example.com';
