-- Collection Steward
-- Add normalized actor-measurement history, flexible measurement definitions,
-- reusable measurement templates, import staging, and review views.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once, then run the private
-- legacy-data import file supplied separately.

CREATE TABLE venues (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NULL,
    name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_venues_name (name),
    UNIQUE KEY uq_venues_code (code),
    KEY idx_venues_active_name (is_active, name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


ALTER TABLE productions
    ADD COLUMN venue_id BIGINT UNSIGNED NULL AFTER name,
    ADD COLUMN production_year SMALLINT UNSIGNED NULL AFTER venue_id,
    ADD KEY idx_productions_venue_year_name (
        venue_id,
        production_year,
        name
    ),
    ADD CONSTRAINT fk_productions_venue
        FOREIGN KEY (venue_id)
        REFERENCES venues (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;

UPDATE productions
SET production_year = YEAR(opening_date)
WHERE production_year IS NULL
  AND opening_date IS NOT NULL;


ALTER TABLE people
    ADD COLUMN first_name VARCHAR(100) NULL AFTER display_name,
    ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name,
    ADD KEY idx_people_last_first (last_name, first_name);


CREATE TABLE measurement_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(150) NOT NULL,
    value_kind VARCHAR(20) NOT NULL,
    unit VARCHAR(30) NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_types_code (code),
    KEY idx_measurement_types_active_order (
        is_active,
        display_order,
        name
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    owner_name VARCHAR(150) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_templates_name_owner (name, owner_name),
    KEY idx_measurement_templates_active_name (is_active, name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_template_items (
    template_id BIGINT UNSIGNED NOT NULL,
    measurement_type_id BIGINT UNSIGNED NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    instructions TEXT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (template_id, measurement_type_id),
    KEY idx_measurement_template_items_order (
        template_id,
        display_order,
        measurement_type_id
    ),

    CONSTRAINT fk_measurement_template_items_template
        FOREIGN KEY (template_id)
        REFERENCES measurement_templates (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_template_items_type
        FOREIGN KEY (measurement_type_id)
        REFERENCES measurement_types (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_name VARCHAR(255) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    source_description TEXT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_import_batches_sha256 (source_sha256)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    person_id BIGINT UNSIGNED NOT NULL,
    production_id BIGINT UNSIGNED NULL,
    measured_on DATE NOT NULL,
    date_precision VARCHAR(20) NOT NULL DEFAULT 'day',
    session_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    measured_by_user_id BIGINT UNSIGNED NULL,
    review_status VARCHAR(30) NOT NULL DEFAULT 'unreviewed',
    notes TEXT NULL,
    source_import_batch_id BIGINT UNSIGNED NULL,
    legacy_import_key CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_sessions_event (
        person_id,
        production_id,
        measured_on,
        session_sequence
    ),
    UNIQUE KEY uq_measurement_sessions_legacy_key (legacy_import_key),
    KEY idx_measurement_sessions_person_date (person_id, measured_on),
    KEY idx_measurement_sessions_production (production_id),
    KEY idx_measurement_sessions_review (review_status),
    KEY idx_measurement_sessions_import_batch (source_import_batch_id),
    KEY idx_measurement_sessions_measured_by (measured_by_user_id),

    CONSTRAINT fk_measurement_sessions_person
        FOREIGN KEY (person_id)
        REFERENCES people (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_measurement_sessions_production
        FOREIGN KEY (production_id)
        REFERENCES productions (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_measurement_sessions_measured_by
        FOREIGN KEY (measured_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_measurement_sessions_import_batch
        FOREIGN KEY (source_import_batch_id)
        REFERENCES measurement_import_batches (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_session_templates (
    measurement_session_id BIGINT UNSIGNED NOT NULL,
    measurement_template_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (measurement_session_id, measurement_template_id),

    CONSTRAINT fk_measurement_session_templates_session
        FOREIGN KEY (measurement_session_id)
        REFERENCES measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_session_templates_template
        FOREIGN KEY (measurement_template_id)
        REFERENCES measurement_templates (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_sheet VARCHAR(150) NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    venue_text VARCHAR(150) NULL,
    resolved_venue_name VARCHAR(150) NOT NULL,
    production_text VARCHAR(150) NOT NULL,
    measurement_period_text VARCHAR(30) NOT NULL,
    measured_on DATE NOT NULL,
    character_text VARCHAR(150) NULL,
    resolved_character_name VARCHAR(150) NOT NULL,
    first_name_text VARCHAR(100) NOT NULL,
    last_name_text VARCHAR(100) NOT NULL,
    session_import_key CHAR(64) NOT NULL,
    normalized_person_id BIGINT UNSIGNED NULL,
    normalized_production_id BIGINT UNSIGNED NULL,
    normalized_measurement_session_id BIGINT UNSIGNED NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    review_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_import_rows_source (
        import_batch_id,
        source_sheet,
        source_row_number
    ),
    KEY idx_measurement_import_rows_session_key (session_import_key),
    KEY idx_measurement_import_rows_review (import_batch_id, needs_review),
    KEY idx_measurement_import_rows_person (normalized_person_id),
    KEY idx_measurement_import_rows_production (normalized_production_id),
    KEY idx_measurement_import_rows_session (
        normalized_measurement_session_id
    ),

    CONSTRAINT fk_measurement_import_rows_batch
        FOREIGN KEY (import_batch_id)
        REFERENCES measurement_import_batches (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_import_rows_person
        FOREIGN KEY (normalized_person_id)
        REFERENCES people (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_measurement_import_rows_production
        FOREIGN KEY (normalized_production_id)
        REFERENCES productions (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_measurement_import_rows_session
        FOREIGN KEY (normalized_measurement_session_id)
        REFERENCES measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_import_cells (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_row_id BIGINT UNSIGNED NOT NULL,
    source_column_name VARCHAR(150) NOT NULL,
    raw_value VARCHAR(255) NOT NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    review_notes TEXT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_import_cells_source (
        import_row_id,
        source_column_name
    ),
    KEY idx_measurement_import_cells_column (source_column_name),
    KEY idx_measurement_import_cells_review (needs_review),

    CONSTRAINT fk_measurement_import_cells_row
        FOREIGN KEY (import_row_id)
        REFERENCES measurement_import_rows (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_import_column_maps (
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_column_name VARCHAR(150) NOT NULL,
    measurement_type_id BIGINT UNSIGNED NOT NULL,
    needs_review_on_import TINYINT(1) NOT NULL DEFAULT 0,
    mapping_notes TEXT NULL,

    PRIMARY KEY (
        import_batch_id,
        source_column_name,
        measurement_type_id
    ),
    KEY idx_measurement_import_column_maps_type (measurement_type_id),

    CONSTRAINT fk_measurement_import_column_maps_batch
        FOREIGN KEY (import_batch_id)
        REFERENCES measurement_import_batches (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_import_column_maps_type
        FOREIGN KEY (measurement_type_id)
        REFERENCES measurement_types (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_session_import_rows (
    measurement_session_id BIGINT UNSIGNED NOT NULL,
    import_row_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (measurement_session_id, import_row_id),
    UNIQUE KEY uq_measurement_session_import_rows_row (import_row_id),

    CONSTRAINT fk_measurement_session_import_rows_session
        FOREIGN KEY (measurement_session_id)
        REFERENCES measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_session_import_rows_row
        FOREIGN KEY (import_row_id)
        REFERENCES measurement_import_rows (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


CREATE TABLE measurement_values (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    measurement_session_id BIGINT UNSIGNED NOT NULL,
    measurement_type_id BIGINT UNSIGNED NOT NULL,
    raw_value VARCHAR(255) NOT NULL,
    numeric_value DECIMAL(8,2) NULL,
    text_value VARCHAR(255) NULL,
    value_status VARCHAR(30) NOT NULL DEFAULT 'recorded',
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    review_notes TEXT NULL,
    source_import_cell_id BIGINT UNSIGNED NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_measurement_values_session_type (
        measurement_session_id,
        measurement_type_id
    ),
    KEY idx_measurement_values_type (measurement_type_id),
    KEY idx_measurement_values_review (needs_review),
    KEY idx_measurement_values_source_cell (source_import_cell_id),
    KEY idx_measurement_values_reviewed_by (reviewed_by_user_id),

    CONSTRAINT fk_measurement_values_session
        FOREIGN KEY (measurement_session_id)
        REFERENCES measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_values_type
        FOREIGN KEY (measurement_type_id)
        REFERENCES measurement_types (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_measurement_values_source_cell
        FOREIGN KEY (source_import_cell_id)
        REFERENCES measurement_import_cells (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_measurement_values_reviewed_by
        FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


INSERT INTO measurement_types (
    code,
    name,
    value_kind,
    unit,
    display_order
) VALUES
    ('height', 'Height', 'number', 'inches', 10),
    ('weight', 'Weight', 'number', 'pounds', 20),
    ('bust', 'Bust', 'number', 'inches', 30),
    ('dress_suit_size', 'Dress / Suit Size', 'text', NULL, 40),
    ('bra_size', 'Bra Size', 'text', NULL, 50),
    ('underbust', 'Underbust', 'number', 'inches', 60),
    ('waist', 'Waist', 'number', 'inches', 70),
    ('hips', 'Hips', 'number', 'inches', 80),
    ('inseam', 'Inseam', 'number', 'inches', 90),
    ('outseam', 'Outseam', 'number', 'inches', 100),
    ('waist_to_knee', 'Waist to Knee', 'number', 'inches', 110),
    ('waist_to_floor', 'Waist to Floor', 'number', 'inches', 120),
    ('neck', 'Neck', 'number', 'inches', 130),
    ('shirt_sleeve', 'Shirt Sleeve', 'number', 'inches', 140),
    ('arabesque_sleeve', 'Arabesque Sleeve', 'number', 'inches', 150),
    ('nape_to_waist_back', 'Nape to Waist (Back)', 'number', 'inches', 160),
    ('nape_to_waist_front', 'Nape to Waist (Front)', 'number', 'inches', 170),
    ('nape_to_floor', 'Nape to Floor', 'number', 'inches', 180),
    ('nape_to_knee', 'Nape to Knee', 'number', 'inches', 190),
    ('shoulder_to_shoulder', 'Shoulder to Shoulder', 'number', 'inches', 200),
    ('head_hat', 'Head / Hat', 'number', 'inches', 210),
    ('girth', 'Girth', 'number', 'inches', 220),
    ('bicep', 'Bicep', 'number', 'inches', 230),
    ('calf', 'Calf', 'number', 'inches', 240),
    ('thigh', 'Thigh', 'number', 'inches', 250),
    ('pants_size', 'Pants Size', 'text', NULL, 260),
    ('blouse_shirt_size', 'Blouse / Shirt Size', 'text', NULL, 270),
    ('shoe_size', 'Shoe Size', 'text', NULL, 280),
    ('wrist', 'Wrist', 'number', 'inches', 290),
    ('neck_to_ankle', 'Neck to Ankle', 'number', 'inches', 300),
    ('fingertip_to_fingertip', 'Fingertip to Fingertip', 'number', 'inches', 310),
    ('neck_to_fingertip', 'Neck to Fingertip', 'number', 'inches', 320);


INSERT INTO measurement_templates (
    name,
    owner_name,
    description,
    is_active
) VALUES (
    'Legacy combined measurements',
    'Guntersville Creative Costumers',
    'All measurement fields found in all-character-measurements-std.ods. '
        'Every field is optional because the source forms varied by production, '
        'rental company, and costumer.',
    1
);

SET @legacy_measurement_template_id = LAST_INSERT_ID();

INSERT INTO measurement_template_items (
    template_id,
    measurement_type_id,
    is_required,
    instructions,
    display_order
)
SELECT
    @legacy_measurement_template_id,
    id,
    0,
    NULL,
    display_order
FROM measurement_types;


CREATE VIEW v_actor_measurement_sessions AS
SELECT
    ms.id AS measurement_session_id,
    pe.id AS person_id,
    pe.display_name AS actor_name,
    pe.first_name,
    pe.last_name,
    pr.id AS production_id,
    pr.name AS production_name,
    pr.production_year,
    ve.id AS venue_id,
    ve.name AS venue_name,
    ms.measured_on,
    ms.date_precision,
    ms.session_sequence,
    GROUP_CONCAT(
        DISTINCT pc.character_name
        ORDER BY pc.character_name
        SEPARATOR '; '
    ) AS characters,
    ms.review_status,
    ms.notes
FROM measurement_sessions AS ms
JOIN people AS pe
    ON pe.id = ms.person_id
LEFT JOIN productions AS pr
    ON pr.id = ms.production_id
LEFT JOIN venues AS ve
    ON ve.id = pr.venue_id
LEFT JOIN production_cast AS pc
    ON pc.person_id = ms.person_id
   AND pc.production_id = ms.production_id
GROUP BY
    ms.id,
    pe.id,
    pe.display_name,
    pe.first_name,
    pe.last_name,
    pr.id,
    pr.name,
    pr.production_year,
    ve.id,
    ve.name,
    ms.measured_on,
    ms.date_precision,
    ms.session_sequence,
    ms.review_status,
    ms.notes;


CREATE VIEW v_actor_measurement_review AS
SELECT
    sessions.measurement_session_id,
    sessions.person_id,
    sessions.actor_name,
    sessions.first_name,
    sessions.last_name,
    sessions.production_id,
    sessions.production_name,
    sessions.production_year,
    sessions.venue_id,
    sessions.venue_name,
    sessions.measured_on,
    sessions.date_precision,
    sessions.characters,
    sessions.review_status AS session_review_status,
    mt.code AS measurement_code,
    mt.name AS measurement_name,
    mt.value_kind,
    mt.unit,
    mv.id AS measurement_value_id,
    mv.raw_value,
    mv.numeric_value,
    mv.text_value,
    mv.value_status,
    mv.needs_review AS value_needs_review,
    mv.review_notes AS value_review_notes
FROM v_actor_measurement_sessions AS sessions
JOIN measurement_values AS mv
    ON mv.measurement_session_id = sessions.measurement_session_id
JOIN measurement_types AS mt
    ON mt.id = mv.measurement_type_id;
