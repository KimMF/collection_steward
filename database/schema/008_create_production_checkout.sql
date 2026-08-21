-- Collection Steward
-- Create the minimum production, cast, and checkout structure needed to
-- assign a collection asset to an actor/character for a specific show.
--
-- People are separate from cast assignments so personal measurements can be
-- added later without placing them in checkout records.

CREATE TABLE productions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    opening_date DATE NULL,
    closing_date DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'planned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_productions_status (status),
    KEY idx_productions_opening_date (opening_date)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE people (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_people_display_name (display_name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE production_cast (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    production_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    character_name VARCHAR(150) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_production_cast_production_order (
        production_id,
        display_order,
        id
    ),
    KEY idx_production_cast_person_id (person_id),

    CONSTRAINT fk_production_cast_production
        FOREIGN KEY (production_id)
        REFERENCES productions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_production_cast_person
        FOREIGN KEY (person_id)
        REFERENCES people (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE asset_checkouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    production_cast_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    notes TEXT NULL,

    checked_out_by_user_id BIGINT UNSIGNED NULL,
    checked_out_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_in_by_user_id BIGINT UNSIGNED NULL,
    checked_in_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_by_user_id BIGINT UNSIGNED NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_asset_checkouts_asset_status (asset_id, status),
    KEY idx_asset_checkouts_cast_status (production_cast_id, status),
    KEY idx_asset_checkouts_checked_out_by (checked_out_by_user_id),
    KEY idx_asset_checkouts_checked_in_by (checked_in_by_user_id),
    KEY idx_asset_checkouts_cancelled_by (cancelled_by_user_id),

    CONSTRAINT fk_asset_checkouts_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_asset_checkouts_cast
        FOREIGN KEY (production_cast_id)
        REFERENCES production_cast (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_asset_checkouts_checked_out_by
        FOREIGN KEY (checked_out_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_asset_checkouts_checked_in_by
        FOREIGN KEY (checked_in_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_asset_checkouts_cancelled_by
        FOREIGN KEY (cancelled_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
