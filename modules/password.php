<?php

declare(strict_types=1);

/**
 * Required first-login password replacement and voluntary password changes.
 *
 * Public entry point: /change-password.php
 */
require dirname(__DIR__) . '/lib/application.php';

startCollectionStewardSession();

// This page deliberately allows a signed-in user whose temporary password has
// not yet been replaced; other modules redirect that user here.
$connection = collectionStewardConnection();
$currentUser = requireCollectionStewardUser($connection, true);
$csrfToken = collectionStewardCsrfToken();
$passwordChangeRequired = (int) $currentUser['must_change_password'] === 1;
$errors = [];
$notice = isset($_GET['changed']) && !$passwordChangeRequired
    ? 'Your password was changed successfully.'
    : null;

// Verify the existing credential before accepting and hashing a replacement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    $currentPassword = is_string($_POST['current_password'] ?? null)
        ? $_POST['current_password']
        : '';
    $newPassword = is_string($_POST['new_password'] ?? null)
        ? $_POST['new_password']
        : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null)
        ? $_POST['confirm_password']
        : '';

    $passwordStatement = $connection->prepare(
        'SELECT password_hash
         FROM users
         WHERE id = :user_id
           AND is_active = 1
         LIMIT 1'
    );
    $passwordStatement->execute([
        'user_id' => (int) $currentUser['id'],
    ]);
    $currentPasswordHash = $passwordStatement->fetchColumn();

    if (
        !is_string($currentPasswordHash)
        || !password_verify($currentPassword, $currentPasswordHash)
    ) {
        $errors[] = 'The current or temporary password was not correct.';
    }

    if (strlen($newPassword) < 12 || strlen($newPassword) > 255) {
        $errors[] = 'Choose a new password containing 12–255 characters.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $errors[] = 'The two new password entries do not match.';
    } elseif (
        is_string($currentPasswordHash)
        && password_verify($newPassword, $currentPasswordHash)
    ) {
        $errors[] = 'The new password must be different from the temporary or current password.';
    }

    if ($errors === []) {
        $updateStatement = $connection->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 must_change_password = 0,
                 password_changed_at = CURRENT_TIMESTAMP
             WHERE id = :user_id'
        );
        $updateStatement->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'user_id' => (int) $currentUser['id'],
        ]);

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        header('Location: /change-password.php?changed=1');
        exit;
    }
}

// Render the required or voluntary password-change form.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change password — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260828-1">
</head>
<body>
<main class="password-page">
    <?php if (!$passwordChangeRequired): ?>
        <nav aria-label="Collection Steward">
            <a href="/">View assets</a>
            <?php if (collectionStewardUserCan($currentUser, 'intake')): ?>
                <a href="/intake.php">Intake</a>
            <?php endif; ?>
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
            <?php if (collectionStewardUserCan($currentUser, 'admin_maintenance')): ?>
                <a href="/admin.php">Data maintenance</a>
            <?php endif; ?>
            <a href="/change-password.php" aria-current="page">Password</a>
        </nav>
    <?php endif; ?>

    <div class="page-heading">
        <div>
            <h1><?= $passwordChangeRequired ? 'Choose your password' : 'Change password' ?></h1>
            <p>Signed in as <strong><?= collectionStewardEscape($currentUser['display_name']) ?></strong></p>
        </div>
        <form method="post" action="/">
            <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
        </form>
    </div>

    <?php if ($passwordChangeRequired): ?>
        <div class="notice" role="status">
            Your administrator issued a temporary password. Choose a private replacement before continuing to Collection Steward.
        </div>
    <?php endif; ?>

    <?php if ($notice !== null): ?>
        <div class="notice" role="status">
            <?= collectionStewardEscape($notice) ?>
            <a href="/intake.php">Continue to Intake</a>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <strong>The password was not changed.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= collectionStewardEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($notice === null): ?>
        <form method="post" class="password-form">
            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">

            <div class="field">
                <label for="current_password"><?= $passwordChangeRequired ? 'Temporary password' : 'Current password' ?></label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>

            <div class="field">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" minlength="12" maxlength="255" autocomplete="new-password" required>
                <span class="help">Use 12–255 characters and do not reuse the temporary password.</span>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="12" maxlength="255" autocomplete="new-password" required>
            </div>

            <button type="submit">Save new password</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
