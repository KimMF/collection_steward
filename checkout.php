<?php

declare(strict_types=1);

require __DIR__ . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability($connection, 'checkout');
$csrfToken = collectionStewardCsrfToken();

$productionStatement = $connection->query(
    "SELECT id, name, opening_date, status
     FROM productions
     WHERE status IN ('planned', 'active')
     ORDER BY
        CASE WHEN status = 'active' THEN 0 ELSE 1 END,
        opening_date,
        name"
);
$productions = $productionStatement->fetchAll();

$requestedProductionId = filter_input(
    INPUT_GET,
    'production_id',
    FILTER_VALIDATE_INT
);

$productionId = null;
if (is_int($requestedProductionId) && $requestedProductionId > 0) {
    foreach ($productions as $productionChoice) {
        if ((int) $productionChoice['id'] === $requestedProductionId) {
            $productionId = $requestedProductionId;
            break;
        }
    }
}

if ($productionId === null && $productions !== []) {
    $productionId = (int) $productions[0]['id'];
}

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    $postedProductionId = filter_input(
        INPUT_POST,
        'production_id',
        FILTER_VALIDATE_INT
    );

    $validPostedProductionId = null;
    if (is_int($postedProductionId) && $postedProductionId > 0) {
        foreach ($productions as $productionChoice) {
            if ((int) $productionChoice['id'] === $postedProductionId) {
                $validPostedProductionId = $postedProductionId;
                break;
            }
        }
    }

    if ($validPostedProductionId === null) {
        $errors[] = 'Choose an active production.';
    } else {
        $productionId = $validPostedProductionId;
    }

    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action']
        : '';

    if ($errors === [] && $action === 'add_cast_member') {
        $actorName = is_string($_POST['actor_name'] ?? null)
            ? trim($_POST['actor_name'])
            : '';
        $characterName = is_string($_POST['character_name'] ?? null)
            ? trim($_POST['character_name'])
            : '';

        if ($actorName === '' || $characterName === '') {
            $errors[] = 'Enter both the actor and character names.';
        } elseif (strlen($actorName) > 150 || strlen($characterName) > 150) {
            $errors[] = 'Actor and character names must be 150 characters or fewer.';
        } else {
            try {
                $connection->beginTransaction();

                $personStatement = $connection->prepare(
                    'SELECT id
                     FROM people
                     WHERE display_name = :display_name
                     LIMIT 1'
                );
                $personStatement->execute([
                    'display_name' => $actorName,
                ]);
                $personId = $personStatement->fetchColumn();

                if ($personId === false) {
                    $insertPersonStatement = $connection->prepare(
                        'INSERT INTO people (display_name)
                         VALUES (:display_name)'
                    );
                    $insertPersonStatement->execute([
                        'display_name' => $actorName,
                    ]);
                    $personId = (int) $connection->lastInsertId();
                } else {
                    $personId = (int) $personId;
                }

                $duplicateCastStatement = $connection->prepare(
                    'SELECT id
                     FROM production_cast
                     WHERE production_id = :production_id
                       AND person_id = :person_id
                       AND character_name = :character_name
                     LIMIT 1'
                );
                $duplicateCastStatement->execute([
                    'production_id' => $productionId,
                    'person_id' => $personId,
                    'character_name' => $characterName,
                ]);

                if ($duplicateCastStatement->fetchColumn() !== false) {
                    throw new DomainException('That actor and character are already in this cast.');
                }

                $displayOrderStatement = $connection->prepare(
                    'SELECT COALESCE(MAX(display_order), 0) + 1
                     FROM production_cast
                     WHERE production_id = :production_id'
                );
                $displayOrderStatement->execute([
                    'production_id' => $productionId,
                ]);
                $displayOrder = (int) $displayOrderStatement->fetchColumn();

                $insertCastStatement = $connection->prepare(
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
                $insertCastStatement->execute([
                    'production_id' => $productionId,
                    'person_id' => $personId,
                    'character_name' => $characterName,
                    'display_order' => $displayOrder,
                ]);

                $connection->commit();

                header(
                    'Location: /checkout.php?production_id='
                    . $productionId
                    . '&cast_added=1'
                );
                exit;
            } catch (DomainException $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = $error->getMessage();
            } catch (Throwable $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = 'The cast member could not be saved.';
            }
        }
    }

    if ($errors === [] && $action === 'checkout_asset') {
        $assetId = filter_input(INPUT_POST, 'asset_id', FILTER_VALIDATE_INT);
        $castId = filter_input(INPUT_POST, 'cast_id', FILTER_VALIDATE_INT);
        $notes = is_string($_POST['notes'] ?? null)
            ? trim($_POST['notes'])
            : '';

        if (
            !is_int($assetId)
            || $assetId < 1
            || !is_int($castId)
            || $castId < 1
        ) {
            $errors[] = 'Choose both an actor/character and an asset.';
        } else {
            try {
                $connection->beginTransaction();

                $validCastStatement = $connection->prepare(
                    'SELECT pc.id
                     FROM production_cast AS pc
                     WHERE pc.id = :cast_id
                       AND pc.production_id = :production_id
                     LIMIT 1'
                );
                $validCastStatement->execute([
                    'cast_id' => $castId,
                    'production_id' => $productionId,
                ]);

                if ($validCastStatement->fetchColumn() === false) {
                    throw new DomainException('Choose an actor and character from this production.');
                }

                $assetStatement = $connection->prepare(
                    'SELECT id, name
                     FROM assets
                     WHERE id = :asset_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $assetStatement->execute([
                    'asset_id' => $assetId,
                ]);
                $asset = $assetStatement->fetch();

                if ($asset === false) {
                    throw new DomainException('The selected asset was not found.');
                }

                $activeCheckoutStatement = $connection->prepare(
                    "SELECT id
                     FROM asset_checkouts
                     WHERE asset_id = :asset_id
                       AND status = 'active'
                     LIMIT 1"
                );
                $activeCheckoutStatement->execute([
                    'asset_id' => $assetId,
                ]);

                if ($activeCheckoutStatement->fetchColumn() !== false) {
                    throw new DomainException('That asset is already checked out.');
                }

                $insertCheckoutStatement = $connection->prepare(
                    "INSERT INTO asset_checkouts (
                        asset_id,
                        production_cast_id,
                        status,
                        notes,
                        checked_out_by_user_id
                     ) VALUES (
                        :asset_id,
                        :production_cast_id,
                        'active',
                        :notes,
                        :checked_out_by_user_id
                     )"
                );
                $insertCheckoutStatement->execute([
                    'asset_id' => $assetId,
                    'production_cast_id' => $castId,
                    'notes' => $notes !== '' ? $notes : null,
                    'checked_out_by_user_id' => (int) $currentUser['id'],
                ]);

                $updateAssetStatement = $connection->prepare(
                    "UPDATE assets
                     SET availability_status = 'checked_out',
                         updated_by = :updated_by
                     WHERE id = :asset_id"
                );
                $updateAssetStatement->execute([
                    'updated_by' => $currentUser['display_name'],
                    'asset_id' => $assetId,
                ]);

                $connection->commit();

                header(
                    'Location: /checkout.php?production_id='
                    . $productionId
                    . '&checked_out=1'
                );
                exit;
            } catch (DomainException $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = $error->getMessage();
            } catch (Throwable $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = 'The asset could not be checked out.';
            }
        }
    }

    if ($errors === [] && $action === 'cancel_checkout') {
        $checkoutId = filter_input(
            INPUT_POST,
            'checkout_id',
            FILTER_VALIDATE_INT
        );

        if (!is_int($checkoutId) || $checkoutId < 1) {
            $errors[] = 'Choose a valid checkout to undo.';
        } else {
            try {
                $connection->beginTransaction();

                $checkoutStatement = $connection->prepare(
                    "SELECT ac.asset_id
                     FROM asset_checkouts AS ac
                     JOIN production_cast AS pc
                        ON pc.id = ac.production_cast_id
                     WHERE ac.id = :checkout_id
                       AND pc.production_id = :production_id
                       AND ac.status = 'active'
                     LIMIT 1
                     FOR UPDATE"
                );
                $checkoutStatement->execute([
                    'checkout_id' => $checkoutId,
                    'production_id' => $productionId,
                ]);
                $assetId = $checkoutStatement->fetchColumn();

                if ($assetId === false) {
                    throw new DomainException('That active checkout was not found.');
                }

                $cancelStatement = $connection->prepare(
                    "UPDATE asset_checkouts
                     SET status = 'cancelled',
                         cancelled_by_user_id = :cancelled_by_user_id,
                         cancelled_at = CURRENT_TIMESTAMP
                     WHERE id = :checkout_id
                       AND status = 'active'"
                );
                $cancelStatement->execute([
                    'cancelled_by_user_id' => (int) $currentUser['id'],
                    'checkout_id' => $checkoutId,
                ]);

                $updateAssetStatement = $connection->prepare(
                    "UPDATE assets
                     SET availability_status = 'available',
                         updated_by = :updated_by
                     WHERE id = :asset_id"
                );
                $updateAssetStatement->execute([
                    'updated_by' => $currentUser['display_name'],
                    'asset_id' => (int) $assetId,
                ]);

                $connection->commit();

                header(
                    'Location: /checkout.php?production_id='
                    . $productionId
                    . '&checkout_cancelled=1'
                );
                exit;
            } catch (DomainException $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = $error->getMessage();
            } catch (Throwable $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = 'The checkout could not be undone.';
            }
        }
    }
}

if (isset($_GET['cast_added'])) {
    $notice = 'The actor and character were added.';
} elseif (isset($_GET['checked_out'])) {
    $notice = 'The asset was checked out.';
} elseif (isset($_GET['checkout_cancelled'])) {
    $notice = 'The mistaken checkout was undone.';
}

$currentProduction = null;
$castMembers = [];
$availableAssets = [];
$activeCheckouts = [];

if ($productionId !== null) {
    foreach ($productions as $productionChoice) {
        if ((int) $productionChoice['id'] === $productionId) {
            $currentProduction = $productionChoice;
            break;
        }
    }

    $castStatement = $connection->prepare(
        'SELECT
            pc.id,
            pc.character_name,
            p.display_name AS actor_name
         FROM production_cast AS pc
         JOIN people AS p
            ON p.id = pc.person_id
         WHERE pc.production_id = :production_id
         ORDER BY pc.display_order, pc.id'
    );
    $castStatement->execute([
        'production_id' => $productionId,
    ]);
    $castMembers = $castStatement->fetchAll();

    $availableAssetStatement = $connection->query(
        "SELECT a.id, a.name, a.size_description
         FROM assets AS a
         WHERE a.availability_status = 'available'
           AND NOT EXISTS (
            SELECT 1
            FROM asset_checkouts AS ac
            WHERE ac.asset_id = a.id
              AND ac.status = 'active'
         )
         ORDER BY a.name, a.id"
    );
    $availableAssets = $availableAssetStatement->fetchAll();

    $activeCheckoutStatement = $connection->prepare(
        "SELECT
            ac.id,
            ac.asset_id,
            ac.notes,
            ac.checked_out_at,
            a.name AS asset_name,
            a.size_description,
            pc.character_name,
            p.display_name AS actor_name,
            ap.file_path,
            u.display_name AS checked_out_by
         FROM asset_checkouts AS ac
         JOIN assets AS a
            ON a.id = ac.asset_id
         JOIN production_cast AS pc
            ON pc.id = ac.production_cast_id
         JOIN people AS p
            ON p.id = pc.person_id
         LEFT JOIN asset_photos AS ap
            ON ap.asset_id = a.id
            AND ap.is_primary = 1
         LEFT JOIN users AS u
            ON u.id = ac.checked_out_by_user_id
         WHERE pc.production_id = :production_id
           AND ac.status = 'active'
         ORDER BY pc.display_order, pc.id, a.name, a.id"
    );
    $activeCheckoutStatement->execute([
        'production_id' => $productionId,
    ]);
    $activeCheckouts = $activeCheckoutStatement->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production checkout — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260825-4">
</head>
<body>
<main>
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/checkout.php" aria-current="page">Production checkout</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_vocabulary')): ?>
            <a href="/vocabulary.php">Vocabulary</a>
        <?php endif; ?>
        <?php if (collectionStewardUserCan($currentUser, 'manage_users')): ?>
            <a href="/users.php">Users</a>
        <?php endif; ?>
        <a href="/change-password.php">Password</a>
    </nav>

    <h1>Production checkout</h1>
    <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>

    <?php if ($productions === []): ?>
        <div class="error">No planned or active production is available.</div>
    <?php else: ?>
        <form method="get" class="compact-form">
            <div class="field">
                <label for="production_id">Production</label>
                <select id="production_id" name="production_id" onchange="this.form.submit()">
                    <?php foreach ($productions as $productionChoice): ?>
                        <option
                            value="<?= (int) $productionChoice['id'] ?>"
                            <?= (int) $productionChoice['id'] === $productionId ? 'selected' : '' ?>
                        >
                            <?= collectionStewardEscape($productionChoice['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit">Choose production</button></noscript>
        </form>

        <?php if ($currentProduction !== null && !empty($currentProduction['opening_date'])): ?>
            <p>Opening date: <?= collectionStewardEscape($currentProduction['opening_date']) ?></p>
        <?php endif; ?>

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

        <section>
            <h2>Check out an asset</h2>
            <?php if ($castMembers === []): ?>
                <p>Add the cast before checking out assets.</p>
            <?php elseif ($availableAssets === []): ?>
                <p>No available asset records were found.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="checkout_asset">
                    <input type="hidden" name="production_id" value="<?= (int) $productionId ?>">

                    <div class="field">
                        <label for="cast_id">Who will wear it?</label>
                        <select id="cast_id" name="cast_id" required>
                            <option value="">Choose actor and character</option>
                            <?php foreach ($castMembers as $castMember): ?>
                                <option value="<?= (int) $castMember['id'] ?>">
                                    <?= collectionStewardEscape($castMember['actor_name']) ?>
                                    — <?= collectionStewardEscape($castMember['character_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="asset_id">Which collection asset?</label>
                        <select id="asset_id" name="asset_id" required>
                            <option value="">Choose an asset</option>
                            <?php foreach ($availableAssets as $assetChoice): ?>
                                <option value="<?= (int) $assetChoice['id'] ?>">
                                    <?= collectionStewardEscape(collectionStewardAssetLabel((int) $assetChoice['id'], $assetChoice['name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help">The asset must already have a record. Blob items may be added through phpMyAdmin during this pilot.</span>
                    </div>

                    <div class="field">
                        <label for="notes">Checkout note</label>
                        <input
                            type="text"
                            id="notes"
                            name="notes"
                            maxlength="500"
                            placeholder="Optional: scene, costume look, or alteration note"
                        >
                    </div>

                    <button type="submit">Check out asset</button>
                </form>
            <?php endif; ?>
        </section>

        <section>
            <h2>Currently checked out</h2>
            <?php if ($activeCheckouts === []): ?>
                <p>No assets are currently checked out to this production.</p>
            <?php else: ?>
                <p><strong><?= count($activeCheckouts) ?></strong> collection assets assigned.</p>
                <div class="checkout-list">
                    <?php foreach ($activeCheckouts as $checkout): ?>
                        <article class="checkout-card">
                            <?php if (!empty($checkout['file_path'])): ?>
                                <img
                                    src="<?= collectionStewardEscape($checkout['file_path']) ?>"
                                    alt=""
                                    class="checkout-thumbnail"
                                >
                            <?php endif; ?>
                            <div class="checkout-details">
                                <h3>
                                    <?= collectionStewardEscape($checkout['actor_name']) ?>
                                    — <?= collectionStewardEscape($checkout['character_name']) ?>
                                </h3>
                                <p>
                                    <a href="/?asset_id=<?= (int) $checkout['asset_id'] ?>">
                                        <?= collectionStewardEscape(collectionStewardAssetLabel((int) $checkout['asset_id'], $checkout['asset_name'])) ?>
                                    </a>
                                </p>
                                <?php if (!empty($checkout['size_description'])): ?>
                                    <p>Size: <?= collectionStewardEscape($checkout['size_description']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($checkout['notes'])): ?>
                                    <p><?= collectionStewardEscape($checkout['notes']) ?></p>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Undo this checkout?');">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="cancel_checkout">
                                    <input type="hidden" name="production_id" value="<?= (int) $productionId ?>">
                                    <input type="hidden" name="checkout_id" value="<?= (int) $checkout['id'] ?>">
                                    <button type="submit" class="secondary">Undo mistaken checkout</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <details class="setup-panel">
            <summary>Set up this production's cast</summary>
            <?php if ($castMembers !== []): ?>
                <ul>
                    <?php foreach ($castMembers as $castMember): ?>
                        <li>
                            <?= collectionStewardEscape($castMember['actor_name']) ?>
                            — <?= collectionStewardEscape($castMember['character_name']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="add_cast_member">
                <input type="hidden" name="production_id" value="<?= (int) $productionId ?>">

                <div class="field">
                    <label for="actor_name">Actor's name</label>
                    <input type="text" id="actor_name" name="actor_name" maxlength="150" required>
                </div>

                <div class="field">
                    <label for="character_name">Character's name</label>
                    <input type="text" id="character_name" name="character_name" maxlength="150" required>
                </div>

                <button type="submit">Add actor and character</button>
            </form>
        </details>
    <?php endif; ?>
</main>
</body>
</html>
