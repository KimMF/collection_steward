-- Collection Steward
-- Add a flexible human-readable size description to assets.
--
-- Size is intentionally free-form for the pilot.
-- It supports rough sorting of costumes rather than calculated fitting.

ALTER TABLE assets
    ADD COLUMN size_description VARCHAR(100) NULL
    AFTER materials;