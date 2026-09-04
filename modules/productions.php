<?php

declare(strict_types=1);

/**
 * Production, venue, and cast management.
 *
 * Public entry point: /productions.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'manage_productions'
);
$csrfToken = collectionStewardCsrfToken();

function productionManagementText(array $source, string $key): string
{
    return is_string($source[$key] ?? null)
        ? trim($source[$key])
        : '';
}

function productionManagementPositiveInteger(mixed $value): ?int
{
    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $validated === false ? null : (int) $validated;
}

function productionManagementDateIsValid(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function productionManagementYear(string $value): ?int
{
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        throw new DomainException(
            'Enter a four-digit production year between 1900 and 2200.'
        );
    }

    $year = (int) $value;

    if ($year < 1900 || $year > 2200) {
        throw new DomainException(
            'Enter a four-digit production year between 1900 and 2200.'
        );
    }

    return $year;
}

function productionManagementVenueExists(
    PDO $connection,
    int $venueId,
    bool $mustBeActive
): bool {
    $statement = $connection->prepare(
        'SELECT id
         FROM venues
         WHERE id = :venue_id'
            . ($mustBeActive ? ' AND is_active = 1' : '')
            . ' LIMIT 1'
    );
    $statement->execute([
        'venue_id' => $venueId,
    ]);

    return $statement->fetchColumn() !== false;
}

function productionManagementValidateProductionValues(
    PDO $connection,
    array $values,
    bool $venueMustBeActive
): array {
    $name = $values['name'];
    $venueText = $values['venue_id'];
    $venueId = productionManagementPositiveInteger($venueText);
    $productionYear = productionManagementYear($values['production_year']);
    $openingDate = $values['opening_date'];
    $closingDate = $values['closing_date'];
    $status = $values['status'];

    if ($name === '' || strlen($name) > 150) {
        throw new DomainException(
            'Enter a production name of 150 characters or fewer.'
        );
    }

    if ($venueText !== '' && $venueId === null) {
        throw new DomainException('Choose a valid venue.');
    }

    if (
        $venueId !== null
        && !productionManagementVenueExists(
            $connection,
            $venueId,
            $venueMustBeActive
        )
    ) {
        throw new DomainException(
            $venueMustBeActive
                ? 'Choose an active venue.'
                : 'The selected venue was not found.'
        );
    }

    if (!productionManagementDateIsValid($openingDate)) {
        throw new DomainException('Enter a valid opening date.');
    }

    if (!productionManagementDateIsValid($closingDate)) {
        throw new DomainException('Enter a valid closing date.');
    }

    if (
        $openingDate !== ''
        && $closingDate !== ''
        && $closingDate < $openingDate
    ) {
        throw new DomainException(
            'The closing date cannot be earlier than the opening date.'
        );
    }

    if (!isset(collectionStewardProductionStatuses()[$status])) {
        throw new DomainException('Choose a valid production status.');
    }

    return [
        'name' => $name,
        'venue_id' => $venueId,
        'production_year' => $productionYear,
        'opening_date' => $openingDate !== '' ? $openingDate : null,
        'closing_date' => $closingDate !== '' ? $closingDate : null,
        'status' => $status,
    ];
}

function productionManagementCheckDuplicate(
    PDO $connection,
    array $values,
    ?int $excludedProductionId
): void {
    $statement = $connection->prepare(
        'SELECT id
         FROM productions
         WHERE name = :name
           AND venue_id <=> :venue_id
           AND production_year <=> :production_year
           AND (:excluded_id IS NULL OR id <> :excluded_id_value)
         LIMIT 1'
    );
    $statement->bindValue(':name', $values['name']);
    $statement->bindValue(
        ':venue_id',
        $values['venue_id'],
        $values['venue_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );
    $statement->bindValue(
        ':production_year',
        $values['production_year'],
        $values['production_year'] === null
            ? PDO::PARAM_NULL
            : PDO::PARAM_INT
    );
    $statement->bindValue(
        ':excluded_id',
        $excludedProductionId,
        $excludedProductionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );
    $statement->bindValue(
        ':excluded_id_value',
        $excludedProductionId ?? 0,
        PDO::PARAM_INT
    );
    $statement->execute();

    if ($statement->fetchColumn() !== false) {
        throw new DomainException(
            'A production with that name, venue, and year already exists.'
        );
    }
}

function productionManagementRedirect(
    ?int $productionId,
    string $notice
): void {
    $parameters = ['saved' => $notice];

    if ($productionId !== null) {
        $parameters['production_id'] = (string) $productionId;
    }

    header('Location: /productions.php?' . http_build_query($parameters));
    exit;
}

$errors = [];
$failedAction = '';
$selectedProductionId = productionManagementPositiveInteger(
    $_POST['production_id'] ?? $_GET['production_id'] ?? null
);

$productionValues = [
    'name' => '',
    'venue_id' => '',
    'production_year' => (new DateTimeImmutable('today'))->format('Y'),
    'opening_date' => '',
    'closing_date' => '',
    'status' => 'planned',
];
$createProductionValues = $productionValues;
$newVenueValues = [
    'name' => '',
    'code' => '',
];
$castValues = [
    'person_id' => '',
    'first_name' => '',
    'last_name' => '',
    'character_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = productionManagementText($_POST, 'action');

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    } else {
        try {
            if (in_array($action, ['create_production', 'update_production'], true)) {
                foreach (array_keys($productionValues) as $field) {
                    $productionValues[$field] = productionManagementText(
                        $_POST,
                        $field
                    );
                }

                if ($action === 'create_production') {
                    $createProductionValues = $productionValues;
                }

                $validated = productionManagementValidateProductionValues(
                    $connection,
                    $productionValues,
                    $action === 'create_production'
                );
                $productionId = $action === 'update_production'
                    ? productionManagementPositiveInteger(
                        $_POST['production_id'] ?? null
                    )
                    : null;

                if ($action === 'update_production') {
                    if ($productionId === null) {
                        throw new DomainException(
                            'The production could not be identified.'
                        );
                    }

                    $existsStatement = $connection->prepare(
                        'SELECT id
                         FROM productions
                         WHERE id = :production_id
                         LIMIT 1'
                    );
                    $existsStatement->execute([
                        'production_id' => $productionId,
                    ]);

                    if ($existsStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The selected production was not found.'
                        );
                    }

                    if (!in_array(
                        $validated['status'],
                        ['planned', 'active'],
                        true
                    )) {
                        $activeCheckoutStatement = $connection->prepare(
                            "SELECT COUNT(*)
                             FROM asset_checkouts AS ac
                             JOIN production_cast AS pc
                                ON pc.id = ac.production_cast_id
                             WHERE pc.production_id = :production_id
                               AND ac.status = 'active'"
                        );
                        $activeCheckoutStatement->execute([
                            'production_id' => $productionId,
                        ]);

                        if ((int) $activeCheckoutStatement->fetchColumn() > 0) {
                            throw new DomainException(
                                'Resolve this production’s active checkouts before marking it completed or cancelled.'
                            );
                        }
                    }
                }

                productionManagementCheckDuplicate(
                    $connection,
                    $validated,
                    $productionId
                );

                if ($action === 'create_production') {
                    $statement = $connection->prepare(
                        'INSERT INTO productions (
                            name,
                            venue_id,
                            production_year,
                            opening_date,
                            closing_date,
                            status
                         ) VALUES (
                            :name,
                            :venue_id,
                            :production_year,
                            :opening_date,
                            :closing_date,
                            :status
                         )'
                    );
                } else {
                    $statement = $connection->prepare(
                        'UPDATE productions
                         SET name = :name,
                             venue_id = :venue_id,
                             production_year = :production_year,
                             opening_date = :opening_date,
                             closing_date = :closing_date,
                             status = :status
                         WHERE id = :production_id'
                    );
                    $statement->bindValue(
                        ':production_id',
                        $productionId,
                        PDO::PARAM_INT
                    );
                }

                $statement->bindValue(':name', $validated['name']);
                $statement->bindValue(
                    ':venue_id',
                    $validated['venue_id'],
                    $validated['venue_id'] === null
                        ? PDO::PARAM_NULL
                        : PDO::PARAM_INT
                );
                $statement->bindValue(
                    ':production_year',
                    $validated['production_year'],
                    $validated['production_year'] === null
                        ? PDO::PARAM_NULL
                        : PDO::PARAM_INT
                );
                $statement->bindValue(
                    ':opening_date',
                    $validated['opening_date'],
                    $validated['opening_date'] === null
                        ? PDO::PARAM_NULL
                        : PDO::PARAM_STR
                );
                $statement->bindValue(
                    ':closing_date',
                    $validated['closing_date'],
                    $validated['closing_date'] === null
                        ? PDO::PARAM_NULL
                        : PDO::PARAM_STR
                );
                $statement->bindValue(':status', $validated['status']);
                $statement->execute();

                if ($productionId === null) {
                    $productionId = (int) $connection->lastInsertId();
                }

                productionManagementRedirect(
                    $productionId,
                    $action === 'create_production'
                        ? 'production_created'
                        : 'production_updated'
                );
            }

            if (in_array($action, ['create_venue', 'update_venue'], true)) {
                $venueId = $action === 'update_venue'
                    ? productionManagementPositiveInteger(
                        $_POST['venue_id'] ?? null
                    )
                    : null;
                $venueName = productionManagementText($_POST, 'venue_name');
                $venueCode = productionManagementText($_POST, 'venue_code');
                $venueIsActive = $action === 'create_venue'
                    || ($_POST['venue_is_active'] ?? '') === '1';

                $newVenueValues = [
                    'name' => $venueName,
                    'code' => $venueCode,
                ];

                if ($venueName === '' || strlen($venueName) > 150) {
                    throw new DomainException(
                        'Enter a venue name of 150 characters or fewer.'
                    );
                }

                if (strlen($venueCode) > 30) {
                    throw new DomainException(
                        'Keep the optional venue code under 30 characters.'
                    );
                }

                if ($action === 'update_venue' && $venueId === null) {
                    throw new DomainException('The venue could not be identified.');
                }

                $duplicateVenueStatement = $connection->prepare(
                    'SELECT id
                     FROM venues
                     WHERE (
                            name = :venue_name
                            OR (
                                :has_code = 1
                                AND code = :venue_code
                            )
                     )
                       AND (:excluded_id IS NULL OR id <> :excluded_id_value)
                     LIMIT 1'
                );
                $duplicateVenueStatement->bindValue(':venue_name', $venueName);
                $duplicateVenueStatement->bindValue(
                    ':has_code',
                    $venueCode !== '' ? 1 : 0,
                    PDO::PARAM_INT
                );
                $duplicateVenueStatement->bindValue(':venue_code', $venueCode);
                $duplicateVenueStatement->bindValue(
                    ':excluded_id',
                    $venueId,
                    $venueId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
                );
                $duplicateVenueStatement->bindValue(
                    ':excluded_id_value',
                    $venueId ?? 0,
                    PDO::PARAM_INT
                );
                $duplicateVenueStatement->execute();

                if ($duplicateVenueStatement->fetchColumn() !== false) {
                    throw new DomainException(
                        'That venue name or code is already in use.'
                    );
                }

                if ($action === 'create_venue') {
                    $venueStatement = $connection->prepare(
                        'INSERT INTO venues (code, name, is_active)
                         VALUES (:code, :name, 1)'
                    );
                } else {
                    $venueStatement = $connection->prepare(
                        'UPDATE venues
                         SET code = :code,
                             name = :name,
                             is_active = :is_active
                         WHERE id = :venue_id'
                    );
                    $venueStatement->bindValue(
                        ':venue_id',
                        $venueId,
                        PDO::PARAM_INT
                    );
                    $venueStatement->bindValue(
                        ':is_active',
                        $venueIsActive ? 1 : 0,
                        PDO::PARAM_INT
                    );
                }

                $venueStatement->bindValue(
                    ':code',
                    $venueCode !== '' ? $venueCode : null,
                    $venueCode !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
                );
                $venueStatement->bindValue(':name', $venueName);
                $venueStatement->execute();

                productionManagementRedirect(
                    $selectedProductionId,
                    $action === 'create_venue'
                        ? 'venue_created'
                        : 'venue_updated'
                );
            }

            if ($action === 'add_cast_member') {
                $productionId = productionManagementPositiveInteger(
                    $_POST['production_id'] ?? null
                );
                $personText = productionManagementText($_POST, 'person_id');
                $personId = productionManagementPositiveInteger($personText);
                $firstName = productionManagementText($_POST, 'first_name');
                $lastName = productionManagementText($_POST, 'last_name');
                $characterName = productionManagementText(
                    $_POST,
                    'character_name'
                );
                $castValues = [
                    'person_id' => $personText,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'character_name' => $characterName,
                ];

                if ($productionId === null) {
                    throw new DomainException(
                        'Choose a production before adding cast.'
                    );
                }

                if ($personText !== '' && $personId === null) {
                    throw new DomainException('Choose a valid existing actor.');
                }

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

                if ($characterName === '' || strlen($characterName) > 150) {
                    throw new DomainException(
                        'Enter a character name of 150 characters or fewer.'
                    );
                }

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
                    throw new DomainException('The production was not found.');
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
                    $personStatement->execute([
                        'person_id' => $personId,
                    ]);

                    if ($personStatement->fetchColumn() === false) {
                        throw new DomainException(
                            'The selected actor is no longer active.'
                        );
                    }
                } else {
                    $displayName = trim($firstName . ' ' . $lastName);
                    $personStatement = $connection->prepare(
                        'SELECT id, is_active
                         FROM people
                         WHERE display_name = :display_name
                            OR (
                                first_name = :first_name
                                AND COALESCE(last_name, \'\') = :last_name
                            )
                         LIMIT 1'
                    );
                    $personStatement->execute([
                        'display_name' => $displayName,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ]);
                    $person = $personStatement->fetch();

                    if ($person === false) {
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
                    } else {
                        $personId = (int) $person['id'];

                        if ((int) $person['is_active'] === 0) {
                            $reactivatePersonStatement = $connection->prepare(
                                'UPDATE people
                                 SET is_active = 1
                                 WHERE id = :person_id'
                            );
                            $reactivatePersonStatement->execute([
                                'person_id' => $personId,
                            ]);
                        }
                    }
                }

                $duplicateCastStatement = $connection->prepare(
                    'SELECT id, is_active
                     FROM production_cast
                     WHERE production_id = :production_id
                       AND person_id = :person_id
                       AND character_name = :character_name
                     LIMIT 1
                     FOR UPDATE'
                );
                $duplicateCastStatement->execute([
                    'production_id' => $productionId,
                    'person_id' => $personId,
                    'character_name' => $characterName,
                ]);
                $existingCast = $duplicateCastStatement->fetch();

                if ($existingCast !== false) {
                    if ((int) $existingCast['is_active'] === 1) {
                        throw new DomainException(
                            'That actor and character are already in this cast.'
                        );
                    }

                    $reactivateStatement = $connection->prepare(
                        'UPDATE production_cast
                         SET is_active = 1
                         WHERE id = :cast_id'
                    );
                    $reactivateStatement->execute([
                        'cast_id' => (int) $existingCast['id'],
                    ]);
                    $connection->commit();
                    productionManagementRedirect(
                        $productionId,
                        'cast_reactivated'
                    );
                }

                $orderStatement = $connection->prepare(
                    'SELECT COALESCE(MAX(display_order), 0) + 1
                     FROM production_cast
                     WHERE production_id = :production_id'
                );
                $orderStatement->execute([
                    'production_id' => $productionId,
                ]);

                $insertCastStatement = $connection->prepare(
                    'INSERT INTO production_cast (
                        production_id,
                        person_id,
                        character_name,
                        display_order,
                        is_active
                     ) VALUES (
                        :production_id,
                        :person_id,
                        :character_name,
                        :display_order,
                        1
                     )'
                );
                $insertCastStatement->execute([
                    'production_id' => $productionId,
                    'person_id' => $personId,
                    'character_name' => $characterName,
                    'display_order' => (int) $orderStatement->fetchColumn(),
                ]);
                $connection->commit();
                productionManagementRedirect($productionId, 'cast_added');
            }

            if ($action === 'update_cast_member') {
                $productionId = productionManagementPositiveInteger(
                    $_POST['production_id'] ?? null
                );
                $castId = productionManagementPositiveInteger(
                    $_POST['production_cast_id'] ?? null
                );
                $characterName = productionManagementText(
                    $_POST,
                    'character_name'
                );
                $displayOrderText = productionManagementText(
                    $_POST,
                    'display_order'
                );
                $isActive = ($_POST['is_active'] ?? '') === '1';

                if ($productionId === null || $castId === null) {
                    throw new DomainException(
                        'The cast assignment could not be identified.'
                    );
                }

                if ($characterName === '' || strlen($characterName) > 150) {
                    throw new DomainException(
                        'Enter a character name of 150 characters or fewer.'
                    );
                }

                if (
                    $displayOrderText === ''
                    || !ctype_digit($displayOrderText)
                    || (int) $displayOrderText > 65535
                ) {
                    throw new DomainException(
                        'Enter a cast order from 0 through 65535.'
                    );
                }

                $castStatement = $connection->prepare(
                    'SELECT person_id
                     FROM production_cast
                     WHERE id = :cast_id
                       AND production_id = :production_id
                     LIMIT 1'
                );
                $castStatement->execute([
                    'cast_id' => $castId,
                    'production_id' => $productionId,
                ]);
                $personId = $castStatement->fetchColumn();

                if ($personId === false) {
                    throw new DomainException(
                        'The cast assignment was not found.'
                    );
                }

                $duplicateStatement = $connection->prepare(
                    'SELECT id
                     FROM production_cast
                     WHERE production_id = :production_id
                       AND person_id = :person_id
                       AND character_name = :character_name
                       AND id <> :cast_id
                     LIMIT 1'
                );
                $duplicateStatement->execute([
                    'production_id' => $productionId,
                    'person_id' => (int) $personId,
                    'character_name' => $characterName,
                    'cast_id' => $castId,
                ]);

                if ($duplicateStatement->fetchColumn() !== false) {
                    throw new DomainException(
                        'That actor and character are already in this cast.'
                    );
                }

                $updateCastStatement = $connection->prepare(
                    'UPDATE production_cast
                     SET character_name = :character_name,
                         display_order = :display_order,
                         is_active = :is_active
                     WHERE id = :cast_id
                       AND production_id = :production_id'
                );
                $updateCastStatement->execute([
                    'character_name' => $characterName,
                    'display_order' => (int) $displayOrderText,
                    'is_active' => $isActive ? 1 : 0,
                    'cast_id' => $castId,
                    'production_id' => $productionId,
                ]);
                productionManagementRedirect($productionId, 'cast_updated');
            }

            throw new DomainException('Choose a valid production action.');
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $failedAction = $action;
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $failedAction = $action;
            $errors[] = 'The production change could not be saved.';
        }
    }
}

$notices = [
    'production_created' => 'The production was created.',
    'production_updated' => 'The production details were saved.',
    'venue_created' => 'The venue was created.',
    'venue_updated' => 'The venue details were saved.',
    'cast_added' => 'The actor and character were added to the cast.',
    'cast_reactivated' => 'The existing actor and character were returned to the active cast.',
    'cast_updated' => 'The cast assignment was saved.',
];
$noticeKey = productionManagementText($_GET, 'saved');
$notice = $notices[$noticeKey] ?? null;

$productionStatement = $connection->query(
    "SELECT
        pr.id,
        pr.name,
        pr.venue_id,
        pr.production_year,
        pr.opening_date,
        pr.closing_date,
        pr.status,
        ve.name AS venue_name,
        (
            SELECT COUNT(*)
            FROM production_cast AS pc
            WHERE pc.production_id = pr.id
              AND pc.is_active = 1
        ) AS active_cast_count,
        (
            SELECT COUNT(*)
            FROM asset_checkouts AS ac
            JOIN production_cast AS pc
                ON pc.id = ac.production_cast_id
            WHERE pc.production_id = pr.id
              AND ac.status = 'active'
        ) AS active_checkout_count,
        (
            SELECT COUNT(*)
            FROM measurement_sessions AS ms
            WHERE ms.production_id = pr.id
        ) AS measurement_session_count
     FROM productions AS pr
     LEFT JOIN venues AS ve
        ON ve.id = pr.venue_id
     ORDER BY
        CASE pr.status
            WHEN 'active' THEN 0
            WHEN 'planned' THEN 1
            WHEN 'completed' THEN 2
            WHEN 'cancelled' THEN 3
            ELSE 4
        END,
        COALESCE(pr.opening_date, CONCAT(pr.production_year, '-01-01')) DESC,
        pr.name,
        pr.id"
);
$productions = $productionStatement->fetchAll();

if ($selectedProductionId !== null) {
    $selectedProductionExists = false;

    foreach ($productions as $production) {
        if ((int) $production['id'] === $selectedProductionId) {
            $selectedProductionExists = true;
            break;
        }
    }

    if (!$selectedProductionExists) {
        $selectedProductionId = null;
    }
}

if ($selectedProductionId === null && $productions !== []) {
    $selectedProductionId = (int) $productions[0]['id'];
}

$selectedProduction = null;
foreach ($productions as $production) {
    if ((int) $production['id'] === $selectedProductionId) {
        $selectedProduction = $production;
        break;
    }
}

$venueStatement = $connection->query(
    'SELECT
        ve.id,
        ve.code,
        ve.name,
        ve.is_active,
        (
            SELECT COUNT(*)
            FROM productions AS pr
            WHERE pr.venue_id = ve.id
        ) AS production_count
     FROM venues AS ve
     ORDER BY ve.is_active DESC, ve.name, ve.id'
);
$venues = $venueStatement->fetchAll();

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

$castMembers = [];
if ($selectedProductionId !== null) {
    $castStatement = $connection->prepare(
        'SELECT
            pc.id,
            pc.person_id,
            pc.character_name,
            pc.display_order,
            pc.is_active,
            pe.display_name AS actor_name,
            (
                SELECT COUNT(*)
                FROM asset_checkouts AS ac
                WHERE ac.production_cast_id = pc.id
            ) AS checkout_count
         FROM production_cast AS pc
         JOIN people AS pe
            ON pe.id = pc.person_id
         WHERE pc.production_id = :production_id
         ORDER BY pc.is_active DESC, pc.display_order, pe.display_name, pc.id'
    );
    $castStatement->execute([
        'production_id' => $selectedProductionId,
    ]);
    $castMembers = $castStatement->fetchAll();
}

if (
    $selectedProduction !== null
    && !(
        $failedAction === 'update_production'
        && productionManagementPositiveInteger(
            $_POST['production_id'] ?? null
        ) === $selectedProductionId
    )
) {
    $productionValues = [
        'name' => (string) $selectedProduction['name'],
        'venue_id' => (string) ($selectedProduction['venue_id'] ?? ''),
        'production_year' => (string) (
            $selectedProduction['production_year'] ?? ''
        ),
        'opening_date' => (string) ($selectedProduction['opening_date'] ?? ''),
        'closing_date' => (string) ($selectedProduction['closing_date'] ?? ''),
        'status' => (string) $selectedProduction['status'],
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productions — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260904-1">
</head>
<body>
<main>
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/productions.php" aria-current="page">Productions</a>
        <a href="/checkout.php">Production checkout</a>
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
            <h1>Productions</h1>
            <p>Maintain production details, venues, and cast assignments without deleting history.</p>
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

    <div class="production-workspace">
        <aside class="production-sidebar">
            <div class="section-heading">
                <h2>Productions</h2>
                <span class="result-count"><?= count($productions) ?></span>
            </div>

            <?php if ($productions === []): ?>
                <p>No productions have been entered.</p>
            <?php else: ?>
                <div class="production-list">
                    <?php foreach ($productions as $production): ?>
                        <a
                            href="/productions.php?production_id=<?= (int) $production['id'] ?>"
                            class="production-list-item <?= (int) $production['id'] === $selectedProductionId ? 'is-current' : '' ?>"
                        >
                            <strong><?= collectionStewardEscape($production['name']) ?></strong>
                            <span>
                                <?= collectionStewardEscape((string) ($production['production_year'] ?: 'Year not recorded')) ?>
                                <?php if (!empty($production['venue_name'])): ?>
                                    · <?= collectionStewardEscape($production['venue_name']) ?>
                                <?php endif; ?>
                            </span>
                            <small><?= collectionStewardEscape(collectionStewardProductionStatusLabel((string) $production['status'])) ?> · <?= (int) $production['active_cast_count'] ?> active cast</small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <details class="production-create" <?= $productions === [] || $failedAction === 'create_production' ? 'open' : '' ?>>
                <summary>Add production</summary>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_production">
                    <div class="field">
                        <label for="new_production_name">Name</label>
                        <input type="text" id="new_production_name" name="name" maxlength="150" value="<?= $failedAction === 'create_production' ? collectionStewardEscape($createProductionValues['name']) : '' ?>" required>
                    </div>
                    <div class="field">
                        <label for="new_production_venue">Venue</label>
                        <select id="new_production_venue" name="venue_id">
                            <option value="">Not selected</option>
                            <?php foreach ($venues as $venue): ?>
                                <?php if ((int) $venue['is_active'] === 1): ?>
                                    <option value="<?= (int) $venue['id'] ?>" <?= $failedAction === 'create_production' && (string) $venue['id'] === $createProductionValues['venue_id'] ? 'selected' : '' ?>><?= collectionStewardEscape($venue['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="production-date-grid">
                        <div class="field">
                            <label for="new_production_year">Year</label>
                            <input type="number" id="new_production_year" name="production_year" min="1900" max="2200" value="<?= $failedAction === 'create_production' ? collectionStewardEscape($createProductionValues['production_year']) : (new DateTimeImmutable('today'))->format('Y') ?>">
                        </div>
                        <div class="field">
                            <label for="new_opening_date">Opening</label>
                            <input type="date" id="new_opening_date" name="opening_date" value="<?= $failedAction === 'create_production' ? collectionStewardEscape($createProductionValues['opening_date']) : '' ?>">
                        </div>
                        <div class="field">
                            <label for="new_closing_date">Closing</label>
                            <input type="date" id="new_closing_date" name="closing_date" value="<?= $failedAction === 'create_production' ? collectionStewardEscape($createProductionValues['closing_date']) : '' ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="new_production_status">Status</label>
                        <select id="new_production_status" name="status" required>
                            <?php foreach (collectionStewardProductionStatuses() as $statusValue => $statusLabel): ?>
                                <option value="<?= collectionStewardEscape($statusValue) ?>" <?= $failedAction === 'create_production' && $createProductionValues['status'] === $statusValue ? 'selected' : ($failedAction !== 'create_production' && $statusValue === 'planned' ? 'selected' : '') ?>><?= collectionStewardEscape($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Add production</button>
                </form>
            </details>
        </aside>

        <div class="production-editor">
            <?php if ($selectedProduction === null): ?>
                <div class="production-empty-state">
                    <h2>Add the first production</h2>
                    <p>Use the form beside this panel to begin.</p>
                </div>
            <?php else: ?>
                <header class="production-record-heading">
                    <div>
                        <span class="status-badge production-status-<?= collectionStewardEscape((string) $selectedProduction['status']) ?>"><?= collectionStewardEscape(collectionStewardProductionStatusLabel((string) $selectedProduction['status'])) ?></span>
                        <h2><?= collectionStewardEscape($selectedProduction['name']) ?></h2>
                        <p>
                            <?= (int) $selectedProduction['active_cast_count'] ?> active cast ·
                            <?= (int) $selectedProduction['active_checkout_count'] ?> active checkout ·
                            <?= (int) $selectedProduction['measurement_session_count'] ?> measurement <?= (int) $selectedProduction['measurement_session_count'] === 1 ? 'session' : 'sessions' ?>
                        </p>
                    </div>
                    <div class="button-row">
                        <?php if (in_array($selectedProduction['status'], ['planned', 'active'], true)): ?>
                            <a class="button" href="/checkout.php?production_id=<?= (int) $selectedProduction['id'] ?>">Open checkout</a>
                        <?php endif; ?>
                        <a class="button secondary" href="/production-measurements.php?production_id=<?= (int) $selectedProduction['id'] ?>"><?= in_array($selectedProduction['status'], ['planned', 'active'], true) ? 'Plan measurements' : 'Production sessions' ?></a>
                        <a class="button secondary" href="/measurements.php?view=compact&amp;scope=group&amp;group_production_id=<?= (int) $selectedProduction['id'] ?>">Open measurements</a>
                    </div>
                </header>

                <section aria-labelledby="production-details-title">
                    <h3 id="production-details-title">Production details</h3>
                    <form method="post" class="production-details-form">
                        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_production">
                        <input type="hidden" name="production_id" value="<?= (int) $selectedProduction['id'] ?>">
                        <div class="field">
                            <label for="production_name">Name</label>
                            <input type="text" id="production_name" name="name" maxlength="150" value="<?= collectionStewardEscape($productionValues['name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="production_venue">Venue</label>
                            <select id="production_venue" name="venue_id">
                                <option value="">Not selected</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?= (int) $venue['id'] ?>" <?= (string) $venue['id'] === $productionValues['venue_id'] ? 'selected' : '' ?>>
                                        <?= collectionStewardEscape($venue['name']) ?><?= (int) $venue['is_active'] === 0 ? ' (inactive)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="production-date-grid">
                            <div class="field">
                                <label for="production_year">Year</label>
                                <input type="number" id="production_year" name="production_year" min="1900" max="2200" value="<?= collectionStewardEscape($productionValues['production_year']) ?>">
                            </div>
                            <div class="field">
                                <label for="opening_date">Opening</label>
                                <input type="date" id="opening_date" name="opening_date" value="<?= collectionStewardEscape($productionValues['opening_date']) ?>">
                            </div>
                            <div class="field">
                                <label for="closing_date">Closing</label>
                                <input type="date" id="closing_date" name="closing_date" value="<?= collectionStewardEscape($productionValues['closing_date']) ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label for="production_status">Status</label>
                            <select id="production_status" name="status" required>
                                <?php foreach (collectionStewardProductionStatuses() as $statusValue => $statusLabel): ?>
                                    <option value="<?= collectionStewardEscape($statusValue) ?>" <?= $productionValues['status'] === $statusValue ? 'selected' : '' ?>><?= collectionStewardEscape($statusLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="help">Completed and cancelled productions remain available as history but no longer accept new checkouts.</span>
                        </div>
                        <button type="submit">Save production details</button>
                    </form>
                </section>

                <section aria-labelledby="cast-title">
                    <div class="section-heading">
                        <div>
                            <h3 id="cast-title">Cast</h3>
                            <p>One actor may have more than one character assignment.</p>
                        </div>
                        <span class="result-count"><?= count($castMembers) ?></span>
                    </div>

                    <?php if ($castMembers === []): ?>
                        <p>No cast assignments have been entered.</p>
                    <?php else: ?>
                        <div class="cast-management-list">
                            <?php foreach ($castMembers as $castMember): ?>
                                <form method="post" class="cast-management-card <?= (int) $castMember['is_active'] === 0 ? 'is-inactive' : '' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="update_cast_member">
                                    <input type="hidden" name="production_id" value="<?= (int) $selectedProduction['id'] ?>">
                                    <input type="hidden" name="production_cast_id" value="<?= (int) $castMember['id'] ?>">
                                    <div class="cast-actor-name">
                                        <strong><?= collectionStewardEscape($castMember['actor_name']) ?></strong>
                                        <?php if ((int) $castMember['checkout_count'] > 0): ?>
                                            <small><?= (int) $castMember['checkout_count'] ?> checkout <?= (int) $castMember['checkout_count'] === 1 ? 'record' : 'records' ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="field">
                                        <label for="character_name_<?= (int) $castMember['id'] ?>">Character</label>
                                        <input type="text" id="character_name_<?= (int) $castMember['id'] ?>" name="character_name" maxlength="150" value="<?= collectionStewardEscape($castMember['character_name']) ?>" required>
                                    </div>
                                    <div class="field cast-order-field">
                                        <label for="display_order_<?= (int) $castMember['id'] ?>">Order</label>
                                        <input type="number" id="display_order_<?= (int) $castMember['id'] ?>" name="display_order" min="0" max="65535" value="<?= (int) $castMember['display_order'] ?>" required>
                                    </div>
                                    <label class="confirmation-choice">
                                        <input type="checkbox" name="is_active" value="1" <?= (int) $castMember['is_active'] === 1 ? 'checked' : '' ?>>
                                        Active cast
                                    </label>
                                    <button type="submit" class="secondary">Save assignment</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <details class="production-create" <?= $failedAction === 'add_cast_member' ? 'open' : '' ?>>
                        <summary>Add actor and character</summary>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="add_cast_member">
                            <input type="hidden" name="production_id" value="<?= (int) $selectedProduction['id'] ?>">
                            <div class="field">
                                <label for="person_id">Existing actor</label>
                                <select id="person_id" name="person_id">
                                    <option value="">Enter a new actor below</option>
                                    <?php foreach ($people as $person): ?>
                                        <option value="<?= (int) $person['id'] ?>" <?= $castValues['person_id'] === (string) $person['id'] ? 'selected' : '' ?>><?= collectionStewardEscape($person['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="production-date-grid">
                                <div class="field">
                                    <label for="cast_first_name">New first name</label>
                                    <input type="text" id="cast_first_name" name="first_name" maxlength="100" value="<?= collectionStewardEscape($castValues['first_name']) ?>">
                                </div>
                                <div class="field">
                                    <label for="cast_last_name">New last name</label>
                                    <input type="text" id="cast_last_name" name="last_name" maxlength="100" value="<?= collectionStewardEscape($castValues['last_name']) ?>">
                                </div>
                            </div>
                            <div class="field">
                                <label for="new_character_name">Character</label>
                                <input type="text" id="new_character_name" name="character_name" maxlength="150" value="<?= collectionStewardEscape($castValues['character_name']) ?>" required>
                            </div>
                            <button type="submit">Add to cast</button>
                        </form>
                    </details>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <section class="venue-management" aria-labelledby="venue-management-title">
        <div class="section-heading">
            <div>
                <h2 id="venue-management-title">Venues</h2>
                <p>Inactive venues remain attached to historical productions.</p>
            </div>
            <span class="result-count"><?= count($venues) ?></span>
        </div>

        <details class="production-create" <?= $failedAction === 'create_venue' ? 'open' : '' ?>>
            <summary>Add venue</summary>
            <form method="post" class="venue-form">
                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="create_venue">
                <?php if ($selectedProductionId !== null): ?>
                    <input type="hidden" name="production_id" value="<?= $selectedProductionId ?>">
                <?php endif; ?>
                <div class="field">
                    <label for="new_venue_name">Name</label>
                    <input type="text" id="new_venue_name" name="venue_name" maxlength="150" value="<?= collectionStewardEscape($newVenueValues['name']) ?>" required>
                </div>
                <div class="field">
                    <label for="new_venue_code">Short code (optional)</label>
                    <input type="text" id="new_venue_code" name="venue_code" maxlength="30" value="<?= collectionStewardEscape($newVenueValues['code']) ?>">
                </div>
                <button type="submit">Add venue</button>
            </form>
        </details>

        <?php if ($venues !== []): ?>
            <div class="venue-list">
                <?php foreach ($venues as $venue): ?>
                    <details class="venue-card">
                        <summary>
                            <?= collectionStewardEscape($venue['name']) ?>
                            <?= (int) $venue['is_active'] === 0 ? ' — Inactive' : '' ?>
                            <small>· <?= (int) $venue['production_count'] ?> <?= (int) $venue['production_count'] === 1 ? 'production' : 'productions' ?></small>
                        </summary>
                        <form method="post" class="venue-form">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_venue">
                            <input type="hidden" name="venue_id" value="<?= (int) $venue['id'] ?>">
                            <?php if ($selectedProductionId !== null): ?>
                                <input type="hidden" name="production_id" value="<?= $selectedProductionId ?>">
                            <?php endif; ?>
                            <div class="field">
                                <label for="venue_name_<?= (int) $venue['id'] ?>">Name</label>
                                <input type="text" id="venue_name_<?= (int) $venue['id'] ?>" name="venue_name" maxlength="150" value="<?= collectionStewardEscape($venue['name']) ?>" required>
                            </div>
                            <div class="field">
                                <label for="venue_code_<?= (int) $venue['id'] ?>">Short code</label>
                                <input type="text" id="venue_code_<?= (int) $venue['id'] ?>" name="venue_code" maxlength="30" value="<?= collectionStewardEscape($venue['code']) ?>">
                            </div>
                            <label class="confirmation-choice">
                                <input type="checkbox" name="venue_is_active" value="1" <?= (int) $venue['is_active'] === 1 ? 'checked' : '' ?>>
                                Active venue
                            </label>
                            <button type="submit" class="secondary">Save venue</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
