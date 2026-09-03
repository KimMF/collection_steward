<?php

declare(strict_types=1);

/**
 * User creation, activation, and temporary-password administration.
 *
 * Public entry point: /users.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

// User administration is restricted to administrators.
$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability(
    $connection,
    'manage_users'
);
$csrfToken = collectionStewardCsrfToken();

// Labels are kept beside their stored role values for validation and display.
$roles = [
    'intake' => 'Intake only',
    'steward' => 'Steward',
    'admin' => 'Administrator',
];

$values = [
    'username' => 'sonya',
    'display_name' => 'Sonya',
    'role' => 'intake',
];
$errors = [];
$notice = null;

// Dispatch the requested account action after the shared CSRF check.
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

        $temporaryPassword = is_string($_POST['temporary_password'] ?? null)
            ? $_POST['temporary_password']
            : '';
        $confirmTemporaryPassword = is_string($_POST['confirm_temporary_password'] ?? null)
            ? $_POST['confirm_temporary_password']
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

        if (strlen($temporaryPassword) < 12 || strlen($temporaryPassword) > 255) {
            $errors[] = 'Use a temporary password containing 12–255 characters.';
        } elseif (!hash_equals($temporaryPassword, $confirmTemporaryPassword)) {
            $errors[] = 'The two temporary password entries do not match.';
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
                    password_hash,
                    must_change_password
                 ) VALUES (
                    :username,
                    :display_name,
                    :role,
                    :password_hash,
                    1
                 )'
            );
            $insertStatement->execute([
                'username' => $values['username'],
                'display_name' => $values['display_name'],
                'role' => $values['role'],
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            ]);

            $notice = $values['display_name'] . ' was created with a temporary password. The application will require a private replacement at first login.';
            $values = [
                'username' => '',
                'display_name' => '',
                'role' => 'intake',
            ];
        }
    }

    if ($errors === [] && $action === 'reset_password') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $temporaryPassword = is_string($_POST['temporary_password'] ?? null)
            ? $_POST['temporary_password']
            : '';
        $confirmTemporaryPassword = is_string($_POST['confirm_temporary_password'] ?? null)
            ? $_POST['confirm_temporary_password']
            : '';

        if (!is_int($userId) || $userId < 1) {
            $errors[] = 'Choose a valid account.';
        } elseif ($userId === (int) $currentUser['id']) {
            $errors[] = 'Use the Password page to change the account you are currently using.';
        }

        if (strlen($temporaryPassword) < 12 || strlen($temporaryPassword) > 255) {
            $errors[] = 'Use a temporary password containing 12–255 characters.';
        } elseif (!hash_equals($temporaryPassword, $confirmTemporaryPassword)) {
            $errors[] = 'The two temporary password entries do not match.';
        }

        if ($errors === []) {
            $targetStatement = $connection->prepare(
                'SELECT display_name
                 FROM users
                 WHERE id = :user_id
                 LIMIT 1'
            );
            $targetStatement->execute([
                'user_id' => $userId,
            ]);
            $targetDisplayName = $targetStatement->fetchColumn();

            if (!is_string($targetDisplayName)) {
                $errors[] = 'That account no longer exists.';
            }
        }

        if ($errors === []) {
            $resetStatement = $connection->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     must_change_password = 1,
                     password_changed_at = NULL
                 WHERE id = :user_id'
            );
            $resetStatement->execute([
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'user_id' => $userId,
            ]);

            $notice = $targetDisplayName . ' now has a temporary password and must choose a private replacement at the next login.';
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

// Refresh the account list after any completed action.
$userStatement = $connection->query(
    'SELECT id, username, display_name, role, is_active, must_change_password
     FROM users
     ORDER BY is_active DESC, display_name, id'
);
$users = $userStatement->fetchAll();

// Render account creation and administration controls.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260828-1">
</head>
<body>
<main>
    <nav aria-label="Collection Steward">
        <a href="/">View assets</a>
        <a href="/intake.php">Intake</a>
        <a href="/checkout.php">Production checkout</a>
        <a href="/measurements.php">Measurements</a>
        <a href="/asset-review.php">Asset review</a>
        <a href="/vocabulary.php">Vocabulary</a>
        <a href="/users.php" aria-current="page">Users</a>
        <a href="/change-password.php">Password</a>
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
        <p class="help">Create a separate account for each person. An Intake-only account can add incoming donations and view assets, but cannot change tags, strike work, checkout, cast, or users.</p>

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
                <label for="temporary_password">Temporary password</label>
                <input type="password" id="temporary_password" name="temporary_password" minlength="12" maxlength="255" autocomplete="new-password" required>
                <span class="help">Give this temporary password to the new user privately. It must be replaced at first login.</span>
            </div>

            <div class="field">
                <label for="confirm_temporary_password">Confirm temporary password</label>
                <input type="password" id="confirm_temporary_password" name="confirm_temporary_password" minlength="12" maxlength="255" autocomplete="new-password" required>
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
                        <?php if ((int) $user['must_change_password'] === 1): ?>
                            <br><span class="account-status">Password change required</span>
                        <?php endif; ?>
                    </div>
                    <div class="user-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= (int) $user['is_active'] === 1 ? 0 : 1 ?>">
                            <button type="submit" class="secondary" <?= (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : '' ?>>
                                <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                            </button>
                        </form>

                        <?php if ((int) $user['id'] !== (int) $currentUser['id']): ?>
                            <details class="password-reset">
                                <summary>Issue temporary password</summary>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">

                                    <div class="field">
                                        <label for="temporary_password_<?= (int) $user['id'] ?>">Temporary password</label>
                                        <input type="password" id="temporary_password_<?= (int) $user['id'] ?>" name="temporary_password" minlength="12" maxlength="255" autocomplete="new-password" required>
                                    </div>

                                    <div class="field">
                                        <label for="confirm_temporary_password_<?= (int) $user['id'] ?>">Confirm temporary password</label>
                                        <input type="password" id="confirm_temporary_password_<?= (int) $user['id'] ?>" name="confirm_temporary_password" minlength="12" maxlength="255" autocomplete="new-password" required>
                                    </div>

                                    <button type="submit">Save temporary password</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
