-- Collection Steward
-- Initial inventory schema
-- File: database/schema/001_create_inventory_schema.sql
--
-- Purpose:
--   Create the first three tables needed for a small physical-asset inventory:
--   1. asset_categories
--   2. assets
--   3. asset_photos
--
-- Important:
--   Review this file before running it.
--   Run it only once against the intended database.
--   Do not place database passwords or other secrets in this file.

CREATE TABLE asset_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_categories_name (name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventory_number VARCHAR(50) NULL,
    category_id BIGINT UNSIGNED NULL,

    name VARCHAR(150) NOT NULL,
    description TEXT NULL,

    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit VARCHAR(30) NOT NULL DEFAULT 'item',

    condition_summary VARCHAR(255) NULL,
    storage_location VARCHAR(255) NULL,
    color VARCHAR(100) NULL,
    materials VARCHAR(255) NULL,
    measurements TEXT NULL,

    availability_status VARCHAR(50) NOT NULL DEFAULT 'available',
    notes TEXT NULL,

    created_by VARCHAR(100) NULL,
    updated_by VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_inventory_number (inventory_number),
    KEY idx_assets_category_id (category_id),
    KEY idx_assets_name (name),
    KEY idx_assets_storage_location (storage_location),
    KEY idx_assets_availability_status (availability_status),

    CONSTRAINT fk_assets_category
        FOREIGN KEY (category_id)
        REFERENCES asset_categories (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE asset_photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,

    file_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    uploaded_by VARCHAR(100) NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_asset_photos_asset_sort (asset_id, sort_order),

    CONSTRAINT fk_asset_photos_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
