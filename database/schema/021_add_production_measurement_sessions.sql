-- Collection Steward
-- Add grouped production measurement sessions, reusable-template selection,
-- and actor-specific measurement fields.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

CREATE TABLE production_measurement_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    production_id BIGINT UNSIGNED NOT NULL,
    measurement_template_id BIGINT UNSIGNED NOT NULL,
    session_name VARCHAR(150) NOT NULL,
    measured_on DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_production_measurement_sessions_production_date (
        production_id,
        measured_on,
        id
    ),
    KEY idx_production_measurement_sessions_status_date (
        status,
        measured_on,
        id
    ),
    KEY idx_production_measurement_sessions_template (
        measurement_template_id
    ),
    KEY idx_production_measurement_sessions_created_by (
        created_by_user_id
    ),

    CONSTRAINT fk_production_measurement_sessions_production
        FOREIGN KEY (production_id)
        REFERENCES productions (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_production_measurement_sessions_template
        FOREIGN KEY (measurement_template_id)
        REFERENCES measurement_templates (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_production_measurement_sessions_created_by
        FOREIGN KEY (created_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


ALTER TABLE measurement_sessions
    ADD COLUMN production_measurement_session_id BIGINT UNSIGNED NULL
        AFTER production_id,
    ADD UNIQUE KEY uq_measurement_sessions_production_group_person (
        production_measurement_session_id,
        person_id
    ),
    ADD KEY idx_measurement_sessions_production_group (
        production_measurement_session_id
    ),
    ADD CONSTRAINT fk_measurement_sessions_production_group
        FOREIGN KEY (production_measurement_session_id)
        REFERENCES production_measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;


CREATE TABLE measurement_session_additional_types (
    measurement_session_id BIGINT UNSIGNED NOT NULL,
    measurement_type_id BIGINT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (measurement_session_id, measurement_type_id),
    KEY idx_measurement_session_additional_types_order (
        measurement_session_id,
        display_order,
        measurement_type_id
    ),

    CONSTRAINT fk_measurement_session_additional_types_session
        FOREIGN KEY (measurement_session_id)
        REFERENCES measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_measurement_session_additional_types_type
        FOREIGN KEY (measurement_type_id)
        REFERENCES measurement_types (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
