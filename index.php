<?php

//......................................................................................................
//  declare working space and set up the environment 

declare(strict_types=1);
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);

session_start();
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
$config = require dirname(__DIR__)
    . '/collection_steward_private/database-config.php';
//-------------------------------------------------------------
$requestedAssetId = filter_input(
    INPUT_GET,
    'asset_id',
    FILTER_VALIDATE_INT
);

$assetId = is_int($requestedAssetId) && $requestedAssetId > 0
    ? $requestedAssetId
    : 1;
//-------------------------------------------------------------
$asset = null;
$tags = [];
$assignedTags = [];
$availableTags = [];
$assetChoices = [];
$strikeActions = [];

// pilot MAY BE TEMPORARY
// Pilot strike action choices
$strikeActionChoices = [
    'Launder',
    'Repair',
    'Return to owner/lender',
    'Return to storage',
];
// end PILOT may be temporary


$errorMessage = null;
$currentUser = null;
$loginError = null;
//......................................................................................................

//......................................................................................................
// establish database connection
try {
    $connection = new PDO(
        'mysql:host=' . $config['host']
            . ';dbname=' . $config['database']
            . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    if (isset($_SESSION['user_id'])) {
        $sessionUserStatement = $connection->prepare(
            'SELECT id, username, display_name
             FROM users
             WHERE id = :user_id
               AND is_active = 1
             LIMIT 1'
        );

        $sessionUserStatement->execute([
            'user_id' => (int) $_SESSION['user_id'],
        ]);

        $sessionUser = $sessionUserStatement->fetch();

        if ($sessionUser !== false) {
            $currentUser = $sessionUser;
        } else {
            unset($_SESSION['user_id']);
        }
    }
// database connection established
//......................................................................................................

//......................................................................................................
// login logic

    if (
        $currentUser === null
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'login'
    ) 
	
	{
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
                'SELECT id, username, display_name, password_hash
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

                unset($user['password_hash']);
                $currentUser = $user;
            } else {
                $loginError = 'Invalid username or password.';
            }
        }
    }
//  end login logic
//......................................................................................................

	
//......................................................................................................
// assign, remove tags or set strike action 
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'assign_tag'
        && $currentUser === null
    ) {
        http_response_code(403);
        exit('You must be signed in to assign tags.');
		}
		    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'remove_tag'
        && $currentUser === null
    ) {
        http_response_code(403);
        exit('You must be signed in to remove tags.');
    }
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'add_strike_action'
        && $currentUser === null
    ) {
        http_response_code(403);
        exit('You must be signed in to add strike work.');
    }
// Add temporary strike work for an authenticated steward.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'complete_strike_action'
    && $currentUser === null
) {
    http_response_code(403);
    exit('You must be signed in to complete strike work.');
}
//............................................................

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'add_strike_action'
    && $currentUser !== null
) {
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

    header('Location: /?asset_id=' . $assetId);
    exit;
}
//...........................................................................
// Complete existing strike work without deleting its history.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'complete_strike_action'
    && $currentUser !== null
) {
    $strikeActionId = filter_input(
        INPUT_POST,
        'strike_action_id',
        FILTER_VALIDATE_INT
    );

    if (is_int($strikeActionId) && $strikeActionId > 0) {
        $completeStrikeActionStatement = $connection->prepare(
            'UPDATE asset_strike_actions
             SET status = :status,
                 completed_at = CURRENT_TIMESTAMP,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :strike_action_id
               AND asset_id = :asset_id
               AND status = :pending_status'
        );

        $completeStrikeActionStatement->execute([
            'status' => 'completed',
            'updated_by_user_id' => (int) $currentUser['id'],
            'strike_action_id' => $strikeActionId,
            'asset_id' => $assetId,
            'pending_status' => 'pending',
        ]);
    }

    header('Location: /?asset_id=' . $assetId);
    exit;
}
//...........................................................................
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'remove_tag'
        && $currentUser !== null
    ) {
        $tagId = filter_input(
            INPUT_POST,
            'tag_id',
            FILTER_VALIDATE_INT
        );

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

        header('Location: /?asset_id=' . $assetId);
        exit;
    }	
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'assign_tag'
        && $currentUser !== null
    ) {
        $tagId = filter_input(
            INPUT_POST,
            'tag_id',
            FILTER_VALIDATE_INT
        );

        if (is_int($tagId) && $tagId > 0) {
            $validTagStatement = $connection->prepare(
                'SELECT id
                 FROM tags
                 WHERE id = :tag_id
                   AND is_active = 1
                 LIMIT 1'
            );

            $validTagStatement->execute([
                'tag_id' => $tagId,
            ]);

            if ($validTagStatement->fetch() !== false) {
                $existingTagStatement = $connection->prepare(
                    'SELECT 1
                     FROM asset_tags
                     WHERE asset_id = :asset_id
                       AND tag_id = :tag_id
                     LIMIT 1'
                );

                $existingTagStatement->execute([
                    'asset_id' => $assetId,
                    'tag_id' => $tagId,
                ]);

                if ($existingTagStatement->fetch() === false) {
                    $assignTagStatement = $connection->prepare(
                        'INSERT INTO asset_tags (asset_id, tag_id)
                         VALUES (:asset_id, :tag_id)'
                    );

                    $assignTagStatement->execute([
                        'asset_id' => $assetId,
                        'tag_id' => $tagId,
                    ]);
               }
            }
        }

        header('Location: /?asset_id=' . $assetId);
        exit;
    }	
// end assign, remove tags or set strike action 
//......................................................................................................


//......................................................................................................
// retrieve asset record, tags, and pending strike work
$assetChoiceStatement = $connection->query(
    'SELECT id, name
     FROM assets
     ORDER BY name, id'
);

$assetChoices = $assetChoiceStatement->fetchAll();
    $statement = $connection->prepare(
        'SELECT
            a.id,
            a.name,
            a.description,
            a.storage_location,
			a.size_description,
            a.notes,
            a.availability_status,
            c.name AS category,
            p.file_path,
            p.caption
        FROM assets AS a
        JOIN asset_categories AS c
            ON c.id = a.category_id
        LEFT JOIN asset_photos AS p
            ON p.asset_id = a.id
            AND p.is_primary = 1
        WHERE a.id = :asset_id
        LIMIT 1'
    );

    $statement->execute([
        'asset_id' => $assetId,
    ]);

    $asset = $statement->fetch();

    if ($asset === false) {
        $asset = null;
        $errorMessage = 'Asset ' . $assetId . ' was not found.';
    }
if ($asset !== null) {
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
    $tags = array_column($assignedTags, 'name');
	$availableTagStatement = $connection->query(
    'SELECT id, name
     FROM tags
     WHERE is_active = 1
     ORDER BY name'
);

$availableTags = $availableTagStatement->fetchAll();

// setstrike activity may need to become separate module.  only here for pilot
$strikeActionStatement = $connection->prepare(
    'SELECT id, action_needed, staging_location, status
     FROM asset_strike_actions
     WHERE asset_id = :asset_id
       AND status = :status
     ORDER BY created_at, id'
);

$strikeActionStatement->execute([
    'asset_id' => $assetId,
    'status' => 'pending',
]);

$strikeActions = $strikeActionStatement->fetchAll();
}
} catch (Throwable $error) {
    $errorMessage = 'The inventory record could not be loaded.';
}

function escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// end of possible temporary code for pilot  
//......................................................................................................
?>



<?php if ($currentUser !== null): ?>

<form method="post">
<input type="hidden" name="action" value="assign_tag">
<?php if (!empty($availableTags)): ?>

    <p>
        <strong>Assign tag:</strong>
        <select name="tag_id">
            <option value="">Choose a tag</option>
            <?php foreach ($availableTags as $tag): ?>
                <option value="<?= (int) $tag['id'] ?>">
                    <?= escape($tag['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
		<button type="submit">Assign tag</button>
    </p>
<?php endif; ?>
</form>
<?php endif; ?>
<?php if ($currentUser !== null && !empty($assignedTags)): ?>
<form method="post">
    <input type="hidden" name="action" value="remove_tag">
    <p>
        <strong>Remove tag:</strong>
        <select name="tag_id">
            <option value="">Choose a tag</option>
            <?php foreach ($assignedTags as $tag): ?>
                <option value="<?= (int) $tag['id'] ?>">
                    <?= escape($tag['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Remove tag</button>
    </p>
</form>
<?php endif; ?>

<!DOCTYPE html>
<!--html comment  this is a test to make sure that I understand syntax to comment-->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Steward</title>
	<?php if ($currentUser === null): ?>
	
<!-- html comment .............................. -->
    <form method="post">
        <p>
            <label for="username">Username:</label>
            <input
                type="text"
                id="username"
                name="username"
                autocomplete="username"
                required
            >
        </p>

        <p>
            <label for="password">Password:</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </p>

        <button type="submit" name="action" value="login">
            Sign in
        </button>
    </form>
<?php endif; ?>
</head>
<body>
    <main>
        <h1>Collection Steward</h1>
		
<?php if (!empty($assetChoices)): ?>
    <form method="get">
        <p>
            <label for="asset_id"><strong>View asset:</strong></label>

            <select id="asset_id" name="asset_id">
                <?php foreach ($assetChoices as $assetChoice): ?>
                    <option
                        value="<?= (int) $assetChoice['id'] ?>"
                        <?= (int) $assetChoice['id'] === $assetId ? 'selected' : '' ?>
                    >
                        <?= escape($assetChoice['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">View</button>
        </p>
    </form>
<?php endif; ?>
		
		<?php if ($currentUser !== null): ?>
            <p>
        Signed in as
        <strong><?= escape($currentUser['display_name']) ?></strong>
            </p>
        <?php endif; ?>
<form method="post">
    <button type="submit" name="action" value="logout">
        Sign out
    </button>
</form>		

<!-- ................................... begin display asset ............................-->
        <?php if ($errorMessage !== null): ?>
            <p><?= escape($errorMessage) ?></p>

        <?php elseif ($asset !== null): ?>
            <article>
                <h2><?= escape($asset['name']) ?></h2>

                <p>
                    <strong>Category:</strong>
                    <?= escape($asset['category']) ?>
                </p>

                <?php if (!empty($asset['file_path'])): ?>
                    <img
                        src="<?= escape($asset['file_path']) ?>"
                        alt="<?= escape($asset['caption'] ?: $asset['name']) ?>"
                        style="max-width: 500px; height: auto;"
                    >
                <?php endif; ?>

                <?php if (!empty($asset['description'])): ?>
                    <p><?= escape($asset['description']) ?></p>
                <?php endif; ?>

                <?php if (!empty($asset['storage_location'])): ?>
                    <p>
                        <strong>Current location:</strong>
                        <?= escape($asset['storage_location']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($asset['size_description'])): ?>
                    <p>
                        <strong>Size:</strong>
                        <?= escape($asset['size_description']) ?>
                    </p>
                <?php endif; ?>

                    <p>
                        <strong>Tags:</strong>
                        <?= empty($tags) ? 'None' : escape(implode(', ', $tags)) ?>
                    </p>
                <p>
                        <strong>Strike work:</strong>
                <?php if (empty($strikeActions)): ?>
                          None
                <?php else: ?>
                <?php foreach ($strikeActions as $strikeAction): ?>
                      <br>
                      <?= escape($strikeAction['action_needed']) ?>
                <?php if (!empty($strikeAction['staging_location'])): ?>
                          — Staging: <?= escape($strikeAction['staging_location']) ?>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php endif; ?>
             </p>
<!--...............................................-->
<?php if ($currentUser !== null && !empty($strikeActions)): ?>
    <?php foreach ($strikeActions as $strikeAction): ?>
        <form method="post">
            <input
                type="hidden"
                name="strike_action_id"
                value="<?= (int) $strikeAction['id'] ?>"
            >
            <button
                type="submit"
                name="action"
                value="complete_strike_action"
            >
                Mark <?= escape($strikeAction['action_needed']) ?> completed
            </button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>
<!--...............................................-->

<!-- authenticated strike-work entry form -->
<?php if ($currentUser !== null): ?>
<form method="post">
    <input type="hidden" name="action" value="add_strike_action">

    <p>
        <label for="action_needed"><strong>Strike action:</strong></label>
        <select id="action_needed" name="action_needed" required>
            <option value="">Choose an action</option>
            <?php foreach ($strikeActionChoices as $strikeActionChoice): ?>
                <option value="<?= escape($strikeActionChoice) ?>">
                    <?= escape($strikeActionChoice) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label for="staging_location">
            <strong>Temporary staging location:</strong>
        </label>
        <input
            type="text"
            id="staging_location"
            name="staging_location"
            maxlength="255"
        >
    </p>

    <button type="submit">Record strike work</button>
</form>
<?php endif; ?>
<!-- end authenticated strike-work entry form -->			 
                <?php if (!empty($asset['notes'])): ?>
                    <p>
                        <strong>Notes:</strong>
                        <?= escape($asset['notes']) ?>
                    </p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </main>
</body>
</html>