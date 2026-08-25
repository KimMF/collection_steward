-- Collection Steward
-- Add database-controlled intake vocabularies and pending suggestions.
--
-- Existing category, color, size_description, and name values remain intact.
-- New structured columns are nullable so existing assets require no immediate
-- migration and can be reviewed manually over time.

CREATE TABLE asset_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_types_name (name),
    KEY idx_asset_types_active_order (is_active, display_order, name),
    KEY idx_asset_types_category_id (category_id),

    CONSTRAINT fk_asset_types_category
        FOREIGN KEY (category_id)
        REFERENCES asset_categories (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE wearer_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_wearer_options_name (name),
    KEY idx_wearer_options_active_order (is_active, display_order, name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE color_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_color_options_name (name),
    KEY idx_color_options_active_order (is_active, display_order, name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE size_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_size_options_name (name),
    KEY idx_size_options_active_order (is_active, display_order, name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE length_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_length_options_name (name),
    KEY idx_length_options_active_order (is_active, display_order, name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


ALTER TABLE assets
    ADD COLUMN asset_type_id BIGINT UNSIGNED NULL
        AFTER category_id,
    ADD COLUMN wearer_option_id BIGINT UNSIGNED NULL
        AFTER asset_type_id,
    ADD COLUMN primary_color_option_id BIGINT UNSIGNED NULL
        AFTER wearer_option_id,
    ADD COLUMN size_option_id BIGINT UNSIGNED NULL
        AFTER primary_color_option_id,
    ADD COLUMN length_option_id BIGINT UNSIGNED NULL
        AFTER size_option_id,
    ADD COLUMN exact_size_label VARCHAR(100) NULL
        AFTER size_description,
    ADD KEY idx_assets_asset_type_id (asset_type_id),
    ADD KEY idx_assets_wearer_option_id (wearer_option_id),
    ADD KEY idx_assets_primary_color_option_id (primary_color_option_id),
    ADD KEY idx_assets_size_option_id (size_option_id),
    ADD KEY idx_assets_length_option_id (length_option_id),
    ADD CONSTRAINT fk_assets_asset_type
        FOREIGN KEY (asset_type_id)
        REFERENCES asset_types (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_assets_wearer_option
        FOREIGN KEY (wearer_option_id)
        REFERENCES wearer_options (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_assets_primary_color_option
        FOREIGN KEY (primary_color_option_id)
        REFERENCES color_options (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_assets_size_option
        FOREIGN KEY (size_option_id)
        REFERENCES size_options (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_assets_length_option
        FOREIGN KEY (length_option_id)
        REFERENCES length_options (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL;


CREATE TABLE vocabulary_suggestions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    vocabulary_type VARCHAR(30) NOT NULL,
    suggested_value VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    submitted_by_user_id BIGINT UNSIGNED NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    review_note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_vocabulary_suggestion_asset_type_value (
        asset_id,
        vocabulary_type,
        suggested_value
    ),
    KEY idx_vocabulary_suggestions_status_type (
        status,
        vocabulary_type,
        created_at
    ),
    KEY idx_vocabulary_suggestions_submitted_by (submitted_by_user_id),
    KEY idx_vocabulary_suggestions_reviewed_by (reviewed_by_user_id),

    CONSTRAINT fk_vocabulary_suggestions_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_vocabulary_suggestions_submitted_by
        FOREIGN KEY (submitted_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_vocabulary_suggestions_reviewed_by
        FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
