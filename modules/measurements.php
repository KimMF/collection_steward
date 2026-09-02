<?php

declare(strict_types=1);

/**
 * Actor measurement entry, review, history, and compact comparisons.
 *
 * Public entry point: /measurements.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

// Measurement records are available only to authorized stewards.
$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'measurements'
);
$csrfToken = collectionStewardCsrfToken();

// Request parsing and display helpers are local to this functional module.
function measurementPositiveInteger(mixed $value): ?int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);

    return is_int($validated) && $validated > 0
        ? $validated
        : null;
}

function measurementText(array $source, string $key): string
{
    return is_string($source[$key] ?? null)
        ? trim($source[$key])
        : '';
}

function measurementDateIsValid(string $date): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();

    return $parsedDate !== false
        && ($dateErrors === false || (
            $dateErrors['warning_count'] === 0
            && $dateErrors['error_count'] === 0
        ))
        && $parsedDate->format('Y-m-d') === $date;
}

function measurementFormattedDate(string $date, string $precision): string
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if ($parsedDate === false) {
        return $date;
    }

    if ($precision === 'year') {
        return $parsedDate->format('Y');
    }

    if ($precision === 'month') {
        return $parsedDate->format('F Y');
    }

    if ($precision === 'unknown') {
        return $parsedDate->format('F j, Y') . ' (date uncertain)';
    }

    return $parsedDate->format('F j, Y');
}

function measurementDecimalForInput(mixed $value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return '';
    }

    $decimal = (string) $value;
    if (strpos($decimal, '.') !== false) {
        $decimal = rtrim(rtrim($decimal, '0'), '.');
    }

    return $decimal === '' || $decimal === '-'
        ? '0'
        : $decimal;
}

function measurementInputValue(array $measurement): string
{
    if ($measurement['value_kind'] === 'number') {
        return $measurement['numeric_value'] !== null
            ? measurementDecimalForInput($measurement['numeric_value'])
            : '';
    }

    return is_string($measurement['text_value'] ?? null)
        ? $measurement['text_value']
        : '';
}

function measurementCompactDisplayValue(array $measurement): string
{
    if (($measurement['value_status'] ?? '') === 'not_applicable') {
        return 'N/A';
    }

    return measurementInputValue($measurement);
}

function measurementValueDiffersFromImport(array $measurement): bool
{
    if (($measurement['source_import_cell_id'] ?? null) === null) {
        return false;
    }

    $rawValue = trim((string) ($measurement['raw_value'] ?? ''));
    $displayValue = measurementCompactDisplayValue($measurement);

    if (($measurement['value_kind'] ?? '') === 'number') {
        $numericRawValue = str_replace(',', '', $rawValue);
        if (is_numeric($numericRawValue)
            && ($measurement['numeric_value'] ?? null) !== null
        ) {
            return abs(
                (float) $numericRawValue
                - (float) $measurement['numeric_value']
            ) > 0.00001;
        }
    }

    return $rawValue !== $displayValue;
}

function measurementCompactUnit(?string $unit): string
{
    return match ($unit) {
        'inches' => 'in',
        'pounds' => 'lb',
        default => (string) $unit,
    };
}

function measurementActorLabel(array $person): string
{
    $firstName = is_string($person['first_name'] ?? null)
        ? trim($person['first_name'])
        : '';
    $lastName = is_string($person['last_name'] ?? null)
        ? trim($person['last_name'])
        : '';

    if ($lastName !== '' && $firstName !== '') {
        return $lastName . ', ' . $firstName;
    }

    return (string) $person['display_name'];
}

function measurementProductionLabel(array $production): string
{
    $parts = [(string) $production['name']];

    if (!empty($production['production_year'])) {
        $parts[] = (string) $production['production_year'];
    }

    if (!empty($production['venue_name'])) {
        $parts[] = (string) $production['venue_name'];
    }

    return implode(' — ', $parts);
}

function measurementSession(
    PDO $connection,
    int $measurementSessionId
): ?array
{
    $statement = $connection->prepare(
        'SELECT
            ms.id AS measurement_session_id,
            ms.person_id,
            ms.production_id,
            ms.measured_on,
            ms.date_precision,
            ms.session_sequence,
            ms.review_status,
            ms.notes,
            ms.source_import_batch_id,
            pe.display_name AS actor_name,
            pe.first_name,
            pe.last_name,
            pr.name AS production_name,
            pr.production_year,
            ve.name AS venue_name,
            measured_by.display_name AS measured_by,
            (
                SELECT GROUP_CONCAT(
                    DISTINCT pc.character_name
                    ORDER BY pc.character_name
                    SEPARATOR \'; \'
                )
                FROM production_cast AS pc
                WHERE pc.person_id = ms.person_id
                  AND pc.production_id = ms.production_id
            ) AS characters,
            (
                SELECT COUNT(*)
                FROM measurement_values AS flagged_value
                WHERE flagged_value.measurement_session_id = ms.id
                  AND flagged_value.needs_review = 1
            ) AS flagged_value_count,
            (
                SELECT COUNT(*)
                FROM measurement_values AS stored_value
                WHERE stored_value.measurement_session_id = ms.id
            ) AS stored_value_count
         FROM measurement_sessions AS ms
         JOIN people AS pe
            ON pe.id = ms.person_id
         LEFT JOIN productions AS pr
            ON pr.id = ms.production_id
         LEFT JOIN venues AS ve
            ON ve.id = pr.venue_id
         LEFT JOIN users AS measured_by
            ON measured_by.id = ms.measured_by_user_id
         WHERE ms.id = :measurement_session_id
         LIMIT 1'
    );
    $statement->execute([
        'measurement_session_id' => $measurementSessionId,
    ]);
    $session = $statement->fetch();

    return $session === false ? null : $session;
}

function measurementReviewNote(
    ?string $existingNote,
    string $newNote
): string
{
    $trimmedExistingNote = is_string($existingNote)
        ? trim($existingNote)
        : '';

    return $trimmedExistingNote === ''
        ? $newNote
        : $trimmedExistingNote . "\n" . $newNote;
}

// Initialize request state for both new-session forms and existing-session
// actions.
$errors = [];
$notice = null;
$requestedSessionId = measurementPositiveInteger(
    $_GET['session_id'] ?? null
);

$newSessionValues = [
    'person_id' => '',
    'first_name' => '',
    'last_name' => '',
    'production_id' => measurementText($_GET, 'new_production_id'),
    'measured_on' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'character_name' => '',
    'notes' => '',
];

$newProductionValues = [
    'production_name' => '',
    'production_year' => (new DateTimeImmutable('today'))->format('Y'),
    'venue_id' => '',
    'new_venue_name' => '',
    'opening_date' => '',
];

// Each submitted action validates authorization and request data before writing
// related actor, production, role, session, or measurement records.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = measurementText($_POST, 'action');
    $postedSessionId = measurementPositiveInteger(
        $_POST['measurement_session_id'] ?? null
    );

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'create_session') {
                foreach (array_keys($newSessionValues) as $field) {
                    $newSessionValues[$field] = measurementText($_POST, $field);
                }

                $personId = measurementPositiveInteger(
                    $newSessionValues['person_id']
                );
                $productionId = measurementPositiveInteger(
                    $newSessionValues['production_id']
                );
                $firstName = $newSessionValues['first_name'];
                $lastName = $newSessionValues['last_name'];
                $measuredOn = $newSessionValues['measured_on'];
                $characterName = $newSessionValues['character_name'];
                $notes = $newSessionValues['notes'];

                if ($personId === null && $firstName === '') {
                    throw new DomainException(
                        'Choose an existing actor or enter a new actor’s first name.'
                    );
                }

                if (
                    strlen($firstName) > 100
                    || strlen($lastName) > 100
                ) {
                    throw new DomainException(
                        'First and last names must each be 100 characters or fewer.'
                    );
                }

                if (!measurementDateIsValid($measuredOn)) {
                    throw new DomainException('Enter a valid measurement date.');
                }

                if (strlen($characterName) > 150) {
                    throw new DomainException(
                        'The character name must be 150 characters or fewer.'
                    );
                }

                if (strlen($notes) > 5000) {
                    throw new DomainException(
                        'Session notes must be 5,000 characters or fewer.'
                    );
                }

                if ($characterName !== '' && $productionId === null) {
                    throw new DomainException(
                        'Choose a production before adding a character.'
                    );
                }

                $connection->beginTransaction();

                if ($personId !== null) {
                    $personStatement = $connection->prepare(
                        'SELECT id
                         FROM people
                         WHERE id = :person_id
                           AND is_active = 1
                         LIMIT 1'
                    );
                    $personStatement->execute(['person_id' => $personId]);

                    if ($personStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The selected actor is no longer available.'
                        );
                    }
                } else {
                    $displayName = trim($firstName . ' ' . $lastName);
                    $duplicatePersonStatement = $connection->prepare(
                        'SELECT id
                         FROM people
                         WHERE display_name = :display_name
                            OR (
                                first_name = :first_name
                                AND COALESCE(last_name, \'\') = :last_name
                            )
                         LIMIT 1'
                    );
                    $duplicatePersonStatement->execute([
                        'display_name' => $displayName,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ]);

                    if ($duplicatePersonStatement->fetchColumn() !== false) {
                        throw new DomainException(
                            'That actor already appears in the list. Choose the existing actor instead.'
                        );
                    }

                    $insertPersonStatement = $connection->prepare(
                        'INSERT INTO people (
                            display_name,
                            first_name,
                            last_name
                         ) VALUES (
                            :display_name,
                            :first_name,
                            :last_name
                         )'
                    );
                    $insertPersonStatement->execute([
                        'display_name' => $displayName,
                        'first_name' => $firstName,
                        'last_name' => $lastName !== '' ? $lastName : null,
                    ]);
                    $personId = (int) $connection->lastInsertId();
                }

                if ($productionId !== null) {
                    $productionStatement = $connection->prepare(
                        'SELECT id
                         FROM productions
                         WHERE id = :production_id
                         LIMIT 1'
                    );
                    $productionStatement->execute([
                        'production_id' => $productionId,
                    ]);

                    if ($productionStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The selected production is no longer available.'
                        );
                    }

                    $sequenceStatement = $connection->prepare(
                        'SELECT COALESCE(MAX(session_sequence), 0) + 1
                         FROM measurement_sessions
                         WHERE person_id = :person_id
                           AND production_id = :production_id
                           AND measured_on = :measured_on'
                    );
                    $sequenceStatement->execute([
                        'person_id' => $personId,
                        'production_id' => $productionId,
                        'measured_on' => $measuredOn,
                    ]);
                } else {
                    $sequenceStatement = $connection->prepare(
                        'SELECT COALESCE(MAX(session_sequence), 0) + 1
                         FROM measurement_sessions
                         WHERE person_id = :person_id
                           AND production_id IS NULL
                           AND measured_on = :measured_on'
                    );
                    $sequenceStatement->execute([
                        'person_id' => $personId,
                        'measured_on' => $measuredOn,
                    ]);
                }

                $sessionSequence = (int) $sequenceStatement->fetchColumn();
                $insertSessionStatement = $connection->prepare(
                    'INSERT INTO measurement_sessions (
                        person_id,
                        production_id,
                        measured_on,
                        date_precision,
                        session_sequence,
                        measured_by_user_id,
                        review_status,
                        notes
                     ) VALUES (
                        :person_id,
                        :production_id,
                        :measured_on,
                        \'day\',
                        :session_sequence,
                        :measured_by_user_id,
                        \'unreviewed\',
                        :notes
                     )'
                );
                $insertSessionStatement->execute([
                    'person_id' => $personId,
                    'production_id' => $productionId,
                    'measured_on' => $measuredOn,
                    'session_sequence' => $sessionSequence,
                    'measured_by_user_id' => (int) $currentUser['id'],
                    'notes' => $notes !== '' ? $notes : null,
                ]);
                $newSessionId = (int) $connection->lastInsertId();

                $templateStatement = $connection->query(
                    'SELECT id
                     FROM measurement_templates
                     WHERE is_active = 1
                     ORDER BY id
                     LIMIT 1'
                );
                $templateId = $templateStatement->fetchColumn();

                if ($templateId !== false) {
                    $sessionTemplateStatement = $connection->prepare(
                        'INSERT INTO measurement_session_templates (
                            measurement_session_id,
                            measurement_template_id
                         ) VALUES (
                            :measurement_session_id,
                            :measurement_template_id
                         )'
                    );
                    $sessionTemplateStatement->execute([
                        'measurement_session_id' => $newSessionId,
                        'measurement_template_id' => (int) $templateId,
                    ]);
                }

                if ($characterName !== '' && $productionId !== null) {
                    $duplicateRoleStatement = $connection->prepare(
                        'SELECT id
                         FROM production_cast
                         WHERE production_id = :production_id
                           AND person_id = :person_id
                           AND character_name = :character_name
                         LIMIT 1'
                    );
                    $duplicateRoleStatement->execute([
                        'production_id' => $productionId,
                        'person_id' => $personId,
                        'character_name' => $characterName,
                    ]);

                    if ($duplicateRoleStatement->fetchColumn() === false) {
                        $roleOrderStatement = $connection->prepare(
                            'SELECT COALESCE(MAX(display_order), 0) + 1
                             FROM production_cast
                             WHERE production_id = :production_id'
                        );
                        $roleOrderStatement->execute([
                            'production_id' => $productionId,
                        ]);
                        $roleOrder = (int) $roleOrderStatement->fetchColumn();

                        $insertRoleStatement = $connection->prepare(
                            'INSERT INTO production_cast (
                                production_id,
                                person_id,
                                character_name,
                                display_order
                             ) VALUES (
                                :production_id,
                                :person_id,
                                :character_name,
                                :display_order
                             )'
                        );
                        $insertRoleStatement->execute([
                            'production_id' => $productionId,
                            'person_id' => $personId,
                            'character_name' => $characterName,
                            'display_order' => $roleOrder,
                        ]);
                    }
                }

                $connection->commit();
                header(
                    'Location: /measurements.php?session_id='
                    . $newSessionId
                    . '&session_created=1&view=expanded'
                );
                exit;
            }

            if ($action === 'create_production') {
                foreach (array_keys($newProductionValues) as $field) {
                    $newProductionValues[$field] = measurementText($_POST, $field);
                }

                $productionName = $newProductionValues['production_name'];
                $productionYearText = $newProductionValues['production_year'];
                $venueId = measurementPositiveInteger(
                    $newProductionValues['venue_id']
                );
                $newVenueName = $newProductionValues['new_venue_name'];
                $openingDate = $newProductionValues['opening_date'];

                if ($productionName === '' || strlen($productionName) > 150) {
                    throw new DomainException(
                        'Enter a production name of 150 characters or fewer.'
                    );
                }

                if (
                    $productionYearText !== ''
                    && (
                        !ctype_digit($productionYearText)
                        || (int) $productionYearText < 1900
                        || (int) $productionYearText > 2200
                    )
                ) {
                    throw new DomainException(
                        'Enter a four-digit production year between 1900 and 2200.'
                    );
                }

                if ($newVenueName !== '' && strlen($newVenueName) > 150) {
                    throw new DomainException(
                        'The venue name must be 150 characters or fewer.'
                    );
                }

                if ($openingDate !== '' && !measurementDateIsValid($openingDate)) {
                    throw new DomainException('Enter a valid opening date.');
                }

                $productionYear = $productionYearText !== ''
                    ? (int) $productionYearText
                    : null;

                $connection->beginTransaction();

                if ($newVenueName !== '') {
                    $venueStatement = $connection->prepare(
                        'SELECT id
                         FROM venues
                         WHERE name = :venue_name
                         LIMIT 1'
                    );
                    $venueStatement->execute(['venue_name' => $newVenueName]);
                    $existingVenueId = $venueStatement->fetchColumn();

                    if ($existingVenueId === false) {
                        $insertVenueStatement = $connection->prepare(
                            'INSERT INTO venues (name)
                             VALUES (:venue_name)'
                        );
                        $insertVenueStatement->execute([
                            'venue_name' => $newVenueName,
                        ]);
                        $venueId = (int) $connection->lastInsertId();
                    } else {
                        $venueId = (int) $existingVenueId;
                    }
                } elseif ($venueId !== null) {
                    $venueStatement = $connection->prepare(
                        'SELECT id
                         FROM venues
                         WHERE id = :venue_id
                           AND is_active = 1
                         LIMIT 1'
                    );
                    $venueStatement->execute(['venue_id' => $venueId]);

                    if ($venueStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The selected venue is no longer available.'
                        );
                    }
                }

                if ($venueId === null) {
                    $duplicateProductionStatement = $connection->prepare(
                        'SELECT id
                         FROM productions
                         WHERE name = :production_name
                           AND venue_id IS NULL
                           AND (
                                production_year = :production_year
                                OR (
                                    production_year IS NULL
                                    AND :null_production_year IS NULL
                                )
                           )
                         LIMIT 1'
                    );
                } else {
                    $duplicateProductionStatement = $connection->prepare(
                        'SELECT id
                         FROM productions
                         WHERE name = :production_name
                           AND venue_id = :venue_id
                           AND (
                                production_year = :production_year
                                OR (
                                    production_year IS NULL
                                    AND :null_production_year IS NULL
                                )
                           )
                         LIMIT 1'
                    );
                    $duplicateProductionStatement->bindValue(
                        ':venue_id',
                        $venueId,
                        PDO::PARAM_INT
                    );
                }

                $duplicateProductionStatement->bindValue(
                    ':production_name',
                    $productionName
                );
                if ($productionYear === null) {
                    $duplicateProductionStatement->bindValue(
                        ':production_year',
                        null,
                        PDO::PARAM_NULL
                    );
                    $duplicateProductionStatement->bindValue(
                        ':null_production_year',
                        null,
                        PDO::PARAM_NULL
                    );
                } else {
                    $duplicateProductionStatement->bindValue(
                        ':production_year',
                        $productionYear,
                        PDO::PARAM_INT
                    );
                    $duplicateProductionStatement->bindValue(
                        ':null_production_year',
                        $productionYear,
                        PDO::PARAM_INT
                    );
                }
                $duplicateProductionStatement->execute();

                if ($duplicateProductionStatement->fetchColumn() !== false) {
                    throw new DomainException(
                        'That production already exists. Choose it when creating the measurement session.'
                    );
                }

                $insertProductionStatement = $connection->prepare(
                    'INSERT INTO productions (
                        name,
                        venue_id,
                        production_year,
                        opening_date,
                        status
                     ) VALUES (
                        :name,
                        :venue_id,
                        :production_year,
                        :opening_date,
                        \'planned\'
                     )'
                );
                $insertProductionStatement->execute([
                    'name' => $productionName,
                    'venue_id' => $venueId,
                    'production_year' => $productionYear,
                    'opening_date' => $openingDate !== '' ? $openingDate : null,
                ]);
                $newProductionId = (int) $connection->lastInsertId();

                $connection->commit();
                header(
                    'Location: /measurements.php?production_created=1'
                    . '&new_production_id=' . $newProductionId
                    . '#new-session'
                );
                exit;
            }

            if ($postedSessionId === null) {
                throw new DomainException(
                    'The measurement session could not be identified.'
                );
            }

            $session = measurementSession($connection, $postedSessionId);
            if ($session === null) {
                throw new DomainException(
                    'That measurement session was not found.'
                );
            }
            $requestedSessionId = $postedSessionId;

            if ($action === 'save_session_details') {
                $firstName = measurementText($_POST, 'first_name');
                $lastName = measurementText($_POST, 'last_name');
                $measuredOn = measurementText($_POST, 'measured_on');
                $datePrecision = measurementText($_POST, 'date_precision');
                $productionId = measurementPositiveInteger(
                    $_POST['production_id'] ?? null
                );
                $notes = measurementText($_POST, 'notes');

                if ($firstName === '') {
                    throw new DomainException('Enter the actor’s first name.');
                }

                if (strlen($firstName) > 100 || strlen($lastName) > 100) {
                    throw new DomainException(
                        'First and last names must each be 100 characters or fewer.'
                    );
                }

                if (!measurementDateIsValid($measuredOn)) {
                    throw new DomainException('Enter a valid measurement date.');
                }

                if (!in_array(
                    $datePrecision,
                    ['day', 'month', 'year', 'unknown'],
                    true
                )) {
                    throw new DomainException('Choose how exact the date is.');
                }

                if (strlen($notes) > 5000) {
                    throw new DomainException(
                        'Session notes must be 5,000 characters or fewer.'
                    );
                }

                if ($productionId !== null) {
                    $productionStatement = $connection->prepare(
                        'SELECT id
                         FROM productions
                         WHERE id = :production_id
                         LIMIT 1'
                    );
                    $productionStatement->execute([
                        'production_id' => $productionId,
                    ]);

                    if ($productionStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The selected production is no longer available.'
                        );
                    }
                }

                $displayName = trim($firstName . ' ' . $lastName);

                $connection->beginTransaction();
                $updatePersonStatement = $connection->prepare(
                    'UPDATE people
                     SET display_name = :display_name,
                         first_name = :first_name,
                         last_name = :last_name
                     WHERE id = :person_id'
                );
                $updatePersonStatement->execute([
                    'display_name' => $displayName,
                    'first_name' => $firstName,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'person_id' => (int) $session['person_id'],
                ]);

                $updateSessionStatement = $connection->prepare(
                    'UPDATE measurement_sessions
                     SET production_id = :production_id,
                         measured_on = :measured_on,
                         date_precision = :date_precision,
                         notes = :notes
                     WHERE id = :measurement_session_id'
                );
                $updateSessionStatement->execute([
                    'production_id' => $productionId,
                    'measured_on' => $measuredOn,
                    'date_precision' => $datePrecision,
                    'notes' => $notes !== '' ? $notes : null,
                    'measurement_session_id' => $postedSessionId,
                ]);
                $connection->commit();

                header(
                    'Location: /measurements.php?session_id='
                    . $postedSessionId
                    . '&details_saved=1'
                );
                exit;
            }

            if ($action === 'save_session_values') {
                $typeStatement = $connection->prepare(
                    'SELECT id, code, name, value_kind
                     FROM measurement_types AS mt
                     WHERE mt.is_active = 1
                        OR EXISTS (
                            SELECT 1
                            FROM measurement_values AS existing_value
                            WHERE existing_value.measurement_type_id = mt.id
                              AND existing_value.measurement_session_id = :measurement_session_id
                        )
                     ORDER BY mt.display_order, mt.name'
                );
                $typeStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                ]);
                $types = $typeStatement->fetchAll();

                $existingValueStatement = $connection->prepare(
                    'SELECT *
                     FROM measurement_values
                     WHERE measurement_session_id = :measurement_session_id'
                );
                $existingValueStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                ]);
                $existingValuesByType = [];
                foreach ($existingValueStatement->fetchAll() as $existingValue) {
                    $existingValuesByType[(int) $existingValue['measurement_type_id']]
                        = $existingValue;
                }

                $submittedValues = is_array($_POST['values'] ?? null)
                    ? $_POST['values']
                    : [];
                $operations = [];

                foreach ($types as $type) {
                    $typeId = (int) $type['id'];
                    $submitted = $submittedValues[$typeId]
                        ?? $submittedValues[(string) $typeId]
                        ?? null;

                    if (!is_array($submitted)) {
                        continue;
                    }

                    $inputValue = measurementText($submitted, 'value');
                    $valueStatus = measurementText($submitted, 'status');
                    $removeValue = isset($submitted['remove'])
                        && (string) $submitted['remove'] === '1';
                    $acceptValue = isset($submitted['accept'])
                        && (string) $submitted['accept'] === '1';
                    $existingValue = $existingValuesByType[$typeId] ?? null;

                    if (!in_array(
                        $valueStatus,
                        ['recorded', 'not_applicable'],
                        true
                    )) {
                        throw new DomainException(
                            'Choose Recorded or Not applicable for '
                            . $type['name'] . '.'
                        );
                    }

                    if ($removeValue) {
                        if ($existingValue !== null) {
                            $operations[] = [
                                'operation' => 'delete',
                                'existing' => $existingValue,
                            ];
                        }
                        continue;
                    }

                    $numericValue = null;
                    $textValue = null;

                    if ($valueStatus === 'not_applicable') {
                        $inputValue = '';
                    } elseif ($inputValue === '') {
                        continue;
                    } elseif ($type['value_kind'] === 'number') {
                        if (!preg_match(
                            '/\A(?:\d{1,6}(?:\.\d{1,2})?|\.\d{1,2})\z/',
                            $inputValue
                        )) {
                            throw new DomainException(
                                $type['name']
                                . ' must be a number with no more than two decimal places.'
                            );
                        }
                        $numericValue = $inputValue;
                    } else {
                        if (strlen($inputValue) > 255) {
                            throw new DomainException(
                                $type['name']
                                . ' must be 255 characters or fewer.'
                            );
                        }
                        $textValue = $inputValue;
                    }

                    if ($existingValue !== null) {
                        $currentInput = $type['value_kind'] === 'number'
                            ? measurementDecimalForInput(
                                $existingValue['numeric_value']
                            )
                            : (string) ($existingValue['text_value'] ?? '');
                        $changed = $currentInput !== $inputValue
                            || (string) $existingValue['value_status'] !== $valueStatus;
                        $accepted = (int) $existingValue['needs_review'] === 1
                            && $acceptValue;

                        if (!$changed && !$accepted) {
                            continue;
                        }
                    }

                    $operations[] = [
                        'operation' => $existingValue === null
                            ? 'insert'
                            : 'update',
                        'type_id' => $typeId,
                        'existing' => $existingValue,
                        'input_value' => $inputValue,
                        'numeric_value' => $numericValue,
                        'text_value' => $textValue,
                        'value_status' => $valueStatus,
                    ];
                }

                $reviewNote = 'Reviewed in the Measurements application by '
                    . $currentUser['display_name'] . ' on '
                    . (new DateTimeImmutable('today'))->format('Y-m-d') . '.';

                $connection->beginTransaction();
                foreach ($operations as $operation) {
                    if ($operation['operation'] === 'delete') {
                        $deleteValueStatement = $connection->prepare(
                            'DELETE FROM measurement_values
                             WHERE id = :measurement_value_id
                               AND measurement_session_id = :measurement_session_id'
                        );
                        $deleteValueStatement->execute([
                            'measurement_value_id' => (int) $operation['existing']['id'],
                            'measurement_session_id' => $postedSessionId,
                        ]);
                        continue;
                    }

                    if ($operation['operation'] === 'insert') {
                        $rawValue = $operation['value_status'] === 'not_applicable'
                            ? 'Not applicable'
                            : $operation['input_value'];
                        $insertValueStatement = $connection->prepare(
                            'INSERT INTO measurement_values (
                                measurement_session_id,
                                measurement_type_id,
                                raw_value,
                                numeric_value,
                                text_value,
                                value_status,
                                needs_review,
                                review_notes,
                                reviewed_by_user_id,
                                reviewed_at
                             ) VALUES (
                                :measurement_session_id,
                                :measurement_type_id,
                                :raw_value,
                                :numeric_value,
                                :text_value,
                                :value_status,
                                0,
                                :review_notes,
                                :reviewed_by_user_id,
                                CURRENT_TIMESTAMP
                             )'
                        );
                        $insertValueStatement->execute([
                            'measurement_session_id' => $postedSessionId,
                            'measurement_type_id' => $operation['type_id'],
                            'raw_value' => $rawValue,
                            'numeric_value' => $operation['numeric_value'],
                            'text_value' => $operation['text_value'],
                            'value_status' => $operation['value_status'],
                            'review_notes' => $reviewNote,
                            'reviewed_by_user_id' => (int) $currentUser['id'],
                        ]);
                        continue;
                    }

                    $updateValueStatement = $connection->prepare(
                        'UPDATE measurement_values
                         SET numeric_value = :numeric_value,
                             text_value = :text_value,
                             value_status = :value_status,
                             needs_review = 0,
                             review_notes = :review_notes,
                             reviewed_by_user_id = :reviewed_by_user_id,
                             reviewed_at = CURRENT_TIMESTAMP
                         WHERE id = :measurement_value_id
                           AND measurement_session_id = :measurement_session_id'
                    );
                    $updateValueStatement->execute([
                        'numeric_value' => $operation['numeric_value'],
                        'text_value' => $operation['text_value'],
                        'value_status' => $operation['value_status'],
                        'review_notes' => measurementReviewNote(
                            $operation['existing']['review_notes'],
                            $reviewNote
                        ),
                        'reviewed_by_user_id' => (int) $currentUser['id'],
                        'measurement_value_id' => (int) $operation['existing']['id'],
                        'measurement_session_id' => $postedSessionId,
                    ]);
                }
                $connection->commit();

                header(
                    'Location: /measurements.php?session_id='
                    . $postedSessionId
                    . '&values_saved=1'
                );
                exit;
            }

            if ($action === 'add_character') {
                $characterName = measurementText($_POST, 'character_name');

                if ($session['production_id'] === null) {
                    throw new DomainException(
                        'Choose a production in Session details before adding a character.'
                    );
                }

                if ($characterName === '' || strlen($characterName) > 150) {
                    throw new DomainException(
                        'Enter a character name of 150 characters or fewer.'
                    );
                }

                $duplicateRoleStatement = $connection->prepare(
                    'SELECT id
                     FROM production_cast
                     WHERE production_id = :production_id
                       AND person_id = :person_id
                       AND character_name = :character_name
                     LIMIT 1'
                );
                $duplicateRoleStatement->execute([
                    'production_id' => (int) $session['production_id'],
                    'person_id' => (int) $session['person_id'],
                    'character_name' => $characterName,
                ]);

                if ($duplicateRoleStatement->fetchColumn() !== false) {
                    throw new DomainException(
                        'That character is already listed for this actor and production.'
                    );
                }

                $roleOrderStatement = $connection->prepare(
                    'SELECT COALESCE(MAX(display_order), 0) + 1
                     FROM production_cast
                     WHERE production_id = :production_id'
                );
                $roleOrderStatement->execute([
                    'production_id' => (int) $session['production_id'],
                ]);

                $insertRoleStatement = $connection->prepare(
                    'INSERT INTO production_cast (
                        production_id,
                        person_id,
                        character_name,
                        display_order
                     ) VALUES (
                        :production_id,
                        :person_id,
                        :character_name,
                        :display_order
                     )'
                );
                $insertRoleStatement->execute([
                    'production_id' => (int) $session['production_id'],
                    'person_id' => (int) $session['person_id'],
                    'character_name' => $characterName,
                    'display_order' => (int) $roleOrderStatement->fetchColumn(),
                ]);

                header(
                    'Location: /measurements.php?session_id='
                    . $postedSessionId
                    . '&character_added=1'
                );
                exit;
            }

            if ($action === 'save_character') {
                $roleId = measurementPositiveInteger(
                    $_POST['production_cast_id'] ?? null
                );
                $characterName = measurementText($_POST, 'character_name');

                if ($roleId === null) {
                    throw new DomainException(
                        'The character assignment could not be identified.'
                    );
                }

                if ($characterName === '' || strlen($characterName) > 150) {
                    throw new DomainException(
                        'Enter a character name of 150 characters or fewer.'
                    );
                }

                $duplicateRoleStatement = $connection->prepare(
                    'SELECT pc.id
                     FROM production_cast AS pc
                     JOIN measurement_sessions AS ms
                        ON ms.id = :measurement_session_id
                       AND ms.person_id = pc.person_id
                       AND ms.production_id = pc.production_id
                     WHERE pc.character_name = :character_name
                       AND pc.id <> :production_cast_id
                     LIMIT 1'
                );
                $duplicateRoleStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                    'character_name' => $characterName,
                    'production_cast_id' => $roleId,
                ]);

                if ($duplicateRoleStatement->fetchColumn() !== false) {
                    throw new DomainException(
                        'That character is already listed for this actor and production.'
                    );
                }

                $updateRoleStatement = $connection->prepare(
                    'UPDATE production_cast AS pc
                     JOIN measurement_sessions AS ms
                        ON ms.id = :measurement_session_id
                       AND ms.person_id = pc.person_id
                       AND ms.production_id = pc.production_id
                     SET pc.character_name = :character_name
                     WHERE pc.id = :production_cast_id'
                );
                $updateRoleStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                    'character_name' => $characterName,
                    'production_cast_id' => $roleId,
                ]);

                if ($updateRoleStatement->rowCount() === 0) {
                    $roleStatement = $connection->prepare(
                        'SELECT pc.id
                         FROM production_cast AS pc
                         JOIN measurement_sessions AS ms
                            ON ms.id = :measurement_session_id
                           AND ms.person_id = pc.person_id
                           AND ms.production_id = pc.production_id
                         WHERE pc.id = :production_cast_id'
                    );
                    $roleStatement->execute([
                        'measurement_session_id' => $postedSessionId,
                        'production_cast_id' => $roleId,
                    ]);

                    if ($roleStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'That character assignment was not found.'
                        );
                    }
                }

                header(
                    'Location: /measurements.php?session_id='
                    . $postedSessionId
                    . '&character_saved=1'
                );
                exit;
            }

            if ($action === 'finish_review') {
                $flaggedValueStatement = $connection->prepare(
                    'SELECT
                        COUNT(*) AS stored_value_count,
                        COALESCE(SUM(needs_review), 0) AS flagged_value_count
                     FROM measurement_values
                     WHERE measurement_session_id = :measurement_session_id'
                );
                $flaggedValueStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                ]);
                $reviewCounts = $flaggedValueStatement->fetch();

                if ((int) $reviewCounts['flagged_value_count'] > 0) {
                    throw new DomainException(
                        'Correct, accept, or remove every flagged measurement before finishing this review.'
                    );
                }

                if ((int) $reviewCounts['stored_value_count'] === 0) {
                    throw new DomainException(
                        'Enter at least one measurement before finishing this review.'
                    );
                }

                $finishReviewStatement = $connection->prepare(
                    'UPDATE measurement_sessions
                     SET review_status = \'reviewed\'
                     WHERE id = :measurement_session_id'
                );
                $finishReviewStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                ]);

                header(
                    'Location: /measurements.php?session_id='
                    . $postedSessionId
                    . '&review_finished=1'
                );
                exit;
            }

            if ($action === 'requeue_review') {
                $requeueReviewStatement = $connection->prepare(
                    'UPDATE measurement_sessions
                     SET review_status = \'needs_review\'
                     WHERE id = :measurement_session_id'
                );
                $requeueReviewStatement->execute([
                    'measurement_session_id' => $postedSessionId,
                ]);

                header(
                    'Location: /measurements.php?session_id='
                    . $postedSessionId
                    . '&review_requeued=1'
                );
                exit;
            }

            throw new DomainException('Choose a valid Measurements action.');
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = 'The change could not be saved. It may conflict with an existing actor, production, or measurement session.';
        }
    }
}

// Convert redirect flags into confirmation messages after successful writes.
if (isset($_GET['session_created'])) {
    $notice = 'The measurement session was created. Add the measurements below.';
} elseif (isset($_GET['production_created'])) {
    $notice = 'The production was created and selected in the new-session form.';
} elseif (isset($_GET['details_saved'])) {
    $notice = 'The actor and session details were saved.';
} elseif (isset($_GET['values_saved'])) {
    $notice = 'The measurement changes were saved. Source imports remain preserved; corrected values continue to show their originals.';
} elseif (isset($_GET['character_added'])) {
    $notice = 'The additional character was added.';
} elseif (isset($_GET['character_saved'])) {
    $notice = 'The character name was saved.';
} elseif (isset($_GET['review_finished'])) {
    $notice = 'The session review is complete.';
} elseif (isset($_GET['review_requeued'])) {
    $notice = 'The session was returned to the review queue.';
}

// Resolve list filters and the saved layout. New visitors start in Compact
// mode, while an explicit URL or saved cookie remains authoritative.
$actorSearch = measurementText($_GET, 'actor_search');
$listMode = measurementText($_GET, 'list');
if (!in_array($listMode, ['review', 'recent'], true)) {
    $listMode = 'review';
}

$requestedViewMode = measurementText($_GET, 'view');
$savedViewMode = is_string($_COOKIE['collection_steward_measurement_view'] ?? null)
    ? $_COOKIE['collection_steward_measurement_view']
    : '';

if (in_array($requestedViewMode, ['compact', 'expanded'], true)) {
    $viewMode = $requestedViewMode;
    setcookie('collection_steward_measurement_view', $viewMode, [
        'expires' => time() + (86400 * 365),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} elseif (in_array($savedViewMode, ['compact', 'expanded'], true)) {
    $viewMode = $savedViewMode;
} else {
    $viewMode = 'compact';
}

$compactScope = measurementText($_GET, 'scope') === 'group'
    ? 'group'
    : 'actor';
$showAllMeasurementSessions = measurementText(
    $_GET,
    'all_sessions'
) === '1';
$showAllActorSessions = $viewMode === 'compact'
    && $compactScope === 'actor'
    && $showAllMeasurementSessions;
$groupProductionId = measurementPositiveInteger(
    $_GET['group_production_id'] ?? null
);
$groupActorSearch = measurementText($_GET, 'group_actor_search');

$pendingSessionCount = (int) $connection->query(
    "SELECT COUNT(*)
     FROM measurement_sessions AS pending_session
     WHERE pending_session.review_status = 'needs_review'
        OR EXISTS (
            SELECT 1
            FROM measurement_values AS pending_value
            WHERE pending_value.measurement_session_id = pending_session.id
              AND pending_value.needs_review = 1
        )"
)->fetchColumn();
$pendingValueCount = (int) $connection->query(
    'SELECT COUNT(*)
     FROM measurement_values
     WHERE needs_review = 1'
)->fetchColumn();

// Build the review/recent session list and select its first row when the URL
// does not name a session.
$sessionListParameters = [];
$sessionListWhere = '';
if ($actorSearch !== '') {
    $sessionListWhere = 'WHERE (
        pe.display_name LIKE :actor_search_display
        OR pe.first_name LIKE :actor_search_first
        OR pe.last_name LIKE :actor_search_last
    )';
    $actorSearchPattern = '%' . $actorSearch . '%';
    $sessionListParameters['actor_search_display'] = $actorSearchPattern;
    $sessionListParameters['actor_search_first'] = $actorSearchPattern;
    $sessionListParameters['actor_search_last'] = $actorSearchPattern;
} elseif ($listMode === 'review') {
    $sessionListWhere = "WHERE ms.review_status = 'needs_review'
        OR EXISTS (
            SELECT 1
            FROM measurement_values AS pending_value
            WHERE pending_value.measurement_session_id = ms.id
              AND pending_value.needs_review = 1
        )";
}

$sessionListStatement = $connection->prepare(
    'SELECT
        ms.id AS measurement_session_id,
        ms.person_id,
        ms.measured_on,
        ms.date_precision,
        ms.review_status,
        pe.display_name AS actor_name,
        pr.name AS production_name,
        pr.production_year,
        ve.name AS venue_name,
        (
            SELECT GROUP_CONCAT(
                DISTINCT pc.character_name
                ORDER BY pc.character_name
                SEPARATOR \'; \'
            )
            FROM production_cast AS pc
            WHERE pc.person_id = ms.person_id
              AND pc.production_id = ms.production_id
        ) AS characters,
        (
            SELECT COUNT(*)
            FROM measurement_values AS flagged_value
            WHERE flagged_value.measurement_session_id = ms.id
              AND flagged_value.needs_review = 1
        ) AS flagged_value_count
     FROM measurement_sessions AS ms
     JOIN people AS pe
        ON pe.id = ms.person_id
     LEFT JOIN productions AS pr
        ON pr.id = ms.production_id
     LEFT JOIN venues AS ve
        ON ve.id = pr.venue_id
     ' . $sessionListWhere . '
     ORDER BY
        CASE WHEN ms.review_status = \'needs_review\' THEN 0 ELSE 1 END,
        ms.measured_on DESC,
        pe.last_name,
        pe.first_name,
        pe.display_name,
        ms.id DESC
     LIMIT 100'
);
$sessionListStatement->execute($sessionListParameters);
$sessionList = $sessionListStatement->fetchAll();

if ($requestedSessionId === null && $sessionList !== []) {
    $requestedSessionId = (int) $sessionList[0]['measurement_session_id'];
}

// Load the selected session's measurements, roles, history, and actor-level
// compact table data.
$selectedSession = null;
$selectedMeasurements = [];
$selectedRoles = [];
$actorHistory = [];
$compactMeasurementTypes = [];
$compactSessions = [];
$compactValues = [];

if ($requestedSessionId !== null) {
    $selectedSession = measurementSession($connection, $requestedSessionId);

    if ($selectedSession === null) {
        $errors[] = 'That measurement session was not found.';
    } else {
        $measurementStatement = $connection->prepare(
            'SELECT
                mt.id AS measurement_type_id,
                mt.code,
                mt.name,
                mt.value_kind,
                mt.unit,
                mt.display_order,
                mv.id AS measurement_value_id,
                mv.raw_value,
                mv.numeric_value,
                mv.text_value,
                mv.value_status,
                mv.needs_review,
                mv.review_notes,
                mv.source_import_cell_id,
                mv.reviewed_at
             FROM measurement_types AS mt
             LEFT JOIN measurement_values AS mv
                ON mv.measurement_type_id = mt.id
               AND mv.measurement_session_id = :measurement_session_id
             WHERE mt.is_active = 1
                OR mv.id IS NOT NULL
             ORDER BY mt.display_order, mt.name'
        );
        $measurementStatement->execute([
            'measurement_session_id' => $requestedSessionId,
        ]);
        $selectedMeasurements = $measurementStatement->fetchAll();

        if ($selectedSession['production_id'] !== null) {
            $roleStatement = $connection->prepare(
                'SELECT id, character_name
                 FROM production_cast
                 WHERE person_id = :person_id
                   AND production_id = :production_id
                 ORDER BY display_order, id'
            );
            $roleStatement->execute([
                'person_id' => (int) $selectedSession['person_id'],
                'production_id' => (int) $selectedSession['production_id'],
            ]);
            $selectedRoles = $roleStatement->fetchAll();
        }

        $historyStatement = $connection->prepare(
            'SELECT
                ms.id AS measurement_session_id,
                ms.measured_on,
                ms.date_precision,
                ms.review_status,
                pr.name AS production_name,
                pr.production_year,
                COUNT(mv.id) AS measurement_count,
                COALESCE(SUM(mv.needs_review), 0) AS flagged_value_count
             FROM measurement_sessions AS ms
             LEFT JOIN productions AS pr
                ON pr.id = ms.production_id
             LEFT JOIN measurement_values AS mv
                ON mv.measurement_session_id = ms.id
             WHERE ms.person_id = :person_id
             GROUP BY
                ms.id,
                ms.measured_on,
                ms.date_precision,
                ms.review_status,
                pr.name,
                pr.production_year
             ORDER BY ms.measured_on DESC, ms.id DESC'
        );
        $historyStatement->execute([
            'person_id' => (int) $selectedSession['person_id'],
        ]);
        $actorHistory = $historyStatement->fetchAll();

        if ($viewMode === 'compact' && $compactScope === 'actor') {
            $compactTypeStatement = $connection->prepare(
                'SELECT
                    mt.id AS measurement_type_id,
                    mt.code,
                    mt.name,
                    mt.value_kind,
                    mt.unit,
                    mt.display_order
                 FROM measurement_types AS mt
                 WHERE mt.is_active = 1
                    OR EXISTS (
                        SELECT 1
                        FROM measurement_values AS actor_value
                        JOIN measurement_sessions AS actor_session
                           ON actor_session.id = actor_value.measurement_session_id
                        WHERE actor_value.measurement_type_id = mt.id
                          AND actor_session.person_id = :person_id
                    )
                 ORDER BY mt.display_order, mt.name'
            );
            $compactTypeStatement->execute([
                'person_id' => (int) $selectedSession['person_id'],
            ]);
            $compactMeasurementTypes = $compactTypeStatement->fetchAll();

            $compactSessions = $showAllActorSessions
                ? $actorHistory
                : array_values(array_filter(
                    $actorHistory,
                    static fn (array $historySession): bool =>
                        (int) $historySession['measurement_session_id']
                        === $requestedSessionId
                ));

            $compactValueSql =
                'SELECT
                    mv.measurement_session_id,
                    mv.measurement_type_id,
                    mt.value_kind,
                    mv.raw_value,
                    mv.numeric_value,
                    mv.text_value,
                    mv.value_status,
                    mv.needs_review,
                    mv.source_import_cell_id
                 FROM measurement_values AS mv
                 JOIN measurement_sessions AS ms
                    ON ms.id = mv.measurement_session_id
                 JOIN measurement_types AS mt
                    ON mt.id = mv.measurement_type_id
                 WHERE ms.person_id = :person_id';
            $compactValueParameters = [
                'person_id' => (int) $selectedSession['person_id'],
            ];

            if (!$showAllActorSessions) {
                $compactValueSql .=
                    ' AND mv.measurement_session_id = :measurement_session_id';
                $compactValueParameters['measurement_session_id'] =
                    $requestedSessionId;
            }

            $compactValueStatement = $connection->prepare($compactValueSql);
            $compactValueStatement->execute($compactValueParameters);

            foreach ($compactValueStatement->fetchAll() as $compactValue) {
                $compactValues[(int) $compactValue['measurement_session_id']][
                    (int) $compactValue['measurement_type_id']
                ] = $compactValue;
            }
        }
    }
}

// These shared choices support new sessions, session editing, and cast-scoped
// compact comparisons.
$peopleStatement = $connection->query(
    'SELECT id, display_name, first_name, last_name
     FROM people
     WHERE is_active = 1
     ORDER BY
        COALESCE(last_name, display_name),
        COALESCE(first_name, display_name),
        id'
);
$people = $peopleStatement->fetchAll();

$productionStatement = $connection->query(
    'SELECT
        pr.id,
        pr.name,
        pr.production_year,
        pr.status,
        ve.name AS venue_name
     FROM productions AS pr
     LEFT JOIN venues AS ve
        ON ve.id = pr.venue_id
     ORDER BY
        COALESCE(pr.production_year, YEAR(pr.opening_date)) DESC,
        pr.name,
        ve.name'
);
$productions = $productionStatement->fetchAll();

$venueStatement = $connection->query(
    'SELECT id, name
     FROM venues
     WHERE is_active = 1
     ORDER BY name'
);
$venues = $venueStatement->fetchAll();

$selectedGroupProduction = null;
$groupActorCount = 0;

// Choose the cast group used by the Compact group table.
if ($compactScope === 'group') {
    foreach ($productions as $production) {
        if ((int) $production['id'] === $groupProductionId) {
            $selectedGroupProduction = $production;
            break;
        }
    }

    if ($selectedGroupProduction === null) {
        $preferredProductionId = $selectedSession !== null
            && $selectedSession['production_id'] !== null
            ? (int) $selectedSession['production_id']
            : null;

        foreach ($productions as $production) {
            if ($preferredProductionId === null
                || (int) $production['id'] === $preferredProductionId
            ) {
                $selectedGroupProduction = $production;
                $groupProductionId = (int) $production['id'];
                break;
            }
        }
    }
}

// Assemble cast rows, their applicable measurement types, and stored values for
// the horizontally scrollable Compact group table.
if ($viewMode === 'compact'
    && $compactScope === 'group'
    && $selectedSession !== null
    && $groupProductionId !== null
) {
    $groupActorSql =
        'SELECT
            pe.id AS person_id,
            pe.display_name AS actor_name,
            pe.first_name,
            pe.last_name,
            GROUP_CONCAT(
                pc.character_name
                ORDER BY pc.display_order, pc.id
                SEPARATOR \'; \'
            ) AS group_characters
         FROM production_cast AS pc
         JOIN people AS pe
            ON pe.id = pc.person_id
         WHERE pc.production_id = :group_production_id';
    $groupActorParameters = [
        'group_production_id' => $groupProductionId,
    ];

    if ($groupActorSearch !== '') {
        $groupActorSql .=
            ' AND (
                pe.display_name LIKE :group_actor_display
                OR pe.first_name LIKE :group_actor_first
                OR pe.last_name LIKE :group_actor_last
            )';
        $groupActorSearchPattern = '%' . $groupActorSearch . '%';
        $groupActorParameters['group_actor_display'] =
            $groupActorSearchPattern;
        $groupActorParameters['group_actor_first'] =
            $groupActorSearchPattern;
        $groupActorParameters['group_actor_last'] =
            $groupActorSearchPattern;
    }

    $groupActorSql .=
        ' GROUP BY
            pe.id,
            pe.display_name,
            pe.first_name,
            pe.last_name
          ORDER BY
            COALESCE(pe.last_name, pe.display_name),
            COALESCE(pe.first_name, pe.display_name),
            pe.id';
    $groupActorStatement = $connection->prepare($groupActorSql);
    $groupActorStatement->execute($groupActorParameters);
    $groupActors = $groupActorStatement->fetchAll();
    $groupActorCount = count($groupActors);

    $groupSessionsByActor = [];
    $groupSessionIds = [];

    if ($groupActors !== []) {
        $groupSessionParameters = [];
        $groupPersonPlaceholders = [];
        foreach ($groupActors as $groupActorIndex => $groupActor) {
            $placeholderName = 'group_person_' . $groupActorIndex;
            $groupPersonPlaceholders[] = ':' . $placeholderName;
            $groupSessionParameters[$placeholderName] =
                (int) $groupActor['person_id'];
        }

        $groupSessionSql =
            'SELECT
                ms.id AS measurement_session_id,
                ms.person_id,
                ms.measured_on,
                ms.date_precision,
                ms.review_status,
                pe.display_name AS actor_name,
                pe.first_name,
                pe.last_name,
                pr.name AS production_name,
                pr.production_year,
                (
                    SELECT COUNT(*)
                    FROM measurement_values AS stored_value
                    WHERE stored_value.measurement_session_id = ms.id
                ) AS measurement_count,
                (
                    SELECT COUNT(*)
                    FROM measurement_values AS flagged_value
                    WHERE flagged_value.measurement_session_id = ms.id
                      AND flagged_value.needs_review = 1
                ) AS flagged_value_count
             FROM measurement_sessions AS ms
             JOIN people AS pe
                ON pe.id = ms.person_id
             LEFT JOIN productions AS pr
                ON pr.id = ms.production_id
             WHERE ms.person_id IN ('
            . implode(', ', $groupPersonPlaceholders)
            . ')';

        if (!$showAllMeasurementSessions) {
            $groupSessionSql .=
                ' AND NOT EXISTS (
                    SELECT 1
                    FROM measurement_sessions AS newer_session
                    WHERE newer_session.person_id = ms.person_id
                      AND (
                        newer_session.measured_on > ms.measured_on
                        OR (
                            newer_session.measured_on = ms.measured_on
                            AND newer_session.id > ms.id
                        )
                      )
                )';
        }

        $groupSessionSql .=
            ' ORDER BY
                COALESCE(pe.last_name, pe.display_name),
                COALESCE(pe.first_name, pe.display_name),
                pe.id,
                ms.measured_on DESC,
                ms.id DESC';
        $groupSessionStatement = $connection->prepare($groupSessionSql);
        $groupSessionStatement->execute($groupSessionParameters);

        foreach ($groupSessionStatement->fetchAll() as $groupSession) {
            $personId = (int) $groupSession['person_id'];
            $groupSessionsByActor[$personId][] = $groupSession;
            $groupSessionIds[] =
                (int) $groupSession['measurement_session_id'];
        }

        foreach ($groupActors as $groupActor) {
            $personId = (int) $groupActor['person_id'];
            $actorSessions = $groupSessionsByActor[$personId] ?? [];

            if ($actorSessions === []) {
                $compactSessions[] = [
                    'measurement_session_id' => null,
                    'person_id' => $personId,
                    'actor_name' => $groupActor['actor_name'],
                    'first_name' => $groupActor['first_name'],
                    'last_name' => $groupActor['last_name'],
                    'measured_on' => null,
                    'date_precision' => 'day',
                    'review_status' => 'unrecorded',
                    'production_name' => null,
                    'production_year' => null,
                    'measurement_count' => 0,
                    'flagged_value_count' => 0,
                    'group_characters' => $groupActor['group_characters'],
                ];
                continue;
            }

            foreach ($actorSessions as $actorSession) {
                $actorSession['group_characters'] =
                    $groupActor['group_characters'];
                $compactSessions[] = $actorSession;
            }
        }
    }

    $compactTypeSql =
        'SELECT
            mt.id AS measurement_type_id,
            mt.code,
            mt.name,
            mt.value_kind,
            mt.unit,
            mt.display_order
         FROM measurement_types AS mt
         WHERE mt.is_active = 1';
    $compactTypeParameters = [];

    if ($groupSessionIds !== []) {
        $groupTypeSessionPlaceholders = [];
        foreach ($groupSessionIds as $groupSessionIndex => $groupSessionId) {
            $placeholderName = 'group_type_session_' . $groupSessionIndex;
            $groupTypeSessionPlaceholders[] = ':' . $placeholderName;
            $compactTypeParameters[$placeholderName] = $groupSessionId;
        }
        $compactTypeSql .=
            ' OR EXISTS (
                SELECT 1
                FROM measurement_values AS group_type_value
                WHERE group_type_value.measurement_type_id = mt.id
                  AND group_type_value.measurement_session_id IN ('
            . implode(', ', $groupTypeSessionPlaceholders)
            . ')
            )';
    }

    $compactTypeSql .= ' ORDER BY mt.display_order, mt.name';
    $compactTypeStatement = $connection->prepare($compactTypeSql);
    $compactTypeStatement->execute($compactTypeParameters);
    $compactMeasurementTypes = $compactTypeStatement->fetchAll();

    if ($groupSessionIds !== []) {
        $groupValueParameters = [];
        $groupValueSessionPlaceholders = [];
        foreach ($groupSessionIds as $groupSessionIndex => $groupSessionId) {
            $placeholderName = 'group_value_session_' . $groupSessionIndex;
            $groupValueSessionPlaceholders[] = ':' . $placeholderName;
            $groupValueParameters[$placeholderName] = $groupSessionId;
        }

        $groupValueStatement = $connection->prepare(
            'SELECT
                mv.measurement_session_id,
                mv.measurement_type_id,
                mt.value_kind,
                mv.raw_value,
                mv.numeric_value,
                mv.text_value,
                mv.value_status,
                mv.needs_review,
                mv.source_import_cell_id
             FROM measurement_values AS mv
             JOIN measurement_types AS mt
                ON mt.id = mv.measurement_type_id
             WHERE mv.measurement_session_id IN ('
            . implode(', ', $groupValueSessionPlaceholders)
            . ')'
        );
        $groupValueStatement->execute($groupValueParameters);

        foreach ($groupValueStatement->fetchAll() as $compactValue) {
            $compactValues[(int) $compactValue['measurement_session_id']][
                (int) $compactValue['measurement_type_id']
            ] = $compactValue;
        }
    }
}

// Preserve the current list, layout, scope, and filters when building links
// between the Compact and Expanded presentations.
$compactContextParameters = [
    'scope' => $compactScope,
];
if ($showAllMeasurementSessions) {
    $compactContextParameters['all_sessions'] = 1;
}
if ($compactScope === 'group') {
    if ($groupProductionId !== null) {
        $compactContextParameters['group_production_id'] =
            $groupProductionId;
    }
    if ($groupActorSearch !== '') {
        $compactContextParameters['group_actor_search'] =
            $groupActorSearch;
    }
}

$reviewListLinkParameters = array_merge(
    ['list' => 'review', 'view' => $viewMode],
    $compactContextParameters
);
$recentListLinkParameters = array_merge(
    ['list' => 'recent', 'view' => $viewMode],
    $compactContextParameters
);
$clearSearchLinkParameters = array_merge(
    ['list' => 'review', 'view' => $viewMode],
    $compactContextParameters
);
$compactViewLinkParameters = array_merge(
    ['view' => 'compact'],
    $compactContextParameters
);
$expandedViewLinkParameters = array_merge(
    ['view' => 'expanded'],
    $compactContextParameters
);
if ($requestedSessionId !== null) {
    $compactViewLinkParameters['session_id'] = $requestedSessionId;
    $expandedViewLinkParameters['session_id'] = $requestedSessionId;
}
if ($actorSearch !== '') {
    $compactViewLinkParameters['actor_search'] = $actorSearch;
    $expandedViewLinkParameters['actor_search'] = $actorSearch;
} else {
    $compactViewLinkParameters['list'] = $listMode;
    $expandedViewLinkParameters['list'] = $listMode;
}

$actorScopeLinkParameters = $compactViewLinkParameters;
$actorScopeLinkParameters['scope'] = 'actor';
unset(
    $actorScopeLinkParameters['group_production_id'],
    $actorScopeLinkParameters['group_actor_search']
);
$groupScopeLinkParameters = $compactViewLinkParameters;
$groupScopeLinkParameters['scope'] = 'group';

// Render the measurement workspace.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actor measurements — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260902-4">
</head>
<body>
<main class="measurements-page">
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/checkout.php">Production checkout</a>
        <a href="/measurements.php" aria-current="page">Measurements</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_vocabulary')): ?>
            <a href="/vocabulary.php">Vocabulary</a>
        <?php endif; ?>
        <?php if (collectionStewardUserCan($currentUser, 'manage_users')): ?>
            <a href="/users.php">Users</a>
        <?php endif; ?>
        <a href="/change-password.php">Password</a>
    </nav>

    <div class="page-heading measurement-heading">
        <div>
            <p class="privacy-label">Private costuming records</p>
            <h1>Actor measurements</h1>
            <p>Review imported records, retain dated history, and record new measurement sessions.</p>
        </div>
        <div class="user-session">
            <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
            <form method="post" action="/">
                <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
            </form>
        </div>
    </div>

    <?php if ($notice !== null): ?>
        <div class="notice" role="status"><?= collectionStewardEscape($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The change was not saved.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="measurement-summary" aria-label="Measurement review summary">
        <div>
            <strong><?= $pendingSessionCount ?></strong>
            <span>sessions awaiting review</span>
        </div>
        <div>
            <strong><?= $pendingValueCount ?></strong>
            <span>individual values flagged</span>
        </div>
        <a class="button" href="#new-session">Record a new measurement session</a>
    </div>

    <div class="measurement-workspace <?= $viewMode === 'compact' ? 'is-compact-view' : 'is-expanded-view' ?>">
        <aside class="measurement-browser" aria-label="Find measurement sessions">
            <form method="get" class="measurement-search">
                <input type="hidden" name="view" value="<?= collectionStewardEscape($viewMode) ?>">
                <input type="hidden" name="scope" value="<?= collectionStewardEscape($compactScope) ?>">
                <?php if ($showAllMeasurementSessions): ?>
                    <input type="hidden" name="all_sessions" value="1">
                <?php endif; ?>
                <?php if ($compactScope === 'group' && $groupProductionId !== null): ?>
                    <input type="hidden" name="group_production_id" value="<?= $groupProductionId ?>">
                <?php endif; ?>
                <?php if ($compactScope === 'group' && $groupActorSearch !== ''): ?>
                    <input type="hidden" name="group_actor_search" value="<?= collectionStewardEscape($groupActorSearch) ?>">
                <?php endif; ?>
                <div class="field">
                    <label for="actor_search">Find an actor</label>
                    <input
                        type="search"
                        id="actor_search"
                        name="actor_search"
                        value="<?= collectionStewardEscape($actorSearch) ?>"
                        placeholder="First or last name"
                    >
                </div>
                <button type="submit">Search</button>
                <?php if ($actorSearch !== ''): ?>
                    <a href="/measurements.php?<?= collectionStewardEscape(http_build_query($clearSearchLinkParameters)) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <div class="measurement-list-tabs" aria-label="Session lists">
                <a href="/measurements.php?<?= collectionStewardEscape(http_build_query($reviewListLinkParameters)) ?>" <?= $listMode === 'review' && $actorSearch === '' ? 'aria-current="page"' : '' ?>>Needs review</a>
                <a href="/measurements.php?<?= collectionStewardEscape(http_build_query($recentListLinkParameters)) ?>" <?= $listMode === 'recent' && $actorSearch === '' ? 'aria-current="page"' : '' ?>>Recent</a>
            </div>

            <div class="measurement-session-list">
                <?php if ($sessionList === []): ?>
                    <p class="empty-results">
                        <?= $actorSearch !== '' ? 'No actor matched that search.' : 'No sessions are waiting for review.' ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($sessionList as $sessionChoice): ?>
                        <?php
                        $sessionLinkParameters = [
                            'session_id' => (int) $sessionChoice['measurement_session_id'],
                            'view' => $viewMode,
                        ];
                        $sessionLinkParameters = array_merge(
                            $sessionLinkParameters,
                            $compactContextParameters
                        );
                        if ($actorSearch !== '') {
                            $sessionLinkParameters['actor_search'] = $actorSearch;
                        } else {
                            $sessionLinkParameters['list'] = $listMode;
                        }
                        ?>
                        <a
                            class="measurement-session-link <?= $requestedSessionId === (int) $sessionChoice['measurement_session_id'] ? 'is-current' : '' ?>"
                            href="/measurements.php?<?= collectionStewardEscape(http_build_query($sessionLinkParameters)) ?>"
                        >
                            <span class="measurement-session-name"><?= collectionStewardEscape($sessionChoice['actor_name']) ?></span>
                            <span><?= collectionStewardEscape($sessionChoice['production_name'] ?: 'General fitting') ?></span>
                            <small>
                                <?= collectionStewardEscape(measurementFormattedDate($sessionChoice['measured_on'], $sessionChoice['date_precision'])) ?>
                                <?php if ((int) $sessionChoice['flagged_value_count'] > 0): ?>
                                    · <?= (int) $sessionChoice['flagged_value_count'] ?> flagged
                                <?php endif; ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <details class="measurement-create" id="new-session" <?= isset($_GET['production_created']) ? 'open' : '' ?>>
                <summary>Record a new measurement session</summary>
                <p class="help">Choose an actor already on file, or leave that list blank and enter a new actor.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_session">

                    <div class="field">
                        <label for="person_id">Existing actor</label>
                        <select id="person_id" name="person_id">
                            <option value="">New actor</option>
                            <?php foreach ($people as $person): ?>
                                <option value="<?= (int) $person['id'] ?>" <?= (string) $person['id'] === $newSessionValues['person_id'] ? 'selected' : '' ?>>
                                    <?= collectionStewardEscape(measurementActorLabel($person)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="new-actor-fields">
                        <div class="field">
                            <label for="new_first_name">New first name</label>
                            <input type="text" id="new_first_name" name="first_name" maxlength="100" value="<?= collectionStewardEscape($newSessionValues['first_name']) ?>">
                        </div>
                        <div class="field">
                            <label for="new_last_name">New last name</label>
                            <input type="text" id="new_last_name" name="last_name" maxlength="100" value="<?= collectionStewardEscape($newSessionValues['last_name']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="new_session_production_id">Production</label>
                        <select id="new_session_production_id" name="production_id">
                            <option value="">General fitting / no production</option>
                            <?php foreach ($productions as $production): ?>
                                <option value="<?= (int) $production['id'] ?>" <?= (string) $production['id'] === $newSessionValues['production_id'] ? 'selected' : '' ?>>
                                    <?= collectionStewardEscape(measurementProductionLabel($production)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="new_measured_on">Date measured</label>
                        <input type="date" id="new_measured_on" name="measured_on" value="<?= collectionStewardEscape($newSessionValues['measured_on']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="new_character_name">Character (optional)</label>
                        <input type="text" id="new_character_name" name="character_name" maxlength="150" value="<?= collectionStewardEscape($newSessionValues['character_name']) ?>">
                        <span class="help">Additional parts can be added after creating the session.</span>
                    </div>

                    <div class="field">
                        <label for="new_session_notes">Notes (optional)</label>
                        <textarea id="new_session_notes" name="notes" maxlength="5000"><?= collectionStewardEscape($newSessionValues['notes']) ?></textarea>
                    </div>

                    <button type="submit">Create session and enter measurements</button>
                </form>
            </details>

            <details class="measurement-create">
                <summary>Add a production or venue</summary>
                <p class="help">Use this first when the production does not yet appear in the fitting form.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_production">

                    <div class="field">
                        <label for="production_name">Production name</label>
                        <input type="text" id="production_name" name="production_name" maxlength="150" value="<?= collectionStewardEscape($newProductionValues['production_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="production_year">Production year</label>
                        <input type="number" id="production_year" name="production_year" min="1900" max="2200" value="<?= collectionStewardEscape($newProductionValues['production_year']) ?>">
                    </div>
                    <div class="field">
                        <label for="venue_id">Existing venue</label>
                        <select id="venue_id" name="venue_id">
                            <option value="">No venue selected</option>
                            <?php foreach ($venues as $venue): ?>
                                <option value="<?= (int) $venue['id'] ?>" <?= (string) $venue['id'] === $newProductionValues['venue_id'] ? 'selected' : '' ?>>
                                    <?= collectionStewardEscape($venue['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="new_venue_name">Or new venue</label>
                        <input type="text" id="new_venue_name" name="new_venue_name" maxlength="150" value="<?= collectionStewardEscape($newProductionValues['new_venue_name']) ?>">
                        <span class="help">A new venue name takes precedence over the existing-venue choice.</span>
                    </div>
                    <div class="field">
                        <label for="opening_date">Opening date (optional)</label>
                        <input type="date" id="opening_date" name="opening_date" value="<?= collectionStewardEscape($newProductionValues['opening_date']) ?>">
                    </div>
                    <button type="submit">Add production</button>
                </form>
            </details>
        </aside>

        <div class="measurement-editor">
            <?php if ($selectedSession === null): ?>
                <div class="measurement-empty-state">
                    <h2>Choose a measurement session</h2>
                    <p>Use the review list or actor search to open a session.</p>
                </div>
            <?php else: ?>
                <header class="measurement-session-header">
                    <div>
                        <span class="status-badge status-<?= collectionStewardEscape($selectedSession['review_status']) ?>">
                            <?= collectionStewardEscape(ucwords(str_replace('_', ' ', $selectedSession['review_status']))) ?>
                        </span>
                        <h2><?= collectionStewardEscape($selectedSession['actor_name']) ?></h2>
                        <p>
                            <?= collectionStewardEscape($selectedSession['production_name'] ?: 'General fitting') ?>
                            <?php if (!empty($selectedSession['production_year'])): ?>
                                · <?= (int) $selectedSession['production_year'] ?>
                            <?php endif; ?>
                            <?php if (!empty($selectedSession['venue_name'])): ?>
                                · <?= collectionStewardEscape($selectedSession['venue_name']) ?>
                            <?php endif; ?>
                        </p>
                        <p>
                            Measured <?= collectionStewardEscape(measurementFormattedDate($selectedSession['measured_on'], $selectedSession['date_precision'])) ?>
                            <?php if (!empty($selectedSession['characters'])): ?>
                                · <?= collectionStewardEscape($selectedSession['characters']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="session-reference">Session <?= (int) $selectedSession['measurement_session_id'] ?></span>
                </header>

                <div class="measurement-view-controls">
                    <div class="measurement-switches">
                        <div class="measurement-view-switch" aria-label="Measurement layout">
                            <span>Layout</span>
                            <a
                                href="/measurements.php?<?= collectionStewardEscape(http_build_query($compactViewLinkParameters)) ?>#compact-measurements-title"
                                <?= $viewMode === 'compact' ? 'aria-current="page"' : '' ?>
                            >Compact</a>
                            <a
                                href="/measurements.php?<?= collectionStewardEscape(http_build_query($expandedViewLinkParameters)) ?>"
                                <?= $viewMode === 'expanded' ? 'aria-current="page"' : '' ?>
                            >Expanded</a>
                        </div>

                        <?php if ($viewMode === 'compact'): ?>
                            <div class="measurement-view-switch measurement-scope-switch" aria-label="Compact table scope">
                                <span>Display</span>
                                <a
                                    href="/measurements.php?<?= collectionStewardEscape(http_build_query($actorScopeLinkParameters)) ?>#compact-measurements-title"
                                    <?= $compactScope === 'actor' ? 'aria-current="page"' : '' ?>
                                >Selected actor</a>
                                <a
                                    href="/measurements.php?<?= collectionStewardEscape(http_build_query($groupScopeLinkParameters)) ?>#compact-measurements-title"
                                    <?= $compactScope === 'group' ? 'aria-current="page"' : '' ?>
                                >Production cast</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($viewMode === 'compact' && $compactScope === 'actor'): ?>
                        <form method="get" action="/measurements.php#compact-measurements-title" class="actor-session-toggle" id="actor-session-toggle">
                            <input type="hidden" name="session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">
                            <input type="hidden" name="view" value="compact">
                            <input type="hidden" name="scope" value="actor">
                            <?php if ($actorSearch !== ''): ?>
                                <input type="hidden" name="actor_search" value="<?= collectionStewardEscape($actorSearch) ?>">
                            <?php else: ?>
                                <input type="hidden" name="list" value="<?= collectionStewardEscape($listMode) ?>">
                            <?php endif; ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="all_sessions"
                                    value="1"
                                    <?= $showAllActorSessions ? 'checked' : '' ?>
                                    data-submit-on-change
                                >
                                Show all measurement dates for this actor
                            </label>
                            <noscript><button type="submit" class="secondary">Apply</button></noscript>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($viewMode === 'compact' && $compactScope === 'group'): ?>
                    <form method="get" action="/measurements.php#compact-measurements-title" class="measurement-group-query">
                        <input type="hidden" name="session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">
                        <input type="hidden" name="view" value="compact">
                        <input type="hidden" name="scope" value="group">
                        <?php if ($actorSearch !== ''): ?>
                            <input type="hidden" name="actor_search" value="<?= collectionStewardEscape($actorSearch) ?>">
                        <?php else: ?>
                            <input type="hidden" name="list" value="<?= collectionStewardEscape($listMode) ?>">
                        <?php endif; ?>

                        <div class="field">
                            <label for="group_production_id">Production cast</label>
                            <select id="group_production_id" name="group_production_id" required>
                                <?php foreach ($productions as $production): ?>
                                    <option value="<?= (int) $production['id'] ?>" <?= (int) $production['id'] === $groupProductionId ? 'selected' : '' ?>>
                                        <?= collectionStewardEscape(measurementProductionLabel($production)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="group_actor_search">Actor name contains</label>
                            <input
                                type="search"
                                id="group_actor_search"
                                name="group_actor_search"
                                value="<?= collectionStewardEscape($groupActorSearch) ?>"
                                placeholder="Optional"
                            >
                        </div>

                        <label class="group-all-sessions-toggle">
                            <input
                                type="checkbox"
                                name="all_sessions"
                                value="1"
                                <?= $showAllMeasurementSessions ? 'checked' : '' ?>
                            >
                            Include all measurement dates
                        </label>

                        <button type="submit">Update</button>
                    </form>
                <?php endif; ?>

                <?php if ($viewMode === 'compact'): ?>
                    <section class="compact-measurements" aria-labelledby="compact-measurements-title">
                        <div class="section-heading compact-measurement-heading">
                            <div>
                                <h3 id="compact-measurements-title">
                                    <?= $compactScope === 'group' ? 'Cast measurements' : 'Measurements' ?>
                                </h3>
                                <?php if ($compactScope === 'group' && $selectedGroupProduction !== null): ?>
                                    <p class="compact-group-name">
                                        <?= collectionStewardEscape(measurementProductionLabel($selectedGroupProduction)) ?>
                                    </p>
                                <?php endif; ?>
                                <p class="help">
                                    Blank means not measured. Select a date to make it the current session; select a value to edit it in Expanded layout. Scroll vertically among actors and horizontally among measurements.
                                </p>
                            </div>
                            <div class="compact-heading-actions">
                                <span class="compact-session-count">
                                    <?php if ($compactScope === 'group'): ?>
                                        <?= $groupActorCount ?> actor<?= $groupActorCount === 1 ? '' : 's' ?> ·
                                    <?php endif; ?>
                                    <?= count($compactSessions) ?> row<?= count($compactSessions) === 1 ? '' : 's' ?> shown
                                </span>
                                <?php if ($compactSessions !== []): ?>
                                    <div class="measurement-print-actions" aria-label="Printable worksheets">
                                        <button type="button" class="secondary" data-print-worksheet="current">Print current measurements</button>
                                        <button type="button" class="secondary" data-print-worksheet="blank">Print blank worksheet</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <header class="print-worksheet-heading">
                            <h1>
                                <span class="print-current-label">Current measurements</span>
                                <span class="print-blank-label">Blank measurement worksheet</span>
                            </h1>
                            <p>
                                <?php if ($compactScope === 'group' && $selectedGroupProduction !== null): ?>
                                    <?= collectionStewardEscape(measurementProductionLabel($selectedGroupProduction)) ?>
                                <?php else: ?>
                                    <?= collectionStewardEscape($selectedSession['actor_name']) ?>
                                <?php endif; ?>
                            </p>
                        </header>

                        <?php if ($compactSessions !== []): ?>
                            <details class="measurement-print-columns">
                                <summary>Choose columns to print</summary>
                                <p class="help">Actor and measurement date are included automatically. Choose the measurement columns for both printable worksheets.</p>
                                <div class="measurement-print-column-actions">
                                    <button type="button" class="secondary" data-print-columns="all">Select all</button>
                                    <button type="button" class="secondary" data-print-columns="none">Clear all</button>
                                </div>
                                <div class="measurement-print-column-list">
                                    <?php foreach ($compactMeasurementTypes as $measurementColumnIndex => $measurementType): ?>
                                        <label>
                                            <input
                                                type="checkbox"
                                                value="<?= (int) $measurementColumnIndex ?>"
                                                data-print-column
                                                checked
                                            >
                                            <?= collectionStewardEscape($measurementType['name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <?php if ($compactSessions === []): ?>
                            <p class="compact-empty-results">
                                No actors in this production matched the query.
                            </p>
                        <?php else: ?>
                            <div class="compact-table-scroll" tabindex="0" aria-label="Scrollable actor measurement comparison">
                            <table class="compact-measurement-table <?= $compactScope === 'group' ? 'has-actor-column' : '' ?>">
                                <thead>
                                    <tr>
                                        <?php if ($compactScope === 'group'): ?>
                                            <th scope="col" class="compact-actor-column">Actor</th>
                                        <?php endif; ?>
                                        <th scope="col" class="compact-session-column">Measurement date</th>
                                        <?php foreach ($compactMeasurementTypes as $measurementType): ?>
                                            <th scope="col">
                                                <span><?= collectionStewardEscape($measurementType['name']) ?></span>
                                                <?php if (!empty($measurementType['unit'])): ?>
                                                    <small>(<?= collectionStewardEscape(measurementCompactUnit($measurementType['unit'])) ?>)</small>
                                                <?php endif; ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($compactSessions as $compactSession): ?>
                                        <?php
                                        $compactSessionId = $compactSession['measurement_session_id'] === null
                                            ? null
                                            : (int) $compactSession['measurement_session_id'];
                                        $compactSessionIsCurrent = $compactSessionId !== null
                                            && $compactSessionId
                                            === (int) $selectedSession['measurement_session_id'];
                                        $compactActorName = $compactScope === 'group'
                                            ? (string) $compactSession['actor_name']
                                            : (string) $selectedSession['actor_name'];
                                        $compactSessionLinkParameters = null;
                                        $expandedCellLinkParameters = null;

                                        if ($compactSessionId !== null) {
                                            $compactSessionLinkParameters = array_merge(
                                                [
                                                    'session_id' => $compactSessionId,
                                                    'view' => 'compact',
                                                ],
                                                $compactContextParameters
                                            );
                                            $expandedCellLinkParameters = array_merge(
                                                [
                                                    'session_id' => $compactSessionId,
                                                    'view' => 'expanded',
                                                ],
                                                $compactContextParameters
                                            );
                                            if ($actorSearch !== '') {
                                                $compactSessionLinkParameters['actor_search'] = $actorSearch;
                                                $expandedCellLinkParameters['actor_search'] = $actorSearch;
                                            } else {
                                                $compactSessionLinkParameters['list'] = $listMode;
                                                $expandedCellLinkParameters['list'] = $listMode;
                                            }
                                        }
                                        ?>
                                        <tr class="<?= $compactSessionIsCurrent ? 'is-current' : '' ?> <?= (int) $compactSession['flagged_value_count'] > 0 ? 'has-flagged-values' : '' ?>">
                                            <?php if ($compactScope === 'group'): ?>
                                                <th scope="row" class="compact-actor-column">
                                                    <span><?= collectionStewardEscape($compactSession['actor_name']) ?></span>
                                                    <?php if (!empty($compactSession['group_characters'])): ?>
                                                        <small><?= collectionStewardEscape($compactSession['group_characters']) ?></small>
                                                    <?php endif; ?>
                                                </th>
                                                <td class="compact-session-column">
                                                    <?php if ($compactSessionId === null): ?>
                                                        <span class="compact-no-session">No measurements recorded</span>
                                                    <?php else: ?>
                                                        <a href="/measurements.php?<?= collectionStewardEscape(http_build_query($compactSessionLinkParameters)) ?>">
                                                            <?= collectionStewardEscape(measurementFormattedDate($compactSession['measured_on'], $compactSession['date_precision'])) ?>
                                                        </a>
                                                        <small>
                                                            <?= collectionStewardEscape($compactSession['production_name'] ?: 'General fitting') ?>
                                                            <?= !empty($compactSession['production_year']) ? ' · ' . (int) $compactSession['production_year'] : '' ?>
                                                        </small>
                                                        <?php if ((int) $compactSession['flagged_value_count'] > 0): ?>
                                                            <span class="compact-row-flag"><?= (int) $compactSession['flagged_value_count'] ?> flagged</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php else: ?>
                                                <th scope="row" class="compact-session-column">
                                                    <a href="/measurements.php?<?= collectionStewardEscape(http_build_query($compactSessionLinkParameters)) ?>">
                                                        <?= collectionStewardEscape(measurementFormattedDate($compactSession['measured_on'], $compactSession['date_precision'])) ?>
                                                    </a>
                                                    <small>
                                                        <?= collectionStewardEscape($compactSession['production_name'] ?: 'General fitting') ?>
                                                        <?= !empty($compactSession['production_year']) ? ' · ' . (int) $compactSession['production_year'] : '' ?>
                                                    </small>
                                                    <?php if ((int) $compactSession['flagged_value_count'] > 0): ?>
                                                        <span class="compact-row-flag"><?= (int) $compactSession['flagged_value_count'] ?> flagged</span>
                                                    <?php endif; ?>
                                                </th>
                                            <?php endif; ?>

                                            <?php foreach ($compactMeasurementTypes as $measurementType): ?>
                                                <?php
                                                $measurementTypeId = (int) $measurementType['measurement_type_id'];
                                                $compactValue = $compactSessionId === null
                                                    ? null
                                                    : ($compactValues[$compactSessionId][$measurementTypeId] ?? null);
                                                $compactDisplayValue = $compactValue === null
                                                    ? ''
                                                    : measurementCompactDisplayValue($compactValue);
                                                $compactValueIsFlagged = $compactValue !== null
                                                    && (int) $compactValue['needs_review'] === 1;
                                                $compactValueWasCorrected = $compactValue !== null
                                                    && measurementValueDiffersFromImport($compactValue);
                                                $compactValueTitle = '';
                                                if ($compactValue !== null) {
                                                    $compactValueTitle = 'Edit '
                                                        . $measurementType['name']
                                                        . ' — '
                                                        . $compactActorName
                                                        . ', '
                                                        . measurementFormattedDate(
                                                            $compactSession['measured_on'],
                                                            $compactSession['date_precision']
                                                        );
                                                    if ($compactValueWasCorrected) {
                                                        $compactValueTitle .= '. Original import: '
                                                            . $compactValue['raw_value'];
                                                    }
                                                    if ($compactValueIsFlagged) {
                                                        $compactValueTitle .= '. Needs review.';
                                                    }
                                                }
                                                ?>
                                                <td class="<?= $compactValueIsFlagged ? 'needs-review' : '' ?> <?= $compactValueWasCorrected ? 'was-corrected' : '' ?>">
                                                    <?php if ($compactValue === null || $compactDisplayValue === ''): ?>
                                                        <span class="visually-hidden">Not measured</span>
                                                    <?php else: ?>
                                                        <a
                                                            class="compact-value-link"
                                                            href="/measurements.php?<?= collectionStewardEscape(http_build_query($expandedCellLinkParameters)) ?>#measurement-card-<?= $measurementTypeId ?>"
                                                            title="<?= collectionStewardEscape($compactValueTitle) ?>"
                                                        >
                                                            <span><?= collectionStewardEscape($compactDisplayValue) ?></span>
                                                            <?php if ($compactValueIsFlagged): ?>
                                                                <span class="compact-value-marker flag" aria-label="Needs review">!</span>
                                                            <?php endif; ?>
                                                            <?php if ($compactValueWasCorrected): ?>
                                                                <span class="compact-value-marker corrected" aria-label="Differs from original import">*</span>
                                                            <?php endif; ?>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <p class="compact-measurement-legend">
                            <span><strong>!</strong> Needs review</span>
                            <span><strong>*</strong> Differs from original import</span>
                        </p>
                    </section>
                    <div id="print-worksheet-pages" class="print-worksheet-pages" aria-hidden="true"></div>
                <?php else: ?>

                <details class="session-details-panel">
                    <summary>Edit actor, production, date, or notes</summary>
                    <form method="post" class="session-details-form">
                        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_session_details">
                        <input type="hidden" name="measurement_session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">

                        <div class="field">
                            <label for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" maxlength="100" value="<?= collectionStewardEscape($selectedSession['first_name'] ?: $selectedSession['actor_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="last_name">Last name</label>
                            <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= collectionStewardEscape($selectedSession['last_name']) ?>">
                        </div>
                        <div class="field">
                            <label for="session_production_id">Production</label>
                            <select id="session_production_id" name="production_id">
                                <option value="">General fitting / no production</option>
                                <?php foreach ($productions as $production): ?>
                                    <option value="<?= (int) $production['id'] ?>" <?= (int) $production['id'] === (int) $selectedSession['production_id'] ? 'selected' : '' ?>>
                                        <?= collectionStewardEscape(measurementProductionLabel($production)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="measured_on">Stored date</label>
                            <input type="date" id="measured_on" name="measured_on" value="<?= collectionStewardEscape($selectedSession['measured_on']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="date_precision">How exact is the date?</label>
                            <select id="date_precision" name="date_precision">
                                <?php foreach ([
                                    'day' => 'Exact day',
                                    'month' => 'Month only',
                                    'year' => 'Year only',
                                    'unknown' => 'Date uncertain',
                                ] as $precision => $label): ?>
                                    <option value="<?= $precision ?>" <?= $selectedSession['date_precision'] === $precision ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field session-notes-field">
                            <label for="session_notes">Session notes</label>
                            <textarea id="session_notes" name="notes" maxlength="5000"><?= collectionStewardEscape($selectedSession['notes']) ?></textarea>
                        </div>
                        <button type="submit">Save session details</button>
                    </form>
                </details>

                <section class="character-panel" aria-labelledby="characters-title">
                    <div class="section-heading">
                        <div>
                            <h3 id="characters-title">Characters in this production</h3>
                            <p class="help">One actor may have several parts in the same production.</p>
                        </div>
                    </div>

                    <?php if ($selectedSession['production_id'] === null): ?>
                        <p>Choose a production in Session details before adding a character.</p>
                    <?php else: ?>
                        <?php foreach ($selectedRoles as $role): ?>
                            <form method="post" class="character-row">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="save_character">
                                <input type="hidden" name="measurement_session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">
                                <input type="hidden" name="production_cast_id" value="<?= (int) $role['id'] ?>">
                                <label class="visually-hidden" for="character_<?= (int) $role['id'] ?>">Character name</label>
                                <input type="text" id="character_<?= (int) $role['id'] ?>" name="character_name" maxlength="150" value="<?= collectionStewardEscape($role['character_name']) ?>" required>
                                <button type="submit" class="secondary">Save name</button>
                            </form>
                        <?php endforeach; ?>

                        <form method="post" class="character-row add-character-row">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="add_character">
                            <input type="hidden" name="measurement_session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">
                            <label class="visually-hidden" for="add_character_name">Additional character</label>
                            <input type="text" id="add_character_name" name="character_name" maxlength="150" placeholder="Add another character" required>
                            <button type="submit">Add character</button>
                        </form>
                    <?php endif; ?>
                </section>

                <form method="post" class="measurement-values-form">
                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_session_values">
                    <input type="hidden" name="measurement_session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">

                    <div class="section-heading">
                        <div>
                            <h3>Measurements</h3>
                            <p class="help">Blank means not measured. For an incorrect imported value, enter the correction or check Remove.</p>
                        </div>
                        <?php if ((int) $selectedSession['flagged_value_count'] > 0): ?>
                            <span class="flag-count"><?= (int) $selectedSession['flagged_value_count'] ?> still flagged</span>
                        <?php endif; ?>
                    </div>

                    <div class="measurement-value-grid">
                        <?php foreach ($selectedMeasurements as $measurement): ?>
                            <?php
                            $measurementTypeId = (int) $measurement['measurement_type_id'];
                            $hasValue = $measurement['measurement_value_id'] !== null;
                            $isFlagged = (int) ($measurement['needs_review'] ?? 0) === 1;
                            $inputValue = measurementInputValue($measurement);
                            $valueStatus = $hasValue
                                && $measurement['value_status'] === 'not_applicable'
                                ? 'not_applicable'
                                : 'recorded';
                            ?>
                            <article id="measurement-card-<?= $measurementTypeId ?>" class="measurement-value-card <?= $isFlagged ? 'needs-review' : '' ?>">
                                <div class="measurement-value-heading">
                                    <label for="measurement_<?= $measurementTypeId ?>"><?= collectionStewardEscape($measurement['name']) ?></label>
                                    <?php if ($isFlagged): ?>
                                        <span class="review-flag">Review</span>
                                    <?php endif; ?>
                                </div>

                                <div class="measurement-input-with-unit">
                                    <input
                                        type="<?= $measurement['value_kind'] === 'number' ? 'number' : 'text' ?>"
                                        id="measurement_<?= $measurementTypeId ?>"
                                        name="values[<?= $measurementTypeId ?>][value]"
                                        value="<?= collectionStewardEscape($inputValue) ?>"
                                        <?= $measurement['value_kind'] === 'number' ? 'min="0" step="0.01" inputmode="decimal"' : 'maxlength="255"' ?>
                                    >
                                    <?php if (!empty($measurement['unit'])): ?>
                                        <span><?= collectionStewardEscape($measurement['unit']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <label class="measurement-status-label" for="measurement_status_<?= $measurementTypeId ?>">Status</label>
                                <select id="measurement_status_<?= $measurementTypeId ?>" name="values[<?= $measurementTypeId ?>][status]">
                                    <option value="recorded" <?= $valueStatus === 'recorded' ? 'selected' : '' ?>>Recorded</option>
                                    <option value="not_applicable" <?= $valueStatus === 'not_applicable' ? 'selected' : '' ?>>Not applicable</option>
                                </select>

                                <?php if ($hasValue && $measurement['source_import_cell_id'] !== null): ?>
                                    <p class="original-value">
                                        Original import: <strong><?= collectionStewardEscape($measurement['raw_value']) ?></strong>
                                    </p>
                                <?php endif; ?>

                                <?php if ($isFlagged && !empty($measurement['review_notes'])): ?>
                                    <p class="review-note"><?= collectionStewardEscape($measurement['review_notes']) ?></p>
                                <?php endif; ?>

                                <?php if ($isFlagged && ($inputValue !== '' || $valueStatus === 'not_applicable')): ?>
                                    <label class="accept-value">
                                        <input type="checkbox" name="values[<?= $measurementTypeId ?>][accept]" value="1">
                                        Accept the displayed value
                                    </label>
                                <?php endif; ?>

                                <?php if ($hasValue): ?>
                                    <label class="remove-value">
                                        <input type="checkbox" name="values[<?= $measurementTypeId ?>][remove]" value="1">
                                        Remove this stored value
                                    </label>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="sticky-save-row">
                        <span>Only corrected, removed, or explicitly accepted flagged values are cleared.</span>
                        <button type="submit">Save measurement changes</button>
                    </div>
                </form>

                <?php if ($selectedSession['review_status'] === 'needs_review'): ?>
                <section class="finish-review-panel">
                    <h3>Finish this review</h3>
                    <?php if ((int) $selectedSession['flagged_value_count'] > 0): ?>
                        <p>Save a decision for the <?= (int) $selectedSession['flagged_value_count'] ?> flagged value<?= (int) $selectedSession['flagged_value_count'] === 1 ? '' : 's' ?> first.</p>
                    <?php elseif ((int) $selectedSession['stored_value_count'] === 0): ?>
                        <p>Enter and save at least one measurement before finishing this review.</p>
                    <?php else: ?>
                        <p>When the actor, production, characters, date, and measurements are correct, remove this session from the review queue.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="finish_review">
                            <input type="hidden" name="measurement_session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">
                            <button type="submit">Mark session reviewed</button>
                        </form>
                    <?php endif; ?>
                </section>
                <?php else: ?>
                <section class="finish-review-panel">
                    <h3>Review this session again</h3>
                    <p>Return this session to the review queue if its details or measurements need another review.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="requeue_review">
                        <input type="hidden" name="measurement_session_id" value="<?= (int) $selectedSession['measurement_session_id'] ?>">
                        <button type="submit" class="secondary">Return to review queue</button>
                    </form>
                </section>
                <?php endif; ?>

                <section class="actor-history" aria-labelledby="actor-history-title">
                    <h3 id="actor-history-title"><?= collectionStewardEscape($selectedSession['actor_name']) ?>’s measurement history</h3>
                    <div class="history-list">
                        <?php foreach ($actorHistory as $historySession): ?>
                            <a class="history-row <?= (int) $historySession['measurement_session_id'] === (int) $selectedSession['measurement_session_id'] ? 'is-current' : '' ?>" href="/measurements.php?session_id=<?= (int) $historySession['measurement_session_id'] ?>">
                                <span><?= collectionStewardEscape(measurementFormattedDate($historySession['measured_on'], $historySession['date_precision'])) ?></span>
                                <span><?= collectionStewardEscape($historySession['production_name'] ?: 'General fitting') ?><?= !empty($historySession['production_year']) ? ' · ' . (int) $historySession['production_year'] : '' ?></span>
                                <span><?= (int) $historySession['measurement_count'] ?> values</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>
document.querySelectorAll('[data-submit-on-change]').forEach(function (input) {
    input.addEventListener('change', function () {
        input.form.requestSubmit();
    });
});

const buildPrintableWorksheetPages = function (isBlankWorksheet) {
    const sourceTable = document.querySelector(
        '.compact-table-scroll .compact-measurement-table'
    );
    const printPages = document.getElementById('print-worksheet-pages');
    const sourceHeading = document.querySelector(
        '.compact-measurements .print-worksheet-heading'
    );

    if (!sourceTable || !printPages || !sourceHeading) {
        return;
    }

    const sourceHeaders = Array.from(
        sourceTable.querySelectorAll('thead tr > th')
    );
    const sourceRows = Array.from(sourceTable.querySelectorAll('tbody > tr'));
    const fixedColumnCount = sourceTable.classList.contains('has-actor-column')
        ? 2
        : 1;
    const measurementsPerPage = 7;
    const selectedMeasurementColumns = Array.from(
        document.querySelectorAll('[data-print-column]:checked')
    ).map(function (input) {
        return fixedColumnCount + Number.parseInt(input.value, 10);
    }).filter(function (columnIndex) {
        return Number.isInteger(columnIndex)
            && columnIndex >= fixedColumnCount
            && columnIndex < sourceHeaders.length;
    });

    if (selectedMeasurementColumns.length === 0) {
        window.alert('Choose at least one measurement column to print.');
        return false;
    }

    const pageCount = Math.ceil(
        selectedMeasurementColumns.length / measurementsPerPage
    );

    printPages.replaceChildren();

    for (let pageIndex = 0; pageIndex < pageCount; pageIndex += 1) {
        const pageMeasurementColumns = selectedMeasurementColumns.slice(
            pageIndex * measurementsPerPage,
            (pageIndex + 1) * measurementsPerPage
        );
        const columnIndexes = [];

        for (let index = 0; index < fixedColumnCount; index += 1) {
            columnIndexes.push(index);
        }
        columnIndexes.push(...pageMeasurementColumns);

        const page = document.createElement('section');
        page.className = 'print-worksheet-page';

        const heading = sourceHeading.cloneNode(true);
        const firstMeasurementName =
            sourceHeaders[pageMeasurementColumns[0]].textContent.trim();
        const lastMeasurementName =
            sourceHeaders[
                pageMeasurementColumns[pageMeasurementColumns.length - 1]
            ].textContent.trim();
        const range = document.createElement('p');
        range.className = 'print-measurement-range';
        range.textContent = 'Page '
            + (pageIndex + 1)
            + ' of '
            + pageCount
            + ' — '
            + firstMeasurementName
            + ' through '
            + lastMeasurementName;
        heading.appendChild(range);
        page.appendChild(heading);

        const table = document.createElement('table');
        table.className = sourceTable.className + ' print-chunk-table';
        const tableHead = table.createTHead();
        const headingRow = tableHead.insertRow();
        columnIndexes.forEach(function (columnIndex) {
            headingRow.appendChild(sourceHeaders[columnIndex].cloneNode(true));
        });

        const tableBody = table.createTBody();
        sourceRows.forEach(function (sourceRow) {
            const row = tableBody.insertRow();
            row.className = sourceRow.className;
            const sourceCells = Array.from(sourceRow.children);
            columnIndexes.forEach(function (columnIndex) {
                row.appendChild(sourceCells[columnIndex].cloneNode(true));
            });
        });

        if (isBlankWorksheet) {
            const minimumBlankRows = 12;
            for (
                let rowIndex = sourceRows.length;
                rowIndex < minimumBlankRows;
                rowIndex += 1
            ) {
                const row = tableBody.insertRow();
                columnIndexes.forEach(function (columnIndex) {
                    const sourceCell = sourceRows[0].children[columnIndex];
                    const cell = document.createElement(
                        sourceCell.tagName.toLowerCase()
                    );
                    if (sourceCell.hasAttribute('scope')) {
                        cell.setAttribute('scope', sourceCell.getAttribute('scope'));
                    }
                    cell.className = sourceCell.className;
                    cell.innerHTML = '&nbsp;';
                    row.appendChild(cell);
                });
            }
        }

        page.appendChild(table);
        printPages.appendChild(page);
    }

    return true;
};

document.querySelectorAll('[data-print-columns]').forEach(function (button) {
    button.addEventListener('click', function () {
        const shouldSelect = button.dataset.printColumns === 'all';
        document.querySelectorAll('[data-print-column]').forEach(function (input) {
            input.checked = shouldSelect;
        });
    });
});

document.querySelectorAll('[data-print-worksheet]').forEach(function (button) {
    button.addEventListener('click', function () {
        const isBlankWorksheet = button.dataset.printWorksheet === 'blank';
        if (!buildPrintableWorksheetPages(isBlankWorksheet)) {
            return;
        }
        document.body.classList.toggle('print-blank-worksheet', isBlankWorksheet);
        document.body.classList.toggle('print-current-worksheet', !isBlankWorksheet);
        window.print();
    });
});

window.addEventListener('afterprint', function () {
    document.body.classList.remove(
        'print-blank-worksheet',
        'print-current-worksheet'
    );
    const printPages = document.getElementById('print-worksheet-pages');
    if (printPages) {
        printPages.replaceChildren();
    }
});

const compactTableScroller = document.querySelector('.compact-table-scroll');
if (compactTableScroller) {
    const horizontalScrollKey = 'collectionSteward.measurements.scrollLeft';
    try {
        const savedHorizontalScroll = Number.parseInt(
            sessionStorage.getItem(horizontalScrollKey) || '0',
            10
        );
        if (Number.isFinite(savedHorizontalScroll)) {
            compactTableScroller.scrollLeft = savedHorizontalScroll;
        }
        compactTableScroller.addEventListener('scroll', function () {
            sessionStorage.setItem(
                horizontalScrollKey,
                String(compactTableScroller.scrollLeft)
            );
        }, { passive: true });
    } catch (error) {
        // The table remains scrollable when browser storage is unavailable.
    }
}
</script>
</body>
</html>
