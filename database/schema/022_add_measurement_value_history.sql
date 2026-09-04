-- Collection Steward
-- Add append-only history for normalized measurement-value changes.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

CREATE TABLE measurement_value_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    measurement_session_id BIGINT UNSIGNED NOT NULL,
    measurement_type_id BIGINT UNSIGNED NOT NULL,
    measurement_value_id BIGINT UNSIGNED NULL,
    change_action VARCHAR(40) NOT NULL,
    previous_raw_value VARCHAR(255) NULL,
    previous_numeric_value DECIMAL(8,2) NULL,
    previous_text_value VARCHAR(255) NULL,
    previous_value_status VARCHAR(30) NULL,
    previous_needs_review TINYINT(1) NULL,
    new_raw_value VARCHAR(255) NULL,
    new_numeric_value DECIMAL(8,2) NULL,
    new_text_value VARCHAR(255) NULL,
    new_value_status VARCHAR(30) NULL,
    new_needs_review TINYINT(1) NULL,
    source_import_cell_id BIGINT UNSIGNED NULL,
    source_context VARCHAR(60) NOT NULL,
    change_reason VARCHAR(500) NOT NULL,
    changed_by_user_id BIGINT UNSIGNED NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_measurement_value_history_session_date (
        measurement_session_id,
        changed_at,
        id
    ),
    KEY idx_measurement_value_history_type_date (
        measurement_type_id,
        changed_at,
        id
    ),
    KEY idx_measurement_value_history_value (measurement_value_id, id),
    KEY idx_measurement_value_history_source (source_import_cell_id, id),
    KEY idx_measurement_value_history_user (changed_by_user_id, id),

    CONSTRAINT fk_measurement_value_history_session
        FOREIGN KEY (measurement_session_id)
        REFERENCES measurement_sessions (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_measurement_value_history_type
        FOREIGN KEY (measurement_type_id)
        REFERENCES measurement_types (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_measurement_value_history_user
        FOREIGN KEY (changed_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;


INSERT INTO measurement_value_history (
    measurement_session_id,
    measurement_type_id,
    measurement_value_id,
    change_action,
    new_raw_value,
    new_numeric_value,
    new_text_value,
    new_value_status,
    new_needs_review,
    source_import_cell_id,
    source_context,
    change_reason,
    changed_by_user_id,
    changed_at
)
SELECT
    mv.measurement_session_id,
    mv.measurement_type_id,
    mv.id,
    'baseline',
    mv.raw_value,
    mv.numeric_value,
    mv.text_value,
    mv.value_status,
    mv.needs_review,
    mv.source_import_cell_id,
    CASE
        WHEN mv.source_import_cell_id IS NULL THEN 'existing_record'
        ELSE 'legacy_import'
    END,
    'Existing value when immutable history tracking began.',
    mv.reviewed_by_user_id,
    COALESCE(mv.reviewed_at, mv.created_at)
FROM measurement_values AS mv;
