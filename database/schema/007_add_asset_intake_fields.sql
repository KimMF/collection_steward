-- Collection Steward
-- Record when and how an asset entered the collection.
--
-- The initial application workflow uses acquisition_type = 'donation'.
-- Other acquisition types can be added later without changing this schema.

ALTER TABLE assets
    ADD COLUMN received_date DATE NULL
        AFTER notes,
    ADD COLUMN acquisition_type VARCHAR(50) NULL
        AFTER received_date,
    ADD KEY idx_assets_received_date (received_date),
    ADD KEY idx_assets_acquisition_type (acquisition_type);
