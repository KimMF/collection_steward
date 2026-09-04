<?php

declare(strict_types=1);

/**
 * Administrator-only record searches, integrity checks, and corrections.
 *
 * This page deliberately uses fixed queries and allowlisted correction forms.
 * It is not a SQL console or a general-purpose table editor.
 *
 * Public entry point: /admin.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'admin_maintenance'
);
$csrfToken = collectionStewardCsrfToken();

function administratorPositiveInteger(mixed $value): ?int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);

    return is_int($validated) && $validated > 0
        ? $validated
        : null;
}

function administratorText(array $source, string $key): string
{
    return is_string($source[$key] ?? null)
        ? trim($source[$key])
        : '';
}

function administratorNormalizedText(array $source, string $key): string
{
    $value = administratorText($source, $key);
    $normalized = preg_replace('/\s+/u', ' ', $value);

    return is_string($normalized) ? trim($normalized) : $value;
}

function administratorJson(array $values): string
{
    $encoded = json_encode(
        $values,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (!is_string($encoded)) {
        throw new RuntimeException('The audit values could not be encoded.');
    }

    return $encoded;
}

function administratorRecordChange(
    PDO $connection,
    string $recordType,
    int $recordId,
    string $recordLabel,
    array $previousValues,
    array $newValues,
    string $reason,
    int $userId
): void {
    $statement = $connection->prepare(
        'INSERT INTO administrative_change_history (
            record_type,
            record_id,
            record_label,
            change_action,
            previous_values,
            new_values,
            change_reason,
            changed_by_user_id
         ) VALUES (
            :record_type,
            :record_id,
            :record_label,
            \'corrected\',
            :previous_values,
            :new_values,
            :change_reason,
            :changed_by_user_id
         )'
    );
    $statement->execute([
        'record_type' => $recordType,
        'record_id' => $recordId,
        'record_label' => $recordLabel,
        'previous_values' => administratorJson($previousValues),
        'new_values' => administratorJson($newValues),
        'change_reason' => $reason,
        'changed_by_user_id' => $userId,
    ]);
}

function administratorCount(PDO $connection, string $sql): int
{
    return (int) $connection->query($sql)->fetchColumn();
}

// Every identifier below is fixed application code. Request values select a
// key from this map and are never interpolated as a table or column name.
$vocabularyConfigurations = [
    'asset_type' => [
        'label' => 'Asset type',
        'table' => 'asset_types',
        'asset_column' => 'asset_type_id',
        'has_display_order' => true,
        'has_description' => false,
    ],
    'wearer' => [
        'label' => 'Wearer',
        'table' => 'wearer_options',
        'asset_column' => 'wearer_option_id',
        'has_display_order' => true,
        'has_description' => false,
    ],
    'color' => [
        'label' => 'Color',
        'table' => 'color_options',
        'asset_column' => 'primary_color_option_id',
        'has_display_order' => true,
        'has_description' => false,
    ],
    'size' => [
        'label' => 'Size',
        'table' => 'size_options',
        'asset_column' => 'size_option_id',
        'has_display_order' => true,
        'has_description' => false,
    ],
    'length' => [
        'label' => 'Length',
        'table' => 'length_options',
        'asset_column' => 'length_option_id',
        'has_display_order' => true,
        'has_description' => false,
    ],
    'tag' => [
        'label' => 'Tag',
        'table' => 'tags',
        'asset_column' => null,
        'has_display_order' => false,
        'has_description' => true,
    ],
];

$recordTypes = [
    'assets' => 'Assets',
    'people' => 'People and actors',
    'productions' => 'Productions',
    'measurements' => 'Measurement sessions',
    'checkouts' => 'Checkout history',
    'fittings' => 'Fittings',
    'users' => 'User accounts',
    'vocabulary' => 'Controlled vocabulary',
    'history' => 'Administrative change history',
];

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = administratorText($_POST, 'action');

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'save_person') {
                $personId = administratorPositiveInteger(
                    $_POST['person_id'] ?? null
                );
                $displayName = administratorNormalizedText(
                    $_POST,
                    'display_name'
                );
                $firstName = administratorNormalizedText(
                    $_POST,
                    'first_name'
                );
                $lastName = administratorNormalizedText(
                    $_POST,
                    'last_name'
                );
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $reason = administratorNormalizedText(
                    $_POST,
                    'change_reason'
                );

                if ($personId === null) {
                    throw new DomainException('Choose a valid person record.');
                }

                if ($displayName === '' || strlen($displayName) > 150) {
                    throw new DomainException(
                        'Enter a display name of 150 characters or fewer.'
                    );
                }

                if (strlen($firstName) > 100 || strlen($lastName) > 100) {
                    throw new DomainException(
                        'First and last names must be 100 characters or fewer.'
                    );
                }

                if ($reason === '' || strlen($reason) > 1000) {
                    throw new DomainException(
                        'Enter a correction reason of 1,000 characters or fewer.'
                    );
                }

                $connection->beginTransaction();
                $personStatement = $connection->prepare(
                    'SELECT id, display_name, first_name, last_name, is_active
                     FROM people
                     WHERE id = :person_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $personStatement->execute(['person_id' => $personId]);
                $person = $personStatement->fetch();

                if ($person === false) {
                    throw new DomainException('That person was not found.');
                }

                $previousValues = [
                    'display_name' => (string) $person['display_name'],
                    'first_name' => $person['first_name'],
                    'last_name' => $person['last_name'],
                    'is_active' => (int) $person['is_active'],
                ];
                $newValues = [
                    'display_name' => $displayName,
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'is_active' => $isActive,
                ];

                if ($previousValues === $newValues) {
                    throw new DomainException(
                        'Change at least one person field before saving.'
                    );
                }

                $updateStatement = $connection->prepare(
                    'UPDATE people
                     SET display_name = :display_name,
                         first_name = :first_name,
                         last_name = :last_name,
                         is_active = :is_active
                     WHERE id = :person_id'
                );
                $updateStatement->execute([
                    'display_name' => $displayName,
                    'first_name' => $newValues['first_name'],
                    'last_name' => $newValues['last_name'],
                    'is_active' => $isActive,
                    'person_id' => $personId,
                ]);

                administratorRecordChange(
                    $connection,
                    'person',
                    $personId,
                    $displayName,
                    $previousValues,
                    $newValues,
                    $reason,
                    (int) $currentUser['id']
                );
                $connection->commit();

                header(
                    'Location: /admin.php?record_type=people&person_id='
                    . $personId
                    . '&person_saved=1'
                );
                exit;
            }

            if ($action === 'save_vocabulary') {
                $vocabularyType = administratorText(
                    $_POST,
                    'vocabulary_type'
                );
                $recordId = administratorPositiveInteger(
                    $_POST['record_id'] ?? null
                );
                $name = administratorNormalizedText($_POST, 'name');
                $description = administratorText($_POST, 'description');
                $displayOrderText = administratorText(
                    $_POST,
                    'display_order'
                );
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $reason = administratorNormalizedText(
                    $_POST,
                    'change_reason'
                );
                $configuration =
                    $vocabularyConfigurations[$vocabularyType] ?? null;

                if ($configuration === null || $recordId === null) {
                    throw new DomainException(
                        'Choose a valid controlled-vocabulary record.'
                    );
                }

                if ($name === '' || strlen($name) > 100) {
                    throw new DomainException(
                        'Enter an approved name of 100 characters or fewer.'
                    );
                }

                if (strlen($description) > 5000) {
                    throw new DomainException(
                        'The tag description must be 5,000 characters or fewer.'
                    );
                }

                $displayOrder = 0;
                if ($configuration['has_display_order']) {
                    $validatedOrder = filter_var(
                        $displayOrderText,
                        FILTER_VALIDATE_INT,
                        [
                            'options' => [
                                'min_range' => 0,
                                'max_range' => 65535,
                            ],
                        ]
                    );
                    if (!is_int($validatedOrder)) {
                        throw new DomainException(
                            'Display order must be a whole number from 0 through 65,535.'
                        );
                    }
                    $displayOrder = $validatedOrder;
                }

                if ($reason === '' || strlen($reason) > 1000) {
                    throw new DomainException(
                        'Enter a correction reason of 1,000 characters or fewer.'
                    );
                }

                $selectColumns = 'id, name, is_active';
                if ($configuration['has_display_order']) {
                    $selectColumns .= ', display_order';
                }
                if ($configuration['has_description']) {
                    $selectColumns .= ', description';
                }

                $connection->beginTransaction();
                $recordStatement = $connection->prepare(
                    'SELECT ' . $selectColumns . '
                     FROM ' . $configuration['table'] . '
                     WHERE id = :record_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $recordStatement->execute(['record_id' => $recordId]);
                $record = $recordStatement->fetch();

                if ($record === false) {
                    throw new DomainException(
                        'That controlled-vocabulary record was not found.'
                    );
                }

                $duplicateStatement = $connection->prepare(
                    'SELECT id
                     FROM ' . $configuration['table'] . '
                     WHERE name = :name
                       AND id <> :record_id
                     LIMIT 1'
                );
                $duplicateStatement->execute([
                    'name' => $name,
                    'record_id' => $recordId,
                ]);

                if ($duplicateStatement->fetchColumn() !== false) {
                    throw new DomainException(
                        'Another option already uses that approved name.'
                    );
                }

                $previousValues = [
                    'name' => (string) $record['name'],
                    'is_active' => (int) $record['is_active'],
                ];
                $newValues = [
                    'name' => $name,
                    'is_active' => $isActive,
                ];
                $setParts = [
                    'name = :name',
                    'is_active = :is_active',
                ];
                $parameters = [
                    'name' => $name,
                    'is_active' => $isActive,
                    'record_id' => $recordId,
                ];

                if ($configuration['has_display_order']) {
                    $previousValues['display_order'] =
                        (int) $record['display_order'];
                    $newValues['display_order'] = $displayOrder;
                    $setParts[] = 'display_order = :display_order';
                    $parameters['display_order'] = $displayOrder;
                }

                if ($configuration['has_description']) {
                    $previousValues['description'] = $record['description'];
                    $newValues['description'] =
                        $description !== '' ? $description : null;
                    $setParts[] = 'description = :description';
                    $parameters['description'] = $newValues['description'];
                }

                if ($previousValues === $newValues) {
                    throw new DomainException(
                        'Change at least one vocabulary field before saving.'
                    );
                }

                $updateStatement = $connection->prepare(
                    'UPDATE ' . $configuration['table'] . '
                     SET ' . implode(', ', $setParts) . '
                     WHERE id = :record_id'
                );
                $updateStatement->execute($parameters);

                if (
                    $configuration['asset_column'] !== null
                    && $previousValues['name'] !== $newValues['name']
                ) {
                    $assetStatement = $connection->prepare(
                        'SELECT id
                         FROM assets
                         WHERE ' . $configuration['asset_column'] .
                            ' = :record_id'
                    );
                    $assetStatement->execute(['record_id' => $recordId]);
                    foreach ($assetStatement->fetchAll() as $asset) {
                        collectionStewardRefreshAssetName(
                            $connection,
                            (int) $asset['id'],
                            (string) $currentUser['display_name']
                        );
                    }
                }

                administratorRecordChange(
                    $connection,
                    'vocabulary_' . $vocabularyType,
                    $recordId,
                    $configuration['label'] . ': ' . $name,
                    $previousValues,
                    $newValues,
                    $reason,
                    (int) $currentUser['id']
                );
                $connection->commit();

                header(
                    'Location: /admin.php?record_type=vocabulary'
                    . '&vocabulary_type=' . rawurlencode($vocabularyType)
                    . '&record_id=' . $recordId
                    . '&vocabulary_saved=1'
                );
                exit;
            }

            throw new DomainException(
                'Choose a valid administrator maintenance action.'
            );
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = 'The administrator correction could not be saved.';
        }
    }
}

if (isset($_GET['person_saved'])) {
    $notice = 'The person record was corrected and an audit entry was added.';
} elseif (isset($_GET['vocabulary_saved'])) {
    $notice = 'The vocabulary record was corrected and an audit entry was added.';
}

$issueCounts = [
    'asset_review' => administratorCount(
        $connection,
        "SELECT COUNT(*) FROM assets WHERE asset_review_status = 'pending'"
    ),
    'vocabulary' => administratorCount(
        $connection,
        "SELECT COUNT(*) FROM vocabulary_suggestions WHERE status = 'pending'"
    ),
    'measurements' => administratorCount(
        $connection,
        'SELECT COUNT(*) FROM measurement_values WHERE needs_review = 1'
    ),
    'fittings' => administratorCount(
        $connection,
        "SELECT COUNT(*) FROM fitting_assets WHERE outcome = 'pending'"
    ),
];

$availabilityIssueStatement = $connection->query(
    'SELECT
        a.id,
        a.name,
        a.availability_status,
        a.collection_status,
        COALESCE(SUM(ac.status = \'active\'), 0) AS active_checkout_count
     FROM assets AS a
     LEFT JOIN asset_checkouts AS ac
        ON ac.asset_id = a.id
     GROUP BY
        a.id,
        a.name,
        a.availability_status,
        a.collection_status
     HAVING
        (a.availability_status = \'available\'
            AND active_checkout_count > 0)
        OR (a.availability_status = \'checked_out\'
            AND active_checkout_count = 0)
        OR (a.collection_status = \'retired\'
            AND active_checkout_count > 0)
     ORDER BY a.id
     LIMIT 100'
);
$availabilityIssues = $availabilityIssueStatement->fetchAll();

$recordType = administratorText($_GET, 'record_type');
if (!isset($recordTypes[$recordType])) {
    $recordType = 'assets';
}

$searchQuery = administratorNormalizedText($_GET, 'q');
if (strlen($searchQuery) > 150) {
    $searchQuery = substr($searchQuery, 0, 150);
}
$searchPattern = '%' . $searchQuery . '%';
$searchResults = [];

if ($recordType === 'assets') {
    $statement = $connection->prepare(
        'SELECT
            a.id,
            a.name,
            a.storage_location,
            a.availability_status,
            a.collection_status,
            a.asset_review_status,
            aty.name AS asset_type
         FROM assets AS a
         LEFT JOIN asset_types AS aty
            ON aty.id = a.asset_type_id
         WHERE CONCAT_WS(
            \' \' ,
            a.id,
            a.name,
            a.storage_location,
            a.availability_status,
            a.collection_status,
            aty.name
         ) LIKE :search_pattern
         ORDER BY a.id DESC
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'people') {
    $statement = $connection->prepare(
        'SELECT
            pe.id,
            pe.display_name,
            pe.first_name,
            pe.last_name,
            pe.is_active,
            (SELECT COUNT(*) FROM production_cast AS pc
                WHERE pc.person_id = pe.id) AS cast_count,
            (SELECT COUNT(*) FROM measurement_sessions AS ms
                WHERE ms.person_id = pe.id) AS measurement_count,
            (SELECT COUNT(*)
                FROM fittings AS f
                JOIN production_cast AS pc2
                    ON pc2.id = f.production_cast_id
                WHERE pc2.person_id = pe.id) AS fitting_count
         FROM people AS pe
         WHERE CONCAT_WS(
            \' \' ,
            pe.id,
            pe.display_name,
            pe.first_name,
            pe.last_name
         ) LIKE :search_pattern
         ORDER BY pe.is_active DESC,
            COALESCE(pe.last_name, pe.display_name),
            COALESCE(pe.first_name, pe.display_name),
            pe.id
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'productions') {
    $statement = $connection->prepare(
        'SELECT
            pr.id,
            pr.name,
            pr.production_year,
            pr.status,
            pr.opening_date,
            pr.closing_date,
            ve.name AS venue_name,
            (SELECT COUNT(*) FROM production_cast AS pc
                WHERE pc.production_id = pr.id) AS cast_count
         FROM productions AS pr
         LEFT JOIN venues AS ve
            ON ve.id = pr.venue_id
         WHERE CONCAT_WS(
            \' \' ,
            pr.id,
            pr.name,
            pr.production_year,
            pr.status,
            ve.name
         ) LIKE :search_pattern
         ORDER BY COALESCE(pr.production_year, 0) DESC, pr.name, pr.id DESC
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'measurements') {
    $statement = $connection->prepare(
        'SELECT
            ms.id,
            pe.display_name AS actor_name,
            pr.name AS production_name,
            ms.measured_on,
            ms.review_status,
            ms.notes,
            (SELECT COUNT(*) FROM measurement_values AS mv
                WHERE mv.measurement_session_id = ms.id) AS value_count,
            (SELECT COUNT(*) FROM measurement_values AS mv2
                WHERE mv2.measurement_session_id = ms.id
                  AND mv2.needs_review = 1) AS flagged_count
         FROM measurement_sessions AS ms
         JOIN people AS pe
            ON pe.id = ms.person_id
         LEFT JOIN productions AS pr
            ON pr.id = ms.production_id
         WHERE CONCAT_WS(
            \' \' ,
            ms.id,
            pe.display_name,
            pr.name,
            ms.measured_on,
            ms.review_status,
            ms.notes
         ) LIKE :search_pattern
         ORDER BY ms.measured_on DESC, pe.display_name, ms.id DESC
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'checkouts') {
    $statement = $connection->prepare(
        'SELECT
            ac.id,
            ac.asset_id,
            a.name AS asset_name,
            pc.production_id,
            pr.name AS production_name,
            pe.display_name AS actor_name,
            pc.character_name,
            ac.status,
            ac.checked_out_at,
            ac.notes
         FROM asset_checkouts AS ac
         JOIN assets AS a
            ON a.id = ac.asset_id
         JOIN production_cast AS pc
            ON pc.id = ac.production_cast_id
         JOIN productions AS pr
            ON pr.id = pc.production_id
         JOIN people AS pe
            ON pe.id = pc.person_id
         WHERE CONCAT_WS(
            \' \' ,
            ac.id,
            ac.asset_id,
            a.name,
            pr.name,
            pe.display_name,
            pc.character_name,
            ac.status,
            ac.notes
         ) LIKE :search_pattern
         ORDER BY ac.checked_out_at DESC, ac.id DESC
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'fittings') {
    $statement = $connection->prepare(
        'SELECT
            f.id,
            pc.production_id,
            pr.name AS production_name,
            pe.display_name AS actor_name,
            pc.character_name,
            f.fitting_date,
            f.status,
            (SELECT COUNT(*) FROM fitting_assets AS fa
                WHERE fa.fitting_id = f.id) AS candidate_count,
            (SELECT COUNT(*) FROM fitting_assets AS fa2
                WHERE fa2.fitting_id = f.id
                  AND fa2.outcome = \'selected_for_wear\') AS selected_count
         FROM fittings AS f
         JOIN production_cast AS pc
            ON pc.id = f.production_cast_id
         JOIN productions AS pr
            ON pr.id = pc.production_id
         JOIN people AS pe
            ON pe.id = pc.person_id
         WHERE CONCAT_WS(
            \' \' ,
            f.id,
            pr.name,
            pe.display_name,
            pc.character_name,
            f.fitting_date,
            f.status,
            f.notes
         ) LIKE :search_pattern
         ORDER BY f.fitting_date DESC, f.id DESC
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'users') {
    $statement = $connection->prepare(
        'SELECT
            id,
            username,
            display_name,
            role,
            is_active,
            must_change_password,
            created_at
         FROM users
         WHERE CONCAT_WS(
            \' \' ,
            id,
            username,
            display_name,
            role
         ) LIKE :search_pattern
         ORDER BY is_active DESC, display_name, id
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
} elseif ($recordType === 'vocabulary') {
    foreach ($vocabularyConfigurations as $type => $configuration) {
        $displayOrderSelect = $configuration['has_display_order']
            ? 'v.display_order'
            : '0';
        $descriptionSelect = $configuration['has_description']
            ? 'v.description'
            : 'NULL';
        $usageSelect = $configuration['asset_column'] === null
            ? '(SELECT COUNT(*) FROM asset_tags AS atg WHERE atg.tag_id = v.id)'
            : '(SELECT COUNT(*) FROM assets AS a WHERE a.'
                . $configuration['asset_column'] . ' = v.id)';
        $statement = $connection->prepare(
            'SELECT
                v.id,
                v.name,
                v.is_active,
                ' . $displayOrderSelect . ' AS display_order,
                ' . $descriptionSelect . ' AS description,
                ' . $usageSelect . ' AS usage_count
             FROM ' . $configuration['table'] . ' AS v
             WHERE CONCAT_WS(\' \' , v.id, v.name) LIKE :search_pattern
             ORDER BY v.is_active DESC, '
                . ($configuration['has_display_order']
                    ? 'v.display_order, '
                    : '')
                . 'v.name, v.id
             LIMIT 100'
        );
        $statement->execute(['search_pattern' => $searchPattern]);
        foreach ($statement->fetchAll() as $record) {
            $record['vocabulary_type'] = $type;
            $record['vocabulary_label'] = $configuration['label'];
            $searchResults[] = $record;
        }
    }
} elseif ($recordType === 'history') {
    $statement = $connection->prepare(
        'SELECT
            ach.id,
            ach.record_type,
            ach.record_id,
            ach.record_label,
            ach.change_action,
            ach.previous_values,
            ach.new_values,
            ach.change_reason,
            ach.changed_at,
            u.display_name AS changed_by
         FROM administrative_change_history AS ach
         LEFT JOIN users AS u
            ON u.id = ach.changed_by_user_id
         WHERE CONCAT_WS(
            \' \' ,
            ach.id,
            ach.record_type,
            ach.record_id,
            ach.record_label,
            ach.change_reason,
            u.display_name
         ) LIKE :search_pattern
         ORDER BY ach.changed_at DESC, ach.id DESC
         LIMIT 100'
    );
    $statement->execute(['search_pattern' => $searchPattern]);
    $searchResults = $statement->fetchAll();
}

$selectedPerson = null;
$selectedPersonId = administratorPositiveInteger($_GET['person_id'] ?? null);
if ($selectedPersonId !== null) {
    $statement = $connection->prepare(
        'SELECT id, display_name, first_name, last_name, is_active
         FROM people
         WHERE id = :person_id
         LIMIT 1'
    );
    $statement->execute(['person_id' => $selectedPersonId]);
    $record = $statement->fetch();
    $selectedPerson = $record === false ? null : $record;
}

$selectedVocabulary = null;
$selectedVocabularyType = administratorText(
    $_GET,
    'vocabulary_type'
);
$selectedVocabularyId = administratorPositiveInteger(
    $_GET['record_id'] ?? null
);
$selectedVocabularyConfiguration =
    $vocabularyConfigurations[$selectedVocabularyType] ?? null;

if (
    $selectedVocabularyConfiguration !== null
    && $selectedVocabularyId !== null
) {
    $selectColumns = 'id, name, is_active';
    if ($selectedVocabularyConfiguration['has_display_order']) {
        $selectColumns .= ', display_order';
    }
    if ($selectedVocabularyConfiguration['has_description']) {
        $selectColumns .= ', description';
    }
    $statement = $connection->prepare(
        'SELECT ' . $selectColumns . '
         FROM ' . $selectedVocabularyConfiguration['table'] . '
         WHERE id = :record_id
         LIMIT 1'
    );
    $statement->execute(['record_id' => $selectedVocabularyId]);
    $record = $statement->fetch();
    $selectedVocabulary = $record === false ? null : $record;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data maintenance — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260904-8">
</head>
<body>
<main class="admin-maintenance-page">
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/productions.php">Productions</a>
        <a href="/checkout.php">Production checkout</a>
        <a href="/fittings.php">Fittings</a>
        <a href="/measurements.php">Measurements</a>
        <a href="/asset-review.php">Asset review</a>
        <a href="/vocabulary.php">Vocabulary</a>
        <a href="/users.php">Users</a>
        <a href="/admin.php" aria-current="page">Data maintenance</a>
        <a href="/change-password.php">Password</a>
    </nav>

    <div class="page-heading">
        <div>
            <h1>Administrator data maintenance</h1>
            <p>Search application records, review fixed integrity checks, and make audited corrections through validated forms.</p>
        </div>
        <div class="user-session">
            <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
            <form method="post" action="/">
                <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
            </form>
        </div>
    </div>

    <div class="admin-safety-note">
        <strong>Restricted maintenance:</strong>
        This page has no SQL entry box and cannot delete records. Most corrections open the normal Collection Steward workflow; direct corrections below are limited to people and controlled vocabulary.
    </div>

    <?php if ($notice !== null): ?>
        <div class="notice" role="status"><?= collectionStewardEscape($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The administrator correction was not saved.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="admin-integrity-section" aria-labelledby="integrity-title">
        <div class="section-heading">
            <div>
                <h2 id="integrity-title">Routine checks</h2>
                <p>These are fixed, read-only checks of application state.</p>
            </div>
        </div>
        <div class="admin-check-grid">
            <a href="/asset-review.php" class="admin-check-card">
                <strong><?= $issueCounts['asset_review'] ?></strong>
                <span>Assets awaiting review</span>
            </a>
            <a href="/vocabulary.php" class="admin-check-card">
                <strong><?= $issueCounts['vocabulary'] ?></strong>
                <span>Vocabulary suggestions</span>
            </a>
            <a href="/measurements.php" class="admin-check-card">
                <strong><?= $issueCounts['measurements'] ?></strong>
                <span>Measurement values flagged</span>
            </a>
            <a href="/fittings.php" class="admin-check-card">
                <strong><?= $issueCounts['fittings'] ?></strong>
                <span>Fitting results pending</span>
            </a>
            <div class="admin-check-card <?= $availabilityIssues === [] ? 'is-clear' : 'has-issue' ?>">
                <strong><?= count($availabilityIssues) ?></strong>
                <span>Asset/checkout mismatches</span>
            </div>
        </div>

        <?php if ($availabilityIssues !== []): ?>
            <details class="admin-integrity-details" open>
                <summary>Review asset/checkout mismatches</summary>
                <div class="table-scroll">
                    <table class="admin-result-table">
                        <thead><tr><th>Asset</th><th>Asset status</th><th>Collection</th><th>Active checkouts</th><th>Open</th></tr></thead>
                        <tbody>
                        <?php foreach ($availabilityIssues as $issue): ?>
                            <tr>
                                <th scope="row"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $issue['id'], $issue['name'])) ?></th>
                                <td><?= collectionStewardEscape(ucfirst(str_replace('_', ' ', $issue['availability_status']))) ?></td>
                                <td><?= collectionStewardEscape(ucfirst($issue['collection_status'])) ?></td>
                                <td><?= (int) $issue['active_checkout_count'] ?></td>
                                <td><a href="/?<?= $issue['collection_status'] === 'retired' ? 'retired_filter=1&amp;include_retired=1&amp;' : '' ?>asset_id=<?= (int) $issue['id'] ?>#asset-record">View asset</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <?php if ($selectedPerson !== null): ?>
        <section class="admin-correction-panel" id="person-correction" aria-labelledby="person-correction-title">
            <h2 id="person-correction-title">Correct person #<?= (int) $selectedPerson['id'] ?></h2>
            <p>This identity is reused by cast assignments, measurements, and fittings. Saving here corrects all displays without replacing those relationships.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="save_person">
                <input type="hidden" name="person_id" value="<?= (int) $selectedPerson['id'] ?>">
                <div class="admin-form-grid">
                    <div class="field">
                        <label for="person_display_name">Display name</label>
                        <input type="text" id="person_display_name" name="display_name" maxlength="150" value="<?= collectionStewardEscape($selectedPerson['display_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="person_first_name">First name</label>
                        <input type="text" id="person_first_name" name="first_name" maxlength="100" value="<?= collectionStewardEscape($selectedPerson['first_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="person_last_name">Last name</label>
                        <input type="text" id="person_last_name" name="last_name" maxlength="100" value="<?= collectionStewardEscape($selectedPerson['last_name']) ?>">
                    </div>
                </div>
                <label class="confirmation-choice">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $selectedPerson['is_active'] === 1 ? 'checked' : '' ?>>
                    Active person
                </label>
                <div class="field">
                    <label for="person_change_reason">Reason for correction</label>
                    <textarea id="person_change_reason" name="change_reason" maxlength="1000" required></textarea>
                    <span class="help">Required. This explanation is stored in append-only administrator history.</span>
                </div>
                <button type="submit">Save audited person correction</button>
                <a class="button secondary" href="/admin.php?record_type=people">Close correction form</a>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($selectedVocabulary !== null && $selectedVocabularyConfiguration !== null): ?>
        <section class="admin-correction-panel" id="vocabulary-correction" aria-labelledby="vocabulary-correction-title">
            <h2 id="vocabulary-correction-title">Correct <?= collectionStewardEscape($selectedVocabularyConfiguration['label']) ?> #<?= (int) $selectedVocabulary['id'] ?></h2>
            <p>Inactive options remain attached to historical records but are no longer offered for new entry. Renaming a structured asset option refreshes names of the assets that use it.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="save_vocabulary">
                <input type="hidden" name="vocabulary_type" value="<?= collectionStewardEscape($selectedVocabularyType) ?>">
                <input type="hidden" name="record_id" value="<?= (int) $selectedVocabulary['id'] ?>">
                <div class="admin-form-grid">
                    <div class="field">
                        <label for="vocabulary_name">Approved name</label>
                        <input type="text" id="vocabulary_name" name="name" maxlength="100" value="<?= collectionStewardEscape($selectedVocabulary['name']) ?>" required>
                    </div>
                    <?php if ($selectedVocabularyConfiguration['has_display_order']): ?>
                        <div class="field">
                            <label for="vocabulary_display_order">Display order</label>
                            <input type="number" id="vocabulary_display_order" name="display_order" min="0" max="65535" value="<?= (int) $selectedVocabulary['display_order'] ?>" required>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($selectedVocabularyConfiguration['has_description']): ?>
                    <div class="field">
                        <label for="vocabulary_description">Description</label>
                        <textarea id="vocabulary_description" name="description" maxlength="5000"><?= collectionStewardEscape($selectedVocabulary['description']) ?></textarea>
                    </div>
                <?php endif; ?>
                <label class="confirmation-choice">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $selectedVocabulary['is_active'] === 1 ? 'checked' : '' ?>>
                    Active option
                </label>
                <div class="field">
                    <label for="vocabulary_change_reason">Reason for correction</label>
                    <textarea id="vocabulary_change_reason" name="change_reason" maxlength="1000" required></textarea>
                    <span class="help">Required. This explanation is stored in append-only administrator history.</span>
                </div>
                <button type="submit">Save audited vocabulary correction</button>
                <a class="button secondary" href="/admin.php?record_type=vocabulary">Close correction form</a>
            </form>
        </section>
    <?php endif; ?>

    <section class="admin-search-section" aria-labelledby="record-search-title">
        <h2 id="record-search-title">Search application records</h2>
        <form method="get" class="admin-search-form">
            <div class="field">
                <label for="record_type">Record type</label>
                <select id="record_type" name="record_type">
                    <?php foreach ($recordTypes as $typeValue => $typeLabel): ?>
                        <option value="<?= collectionStewardEscape($typeValue) ?>" <?= $recordType === $typeValue ? 'selected' : '' ?>><?= collectionStewardEscape($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="record_query">Name, ID, status, or related text</label>
                <input type="search" id="record_query" name="q" maxlength="150" value="<?= collectionStewardEscape($searchQuery) ?>">
            </div>
            <button type="submit">Search records</button>
        </form>

        <p><strong><?= count($searchResults) ?></strong> result<?= count($searchResults) === 1 ? '' : 's' ?> shown<?= count($searchResults) === 100 ? ' (first 100)' : '' ?>.</p>

        <div class="table-scroll">
            <?php if ($recordType === 'assets'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Asset</th><th>Type/location</th><th>Status</th><th>Review</th><th>Actions</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $row['id'], $row['name'])) ?></th>
                        <td><?= collectionStewardEscape($row['asset_type'] ?: 'Type not recorded') ?><br><small><?= collectionStewardEscape($row['storage_location'] ?: 'Location not recorded') ?></small></td>
                        <td><?= collectionStewardEscape(ucfirst(str_replace('_', ' ', $row['availability_status']))) ?> · <?= collectionStewardEscape(ucfirst($row['collection_status'])) ?></td>
                        <td><?= collectionStewardEscape(ucfirst(str_replace('_', ' ', $row['asset_review_status']))) ?></td>
                        <td><a href="/?<?= $row['collection_status'] === 'retired' ? 'retired_filter=1&amp;include_retired=1&amp;' : '' ?>asset_id=<?= (int) $row['id'] ?>#asset-record">View and open correction options</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'people'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Person</th><th>Stored names</th><th>Related records</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">#<?= (int) $row['id'] ?> — <?= collectionStewardEscape($row['display_name']) ?></th>
                        <td><?= collectionStewardEscape($row['first_name'] ?: '—') ?> / <?= collectionStewardEscape($row['last_name'] ?: '—') ?></td>
                        <td><?= (int) $row['cast_count'] ?> cast · <?= (int) $row['measurement_count'] ?> measurements · <?= (int) $row['fitting_count'] ?> fittings</td>
                        <td><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td><a href="/admin.php?record_type=people&amp;q=<?= rawurlencode($searchQuery) ?>&amp;person_id=<?= (int) $row['id'] ?>#person-correction">Correct</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'productions'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Production</th><th>Venue/year</th><th>Dates</th><th>Status/cast</th><th>Action</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">#<?= (int) $row['id'] ?> — <?= collectionStewardEscape($row['name']) ?></th>
                        <td><?= collectionStewardEscape($row['venue_name'] ?: 'No venue') ?> · <?= collectionStewardEscape((string) ($row['production_year'] ?: 'No year')) ?></td>
                        <td><?= collectionStewardEscape($row['opening_date'] ?: '—') ?> to <?= collectionStewardEscape($row['closing_date'] ?: '—') ?></td>
                        <td><?= collectionStewardEscape(ucfirst($row['status'])) ?> · <?= (int) $row['cast_count'] ?> cast</td>
                        <td><a href="/productions.php?production_id=<?= (int) $row['id'] ?>">Open normal editor</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'measurements'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Session</th><th>Production/date</th><th>Status</th><th>Values</th><th>Action</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">#<?= (int) $row['id'] ?> — <?= collectionStewardEscape($row['actor_name']) ?></th>
                        <td><?= collectionStewardEscape($row['production_name'] ?: 'No production') ?> · <?= collectionStewardEscape($row['measured_on']) ?></td>
                        <td><?= collectionStewardEscape(ucfirst(str_replace('_', ' ', $row['review_status']))) ?></td>
                        <td><?= (int) $row['value_count'] ?> total · <?= (int) $row['flagged_count'] ?> flagged</td>
                        <td><a href="/measurements.php?view=expanded&amp;session_id=<?= (int) $row['id'] ?>">Open normal editor</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'checkouts'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Checkout/asset</th><th>Production</th><th>Actor/character</th><th>Status/date</th><th>Actions</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">Checkout #<?= (int) $row['id'] ?><br><small><?= collectionStewardEscape(collectionStewardAssetLabel((int) $row['asset_id'], $row['asset_name'])) ?></small></th>
                        <td><?= collectionStewardEscape($row['production_name']) ?></td>
                        <td><?= collectionStewardEscape($row['actor_name']) ?> — <?= collectionStewardEscape($row['character_name']) ?></td>
                        <td><?= collectionStewardEscape(ucfirst($row['status'])) ?> · <?= collectionStewardEscape($row['checked_out_at']) ?></td>
                        <td><a href="/?asset_id=<?= (int) $row['asset_id'] ?>#asset-record">Asset</a> · <a href="/checkout.php?production_id=<?= (int) $row['production_id'] ?>">Checkout workflow</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'fittings'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Fitting</th><th>Production</th><th>Actor/character</th><th>Results</th><th>Action</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">#<?= (int) $row['id'] ?> · <?= collectionStewardEscape($row['fitting_date']) ?> · <?= collectionStewardEscape(ucfirst($row['status'])) ?></th>
                        <td><?= collectionStewardEscape($row['production_name']) ?></td>
                        <td><?= collectionStewardEscape($row['actor_name']) ?> — <?= collectionStewardEscape($row['character_name']) ?></td>
                        <td><?= (int) $row['selected_count'] ?> selected of <?= (int) $row['candidate_count'] ?></td>
                        <td><a href="/fittings.php?production_id=<?= (int) $row['production_id'] ?>&amp;fitting_id=<?= (int) $row['id'] ?>">Open fitting</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'users'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Account</th><th>Username</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">#<?= (int) $row['id'] ?> — <?= collectionStewardEscape($row['display_name']) ?></th>
                        <td><?= collectionStewardEscape($row['username']) ?></td>
                        <td><?= collectionStewardEscape(ucfirst($row['role'])) ?></td>
                        <td><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?><?= (int) $row['must_change_password'] === 1 ? ' · Password change required' : '' ?></td>
                        <td><a href="/users.php">Open normal editor</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php elseif ($recordType === 'vocabulary'): ?>
                <table class="admin-result-table">
                    <thead><tr><th>Vocabulary</th><th>Approved name</th><th>Order</th><th>Usage/status</th><th>Action</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row"><?= collectionStewardEscape($row['vocabulary_label']) ?> #<?= (int) $row['id'] ?></th>
                        <td><?= collectionStewardEscape($row['name']) ?></td>
                        <td><?= $vocabularyConfigurations[$row['vocabulary_type']]['has_display_order'] ? (int) $row['display_order'] : '—' ?></td>
                        <td><?= (int) $row['usage_count'] ?> assets · <?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td><a href="/admin.php?record_type=vocabulary&amp;q=<?= rawurlencode($searchQuery) ?>&amp;vocabulary_type=<?= collectionStewardEscape($row['vocabulary_type']) ?>&amp;record_id=<?= (int) $row['id'] ?>#vocabulary-correction">Correct</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php else: ?>
                <table class="admin-result-table">
                    <thead><tr><th>Change</th><th>Record</th><th>Reason</th><th>Changed by</th><th>Before/after</th></tr></thead>
                    <tbody><?php foreach ($searchResults as $row): ?><tr>
                        <th scope="row">#<?= (int) $row['id'] ?> · <?= collectionStewardEscape($row['changed_at']) ?></th>
                        <td><?= collectionStewardEscape($row['record_label']) ?><br><small><?= collectionStewardEscape($row['record_type']) ?> #<?= (int) $row['record_id'] ?></small></td>
                        <td><?= collectionStewardEscape($row['change_reason']) ?></td>
                        <td><?= collectionStewardEscape($row['changed_by'] ?: 'Earlier administrator') ?></td>
                        <td><details><summary>Show values</summary><p><strong>Before:</strong> <code><?= collectionStewardEscape($row['previous_values']) ?></code></p><p><strong>After:</strong> <code><?= collectionStewardEscape($row['new_values']) ?></code></p></details></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
