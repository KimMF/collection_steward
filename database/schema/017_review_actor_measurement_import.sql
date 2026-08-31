-- Collection Steward actor-measurement import review queries
-- These queries do not change data unless a commented correction example is
-- deliberately copied, completed with an exact ID, and run separately.

SET @measurement_import_batch_id = (
    SELECT id
    FROM measurement_import_batches
    WHERE source_sha256 =
        'efafaaa89003bcb19c54c0e90c3759407ed362f835142d8cbc33d5ac2ef7749b'
    LIMIT 1
);


-- Expected results:
-- source rows                164
-- distinct actors            119
-- production instances         4
-- measurement sessions       146
-- measurement values        2308
-- sessions needing review     48
-- values needing review       167
SELECT 'source rows' AS item, COUNT(*) AS actual_count
FROM measurement_import_rows
WHERE import_batch_id = @measurement_import_batch_id
UNION ALL
SELECT 'distinct actors', COUNT(DISTINCT normalized_person_id)
FROM measurement_import_rows
WHERE import_batch_id = @measurement_import_batch_id
UNION ALL
SELECT 'production instances', COUNT(DISTINCT normalized_production_id)
FROM measurement_import_rows
WHERE import_batch_id = @measurement_import_batch_id
UNION ALL
SELECT 'measurement sessions', COUNT(*)
FROM measurement_sessions
WHERE source_import_batch_id = @measurement_import_batch_id
UNION ALL
SELECT 'measurement values', COUNT(*)
FROM measurement_values AS mv
JOIN measurement_sessions AS ms
    ON ms.id = mv.measurement_session_id
WHERE ms.source_import_batch_id = @measurement_import_batch_id
UNION ALL
SELECT 'sessions needing review', COUNT(*)
FROM measurement_sessions
WHERE source_import_batch_id = @measurement_import_batch_id
  AND review_status = 'needs_review'
UNION ALL
SELECT 'values needing review', COUNT(*)
FROM measurement_values AS mv
JOIN measurement_sessions AS ms
    ON ms.id = mv.measurement_session_id
WHERE ms.source_import_batch_id = @measurement_import_batch_id
  AND mv.needs_review = 1;


-- Familiar actor/session history. Several character names may appear in one
-- row because one measurement session can support multiple roles.
SELECT *
FROM v_actor_measurement_sessions
ORDER BY last_name, first_name, measured_on, production_name;


-- All individual values initially flagged for review.
SELECT *
FROM v_actor_measurement_review
WHERE value_needs_review = 1
ORDER BY last_name, first_name, measured_on, measurement_name;


-- Thigh and Pants Size copies side by side. The two value-ID columns identify
-- exactly which measurement_values row may be removed after review.
SELECT
    sessions.measurement_session_id,
    sessions.actor_name,
    sessions.production_name,
    sessions.production_year,
    sessions.venue_name,
    sessions.measured_on,
    sessions.characters,
    MAX(CASE
        WHEN mt.code = 'thigh' THEN mv.id
        ELSE NULL
    END) AS thigh_value_id,
    MAX(CASE
        WHEN mt.code = 'thigh' THEN mv.raw_value
        ELSE NULL
    END) AS thigh_value,
    MAX(CASE
        WHEN mt.code = 'pants_size' THEN mv.id
        ELSE NULL
    END) AS pants_size_value_id,
    MAX(CASE
        WHEN mt.code = 'pants_size' THEN mv.raw_value
        ELSE NULL
    END) AS pants_size_value
FROM v_actor_measurement_sessions AS sessions
JOIN measurement_values AS mv
    ON mv.measurement_session_id = sessions.measurement_session_id
JOIN measurement_types AS mt
    ON mt.id = mv.measurement_type_id
WHERE mt.code IN ('thigh', 'pants_size')
GROUP BY
    sessions.measurement_session_id,
    sessions.actor_name,
    sessions.production_name,
    sessions.production_year,
    sessions.venue_name,
    sessions.measured_on,
    sessions.characters
ORDER BY sessions.actor_name, sessions.measured_on;


-- Original spreadsheet rows whose venue, character, ambiguous Thigh value,
-- date-converted size, or possible column mapping requires review.
SELECT
    source_sheet,
    source_row_number,
    venue_text,
    resolved_venue_name,
    production_text,
    measurement_period_text,
    character_text,
    resolved_character_name,
    first_name_text,
    last_name_text,
    review_notes
FROM measurement_import_rows
WHERE import_batch_id = @measurement_import_batch_id
  AND needs_review = 1
ORDER BY source_row_number;


-- Cast assignments created with a placeholder because the original character
-- cell was blank.
SELECT
    pc.id AS production_cast_id,
    pe.display_name AS actor_name,
    pr.name AS production_name,
    pr.production_year,
    ve.name AS venue_name,
    pc.character_name
FROM production_cast AS pc
JOIN people AS pe
    ON pe.id = pc.person_id
JOIN productions AS pr
    ON pr.id = pc.production_id
LEFT JOIN venues AS ve
    ON ve.id = pr.venue_id
WHERE pc.character_name = '(character not recorded)'
ORDER BY pe.display_name, pr.production_year;


-- CORRECTION EXAMPLES -- DO NOT RUN WITH PLACEHOLDER IDS.
-- Remove one inappropriate duplicate after reviewing the paired query above:
-- DELETE FROM measurement_values WHERE id = <exact_value_id>;

-- Correct a placeholder character after reviewing production_cast_id:
-- UPDATE production_cast
-- SET character_name = '<correct character>'
-- WHERE id = <exact_production_cast_id>;

-- Mark a retained measurement value reviewed without deleting it:
-- UPDATE measurement_values
-- SET needs_review = 0,
--     review_notes = CONCAT_WS(' | ', review_notes, 'Reviewed by Kim'),
--     reviewed_at = CURRENT_TIMESTAMP
-- WHERE id = <exact_value_id>;
