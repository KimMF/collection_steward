<?php

declare(strict_types=1);

require __DIR__ . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability($connection, 'intake');
$csrfToken = collectionStewardCsrfToken();

$categoryStatement = $connection->query(
    'SELECT id, name
     FROM asset_categories
     WHERE is_active = 1
     ORDER BY name'
);
$categories = $categoryStatement->fetchAll();

$tagStatement = $connection->query(
    'SELECT id, name
     FROM tags
     WHERE is_active = 1
     ORDER BY name'
);
$availableTags = $tagStatement->fetchAll();

$values = [
    'name' => '',
    'category_id' => '',
    'size_description' => '',
    'color' => '',
    'description' => '',
    'received_date' => date('Y-m-d'),
    'storage_location' => 'WBS intake rack',
    'notes' => '',
];

$errors = [];
$selectedTagIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $field) {
        if (is_string($_POST[$field] ?? null)) {
            $values[$field] = trim($_POST[$field]);
        }
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

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    if ($values['name'] === '') {
        $errors[] = 'Enter a short name for the item.';
    } elseif (strlen($values['name']) > 150) {
        $errors[] = 'The item name must be 150 characters or fewer.';
    }

    $categoryId = null;
    if ($values['category_id'] !== '') {
        $categoryId = filter_var(
            $values['category_id'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($categoryId === false) {
            $errors[] = 'Choose a valid category or leave it unsure.';
            $categoryId = null;
        } else {
            $validCategoryStatement = $connection->prepare(
                'SELECT 1
                 FROM asset_categories
                 WHERE id = :category_id
                   AND is_active = 1
                 LIMIT 1'
            );
            $validCategoryStatement->execute([
                'category_id' => $categoryId,
            ]);

            if ($validCategoryStatement->fetchColumn() === false) {
                $errors[] = 'The selected category is no longer available.';
                $categoryId = null;
            }
        }
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
                    category_id,
                    name,
                    description,
                    storage_location,
                    color,
                    size_description,
                    availability_status,
                    notes,
                    received_date,
                    acquisition_type,
                    created_by,
                    updated_by
                 ) VALUES (
                    :category_id,
                    :name,
                    :description,
                    :storage_location,
                    :color,
                    :size_description,
                    :availability_status,
                    :notes,
                    :received_date,
                    :acquisition_type,
                    :created_by,
                    :updated_by
                 )'
            );

            $insertAssetStatement->execute([
                'category_id' => $categoryId,
                'name' => $values['name'],
                'description' => $values['description'] !== '' ? $values['description'] : null,
                'storage_location' => $values['storage_location'] !== '' ? $values['storage_location'] : null,
                'color' => $values['color'] !== '' ? $values['color'] : null,
                'size_description' => $values['size_description'] !== '' ? $values['size_description'] : null,
                'availability_status' => 'available',
                'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                'received_date' => $receivedDate,
                'acquisition_type' => 'donation',
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

            if ($photoDetails !== null) {
                $assetPhotoDirectory = __DIR__ . '/uploads/assets/' . $assetId;

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
                    'caption' => $values['name'],
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

$createdAsset = null;
$createdAssetTags = [];
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
            c.name AS category,
            p.file_path,
            p.caption
         FROM assets AS a
         LEFT JOIN asset_categories AS c
            ON c.id = a.category_id
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
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add incoming donation — Collection Steward</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
<main class="intake-page">
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php" aria-current="page">Intake</a>
        <?php if (collectionStewardUserCan($currentUser, 'checkout')): ?>
            <a href="/checkout.php">Production checkout</a>
        <?php endif; ?>
        <?php if (collectionStewardUserCan($currentUser, 'manage_users')): ?>
            <a href="/users.php">Users</a>
        <?php endif; ?>
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
    <p class="required-note">Only the item name is required. Record what is known and leave uncertain information blank.</p>

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
                    <h2 id="saved-asset-title"><?= collectionStewardEscape($createdAsset['name']) ?></h2>
                    <dl class="asset-facts">
                        <div><dt>Asset ID</dt><dd><?= (int) $createdAsset['id'] ?></dd></div>
                        <div><dt>Type</dt><dd><?= collectionStewardEscape($createdAsset['category'] ?? 'Unassigned') ?></dd></div>
                        <?php if (!empty($createdAsset['size_description'])): ?>
                            <div><dt>Size</dt><dd><?= collectionStewardEscape($createdAsset['size_description']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($createdAsset['color'])): ?>
                            <div><dt>Color</dt><dd><?= collectionStewardEscape($createdAsset['color']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($createdAsset['storage_location'])): ?>
                            <div><dt>Location</dt><dd><?= collectionStewardEscape($createdAsset['storage_location']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($createdAssetTags !== []): ?>
                            <div><dt>Tags</dt><dd><?= collectionStewardEscape(implode(', ', $createdAssetTags)) ?></dd></div>
                        <?php endif; ?>
                    </dl>
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
            <label for="name">What is the item? *</label>
            <input
                type="text"
                id="name"
                name="name"
                maxlength="150"
                value="<?= collectionStewardEscape($values['name']) ?>"
                placeholder="For example: Men's black tuxedo"
                required
                autofocus
            >
        </div>

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

        <div class="field">
            <label for="category_id">What general category is it?</label>
            <select id="category_id" name="category_id">
                <option value="">Unsure — leave for costumer review</option>
                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= (string) $category['id'] === $values['category_id'] ? 'selected' : '' ?>
                    >
                        <?= collectionStewardEscape($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="size_description">What size is shown on the item?</label>
            <input
                type="text"
                id="size_description"
                name="size_description"
                maxlength="100"
                value="<?= collectionStewardEscape($values['size_description']) ?>"
                placeholder="For example: 42L, Medium, or No size label"
            >
        </div>

        <div class="field">
            <label for="color">What color is it?</label>
            <input
                type="text"
                id="color"
                name="color"
                maxlength="100"
                value="<?= collectionStewardEscape($values['color']) ?>"
            >
        </div>

        <?php if ($availableTags !== []): ?>
            <fieldset class="field choices tag-selector">
                <legend>Do any existing tags apply?</legend>
                <span class="help">Leave these blank if you are unsure.</span>
                <label class="tag-search-label" for="tag-search">Search tags</label>
                <input
                    type="search"
                    id="tag-search"
                    placeholder="Begin typing a tag name"
                    autocomplete="off"
                >
                <p id="tag-selection-summary" class="tag-selection-summary" aria-live="polite">No tags selected.</p>
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
                <p id="no-tag-results" class="help" hidden>No existing tags match that search.</p>
            </fieldset>
        <?php endif; ?>

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
                    tagSelectionSummary.textContent = 'No tags selected.';
                    return;
                }

                const names = checkedTags.map(function (label) {
                    return label.querySelector('span').textContent.trim();
                });

                tagSelectionSummary.textContent = names.length
                    + (names.length === 1 ? ' tag selected: ' : ' tags selected: ')
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
