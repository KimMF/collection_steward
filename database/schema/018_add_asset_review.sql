-- Collection Steward
-- Add the Version 3 asset-review queue and dated condition-review history.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

ALTER TABLE assets
    ADD COLUMN asset_review_status VARCHAR(30) NOT NULL DEFAULT 'pending'
        AFTER acquisition_type,
    ADD COLUMN asset_review_requested_at TIMESTAMP NULL DEFAULT NULL
        AFTER asset_review_status,
    ADD COLUMN asset_review_requested_by_user_id BIGINT UNSIGNED NULL
        AFTER asset_review_requested_at,
    ADD KEY idx_assets_review_status (asset_review_status, updated_at),
    ADD KEY idx_assets_review_requested_by (
        asset_review_requested_by_user_id
    ),
    ADD CONSTRAINT fk_assets_review_requested_by
        FOREIGN KEY (asset_review_requested_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

-- Existing assets are not placed into a large queue automatically. A steward
-- can send any of them to review from its full asset record. Assets entered
-- after this migration default to pending review.
UPDATE assets
SET asset_review_status = 'not_queued',
    asset_review_requested_at = NULL,
    asset_review_requested_by_user_id = NULL;


CREATE TABLE asset_condition_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,

    smell_result VARCHAR(30) NOT NULL DEFAULT 'not_assessed',
    smell_note TEXT NULL,
    stains_result VARCHAR(30) NOT NULL DEFAULT 'not_assessed',
    stains_note TEXT NULL,
    damage_result VARCHAR(30) NOT NULL DEFAULT 'not_assessed',
    damage_note TEXT NULL,
    wear_result VARCHAR(30) NOT NULL DEFAULT 'not_assessed',
    wear_note TEXT NULL,
    general_condition_result VARCHAR(30) NOT NULL DEFAULT 'not_assessed',
    general_condition_note TEXT NULL,
    overall_note TEXT NULL,

    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_asset_condition_reviews_asset_date (
        asset_id,
        reviewed_at,
        id
    ),
    KEY idx_asset_condition_reviews_user (reviewed_by_user_id),

    CONSTRAINT fk_asset_condition_reviews_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_asset_condition_reviews_user
        FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
