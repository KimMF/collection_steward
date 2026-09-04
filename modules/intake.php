<?php

declare(strict_types=1);

/**
 * Donation intake and controlled-vocabulary suggestion workflow.
 *
 * Public entry point: /intake.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

// Intake is available only to signed-in users with the intake capability.
$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability($connection, 'intake');
$csrfToken = collectionStewardCsrfToken();

// Load the approved choices displayed by the controlled intake fields.
$assetTypeStatement = $connection->query(
    'SELECT id, name
     FROM asset_types
     WHERE is_active = 1
     ORDER BY display_order, name'
);
$assetTypes = $assetTypeStatement->fetchAll();

$wearerStatement = $connection->query(
    'SELECT id, name
     FROM wearer_options
     WHERE is_active = 1
     ORDER BY display_order, name'
);
$wearerOptions = $wearerStatement->fetchAll();

$colorStatement = $connection->query(
    'SELECT id, name
     FROM color_options
     WHERE is_active = 1
     ORDER BY display_order, name'
);
$colorOptions = $colorStatement->fetchAll();

$sizeStatement = $connection->query(
    'SELECT id, name
     FROM size_options
     WHERE is_active = 1
     ORDER BY display_order, name'
);
$sizeOptions = $sizeStatement->fetchAll();

$lengthStatement = $connection->query(
    'SELECT id, name
     FROM length_options
     WHERE is_active = 1
     ORDER BY display_order, name'
);
$lengthOptions = $lengthStatement->fetchAll();

$tagStatement = $connection->query(
    'SELECT id, name
     FROM tags
     WHERE is_active = 1
     ORDER BY name'
);
$availableTags = $tagStatement->fetchAll();

// Each controlled field uses the same validation and suggestion workflow. The
// configuration keeps those rules in one place.
$controlledFields = [
    'asset_type' => [
        'id_field' => 'asset_type_id',
        'suggestion_field' => 'asset_type_suggestion',
        'vocabulary_type' => 'asset_type',
        'label' => 'What type of item is it?',
        'suggestion_label' => 'Suggest an item type',
        'options' => $assetTypes,
    ],
    'wearer' => [
        'id_field' => 'wearer_option_id',
        'suggestion_field' => 'wearer_suggestion',
        'vocabulary_type' => 'wearer',
        'label' => 'Who is it intended to fit?',
        'suggestion_label' => 'Suggest a wearer option',
        'options' => $wearerOptions,
    ],
    'primary_color' => [
        'id_field' => 'primary_color_option_id',
        'suggestion_field' => 'primary_color_suggestion',
        'vocabulary_type' => 'primary_color',
        'label' => 'What is its primary color?',
        'suggestion_label' => 'Suggest a primary color',
        'options' => $colorOptions,
    ],
    'size' => [
        'id_field' => 'size_option_id',
        'suggestion_field' => 'size_suggestion',
        'vocabulary_type' => 'size',
        'label' => 'What standardized size is it?',
        'suggestion_label' => 'Suggest a standardized size',
        'options' => $sizeOptions,
    ],
    'length' => [
        'id_field' => 'length_option_id',
        'suggestion_field' => 'length_suggestion',
        'vocabulary_type' => 'length',
        'label' => 'What length is it?',
        'suggestion_label' => 'Suggest a length option',
        'options' => $lengthOptions,
    ],
];

// Preserve submitted values when validation returns the form to the user.
$values = [
    'asset_type_id' => '',
    'asset_type_suggestion' => '',
    'wearer_option_id' => '',
    'wearer_suggestion' => '',
    'primary_color_option_id' => '',
    'primary_color_suggestion' => '',
    'size_option_id' => '',
    'size_suggestion' => '',
    'exact_size_label' => '',
    'length_option_id' => '',
    'length_suggestion' => '',
    'other_attribute_suggestion' => '',
    'description' => '',
    'received_date' => date('Y-m-d'),
    'storage_location' => 'WBS intake rack',
    'notes' => '',
];

$errors = [];
$selectedTagIds = [];
$selectedOptionIds = [];
$selectedOptionNames = [];
$pendingSuggestions = [];

// Validate the request completely before opening the transaction that creates
// the asset, tags, suggestions, and optional photograph.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $field) {
        if (is_string($_POST[$field] ?? null)) {
            $values[$field] = trim($_POST[$field]);
        }
    }

    foreach ($controlledFields as $fieldKey => $configuration) {
        $submittedValue = $values[$configuration['id_field']];
        $suggestedValue = preg_replace(
            '/\s+/u',
            ' ',
            $values[$configuration['suggestion_field']]
        );
        $suggestedValue = is_string($suggestedValue)
            ? trim($suggestedValue)
            : '';

        $selectedOptionIds[$fieldKey] = null;
        $selectedOptionNames[$fieldKey] = null;

        if ($submittedValue === '__other__') {
            if ($suggestedValue === '') {
                $errors[] = 'Enter the suggested value for ' . strtolower($configuration['label']);
            } elseif (strlen($suggestedValue) > 100) {
                $errors[] = $configuration['suggestion_label'] . ' must be 100 characters or fewer.';
            } else {
                $pendingSuggestions[$configuration['vocabulary_type']] = $suggestedValue;
            }

            continue;
        }

        if ($submittedValue === '') {
            continue;
        }

        $optionId = filter_var(
            $submittedValue,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $matchedOption = null;

        if ($optionId !== false) {
            foreach ($configuration['options'] as $option) {
                if ((int) $option['id'] === $optionId) {
                    $matchedOption = $option;
                    break;
                }
            }
        }

        if ($matchedOption === null) {
            $errors[] = 'Choose a valid option for ' . strtolower($configuration['label']);
            continue;
        }

        $selectedOptionIds[$fieldKey] = $optionId;
        $selectedOptionNames[$fieldKey] = (string) $matchedOption['name'];
    }

    $submittedTagIds = is_array($_POST['tag_ids'] ?? null)
        ? $_POST['tag_ids']
        : [];
    $availableTagIds = array_map(
        static fn (array $tag): int => (int) $tag['id'],
        $availableTags
    );

    foreach ($submittedTagIds as $submittedTagId) {
        $tagId = filter_var(
            $submittedTagId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($tagId !== false && in_array($tagId, $availableTagIds, true)) {
            $selectedTagIds[] = $tagId;
        }
    }

    $selectedTagIds = array_values(array_unique($selectedTagIds));

    $otherAttributeSuggestion = preg_replace(
        '/\s+/u',
        ' ',
        $values['other_attribute_suggestion']
    );
    $otherAttributeSuggestion = is_string($otherAttributeSuggestion)
        ? trim($otherAttributeSuggestion)
        : '';

    if (strlen($otherAttributeSuggestion) > 100) {
        $errors[] = 'The suggested other attribute must be 100 characters or fewer.';
    } elseif ($otherAttributeSuggestion !== '') {
        $pendingSuggestions['tag'] = $otherAttributeSuggestion;
    }

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    if (strlen($values['exact_size_label']) > 100) {
        $errors[] = 'The exact size label must be 100 characters or fewer.';
    }

    $receivedDate = null;
    if ($values['received_date'] !== '') {
        $parsedDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $values['received_date']
        );

        if (
            $parsedDate === false
            || $parsedDate->format('Y-m-d') !== $values['received_date']
        ) {
            $errors[] = 'Enter a valid date received.';
        } else {
            $receivedDate = $values['received_date'];
        }
    }

    $displaySize = collectionStewardAssetSizeDescription(
        $selectedOptionNames['size'],
        $values['exact_size_label']
    );

    $generatedAssetName = collectionStewardBuildAssetName(
        $selectedOptionNames['wearer'],
        $selectedOptionNames['primary_color'],
        $selectedOptionNames['length'],
        $selectedOptionNames['asset_type'],
        $displaySize
    );

    if (strlen($generatedAssetName) > 150) {
        $errors[] = 'The selected attributes produce a name longer than 150 characters. Shorten the exact size label or submit an option for review.';
    }

    $photo = $_FILES['photo'] ?? null;
    $photoDetails = null;

    if (is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'The photograph could not be uploaded. Try again or save without it.';
        } elseif (!is_int($photo['size'] ?? null) || $photo['size'] > 15 * 1024 * 1024) {
            $errors[] = 'The photograph must be smaller than 15 MB.';
        } elseif (!is_string($photo['tmp_name'] ?? null) || !is_uploaded_file($photo['tmp_name'])) {
            $errors[] = 'The photograph upload was not valid.';
        } else {
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
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

    if ($errors === []) {
        $storedPhotoPath = null;

        try {
            $connection->beginTransaction();

            $insertAssetStatement = $connection->prepare(
                'INSERT INTO assets (
                    asset_type_id,
                    wearer_option_id,
                    primary_color_option_id,
                    size_option_id,
                    length_option_id,
                    name,
                    description,
                    storage_location,
                    color,
                    size_description,
                    exact_size_label,
                    availability_status,
                    notes,
                    received_date,
                    acquisition_type,
                    asset_review_status,
                    asset_review_requested_at,
                    asset_review_requested_by_user_id,
                    created_by,
                    updated_by
                 ) VALUES (
                    :asset_type_id,
                    :wearer_option_id,
                    :primary_color_option_id,
                    :size_option_id,
                    :length_option_id,
                    :name,
                    :description,
                    :storage_location,
                    :color,
                    :size_description,
                    :exact_size_label,
                    :availability_status,
                    :notes,
                    :received_date,
                    :acquisition_type,
                    \'pending\',
                    CURRENT_TIMESTAMP,
                    :asset_review_requested_by_user_id,
                    :created_by,
                    :updated_by
                 )'
            );

            $insertAssetStatement->execute([
                'asset_type_id' => $selectedOptionIds['asset_type'],
                'wearer_option_id' => $selectedOptionIds['wearer'],
                'primary_color_option_id' => $selectedOptionIds['primary_color'],
                'size_option_id' => $selectedOptionIds['size'],
                'length_option_id' => $selectedOptionIds['length'],
                'name' => $generatedAssetName,
                'description' => $values['description'] !== '' ? $values['description'] : null,
                'storage_location' => $values['storage_location'] !== '' ? $values['storage_location'] : null,
                'color' => $selectedOptionNames['primary_color'],
                'size_description' => $displaySize,
                'exact_size_label' => $values['exact_size_label'] !== ''
                    ? $values['exact_size_label']
                    : null,
                'availability_status' => 'available',
                'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                'received_date' => $receivedDate,
                'acquisition_type' => 'donation',
                'asset_review_requested_by_user_id' => (int) $currentUser['id'],
                'created_by' => $currentUser['display_name'],
                'updated_by' => $currentUser['display_name'],
            ]);

            $assetId = (int) $connection->lastInsertId();

            if ($selectedTagIds !== []) {
                $insertTagStatement = $connection->prepare(
                    'INSERT INTO asset_tags (asset_id, tag_id)
                     VALUES (:asset_id, :tag_id)'
                );

                foreach ($selectedTagIds as $tagId) {
                    $insertTagStatement->execute([
                        'asset_id' => $assetId,
                        'tag_id' => $tagId,
                    ]);
                }
            }

            if ($pendingSuggestions !== []) {
                $insertSuggestionStatement = $connection->prepare(
                    'INSERT INTO vocabulary_suggestions (
                        asset_id,
                        vocabulary_type,
                        suggested_value,
                        submitted_by_user_id
                     ) VALUES (
                        :asset_id,
                        :vocabulary_type,
                        :suggested_value,
                        :submitted_by_user_id
                     )'
                );

                foreach ($pendingSuggestions as $vocabularyType => $suggestedValue) {
                    $insertSuggestionStatement->execute([
                        'asset_id' => $assetId,
                        'vocabulary_type' => $vocabularyType,
                        'suggested_value' => $suggestedValue,
                        'submitted_by_user_id' => (int) $currentUser['id'],
                    ]);
                }
            }

            if ($photoDetails !== null) {
                $assetPhotoDirectory = dirname(__DIR__)
                    . '/uploads/assets/'
                    . $assetId;

                if (
                    !is_dir($assetPhotoDirectory)
                    && !mkdir($assetPhotoDirectory, 0755, true)
                    && !is_dir($assetPhotoDirectory)
                ) {
                    throw new RuntimeException('The asset photograph directory could not be created.');
                }

                $photoFilename = 'primary-'
                    . bin2hex(random_bytes(8))
                    . '.'
                    . $photoDetails['extension'];
                $storedPhotoPath = $assetPhotoDirectory . '/' . $photoFilename;

                if (!move_uploaded_file($photoDetails['temporary_path'], $storedPhotoPath)) {
                    throw new RuntimeException('The asset photograph could not be stored.');
                }

                $publicPhotoPath = '/uploads/assets/'
                    . $assetId
                    . '/'
                    . $photoFilename;

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
                    'asset_id' => $assetId,
                    'file_path' => $publicPhotoPath,
                    'caption' => $generatedAssetName,
                    'uploaded_by' => $currentUser['display_name'],
                ]);
            }

            $connection->commit();

            header('Location: /intake.php?created=' . $assetId);
            exit;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($storedPhotoPath !== null && is_file($storedPhotoPath)) {
                unlink($storedPhotoPath);
            }

            $errors[] = 'The item could not be saved. No asset record was created.';
        }
    }
}

// A successful redirect returns the saved record for immediate confirmation.
$createdAsset = null;
$createdAssetTags = [];
$createdSuggestions = [];
$createdAssetId = filter_input(INPUT_GET, 'created', FILTER_VALIDATE_INT);
if (is_int($createdAssetId) && $createdAssetId > 0) {
    $createdAssetStatement = $connection->prepare(
        'SELECT
            a.id,
            a.name,
            a.description,
            a.storage_location,
            a.color,
            a.size_description,
            a.received_date,
            a.asset_review_status,
            aty.name AS asset_type,
            wo.name AS wearer,
            COALESCE(co.name, a.color) AS primary_color,
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
         LIMIT 1'
    );
    $createdAssetStatement->execute([
        'asset_id' => $createdAssetId,
    ]);
    $createdAsset = $createdAssetStatement->fetch() ?: null;

    if ($createdAsset !== null) {
        $createdTagStatement = $connection->prepare(
            'SELECT t.name
             FROM tags AS t
             JOIN asset_tags AS at
                ON at.tag_id = t.id
             WHERE at.asset_id = :asset_id
             ORDER BY t.name'
        );
        $createdTagStatement->execute([
            'asset_id' => $createdAssetId,
        ]);
        $createdAssetTags = array_column(
            $createdTagStatement->fetchAll(),
            'name'
        );

        $createdSuggestionStatement = $connection->prepare(
            "SELECT vocabulary_type, suggested_value
             FROM vocabulary_suggestions
             WHERE asset_id = :asset_id
               AND status = 'pending'
             ORDER BY vocabulary_type, id"
        );
        $createdSuggestionStatement->execute([
            'asset_id' => $createdAssetId,
        ]);
        $createdSuggestions = $createdSuggestionStatement->fetchAll();
    }
}

// Render either the confirmation panel or the intake form.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intake — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260828-1">
</head>
<body>
<main class="intake-page">
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php" aria-current="page">Intake</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_productions')): ?>
            <a href="/productions.php">Productions</a>
        <?php endif; ?>
        <?php if (collectionStewardUserCan($currentUser, 'checkout')): ?>
            <a href="/checkout.php">Production checkout</a>
            <a href="/fittings.php">Fittings</a>
        <?php endif; ?>
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
            <h1>WBS intake</h1>
            <p>Add one incoming donation at a time.</p>
        </div>
        <div class="user-session">
            <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
            <form method="post" action="/">
                <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
            </form>
        </div>
    </div>
    <p class="required-note">Choose from the approved lists when possible. Use “Not listed” to submit a suggestion for review.</p>

    <?php if ($createdAsset !== null): ?>
        <section class="saved-asset" aria-labelledby="saved-asset-title">
            <div class="notice" role="status">
                <strong>Saved successfully as Asset <?= (int) $createdAsset['id'] ?>.</strong>
            </div>

            <div class="saved-asset-card">
                <?php if (!empty($createdAsset['file_path'])): ?>
                    <img
                        src="<?= collectionStewardEscape($createdAsset['file_path']) ?>"
                        alt="<?= collectionStewardEscape($createdAsset['caption'] ?: $createdAsset['name']) ?>"
                        class="saved-asset-photo"
                    >
                <?php endif; ?>
                <div>
                    <h2 id="saved-asset-title"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $createdAsset['id'], $createdAsset['name'])) ?></h2>
                    <dl class="asset-facts">
                        <div><dt>Asset ID</dt><dd><?= (int) $createdAsset['id'] ?></dd></div>
                        <div><dt>Review</dt><dd>Awaiting steward review</dd></div>
                        <div><dt>Type</dt><dd><?= collectionStewardEscape($createdAsset['asset_type'] ?? 'Pending review') ?></dd></div>
                        <?php if (!empty($createdAsset['wearer'])): ?>
                            <div><dt>Wearer</dt><dd><?= collectionStewardEscape($createdAsset['wearer']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($createdAsset['primary_color'])): ?>
                            <div><dt>Primary color</dt><dd><?= collectionStewardEscape($createdAsset['primary_color']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($createdAsset['length_name'])): ?>
                            <div><dt>Length</dt><dd><?= collectionStewardEscape($createdAsset['length_name']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($createdAsset['size_description'])): ?>
                            <div><dt>Size</dt><dd><?= collectionStewardEscape($createdAsset['size_description']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($createdAsset['storage_location'])): ?>
                            <div><dt>Location</dt><dd><?= collectionStewardEscape($createdAsset['storage_location']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($createdAssetTags !== []): ?>
                            <div><dt>Other attributes</dt><dd><?= collectionStewardEscape(implode(', ', $createdAssetTags)) ?></dd></div>
                        <?php endif; ?>
                    </dl>

                    <?php if ($createdSuggestions !== []): ?>
                        <div class="pending-suggestions">
                            <strong>Pending vocabulary review:</strong>
                            <ul>
                                <?php foreach ($createdSuggestions as $suggestion): ?>
                                    <li>
                                        <?= collectionStewardEscape(str_replace('_', ' ', $suggestion['vocabulary_type'])) ?>:
                                        <?= collectionStewardEscape($suggestion['suggested_value']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($createdAsset['description'])): ?>
                        <p><?= collectionStewardEscape($createdAsset['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="button-row">
                <a class="button" href="/intake.php#intake-form">Enter another item</a>
                <a class="button secondary" href="/?asset_id=<?= (int) $createdAsset['id'] ?>#asset-record">View full item record</a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The item was not saved.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="intake-form" class="intake-form">
        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">

        <div class="field">
            <label for="photo">Photograph the item</label>
            <input
                type="file"
                id="photo"
                name="photo"
                accept="image/*"
                capture="environment"
            >
            <span class="help" id="photo-help">Optional. Take a new photograph or choose one already on the device.</span>
        </div>

        <fieldset class="controlled-attributes">
            <legend>Standard description</legend>
            <p class="help">These selections create the item name in a consistent order.</p>

            <?php foreach ($controlledFields as $fieldKey => $configuration): ?>
                <?php
                $idField = $configuration['id_field'];
                $suggestionField = $configuration['suggestion_field'];
                $suggestionIsOpen = $values[$idField] === '__other__';
                ?>
                <div class="field controlled-field">
                    <label for="<?= collectionStewardEscape($idField) ?>"><?= collectionStewardEscape($configuration['label']) ?></label>
                    <select
                        id="<?= collectionStewardEscape($idField) ?>"
                        name="<?= collectionStewardEscape($idField) ?>"
                        data-controlled-select
                        data-suggestion-panel="<?= collectionStewardEscape($suggestionField) ?>-panel"
                    >
                        <option value="">Unsure or not applicable</option>
                        <?php foreach ($configuration['options'] as $option): ?>
                            <option
                                value="<?= (int) $option['id'] ?>"
                                <?= (string) $option['id'] === $values[$idField] ? 'selected' : '' ?>
                            >
                                <?= collectionStewardEscape($option['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="__other__" <?= $suggestionIsOpen ? 'selected' : '' ?>>Not listed — suggest an option</option>
                    </select>

                    <div id="<?= collectionStewardEscape($suggestionField) ?>-panel" class="other-option" <?= $suggestionIsOpen ? '' : 'hidden' ?>>
                        <label for="<?= collectionStewardEscape($suggestionField) ?>"><?= collectionStewardEscape($configuration['suggestion_label']) ?></label>
                        <input
                            type="text"
                            id="<?= collectionStewardEscape($suggestionField) ?>"
                            name="<?= collectionStewardEscape($suggestionField) ?>"
                            maxlength="100"
                            value="<?= collectionStewardEscape($values[$suggestionField]) ?>"
                        >
                        <span class="help">This will be submitted for review, not automatically added to the approved list.</span>
                    </div>
                </div>

                <?php if ($fieldKey === 'size'): ?>
                    <div class="field">
                        <label for="exact_size_label">What exactly does the garment label say?</label>
                        <input
                            type="text"
                            id="exact_size_label"
                            name="exact_size_label"
                            maxlength="100"
                            value="<?= collectionStewardEscape($values['exact_size_label']) ?>"
                            placeholder="Optional, for example: 42L"
                        >
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="generated-name-preview" role="status">
                <strong>Generated item name:</strong>
                <span id="generated-name-preview">Unclassified item</span>
            </div>
        </fieldset>

        <fieldset class="field choices tag-selector">
            <legend>Do any other attributes apply?</legend>
            <span class="help">Select as many as apply. Leave these blank if you are unsure.</span>
            <?php if ($availableTags !== []): ?>
                <label class="tag-search-label" for="tag-search">Search other attributes</label>
                <input
                    type="search"
                    id="tag-search"
                    placeholder="Begin typing an attribute"
                    autocomplete="off"
                >
                <p id="tag-selection-summary" class="tag-selection-summary" aria-live="polite">No attributes selected.</p>
                <div id="tag-choices" class="tag-choices" tabindex="0">
                    <?php foreach ($availableTags as $tag): ?>
                        <label data-tag-name="<?= collectionStewardEscape(strtolower($tag['name'])) ?>">
                            <input
                                type="checkbox"
                                name="tag_ids[]"
                                value="<?= (int) $tag['id'] ?>"
                                <?= in_array((int) $tag['id'], $selectedTagIds, true) ? 'checked' : '' ?>
                            >
                            <span><?= collectionStewardEscape($tag['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p id="no-tag-results" class="help" hidden>No existing attributes match that search.</p>
            <?php else: ?>
                <p class="help">No approved other attributes have been added yet.</p>
            <?php endif; ?>
            <div class="other-option">
                <label for="other_attribute_suggestion">Attribute not listed?</label>
                <input
                    type="text"
                    id="other_attribute_suggestion"
                    name="other_attribute_suggestion"
                    maxlength="100"
                    value="<?= collectionStewardEscape($values['other_attribute_suggestion']) ?>"
                    placeholder="Suggest one attribute for review"
                >
                <span class="help">This suggestion will not be added to the approved list until a steward reviews it.</span>
            </div>
        </fieldset>

        <div class="field">
            <label for="description">What else would help a costumer recognize it?</label>
            <textarea id="description" name="description"><?= collectionStewardEscape($values['description']) ?></textarea>
        </div>

        <div class="field">
            <label for="received_date">When was it received?</label>
            <input
                type="date"
                id="received_date"
                name="received_date"
                value="<?= collectionStewardEscape($values['received_date']) ?>"
            >
        </div>

        <div class="field">
            <label for="storage_location">Where did you place it?</label>
            <input
                type="text"
                id="storage_location"
                name="storage_location"
                maxlength="255"
                value="<?= collectionStewardEscape($values['storage_location']) ?>"
            >
            <span class="help">Use the permanent rack or bin label when one is available.</span>
        </div>

        <div class="field">
            <label for="notes">Anything else we should know?</label>
            <textarea id="notes" name="notes"><?= collectionStewardEscape($values['notes']) ?></textarea>
        </div>

        <button type="submit" id="save-donation">Save incoming donation</button>
    </form>

    <script>
        const photoInput = document.getElementById('photo');
        const photoHelp = document.getElementById('photo-help');
        const saveDonation = document.getElementById('save-donation');

        photoInput.addEventListener('change', function () {
            const originalPhoto = photoInput.files[0];

            if (!originalPhoto || !originalPhoto.type.startsWith('image/')) {
                return;
            }

            const image = new Image();
            const imageUrl = URL.createObjectURL(originalPhoto);

            photoHelp.textContent = 'Preparing photograph for upload…';
            saveDonation.disabled = true;

            image.onload = function () {
                const maximumDimension = 1600;
                const scale = Math.min(
                    1,
                    maximumDimension / Math.max(image.width, image.height)
                );
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(image.width * scale));
                canvas.height = Math.max(1, Math.round(image.height * scale));

                const context = canvas.getContext('2d');

                if (!context) {
                    URL.revokeObjectURL(imageUrl);
                    photoHelp.textContent = 'Photograph selected.';
                    saveDonation.disabled = false;
                    return;
                }

                context.drawImage(image, 0, 0, canvas.width, canvas.height);

                canvas.toBlob(function (preparedPhoto) {
                    URL.revokeObjectURL(imageUrl);

                    try {
                        if (preparedPhoto && preparedPhoto.size < originalPhoto.size) {
                            const preparedFile = new File(
                                [preparedPhoto],
                                'asset-photo.jpg',
                                { type: 'image/jpeg' }
                            );
                            const transfer = new DataTransfer();
                            transfer.items.add(preparedFile);
                            photoInput.files = transfer.files;
                            photoHelp.textContent = 'Photograph is ready to upload.';
                        } else {
                            photoHelp.textContent = 'Photograph selected.';
                        }
                    } catch (error) {
                        photoHelp.textContent = 'Photograph selected.';
                    }

                    saveDonation.disabled = false;
                }, 'image/jpeg', 0.82);
            };

            image.onerror = function () {
                URL.revokeObjectURL(imageUrl);
                photoHelp.textContent = 'Photograph selected. The server will check its format when you save.';
                saveDonation.disabled = false;
            };

            image.src = imageUrl;
        });

        const controlledSelects = Array.from(
            document.querySelectorAll('[data-controlled-select]')
        );
        const generatedNamePreview = document.getElementById('generated-name-preview');
        const exactSizeLabel = document.getElementById('exact_size_label');

        const selectedOptionName = function (selectId) {
            const select = document.getElementById(selectId);

            if (!select || select.value === '' || select.value === '__other__') {
                return '';
            }

            const optionName = select.options[select.selectedIndex].text.trim();
            const normalizedOptionName = optionName.toLocaleLowerCase();

            return normalizedOptionName === 'unknown'
                || normalizedOptionName === 'not applicable'
                ? ''
                : optionName;
        };

        const updateGeneratedName = function () {
            const nameParts = [
                selectedOptionName('wearer_option_id'),
                selectedOptionName('primary_color_option_id').toLocaleLowerCase(),
                selectedOptionName('asset_type_id').toLocaleLowerCase(),
            ].filter(Boolean);
            let displaySize = selectedOptionName('size_option_id');
            const exactLabel = exactSizeLabel.value.trim();
            const length = selectedOptionName('length_option_id');

            if (exactLabel) {
                displaySize = displaySize
                    && displaySize.toLocaleLowerCase() !== exactLabel.toLocaleLowerCase()
                    ? displaySize + ' (' + exactLabel + ')'
                    : exactLabel;
            }

            let generatedName = nameParts.length > 0
                ? nameParts.join(' ')
                : 'Unclassified item';

            generatedName += ' — Size: ' + (displaySize || 'Not recorded');

            if (length) {
                generatedName += '; Length: ' + length;
            }

            generatedNamePreview.textContent = generatedName;
        };

        controlledSelects.forEach(function (select) {
            const suggestionPanel = document.getElementById(
                select.dataset.suggestionPanel
            );
            const suggestionInput = suggestionPanel.querySelector('input');

            const updateSuggestionPanel = function () {
                const needsSuggestion = select.value === '__other__';
                suggestionPanel.hidden = !needsSuggestion;
                suggestionInput.required = needsSuggestion;
                updateGeneratedName();
            };

            select.addEventListener('change', updateSuggestionPanel);
            updateSuggestionPanel();
        });

        exactSizeLabel.addEventListener('input', updateGeneratedName);
        updateGeneratedName();

        const tagSearch = document.getElementById('tag-search');
        const tagChoices = document.getElementById('tag-choices');
        const tagSelectionSummary = document.getElementById('tag-selection-summary');
        const noTagResults = document.getElementById('no-tag-results');

        if (tagSearch && tagChoices && tagSelectionSummary && noTagResults) {
            const tagLabels = Array.from(tagChoices.querySelectorAll('label'));

            const updateTagSelection = function () {
                const checkedTags = tagLabels.filter(function (label) {
                    return label.querySelector('input').checked;
                });

                if (checkedTags.length === 0) {
                    tagSelectionSummary.textContent = 'No attributes selected.';
                    return;
                }

                const names = checkedTags.map(function (label) {
                    return label.querySelector('span').textContent.trim();
                });

                tagSelectionSummary.textContent = names.length
                    + (names.length === 1 ? ' attribute selected: ' : ' attributes selected: ')
                    + names.join(', ');
            };

            const filterTags = function () {
                const query = tagSearch.value.trim().toLocaleLowerCase();
                let visibleCount = 0;

                tagLabels.forEach(function (label) {
                    const checkbox = label.querySelector('input');
                    const matches = label.dataset.tagName.includes(query);
                    const shouldShow = query === '' || matches || checkbox.checked;
                    label.hidden = !shouldShow;

                    if (shouldShow) {
                        visibleCount += 1;
                    }
                });

                noTagResults.hidden = visibleCount !== 0;
            };

            tagSearch.addEventListener('input', filterTags);
            tagChoices.addEventListener('change', function () {
                updateTagSelection();
                filterTags();
            });
            updateTagSelection();
        }
    </script>
</main>
</body>
</html>
