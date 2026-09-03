-- Collection Steward
-- Add soft retirement and append-only retirement history for assets.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

ALTER TABLE assets
    ADD COLUMN collection_status VARCHAR(30) NOT NULL DEFAULT 'active'
        AFTER availability_status,
    ADD KEY idx_assets_collection_status (collection_status, name, id);


CREATE TABLE asset_lifecycle_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    disposition VARCHAR(50) NOT NULL,
    effective_date DATE NOT NULL,
    note TEXT NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_lifecycle_event (asset_id, event_type),
    KEY idx_asset_lifecycle_events_date (
        effective_date,
        id
    ),
    KEY idx_asset_lifecycle_events_user (recorded_by_user_id),

    CONSTRAINT fk_asset_lifecycle_events_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_asset_lifecycle_events_user
        FOREIGN KEY (recorded_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
