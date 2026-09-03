<?php

declare(strict_types=1);

/**
 * Shared Collection Steward infrastructure and domain helpers.
 *
 * Functional modules require this file for sessions, database access,
 * authorization, request protection, output escaping, and asset naming.
 */

// Session and database infrastructure.
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

// Authentication and role capabilities.
function collectionStewardCurrentUser(
    PDO $connection,
    bool $allowPasswordChangeRequired = false
): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $statement = $connection->prepare(
        'SELECT id, username, display_name, role, must_change_password
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

    if (
        (int) $user['must_change_password'] === 1
        && !$allowPasswordChangeRequired
    ) {
        header('Location: /change-password.php');
        exit;
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
            'measurements',
            'manage_assets',
            'manage_productions',
            'manage_users',
            'manage_vocabulary',
        ],
        'steward' => [
            'intake',
            'checkout',
            'measurements',
            'manage_assets',
            'manage_productions',
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

function requireCollectionStewardUser(
    PDO $connection,
    bool $allowPasswordChangeRequired = false
): array
{
    $user = collectionStewardCurrentUser(
        $connection,
        $allowPasswordChangeRequired
    );

    if ($user === null) {
        header('Location: /');
        exit;
    }

    return $user;
}

function requireCollectionStewardCapability(
    PDO $connection,
    string $capability
): array
{
    $user = requireCollectionStewardUser($connection);

    if (!collectionStewardUserCan($user, $capability)) {
        http_response_code(403);
        exit('Your Collection Steward account does not have access to this page.');
    }

    return $user;
}

// Cross-site request protection and safe HTML output.
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

function collectionStewardProductionStatuses(): array
{
    return [
        'planned' => 'Planned',
        'active' => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

function collectionStewardProductionStatusLabel(string $value): string
{
    return collectionStewardProductionStatuses()[$value]
        ?? ucwords(str_replace('_', ' ', $value));
}

// Asset retirement hides a record without deleting it. The retirement event
// and the asset's operational history remain in the database.
function collectionStewardRetirementDispositions(): array
{
    return [
        'discarded' => 'Discarded',
        'donated_transferred' => 'Donated or transferred',
        'returned_to_owner_lender' => 'Returned to owner or lender',
        'sold' => 'Sold',
        'lost_missing' => 'Lost or missing',
        'other' => 'Other',
    ];
}

function collectionStewardRetirementDispositionLabel(string $value): string
{
    return collectionStewardRetirementDispositions()[$value] ?? $value;
}

function collectionStewardRetireAsset(
    PDO $connection,
    int $assetId,
    string $disposition,
    string $effectiveDate,
    string $note,
    array $currentUser
): void {
    if (!isset(collectionStewardRetirementDispositions()[$disposition])) {
        throw new DomainException('Choose a valid retirement disposition.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);

    if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
        throw new DomainException('Enter a valid retirement date.');
    }

    if (strlen($note) > 5000) {
        throw new DomainException('Keep the retirement note under 5,000 characters.');
    }

    if (!isset($currentUser['id'], $currentUser['display_name'])) {
        throw new DomainException('A signed-in steward must record the retirement.');
    }

    try {
        $connection->beginTransaction();

        $assetStatement = $connection->prepare(
            'SELECT id, collection_status
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

        if ($asset['collection_status'] !== 'active') {
            throw new DomainException('That asset is already retired.');
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
            throw new DomainException(
                'Check in or undo the active production checkout before retiring this asset.'
            );
        }

        $eventStatement = $connection->prepare(
            "INSERT INTO asset_lifecycle_events (
                asset_id,
                event_type,
                disposition,
                effective_date,
                note,
                recorded_by_user_id
             ) VALUES (
                :asset_id,
                'retired',
                :disposition,
                :effective_date,
                :note,
                :recorded_by_user_id
             )"
        );
        $eventStatement->execute([
            'asset_id' => $assetId,
            'disposition' => $disposition,
            'effective_date' => $effectiveDate,
            'note' => $note !== '' ? $note : null,
            'recorded_by_user_id' => (int) $currentUser['id'],
        ]);

        $updateAssetStatement = $connection->prepare(
            "UPDATE assets
             SET collection_status = 'retired',
                 asset_review_status = 'not_queued',
                 asset_review_requested_at = NULL,
                 asset_review_requested_by_user_id = NULL,
                 updated_by = :updated_by
             WHERE id = :asset_id"
        );
        $updateAssetStatement->execute([
            'updated_by' => (string) $currentUser['display_name'],
            'asset_id' => $assetId,
        ]);

        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $error;
    }
}

// Asset labels and generated names shared by browsing, intake, and vocabulary.
function collectionStewardAssetLabel(int $assetId, ?string $name): string
{
    $descriptiveName = is_string($name) && trim($name) !== ''
        ? trim($name)
        : 'Unnamed asset';

    return 'Asset ' . $assetId . ' — ' . $descriptiveName;
}

function collectionStewardAssetSizeDescription(
    ?string $standardizedSize,
    ?string $exactSizeLabel,
    ?string $fallbackSize = null
): string
{
    $standardizedSize = is_string($standardizedSize)
        ? trim($standardizedSize)
        : '';
    $exactSizeLabel = is_string($exactSizeLabel)
        ? trim($exactSizeLabel)
        : '';

    if (
        in_array(strtolower($standardizedSize), ['unknown', 'not applicable'], true)
    ) {
        $standardizedSize = '';
    }

    if (
        in_array(strtolower($exactSizeLabel), ['unknown', 'not applicable'], true)
    ) {
        $exactSizeLabel = '';
    }

    if ($standardizedSize !== '' && $exactSizeLabel !== '') {
        if (strcasecmp($standardizedSize, $exactSizeLabel) === 0) {
            return $standardizedSize;
        }

        return $standardizedSize . ' (' . $exactSizeLabel . ')';
    }

    if ($standardizedSize !== '') {
        return $standardizedSize;
    }

    if ($exactSizeLabel !== '') {
        return $exactSizeLabel;
    }

    $fallbackSize = is_string($fallbackSize) ? trim($fallbackSize) : '';

    if (
        $fallbackSize !== ''
        && !in_array(
            strtolower($fallbackSize),
            ['unknown', 'not applicable'],
            true
        )
    ) {
        return $fallbackSize;
    }

    return '';
}

function collectionStewardBuildAssetName(
    ?string $wearer,
    ?string $primaryColor,
    ?string $length,
    ?string $assetType,
    ?string $size
): string
{
    $nameParts = [];

    foreach ([$wearer, $primaryColor, $assetType] as $partIndex => $namePart) {
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
            $nameParts[] = $partIndex === 0
                ? $trimmedNamePart
                : strtolower($trimmedNamePart);
        }
    }

    $name = $nameParts !== []
        ? implode(' ', $nameParts)
        : 'Unclassified item';

    $trimmedSize = is_string($size) ? trim($size) : '';
    if (
        $trimmedSize === ''
        || in_array(strtolower($trimmedSize), ['unknown', 'not applicable'], true)
    ) {
        $trimmedSize = 'Not recorded';
    }

    $name .= ' — Size: ' . $trimmedSize;

    if (is_string($length)) {
        $trimmedLength = trim($length);

        if (
            $trimmedLength !== ''
            && !in_array(
                strtolower($trimmedLength),
                ['unknown', 'not applicable'],
                true
            )
        ) {
            $name .= '; Length: ' . $trimmedLength;
        }
    }

    return $name;
}

function collectionStewardRefreshAssetName(
    PDO $connection,
    int $assetId,
    string $updatedBy
): void
{
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

    $displaySize = collectionStewardAssetSizeDescription(
        $asset['standardized_size'] ?? null,
        $asset['exact_size_label'] ?? null,
        $asset['size_description'] ?? null
    );

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
