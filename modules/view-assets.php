<?php

declare(strict_types=1);

/**
 * Asset browsing, sign-in, and asset-record maintenance.
 *
 * Public entry point: /index.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

// Sign out before loading any page data so the request cannot continue as the
// former user.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'logout'
) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
    header('Location: /');
    exit;
}

// Page state is initialized before database access so connection failures can
// still render a useful error page.
$asset = null;
$assetChoices = [];
$assetsByType = [];
$assignedTags = [];
$availableTags = [];
$strikeActions = [];
$activeCheckout = null;
$lastAssetReview = null;
$latestLifecycleEvent = null;
$currentUser = null;
$canManageAssets = false;
$canManageProductions = false;
$canManageUsers = false;
$canManageVocabulary = false;
$canUseIntake = false;
$canUseCheckout = false;
$canUseMeasurements = false;
$loginError = null;
$errorMessage = null;
$assetManagementError = null;
$assetId = null;
$csrfToken = null;
$pendingAssetReviewCount = 0;
$retiredAssetCount = 0;
$includeRetiredAssets = false;
$retiredAssetId = filter_var(
    $_GET['asset_retired'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$retirementDisposition = '';
$retirementDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$retirementNote = '';

// A valid URL choice takes precedence over the saved cookie. New visitors use
// the Compact layout unless they deliberately select Expanded.
$requestedAssetListMode = is_string($_GET['asset_view'] ?? null)
    ? $_GET['asset_view']
    : '';
$savedAssetListMode = is_string(
    $_COOKIE['collection_steward_asset_list_view'] ?? null
)
    ? $_COOKIE['collection_steward_asset_list_view']
    : '';

if (in_array($requestedAssetListMode, ['compact', 'expanded'], true)) {
    $assetListMode = $requestedAssetListMode;
    setcookie('collection_steward_asset_list_view', $assetListMode, [
        'expires' => time() + (86400 * 365),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} elseif (in_array($savedAssetListMode, ['compact', 'expanded'], true)) {
    $assetListMode = $savedAssetListMode;
} else {
    $assetListMode = 'compact';
}

$strikeActionChoices = [
    'Launder',
    'Repair',
    'Return to owner/lender',
    'Return to storage',
];

// Load authentication state, browser rows, the selected asset, and any
// privileged maintenance data needed by the page.
try {
    $connection = collectionStewardConnection();
    $currentUser = collectionStewardCurrentUser($connection);

    if (
        $currentUser === null
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'login'
    ) {
        $username = is_string($_POST['username'] ?? null)
            ? trim($_POST['username'])
            : '';
        $password = is_string($_POST['password'] ?? null)
            ? $_POST['password']
            : '';

        if ($username === '' || $password === '') {
            $loginError = 'Enter both username and password.';
        } else {
            $loginStatement = $connection->prepare(
                'SELECT id, username, display_name, role, password_hash
                 FROM users
                 WHERE username = :username
                   AND is_active = 1
                 LIMIT 1'
            );
            $loginStatement->execute([
                'username' => $username,
            ]);
            $user = $loginStatement->fetch();

            if (
                $user !== false
                && password_verify($password, $user['password_hash'])
            ) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                header('Location: /');
                exit;
            }

            $loginError = 'Invalid username or password.';
        }
    }

    if ($currentUser !== null) {
        $canManageAssets = collectionStewardUserCan(
            $currentUser,
            'manage_assets'
        );
        $canManageProductions = collectionStewardUserCan(
            $currentUser,
            'manage_productions'
        );
        $canManageUsers = collectionStewardUserCan(
            $currentUser,
            'manage_users'
        );
        $canManageVocabulary = collectionStewardUserCan(
            $currentUser,
            'manage_vocabulary'
        );
        $canUseIntake = collectionStewardUserCan($currentUser, 'intake');
        $canUseCheckout = collectionStewardUserCan($currentUser, 'checkout');
        $canUseMeasurements = collectionStewardUserCan(
            $currentUser,
            'measurements'
        );
        $csrfToken = collectionStewardCsrfToken();
    }

    if ($canManageAssets) {
        $savedRetiredFilter = is_string(
            $_COOKIE['collection_steward_include_retired'] ?? null
        )
            ? $_COOKIE['collection_steward_include_retired']
            : '0';

        if (isset($_GET['retired_filter'])) {
            $includeRetiredAssets = ($_GET['include_retired'] ?? '') === '1';
            setcookie(
                'collection_steward_include_retired',
                $includeRetiredAssets ? '1' : '0',
                [
                    'expires' => time() + (86400 * 365),
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS'])
                        && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        } else {
            $includeRetiredAssets = $savedRetiredFilter === '1';
        }

        $retiredAssetCount = (int) $connection->query(
            "SELECT COUNT(*)
             FROM assets
             WHERE collection_status = 'retired'"
        )->fetchColumn();
    }

    $collectionStatusFilter = $includeRetiredAssets
        ? "a.collection_status IN ('active', 'retired')"
        : "a.collection_status = 'active'";

    $assetChoiceStatement = $connection->query(
        "SELECT
            a.id,
            a.name,
            a.description,
            a.storage_location,
            COALESCE(co.name, a.color) AS color,
            a.size_description,
            a.availability_status,
            a.collection_status,
            COALESCE(aty.name, 'Unassigned') AS asset_type,
            wo.name AS wearer,
            lo.name AS length_name,
            p.file_path,
            (
                SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                FROM asset_tags AS at
                JOIN tags AS t
                    ON t.id = at.tag_id
                WHERE at.asset_id = a.id
            ) AS tags
         FROM assets AS a
         LEFT JOIN asset_types AS aty
            ON aty.id = a.asset_type_id
         LEFT JOIN wearer_options AS wo
            ON wo.id = a.wearer_option_id
         LEFT JOIN color_options AS co
            ON co.id = a.primary_color_option_id
         LEFT JOIN length_options AS lo
            ON lo.id = a.length_option_id
         LEFT JOIN asset_photos AS p
            ON p.asset_id = a.id
            AND p.is_primary = 1
         WHERE " . $collectionStatusFilter . "
         ORDER BY asset_type, a.name, a.id"
    );
    $assetChoices = $assetChoiceStatement->fetchAll();

    foreach ($assetChoices as $assetIndex => $assetChoice) {
        $assetChoice['display_label'] = collectionStewardAssetLabel(
            (int) $assetChoice['id'],
            $assetChoice['name']
        );
        $assetChoices[$assetIndex] = $assetChoice;
        $typeName = (string) $assetChoice['asset_type'];
        $assetsByType[$typeName][] = $assetChoice;
    }

    $requestedAssetId = filter_input(
        INPUT_GET,
        'asset_id',
        FILTER_VALIDATE_INT
    );

    if (is_int($requestedAssetId) && $requestedAssetId > 0) {
        foreach ($assetChoices as $assetChoice) {
            if ((int) $assetChoice['id'] === $requestedAssetId) {
                $assetId = $requestedAssetId;
                break;
            }
        }
    }

    if ($assetId === null && $assetChoices !== []) {
        $assetId = (int) $assetChoices[0]['id'];
    }

    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action']
        : '';
    $assetManagementActions = [
        'assign_tag',
        'remove_tag',
        'add_strike_action',
        'complete_strike_action',
        'queue_asset_review',
        'retire_asset',
    ];

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array($action, $assetManagementActions, true)
    ) {
        if (!$canManageAssets || $currentUser === null) {
            http_response_code(403);
            exit('Your account cannot change asset records.');
        }

        if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            exit('The form expired. Return to the asset and try again.');
        }

        if ($assetId === null) {
            http_response_code(404);
            exit('The selected asset was not found.');
        }

        if ($action === 'retire_asset') {
            $retirementDisposition = is_string(
                $_POST['retirement_disposition'] ?? null
            )
                ? trim($_POST['retirement_disposition'])
                : '';
            $retirementDate = is_string($_POST['retirement_date'] ?? null)
                ? trim($_POST['retirement_date'])
                : '';
            $retirementNote = is_string($_POST['retirement_note'] ?? null)
                ? trim($_POST['retirement_note'])
                : '';

            if (($_POST['confirm_retirement'] ?? '') !== '1') {
                $assetManagementError = 'Confirm that this asset should be retired.';
            } else {
                try {
                    collectionStewardRetireAsset(
                        $connection,
                        $assetId,
                        $retirementDisposition,
                        $retirementDate,
                        $retirementNote,
                        $currentUser
                    );

                    header('Location: /?asset_retired=' . $assetId);
                    exit;
                } catch (DomainException $error) {
                    $assetManagementError = $error->getMessage();
                } catch (Throwable $error) {
                    $assetManagementError = 'The asset could not be retired.';
                }
            }
        }

        if ($action === 'assign_tag') {
            $tagId = filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);

            if (is_int($tagId) && $tagId > 0) {
                $validTagStatement = $connection->prepare(
                    'SELECT 1
                     FROM tags
                     WHERE id = :tag_id
                       AND is_active = 1
                     LIMIT 1'
                );
                $validTagStatement->execute([
                    'tag_id' => $tagId,
                ]);

                if ($validTagStatement->fetchColumn() !== false) {
                    $assignTagStatement = $connection->prepare(
                        'INSERT IGNORE INTO asset_tags (asset_id, tag_id)
                         VALUES (:asset_id, :tag_id)'
                    );
                    $assignTagStatement->execute([
                        'asset_id' => $assetId,
                        'tag_id' => $tagId,
                    ]);
                }
            }
        }

        if ($action === 'queue_asset_review') {
            $queueReviewStatement = $connection->prepare(
                "UPDATE assets
                 SET asset_review_status = 'pending',
                     asset_review_requested_at = CURRENT_TIMESTAMP,
                     asset_review_requested_by_user_id = :requested_by_user_id,
                     updated_by = :updated_by
                 WHERE id = :asset_id
                   AND collection_status = 'active'"
            );
            $queueReviewStatement->execute([
                'requested_by_user_id' => (int) $currentUser['id'],
                'updated_by' => $currentUser['display_name'],
                'asset_id' => $assetId,
            ]);

            header(
                'Location: /asset-review.php?asset_id=' . $assetId
            );
            exit;
        }

        if ($action === 'remove_tag') {
            $tagId = filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);

            if (is_int($tagId) && $tagId > 0) {
                $removeTagStatement = $connection->prepare(
                    'DELETE FROM asset_tags
                     WHERE asset_id = :asset_id
                       AND tag_id = :tag_id'
                );
                $removeTagStatement->execute([
                    'asset_id' => $assetId,
                    'tag_id' => $tagId,
                ]);
            }
        }

        if ($action === 'add_strike_action') {
            $actionNeeded = is_string($_POST['action_needed'] ?? null)
                ? trim($_POST['action_needed'])
                : '';
            $stagingLocation = is_string($_POST['staging_location'] ?? null)
                ? trim($_POST['staging_location'])
                : '';

            if (in_array($actionNeeded, $strikeActionChoices, true)) {
                $addStrikeActionStatement = $connection->prepare(
                    'INSERT INTO asset_strike_actions (
                        asset_id,
                        action_needed,
                        staging_location,
                        created_by_user_id,
                        updated_by_user_id
                     ) VALUES (
                        :asset_id,
                        :action_needed,
                        :staging_location,
                        :created_by_user_id,
                        :updated_by_user_id
                     )'
                );
                $addStrikeActionStatement->execute([
                    'asset_id' => $assetId,
                    'action_needed' => $actionNeeded,
                    'staging_location' => $stagingLocation !== ''
                        ? $stagingLocation
                        : null,
                    'created_by_user_id' => (int) $currentUser['id'],
                    'updated_by_user_id' => (int) $currentUser['id'],
                ]);
            }
        }

        if ($action === 'complete_strike_action') {
            $strikeActionId = filter_input(
                INPUT_POST,
                'strike_action_id',
                FILTER_VALIDATE_INT
            );

            if (is_int($strikeActionId) && $strikeActionId > 0) {
                $completeStrikeActionStatement = $connection->prepare(
                    "UPDATE asset_strike_actions
                     SET status = 'completed',
                         completed_at = CURRENT_TIMESTAMP,
                         updated_by_user_id = :updated_by_user_id
                     WHERE id = :strike_action_id
                       AND asset_id = :asset_id
                       AND status = 'pending'"
                );
                $completeStrikeActionStatement->execute([
                    'updated_by_user_id' => (int) $currentUser['id'],
                    'strike_action_id' => $strikeActionId,
                    'asset_id' => $assetId,
                ]);
            }
        }

        if ($assetManagementError === null) {
            header('Location: /?asset_id=' . $assetId . '#asset-record');
            exit;
        }
    }

    if ($assetId !== null) {
        $assetStatement = $connection->prepare(
            'SELECT
                a.id,
                a.name,
                a.description,
                a.storage_location,
                COALESCE(co.name, a.color) AS color,
                a.size_description,
                a.received_date,
                a.acquisition_type,
                a.asset_review_status,
                a.notes,
                a.availability_status,
                a.collection_status,
                aty.name AS asset_type,
                wo.name AS wearer,
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
             LEFT JOIN length_options AS lo
                ON lo.id = a.length_option_id
             LEFT JOIN asset_photos AS p
                ON p.asset_id = a.id
                AND p.is_primary = 1
             WHERE a.id = :asset_id
             LIMIT 1'
        );
        $assetStatement->execute([
            'asset_id' => $assetId,
        ]);
        $assetRecord = $assetStatement->fetch();
        $asset = $assetRecord !== false ? $assetRecord : null;
    }

    if ($asset !== null) {
        if (
            $currentUser !== null
            && $asset['availability_status'] === 'checked_out'
        ) {
            $activeCheckoutStatement = $connection->prepare(
                "SELECT
                    pr.name AS production_name,
                    pc.character_name,
                    pe.display_name AS actor_name
                 FROM asset_checkouts AS ac
                 JOIN production_cast AS pc
                    ON pc.id = ac.production_cast_id
                 JOIN productions AS pr
                    ON pr.id = pc.production_id
                 JOIN people AS pe
                    ON pe.id = pc.person_id
                 WHERE ac.asset_id = :asset_id
                   AND ac.status = 'active'
                 ORDER BY ac.checked_out_at DESC, ac.id DESC
                 LIMIT 1"
            );
            $activeCheckoutStatement->execute([
                'asset_id' => $assetId,
            ]);
            $activeCheckoutRecord = $activeCheckoutStatement->fetch();
            $activeCheckout = $activeCheckoutRecord !== false
                ? $activeCheckoutRecord
                : null;
        }

        $tagStatement = $connection->prepare(
            'SELECT t.id, t.name
             FROM tags AS t
             JOIN asset_tags AS at
                ON at.tag_id = t.id
             WHERE at.asset_id = :asset_id
             ORDER BY t.name'
        );
        $tagStatement->execute([
            'asset_id' => $assetId,
        ]);
        $assignedTags = $tagStatement->fetchAll();

        $strikeActionStatement = $connection->prepare(
            "SELECT id, action_needed, staging_location
             FROM asset_strike_actions
             WHERE asset_id = :asset_id
               AND status = 'pending'
             ORDER BY created_at, id"
        );
        $strikeActionStatement->execute([
            'asset_id' => $assetId,
        ]);
        $strikeActions = $strikeActionStatement->fetchAll();

        if ($canManageAssets) {
            $lastReviewStatement = $connection->prepare(
                'SELECT
                    acr.reviewed_at,
                    u.display_name AS reviewer_name
                 FROM asset_condition_reviews AS acr
                 LEFT JOIN users AS u
                    ON u.id = acr.reviewed_by_user_id
                 WHERE acr.asset_id = :asset_id
                 ORDER BY acr.reviewed_at DESC, acr.id DESC
                 LIMIT 1'
            );
            $lastReviewStatement->execute([
                'asset_id' => $assetId,
            ]);
            $lastAssetReview = $lastReviewStatement->fetch() ?: null;

            if ($asset['collection_status'] === 'retired') {
                $lifecycleStatement = $connection->prepare(
                    "SELECT
                        ale.disposition,
                        ale.effective_date,
                        ale.note,
                        ale.recorded_at,
                        u.display_name AS recorded_by
                     FROM asset_lifecycle_events AS ale
                     LEFT JOIN users AS u
                        ON u.id = ale.recorded_by_user_id
                     WHERE ale.asset_id = :asset_id
                       AND ale.event_type = 'retired'
                     ORDER BY ale.recorded_at DESC, ale.id DESC
                     LIMIT 1"
                );
                $lifecycleStatement->execute([
                    'asset_id' => $assetId,
                ]);
                $latestLifecycleEvent = $lifecycleStatement->fetch() ?: null;
            }
        }
    }

    if ($canManageAssets) {
        $pendingAssetReviewCount = (int) $connection->query(
            "SELECT COUNT(*)
             FROM assets
             WHERE asset_review_status = 'pending'
               AND collection_status = 'active'"
        )->fetchColumn();

        $availableTagStatement = $connection->query(
            'SELECT id, name
             FROM tags
             WHERE is_active = 1
             ORDER BY name'
        );
        $availableTags = $availableTagStatement->fetchAll();
    }
} catch (Throwable $error) {
    $errorMessage = 'The inventory records could not be loaded.';
}

// The browser preview uses the same rows already loaded for the server-rendered
// list. JSON_HEX options keep database text from becoming executable markup.
$assetBrowserJson = json_encode(
    $assetChoices,
    JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES
);

if (!is_string($assetBrowserJson)) {
    $assetBrowserJson = '[]';
}

// Render the asset browser and selected asset record.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260903-2">
</head>
<body>
<main>
    <header class="site-header">
        <div>
            <h1>Collection Steward</h1>
            <?php if ($currentUser !== null): ?>
                <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
            <?php endif; ?>
        </div>

        <?php if ($currentUser !== null): ?>
            <form method="post" class="sign-out-form">
                <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($currentUser !== null): ?>
        <nav aria-label="Collection Steward">
            <a href="/" aria-current="page">View assets</a>
            <?php if ($canUseIntake): ?>
                <a href="/intake.php">Intake</a>
            <?php endif; ?>
            <?php if ($canManageProductions): ?>
                <a href="/productions.php">Productions</a>
            <?php endif; ?>
            <?php if ($canUseCheckout): ?>
                <a href="/checkout.php">Production checkout</a>
                <a href="/fittings.php">Fittings</a>
            <?php endif; ?>
            <?php if ($canUseMeasurements): ?>
                <a href="/measurements.php">Measurements</a>
            <?php endif; ?>
            <?php if ($canManageAssets): ?>
                <a href="/asset-review.php">Asset review<?= $pendingAssetReviewCount > 0 ? ' (' . $pendingAssetReviewCount . ')' : '' ?></a>
            <?php endif; ?>
            <?php if ($canManageVocabulary): ?>
                <a href="/vocabulary.php">Vocabulary</a>
            <?php endif; ?>
            <?php if ($canManageUsers): ?>
                <a href="/users.php">Users</a>
            <?php endif; ?>
            <a href="/change-password.php">Password</a>
        </nav>
    <?php endif; ?>

    <?php if ($currentUser === null): ?>
        <section class="login-panel" aria-labelledby="login-title">
            <h2 id="login-title">Steward sign in</h2>
            <?php if ($loginError !== null): ?>
                <div class="error" role="alert"><?= collectionStewardEscape($loginError) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" autocomplete="username" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="submit" name="action" value="login">Sign in</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="error" role="alert"><?= collectionStewardEscape($errorMessage) ?></div>
    <?php elseif (
        $canManageAssets
        && $retiredAssetId !== false
        && $retiredAssetId !== null
    ): ?>
        <div class="notice" role="status">
            Asset <?= (int) $retiredAssetId ?> was retired. Retired assets are hidden whenever <strong>Include retired assets</strong> is unchecked.
        </div>
    <?php endif; ?>

    <?php if ($assetManagementError !== null): ?>
        <div class="error" role="alert"><?= collectionStewardEscape($assetManagementError) ?></div>
    <?php endif; ?>

    <?php if (
        $errorMessage === null
        && $assetChoices === []
        && $canManageAssets
    ): ?>
        <form method="get" action="/#asset-browser-title" class="asset-visibility-form">
            <input type="hidden" name="retired_filter" value="1">
            <label>
                <input
                    type="checkbox"
                    name="include_retired"
                    value="1"
                    <?= $includeRetiredAssets ? 'checked' : '' ?>
                    data-submit-on-change
                >
                Include retired assets<?= $retiredAssetCount > 0 ? ' (' . $retiredAssetCount . ')' : '' ?>
            </label>
            <noscript><button type="submit" class="secondary">Apply asset view</button></noscript>
        </form>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <?php // The database error was already displayed above. ?>
    <?php elseif ($assetChoices === []): ?>
        <p><?= $retiredAssetCount > 0 && !$includeRetiredAssets ? 'No active assets are visible.' : 'No asset records have been entered yet.' ?></p>
    <?php else: ?>
        <section class="asset-browser" aria-labelledby="asset-browser-title">
            <div class="section-heading">
                <h2 id="asset-browser-title" aria-live="polite">
                    Scroll through <?= count($assetChoices) ?>
                    <?= count($assetChoices) === 1 ? 'asset' : 'assets' ?>
                </h2>
                <div class="asset-browser-heading-actions">
                    <?php if ($canManageAssets): ?>
                        <form method="get" action="/#asset-browser-title" class="asset-list-mode-form">
                            <input type="hidden" name="retired_filter" value="1">
                            <?php if ($assetId !== null): ?>
                                <input type="hidden" name="asset_id" value="<?= $assetId ?>">
                            <?php endif; ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="include_retired"
                                    value="1"
                                    <?= $includeRetiredAssets ? 'checked' : '' ?>
                                    data-submit-on-change
                                >
                                Include retired assets<?= $retiredAssetCount > 0 ? ' (' . $retiredAssetCount . ')' : '' ?>
                            </label>
                            <noscript><button type="submit" class="secondary">Apply asset view</button></noscript>
                        </form>
                    <?php endif; ?>
                    <form method="get" action="/#asset-browser-title" class="asset-list-mode-form">
                        <?php if ($assetId !== null): ?>
                            <input type="hidden" name="asset_id" value="<?= $assetId ?>">
                        <?php endif; ?>
                        <input type="hidden" name="asset_view" value="expanded">
                        <label>
                            <input
                                type="checkbox"
                                name="asset_view"
                                value="compact"
                                <?= $assetListMode === 'compact' ? 'checked' : '' ?>
                                data-submit-on-change
                            >
                            Compact list (names only)
                        </label>
                        <noscript><button type="submit" class="secondary">Apply layout</button></noscript>
                    </form>
                </div>
            </div>

            <div class="browser-filters">
                <div class="field">
                    <label for="asset-search" class="visually-hidden">Search assets</label>
                    <input type="search" id="asset-search" placeholder="Name, ID, type, wearer, size, color, length, or attribute" autocomplete="off">
                </div>
                <div class="field">
                    <label for="type-filter">Type</label>
                    <select id="type-filter">
                        <option value="">All types</option>
                        <?php foreach (array_keys($assetsByType) as $typeName): ?>
                            <option value="<?= collectionStewardEscape(strtolower($typeName)) ?>">
                                <?= collectionStewardEscape($typeName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="asset-browser-layout">
                <div id="asset-browser-list" class="asset-browser-list <?= $assetListMode === 'compact' ? 'is-compact' : 'is-expanded' ?>" aria-label="Assets grouped by type">
                    <?php foreach ($assetsByType as $typeName => $typeAssets): ?>
                        <section class="asset-type-group" data-type="<?= collectionStewardEscape(strtolower($typeName)) ?>">
                            <h3><?= collectionStewardEscape($typeName) ?></h3>
                            <?php foreach ($typeAssets as $assetChoice): ?>
                                <?php
                                $searchText = strtolower(implode(' ', [
                                    (string) $assetChoice['id'],
                                    (string) $assetChoice['name'],
                                    (string) ($assetChoice['description'] ?? ''),
                                    (string) ($assetChoice['size_description'] ?? ''),
                                    (string) ($assetChoice['color'] ?? ''),
                                    (string) ($assetChoice['wearer'] ?? ''),
                                    (string) ($assetChoice['length_name'] ?? ''),
                                    (string) ($assetChoice['tags'] ?? ''),
                                    (string) $assetChoice['asset_type'],
                                    (string) $assetChoice['collection_status'],
                                ]));
                                ?>
                                <a
                                    href="/?asset_id=<?= (int) $assetChoice['id'] ?>#asset-record"
                                    class="asset-list-item <?= (int) $assetChoice['id'] === $assetId ? 'is-current' : '' ?> <?= $assetChoice['collection_status'] === 'retired' ? 'is-retired' : '' ?>"
                                    data-asset-id="<?= (int) $assetChoice['id'] ?>"
                                    data-search="<?= collectionStewardEscape($searchText) ?>"
                                >
                                    <?php if ($assetListMode === 'expanded'): ?>
                                        <?php if (!empty($assetChoice['file_path'])): ?>
                                            <img src="<?= collectionStewardEscape($assetChoice['file_path']) ?>" alt="" class="asset-list-thumbnail">
                                        <?php else: ?>
                                            <span class="asset-list-placeholder" aria-hidden="true">No photo</span>
                                        <?php endif; ?>
                                        <span>
                                            <strong>
                                                <?= collectionStewardEscape($assetChoice['display_label']) ?>
                                                <?php if ($assetChoice['collection_status'] === 'retired'): ?>
                                                    <span class="retired-marker">— Retired</span>
                                                <?php endif; ?>
                                            </strong>
                                            <?php if (!empty($assetChoice['size_description'])): ?>
                                                <small>Size <?= collectionStewardEscape($assetChoice['size_description']) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <strong>
                                            <?= collectionStewardEscape($assetChoice['display_label']) ?>
                                            <?php if ($assetChoice['collection_status'] === 'retired'): ?>
                                                <span class="retired-marker">— Retired</span>
                                            <?php endif; ?>
                                        </strong>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                    <p id="no-asset-results" class="empty-results" hidden>No assets match this search.</p>
                </div>

                <aside id="asset-preview" class="asset-preview" aria-live="polite">
                    <img id="preview-photo" class="asset-preview-photo" alt="" hidden>
                    <p id="preview-no-photo" class="asset-preview-placeholder">No photograph available</p>
                    <h3 id="preview-name"></h3>
                    <dl class="asset-facts">
                        <div><dt>Type</dt><dd id="preview-type"></dd></div>
                        <div id="preview-size-row"><dt>Size</dt><dd id="preview-size"></dd></div>
                        <div><dt>Status</dt><dd id="preview-status"></dd></div>
                        <div id="preview-location-row"><dt>Location</dt><dd id="preview-location"></dd></div>
                    </dl>
                    <a id="preview-full-link" class="button" href="#asset-record">Open full record</a>
                </aside>
            </div>
        </section>

        <?php if ($asset !== null): ?>
            <article id="asset-record" class="asset-record">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Full item record</p>
                        <h2><?= collectionStewardEscape(collectionStewardAssetLabel((int) $asset['id'], $asset['name'])) ?></h2>
                    </div>
                </div>

                <div class="notice">
                    <?php if ($asset['collection_status'] === 'retired'): ?>
                        <strong>Collection:</strong> Retired
                        <br>
                        <strong>Availability at retirement:</strong>
                    <?php else: ?>
                        <strong>Status:</strong>
                    <?php endif; ?>
                    <?= collectionStewardEscape(ucfirst(str_replace('_', ' ', (string) $asset['availability_status']))) ?>

                    <?php if ($activeCheckout !== null): ?>
                        <br>
                        <strong>Assigned to:</strong>
                        <?= collectionStewardEscape($activeCheckout['production_name']) ?>
                        — <?= collectionStewardEscape($activeCheckout['character_name']) ?>
                        (<?= collectionStewardEscape($activeCheckout['actor_name']) ?>)
                    <?php endif; ?>

                    <?php if ($canManageAssets): ?>
                        <br>
                        <strong>Asset review:</strong>
                        <?php if ($asset['asset_review_status'] === 'pending'): ?>
                            Awaiting review
                        <?php elseif ($lastAssetReview !== null): ?>
                            Last reviewed <?= collectionStewardEscape($lastAssetReview['reviewed_at']) ?>
                            by <?= collectionStewardEscape($lastAssetReview['reviewer_name'] ?: 'a former user') ?>
                        <?php else: ?>
                            Not queued
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($latestLifecycleEvent !== null): ?>
                    <div class="retirement-record">
                        <h3>Retirement record</h3>
                        <dl class="asset-facts full-asset-facts">
                            <div><dt>Date</dt><dd><?= collectionStewardEscape($latestLifecycleEvent['effective_date']) ?></dd></div>
                            <div><dt>Disposition</dt><dd><?= collectionStewardEscape(collectionStewardRetirementDispositionLabel((string) $latestLifecycleEvent['disposition'])) ?></dd></div>
                            <div>
                                <dt>Recorded by</dt>
                                <dd>
                                    <?= collectionStewardEscape($latestLifecycleEvent['recorded_by'] ?: 'Former user') ?>
                                    on <?= collectionStewardEscape($latestLifecycleEvent['recorded_at']) ?>
                                </dd>
                            </div>
                            <?php if (!empty($latestLifecycleEvent['note'])): ?>
                                <div><dt>Note</dt><dd><?= collectionStewardEscape($latestLifecycleEvent['note']) ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    </div>
                <?php endif; ?>

                <?php if (!empty($asset['file_path'])): ?>
                    <div class="asset-photo-panel">
                        <img
                            id="asset-photo"
                            src="<?= collectionStewardEscape($asset['file_path']) ?>"
                            alt="<?= collectionStewardEscape($asset['caption'] ?: $asset['name']) ?>"
                            class="asset-photo"
                        >
                        <button type="button" id="photo-size-toggle" class="photo-size-toggle" aria-controls="asset-photo" aria-expanded="false">Show larger photo</button>
                    </div>
                <?php endif; ?>

                <dl class="asset-facts full-asset-facts">
                    <div><dt>Type</dt><dd><?= collectionStewardEscape($asset['asset_type'] ?? 'Unassigned') ?></dd></div>
                    <?php if (!empty($asset['wearer'])): ?>
                        <div><dt>Wearer</dt><dd><?= collectionStewardEscape($asset['wearer']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($asset['storage_location'])): ?>
                        <div><dt>Current location</dt><dd><?= collectionStewardEscape($asset['storage_location']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($asset['size_description'])): ?>
                        <div><dt>Size</dt><dd><?= collectionStewardEscape($asset['size_description']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($asset['color'])): ?>
                        <div><dt>Primary color</dt><dd><?= collectionStewardEscape($asset['color']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($asset['length_name'])): ?>
                        <div><dt>Length</dt><dd><?= collectionStewardEscape($asset['length_name']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($asset['received_date'])): ?>
                        <div>
                            <dt>Received</dt>
                            <dd>
                                <?= collectionStewardEscape($asset['received_date']) ?>
                                <?= $asset['acquisition_type'] === 'donation' ? ' as a donation' : '' ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Other attributes</dt>
                        <dd><?= $assignedTags === [] ? 'None' : collectionStewardEscape(implode(', ', array_column($assignedTags, 'name'))) ?></dd>
                    </div>
                    <div>
                        <dt>Strike work</dt>
                        <dd>
                            <?php if ($strikeActions === []): ?>
                                None
                            <?php else: ?>
                                <ul class="compact-list">
                                    <?php foreach ($strikeActions as $strikeAction): ?>
                                        <li>
                                            <?= collectionStewardEscape($strikeAction['action_needed']) ?>
                                            <?php if (!empty($strikeAction['staging_location'])): ?>
                                                — Staging: <?= collectionStewardEscape($strikeAction['staging_location']) ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>

                <?php if (!empty($asset['description'])): ?>
                    <section>
                        <h3>Description</h3>
                        <p><?= collectionStewardEscape($asset['description']) ?></p>
                    </section>
                <?php endif; ?>

                <?php if (!empty($asset['notes'])): ?>
                    <section>
                        <h3>Notes</h3>
                        <p><?= collectionStewardEscape($asset['notes']) ?></p>
                    </section>
                <?php endif; ?>

                <?php if (
                    $canManageAssets
                    && $csrfToken !== null
                    && $asset['collection_status'] === 'active'
                ): ?>
                    <details class="asset-actions">
                        <summary>Steward actions</summary>

                        <?php if ($asset['asset_review_status'] === 'pending'): ?>
                            <p><a class="button" href="/asset-review.php?asset_id=<?= (int) $asset['id'] ?>">Open asset review</a></p>
                        <?php else: ?>
                            <form method="post" action="/?asset_id=<?= (int) $asset['id'] ?>#asset-record" class="compact-form">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="queue_asset_review">
                                <button type="submit">Send to asset review</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($availableTags !== []): ?>
                            <form method="post" action="/?asset_id=<?= (int) $asset['id'] ?>#asset-record" class="compact-form">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="assign_tag">
                                <div class="field">
                                    <label for="tag_id">Assign tag</label>
                                    <select id="tag_id" name="tag_id">
                                        <option value="">Choose a tag</option>
                                        <?php foreach ($availableTags as $tag): ?>
                                            <option value="<?= (int) $tag['id'] ?>"><?= collectionStewardEscape($tag['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit">Assign tag</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($assignedTags !== []): ?>
                            <form method="post" action="/?asset_id=<?= (int) $asset['id'] ?>#asset-record" class="compact-form">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="remove_tag">
                                <div class="field">
                                    <label for="remove_tag_id">Remove tag</label>
                                    <select id="remove_tag_id" name="tag_id">
                                        <option value="">Choose a tag</option>
                                        <?php foreach ($assignedTags as $tag): ?>
                                            <option value="<?= (int) $tag['id'] ?>"><?= collectionStewardEscape($tag['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="secondary">Remove tag</button>
                            </form>
                        <?php endif; ?>

                        <?php foreach ($strikeActions as $strikeAction): ?>
                            <form method="post" action="/?asset_id=<?= (int) $asset['id'] ?>#asset-record" class="compact-form">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="complete_strike_action">
                                <input type="hidden" name="strike_action_id" value="<?= (int) $strikeAction['id'] ?>">
                                <button type="submit">Mark <?= collectionStewardEscape($strikeAction['action_needed']) ?> completed</button>
                            </form>
                        <?php endforeach; ?>

                        <form method="post" action="/?asset_id=<?= (int) $asset['id'] ?>#asset-record">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="add_strike_action">
                            <div class="field">
                                <label for="action_needed">Strike action</label>
                                <select id="action_needed" name="action_needed" required>
                                    <option value="">Choose an action</option>
                                    <?php foreach ($strikeActionChoices as $strikeActionChoice): ?>
                                        <option value="<?= collectionStewardEscape($strikeActionChoice) ?>"><?= collectionStewardEscape($strikeActionChoice) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="staging_location">Temporary staging location</label>
                                <input type="text" id="staging_location" name="staging_location" maxlength="255">
                            </div>
                            <button type="submit">Record strike work</button>
                        </form>

                        <div class="retirement-action">
                            <h3>Retire asset</h3>
                            <p>
                                Use this when an item leaves the collection or when its record was created in error. The record and its history will be preserved.
                            </p>
                            <form method="post" action="/?asset_id=<?= (int) $asset['id'] ?>#asset-record">
                                <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="retire_asset">
                                <div class="field">
                                    <label for="retirement_disposition">Disposition</label>
                                    <select id="retirement_disposition" name="retirement_disposition" required>
                                        <option value="">Choose a disposition</option>
                                        <?php foreach (collectionStewardRetirementDispositions() as $dispositionValue => $dispositionLabel): ?>
                                            <option value="<?= collectionStewardEscape($dispositionValue) ?>" <?= $retirementDisposition === $dispositionValue ? 'selected' : '' ?>><?= collectionStewardEscape($dispositionLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="retirement_date">Retirement date</label>
                                    <input type="date" id="retirement_date" name="retirement_date" value="<?= collectionStewardEscape($retirementDate) ?>" required>
                                </div>
                                <div class="field">
                                    <label for="retirement_note">Note (optional)</label>
                                    <textarea id="retirement_note" name="retirement_note" maxlength="5000"><?= collectionStewardEscape($retirementNote) ?></textarea>
                                    <span class="help">For a record created in error, choose Discarded and note that no physical asset existed.</span>
                                </div>
                                <label class="confirmation-choice">
                                    <input type="checkbox" name="confirm_retirement" value="1" required>
                                    I confirm that this asset should be retired.
                                </label>
                                <button type="submit" class="secondary">Retire asset</button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </article>
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

<?php if ($assetChoices !== []): ?>
    <script type="application/json" id="asset-browser-data"><?= $assetBrowserJson ?></script>
    <script>
        const assetBrowserData = JSON.parse(
            document.getElementById('asset-browser-data').textContent
        );
        const assetsById = new Map(
            assetBrowserData.map(function (asset) {
                return [String(asset.id), asset];
            })
        );
        const assetList = document.getElementById('asset-browser-list');
        const assetItems = Array.from(document.querySelectorAll('.asset-list-item'));
        const assetGroups = Array.from(document.querySelectorAll('.asset-type-group'));
        const assetSearch = document.getElementById('asset-search');
        const typeFilter = document.getElementById('type-filter');
        const assetBrowserTitle = document.getElementById('asset-browser-title');
        const noAssetResults = document.getElementById('no-asset-results');
        let previewedAssetId = null;
        let scrollTimer = null;

        const setOptionalPreviewValue = function (rowId, valueId, value) {
            const row = document.getElementById(rowId);
            document.getElementById(valueId).textContent = value || '';
            row.hidden = !value;
        };

        const showAssetPreview = function (assetId) {
            const asset = assetsById.get(String(assetId));

            if (!asset || String(asset.id) === previewedAssetId) {
                return;
            }

            previewedAssetId = String(asset.id);
            document.getElementById('preview-name').textContent = asset.display_label;
            document.getElementById('preview-type').textContent = asset.asset_type;
            const previewStatus = asset.collection_status === 'retired'
                ? 'retired'
                : asset.availability_status;
            document.getElementById('preview-status').textContent = String(previewStatus)
                .replaceAll('_', ' ')
                .replace(/^./, function (letter) { return letter.toUpperCase(); });
            document.getElementById('preview-full-link').href = '/?asset_id=' + asset.id + '#asset-record';

            setOptionalPreviewValue('preview-size-row', 'preview-size', asset.size_description);
            setOptionalPreviewValue('preview-location-row', 'preview-location', asset.storage_location);

            const previewPhoto = document.getElementById('preview-photo');
            const previewNoPhoto = document.getElementById('preview-no-photo');

            if (asset.file_path) {
                previewPhoto.src = asset.file_path;
                previewPhoto.alt = asset.name;
                previewPhoto.hidden = false;
                previewNoPhoto.hidden = true;
            } else {
                previewPhoto.removeAttribute('src');
                previewPhoto.alt = '';
                previewPhoto.hidden = true;
                previewNoPhoto.hidden = false;
            }

            assetItems.forEach(function (item) {
                item.classList.toggle(
                    'is-previewed',
                    item.dataset.assetId === String(asset.id)
                );
            });
        };

        const visibleAssetItems = function () {
            return assetItems.filter(function (item) {
                return !item.hidden && !item.closest('.asset-type-group').hidden;
            });
        };

        const previewCenteredAsset = function () {
            const visibleItems = visibleAssetItems();

            if (visibleItems.length === 0) {
                return;
            }

            const listBounds = assetList.getBoundingClientRect();
            const listCenter = listBounds.top + listBounds.height / 2;
            let closestItem = visibleItems[0];
            let closestDistance = Number.POSITIVE_INFINITY;

            visibleItems.forEach(function (item) {
                const bounds = item.getBoundingClientRect();
                const itemCenter = bounds.top + bounds.height / 2;
                const distance = Math.abs(itemCenter - listCenter);

                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestItem = item;
                }
            });

            showAssetPreview(closestItem.dataset.assetId);
        };

        const filterAssets = function () {
            const query = assetSearch.value.trim().toLocaleLowerCase();
            const selectedType = typeFilter.value;
            let visibleCount = 0;

            assetGroups.forEach(function (group) {
                let groupCount = 0;
                const typeMatches = selectedType === ''
                    || group.dataset.type === selectedType;

                group.querySelectorAll('.asset-list-item').forEach(function (item) {
                    const matches = typeMatches
                        && (query === '' || item.dataset.search.includes(query));
                    item.hidden = !matches;

                    if (matches) {
                        groupCount += 1;
                        visibleCount += 1;
                    }
                });

                group.hidden = groupCount === 0;
            });

            assetBrowserTitle.textContent = 'Scroll through ' + visibleCount
                + (visibleCount === 1 ? ' asset' : ' assets');
            noAssetResults.hidden = visibleCount !== 0;

            if (visibleCount > 0) {
                const visibleItems = visibleAssetItems();
                const currentPreviewIsVisible = visibleItems.some(function (item) {
                    return item.dataset.assetId === previewedAssetId;
                });

                if (!currentPreviewIsVisible) {
                    showAssetPreview(visibleItems[0].dataset.assetId);
                }
            }
        };

        assetItems.forEach(function (item) {
            item.addEventListener('focus', function () {
                showAssetPreview(item.dataset.assetId);
            });
            item.addEventListener('pointerenter', function () {
                showAssetPreview(item.dataset.assetId);
            });
        });

        assetList.addEventListener('scroll', function () {
            window.clearTimeout(scrollTimer);
            scrollTimer = window.setTimeout(previewCenteredAsset, 350);
        }, { passive: true });

        assetSearch.addEventListener('input', filterAssets);
        typeFilter.addEventListener('change', filterAssets);

        const initiallySelected = document.querySelector('.asset-list-item.is-current')
            || assetItems[0];
        showAssetPreview(initiallySelected.dataset.assetId);
        filterAssets();

        const assetPhoto = document.getElementById('asset-photo');
        const photoSizeToggle = document.getElementById('photo-size-toggle');

        if (assetPhoto && photoSizeToggle) {
            photoSizeToggle.addEventListener('click', function () {
                const isExpanded = assetPhoto.classList.toggle('is-expanded');
                photoSizeToggle.textContent = isExpanded
                    ? 'Show thumbnail'
                    : 'Show larger photo';
                photoSizeToggle.setAttribute(
                    'aria-expanded',
                    isExpanded ? 'true' : 'false'
                );
            });
        }
    </script>
<?php endif; ?>
</body>
</html>
