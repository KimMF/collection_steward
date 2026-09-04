-- Collection Steward
-- Add append-only history for corrections made through the administrator
-- data-maintenance workspace.
--
-- Back up the production database before running this file. MySQL schema
-- changes commit automatically. Run this migration once.

CREATE TABLE administrative_change_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_type VARCHAR(50) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    record_label VARCHAR(200) NOT NULL,
    change_action VARCHAR(50) NOT NULL DEFAULT 'corrected',
    previous_values TEXT NOT NULL,
    new_values TEXT NOT NULL,
    change_reason TEXT NOT NULL,
    changed_by_user_id BIGINT UNSIGNED NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_administrative_change_record (
        record_type,
        record_id,
        changed_at,
        id
    ),
    KEY idx_administrative_change_user (
        changed_by_user_id,
        changed_at,
        id
    ),

    CONSTRAINT fk_administrative_change_user
        FOREIGN KEY (changed_by_user_id)
        REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
