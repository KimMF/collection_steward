-- Collection Steward
-- Add limited-purpose roles for intake and stewardship accounts.
--
-- Existing users are promoted to administrator so this migration
-- preserves their current access. New users default to intake-only access.

ALTER TABLE users
    ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'intake'
        AFTER display_name,
    ADD KEY idx_users_role_active (role, is_active);

UPDATE users
SET role = 'admin';
