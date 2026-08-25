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
            'manage_vocabulary',
        ],
        'steward' => [
            'intake',
            'checkout',
            'manage_assets',
            'manage_vocabulary',
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

function collectionStewardBuildAssetName(
    ?string $wearer,
    ?string $primaryColor,
    ?string $length,
    ?string $assetType,
    ?string $size
): string {
    $nameParts = [];

    foreach ([$wearer, $primaryColor, $length, $assetType] as $namePart) {
        if (!is_string($namePart)) {
            continue;
        }

        $trimmedNamePart = trim($namePart);
        $normalizedNamePart = strtolower($trimmedNamePart);

        if (
            $trimmedNamePart !== ''
            && !in_array(
                $normalizedNamePart,
                ['unknown', 'not applicable'],
                true
            )
        ) {
            $nameParts[] = $trimmedNamePart;
        }
    }

    $name = $nameParts !== []
        ? implode(' ', $nameParts)
        : 'Unclassified item';

    if (is_string($size)) {
        $trimmedSize = trim($size);
        $normalizedSize = strtolower($trimmedSize);

        if (
            $trimmedSize !== ''
            && !in_array($normalizedSize, ['unknown', 'not applicable'], true)
        ) {
            $name .= ' — ' . $trimmedSize;
        }
    }

    return $name;
}

function collectionStewardRefreshAssetName(
    PDO $connection,
    int $assetId,
    string $updatedBy
): void {
    $assetStatement = $connection->prepare(
        'SELECT
            a.size_description,
            a.exact_size_label,
            wo.name AS wearer,
            co.name AS primary_color,
            lo.name AS length_name,
            aty.name AS asset_type,
            so.name AS standardized_size
         FROM assets AS a
         LEFT JOIN wearer_options AS wo
            ON wo.id = a.wearer_option_id
         LEFT JOIN color_options AS co
            ON co.id = a.primary_color_option_id
         LEFT JOIN length_options AS lo
            ON lo.id = a.length_option_id
         LEFT JOIN asset_types AS aty
            ON aty.id = a.asset_type_id
         LEFT JOIN size_options AS so
            ON so.id = a.size_option_id
         WHERE a.id = :asset_id
         LIMIT 1'
    );
    $assetStatement->execute([
        'asset_id' => $assetId,
    ]);
    $asset = $assetStatement->fetch();

    if ($asset === false) {
        throw new DomainException('The asset for this suggestion was not found.');
    }

    $displaySize = is_string($asset['standardized_size'] ?? null)
        ? trim($asset['standardized_size'])
        : '';
    $exactSizeLabel = is_string($asset['exact_size_label'] ?? null)
        ? trim($asset['exact_size_label'])
        : '';

    if ($exactSizeLabel !== '') {
        if (
            $displaySize !== ''
            && strcasecmp($displaySize, $exactSizeLabel) !== 0
        ) {
            $displaySize .= ' (' . $exactSizeLabel . ')';
        } else {
            $displaySize = $exactSizeLabel;
        }
    } elseif ($displaySize === '' && is_string($asset['size_description'])) {
        $displaySize = trim($asset['size_description']);
    }

    $generatedName = collectionStewardBuildAssetName(
        $asset['wearer'] ?? null,
        $asset['primary_color'] ?? null,
        $asset['length_name'] ?? null,
        $asset['asset_type'] ?? null,
        $displaySize
    );

    if (strlen($generatedName) > 150) {
        throw new DomainException('Those vocabulary choices produce an item name longer than 150 characters.');
    }

    $updateStatement = $connection->prepare(
        'UPDATE assets
         SET name = :name,
             color = COALESCE(:primary_color, color),
             size_description = :size_description,
             updated_by = :updated_by
         WHERE id = :asset_id'
    );
    $updateStatement->execute([
        'name' => $generatedName,
        'primary_color' => $asset['primary_color'] ?? null,
        'size_description' => $displaySize !== '' ? $displaySize : null,
        'updated_by' => $updatedBy,
        'asset_id' => $assetId,
    ]);
}
