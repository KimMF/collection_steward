<?php

declare(strict_types=1);

$config = require dirname(__DIR__)
    . '/collection_steward_private/database-config.php';

$asset = null;
$tags = [];
$availableTags = [];
$errorMessage = null;

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
        'asset_id' => 1,
    ]);

    $asset = $statement->fetch();

    if ($asset === false) {
        $asset = null;
        $errorMessage = 'Asset 1 was not found.';
    }
if ($asset !== null) {
    $tagStatement = $connection->prepare(
        'SELECT t.name
         FROM tags AS t
         JOIN asset_tags AS at
             ON at.tag_id = t.id
         WHERE at.asset_id = :asset_id
         ORDER BY t.name'
    );

    $tagStatement->execute([
        'asset_id' => 1,
    ]);

    $tags = $tagStatement->fetchAll(PDO::FETCH_COLUMN);
	$availableTagStatement = $connection->query(
    'SELECT id, name
     FROM tags
     WHERE is_active = 1
     ORDER BY name'
);

$availableTags = $availableTagStatement->fetchAll();
}
} catch (Throwable $error) {
    $errorMessage = 'The inventory record could not be loaded.';
}

function escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}


?>
<form method="post">
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Steward</title>
</head>
<body>
    <main>
        <h1>Collection Steward</h1>

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