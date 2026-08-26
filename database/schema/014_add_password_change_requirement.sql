-- Collection Steward
-- Support administrator-issued temporary passwords and required first-login
-- password changes.
--
-- Existing users keep their current passwords and are not required to change
-- them. New application-created users receive must_change_password = 1 until
-- they choose a private replacement.
--
-- Back up the database and run this file once before uploading the PHP files
-- that use these columns.

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
        AFTER password_hash,
    ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL
        AFTER must_change_password;

