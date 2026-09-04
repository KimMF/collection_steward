<?php

declare(strict_types=1);

/**
 * Production pull lists and simple fitting results.
 *
 * Public entry point: /fittings.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability($connection, 'checkout');
$csrfToken = collectionStewardCsrfToken();

function fittingPositiveInteger(mixed $value): ?int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);

    return is_int($validated) && $validated > 0
        ? $validated
        : null;
}

function fittingText(array $source, string $key): string
{
    return is_string($source[$key] ?? null)
        ? trim($source[$key])
        : '';
}

function fittingDateIsValid(string $date): bool
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

function fittingProductionLabel(array $production): string
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

function fittingOutcomeLabel(string $outcome): string
{
    return match ($outcome) {
        'pending' => 'Awaiting result',
        'not_selected' => 'Not selected for wear',
        'selected_for_wear' => 'Selected for wear',
        default => ucfirst(str_replace('_', ' ', $outcome)),
    };
}

function fittingLockedRecord(PDO $connection, int $fittingId): ?array
{
    $statement = $connection->prepare(
        'SELECT
            f.id,
            f.production_cast_id,
            f.production_measurement_session_id,
            f.fitting_date,
            f.status,
            f.notes,
            pc.production_id,
            pc.person_id,
            pc.is_active AS cast_is_active,
            pr.status AS production_status
         FROM fittings AS f
         JOIN production_cast AS pc
            ON pc.id = f.production_cast_id
         JOIN productions AS pr
            ON pr.id = pc.production_id
         WHERE f.id = :fitting_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['fitting_id' => $fittingId]);
    $fitting = $statement->fetch();

    return $fitting === false ? null : $fitting;
}

function fittingMeasurementSessionIsValid(
    PDO $connection,
    int $productionMeasurementSessionId,
    int $productionId,
    int $personId
): bool
{
    $statement = $connection->prepare(
        'SELECT pms.id
         FROM production_measurement_sessions AS pms
         JOIN measurement_sessions AS ms
            ON ms.production_measurement_session_id = pms.id
         WHERE pms.id = :production_measurement_session_id
           AND pms.production_id = :production_id
           AND ms.person_id = :person_id
         LIMIT 1'
    );
    $statement->execute([
        'production_measurement_session_id' =>
            $productionMeasurementSessionId,
        'production_id' => $productionId,
        'person_id' => $personId,
    ]);

    return $statement->fetchColumn() !== false;
}

$productionStatement = $connection->query(
    'SELECT
        pr.id,
        pr.name,
        pr.production_year,
        pr.opening_date,
        pr.status,
        ve.name AS venue_name
     FROM productions AS pr
     LEFT JOIN venues AS ve
        ON ve.id = pr.venue_id
     ORDER BY
        CASE pr.status
            WHEN \'active\' THEN 0
            WHEN \'planned\' THEN 1
            WHEN \'completed\' THEN 2
            ELSE 3
        END,
        COALESCE(pr.production_year, YEAR(pr.opening_date)) DESC,
        pr.name,
        pr.id DESC'
);
$productions = $productionStatement->fetchAll();

$productionId = fittingPositiveInteger($_GET['production_id'] ?? null);
$requestedFittingId = fittingPositiveInteger($_GET['fitting_id'] ?? null);

if ($productionId !== null) {
    $productionExists = false;
    foreach ($productions as $production) {
        if ((int) $production['id'] === $productionId) {
            $productionExists = true;
            break;
        }
    }

    if (!$productionExists) {
        $productionId = null;
    }
}

if ($productionId === null && $productions !== []) {
    $productionId = (int) $productions[0]['id'];
}

$errors = [];
$notice = null;
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = fittingText($_POST, 'action');

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'create_fitting') {
                $postedProductionId = fittingPositiveInteger(
                    $_POST['production_id'] ?? null
                );
                $productionCastId = fittingPositiveInteger(
                    $_POST['production_cast_id'] ?? null
                );
                $fittingDate = fittingText($_POST, 'fitting_date');
                $notes = fittingText($_POST, 'notes');

                if ($postedProductionId === null || $productionCastId === null) {
                    throw new DomainException(
                        'Choose a production and an actor/character.'
                    );
                }

                if (!fittingDateIsValid($fittingDate)) {
                    throw new DomainException('Enter a valid fitting date.');
                }

                if (strlen($notes) > 5000) {
                    throw new DomainException(
                        'Fitting notes must be 5,000 characters or fewer.'
                    );
                }

                $castStatement = $connection->prepare(
                    'SELECT pc.id
                     FROM production_cast AS pc
                     JOIN productions AS pr
                        ON pr.id = pc.production_id
                     WHERE pc.id = :production_cast_id
                       AND pc.production_id = :production_id
                       AND pc.is_active = 1
                       AND pr.status IN (\'planned\', \'active\')
                     LIMIT 1'
                );
                $castStatement->execute([
                    'production_cast_id' => $productionCastId,
                    'production_id' => $postedProductionId,
                ]);

                if ($castStatement->fetchColumn() === false) {
                    throw new DomainException(
                        'New fittings require an active cast member in a Planned or Active production.'
                    );
                }

                $insertStatement = $connection->prepare(
                    'INSERT INTO fittings (
                        production_cast_id,
                        fitting_date,
                        status,
                        notes,
                        created_by_user_id
                     ) VALUES (
                        :production_cast_id,
                        :fitting_date,
                        \'planned\',
                        :notes,
                        :created_by_user_id
                     )'
                );
                $insertStatement->execute([
                    'production_cast_id' => $productionCastId,
                    'fitting_date' => $fittingDate,
                    'notes' => $notes !== '' ? $notes : null,
                    'created_by_user_id' => (int) $currentUser['id'],
                ]);

                $requestedFittingId = (int) $connection->lastInsertId();
                $productionId = $postedProductionId;

                header(
                    'Location: /fittings.php?production_id='
                    . $productionId
                    . '&fitting_id='
                    . $requestedFittingId
                    . '&fitting_created=1'
                );
                exit;
            }

            if ($action === 'save_fitting_details') {
                $fittingId = fittingPositiveInteger(
                    $_POST['fitting_id'] ?? null
                );
                $fittingDate = fittingText($_POST, 'fitting_date');
                $measurementSessionId = fittingPositiveInteger(
                    $_POST['production_measurement_session_id'] ?? null
                );
                $notes = fittingText($_POST, 'notes');

                if ($fittingId === null) {
                    throw new DomainException('Choose a valid fitting.');
                }

                if (!fittingDateIsValid($fittingDate)) {
                    throw new DomainException('Enter a valid fitting date.');
                }

                if (strlen($notes) > 5000) {
                    throw new DomainException(
                        'Fitting notes must be 5,000 characters or fewer.'
                    );
                }

                $connection->beginTransaction();
                $fitting = fittingLockedRecord($connection, $fittingId);

                if ($fitting === null) {
                    throw new DomainException('That fitting was not found.');
                }

                if ($fitting['status'] !== 'planned') {
                    throw new DomainException(
                        'A completed fitting cannot be changed.'
                    );
                }

                if (
                    $measurementSessionId !== null
                    && !fittingMeasurementSessionIsValid(
                        $connection,
                        $measurementSessionId,
                        (int) $fitting['production_id'],
                        (int) $fitting['person_id']
                    )
                ) {
                    throw new DomainException(
                        'Choose a production measurement session that includes this actor.'
                    );
                }

                $updateStatement = $connection->prepare(
                    'UPDATE fittings
                     SET fitting_date = :fitting_date,
                         production_measurement_session_id =
                            :production_measurement_session_id,
                         notes = :notes
                     WHERE id = :fitting_id'
                );
                $updateStatement->execute([
                    'fitting_date' => $fittingDate,
                    'production_measurement_session_id' =>
                        $measurementSessionId,
                    'notes' => $notes !== '' ? $notes : null,
                    'fitting_id' => $fittingId,
                ]);
                $connection->commit();

                header(
                    'Location: /fittings.php?production_id='
                    . (int) $fitting['production_id']
                    . '&fitting_id='
                    . $fittingId
                    . '&details_saved=1'
                );
                exit;
            }

            if ($action === 'add_candidate') {
                $fittingId = fittingPositiveInteger(
                    $_POST['fitting_id'] ?? null
                );
                $assetId = fittingPositiveInteger($_POST['asset_id'] ?? null);

                if ($fittingId === null || $assetId === null) {
                    throw new DomainException(
                        'Choose a fitting and an available asset.'
                    );
                }

                $connection->beginTransaction();
                $fitting = fittingLockedRecord($connection, $fittingId);

                if ($fitting === null) {
                    throw new DomainException('That fitting was not found.');
                }

                if ($fitting['status'] !== 'planned') {
                    throw new DomainException(
                        'Candidates cannot be added to a completed fitting.'
                    );
                }

                if (!in_array(
                    $fitting['production_status'],
                    ['planned', 'active'],
                    true
                )) {
                    throw new DomainException(
                        'Candidates can be added only while the production is Planned or Active.'
                    );
                }

                $assetStatement = $connection->prepare(
                    'SELECT id
                     FROM assets AS a
                     WHERE a.id = :asset_id
                       AND a.collection_status = \'active\'
                       AND a.availability_status = \'available\'
                       AND NOT EXISTS (
                            SELECT 1
                            FROM asset_checkouts AS ac
                            WHERE ac.asset_id = a.id
                              AND ac.status = \'active\'
                       )
                     LIMIT 1
                     FOR UPDATE'
                );
                $assetStatement->execute(['asset_id' => $assetId]);

                if ($assetStatement->fetchColumn() === false) {
                    throw new DomainException(
                        'That asset is retired, checked out, or otherwise unavailable.'
                    );
                }

                $insertStatement = $connection->prepare(
                    'INSERT INTO fitting_assets (
                        fitting_id,
                        asset_id,
                        outcome,
                        added_by_user_id
                     ) VALUES (
                        :fitting_id,
                        :asset_id,
                        \'pending\',
                        :added_by_user_id
                     )'
                );

                try {
                    $insertStatement->execute([
                        'fitting_id' => $fittingId,
                        'asset_id' => $assetId,
                        'added_by_user_id' => (int) $currentUser['id'],
                    ]);
                } catch (PDOException $error) {
                    if ((string) $error->getCode() === '23000') {
                        throw new DomainException(
                            'That asset is already on this pull list.'
                        );
                    }

                    throw $error;
                }

                $connection->commit();

                header(
                    'Location: /fittings.php?production_id='
                    . (int) $fitting['production_id']
                    . '&fitting_id='
                    . $fittingId
                    . '&candidate_added=1'
                );
                exit;
            }

            if ($action === 'save_candidate_result') {
                $fittingId = fittingPositiveInteger(
                    $_POST['fitting_id'] ?? null
                );
                $fittingAssetId = fittingPositiveInteger(
                    $_POST['fitting_asset_id'] ?? null
                );
                $outcome = fittingText($_POST, 'outcome');
                $fittingNote = fittingText($_POST, 'fitting_note');
                $newTagName = fittingText($_POST, 'new_tag_name');
                $postedTagIds = is_array($_POST['tag_ids'] ?? null)
                    ? $_POST['tag_ids']
                    : [];

                if ($fittingId === null || $fittingAssetId === null) {
                    throw new DomainException(
                        'Choose a valid fitting candidate.'
                    );
                }

                if (!in_array(
                    $outcome,
                    ['not_selected', 'selected_for_wear'],
                    true
                )) {
                    throw new DomainException(
                        'Choose whether the asset was selected for wear.'
                    );
                }

                if (strlen($fittingNote) > 5000) {
                    throw new DomainException(
                        'The fitting-tag note must be 5,000 characters or fewer.'
                    );
                }

                if (strlen($newTagName) > 100) {
                    throw new DomainException(
                        'A new result tag must be 100 characters or fewer.'
                    );
                }

                $tagIds = [];
                foreach ($postedTagIds as $postedTagId) {
                    $tagId = fittingPositiveInteger($postedTagId);
                    if ($tagId === null) {
                        throw new DomainException(
                            'Choose valid fitting result tags.'
                        );
                    }
                    $tagIds[$tagId] = $tagId;
                }

                $connection->beginTransaction();
                $candidateStatement = $connection->prepare(
                    'SELECT
                        fa.id,
                        fa.asset_id,
                        fa.outcome AS current_outcome,
                        fa.asset_checkout_id,
                        f.status AS fitting_status,
                        pc.id AS production_cast_id,
                        pc.production_id,
                        pr.status AS production_status,
                        a.collection_status,
                        a.availability_status
                     FROM fitting_assets AS fa
                     JOIN fittings AS f
                        ON f.id = fa.fitting_id
                     JOIN production_cast AS pc
                        ON pc.id = f.production_cast_id
                     JOIN productions AS pr
                        ON pr.id = pc.production_id
                     JOIN assets AS a
                        ON a.id = fa.asset_id
                     WHERE fa.id = :fitting_asset_id
                       AND fa.fitting_id = :fitting_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $candidateStatement->execute([
                    'fitting_asset_id' => $fittingAssetId,
                    'fitting_id' => $fittingId,
                ]);
                $candidate = $candidateStatement->fetch();

                if ($candidate === false) {
                    throw new DomainException(
                        'That fitting candidate was not found.'
                    );
                }

                if ($candidate['fitting_status'] !== 'planned') {
                    throw new DomainException(
                        'Results cannot be changed after the fitting is completed.'
                    );
                }

                if ($tagIds !== []) {
                    $tagParameters = [];
                    $tagPlaceholders = [];
                    foreach (array_values($tagIds) as $index => $tagId) {
                        $name = 'tag_' . $index;
                        $tagPlaceholders[] = ':' . $name;
                        $tagParameters[$name] = $tagId;
                    }

                    $tagStatement = $connection->prepare(
                        'SELECT id
                         FROM tags
                         WHERE is_active = 1
                           AND id IN ('
                        . implode(', ', $tagPlaceholders)
                        . ')'
                    );
                    $tagStatement->execute($tagParameters);
                    $validTagIds = array_map(
                        'intval',
                        array_column($tagStatement->fetchAll(), 'id')
                    );

                    if (count($validTagIds) !== count($tagIds)) {
                        throw new DomainException(
                            'One of the selected result tags is no longer active.'
                        );
                    }
                }

                if ($newTagName !== '') {
                    $existingTagStatement = $connection->prepare(
                        'SELECT id, is_active
                         FROM tags
                         WHERE name = :name
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $existingTagStatement->execute(['name' => $newTagName]);
                    $existingTag = $existingTagStatement->fetch();

                    if ($existingTag === false) {
                        $insertTagStatement = $connection->prepare(
                            'INSERT INTO tags (name, description)
                             VALUES (
                                :name,
                                \'Added as a fitting result tag.\'
                             )'
                        );
                        $insertTagStatement->execute(['name' => $newTagName]);
                        $newTagId = (int) $connection->lastInsertId();
                        $tagIds[$newTagId] = $newTagId;
                    } elseif ((int) $existingTag['is_active'] !== 1) {
                        throw new DomainException(
                            'That tag already exists but is inactive. Reactivate it in Vocabulary before using it.'
                        );
                    } else {
                        $tagIds[(int) $existingTag['id']] =
                            (int) $existingTag['id'];
                    }
                }

                $assetCheckoutId = $candidate['asset_checkout_id'] !== null
                    ? (int) $candidate['asset_checkout_id']
                    : null;

                if ($outcome === 'selected_for_wear') {
                    if (!in_array(
                        $candidate['production_status'],
                        ['planned', 'active'],
                        true
                    )) {
                        throw new DomainException(
                            'An asset can be selected for wear only while the production is Planned or Active.'
                        );
                    }

                    if ($candidate['collection_status'] !== 'active') {
                        throw new DomainException(
                            'A retired asset cannot be selected for wear.'
                        );
                    }

                    $activeCheckoutStatement = $connection->prepare(
                        'SELECT id, production_cast_id
                         FROM asset_checkouts
                         WHERE asset_id = :asset_id
                           AND status = \'active\'
                         ORDER BY id DESC
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $activeCheckoutStatement->execute([
                        'asset_id' => (int) $candidate['asset_id'],
                    ]);
                    $activeCheckout = $activeCheckoutStatement->fetch();

                    if ($activeCheckout !== false) {
                        if (
                            (int) $activeCheckout['production_cast_id']
                            !== (int) $candidate['production_cast_id']
                        ) {
                            throw new DomainException(
                                'That asset is already checked out to someone else.'
                            );
                        }

                        $assetCheckoutId = (int) $activeCheckout['id'];
                    } else {
                        if ($candidate['availability_status'] !== 'available') {
                            throw new DomainException(
                                'That asset is no longer available for checkout.'
                            );
                        }

                        $checkoutStatement = $connection->prepare(
                            'INSERT INTO asset_checkouts (
                                asset_id,
                                production_cast_id,
                                status,
                                notes,
                                checked_out_by_user_id
                             ) VALUES (
                                :asset_id,
                                :production_cast_id,
                                \'active\',
                                :notes,
                                :checked_out_by_user_id
                             )'
                        );
                        $checkoutStatement->execute([
                            'asset_id' => (int) $candidate['asset_id'],
                            'production_cast_id' =>
                                (int) $candidate['production_cast_id'],
                            'notes' => 'Selected for wear in fitting #'
                                . $fittingId . '.',
                            'checked_out_by_user_id' =>
                                (int) $currentUser['id'],
                        ]);
                        $assetCheckoutId =
                            (int) $connection->lastInsertId();

                        $assetStatement = $connection->prepare(
                            'UPDATE assets
                             SET availability_status = \'checked_out\',
                                 updated_by = :updated_by
                             WHERE id = :asset_id'
                        );
                        $assetStatement->execute([
                            'updated_by' => $currentUser['display_name'],
                            'asset_id' => (int) $candidate['asset_id'],
                        ]);
                    }
                } elseif (
                    $candidate['current_outcome'] === 'selected_for_wear'
                    && $assetCheckoutId !== null
                ) {
                    $linkedCheckoutStatement = $connection->prepare(
                        'SELECT id, asset_id, status
                         FROM asset_checkouts
                         WHERE id = :asset_checkout_id
                           AND asset_id = :asset_id
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $linkedCheckoutStatement->execute([
                        'asset_checkout_id' => $assetCheckoutId,
                        'asset_id' => (int) $candidate['asset_id'],
                    ]);
                    $linkedCheckout = $linkedCheckoutStatement->fetch();

                    if (
                        $linkedCheckout !== false
                        && $linkedCheckout['status'] === 'active'
                    ) {
                        $cancelStatement = $connection->prepare(
                            'UPDATE asset_checkouts
                             SET status = \'cancelled\',
                                 cancelled_by_user_id = :cancelled_by_user_id,
                                 cancelled_at = CURRENT_TIMESTAMP
                             WHERE id = :asset_checkout_id
                               AND status = \'active\''
                        );
                        $cancelStatement->execute([
                            'cancelled_by_user_id' =>
                                (int) $currentUser['id'],
                            'asset_checkout_id' => $assetCheckoutId,
                        ]);

                        $assetStatement = $connection->prepare(
                            'UPDATE assets
                             SET availability_status = \'available\',
                                 updated_by = :updated_by
                             WHERE id = :asset_id'
                        );
                        $assetStatement->execute([
                            'updated_by' => $currentUser['display_name'],
                            'asset_id' => (int) $candidate['asset_id'],
                        ]);
                    }
                }

                $updateCandidateStatement = $connection->prepare(
                    'UPDATE fitting_assets
                     SET outcome = :outcome,
                         fitting_note = :fitting_note,
                         asset_checkout_id = :asset_checkout_id,
                         recorded_by_user_id = :recorded_by_user_id,
                         recorded_at = CURRENT_TIMESTAMP
                     WHERE id = :fitting_asset_id
                       AND fitting_id = :fitting_id'
                );
                $updateCandidateStatement->execute([
                    'outcome' => $outcome,
                    'fitting_note' => $fittingNote !== ''
                        ? $fittingNote
                        : null,
                    'asset_checkout_id' => $assetCheckoutId,
                    'recorded_by_user_id' => (int) $currentUser['id'],
                    'fitting_asset_id' => $fittingAssetId,
                    'fitting_id' => $fittingId,
                ]);

                if ($tagIds !== []) {
                    $insertResultTagStatement = $connection->prepare(
                        'INSERT IGNORE INTO fitting_asset_result_tags (
                            fitting_asset_id,
                            tag_id,
                            recorded_by_user_id
                         ) VALUES (
                            :fitting_asset_id,
                            :tag_id,
                            :recorded_by_user_id
                         )'
                    );
                    $insertAssetTagStatement = $connection->prepare(
                        'INSERT IGNORE INTO asset_tags (asset_id, tag_id)
                         VALUES (:asset_id, :tag_id)'
                    );

                    foreach ($tagIds as $tagId) {
                        $insertResultTagStatement->execute([
                            'fitting_asset_id' => $fittingAssetId,
                            'tag_id' => $tagId,
                            'recorded_by_user_id' =>
                                (int) $currentUser['id'],
                        ]);
                        $insertAssetTagStatement->execute([
                            'asset_id' => (int) $candidate['asset_id'],
                            'tag_id' => $tagId,
                        ]);
                    }
                }

                $connection->commit();

                header(
                    'Location: /fittings.php?production_id='
                    . (int) $candidate['production_id']
                    . '&fitting_id='
                    . $fittingId
                    . '&result_saved=1#candidate-'
                    . $fittingAssetId
                );
                exit;
            }

            if ($action === 'complete_fitting') {
                $fittingId = fittingPositiveInteger(
                    $_POST['fitting_id'] ?? null
                );

                if ($fittingId === null) {
                    throw new DomainException('Choose a valid fitting.');
                }

                $connection->beginTransaction();
                $fitting = fittingLockedRecord($connection, $fittingId);

                if ($fitting === null) {
                    throw new DomainException('That fitting was not found.');
                }

                if ($fitting['status'] !== 'planned') {
                    throw new DomainException(
                        'That fitting is already completed.'
                    );
                }

                $countStatement = $connection->prepare(
                    'SELECT
                        COUNT(*) AS candidate_count,
                        COALESCE(SUM(outcome = \'pending\'), 0)
                            AS pending_count
                     FROM fitting_assets
                     WHERE fitting_id = :fitting_id'
                );
                $countStatement->execute(['fitting_id' => $fittingId]);
                $counts = $countStatement->fetch();

                if ((int) $counts['candidate_count'] === 0) {
                    throw new DomainException(
                        'Add at least one candidate asset before completing the fitting.'
                    );
                }

                if ((int) $counts['pending_count'] > 0) {
                    throw new DomainException(
                        'Record a result for every candidate before completing the fitting.'
                    );
                }

                $completeStatement = $connection->prepare(
                    'UPDATE fittings
                     SET status = \'completed\',
                         completed_by_user_id = :completed_by_user_id,
                         completed_at = CURRENT_TIMESTAMP
                     WHERE id = :fitting_id
                       AND status = \'planned\''
                );
                $completeStatement->execute([
                    'completed_by_user_id' => (int) $currentUser['id'],
                    'fitting_id' => $fittingId,
                ]);
                $connection->commit();

                header(
                    'Location: /fittings.php?production_id='
                    . (int) $fitting['production_id']
                    . '&fitting_id='
                    . $fittingId
                    . '&fitting_completed=1'
                );
                exit;
            }

            if ($action === 'reopen_fitting') {
                $fittingId = fittingPositiveInteger(
                    $_POST['fitting_id'] ?? null
                );

                if ($fittingId === null) {
                    throw new DomainException('Choose a valid fitting.');
                }

                $connection->beginTransaction();
                $fitting = fittingLockedRecord($connection, $fittingId);

                if ($fitting === null) {
                    throw new DomainException('That fitting was not found.');
                }

                if ($fitting['status'] !== 'completed') {
                    throw new DomainException(
                        'Only a completed fitting can be reopened.'
                    );
                }

                $reopenStatement = $connection->prepare(
                    'UPDATE fittings
                     SET status = \'planned\'
                     WHERE id = :fitting_id
                       AND status = \'completed\''
                );
                $reopenStatement->execute(['fitting_id' => $fittingId]);
                $connection->commit();

                header(
                    'Location: /fittings.php?production_id='
                    . (int) $fitting['production_id']
                    . '&fitting_id='
                    . $fittingId
                    . '&fitting_reopened=1'
                );
                exit;
            }

            throw new DomainException('Choose a valid fitting action.');
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = 'The fitting change could not be saved.';
        }
    }
}

if (isset($_GET['fitting_created'])) {
    $notice = 'The fitting was created. Add candidate assets to its pull list.';
} elseif (isset($_GET['details_saved'])) {
    $notice = 'The fitting details were saved.';
} elseif (isset($_GET['candidate_added'])) {
    $notice = 'The asset was added to the pull list.';
} elseif (isset($_GET['result_saved'])) {
    $notice = 'The fitting result was saved.';
} elseif (isset($_GET['fitting_completed'])) {
    $notice = 'The fitting was completed. Its results remain available as history.';
} elseif (isset($_GET['fitting_reopened'])) {
    $notice = 'The fitting was reopened. Existing candidates, results, notes, tags, and checkout links were preserved.';
}

$currentProduction = null;
foreach ($productions as $production) {
    if ((int) $production['id'] === $productionId) {
        $currentProduction = $production;
        break;
    }
}

$castMembers = [];
$fittingList = [];
$selectedFitting = null;
$measurementSessionChoices = [];
$availableAssets = [];
$fittingCandidates = [];
$fittingCandidateGroups = [
    'pending' => [],
    'selected_for_wear' => [],
    'not_selected' => [],
];
$resultTags = [];
$candidateResultTagIds = [];

if ($productionId !== null) {
    $castStatement = $connection->prepare(
        'SELECT
            pc.id,
            pc.person_id,
            pc.character_name,
            pe.display_name AS actor_name,
            pe.first_name,
            pe.last_name
         FROM production_cast AS pc
         JOIN people AS pe
            ON pe.id = pc.person_id
         WHERE pc.production_id = :production_id
           AND pc.is_active = 1
         ORDER BY pc.display_order, pc.id'
    );
    $castStatement->execute(['production_id' => $productionId]);
    $castMembers = $castStatement->fetchAll();

    $fittingListStatement = $connection->prepare(
        'SELECT
            f.id,
            f.fitting_date,
            f.status,
            pc.character_name,
            pe.display_name AS actor_name,
            COUNT(fa.id) AS candidate_count,
            COALESCE(SUM(fa.outcome <> \'pending\'), 0) AS result_count
         FROM fittings AS f
         JOIN production_cast AS pc
            ON pc.id = f.production_cast_id
         JOIN people AS pe
            ON pe.id = pc.person_id
         LEFT JOIN fitting_assets AS fa
            ON fa.fitting_id = f.id
         WHERE pc.production_id = :production_id
         GROUP BY
            f.id,
            f.fitting_date,
            f.status,
            pc.character_name,
            pe.display_name
         ORDER BY
            CASE f.status WHEN \'planned\' THEN 0 ELSE 1 END,
            f.fitting_date DESC,
            f.id DESC'
    );
    $fittingListStatement->execute(['production_id' => $productionId]);
    $fittingList = $fittingListStatement->fetchAll();

    if ($requestedFittingId === null && $fittingList !== []) {
        $requestedFittingId = (int) $fittingList[0]['id'];
    }

    if ($requestedFittingId !== null) {
        $selectedFittingStatement = $connection->prepare(
            'SELECT
                f.id,
                f.production_cast_id,
                f.production_measurement_session_id,
                f.fitting_date,
                f.status,
                f.notes,
                f.created_at,
                f.completed_at,
                pc.person_id,
                pc.character_name,
                pe.display_name AS actor_name,
                pms.session_name AS measurement_session_name,
                pms.measured_on AS measurement_session_date,
                ms.id AS actor_measurement_session_id,
                created_by.display_name AS created_by,
                completed_by.display_name AS completed_by
             FROM fittings AS f
             JOIN production_cast AS pc
                ON pc.id = f.production_cast_id
             JOIN people AS pe
                ON pe.id = pc.person_id
             LEFT JOIN production_measurement_sessions AS pms
                ON pms.id = f.production_measurement_session_id
             LEFT JOIN measurement_sessions AS ms
                ON ms.production_measurement_session_id = pms.id
               AND ms.person_id = pc.person_id
             LEFT JOIN users AS created_by
                ON created_by.id = f.created_by_user_id
             LEFT JOIN users AS completed_by
                ON completed_by.id = f.completed_by_user_id
             WHERE f.id = :fitting_id
               AND pc.production_id = :production_id
             LIMIT 1'
        );
        $selectedFittingStatement->execute([
            'fitting_id' => $requestedFittingId,
            'production_id' => $productionId,
        ]);
        $selectedFittingRecord = $selectedFittingStatement->fetch();
        $selectedFitting = $selectedFittingRecord === false
            ? null
            : $selectedFittingRecord;

        if ($selectedFitting === null) {
            $errors[] = 'That fitting was not found for this production.';
        }
    }
}

if ($selectedFitting !== null) {
    $measurementSessionStatement = $connection->prepare(
        'SELECT DISTINCT
            pms.id,
            pms.session_name,
            pms.measured_on,
            pms.status
         FROM production_measurement_sessions AS pms
         JOIN measurement_sessions AS ms
            ON ms.production_measurement_session_id = pms.id
         WHERE pms.production_id = :production_id
           AND ms.person_id = :person_id
         ORDER BY pms.measured_on DESC, pms.id DESC'
    );
    $measurementSessionStatement->execute([
        'production_id' => $productionId,
        'person_id' => (int) $selectedFitting['person_id'],
    ]);
    $measurementSessionChoices =
        $measurementSessionStatement->fetchAll();

    $availableAssetStatement = $connection->prepare(
        'SELECT
            a.id,
            a.name,
            a.size_description,
            a.storage_location,
            aty.name AS asset_type
         FROM assets AS a
         LEFT JOIN asset_types AS aty
            ON aty.id = a.asset_type_id
         WHERE a.collection_status = \'active\'
           AND a.availability_status = \'available\'
           AND NOT EXISTS (
                SELECT 1
                FROM asset_checkouts AS ac
                WHERE ac.asset_id = a.id
                  AND ac.status = \'active\'
           )
           AND NOT EXISTS (
                SELECT 1
                FROM fitting_assets AS existing_candidate
                WHERE existing_candidate.fitting_id = :fitting_id
                  AND existing_candidate.asset_id = a.id
           )
         ORDER BY COALESCE(aty.name, \'Unassigned\'), a.name, a.id'
    );
    $availableAssetStatement->execute([
        'fitting_id' => (int) $selectedFitting['id'],
    ]);
    $availableAssets = $availableAssetStatement->fetchAll();

    $candidateStatement = $connection->prepare(
        'SELECT
            fa.id AS fitting_asset_id,
            fa.asset_id,
            fa.outcome,
            fa.fitting_note,
            fa.asset_checkout_id,
            fa.added_at,
            fa.recorded_at,
            a.name AS asset_name,
            a.size_description,
            a.storage_location,
            a.availability_status,
            a.collection_status,
            aty.name AS asset_type,
            co.name AS color_name,
            ap.file_path,
            ac.status AS checkout_status,
            recorded_by.display_name AS recorded_by,
            (
                SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \')
                FROM fitting_asset_result_tags AS result_tag
                JOIN tags AS t
                   ON t.id = result_tag.tag_id
                WHERE result_tag.fitting_asset_id = fa.id
            ) AS result_tag_names
         FROM fitting_assets AS fa
         JOIN assets AS a
            ON a.id = fa.asset_id
         LEFT JOIN asset_types AS aty
            ON aty.id = a.asset_type_id
         LEFT JOIN color_options AS co
            ON co.id = a.primary_color_option_id
         LEFT JOIN asset_photos AS ap
            ON ap.asset_id = a.id
           AND ap.is_primary = 1
         LEFT JOIN asset_checkouts AS ac
            ON ac.id = fa.asset_checkout_id
         LEFT JOIN users AS recorded_by
            ON recorded_by.id = fa.recorded_by_user_id
         WHERE fa.fitting_id = :fitting_id
         ORDER BY
            CASE fa.outcome
                WHEN \'pending\' THEN 0
                WHEN \'selected_for_wear\' THEN 1
                WHEN \'not_selected\' THEN 2
                ELSE 3
            END,
            fa.added_at,
            fa.id'
    );
    $candidateStatement->execute([
        'fitting_id' => (int) $selectedFitting['id'],
    ]);
    $fittingCandidates = $candidateStatement->fetchAll();

    foreach ($fittingCandidates as $candidate) {
        if (isset($fittingCandidateGroups[$candidate['outcome']])) {
            $fittingCandidateGroups[$candidate['outcome']][] = $candidate;
        }
    }

    $resultTagStatement = $connection->query(
        'SELECT id, name
         FROM tags
         WHERE is_active = 1
         ORDER BY
            CASE name
                WHEN \'Needs alteration\' THEN 0
                WHEN \'Needs laundering\' THEN 1
                WHEN \'Needs repair\' THEN 2
                ELSE 3
            END,
            name'
    );
    $resultTags = $resultTagStatement->fetchAll();

    if ($fittingCandidates !== []) {
        $resultTagMapStatement = $connection->prepare(
            'SELECT result_tag.fitting_asset_id, result_tag.tag_id
             FROM fitting_asset_result_tags AS result_tag
             JOIN fitting_assets AS fa
                ON fa.id = result_tag.fitting_asset_id
             WHERE fa.fitting_id = :fitting_id'
        );
        $resultTagMapStatement->execute([
            'fitting_id' => (int) $selectedFitting['id'],
        ]);
        foreach ($resultTagMapStatement->fetchAll() as $resultTagRow) {
            $candidateResultTagIds[(int) $resultTagRow['fitting_asset_id']][
                (int) $resultTagRow['tag_id']
            ] = true;
        }
    }
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fittings — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260904-7">
</head>
<body>
<main class="fittings-page">
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_productions')): ?>
            <a href="/productions.php">Productions</a>
        <?php endif; ?>
        <a href="/checkout.php">Production checkout</a>
        <a href="/fittings.php" aria-current="page">Fittings</a>
        <?php if (collectionStewardUserCan($currentUser, 'measurements')): ?>
            <a href="/measurements.php">Measurements</a>
        <?php endif; ?>
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
            <h1>Fittings</h1>
            <p>Create a pull list, record what happened during the fitting, and preserve the result.</p>
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
            <strong>The fitting change was not saved.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($productions === []): ?>
        <div class="error">Create a production and cast before starting a fitting.</div>
    <?php else: ?>
        <form method="get" class="fitting-production-selector">
            <div class="field">
                <label for="production_id">Production</label>
                <select id="production_id" name="production_id" data-submit-on-change>
                    <?php foreach ($productions as $production): ?>
                        <option value="<?= (int) $production['id'] ?>" <?= (int) $production['id'] === $productionId ? 'selected' : '' ?>>
                            <?= collectionStewardEscape(fittingProductionLabel($production)) ?> — <?= collectionStewardEscape(ucfirst($production['status'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit">Choose production</button></noscript>
        </form>

        <div class="fitting-workspace">
            <aside class="fitting-browser" aria-label="Fittings for selected production">
                <h2><?= collectionStewardEscape($currentProduction['name'] ?? 'Production') ?></h2>

                <?php if ($fittingList === []): ?>
                    <p>No fittings have been recorded for this production.</p>
                <?php else: ?>
                    <div class="fitting-list">
                        <?php foreach ($fittingList as $fittingChoice): ?>
                            <a href="/fittings.php?production_id=<?= $productionId ?>&amp;fitting_id=<?= (int) $fittingChoice['id'] ?>" class="fitting-list-row <?= (int) $fittingChoice['id'] === $requestedFittingId ? 'is-current' : '' ?>">
                                <strong><?= collectionStewardEscape($fittingChoice['actor_name']) ?> — <?= collectionStewardEscape($fittingChoice['character_name']) ?></strong>
                                <span><?= collectionStewardEscape($fittingChoice['fitting_date']) ?> · <?= collectionStewardEscape(ucfirst($fittingChoice['status'])) ?></span>
                                <span><?= (int) $fittingChoice['result_count'] ?> of <?= (int) $fittingChoice['candidate_count'] ?> results</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (
                    $currentProduction !== null
                    && in_array($currentProduction['status'], ['planned', 'active'], true)
                ): ?>
                    <details class="new-fitting-panel" <?= $fittingList === [] ? 'open' : '' ?>>
                        <summary>Start a fitting</summary>
                        <?php if ($castMembers === []): ?>
                            <p>Add an active cast member on the Productions page first.</p>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="create_fitting">
                                <input type="hidden" name="production_id" value="<?= $productionId ?>">

                                <div class="field">
                                    <label for="new_production_cast_id">Actor and character</label>
                                    <select id="new_production_cast_id" name="production_cast_id" required>
                                        <option value="">Choose cast assignment</option>
                                        <?php foreach ($castMembers as $castMember): ?>
                                            <option value="<?= (int) $castMember['id'] ?>"><?= collectionStewardEscape($castMember['actor_name']) ?> — <?= collectionStewardEscape($castMember['character_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="new_fitting_date">Fitting date</label>
                                    <input type="date" id="new_fitting_date" name="fitting_date" value="<?= collectionStewardEscape($today) ?>" required>
                                </div>
                                <div class="field">
                                    <label for="new_fitting_notes">Overall notes</label>
                                    <textarea id="new_fitting_notes" name="notes" maxlength="5000"></textarea>
                                </div>
                                <button type="submit">Create fitting</button>
                            </form>
                        <?php endif; ?>
                    </details>
                <?php else: ?>
                    <p class="help">Historical fittings remain available, but new fittings require a Planned or Active production.</p>
                <?php endif; ?>
            </aside>

            <section class="fitting-editor">
                <?php if ($selectedFitting === null): ?>
                    <h2>No fitting selected</h2>
                    <p>Start a fitting or choose one from the list.</p>
                <?php else: ?>
                    <header class="fitting-header">
                        <div>
                            <p class="eyebrow">Fitting #<?= (int) $selectedFitting['id'] ?></p>
                            <h2><?= collectionStewardEscape($selectedFitting['actor_name']) ?> — <?= collectionStewardEscape($selectedFitting['character_name']) ?></h2>
                            <p><?= collectionStewardEscape($currentProduction['name']) ?> · <?= collectionStewardEscape($selectedFitting['fitting_date']) ?> · <strong><?= collectionStewardEscape(ucfirst($selectedFitting['status'])) ?></strong></p>
                        </div>
                        <div class="fitting-print-actions">
                            <button type="button" class="secondary fitting-print-button" onclick="printFitting(false)"><?= $selectedFitting['status'] === 'planned' ? 'Print pull list' : 'Print fitting record' ?></button>
                            <?php if ($fittingCandidateGroups['selected_for_wear'] !== []): ?>
                                <button type="button" class="secondary fitting-print-button" onclick="printFitting(true)">Print selected assets</button>
                            <?php else: ?>
                                <button type="button" class="secondary fitting-print-button" disabled title="No assets are currently selected for wear.">Print selected assets (none)</button>
                            <?php endif; ?>
                        </div>
                    </header>

                    <?php if ($selectedFitting['status'] === 'planned'): ?>
                        <details class="fitting-details-panel">
                            <summary>Edit date, measurement-session link, or notes</summary>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="save_fitting_details">
                                <input type="hidden" name="fitting_id" value="<?= (int) $selectedFitting['id'] ?>">

                                <div class="field">
                                    <label for="fitting_date">Fitting date</label>
                                    <input type="date" id="fitting_date" name="fitting_date" value="<?= collectionStewardEscape($selectedFitting['fitting_date']) ?>" required>
                                </div>
                                <div class="field">
                                    <label for="production_measurement_session_id">Production measurement session</label>
                                    <select id="production_measurement_session_id" name="production_measurement_session_id">
                                        <option value="">Not connected</option>
                                        <?php foreach ($measurementSessionChoices as $measurementSession): ?>
                                            <option value="<?= (int) $measurementSession['id'] ?>" <?= (int) $measurementSession['id'] === (int) $selectedFitting['production_measurement_session_id'] ? 'selected' : '' ?>><?= collectionStewardEscape($measurementSession['session_name']) ?> — <?= collectionStewardEscape($measurementSession['measured_on']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="help">Only sessions that include this actor appear.</span>
                                </div>
                                <div class="field">
                                    <label for="fitting_notes">Overall fitting notes</label>
                                    <textarea id="fitting_notes" name="notes" maxlength="5000"><?= collectionStewardEscape($selectedFitting['notes']) ?></textarea>
                                </div>
                                <button type="submit">Save fitting details</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <div class="fitting-completion-record">
                            <strong>Completed</strong>
                            by <?= collectionStewardEscape($selectedFitting['completed_by'] ?: 'an earlier user') ?>
                            on <?= collectionStewardEscape($selectedFitting['completed_at']) ?>
                        </div>
                        <form method="post" class="reopen-fitting-form" onsubmit="return confirm('Reopen this fitting for corrections? Existing candidates and results will be preserved.');">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="reopen_fitting">
                            <input type="hidden" name="fitting_id" value="<?= (int) $selectedFitting['id'] ?>">
                            <button type="submit" class="secondary">Reopen fitting</button>
                        </form>
                        <?php if (!empty($selectedFitting['notes'])): ?>
                            <p><strong>Overall notes:</strong> <?= collectionStewardEscape($selectedFitting['notes']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($selectedFitting['production_measurement_session_id'] !== null): ?>
                        <p class="fitting-measurement-link">
                            <strong>Measurement session:</strong>
                            <a href="/production-measurements.php?production_id=<?= $productionId ?>&amp;session_id=<?= (int) $selectedFitting['production_measurement_session_id'] ?>"><?= collectionStewardEscape($selectedFitting['measurement_session_name']) ?></a>
                            <?php if ($selectedFitting['actor_measurement_session_id'] !== null): ?>
                                · <a href="/measurements.php?view=expanded&amp;session_id=<?= (int) $selectedFitting['actor_measurement_session_id'] ?>">Open actor measurements</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($selectedFitting['status'] === 'planned'): ?>
                        <section class="add-fitting-candidate">
                            <h3>Add to the pull list</h3>
                            <?php if ($availableAssets === []): ?>
                                <p>No additional available assets were found.</p>
                            <?php else: ?>
                                <form method="post" class="fitting-add-form">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add_candidate">
                                    <input type="hidden" name="fitting_id" value="<?= (int) $selectedFitting['id'] ?>">
                                    <div class="field">
                                        <label for="asset_id">Available collection asset</label>
                                        <select id="asset_id" name="asset_id" required>
                                            <option value="">Choose an asset</option>
                                            <?php foreach ($availableAssets as $assetChoice): ?>
                                                <option value="<?= (int) $assetChoice['id'] ?>"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $assetChoice['id'], $assetChoice['name'])) ?><?= !empty($assetChoice['size_description']) ? ' · Size ' . collectionStewardEscape($assetChoice['size_description']) : '' ?><?= !empty($assetChoice['storage_location']) ? ' · ' . collectionStewardEscape($assetChoice['storage_location']) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="help">Add one candidate at a time. The asset remains available until it is selected for wear.</span>
                                    </div>
                                    <button type="submit">Add candidate</button>
                                </form>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <section class="fitting-pull-list">
                        <div class="section-heading">
                            <div>
                                <h3><?= $selectedFitting['status'] === 'planned' ? 'Candidate assets and results' : 'Fitting results' ?></h3>
                                <?php if ($selectedFitting['status'] === 'planned'): ?>
                                    <p class="help">Record the physical fitting-tag text and final result for every candidate.</p>
                                <?php else: ?>
                                    <p class="help">Selected assets and assets tried but not selected are separated below and retained as history.</p>
                                <?php endif; ?>
                            </div>
                            <strong><?= count($fittingCandidates) ?> record<?= count($fittingCandidates) === 1 ? '' : 's' ?></strong>
                        </div>

                        <?php if ($fittingCandidates === []): ?>
                            <p>No candidate assets have been recorded.</p>
                        <?php else: ?>
                            <?php
                            $candidateGroupOrder = $selectedFitting['status'] === 'planned'
                                ? ['pending', 'selected_for_wear', 'not_selected']
                                : ['selected_for_wear', 'not_selected'];
                            $candidateGroupLabels = [
                                'pending' => 'Awaiting fitting result',
                                'selected_for_wear' => 'Selected for wear',
                                'not_selected' => 'Not selected during fitting',
                            ];
                            ?>
                            <?php if (
                                $selectedFitting['status'] === 'completed'
                                && $fittingCandidateGroups['selected_for_wear'] === []
                            ): ?>
                                <p class="fitting-no-selection">No assets were selected for wear.</p>
                            <?php endif; ?>

                            <?php foreach ($candidateGroupOrder as $candidateGroupKey): ?>
                                <?php if ($fittingCandidateGroups[$candidateGroupKey] === []): ?>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <section class="fitting-candidate-group fitting-candidate-group-<?= collectionStewardEscape($candidateGroupKey) ?>">
                                    <div class="fitting-candidate-group-heading">
                                        <strong><?= collectionStewardEscape($candidateGroupLabels[$candidateGroupKey]) ?></strong>
                                        <span><?= count($fittingCandidateGroups[$candidateGroupKey]) ?></span>
                                    </div>
                                    <div class="fitting-candidate-list">
                                <?php foreach ($fittingCandidateGroups[$candidateGroupKey] as $candidate): ?>
                                    <?php
                                    $fittingAssetId = (int) $candidate['fitting_asset_id'];
                                    $recordedTagIds =
                                        $candidateResultTagIds[$fittingAssetId] ?? [];
                                    ?>
                                    <article id="candidate-<?= $fittingAssetId ?>" class="fitting-candidate <?= $candidate['outcome'] === 'pending' ? 'is-pending' : '' ?>">
                                        <div class="fitting-candidate-identification">
                                            <?php if (!empty($candidate['file_path'])): ?>
                                                <img src="<?= collectionStewardEscape($candidate['file_path']) ?>" alt="" class="fitting-candidate-photo">
                                            <?php else: ?>
                                                <div class="fitting-photo-placeholder">No photo</div>
                                            <?php endif; ?>
                                            <div>
                                                <p class="eyebrow"><?= collectionStewardEscape($candidate['asset_type'] ?: 'Unassigned type') ?></p>
                                                <h4><a href="/?asset_id=<?= (int) $candidate['asset_id'] ?>#asset-record"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $candidate['asset_id'], $candidate['asset_name'])) ?></a></h4>
                                                <dl class="fitting-asset-facts">
                                                    <div><dt>Size</dt><dd><?= collectionStewardEscape($candidate['size_description'] ?: 'Not recorded') ?></dd></div>
                                                    <div><dt>Color</dt><dd><?= collectionStewardEscape($candidate['color_name'] ?: 'Not recorded') ?></dd></div>
                                                    <div><dt>Location</dt><dd><?= collectionStewardEscape($candidate['storage_location'] ?: 'Not recorded') ?></dd></div>
                                                    <div><dt>Availability</dt><dd><?= collectionStewardEscape(ucfirst(str_replace('_', ' ', $candidate['availability_status']))) ?></dd></div>
                                                </dl>
                                            </div>
                                        </div>

                                        <div class="fitting-result-summary">
                                            <strong><?= collectionStewardEscape(fittingOutcomeLabel($candidate['outcome'])) ?></strong>
                                            <?php if (!empty($candidate['result_tag_names'])): ?>
                                                <span><?= collectionStewardEscape($candidate['result_tag_names']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($candidate['fitting_note'])): ?>
                                                <p><strong>Fitting-tag note:</strong> <?= collectionStewardEscape($candidate['fitting_note']) ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($candidate['recorded_at'])): ?>
                                                <small>Recorded by <?= collectionStewardEscape($candidate['recorded_by'] ?: 'an earlier user') ?> on <?= collectionStewardEscape($candidate['recorded_at']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($candidate['asset_checkout_id'] !== null): ?>
                                                <small>Checkout #<?= (int) $candidate['asset_checkout_id'] ?> · <?= collectionStewardEscape(ucfirst($candidate['checkout_status'] ?: 'unknown')) ?></small>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($selectedFitting['status'] === 'planned'): ?>
                                            <details class="fitting-result-form" <?= $candidate['outcome'] === 'pending' ? 'open' : '' ?>>
                                                <summary><?= $candidate['outcome'] === 'pending' ? 'Record result' : 'Update result or add tags' ?></summary>
                                                <form method="post" <?= $candidate['outcome'] === 'selected_for_wear' ? 'onsubmit="if (this.elements.outcome.value === \'not_selected\') { return confirm(\'Changing this result to Not selected will also cancel its active checkout. Continue?\'); }"' : '' ?>>
                                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="save_candidate_result">
                                                    <input type="hidden" name="fitting_id" value="<?= (int) $selectedFitting['id'] ?>">
                                                    <input type="hidden" name="fitting_asset_id" value="<?= $fittingAssetId ?>">

                                                    <div class="field">
                                                        <label for="outcome_<?= $fittingAssetId ?>">Wear decision</label>
                                                        <select id="outcome_<?= $fittingAssetId ?>" name="outcome" required>
                                                            <option value="">Choose result</option>
                                                            <option value="not_selected" <?= $candidate['outcome'] === 'not_selected' ? 'selected' : '' ?>>Not selected for wear</option>
                                                            <option value="selected_for_wear" <?= $candidate['outcome'] === 'selected_for_wear' ? 'selected' : '' ?>>Selected for wear and check out</option>
                                                        </select>
                                                    </div>

                                                    <div class="field">
                                                        <label for="fitting_note_<?= $fittingAssetId ?>">Text from the physical fitting tag</label>
                                                        <textarea id="fitting_note_<?= $fittingAssetId ?>" name="fitting_note" maxlength="5000"><?= collectionStewardEscape($candidate['fitting_note']) ?></textarea>
                                                    </div>

                                                    <fieldset class="fitting-result-tags">
                                                        <legend>Result or work tags</legend>
                                                        <p class="help">Recorded tags remain part of this fitting history and are also assigned to the asset.</p>
                                                        <div class="checkbox-grid">
                                                            <?php foreach ($resultTags as $tag): ?>
                                                                <?php $tagWasRecorded = isset($recordedTagIds[(int) $tag['id']]); ?>
                                                                <label>
                                                                    <input type="checkbox" name="tag_ids[]" value="<?= (int) $tag['id'] ?>" <?= $tagWasRecorded ? 'checked disabled' : '' ?>>
                                                                    <?= collectionStewardEscape($tag['name']) ?><?= $tagWasRecorded ? ' — recorded' : '' ?>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </fieldset>

                                                    <div class="field">
                                                        <label for="new_tag_name_<?= $fittingAssetId ?>">New result tag, if needed</label>
                                                        <input type="text" id="new_tag_name_<?= $fittingAssetId ?>" name="new_tag_name" maxlength="100" placeholder="For example: Hem too long">
                                                        <span class="help">A new tag is added to the controlled vocabulary and assigned to this asset.</span>
                                                    </div>

                                                    <button type="submit">Save fitting result</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                    <?php if ($selectedFitting['status'] === 'planned'): ?>
                        <section class="complete-fitting-panel">
                            <h3>Complete this fitting</h3>
                            <?php
                            $pendingCandidateCount = count(array_filter(
                                $fittingCandidates,
                                static fn (array $candidate): bool =>
                                    $candidate['outcome'] === 'pending'
                            ));
                            ?>
                            <?php if ($fittingCandidates === []): ?>
                                <p>Add at least one candidate asset first.</p>
                            <?php elseif ($pendingCandidateCount > 0): ?>
                                <p>Record the remaining <?= $pendingCandidateCount ?> result<?= $pendingCandidateCount === 1 ? '' : 's' ?> first.</p>
                            <?php else: ?>
                                <p>Completion keeps every candidate, result, fitting-tag note, tag, and checkout link as history. The fitting can be reopened later if a correction is needed.</p>
                                <form method="post" onsubmit="return confirm('Complete this fitting? Results will become read-only.');">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="complete_fitting">
                                    <input type="hidden" name="fitting_id" value="<?= (int) $selectedFitting['id'] ?>">
                                    <button type="submit">Mark fitting complete</button>
                                </form>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</main>
<script>
function printFitting(selectedAssetsOnly) {
    document.body.classList.toggle(
        'print-selected-fitting-assets',
        selectedAssetsOnly
    );
    window.print();
}

window.addEventListener('afterprint', function () {
    document.body.classList.remove('print-selected-fitting-assets');
});

document.querySelectorAll('[data-submit-on-change]').forEach(function (input) {
    input.addEventListener('change', function () {
        input.form.requestSubmit();
    });
});
</script>
</body>
</html>
