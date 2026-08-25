<?php

declare(strict_types=1);

function startCollectionStewardSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);

    session_start();
}

function collectionStewardConnection(): PDO
{
    $config = require dirname(__DIR__, 2)
        . '/collection_steward_private/database-config.php';

    return new PDO(
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
}

function collectionStewardCurrentUser(PDO $connection): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $statement = $connection->prepare(
        'SELECT id, username, display_name, role
         FROM users
         WHERE id = :user_id
           AND is_active = 1
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => (int) $_SESSION['user_id'],
    ]);

    $user = $statement->fetch();

    if ($user === false) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function collectionStewardUserCan(array $user, string $capability): bool
{
    $role = is_string($user['role'] ?? null)
        ? $user['role']
        : '';

    $capabilitiesByRole = [
        'admin' => [
            'intake',
            'checkout',
            'manage_assets',
            'manage_users',
        ],
        'steward' => [
            'intake',
            'checkout',
            'manage_assets',
        ],
        'intake' => [
            'intake',
        ],
    ];

    return in_array(
        $capability,
        $capabilitiesByRole[$role] ?? [],
        true
    );
}

function requireCollectionStewardUser(PDO $connection): array
{
    $user = collectionStewardCurrentUser($connection);

    if ($user === null) {
        header('Location: /');
        exit;
    }

    return $user;
}

function requireCollectionStewardCapability(
    PDO $connection,
    string $capability
): array {
    $user = requireCollectionStewardUser($connection);

    if (!collectionStewardUserCan($user, $capability)) {
        http_response_code(403);
        exit('Your Collection Steward account does not have access to this page.');
    }

    return $user;
}

function collectionStewardCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function collectionStewardCsrfIsValid(mixed $submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $submittedToken);
}

function collectionStewardEscape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
