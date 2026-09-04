<?php

declare(strict_types=1);

/**
 * Production measurement-session planning and reusable templates.
 *
 * Public entry point: /production-measurements.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'measurements'
);
$csrfToken = collectionStewardCsrfToken();

function productionMeasurementText(array $source, string $key): string
{
    return is_string($source[$key] ?? null)
        ? trim($source[$key])
        : '';
}

function productionMeasurementPositiveInteger(mixed $value): ?int
{
    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $validated === false ? null : (int) $validated;
}

function productionMeasurementDateIsValid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || (
            $errors['warning_count'] === 0
            && $errors['error_count'] === 0
        ))
        && $date->format('Y-m-d') === $value;
}

function productionMeasurementIntegerList(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }

    $integers = [];
    foreach ($values as $value) {
        $integer = productionMeasurementPositiveInteger($value);
        if ($integer !== null) {
            $integers[$integer] = $integer;
        }
    }

    return array_values($integers);
}

function productionMeasurementPlaceholders(
    array $ids,
    string $prefix,
    array &$parameters
): string {
    $placeholders = [];

    foreach (array_values($ids) as $index => $id) {
        $name = $prefix . $index;
        $placeholders[] = ':' . $name;
        $parameters[$name] = (int) $id;
    }

    return implode(', ', $placeholders);
}

function productionMeasurementRedirect(
    ?int $sessionId,
    string $notice,
    array $extra = []
): void {
    $parameters = array_merge(['saved' => $notice], $extra);

    if ($sessionId !== null) {
        $parameters['session_id'] = (string) $sessionId;
    }

    header(
        'Location: /production-measurements.php?'
        . http_build_query($parameters)
    );
    exit;
}

function productionMeasurementProductionLabel(array $production): string
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

function productionMeasurementActorLabel(array $person): string
{
    $firstName = trim((string) ($person['first_name'] ?? ''));
    $lastName = trim((string) ($person['last_name'] ?? ''));

    if ($lastName !== '' && $firstName !== '') {
        return $lastName . ', ' . $firstName;
    }

    return (string) $person['actor_name'];
}

function productionMeasurementInsertActorRecords(
    PDO $connection,
    int $groupSessionId,
    int $productionId,
    int $templateId,
    string $measuredOn,
    int $measuredByUserId,
    array $personIds
): void {
    $sequenceStatement = $connection->prepare(
        'SELECT COALESCE(MAX(session_sequence), 0) + 1
         FROM measurement_sessions
         WHERE person_id = :person_id
           AND production_id = :production_id
           AND measured_on = :measured_on'
    );
    $individualStatement = $connection->prepare(
        'INSERT INTO measurement_sessions (
            person_id,
            production_id,
            production_measurement_session_id,
            measured_on,
            date_precision,
            session_sequence,
            measured_by_user_id,
            review_status
         ) VALUES (
            :person_id,
            :production_id,
            :production_measurement_session_id,
            :measured_on,
            \'day\',
            :session_sequence,
            :measured_by_user_id,
            \'unreviewed\'
         )'
    );
    $sessionTemplateStatement = $connection->prepare(
        'INSERT INTO measurement_session_templates (
            measurement_session_id,
            measurement_template_id
         ) VALUES (
            :measurement_session_id,
            :measurement_template_id
         )'
    );

    foreach ($personIds as $personId) {
        $sequenceStatement->execute([
            'person_id' => $personId,
            'production_id' => $productionId,
            'measured_on' => $measuredOn,
        ]);
        $individualStatement->execute([
            'person_id' => $personId,
            'production_id' => $productionId,
            'production_measurement_session_id' => $groupSessionId,
            'measured_on' => $measuredOn,
            'session_sequence' => (int) $sequenceStatement->fetchColumn(),
            'measured_by_user_id' => $measuredByUserId,
        ]);
        $measurementSessionId = (int) $connection->lastInsertId();
        $sessionTemplateStatement->execute([
            'measurement_session_id' => $measurementSessionId,
            'measurement_template_id' => $templateId,
        ]);
    }
}

$errors = [];
$failedAction = '';
$selectedSessionId = productionMeasurementPositiveInteger(
    $_POST['production_measurement_session_id']
        ?? $_GET['session_id']
        ?? null
);
$requestedProductionId = productionMeasurementPositiveInteger(
    $_POST['production_id'] ?? $_GET['production_id'] ?? null
);

$newSessionValues = [
    'production_id' => $requestedProductionId === null
        ? ''
        : (string) $requestedProductionId,
    'session_name' => '',
    'measured_on' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'measurement_template_id' => '',
    'notes' => '',
];
$selectedPersonIds = [];
$newTemplateValues = [
    'template_name' => '',
    'owner_name' => (string) $currentUser['display_name'],
    'description' => '',
];
$selectedTemplateTypeIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = productionMeasurementText($_POST, 'action');

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'create_template') {
                foreach (array_keys($newTemplateValues) as $field) {
                    $newTemplateValues[$field] = productionMeasurementText(
                        $_POST,
                        $field
                    );
                }
                $selectedTemplateTypeIds = productionMeasurementIntegerList(
                    $_POST['measurement_type_ids'] ?? null
                );

                if (
                    $newTemplateValues['template_name'] === ''
                    || strlen($newTemplateValues['template_name']) > 150
                ) {
                    throw new DomainException(
                        'Enter a template name of 150 characters or fewer.'
                    );
                }

                if (strlen($newTemplateValues['owner_name']) > 150) {
                    throw new DomainException(
                        'The template owner must be 150 characters or fewer.'
                    );
                }

                if (strlen($newTemplateValues['description']) > 5000) {
                    throw new DomainException(
                        'The template description must be 5,000 characters or fewer.'
                    );
                }

                if ($selectedTemplateTypeIds === []) {
                    throw new DomainException(
                        'Choose at least one measurement for the template.'
                    );
                }

                $typeParameters = [];
                $typePlaceholders = productionMeasurementPlaceholders(
                    $selectedTemplateTypeIds,
                    'template_type_',
                    $typeParameters
                );
                $typeStatement = $connection->prepare(
                    'SELECT id
                     FROM measurement_types
                     WHERE is_active = 1
                       AND id IN (' . $typePlaceholders . ')'
                );
                $typeStatement->execute($typeParameters);
                $validTypeIds = array_map(
                    'intval',
                    $typeStatement->fetchAll(PDO::FETCH_COLUMN)
                );

                if (count($validTypeIds) !== count($selectedTemplateTypeIds)) {
                    throw new DomainException(
                        'One of the selected measurements is no longer available.'
                    );
                }

                $connection->beginTransaction();
                $templateStatement = $connection->prepare(
                    'INSERT INTO measurement_templates (
                        name,
                        owner_name,
                        description,
                        is_active
                     ) VALUES (
                        :name,
                        :owner_name,
                        :description,
                        1
                     )'
                );
                $templateStatement->execute([
                    'name' => $newTemplateValues['template_name'],
                    'owner_name' => $newTemplateValues['owner_name'] !== ''
                        ? $newTemplateValues['owner_name']
                        : null,
                    'description' => $newTemplateValues['description'] !== ''
                        ? $newTemplateValues['description']
                        : null,
                ]);
                $templateId = (int) $connection->lastInsertId();

                $itemStatement = $connection->prepare(
                    'INSERT INTO measurement_template_items (
                        template_id,
                        measurement_type_id,
                        display_order
                     ) VALUES (
                        :template_id,
                        :measurement_type_id,
                        :display_order
                     )'
                );
                foreach ($selectedTemplateTypeIds as $index => $typeId) {
                    $itemStatement->execute([
                        'template_id' => $templateId,
                        'measurement_type_id' => $typeId,
                        'display_order' => $index + 1,
                    ]);
                }
                $connection->commit();

                productionMeasurementRedirect(
                    $selectedSessionId,
                    'template_created',
                    array_filter([
                        'template_id' => (string) $templateId,
                        'production_id' => $requestedProductionId === null
                            ? null
                            : (string) $requestedProductionId,
                    ], static fn (mixed $value): bool => $value !== null)
                );
            }

            if ($action === 'create_session') {
                foreach (array_keys($newSessionValues) as $field) {
                    $newSessionValues[$field] = productionMeasurementText(
                        $_POST,
                        $field
                    );
                }
                $selectedPersonIds = productionMeasurementIntegerList(
                    $_POST['person_ids'] ?? null
                );
                $productionId = productionMeasurementPositiveInteger(
                    $newSessionValues['production_id']
                );
                $templateId = productionMeasurementPositiveInteger(
                    $newSessionValues['measurement_template_id']
                );

                if ($productionId === null) {
                    throw new DomainException('Choose a production.');
                }

                if (
                    $newSessionValues['session_name'] === ''
                    || strlen($newSessionValues['session_name']) > 150
                ) {
                    throw new DomainException(
                        'Enter a session name of 150 characters or fewer.'
                    );
                }

                if (!productionMeasurementDateIsValid(
                    $newSessionValues['measured_on']
                )) {
                    throw new DomainException('Enter a valid session date.');
                }

                if ($templateId === null) {
                    throw new DomainException('Choose a measurement template.');
                }

                if (strlen($newSessionValues['notes']) > 5000) {
                    throw new DomainException(
                        'Session notes must be 5,000 characters or fewer.'
                    );
                }

                if ($selectedPersonIds === []) {
                    throw new DomainException(
                        'Choose at least one participating cast member.'
                    );
                }

                $productionStatement = $connection->prepare(
                    "SELECT id
                     FROM productions
                     WHERE id = :production_id
                       AND status IN ('planned', 'active')
                     LIMIT 1"
                );
                $productionStatement->execute([
                    'production_id' => $productionId,
                ]);

                if ($productionStatement->fetchColumn() === false) {
                    throw new DomainException(
                        'New measurement sessions require a planned or active production.'
                    );
                }

                $templateStatement = $connection->prepare(
                    'SELECT mt.id
                     FROM measurement_templates AS mt
                     WHERE mt.id = :template_id
                       AND mt.is_active = 1
                       AND EXISTS (
                           SELECT 1
                           FROM measurement_template_items AS mti
                           WHERE mti.template_id = mt.id
                       )
                     LIMIT 1'
                );
                $templateStatement->execute(['template_id' => $templateId]);

                if ($templateStatement->fetchColumn() === false) {
                    throw new DomainException(
                        'The selected template is no longer available or has no measurements.'
                    );
                }

                $castParameters = ['production_id' => $productionId];
                $castPlaceholders = productionMeasurementPlaceholders(
                    $selectedPersonIds,
                    'cast_person_',
                    $castParameters
                );
                $castStatement = $connection->prepare(
                    'SELECT DISTINCT pc.person_id
                     FROM production_cast AS pc
                     JOIN people AS pe
                        ON pe.id = pc.person_id
                     WHERE pc.production_id = :production_id
                       AND pc.is_active = 1
                       AND pe.is_active = 1
                       AND pc.person_id IN (' . $castPlaceholders . ')'
                );
                $castStatement->execute($castParameters);
                $validPersonIds = array_map(
                    'intval',
                    $castStatement->fetchAll(PDO::FETCH_COLUMN)
                );

                if (count($validPersonIds) !== count($selectedPersonIds)) {
                    throw new DomainException(
                        'One of the selected actors is no longer active in this production’s cast.'
                    );
                }

                $connection->beginTransaction();
                $groupStatement = $connection->prepare(
                    'INSERT INTO production_measurement_sessions (
                        production_id,
                        measurement_template_id,
                        session_name,
                        measured_on,
                        status,
                        notes,
                        created_by_user_id
                     ) VALUES (
                        :production_id,
                        :measurement_template_id,
                        :session_name,
                        :measured_on,
                        \'planned\',
                        :notes,
                        :created_by_user_id
                     )'
                );
                $groupStatement->execute([
                    'production_id' => $productionId,
                    'measurement_template_id' => $templateId,
                    'session_name' => $newSessionValues['session_name'],
                    'measured_on' => $newSessionValues['measured_on'],
                    'notes' => $newSessionValues['notes'] !== ''
                        ? $newSessionValues['notes']
                        : null,
                    'created_by_user_id' => (int) $currentUser['id'],
                ]);
                $groupSessionId = (int) $connection->lastInsertId();

                productionMeasurementInsertActorRecords(
                    $connection,
                    $groupSessionId,
                    $productionId,
                    $templateId,
                    $newSessionValues['measured_on'],
                    (int) $currentUser['id'],
                    $validPersonIds
                );
                $connection->commit();

                productionMeasurementRedirect(
                    $groupSessionId,
                    'session_created'
                );
            }

            if ($action === 'add_participants') {
                $sessionId = productionMeasurementPositiveInteger(
                    $_POST['production_measurement_session_id'] ?? null
                );
                $personIds = productionMeasurementIntegerList(
                    $_POST['person_ids'] ?? null
                );

                if ($sessionId === null) {
                    throw new DomainException(
                        'The production measurement session could not be identified.'
                    );
                }

                if ($personIds === []) {
                    throw new DomainException(
                        'Choose at least one additional cast member.'
                    );
                }

                $groupStatement = $connection->prepare(
                    "SELECT
                        id,
                        production_id,
                        measurement_template_id,
                        measured_on,
                        status
                     FROM production_measurement_sessions
                     WHERE id = :session_id
                     LIMIT 1"
                );
                $groupStatement->execute(['session_id' => $sessionId]);
                $groupSession = $groupStatement->fetch();

                if ($groupSession === false) {
                    throw new DomainException(
                        'The production measurement session was not found.'
                    );
                }

                if ($groupSession['status'] !== 'planned') {
                    throw new DomainException(
                        'Return the session to Planned before adding cast members.'
                    );
                }

                $castParameters = [
                    'production_id' => (int) $groupSession['production_id'],
                    'group_session_id' => $sessionId,
                ];
                $castPlaceholders = productionMeasurementPlaceholders(
                    $personIds,
                    'new_participant_',
                    $castParameters
                );
                $castStatement = $connection->prepare(
                    'SELECT DISTINCT pc.person_id
                     FROM production_cast AS pc
                     JOIN people AS pe
                        ON pe.id = pc.person_id
                     WHERE pc.production_id = :production_id
                       AND pc.is_active = 1
                       AND pe.is_active = 1
                       AND pc.person_id IN (' . $castPlaceholders . ')
                       AND NOT EXISTS (
                           SELECT 1
                           FROM measurement_sessions AS existing_participant
                           WHERE existing_participant.production_measurement_session_id = :group_session_id
                             AND existing_participant.person_id = pc.person_id
                       )'
                );
                $castStatement->execute($castParameters);
                $validPersonIds = array_map(
                    'intval',
                    $castStatement->fetchAll(PDO::FETCH_COLUMN)
                );

                if (count($validPersonIds) !== count($personIds)) {
                    throw new DomainException(
                        'One selected actor is unavailable or already belongs to this session.'
                    );
                }

                $connection->beginTransaction();
                productionMeasurementInsertActorRecords(
                    $connection,
                    $sessionId,
                    (int) $groupSession['production_id'],
                    (int) $groupSession['measurement_template_id'],
                    (string) $groupSession['measured_on'],
                    (int) $currentUser['id'],
                    $validPersonIds
                );
                $connection->commit();

                productionMeasurementRedirect(
                    $sessionId,
                    'participants_added'
                );
            }

            if ($action === 'update_session') {
                $sessionId = productionMeasurementPositiveInteger(
                    $_POST['production_measurement_session_id'] ?? null
                );
                $sessionName = productionMeasurementText(
                    $_POST,
                    'session_name'
                );
                $status = productionMeasurementText($_POST, 'status');
                $notes = productionMeasurementText($_POST, 'notes');

                if ($sessionId === null) {
                    throw new DomainException(
                        'The production measurement session could not be identified.'
                    );
                }

                if ($sessionName === '' || strlen($sessionName) > 150) {
                    throw new DomainException(
                        'Enter a session name of 150 characters or fewer.'
                    );
                }

                if (!in_array($status, ['planned', 'completed'], true)) {
                    throw new DomainException('Choose Planned or Completed.');
                }

                if (strlen($notes) > 5000) {
                    throw new DomainException(
                        'Session notes must be 5,000 characters or fewer.'
                    );
                }

                $statement = $connection->prepare(
                    'UPDATE production_measurement_sessions
                     SET session_name = :session_name,
                         status = :status,
                         notes = :notes
                     WHERE id = :session_id'
                );
                $statement->execute([
                    'session_name' => $sessionName,
                    'status' => $status,
                    'notes' => $notes !== '' ? $notes : null,
                    'session_id' => $sessionId,
                ]);

                if ($statement->rowCount() === 0) {
                    $existsStatement = $connection->prepare(
                        'SELECT id
                         FROM production_measurement_sessions
                         WHERE id = :session_id'
                    );
                    $existsStatement->execute(['session_id' => $sessionId]);
                    if ($existsStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The production measurement session was not found.'
                        );
                    }
                }

                productionMeasurementRedirect(
                    $sessionId,
                    'session_updated'
                );
            }

            if ($action === 'save_actor_fields') {
                $sessionId = productionMeasurementPositiveInteger(
                    $_POST['production_measurement_session_id'] ?? null
                );
                $measurementSessionId = productionMeasurementPositiveInteger(
                    $_POST['measurement_session_id'] ?? null
                );
                $additionalTypeIds = productionMeasurementIntegerList(
                    $_POST['additional_type_ids'] ?? null
                );

                if ($sessionId === null || $measurementSessionId === null) {
                    throw new DomainException(
                        'The actor measurement record could not be identified.'
                    );
                }

                $childStatement = $connection->prepare(
                    'SELECT
                        ms.id,
                        pms.measurement_template_id
                     FROM measurement_sessions AS ms
                     JOIN production_measurement_sessions AS pms
                        ON pms.id = ms.production_measurement_session_id
                     WHERE ms.id = :measurement_session_id
                       AND pms.id = :production_measurement_session_id
                     LIMIT 1'
                );
                $childStatement->execute([
                    'measurement_session_id' => $measurementSessionId,
                    'production_measurement_session_id' => $sessionId,
                ]);
                $child = $childStatement->fetch();

                if ($child === false) {
                    throw new DomainException(
                        'The actor is not part of this production measurement session.'
                    );
                }

                if ($additionalTypeIds !== []) {
                    $typeParameters = [
                        'template_id' => (int) $child['measurement_template_id'],
                    ];
                    $typePlaceholders = productionMeasurementPlaceholders(
                        $additionalTypeIds,
                        'additional_type_',
                        $typeParameters
                    );
                    $typeStatement = $connection->prepare(
                        'SELECT mt.id
                         FROM measurement_types AS mt
                         WHERE mt.is_active = 1
                           AND mt.id IN (' . $typePlaceholders . ')
                           AND NOT EXISTS (
                               SELECT 1
                               FROM measurement_template_items AS mti
                               WHERE mti.template_id = :template_id
                                 AND mti.measurement_type_id = mt.id
                           )'
                    );
                    $typeStatement->execute($typeParameters);
                    $validTypeIds = array_map(
                        'intval',
                        $typeStatement->fetchAll(PDO::FETCH_COLUMN)
                    );

                    if (count($validTypeIds) !== count($additionalTypeIds)) {
                        throw new DomainException(
                            'One of the actor-specific measurements is unavailable or already belongs to the shared template.'
                        );
                    }
                }

                $connection->beginTransaction();
                $deleteStatement = $connection->prepare(
                    'DELETE FROM measurement_session_additional_types
                     WHERE measurement_session_id = :measurement_session_id'
                );
                $deleteStatement->execute([
                    'measurement_session_id' => $measurementSessionId,
                ]);

                if ($additionalTypeIds !== []) {
                    $insertStatement = $connection->prepare(
                        'INSERT INTO measurement_session_additional_types (
                            measurement_session_id,
                            measurement_type_id,
                            display_order
                         ) VALUES (
                            :measurement_session_id,
                            :measurement_type_id,
                            :display_order
                         )'
                    );
                    foreach ($additionalTypeIds as $index => $typeId) {
                        $insertStatement->execute([
                            'measurement_session_id' => $measurementSessionId,
                            'measurement_type_id' => $typeId,
                            'display_order' => $index + 1,
                        ]);
                    }
                }
                $connection->commit();

                productionMeasurementRedirect(
                    $sessionId,
                    'actor_fields_saved',
                    ['actor_session_id' => (string) $measurementSessionId]
                );
            }

            throw new DomainException(
                'Choose a valid production measurement action.'
            );
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $failedAction = $action;
            $errors[] = $error->getMessage();
        } catch (PDOException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $failedAction = $action;
            if ($action === 'create_template'
                && (string) $error->getCode() === '23000'
            ) {
                $errors[] = 'A template with that name and owner already exists.';
            } else {
                $errors[] = 'The production measurement change could not be saved.';
            }
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $failedAction = $action;
            $errors[] = 'The production measurement change could not be saved.';
        }
    }
}

$notices = [
    'session_created' => 'The production measurement session and its individual actor records were created.',
    'session_updated' => 'The production measurement session was saved.',
    'participants_added' => 'The selected cast members and their individual measurement records were added.',
    'template_created' => 'The reusable measurement template was created.',
    'actor_fields_saved' => 'The actor-specific measurement fields were saved without changing the shared template.',
];
$notice = $notices[productionMeasurementText($_GET, 'saved')] ?? null;

$productionStatement = $connection->query(
    "SELECT
        pr.id,
        pr.name,
        pr.production_year,
        pr.status,
        ve.name AS venue_name,
        COUNT(DISTINCT CASE
            WHEN pc.is_active = 1 AND pe.is_active = 1 THEN pc.person_id
            ELSE NULL
        END) AS active_cast_count
     FROM productions AS pr
     LEFT JOIN venues AS ve
        ON ve.id = pr.venue_id
     LEFT JOIN production_cast AS pc
        ON pc.production_id = pr.id
     LEFT JOIN people AS pe
        ON pe.id = pc.person_id
     GROUP BY
        pr.id,
        pr.name,
        pr.production_year,
        pr.status,
        ve.name
     ORDER BY
        CASE pr.status
            WHEN 'active' THEN 0
            WHEN 'planned' THEN 1
            WHEN 'completed' THEN 2
            WHEN 'cancelled' THEN 3
            ELSE 4
        END,
        COALESCE(pr.production_year, 0) DESC,
        pr.name,
        pr.id"
);
$productions = $productionStatement->fetchAll();
$openProductions = array_values(array_filter(
    $productions,
    static fn (array $production): bool => in_array(
        $production['status'],
        ['planned', 'active'],
        true
    )
));

if ($newSessionValues['production_id'] === '' && $openProductions !== []) {
    $newSessionValues['production_id'] = (string) $openProductions[0]['id'];
}

$templateStatement = $connection->query(
    'SELECT
        mt.id,
        mt.name,
        mt.owner_name,
        mt.description,
        COUNT(mti.measurement_type_id) AS measurement_count,
        GROUP_CONCAT(
            mtype.name
            ORDER BY mti.display_order, mtype.display_order, mtype.name
            SEPARATOR \', \'
        ) AS measurement_names
     FROM measurement_templates AS mt
     LEFT JOIN measurement_template_items AS mti
        ON mti.template_id = mt.id
     LEFT JOIN measurement_types AS mtype
        ON mtype.id = mti.measurement_type_id
     WHERE mt.is_active = 1
     GROUP BY
        mt.id,
        mt.name,
        mt.owner_name,
        mt.description
     ORDER BY mt.name, mt.owner_name, mt.id'
);
$templates = $templateStatement->fetchAll();
$usableTemplates = array_values(array_filter(
    $templates,
    static fn (array $template): bool =>
        (int) $template['measurement_count'] > 0
));

$requestedTemplateId = productionMeasurementPositiveInteger(
    $_GET['template_id'] ?? null
);
if ($newSessionValues['measurement_template_id'] === '') {
    foreach ($usableTemplates as $template) {
        if ($requestedTemplateId === null
            || (int) $template['id'] === $requestedTemplateId
        ) {
            $newSessionValues['measurement_template_id'] =
                (string) $template['id'];
            break;
        }
    }
}

$measurementTypeStatement = $connection->query(
    'SELECT id, name, value_kind, unit, display_order
     FROM measurement_types
     WHERE is_active = 1
     ORDER BY display_order, name, id'
);
$measurementTypes = $measurementTypeStatement->fetchAll();

$castStatement = $connection->query(
    'SELECT
        pc.production_id,
        pc.person_id,
        pe.display_name AS actor_name,
        pe.first_name,
        pe.last_name,
        GROUP_CONCAT(
            pc.character_name
            ORDER BY pc.display_order, pc.id
            SEPARATOR \'; \'
        ) AS characters
     FROM production_cast AS pc
     JOIN people AS pe
        ON pe.id = pc.person_id
     JOIN productions AS pr
        ON pr.id = pc.production_id
     WHERE pc.is_active = 1
       AND pe.is_active = 1
       AND pr.status IN (\'planned\', \'active\')
     GROUP BY
        pc.production_id,
        pc.person_id,
        pe.display_name,
        pe.first_name,
        pe.last_name
     ORDER BY
        pc.production_id,
        COALESCE(pe.last_name, pe.display_name),
        COALESCE(pe.first_name, pe.display_name),
        pe.id'
);
$castByProduction = [];
foreach ($castStatement->fetchAll() as $castMember) {
    $castByProduction[(int) $castMember['production_id']][] = $castMember;
}

$sessionStatement = $connection->query(
    "SELECT
        pms.id,
        pms.production_id,
        pms.measurement_template_id,
        pms.session_name,
        pms.measured_on,
        pms.status,
        pms.notes,
        pms.created_at,
        pr.name AS production_name,
        pr.production_year,
        pr.status AS production_status,
        ve.name AS venue_name,
        mt.name AS template_name,
        created_by.display_name AS created_by,
        COUNT(ms.id) AS participant_count,
        COALESCE(SUM(
            CASE WHEN ms.review_status = 'reviewed' THEN 1 ELSE 0 END
        ), 0) AS reviewed_participant_count
     FROM production_measurement_sessions AS pms
     JOIN productions AS pr
        ON pr.id = pms.production_id
     LEFT JOIN venues AS ve
        ON ve.id = pr.venue_id
     JOIN measurement_templates AS mt
        ON mt.id = pms.measurement_template_id
     LEFT JOIN users AS created_by
        ON created_by.id = pms.created_by_user_id
     LEFT JOIN measurement_sessions AS ms
        ON ms.production_measurement_session_id = pms.id
     GROUP BY
        pms.id,
        pms.production_id,
        pms.measurement_template_id,
        pms.session_name,
        pms.measured_on,
        pms.status,
        pms.notes,
        pms.created_at,
        pr.name,
        pr.production_year,
        pr.status,
        ve.name,
        mt.name,
        created_by.display_name
     ORDER BY
        CASE pms.status WHEN 'planned' THEN 0 ELSE 1 END,
        CASE
            WHEN pms.status = 'planned' THEN pms.measured_on
            ELSE NULL
        END ASC,
        CASE
            WHEN pms.status = 'completed' THEN pms.measured_on
            ELSE NULL
        END DESC,
        pms.id DESC"
);
$productionSessions = $sessionStatement->fetchAll();

if ($selectedSessionId !== null) {
    $sessionExists = false;
    foreach ($productionSessions as $productionSession) {
        if ((int) $productionSession['id'] === $selectedSessionId) {
            $sessionExists = true;
            break;
        }
    }
    if (!$sessionExists) {
        $selectedSessionId = null;
    }
}

if ($selectedSessionId === null && $requestedProductionId !== null) {
    foreach ($productionSessions as $productionSession) {
        if ((int) $productionSession['production_id'] === $requestedProductionId) {
            $selectedSessionId = (int) $productionSession['id'];
            break;
        }
    }
}

if ($selectedSessionId === null && $productionSessions !== []) {
    $selectedSessionId = (int) $productionSessions[0]['id'];
}

$selectedSession = null;
foreach ($productionSessions as $productionSession) {
    if ((int) $productionSession['id'] === $selectedSessionId) {
        $selectedSession = $productionSession;
        break;
    }
}

$participants = [];
$availableParticipants = [];
$templateTypes = [];
$additionalOptions = [];
$additionalTypeIdsBySession = [];
$worksheetTypes = [];
$applicableTypesBySession = [];
$openNewSessionForm = $productionSessions === []
    || $failedAction === 'create_session'
    || (
        $requestedProductionId !== null
        && isset($_GET['production_id'])
        && !isset($_GET['session_id'])
    );

if ($selectedSession !== null) {
    $participantStatement = $connection->prepare(
        'SELECT
            ms.id AS measurement_session_id,
            ms.person_id,
            ms.review_status,
            pe.display_name AS actor_name,
            pe.first_name,
            pe.last_name,
            (
                SELECT GROUP_CONCAT(
                    pc.character_name
                    ORDER BY pc.display_order, pc.id
                    SEPARATOR \'; \'
                )
                FROM production_cast AS pc
                WHERE pc.production_id = ms.production_id
                  AND pc.person_id = ms.person_id
            ) AS characters,
            (
                SELECT COUNT(*)
                FROM measurement_values AS mv
                WHERE mv.measurement_session_id = ms.id
            ) AS recorded_value_count
         FROM measurement_sessions AS ms
         JOIN people AS pe
            ON pe.id = ms.person_id
         WHERE ms.production_measurement_session_id = :session_id
         ORDER BY
            COALESCE(pe.last_name, pe.display_name),
            COALESCE(pe.first_name, pe.display_name),
            pe.id'
    );
    $participantStatement->execute([
        'session_id' => (int) $selectedSession['id'],
    ]);
    $participants = $participantStatement->fetchAll();

    if ($selectedSession['status'] === 'planned') {
        $availableParticipantStatement = $connection->prepare(
            'SELECT
                pc.person_id,
                pe.display_name AS actor_name,
                pe.first_name,
                pe.last_name,
                GROUP_CONCAT(
                    pc.character_name
                    ORDER BY pc.display_order, pc.id
                    SEPARATOR \'; \'
                ) AS characters
             FROM production_cast AS pc
             JOIN people AS pe
                ON pe.id = pc.person_id
             WHERE pc.production_id = :production_id
               AND pc.is_active = 1
               AND pe.is_active = 1
               AND NOT EXISTS (
                   SELECT 1
                   FROM measurement_sessions AS existing_participant
                   WHERE existing_participant.production_measurement_session_id = :group_session_id
                     AND existing_participant.person_id = pc.person_id
               )
             GROUP BY
                pc.person_id,
                pe.display_name,
                pe.first_name,
                pe.last_name
             ORDER BY
                COALESCE(pe.last_name, pe.display_name),
                COALESCE(pe.first_name, pe.display_name),
                pe.id'
        );
        $availableParticipantStatement->execute([
            'production_id' => (int) $selectedSession['production_id'],
            'group_session_id' => (int) $selectedSession['id'],
        ]);
        $availableParticipants =
            $availableParticipantStatement->fetchAll();
    }

    $templateTypeStatement = $connection->prepare(
        'SELECT
            mt.id,
            mt.name,
            mt.value_kind,
            mt.unit,
            mti.display_order
         FROM measurement_template_items AS mti
         JOIN measurement_types AS mt
            ON mt.id = mti.measurement_type_id
         WHERE mti.template_id = :template_id
         ORDER BY mti.display_order, mt.display_order, mt.name, mt.id'
    );
    $templateTypeStatement->execute([
        'template_id' => (int) $selectedSession['measurement_template_id'],
    ]);
    $templateTypes = $templateTypeStatement->fetchAll();

    $baseTypeIds = [];
    foreach ($templateTypes as $type) {
        $baseTypeIds[(int) $type['id']] = true;
        $worksheetTypes[(int) $type['id']] = $type;
    }

    $additionalOptionStatement = $connection->prepare(
        'SELECT mt.id, mt.name, mt.value_kind, mt.unit, mt.display_order
         FROM measurement_types AS mt
         WHERE mt.is_active = 1
           AND NOT EXISTS (
               SELECT 1
               FROM measurement_template_items AS mti
               WHERE mti.template_id = :template_id
                 AND mti.measurement_type_id = mt.id
           )
         ORDER BY mt.display_order, mt.name, mt.id'
    );
    $additionalOptionStatement->execute([
        'template_id' => (int) $selectedSession['measurement_template_id'],
    ]);
    $additionalOptions = $additionalOptionStatement->fetchAll();

    $additionalStatement = $connection->prepare(
        'SELECT
            msat.measurement_session_id,
            mt.id,
            mt.name,
            mt.value_kind,
            mt.unit,
            mt.display_order,
            msat.display_order AS actor_display_order
         FROM measurement_session_additional_types AS msat
         JOIN measurement_sessions AS ms
            ON ms.id = msat.measurement_session_id
         JOIN measurement_types AS mt
            ON mt.id = msat.measurement_type_id
         WHERE ms.production_measurement_session_id = :session_id
         ORDER BY
            msat.measurement_session_id,
            msat.display_order,
            mt.display_order,
            mt.name'
    );
    $additionalStatement->execute([
        'session_id' => (int) $selectedSession['id'],
    ]);
    foreach ($additionalStatement->fetchAll() as $additionalType) {
        $measurementSessionId =
            (int) $additionalType['measurement_session_id'];
        $typeId = (int) $additionalType['id'];
        $additionalTypeIdsBySession[$measurementSessionId][$typeId] = true;
        $worksheetTypes[$typeId] = $additionalType;
    }

    foreach ($participants as $participant) {
        $measurementSessionId =
            (int) $participant['measurement_session_id'];
        foreach ($baseTypeIds as $typeId => $isIncluded) {
            $applicableTypesBySession[$measurementSessionId][$typeId] = true;
        }
        foreach (
            $additionalTypeIdsBySession[$measurementSessionId] ?? []
            as $typeId => $isIncluded
        ) {
            $applicableTypesBySession[$measurementSessionId][$typeId] = true;
        }
    }

    uasort(
        $worksheetTypes,
        static function (array $left, array $right): int {
            $leftOrder = (int) ($left['display_order'] ?? 0);
            $rightOrder = (int) ($right['display_order'] ?? 0);

            return $leftOrder <=> $rightOrder
                ?: strcasecmp((string) $left['name'], (string) $right['name'])
                ?: (int) $left['id'] <=> (int) $right['id'];
        }
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production measurement sessions — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260904-2">
</head>
<body>
<main class="production-measurements-page">
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_productions')): ?>
            <a href="/productions.php">Productions</a>
        <?php endif; ?>
        <a href="/checkout.php">Production checkout</a>
        <a href="/fittings.php">Fittings</a>
        <a href="/measurements.php">Measurements</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_assets')): ?>
            <a href="/asset-review.php">Asset review</a>
        <?php endif; ?>
        <?php if (collectionStewardUserCan($currentUser, 'manage_vocabulary')): ?>
            <a href="/vocabulary.php">Vocabulary</a>
        <?php endif; ?>
        <?php if (collectionStewardUserCan($currentUser, 'manage_users')): ?>
            <a href="/users.php">Users</a>
        <?php endif; ?>
        <a href="/change-password.php">Password</a>
    </nav>

    <div class="page-heading">
        <div>
            <p class="privacy-label">Private measurement records</p>
            <h1>Production measurement sessions</h1>
            <p>Plan a dated cast session, reuse a shared template, and add fields for one actor when needed.</p>
        </div>
        <div class="user-session">
            <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
            <form method="post" action="/">
                <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
            </form>
        </div>
    </div>

    <div class="button-row production-measurement-top-actions">
        <a class="button secondary" href="/measurements.php">Browse individual measurements</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_productions')): ?>
            <a class="button secondary" href="/productions.php">Manage productions and cast</a>
        <?php endif; ?>
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

    <div class="production-measurement-workspace">
        <aside class="production-measurement-sidebar">
            <div class="section-heading">
                <h2>Sessions</h2>
                <span class="result-count"><?= count($productionSessions) ?></span>
            </div>

            <?php if ($productionSessions === []): ?>
                <p>No production measurement sessions have been created.</p>
            <?php else: ?>
                <div class="production-measurement-session-list">
                    <?php foreach ($productionSessions as $productionSession): ?>
                        <a
                            class="production-measurement-session-link <?= (int) $productionSession['id'] === $selectedSessionId ? 'is-current' : '' ?>"
                            href="/production-measurements.php?session_id=<?= (int) $productionSession['id'] ?>"
                        >
                            <strong><?= collectionStewardEscape($productionSession['session_name']) ?></strong>
                            <span><?= collectionStewardEscape($productionSession['production_name']) ?><?= !empty($productionSession['production_year']) ? ' · ' . (int) $productionSession['production_year'] : '' ?></span>
                            <small><?= collectionStewardEscape((new DateTimeImmutable($productionSession['measured_on']))->format('F j, Y')) ?> · <?= (int) $productionSession['participant_count'] ?> participant<?= (int) $productionSession['participant_count'] === 1 ? '' : 's' ?> · <?= collectionStewardEscape(ucfirst($productionSession['status'])) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <details class="production-measurement-create" <?= $openNewSessionForm ? 'open' : '' ?>>
                <summary>Start a production session</summary>
                <?php if ($openProductions === []): ?>
                    <p>A production must be Planned or Active before a new measurement session can be started.</p>
                    <?php if (collectionStewardUserCan($currentUser, 'manage_productions')): ?>
                        <a class="button secondary" href="/productions.php">Open Productions</a>
                    <?php endif; ?>
                <?php elseif ($usableTemplates === []): ?>
                    <p>Create a reusable template below before starting a session.</p>
                <?php else: ?>
                    <form method="post" id="create-production-measurement-session">
                        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_session">
                        <div class="field">
                            <label for="production_measurement_production">Production</label>
                            <select id="production_measurement_production" name="production_id" required>
                                <?php foreach ($openProductions as $production): ?>
                                    <option value="<?= (int) $production['id'] ?>" <?= $newSessionValues['production_id'] === (string) $production['id'] ? 'selected' : '' ?>>
                                        <?= collectionStewardEscape(productionMeasurementProductionLabel($production)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="production_measurement_session_name">Session name</label>
                            <input type="text" id="production_measurement_session_name" name="session_name" maxlength="150" value="<?= collectionStewardEscape($newSessionValues['session_name']) ?>" placeholder="Example: Initial measurements" required>
                        </div>
                        <div class="field">
                            <label for="production_measurement_date">Date</label>
                            <input type="date" id="production_measurement_date" name="measured_on" value="<?= collectionStewardEscape($newSessionValues['measured_on']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="production_measurement_template">Template</label>
                            <select id="production_measurement_template" name="measurement_template_id" required>
                                <?php foreach ($usableTemplates as $template): ?>
                                    <option value="<?= (int) $template['id'] ?>" <?= $newSessionValues['measurement_template_id'] === (string) $template['id'] ? 'selected' : '' ?>>
                                        <?= collectionStewardEscape($template['name']) ?> (<?= (int) $template['measurement_count'] ?> fields)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="production-measurement-cast-picker">
                            <div class="section-heading">
                                <strong>Participating cast</strong>
                                <div class="button-row">
                                    <button type="button" class="secondary" data-cast-selection="all">Select all</button>
                                    <button type="button" class="secondary" data-cast-selection="none">Clear</button>
                                </div>
                            </div>
                            <?php foreach ($openProductions as $production): ?>
                                <?php $productionCast = $castByProduction[(int) $production['id']] ?? []; ?>
                                <fieldset
                                    class="production-cast-choice-group"
                                    data-production-cast="<?= (int) $production['id'] ?>"
                                    <?= $newSessionValues['production_id'] === (string) $production['id'] ? '' : 'hidden' ?>
                                >
                                    <legend class="visually-hidden"><?= collectionStewardEscape($production['name']) ?> cast</legend>
                                    <?php if ($productionCast === []): ?>
                                        <p>No active cast members are available for this production.</p>
                                    <?php else: ?>
                                        <?php foreach ($productionCast as $castMember): ?>
                                            <label class="production-cast-choice">
                                                <input
                                                    type="checkbox"
                                                    name="person_ids[]"
                                                    value="<?= (int) $castMember['person_id'] ?>"
                                                    <?= in_array((int) $castMember['person_id'], $selectedPersonIds, true) ? 'checked' : '' ?>
                                                >
                                                <span>
                                                    <strong><?= collectionStewardEscape(productionMeasurementActorLabel($castMember)) ?></strong>
                                                    <small><?= collectionStewardEscape($castMember['characters']) ?></small>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>

                        <div class="field">
                            <label for="production_measurement_notes">Session notes</label>
                            <textarea id="production_measurement_notes" name="notes" rows="3" maxlength="5000"><?= collectionStewardEscape($newSessionValues['notes']) ?></textarea>
                        </div>
                        <button type="submit">Create session and actor records</button>
                    </form>
                <?php endif; ?>
            </details>

            <details class="production-measurement-create" <?= $failedAction === 'create_template' ? 'open' : '' ?>>
                <summary>Create a reusable template</summary>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_template">
                    <?php if ($selectedSessionId !== null): ?>
                        <input type="hidden" name="production_measurement_session_id" value="<?= $selectedSessionId ?>">
                    <?php endif; ?>
                    <?php if ($requestedProductionId !== null): ?>
                        <input type="hidden" name="production_id" value="<?= $requestedProductionId ?>">
                    <?php endif; ?>
                    <div class="field">
                        <label for="template_name">Template name</label>
                        <input type="text" id="template_name" name="template_name" maxlength="150" value="<?= collectionStewardEscape($newTemplateValues['template_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="owner_name">Owner or source</label>
                        <input type="text" id="owner_name" name="owner_name" maxlength="150" value="<?= collectionStewardEscape($newTemplateValues['owner_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="template_description">Description</label>
                        <textarea id="template_description" name="description" rows="3" maxlength="5000"><?= collectionStewardEscape($newTemplateValues['description']) ?></textarea>
                    </div>
                    <fieldset class="field">
                        <legend>Measurements</legend>
                        <div class="template-measurement-options">
                            <?php foreach ($measurementTypes as $type): ?>
                                <label>
                                    <input type="checkbox" name="measurement_type_ids[]" value="<?= (int) $type['id'] ?>" <?= in_array((int) $type['id'], $selectedTemplateTypeIds, true) ? 'checked' : '' ?>>
                                    <span><?= collectionStewardEscape($type['name']) ?><?= !empty($type['unit']) ? ' (' . collectionStewardEscape($type['unit']) . ')' : '' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <button type="submit">Create reusable template</button>
                </form>
            </details>

            <?php if ($templates !== []): ?>
                <details class="production-measurement-create">
                    <summary>View reusable templates (<?= count($templates) ?>)</summary>
                    <div class="production-measurement-template-list">
                        <?php foreach ($templates as $template): ?>
                            <article>
                                <strong><?= collectionStewardEscape($template['name']) ?></strong>
                                <?php if (!empty($template['owner_name'])): ?>
                                    <small>Owner/source: <?= collectionStewardEscape($template['owner_name']) ?></small>
                                <?php endif; ?>
                                <p><?= (int) $template['measurement_count'] ?> fields<?= !empty($template['measurement_names']) ? ': ' . collectionStewardEscape($template['measurement_names']) : '' ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </aside>

        <div class="production-measurement-editor">
            <?php if ($selectedSession === null): ?>
                <div class="production-measurement-empty-state">
                    <h2>Start the first production measurement session</h2>
                    <p>Select a planned or active production, a reusable template, and participating cast members.</p>
                </div>
            <?php else: ?>
                <header class="production-measurement-record-heading">
                    <div>
                        <span class="status-badge production-measurement-status-<?= collectionStewardEscape($selectedSession['status']) ?>"><?= collectionStewardEscape(ucfirst($selectedSession['status'])) ?></span>
                        <h2><?= collectionStewardEscape($selectedSession['session_name']) ?></h2>
                        <p><?= collectionStewardEscape($selectedSession['production_name']) ?><?= !empty($selectedSession['production_year']) ? ' · ' . (int) $selectedSession['production_year'] : '' ?><?= !empty($selectedSession['venue_name']) ? ' · ' . collectionStewardEscape($selectedSession['venue_name']) : '' ?></p>
                    </div>
                    <?php if ($participants !== [] && $worksheetTypes !== []): ?>
                        <button type="button" data-production-worksheet-print>Print blank worksheet</button>
                    <?php endif; ?>
                </header>

                <div class="production-measurement-summary">
                    <div>
                        <strong><?= collectionStewardEscape((new DateTimeImmutable($selectedSession['measured_on']))->format('F j, Y')) ?></strong>
                        <span>Session date</span>
                    </div>
                    <div>
                        <strong><?= collectionStewardEscape($selectedSession['template_name']) ?></strong>
                        <span>Shared template</span>
                    </div>
                    <div>
                        <strong><?= count($participants) ?></strong>
                        <span>Individual actor records</span>
                    </div>
                </div>

                <details class="production-measurement-details">
                    <summary>Edit session name, status, or notes</summary>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_session">
                        <input type="hidden" name="production_measurement_session_id" value="<?= (int) $selectedSession['id'] ?>">
                        <div class="field">
                            <label for="edit_production_measurement_session_name">Session name</label>
                            <input type="text" id="edit_production_measurement_session_name" name="session_name" maxlength="150" value="<?= collectionStewardEscape($selectedSession['session_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="production_measurement_status">Status</label>
                            <select id="production_measurement_status" name="status" required>
                                <option value="planned" <?= $selectedSession['status'] === 'planned' ? 'selected' : '' ?>>Planned</option>
                                <option value="completed" <?= $selectedSession['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                            <span class="help">Completing this group does not hide or lock its individual measurement records.</span>
                        </div>
                        <div class="field">
                            <label for="edit_production_measurement_notes">Notes</label>
                            <textarea id="edit_production_measurement_notes" name="notes" rows="3" maxlength="5000"><?= collectionStewardEscape((string) ($selectedSession['notes'] ?? '')) ?></textarea>
                        </div>
                        <button type="submit">Save session</button>
                    </form>
                </details>

                <?php if ($participants !== [] && $worksheetTypes !== []): ?>
                    <section class="production-measurement-worksheet" aria-labelledby="production-measurement-worksheet-title">
                        <div class="section-heading">
                            <div>
                                <h3 id="production-measurement-worksheet-title">Blank worksheet preview</h3>
                                <p>Shared-template fields apply to everyone. A dash marks an actor-specific field that does not apply to that row.</p>
                            </div>
                        </div>

                        <details class="production-measurement-print-columns">
                            <summary>Choose columns to print</summary>
                            <div class="button-row">
                                <button type="button" class="secondary" data-production-print-columns="all">Select all</button>
                                <button type="button" class="secondary" data-production-print-columns="none">Clear all</button>
                            </div>
                            <div class="template-measurement-options">
                                <?php foreach (array_values($worksheetTypes) as $columnIndex => $type): ?>
                                    <label>
                                        <input type="checkbox" value="<?= $columnIndex + 2 ?>" data-production-print-column checked>
                                        <span><?= collectionStewardEscape($type['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </details>

                        <div class="production-measurement-table-scroll" tabindex="0" aria-label="Scrollable blank production measurement worksheet">
                            <table id="production-measurement-worksheet-table" class="production-measurement-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Actor</th>
                                        <th scope="col">Character</th>
                                        <?php foreach ($worksheetTypes as $type): ?>
                                            <th scope="col">
                                                <?= collectionStewardEscape($type['name']) ?>
                                                <?php if (!empty($type['unit'])): ?>
                                                    <small>(<?= collectionStewardEscape($type['unit']) ?>)</small>
                                                <?php endif; ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($participants as $participant): ?>
                                        <?php $participantSessionId = (int) $participant['measurement_session_id']; ?>
                                        <tr>
                                            <th scope="row"><?= collectionStewardEscape(productionMeasurementActorLabel($participant)) ?></th>
                                            <td><?= collectionStewardEscape((string) ($participant['characters'] ?? '')) ?></td>
                                            <?php foreach ($worksheetTypes as $type): ?>
                                                <?php $applies = isset($applicableTypesBySession[$participantSessionId][(int) $type['id']]); ?>
                                                <td class="<?= $applies ? 'measurement-blank-cell' : 'measurement-not-applicable-cell' ?>"><?= $applies ? '&nbsp;' : '—' ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <section aria-labelledby="production-measurement-participants-title">
                    <div class="section-heading">
                        <div>
                            <h3 id="production-measurement-participants-title">Participating cast</h3>
                            <p>Open an actor record to enter measurements. Actor-specific fields remain limited to that person.</p>
                        </div>
                        <span class="result-count"><?= count($participants) ?></span>
                    </div>

                    <?php if ($selectedSession['status'] === 'planned'): ?>
                        <?php if ($availableParticipants === []): ?>
                            <p class="help">Every active cast member is already included in this session.</p>
                        <?php else: ?>
                            <details class="production-measurement-details" <?= $failedAction === 'add_participants' ? 'open' : '' ?>>
                                <summary>Add cast members</summary>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add_participants">
                                    <input type="hidden" name="production_measurement_session_id" value="<?= (int) $selectedSession['id'] ?>">
                                    <div class="production-measurement-cast-picker">
                                        <div class="section-heading">
                                            <strong>Additional participants</strong>
                                            <div class="button-row">
                                                <button type="button" class="secondary" data-add-participant-selection="all">Select all</button>
                                                <button type="button" class="secondary" data-add-participant-selection="none">Clear</button>
                                            </div>
                                        </div>
                                        <fieldset class="production-cast-choice-group">
                                            <legend class="visually-hidden">Additional cast members</legend>
                                            <?php foreach ($availableParticipants as $castMember): ?>
                                                <label class="production-cast-choice">
                                                    <input type="checkbox" name="person_ids[]" value="<?= (int) $castMember['person_id'] ?>">
                                                    <span>
                                                        <strong><?= collectionStewardEscape(productionMeasurementActorLabel($castMember)) ?></strong>
                                                        <small><?= collectionStewardEscape($castMember['characters']) ?></small>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    </div>
                                    <button type="submit">Add selected cast members</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="production-measurement-participant-list">
                        <?php foreach ($participants as $participant): ?>
                            <?php
                            $participantSessionId =
                                (int) $participant['measurement_session_id'];
                            $participantAdditionalIds = array_keys(
                                $additionalTypeIdsBySession[$participantSessionId]
                                    ?? []
                            );
                            ?>
                            <article class="production-measurement-participant-card" id="actor-session-<?= $participantSessionId ?>">
                                <div class="production-measurement-participant-heading">
                                    <div>
                                        <h4><?= collectionStewardEscape(productionMeasurementActorLabel($participant)) ?></h4>
                                        <?php if (!empty($participant['characters'])): ?>
                                            <p><?= collectionStewardEscape($participant['characters']) ?></p>
                                        <?php endif; ?>
                                        <small><?= (int) $participant['recorded_value_count'] ?> values recorded · <?= collectionStewardEscape(str_replace('_', ' ', ucfirst($participant['review_status']))) ?></small>
                                    </div>
                                    <a class="button" href="/measurements.php?session_id=<?= $participantSessionId ?>&amp;view=expanded">Open actor record</a>
                                </div>

                                <?php if ($additionalOptions !== []): ?>
                                    <details class="production-measurement-actor-fields">
                                        <summary>Actor-specific fields<?= $participantAdditionalIds !== [] ? ' (' . count($participantAdditionalIds) . ')' : '' ?></summary>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="save_actor_fields">
                                            <input type="hidden" name="production_measurement_session_id" value="<?= (int) $selectedSession['id'] ?>">
                                            <input type="hidden" name="measurement_session_id" value="<?= $participantSessionId ?>">
                                            <p class="help">These fields are added only to <?= collectionStewardEscape(productionMeasurementActorLabel($participant)) ?>. Removing a checked field does not delete an already-recorded value.</p>
                                            <div class="template-measurement-options">
                                                <?php foreach ($additionalOptions as $type): ?>
                                                    <label>
                                                        <input type="checkbox" name="additional_type_ids[]" value="<?= (int) $type['id'] ?>" <?= in_array((int) $type['id'], $participantAdditionalIds, true) ? 'checked' : '' ?>>
                                                        <span><?= collectionStewardEscape($type['name']) ?><?= !empty($type['unit']) ? ' (' . collectionStewardEscape($type['unit']) . ')' : '' ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <button type="submit" class="secondary">Save actor-specific fields</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <header id="production-session-print-heading" class="production-session-print-heading">
                    <h1>Blank measurement worksheet</h1>
                    <p><?= collectionStewardEscape($selectedSession['production_name']) ?> — <?= collectionStewardEscape($selectedSession['session_name']) ?></p>
                    <p><?= collectionStewardEscape((new DateTimeImmutable($selectedSession['measured_on']))->format('F j, Y')) ?> · Template: <?= collectionStewardEscape($selectedSession['template_name']) ?></p>
                </header>
            <?php endif; ?>
        </div>
    </div>

    <div id="production-session-print-pages" class="production-session-print-pages" aria-hidden="true"></div>
</main>

<script>
const productionSelect = document.getElementById('production_measurement_production');

const updateProductionCastChoices = function () {
    if (!productionSelect) {
        return;
    }

    document.querySelectorAll('[data-production-cast]').forEach(function (group) {
        const isSelected = group.dataset.productionCast === productionSelect.value;
        group.hidden = !isSelected;
        group.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
            input.disabled = !isSelected;
            if (!isSelected) {
                input.checked = false;
            }
        });
    });
};

if (productionSelect) {
    productionSelect.addEventListener('change', updateProductionCastChoices);
    updateProductionCastChoices();
}

document.querySelectorAll('[data-cast-selection]').forEach(function (button) {
    button.addEventListener('click', function () {
        const shouldSelect = button.dataset.castSelection === 'all';
        document.querySelectorAll('[data-production-cast]:not([hidden]) input[type="checkbox"]').forEach(function (input) {
            input.checked = shouldSelect;
        });
    });
});

document.querySelectorAll('[data-add-participant-selection]').forEach(function (button) {
    button.addEventListener('click', function () {
        const shouldSelect = button.dataset.addParticipantSelection === 'all';
        const form = button.closest('form');
        if (!form) {
            return;
        }
        form.querySelectorAll('input[name="person_ids[]"]').forEach(function (input) {
            input.checked = shouldSelect;
        });
    });
});

document.querySelectorAll('[data-production-print-columns]').forEach(function (button) {
    button.addEventListener('click', function () {
        const shouldSelect = button.dataset.productionPrintColumns === 'all';
        document.querySelectorAll('[data-production-print-column]').forEach(function (input) {
            input.checked = shouldSelect;
        });
    });
});

const buildProductionWorksheetPages = function () {
    const sourceTable = document.getElementById('production-measurement-worksheet-table');
    const sourceHeading = document.getElementById('production-session-print-heading');
    const printPages = document.getElementById('production-session-print-pages');

    if (!sourceTable || !sourceHeading || !printPages) {
        return false;
    }

    const sourceHeaders = Array.from(sourceTable.tHead.rows[0].cells);
    const sourceRows = Array.from(sourceTable.tBodies[0].rows);
    const selectedColumns = Array.from(
        document.querySelectorAll('[data-production-print-column]:checked')
    ).map(function (input) {
        return Number.parseInt(input.value, 10);
    }).filter(Number.isFinite);

    if (selectedColumns.length === 0) {
        window.alert('Choose at least one measurement column to print.');
        return false;
    }

    const fieldsPerPage = 7;
    const pageCount = Math.ceil(selectedColumns.length / fieldsPerPage);
    printPages.replaceChildren();

    for (let pageIndex = 0; pageIndex < pageCount; pageIndex += 1) {
        const pageColumns = selectedColumns.slice(
            pageIndex * fieldsPerPage,
            (pageIndex + 1) * fieldsPerPage
        );
        const columnIndexes = [0, 1].concat(pageColumns);
        const page = document.createElement('section');
        page.className = 'production-session-print-page';

        const heading = sourceHeading.cloneNode(true);
        heading.removeAttribute('id');
        const range = document.createElement('p');
        range.className = 'print-measurement-range';
        range.textContent = 'Page '
            + (pageIndex + 1)
            + ' of '
            + pageCount
            + ' — '
            + sourceHeaders[pageColumns[0]].textContent.trim()
            + ' through '
            + sourceHeaders[pageColumns[pageColumns.length - 1]].textContent.trim();
        heading.appendChild(range);
        page.appendChild(heading);

        const table = document.createElement('table');
        table.className = 'production-measurement-table production-session-print-table';
        const tableHead = table.createTHead();
        const headingRow = tableHead.insertRow();
        columnIndexes.forEach(function (columnIndex) {
            headingRow.appendChild(sourceHeaders[columnIndex].cloneNode(true));
        });

        const tableBody = table.createTBody();
        sourceRows.forEach(function (sourceRow) {
            const row = tableBody.insertRow();
            const sourceCells = Array.from(sourceRow.cells);
            columnIndexes.forEach(function (columnIndex) {
                row.appendChild(sourceCells[columnIndex].cloneNode(true));
            });
        });

        page.appendChild(table);
        printPages.appendChild(page);
    }

    return true;
};

document.querySelectorAll('[data-production-worksheet-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        if (buildProductionWorksheetPages()) {
            document.body.classList.add('print-production-session-worksheet');
            window.print();
        }
    });
});

window.addEventListener('afterprint', function () {
    document.body.classList.remove('print-production-session-worksheet');
    const printPages = document.getElementById('production-session-print-pages');
    if (printPages) {
        printPages.replaceChildren();
    }
});
</script>
</body>
</html>
