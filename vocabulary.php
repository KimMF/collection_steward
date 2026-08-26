<?php

declare(strict_types=1);

require __DIR__ . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'manage_vocabulary'
);
$csrfToken = collectionStewardCsrfToken();

$vocabularies = [
    'asset_type' => [
        'label' => 'Item type',
        'table' => 'asset_types',
        'asset_column' => 'asset_type_id',
        'order_by' => 'display_order, name',
    ],
    'wearer' => [
        'label' => 'Wearer',
        'table' => 'wearer_options',
        'asset_column' => 'wearer_option_id',
        'order_by' => 'display_order, name',
    ],
    'primary_color' => [
        'label' => 'Primary color',
        'table' => 'color_options',
        'asset_column' => 'primary_color_option_id',
        'order_by' => 'display_order, name',
    ],
    'size' => [
        'label' => 'Size',
        'table' => 'size_options',
        'asset_column' => 'size_option_id',
        'order_by' => 'display_order, name',
    ],
    'length' => [
        'label' => 'Length',
        'table' => 'length_options',
        'asset_column' => 'length_option_id',
        'order_by' => 'display_order, name',
    ],
    'tag' => [
        'label' => 'Other attribute',
        'table' => 'tags',
        'asset_column' => null,
        'order_by' => 'name',
    ],
];

$errors = [];
$notice = isset($_GET['resolved'])
    ? 'The vocabulary suggestion was reviewed. The related asset was updated when applicable.'
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    $suggestionId = filter_input(
        INPUT_POST,
        'suggestion_id',
        FILTER_VALIDATE_INT
    );
    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action']
        : '';

    if (!is_int($suggestionId) || $suggestionId < 1) {
        $errors[] = 'Choose a valid suggestion.';
    }

    if (!in_array($action, ['match_existing', 'approve_new', 'dismiss'], true)) {
        $errors[] = 'Choose a valid review action.';
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            $suggestionStatement = $connection->prepare(
                "SELECT id, asset_id, vocabulary_type, suggested_value
                 FROM vocabulary_suggestions
                 WHERE id = :suggestion_id
                   AND status = 'pending'
                 LIMIT 1
                 FOR UPDATE"
            );
            $suggestionStatement->execute([
                'suggestion_id' => $suggestionId,
            ]);
            $suggestion = $suggestionStatement->fetch();

            if ($suggestion === false) {
                throw new DomainException('That pending suggestion was not found.');
            }

            $vocabularyType = (string) $suggestion['vocabulary_type'];
            $configuration = $vocabularies[$vocabularyType] ?? null;

            if ($configuration === null) {
                throw new DomainException('That suggestion uses an unsupported vocabulary.');
            }

            if ($action === 'dismiss') {
                $dismissStatement = $connection->prepare(
                    "UPDATE vocabulary_suggestions
                     SET status = 'dismissed',
                         reviewed_by_user_id = :reviewed_by_user_id,
                         reviewed_at = CURRENT_TIMESTAMP,
                         review_note = 'Dismissed without changing the asset'
                     WHERE id = :suggestion_id"
                );
                $dismissStatement->execute([
                    'reviewed_by_user_id' => (int) $currentUser['id'],
                    'suggestion_id' => $suggestionId,
                ]);
            } else {
                $resolvedOptionId = null;
                $resolvedOptionName = null;
                $resolutionStatus = 'matched';

                if ($action === 'match_existing') {
                    $optionId = filter_input(
                        INPUT_POST,
                        'option_id',
                        FILTER_VALIDATE_INT
                    );

                    if (!is_int($optionId) || $optionId < 1) {
                        throw new DomainException('Choose an existing approved option.');
                    }

                    $optionStatement = $connection->prepare(
                        'SELECT id, name
                         FROM ' . $configuration['table'] . '
                         WHERE id = :option_id
                           AND is_active = 1
                         LIMIT 1'
                    );
                    $optionStatement->execute([
                        'option_id' => $optionId,
                    ]);
                    $option = $optionStatement->fetch();

                    if ($option === false) {
                        throw new DomainException('The selected approved option was not found.');
                    }

                    $resolvedOptionId = (int) $option['id'];
                    $resolvedOptionName = (string) $option['name'];
                }

                if ($action === 'approve_new') {
                    $canonicalName = is_string($_POST['canonical_name'] ?? null)
                        ? preg_replace('/\s+/u', ' ', trim($_POST['canonical_name']))
                        : '';
                    $canonicalName = is_string($canonicalName)
                        ? trim($canonicalName)
                        : '';

                    if ($canonicalName === '' || strlen($canonicalName) > 100) {
                        throw new DomainException('Enter an approved name of 100 characters or fewer.');
                    }

                    $existingStatement = $connection->prepare(
                        'SELECT id, name
                         FROM ' . $configuration['table'] . '
                         WHERE name = :name
                         LIMIT 1'
                    );
                    $existingStatement->execute([
                        'name' => $canonicalName,
                    ]);
                    $existingOption = $existingStatement->fetch();

                    if ($existingOption !== false) {
                        $resolvedOptionId = (int) $existingOption['id'];
                        $resolvedOptionName = (string) $existingOption['name'];

                        $reactivateStatement = $connection->prepare(
                            'UPDATE ' . $configuration['table'] . '
                             SET is_active = 1
                             WHERE id = :option_id'
                        );
                        $reactivateStatement->execute([
                            'option_id' => $resolvedOptionId,
                        ]);
                    } else {
                        $insertOptionStatement = $connection->prepare(
                            'INSERT INTO ' . $configuration['table'] . ' (name)
                             VALUES (:name)'
                        );
                        $insertOptionStatement->execute([
                            'name' => $canonicalName,
                        ]);
                        $resolvedOptionId = (int) $connection->lastInsertId();
                        $resolvedOptionName = $canonicalName;
                        $resolutionStatus = 'approved';
                    }
                }

                if ($resolvedOptionId === null || $resolvedOptionName === null) {
                    throw new DomainException('The vocabulary option could not be resolved.');
                }

                if ($vocabularyType === 'tag') {
                    $assignTagStatement = $connection->prepare(
                        'INSERT IGNORE INTO asset_tags (asset_id, tag_id)
                         VALUES (:asset_id, :tag_id)'
                    );
                    $assignTagStatement->execute([
                        'asset_id' => (int) $suggestion['asset_id'],
                        'tag_id' => $resolvedOptionId,
                    ]);
                } else {
                    $updateAssetStatement = $connection->prepare(
                        'UPDATE assets
                         SET ' . $configuration['asset_column'] . ' = :option_id
                         WHERE id = :asset_id'
                    );
                    $updateAssetStatement->execute([
                        'option_id' => $resolvedOptionId,
                        'asset_id' => (int) $suggestion['asset_id'],
                    ]);
                }

                if ($vocabularyType !== 'tag') {
                    collectionStewardRefreshAssetName(
                        $connection,
                        (int) $suggestion['asset_id'],
                        (string) $currentUser['display_name']
                    );
                }

                $resolveStatement = $connection->prepare(
                    'UPDATE vocabulary_suggestions
                     SET status = :status,
                         reviewed_by_user_id = :reviewed_by_user_id,
                         reviewed_at = CURRENT_TIMESTAMP,
                         review_note = :review_note
                     WHERE id = :suggestion_id'
                );
                $resolveStatement->execute([
                    'status' => $resolutionStatus,
                    'reviewed_by_user_id' => (int) $currentUser['id'],
                    'review_note' => 'Resolved as ' . $resolvedOptionName,
                    'suggestion_id' => $suggestionId,
                ]);
            }

            $connection->commit();
            header('Location: /vocabulary.php?resolved=1');
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

            $errors[] = 'The suggestion review could not be saved.';
        }
    }
}

$optionsByVocabulary = [];
foreach ($vocabularies as $vocabularyType => $configuration) {
    $optionStatement = $connection->query(
        'SELECT id, name
         FROM ' . $configuration['table'] . '
         WHERE is_active = 1
         ORDER BY ' . $configuration['order_by']
    );
    $optionsByVocabulary[$vocabularyType] = $optionStatement->fetchAll();
}

$pendingSuggestionStatement = $connection->query(
    "SELECT
        vs.id,
        vs.asset_id,
        vs.vocabulary_type,
        vs.suggested_value,
        vs.created_at,
        a.name AS asset_name,
        p.file_path,
        u.display_name AS submitted_by
     FROM vocabulary_suggestions AS vs
     JOIN assets AS a
        ON a.id = vs.asset_id
     LEFT JOIN asset_photos AS p
        ON p.asset_id = a.id
        AND p.is_primary = 1
     LEFT JOIN users AS u
        ON u.id = vs.submitted_by_user_id
     WHERE vs.status = 'pending'
     ORDER BY vs.created_at, vs.id"
);
$pendingSuggestions = $pendingSuggestionStatement->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vocabulary review — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260825-4">
</head>
<body>
<main>
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/checkout.php">Production checkout</a>
        <a href="/vocabulary.php" aria-current="page">Vocabulary</a>
        <?php if (collectionStewardUserCan($currentUser, 'manage_users')): ?>
            <a href="/users.php">Users</a>
        <?php endif; ?>
        <a href="/change-password.php">Password</a>
    </nav>

    <h1>Vocabulary review</h1>
    <p>Review “Not listed” suggestions without allowing intake users to change the approved lists directly.</p>

    <?php if ($notice !== null): ?>
        <div class="notice" role="status"><?= collectionStewardEscape($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The review was not saved.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($pendingSuggestions === []): ?>
        <p>No vocabulary suggestions are awaiting review.</p>
    <?php else: ?>
        <p><strong><?= count($pendingSuggestions) ?></strong> suggestion<?= count($pendingSuggestions) === 1 ? '' : 's' ?> awaiting review.</p>

        <div class="suggestion-list">
            <?php foreach ($pendingSuggestions as $suggestion): ?>
                <?php
                $configuration = $vocabularies[$suggestion['vocabulary_type']] ?? null;
                $options = $optionsByVocabulary[$suggestion['vocabulary_type']] ?? [];
                ?>
                <?php if ($configuration !== null): ?>
                    <article class="suggestion-card">
                        <?php if (!empty($suggestion['file_path'])): ?>
                            <img src="<?= collectionStewardEscape($suggestion['file_path']) ?>" alt="" class="suggestion-thumbnail">
                        <?php endif; ?>
                        <div class="suggestion-details">
                            <p class="eyebrow"><?= collectionStewardEscape($configuration['label']) ?> suggestion</p>
                            <h2><?= collectionStewardEscape($suggestion['suggested_value']) ?></h2>
                            <p>
                                For <a href="/?asset_id=<?= (int) $suggestion['asset_id'] ?>#asset-record"><?= collectionStewardEscape(collectionStewardAssetLabel((int) $suggestion['asset_id'], $suggestion['asset_name'])) ?></a>
                            </p>
                            <?php if (!empty($suggestion['submitted_by'])): ?>
                                <p>Submitted by <?= collectionStewardEscape($suggestion['submitted_by']) ?></p>
                            <?php endif; ?>

                            <?php if ($options !== []): ?>
                                <form method="post" class="suggestion-action">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="suggestion_id" value="<?= (int) $suggestion['id'] ?>">
                                    <div class="field">
                                        <label for="option-<?= (int) $suggestion['id'] ?>">Use an existing approved option</label>
                                        <select id="option-<?= (int) $suggestion['id'] ?>" name="option_id">
                                            <option value="">Choose an option</option>
                                            <?php foreach ($options as $option): ?>
                                                <option value="<?= (int) $option['id'] ?>"><?= collectionStewardEscape($option['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" name="action" value="match_existing">Use existing option</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" class="suggestion-action">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="suggestion_id" value="<?= (int) $suggestion['id'] ?>">
                                <div class="field">
                                    <label for="canonical-<?= (int) $suggestion['id'] ?>">Approve a canonical name</label>
                                    <input
                                        type="text"
                                        id="canonical-<?= (int) $suggestion['id'] ?>"
                                        name="canonical_name"
                                        maxlength="100"
                                        value="<?= collectionStewardEscape($suggestion['suggested_value']) ?>"
                                    >
                                </div>
                                <button type="submit" name="action" value="approve_new">Approve new option</button>
                            </form>

                            <form method="post" class="suggestion-action" onsubmit="return confirm('Dismiss this suggestion without changing the asset?');">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="suggestion_id" value="<?= (int) $suggestion['id'] ?>">
                                <button type="submit" name="action" value="dismiss" class="secondary">Dismiss suggestion</button>
                            </form>
                        </div>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
