<?php

declare(strict_types=1);

require __DIR__ . '/lib/application.php';

startCollectionStewardSession();

$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'manage_users'
);
$csrfToken = collectionStewardCsrfToken();

$roles = [
    'intake' => 'Intake only',
    'steward' => 'Steward',
    'admin' => 'Administrator',
];

$values = [
    'username' => 'WBS-intake',
    'display_name' => 'WBS Intake',
    'role' => 'intake',
];
$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action']
        : '';

    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    if ($action === 'create_user') {
        foreach (array_keys($values) as $field) {
            if (is_string($_POST[$field] ?? null)) {
                $values[$field] = trim($_POST[$field]);
            }
        }

        $password = is_string($_POST['password'] ?? null)
            ? $_POST['password']
            : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null)
            ? $_POST['confirm_password']
            : '';

        if (!preg_match('/\A[A-Za-z0-9._-]{3,100}\z/', $values['username'])) {
            $errors[] = 'Use 3–100 letters, numbers, periods, underscores, or hyphens for the username.';
        }

        if ($values['display_name'] === '' || strlen($values['display_name']) > 150) {
            $errors[] = 'Enter a display name of 150 characters or fewer.';
        }

        if (!isset($roles[$values['role']])) {
            $errors[] = 'Choose a valid account role.';
        }

        if (strlen($password) < 12) {
            $errors[] = 'Use a password containing at least 12 characters.';
        } elseif (!hash_equals($password, $confirmPassword)) {
            $errors[] = 'The two password entries do not match.';
        }

        if ($errors === []) {
            $duplicateStatement = $connection->prepare(
                'SELECT 1
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $duplicateStatement->execute([
                'username' => $values['username'],
            ]);

            if ($duplicateStatement->fetchColumn() !== false) {
                $errors[] = 'That username already exists.';
            }
        }

        if ($errors === []) {
            $insertStatement = $connection->prepare(
                'INSERT INTO users (
                    username,
                    display_name,
                    role,
                    password_hash
                 ) VALUES (
                    :username,
                    :display_name,
                    :role,
                    :password_hash
                 )'
            );
            $insertStatement->execute([
                'username' => $values['username'],
                'display_name' => $values['display_name'],
                'role' => $values['role'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $notice = $values['display_name'] . ' was created.';
            $values = [
                'username' => '',
                'display_name' => '',
                'role' => 'intake',
            ];
        }
    }

    if ($errors === [] && $action === 'set_active') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $isActive = filter_input(INPUT_POST, 'is_active', FILTER_VALIDATE_INT);

        if (
            !is_int($userId)
            || $userId < 1
            || !is_int($isActive)
            || !in_array($isActive, [0, 1], true)
        ) {
            $errors[] = 'Choose a valid account change.';
        } elseif ($userId === (int) $currentUser['id'] && $isActive === 0) {
            $errors[] = 'You cannot deactivate the account you are currently using.';
        } else {
            $updateStatement = $connection->prepare(
                'UPDATE users
                 SET is_active = :is_active
                 WHERE id = :user_id'
            );
            $updateStatement->execute([
                'is_active' => $isActive,
                'user_id' => $userId,
            ]);
            $notice = $isActive === 1
                ? 'The account was reactivated.'
                : 'The account was deactivated.';
        }
    }
}

$userStatement = $connection->query(
    'SELECT id, username, display_name, role, is_active
     FROM users
     ORDER BY is_active DESC, display_name, id'
);
$users = $userStatement->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260825-2">
</head>
<body>
<main>
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/checkout.php">Production checkout</a>
        <a href="/vocabulary.php">Vocabulary</a>
        <a href="/users.php" aria-current="page">Users</a>
    </nav>

    <h1>Collection Steward users</h1>
    <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>

    <?php if ($notice !== null): ?>
        <div class="notice" role="status"><?= collectionStewardEscape($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The account change was not saved.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section>
        <h2>Create an account</h2>
        <p class="help">The suggested WBS-intake account can add incoming donations and view assets, but cannot change tags, strike work, checkout, cast, or users.</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" maxlength="100" value="<?= collectionStewardEscape($values['username']) ?>" required>
            </div>

            <div class="field">
                <label for="display_name">Display name</label>
                <input type="text" id="display_name" name="display_name" maxlength="150" value="<?= collectionStewardEscape($values['display_name']) ?>" required>
            </div>

            <div class="field">
                <label for="role">Access</label>
                <select id="role" name="role">
                    <?php foreach ($roles as $role => $label): ?>
                        <option value="<?= collectionStewardEscape($role) ?>" <?= $values['role'] === $role ? 'selected' : '' ?>>
                            <?= collectionStewardEscape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="12" autocomplete="new-password" required>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="12" autocomplete="new-password" required>
            </div>

            <button type="submit">Create account</button>
        </form>
    </section>

    <section>
        <h2>Existing accounts</h2>
        <div class="user-list">
            <?php foreach ($users as $user): ?>
                <article class="user-card">
                    <div>
                        <strong><?= collectionStewardEscape($user['display_name']) ?></strong><br>
                        <span><?= collectionStewardEscape($user['username']) ?></span><br>
                        <span><?= collectionStewardEscape($roles[$user['role']] ?? $user['role']) ?></span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="set_active">
                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= (int) $user['is_active'] === 1 ? 0 : 1 ?>">
                        <button type="submit" class="secondary" <?= (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : '' ?>>
                            <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                        </button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
