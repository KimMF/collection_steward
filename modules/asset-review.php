<?php

declare(strict_types=1);

/**
 * Asset-review queue, condition review, and authorized asset correction.
 *
 * Public entry point: /asset-review.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'manage_assets'
);
$csrfToken = collectionStewardCsrfToken();

function assetReviewText(array $source, string $key): string
{
    return is_string($source[$key] ?? null)
        ? trim($source[$key])
        : '';
}

function assetReviewPositiveInteger(mixed $value): ?int
{
    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $validated === false ? null : (int) $validated;
}

function assetReviewDateIsValid(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function assetReviewLoadOptions(PDO $connection, string $table): array
{
    $allowedTables = [
        'asset_types',
        'wearer_options',
        'color_options',
        'size_options',
        'length_options',
    ];

    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported review option table.');
    }

    $statement = $connection->query(
        'SELECT id, name, is_active
         FROM ' . $table . '
         ORDER BY is_active DESC, display_order, name'
    );

    return $statement->fetchAll();
}

$assetTypes = assetReviewLoadOptions($connection, 'asset_types');
$wearerOptions = assetReviewLoadOptions($connection, 'wearer_options');
$colorOptions = assetReviewLoadOptions($connection, 'color_options');
$sizeOptions = assetReviewLoadOptions($connection, 'size_options');
$lengthOptions = assetReviewLoadOptions($connection, 'length_options');

$tagStatement = $connection->query(
    'SELECT id, name, is_active
     FROM tags
     ORDER BY is_active DESC, name'
);
$tagOptions = $tagStatement->fetchAll();

$optionLists = [
    'asset_type_id' => [
        'label' => 'Type',
        'options' => $assetTypes,
    ],
    'wearer_option_id' => [
        'label' => 'Wearer',
        'options' => $wearerOptions,
    ],
    'primary_color_option_id' => [
        'label' => 'Primary color',
        'options' => $colorOptions,
    ],
    'size_option_id' => [
        'label' => 'Standardized size',
        'options' => $sizeOptions,
    ],
    'length_option_id' => [
        'label' => 'Length',
        'options' => $lengthOptions,
    ],
];

$conditionFields = [
    'smell' => 'Smell',
    'stains' => 'Stains',
    'damage' => 'Damage',
    'wear' => 'Wear',
    'general_condition' => 'General condition',
];
$conditionResults = [
    'clear' => 'Clear',
    'issue_found' => 'Issue found',
    'not_assessed' => 'Not assessed',
];

$errors = [];
$reviewedAssetId = assetReviewPositiveInteger($_GET['reviewed'] ?? null);
$retiredAssetId = assetReviewPositiveInteger($_GET['retired'] ?? null);
$selectedAssetId = assetReviewPositiveInteger(
    $_POST['asset_id'] ?? $_GET['asset_id'] ?? null
);
$submittedValues = null;
$submittedTagIds = [];
$submittedConditions = [];
$submittedConditionNotes = [];
$submittedOverallNote = '';
$retirementDisposition = '';
$retirementDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$retirementNote = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'retire_asset'
) {
    $retirementDisposition = assetReviewText(
        $_POST,
        'retirement_disposition'
    );
    $retirementDate = assetReviewText($_POST, 'retirement_date');
    $retirementNote = assetReviewText($_POST, 'retirement_note');

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    if ($selectedAssetId === null) {
        $errors[] = 'Choose an asset from the review queue.';
    }

    if (($_POST['confirm_retirement'] ?? '') !== '1') {
        $errors[] = 'Confirm that this asset should be retired.';
    }

    if ($errors === [] && $selectedAssetId !== null) {
        try {
            collectionStewardRetireAsset(
                $connection,
                $selectedAssetId,
                $retirementDisposition,
                $retirementDate,
                $retirementNote,
                $currentUser
            );

            header(
                'Location: /asset-review.php?retired=' . $selectedAssetId
            );
            exit;
        } catch (DomainException $error) {
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            $errors[] = 'The asset could not be retired.';
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_asset_review'
) {
    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    if ($selectedAssetId === null) {
        $errors[] = 'Choose an asset from the review queue.';
    }

    $submittedValues = [
        'asset_type_id' => assetReviewText($_POST, 'asset_type_id'),
        'wearer_option_id' => assetReviewText($_POST, 'wearer_option_id'),
        'primary_color_option_id' => assetReviewText(
            $_POST,
            'primary_color_option_id'
        ),
        'size_option_id' => assetReviewText($_POST, 'size_option_id'),
        'length_option_id' => assetReviewText($_POST, 'length_option_id'),
        'exact_size_label' => assetReviewText($_POST, 'exact_size_label'),
        'storage_location' => assetReviewText($_POST, 'storage_location'),
        'received_date' => assetReviewText($_POST, 'received_date'),
        'acquisition_type' => assetReviewText($_POST, 'acquisition_type'),
        'description' => assetReviewText($_POST, 'description'),
        'notes' => assetReviewText($_POST, 'notes'),
    ];

    $selectedOptionIds = [];
    $selectedOptionNames = [];

    foreach ($optionLists as $fieldName => $configuration) {
        $submittedOptionId = $submittedValues[$fieldName] !== ''
            ? assetReviewPositiveInteger($submittedValues[$fieldName])
            : null;
        $matchedOption = null;

        if ($submittedOptionId !== null) {
            foreach ($configuration['options'] as $option) {
                if ((int) $option['id'] === $submittedOptionId) {
                    $matchedOption = $option;
                    break;
                }
            }
        }

        if ($submittedValues[$fieldName] !== '' && $matchedOption === null) {
            $errors[] = 'Choose a valid ' . strtolower($configuration['label']) . '.';
        }

        $selectedOptionIds[$fieldName] = $matchedOption !== null
            ? (int) $matchedOption['id']
            : null;
        $selectedOptionNames[$fieldName] = $matchedOption['name'] ?? null;
    }

    if (strlen($submittedValues['exact_size_label']) > 100) {
        $errors[] = 'The exact size label must be 100 characters or fewer.';
    }

    if (strlen($submittedValues['storage_location']) > 255) {
        $errors[] = 'The storage location must be 255 characters or fewer.';
    }

    if (strlen($submittedValues['acquisition_type']) > 50) {
        $errors[] = 'The acquisition type must be 50 characters or fewer.';
    }

    if (!assetReviewDateIsValid($submittedValues['received_date'])) {
        $errors[] = 'Enter a valid date received.';
    }

    if (strlen($submittedValues['description']) > 10000) {
        $errors[] = 'The description must be 10,000 characters or fewer.';
    }

    if (strlen($submittedValues['notes']) > 10000) {
        $errors[] = 'The notes must be 10,000 characters or fewer.';
    }

    $availableTagIds = array_map(
        static fn (array $tag): int => (int) $tag['id'],
        $tagOptions
    );
    $postedTagIds = is_array($_POST['tag_ids'] ?? null)
        ? $_POST['tag_ids']
        : [];

    foreach ($postedTagIds as $postedTagId) {
        $tagId = assetReviewPositiveInteger($postedTagId);

        if ($tagId !== null && in_array($tagId, $availableTagIds, true)) {
            $submittedTagIds[] = $tagId;
        }
    }

    $submittedTagIds = array_values(array_unique($submittedTagIds));

    foreach ($conditionFields as $fieldName => $label) {
        $result = assetReviewText($_POST, $fieldName . '_result');
        $note = assetReviewText($_POST, $fieldName . '_note');

        if (!isset($conditionResults[$result])) {
            $errors[] = 'Choose a valid result for ' . strtolower($label) . '.';
            $result = 'not_assessed';
        }

        if (strlen($note) > 2000) {
            $errors[] = $label . ' notes must be 2,000 characters or fewer.';
        }

        $submittedConditions[$fieldName] = $result;
        $submittedConditionNotes[$fieldName] = $note;
    }

    $submittedOverallNote = assetReviewText($_POST, 'overall_note');
    if (strlen($submittedOverallNote) > 5000) {
        $errors[] = 'The overall review note must be 5,000 characters or fewer.';
    }

    $photo = $_FILES['photo'] ?? null;
    $photoDetails = null;

    if (
        is_array($photo)
        && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'The replacement photograph could not be uploaded.';
        } elseif (
            !is_int($photo['size'] ?? null)
            || $photo['size'] > 15 * 1024 * 1024
        ) {
            $errors[] = 'The photograph must be smaller than 15 MB.';
        } elseif (
            !is_string($photo['tmp_name'] ?? null)
            || !is_uploaded_file($photo['tmp_name'])
        ) {
            $errors[] = 'The photograph upload was not valid.';
        } else {
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file(
                $photo['tmp_name']
            );
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
                $errors[] = 'Use a JPEG, PNG, or WebP photograph.';
            } else {
                $photoDetails = [
                    'temporary_path' => $photo['tmp_name'],
                    'extension' => $allowedTypes[$mimeType],
                ];
            }
        }
    }

    $currentAssetSnapshot = null;

    if ($selectedAssetId !== null) {
        $snapshotStatement = $connection->prepare(
            "SELECT color, size_description
             FROM assets
             WHERE id = :asset_id
               AND asset_review_status = 'pending'
               AND collection_status = 'active'
             LIMIT 1"
        );
        $snapshotStatement->execute([
            'asset_id' => $selectedAssetId,
        ]);
        $currentAssetSnapshot = $snapshotStatement->fetch() ?: null;

        if ($currentAssetSnapshot === null) {
            $errors[] = 'That asset is no longer awaiting review.';
        }
    }

    $displaySize = collectionStewardAssetSizeDescription(
        $selectedOptionNames['size_option_id'] ?? null,
        $submittedValues['exact_size_label'],
        $currentAssetSnapshot['size_description'] ?? null
    );
    $generatedName = collectionStewardBuildAssetName(
        $selectedOptionNames['wearer_option_id'] ?? null,
        $selectedOptionNames['primary_color_option_id'] ?? null,
        $selectedOptionNames['length_option_id'] ?? null,
        $selectedOptionNames['asset_type_id'] ?? null,
        $displaySize
    );

    if (strlen($generatedName) > 150) {
        $errors[] = 'The selected attributes produce a name longer than 150 characters.';
    }

    if ($errors === [] && $selectedAssetId !== null) {
        $storedPhotoPath = null;

        try {
            $connection->beginTransaction();

            $lockStatement = $connection->prepare(
                "SELECT id
                 FROM assets
                 WHERE id = :asset_id
                   AND asset_review_status = 'pending'
                   AND collection_status = 'active'
                 FOR UPDATE"
            );
            $lockStatement->execute([
                'asset_id' => $selectedAssetId,
            ]);

            if ($lockStatement->fetchColumn() === false) {
                throw new DomainException(
                    'That asset is no longer awaiting review.'
                );
            }

            $updateAssetStatement = $connection->prepare(
                "UPDATE assets
                 SET asset_type_id = :asset_type_id,
                     wearer_option_id = :wearer_option_id,
                     primary_color_option_id = :primary_color_option_id,
                     size_option_id = :size_option_id,
                     length_option_id = :length_option_id,
                     name = :name,
                     description = :description,
                     storage_location = :storage_location,
                     color = :color,
                     size_description = :size_description,
                     exact_size_label = :exact_size_label,
                     notes = :notes,
                     received_date = :received_date,
                     acquisition_type = :acquisition_type,
                     asset_review_status = 'reviewed',
                     updated_by = :updated_by
                 WHERE id = :asset_id"
            );
            $updateAssetStatement->execute([
                'asset_type_id' => $selectedOptionIds['asset_type_id'],
                'wearer_option_id' => $selectedOptionIds['wearer_option_id'],
                'primary_color_option_id' => $selectedOptionIds['primary_color_option_id'],
                'size_option_id' => $selectedOptionIds['size_option_id'],
                'length_option_id' => $selectedOptionIds['length_option_id'],
                'name' => $generatedName,
                'description' => $submittedValues['description'] !== ''
                    ? $submittedValues['description']
                    : null,
                'storage_location' => $submittedValues['storage_location'] !== ''
                    ? $submittedValues['storage_location']
                    : null,
                'color' => $selectedOptionNames['primary_color_option_id']
                    ?? ($currentAssetSnapshot['color'] ?? null),
                'size_description' => $displaySize !== '' ? $displaySize : null,
                'exact_size_label' => $submittedValues['exact_size_label'] !== ''
                    ? $submittedValues['exact_size_label']
                    : null,
                'notes' => $submittedValues['notes'] !== ''
                    ? $submittedValues['notes']
                    : null,
                'received_date' => $submittedValues['received_date'] !== ''
                    ? $submittedValues['received_date']
                    : null,
                'acquisition_type' => $submittedValues['acquisition_type'] !== ''
                    ? $submittedValues['acquisition_type']
                    : null,
                'updated_by' => $currentUser['display_name'],
                'asset_id' => $selectedAssetId,
            ]);

            $deleteTagsStatement = $connection->prepare(
                'DELETE FROM asset_tags WHERE asset_id = :asset_id'
            );
            $deleteTagsStatement->execute([
                'asset_id' => $selectedAssetId,
            ]);

            if ($submittedTagIds !== []) {
                $insertTagStatement = $connection->prepare(
                    'INSERT INTO asset_tags (asset_id, tag_id)
                     VALUES (:asset_id, :tag_id)'
                );

                foreach ($submittedTagIds as $tagId) {
                    $insertTagStatement->execute([
                        'asset_id' => $selectedAssetId,
                        'tag_id' => $tagId,
                    ]);
                }
            }

            $insertReviewStatement = $connection->prepare(
                'INSERT INTO asset_condition_reviews (
                    asset_id,
                    smell_result,
                    smell_note,
                    stains_result,
                    stains_note,
                    damage_result,
                    damage_note,
                    wear_result,
                    wear_note,
                    general_condition_result,
                    general_condition_note,
                    overall_note,
                    reviewed_by_user_id
                 ) VALUES (
                    :asset_id,
                    :smell_result,
                    :smell_note,
                    :stains_result,
                    :stains_note,
                    :damage_result,
                    :damage_note,
                    :wear_result,
                    :wear_note,
                    :general_condition_result,
                    :general_condition_note,
                    :overall_note,
                    :reviewed_by_user_id
                 )'
            );
            $insertReviewStatement->execute([
                'asset_id' => $selectedAssetId,
                'smell_result' => $submittedConditions['smell'],
                'smell_note' => $submittedConditionNotes['smell'] !== ''
                    ? $submittedConditionNotes['smell']
                    : null,
                'stains_result' => $submittedConditions['stains'],
                'stains_note' => $submittedConditionNotes['stains'] !== ''
                    ? $submittedConditionNotes['stains']
                    : null,
                'damage_result' => $submittedConditions['damage'],
                'damage_note' => $submittedConditionNotes['damage'] !== ''
                    ? $submittedConditionNotes['damage']
                    : null,
                'wear_result' => $submittedConditions['wear'],
                'wear_note' => $submittedConditionNotes['wear'] !== ''
                    ? $submittedConditionNotes['wear']
                    : null,
                'general_condition_result' => $submittedConditions['general_condition'],
                'general_condition_note' => $submittedConditionNotes['general_condition'] !== ''
                    ? $submittedConditionNotes['general_condition']
                    : null,
                'overall_note' => $submittedOverallNote !== ''
                    ? $submittedOverallNote
                    : null,
                'reviewed_by_user_id' => (int) $currentUser['id'],
            ]);

            if ($photoDetails !== null) {
                $assetPhotoDirectory = dirname(__DIR__)
                    . '/uploads/assets/'
                    . $selectedAssetId;

                if (
                    !is_dir($assetPhotoDirectory)
                    && !mkdir($assetPhotoDirectory, 0755, true)
                    && !is_dir($assetPhotoDirectory)
                ) {
                    throw new RuntimeException(
                        'The asset photograph directory could not be created.'
                    );
                }

                $photoFilename = 'review-'
                    . bin2hex(random_bytes(8))
                    . '.'
                    . $photoDetails['extension'];
                $storedPhotoPath = $assetPhotoDirectory . '/' . $photoFilename;

                if (
                    !move_uploaded_file(
                        $photoDetails['temporary_path'],
                        $storedPhotoPath
                    )
                ) {
                    throw new RuntimeException(
                        'The replacement photograph could not be stored.'
                    );
                }

                $clearPrimaryStatement = $connection->prepare(
                    'UPDATE asset_photos
                     SET is_primary = 0
                     WHERE asset_id = :asset_id'
                );
                $clearPrimaryStatement->execute([
                    'asset_id' => $selectedAssetId,
                ]);

                $insertPhotoStatement = $connection->prepare(
                    'INSERT INTO asset_photos (
                        asset_id,
                        file_path,
                        caption,
                        is_primary,
                        uploaded_by
                     ) VALUES (
                        :asset_id,
                        :file_path,
                        :caption,
                        1,
                        :uploaded_by
                     )'
                );
                $insertPhotoStatement->execute([
                    'asset_id' => $selectedAssetId,
                    'file_path' => '/uploads/assets/'
                        . $selectedAssetId
                        . '/'
                        . $photoFilename,
                    'caption' => $generatedName,
                    'uploaded_by' => $currentUser['display_name'],
                ]);
            }

            $connection->commit();

            header(
                'Location: /asset-review.php?reviewed=' . $selectedAssetId
            );
            exit;
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($storedPhotoPath !== null && is_file($storedPhotoPath)) {
                unlink($storedPhotoPath);
            }

            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($storedPhotoPath !== null && is_file($storedPhotoPath)) {
                unlink($storedPhotoPath);
            }

            $errors[] = 'The asset review could not be saved.';
        }
    }
}

$queueStatement = $connection->query(
    "SELECT
        a.id,
        a.name,
        a.storage_location,
        a.asset_review_requested_at,
        aty.name AS asset_type,
        (
            SELECT acr.reviewed_at
            FROM asset_condition_reviews AS acr
            WHERE acr.asset_id = a.id
            ORDER BY acr.reviewed_at DESC, acr.id DESC
            LIMIT 1
        ) AS last_reviewed_at
     FROM assets AS a
     LEFT JOIN asset_types AS aty
        ON aty.id = a.asset_type_id
     WHERE a.asset_review_status = 'pending'
       AND a.collection_status = 'active'
     ORDER BY
        COALESCE(a.asset_review_requested_at, a.created_at),
        a.id"
);
$reviewQueue = $queueStatement->fetchAll();

if ($selectedAssetId !== null) {
    $isQueued = false;

    foreach ($reviewQueue as $queuedAsset) {
        if ((int) $queuedAsset['id'] === $selectedAssetId) {
            $isQueued = true;
            break;
        }
    }

    if (!$isQueued) {
        $selectedAssetId = null;
    }
}

if ($selectedAssetId === null && $reviewQueue !== []) {
    $selectedAssetId = (int) $reviewQueue[0]['id'];
}

$selectedAsset = null;
$selectedAssetTags = [];
$reviewHistory = [];

if ($selectedAssetId !== null) {
    $assetStatement = $connection->prepare(
        'SELECT
            a.*,
            aty.name AS asset_type,
            wo.name AS wearer,
            co.name AS primary_color,
            so.name AS standardized_size,
            lo.name AS length_name,
            p.file_path,
            p.caption
         FROM assets AS a
         LEFT JOIN asset_types AS aty
            ON aty.id = a.asset_type_id
         LEFT JOIN wearer_options AS wo
            ON wo.id = a.wearer_option_id
         LEFT JOIN color_options AS co
            ON co.id = a.primary_color_option_id
         LEFT JOIN size_options AS so
            ON so.id = a.size_option_id
         LEFT JOIN length_options AS lo
            ON lo.id = a.length_option_id
         LEFT JOIN asset_photos AS p
            ON p.asset_id = a.id
            AND p.is_primary = 1
         WHERE a.id = :asset_id
           AND a.asset_review_status = \'pending\'
           AND a.collection_status = \'active\'
         LIMIT 1'
    );
    $assetStatement->execute([
        'asset_id' => $selectedAssetId,
    ]);
    $selectedAsset = $assetStatement->fetch() ?: null;

    if ($selectedAsset !== null) {
        $assignedTagStatement = $connection->prepare(
            'SELECT tag_id
             FROM asset_tags
             WHERE asset_id = :asset_id'
        );
        $assignedTagStatement->execute([
            'asset_id' => $selectedAssetId,
        ]);
        $selectedAssetTags = array_map(
            'intval',
            array_column($assignedTagStatement->fetchAll(), 'tag_id')
        );

        $historyStatement = $connection->prepare(
            'SELECT
                acr.*,
                u.display_name AS reviewer_name
             FROM asset_condition_reviews AS acr
             LEFT JOIN users AS u
                ON u.id = acr.reviewed_by_user_id
             WHERE acr.asset_id = :asset_id
             ORDER BY acr.reviewed_at DESC, acr.id DESC'
        );
        $historyStatement->execute([
            'asset_id' => $selectedAssetId,
        ]);
        $reviewHistory = $historyStatement->fetchAll();
    }
}

if ($selectedAsset !== null && $submittedValues === null) {
    $submittedValues = [
        'asset_type_id' => (string) ($selectedAsset['asset_type_id'] ?? ''),
        'wearer_option_id' => (string) ($selectedAsset['wearer_option_id'] ?? ''),
        'primary_color_option_id' => (string) ($selectedAsset['primary_color_option_id'] ?? ''),
        'size_option_id' => (string) ($selectedAsset['size_option_id'] ?? ''),
        'length_option_id' => (string) ($selectedAsset['length_option_id'] ?? ''),
        'exact_size_label' => (string) ($selectedAsset['exact_size_label'] ?? ''),
        'storage_location' => (string) ($selectedAsset['storage_location'] ?? ''),
        'received_date' => (string) ($selectedAsset['received_date'] ?? ''),
        'acquisition_type' => (string) ($selectedAsset['acquisition_type'] ?? ''),
        'description' => (string) ($selectedAsset['description'] ?? ''),
        'notes' => (string) ($selectedAsset['notes'] ?? ''),
    ];
    $submittedTagIds = $selectedAssetTags;
}

foreach ($conditionFields as $fieldName => $label) {
    if (!isset($submittedConditions[$fieldName])) {
        $submittedConditions[$fieldName] = 'not_assessed';
    }

    if (!isset($submittedConditionNotes[$fieldName])) {
        $submittedConditionNotes[$fieldName] = '';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset review — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260903-2">
</head>
<body>
<main>
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_productions')): ?>
            <a href="/productions.php">Productions</a>
        <?php endif; ?>
        <a href="/checkout.php">Production checkout</a>
        <a href="/fittings.php">Fittings</a>
        <a href="/measurements.php">Measurements</a>
        <a href="/asset-review.php" aria-current="page">Asset review</a>
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
            <h1>Assets awaiting review</h1>
            <p>Correct the asset record and complete a dated condition review.</p>
        </div>
        <div class="user-session">
            <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
            <form method="post" action="/">
                <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
            </form>
        </div>
    </div>

    <?php if ($reviewedAssetId !== null): ?>
        <div class="notice" role="status">
            Asset <?= $reviewedAssetId ?> was saved and removed from the review queue.
        </div>
    <?php endif; ?>

    <?php if ($retiredAssetId !== null): ?>
        <div class="notice" role="status">
            Asset <?= $retiredAssetId ?> was retired and removed from the review queue.
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The asset was not changed.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="notice">
        <strong><?= count($reviewQueue) ?></strong>
        <?= count($reviewQueue) === 1 ? 'asset is' : 'assets are' ?> awaiting review.
    </div>

    <?php if ($reviewQueue === []): ?>
        <p>The review queue is empty.</p>
        <p><a class="button" href="/">Return to asset browsing</a></p>
    <?php elseif ($selectedAsset !== null && $submittedValues !== null): ?>
        <form method="get" class="review-asset-picker">
            <div class="field">
                <label for="asset_id">Asset to review</label>
                <select id="asset_id" name="asset_id" data-submit-on-change>
                    <?php foreach ($reviewQueue as $queuedAsset): ?>
                        <option value="<?= (int) $queuedAsset['id'] ?>" <?= (int) $queuedAsset['id'] === $selectedAssetId ? 'selected' : '' ?>>
                            <?= collectionStewardEscape(collectionStewardAssetLabel((int) $queuedAsset['id'], $queuedAsset['name'])) ?>
                            <?= !empty($queuedAsset['storage_location']) ? ' — ' . collectionStewardEscape($queuedAsset['storage_location']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit">Choose asset</button></noscript>
        </form>

        <section class="review-asset-summary" aria-labelledby="review-asset-title">
            <?php if (!empty($selectedAsset['file_path'])): ?>
                <img
                    src="<?= collectionStewardEscape($selectedAsset['file_path']) ?>"
                    alt="<?= collectionStewardEscape($selectedAsset['caption'] ?: $selectedAsset['name']) ?>"
                    class="review-asset-photo"
                >
            <?php endif; ?>
            <div>
                <p class="eyebrow">Reviewing</p>
                <h2 id="review-asset-title"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $selectedAsset['id'], $selectedAsset['name'])) ?></h2>
                <p>
                    <?= collectionStewardEscape($selectedAsset['asset_type'] ?: 'Unassigned type') ?>
                    <?php if (!empty($selectedAsset['storage_location'])): ?>
                        · <?= collectionStewardEscape($selectedAsset['storage_location']) ?>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <details class="asset-actions retirement-from-review">
            <summary>Retire this asset instead of reviewing it</summary>
            <p>
                Use this when the item has left the collection or when its record was created in error. Its record and history will remain available to stewards.
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="retire_asset">
                <input type="hidden" name="asset_id" value="<?= (int) $selectedAsset['id'] ?>">
                <div class="review-field-grid">
                    <div class="field">
                        <label for="review_retirement_disposition">Disposition</label>
                        <select id="review_retirement_disposition" name="retirement_disposition" required>
                            <option value="">Choose a disposition</option>
                            <?php foreach (collectionStewardRetirementDispositions() as $dispositionValue => $dispositionLabel): ?>
                                <option value="<?= collectionStewardEscape($dispositionValue) ?>" <?= $retirementDisposition === $dispositionValue ? 'selected' : '' ?>><?= collectionStewardEscape($dispositionLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="review_retirement_date">Retirement date</label>
                        <input type="date" id="review_retirement_date" name="retirement_date" value="<?= collectionStewardEscape($retirementDate) ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="review_retirement_note">Note (optional)</label>
                    <textarea id="review_retirement_note" name="retirement_note" maxlength="5000"><?= collectionStewardEscape($retirementNote) ?></textarea>
                    <span class="help">For a record created in error, choose Discarded and note that no physical asset existed.</span>
                </div>
                <label class="confirmation-choice">
                    <input type="checkbox" name="confirm_retirement" value="1" required>
                    I confirm that this asset should be retired.
                </label>
                <button type="submit" class="secondary">Retire asset</button>
            </form>
        </details>

        <form method="post" enctype="multipart/form-data" class="asset-review-form">
            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="save_asset_review">
            <input type="hidden" name="asset_id" value="<?= (int) $selectedAsset['id'] ?>">

            <fieldset class="controlled-attributes">
                <legend>Correct the standard description</legend>

                <div class="review-field-grid">
                    <?php foreach ($optionLists as $fieldName => $configuration): ?>
                        <div class="field">
                            <label for="<?= collectionStewardEscape($fieldName) ?>"><?= collectionStewardEscape($configuration['label']) ?></label>
                            <select id="<?= collectionStewardEscape($fieldName) ?>" name="<?= collectionStewardEscape($fieldName) ?>">
                                <option value="">Not recorded</option>
                                <?php foreach ($configuration['options'] as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>" <?= (string) $option['id'] === $submittedValues[$fieldName] ? 'selected' : '' ?>>
                                        <?= collectionStewardEscape($option['name']) ?><?= (int) $option['is_active'] === 0 ? ' (inactive)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>

                    <div class="field">
                        <label for="exact_size_label">Exact garment label</label>
                        <input type="text" id="exact_size_label" name="exact_size_label" maxlength="100" value="<?= collectionStewardEscape($submittedValues['exact_size_label']) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset class="controlled-attributes">
                <legend>Correct the record</legend>

                <div class="review-field-grid">
                    <div class="field">
                        <label for="storage_location">Storage location</label>
                        <input type="text" id="storage_location" name="storage_location" maxlength="255" value="<?= collectionStewardEscape($submittedValues['storage_location']) ?>">
                    </div>
                    <div class="field">
                        <label for="received_date">Date received</label>
                        <input type="date" id="received_date" name="received_date" value="<?= collectionStewardEscape($submittedValues['received_date']) ?>">
                    </div>
                    <div class="field">
                        <label for="acquisition_type">Acquisition type</label>
                        <input type="text" id="acquisition_type" name="acquisition_type" maxlength="50" value="<?= collectionStewardEscape($submittedValues['acquisition_type']) ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="10000"><?= collectionStewardEscape($submittedValues['description']) ?></textarea>
                </div>

                <div class="field">
                    <label for="notes">Asset notes</label>
                    <textarea id="notes" name="notes" maxlength="10000"><?= collectionStewardEscape($submittedValues['notes']) ?></textarea>
                </div>

                <fieldset class="field choices tag-selector">
                    <legend>Other attributes and work tags</legend>
                    <div class="review-tag-grid">
                        <?php foreach ($tagOptions as $tag): ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="tag_ids[]"
                                    value="<?= (int) $tag['id'] ?>"
                                    <?= in_array((int) $tag['id'], $submittedTagIds, true) ? 'checked' : '' ?>
                                >
                                <?= collectionStewardEscape($tag['name']) ?><?= (int) $tag['is_active'] === 0 ? ' (inactive)' : '' ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="field">
                    <label for="photo">Replace the primary photograph</label>
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                    <span class="help">Leave blank to keep the current photograph.</span>
                </div>
            </fieldset>

            <fieldset class="controlled-attributes">
                <legend>Condition review</legend>
                <p class="help">Choose one result for each category. Add a note when it will help the next costumer.</p>

                <div class="review-condition-grid">
                    <?php foreach ($conditionFields as $fieldName => $label): ?>
                        <section class="condition-review-item">
                            <h3><?= collectionStewardEscape($label) ?></h3>
                            <div class="field">
                                <label for="<?= collectionStewardEscape($fieldName) ?>_result">Result</label>
                                <select id="<?= collectionStewardEscape($fieldName) ?>_result" name="<?= collectionStewardEscape($fieldName) ?>_result" required>
                                    <?php foreach ($conditionResults as $resultValue => $resultLabel): ?>
                                        <option value="<?= collectionStewardEscape($resultValue) ?>" <?= $submittedConditions[$fieldName] === $resultValue ? 'selected' : '' ?>>
                                            <?= collectionStewardEscape($resultLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="<?= collectionStewardEscape($fieldName) ?>_note">Optional note</label>
                                <textarea id="<?= collectionStewardEscape($fieldName) ?>_note" name="<?= collectionStewardEscape($fieldName) ?>_note" maxlength="2000"><?= collectionStewardEscape($submittedConditionNotes[$fieldName]) ?></textarea>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="field">
                    <label for="overall_note">Overall review note</label>
                    <textarea id="overall_note" name="overall_note" maxlength="5000"><?= collectionStewardEscape($submittedOverallNote) ?></textarea>
                </div>
            </fieldset>

            <div class="button-row">
                <button type="submit">Save changes and mark reviewed</button>
                <a class="button secondary" href="/?asset_id=<?= (int) $selectedAsset['id'] ?>#asset-record">Leave in queue and return to asset</a>
            </div>
        </form>

        <?php if ($reviewHistory !== []): ?>
            <section class="review-history" aria-labelledby="review-history-title">
                <h2 id="review-history-title">Earlier condition reviews</h2>
                <?php foreach ($reviewHistory as $historyReview): ?>
                    <article>
                        <h3>
                            <?= collectionStewardEscape($historyReview['reviewed_at']) ?>
                            · <?= collectionStewardEscape($historyReview['reviewer_name'] ?: 'Former user') ?>
                        </h3>
                        <dl class="asset-facts">
                            <?php foreach ($conditionFields as $fieldName => $label): ?>
                                <div>
                                    <dt><?= collectionStewardEscape($label) ?></dt>
                                    <dd>
                                        <?= collectionStewardEscape($conditionResults[$historyReview[$fieldName . '_result']] ?? $historyReview[$fieldName . '_result']) ?>
                                        <?php if (!empty($historyReview[$fieldName . '_note'])): ?>
                                            — <?= collectionStewardEscape($historyReview[$fieldName . '_note']) ?>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                        <?php if (!empty($historyReview['overall_note'])): ?>
                            <p><strong>Overall note:</strong> <?= collectionStewardEscape($historyReview['overall_note']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<script>
document.querySelectorAll('[data-submit-on-change]').forEach(function (input) {
    input.addEventListener('change', function () {
        input.form.requestSubmit();
    });
});
</script>
</body>
</html>
