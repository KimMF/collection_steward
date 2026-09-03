-- Collection Steward
-- Add non-destructive cast-assignment lifecycle support for the dedicated
-- Version 3 production-management workspace.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

ALTER TABLE production_cast
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1
        AFTER display_order,
    ADD KEY idx_production_cast_active_order (
        production_id,
        is_active,
        display_order,
        id
    );
