-- Collection Steward
-- Seed the first reusable operational tags.
--
-- These tags are added to the vocabulary only.
-- They are not assigned to any asset by this migration.

INSERT INTO tags (name, description)
VALUES
    ('Needs laundering', 'Asset should be laundered before being considered ready for use.'),
    ('Needs repair', 'Asset requires repair or mending before being considered ready for use.');