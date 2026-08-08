-- Collection Steward
-- Create temporary post-show strike work records for assets.
--
-- Strike work is intentionally separate from descriptive tags
-- and from the asset's permanent storage location.
CREATE TABLE asset_strike_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,

    action_needed VARCHAR(100) NOT NULL,
    staging_location VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    notes TEXT NULL,

    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_asset_strike_actions_asset_id (asset_id),
    KEY idx_asset_strike_actions_status (status),

    CONSTRAINT fk_asset_strike_actions_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_asset_strike_actions_created_by
        FOREIGN KEY (created_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_asset_strike_actions_updated_by
        FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;