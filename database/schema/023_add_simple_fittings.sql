-- Collection Steward
-- Add the Version 3 pull-list and simple fitting workflow.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

CREATE TABLE fittings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    production_cast_id BIGINT UNSIGNED NOT NULL,
    production_measurement_session_id BIGINT UNSIGNED NULL,
    fitting_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_fittings_cast_date (production_cast_id, fitting_date, id),
    KEY idx_fittings_measurement_session (
        production_measurement_session_id,
        id
    ),
    KEY idx_fittings_status_date (status, fitting_date, id),
    KEY idx_fittings_created_by (created_by_user_id),
    KEY idx_fittings_completed_by (completed_by_user_id),

    CONSTRAINT fk_fittings_cast
        FOREIGN KEY (production_cast_id)
        REFERENCES production_cast (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fittings_measurement_session
        FOREIGN KEY (production_measurement_session_id)
        REFERENCES production_measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fittings_created_by
        FOREIGN KEY (created_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_fittings_completed_by
        FOREIGN KEY (completed_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE fitting_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fitting_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    outcome VARCHAR(30) NOT NULL DEFAULT 'pending',
    fitting_note TEXT NULL,
    asset_checkout_id BIGINT UNSIGNED NULL,
    added_by_user_id BIGINT UNSIGNED NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    recorded_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fitting_assets_fitting_asset (fitting_id, asset_id),
    KEY idx_fitting_assets_asset (asset_id, fitting_id),
    KEY idx_fitting_assets_outcome (fitting_id, outcome, id),
    KEY idx_fitting_assets_checkout (asset_checkout_id),
    KEY idx_fitting_assets_added_by (added_by_user_id),
    KEY idx_fitting_assets_recorded_by (recorded_by_user_id),

    CONSTRAINT fk_fitting_assets_fitting
        FOREIGN KEY (fitting_id)
        REFERENCES fittings (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fitting_assets_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fitting_assets_checkout
        FOREIGN KEY (asset_checkout_id)
        REFERENCES asset_checkouts (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fitting_assets_added_by
        FOREIGN KEY (added_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_fitting_assets_recorded_by
        FOREIGN KEY (recorded_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE fitting_asset_result_tags (
    fitting_asset_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (fitting_asset_id, tag_id),
    KEY idx_fitting_asset_result_tags_tag (tag_id, fitting_asset_id),
    KEY idx_fitting_asset_result_tags_user (recorded_by_user_id),

    CONSTRAINT fk_fitting_asset_result_tags_asset
        FOREIGN KEY (fitting_asset_id)
        REFERENCES fitting_assets (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fitting_asset_result_tags_tag
        FOREIGN KEY (tag_id)
        REFERENCES tags (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_fitting_asset_result_tags_user
        FOREIGN KEY (recorded_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


INSERT IGNORE INTO tags (name, description)
VALUES
    (
        'Needs alteration',
        'Asset requires alteration before or during production use.'
    ),
    (
        'Needs laundering',
        'Asset should be laundered before being considered ready for use.'
    ),
    (
        'Needs repair',
        'Asset requires repair or mending before being considered ready for use.'
    );
