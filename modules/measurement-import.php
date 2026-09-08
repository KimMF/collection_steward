<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/application.php';
require dirname(__DIR__) . '/lib/measurement-csv-import.php';
startCollectionStewardSession();
$db = collectionStewardConnection();
$currentUser = requireCollectionStewardCapability($db, 'measurements');
$csrfToken = collectionStewardCsrfToken();
header('Cache-Control: no-store');
$error = null;
$pending = $_SESSION['measurement_csv_import'] ?? null;
if (!is_array($pending) || ($pending['user_id'] ?? null) !== (int) $currentUser['id'] || ($pending['expires'] ?? 0) < time()) {
    $pending = null;
    unset($_SESSION['measurement_csv_import']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!collectionStewardCsrfIsValid($_POST['csrf_token'] ?? null)) {
            throw new DomainException('The form expired. Refresh the page and try again.');
        }
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'upload') {
            $file = $_FILES['csv_file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new DomainException('Choose a CSV file that uploaded successfully (maximum 2 MB).');
            }
            if (filesize($file['tmp_name']) > 2 * 1024 * 1024) { throw new DomainException('The CSV must be no larger than 2 MB.'); }
            $contents = file_get_contents($file['tmp_name']);
            if ($contents === false) { throw new DomainException('The uploaded file could not be read.'); }
            $data = measurementCsvParse($contents);
            $filename = basename(str_replace('\\', '/', (string) $file['name']));
            if (!preg_match('//u', $filename) || strlen($filename) > 255 || preg_match('/[\r\n\x00-\x1f]/', $filename)) { $filename = 'measurements.csv'; }
            $pending = ['data' => $data, 'filename' => $filename, 'sha256' => hash('sha256', $contents),
                'user_id' => (int) $currentUser['id'], 'expires' => time() + 3600, 'token' => bin2hex(random_bytes(24)), 'stage' => 'configure'];
        } else {
            if (!$pending || !is_string($_POST['import_token'] ?? null) || !hash_equals($pending['token'], $_POST['import_token'])) {
                throw new DomainException('This import preview expired or was replaced in another tab. Upload the CSV again.');
            }
            if ($action === 'cancel') { $pending = null; }
            elseif ($action === 'configure') { $pending['stage'] = 'configure'; }
            elseif ($action === 'preview') {
                if (($_POST['row_selection_complete'] ?? '') !== '1') {
                    throw new DomainException('The row-selection form was incomplete or too large. Refresh this page and try again; use a smaller CSV if it persists.');
                }
                $pending['stage'] = 'configure';
                $columnChoices = [];
                foreach ($pending['data']['columns'] as $index => $_) {
                    foreach (['target', 'approve', 'kind', 'unit'] as $field) {
                        $value = $_POST['columns'][$index][$field] ?? null;
                        $columnChoices[$index][$field] = is_string($value) ? $value : '';
                    }
                }
                $actorChoices = [];
                foreach ($pending['data']['actors'] as $actor) {
                    $value = $_POST['actors'][$actor['index']] ?? null;
                    $actorChoices[$actor['index']] = is_string($value) ? $value : '';
                }
                $includedRows = [];
                foreach ($pending['data']['rows'] as $row) {
                    if (($_POST['included_rows'][$row['number']] ?? '') === '1') {
                        $includedRows[$row['number']] = '1';
                    }
                }
                $pending['choices'] = [
                    'production_id' => is_string($_POST['production_id'] ?? null) ? $_POST['production_id'] : '',
                    'columns' => $columnChoices,
                    'actors' => $actorChoices,
                    'included_rows' => $includedRows,
                ];
                $plan = measurementCsvPlan($db, $pending, $pending['choices']);
                $pending['plan'] = $plan;
                $pending['plan_hash'] = hash('sha256', serialize($plan));
                $pending['stage'] = 'preview';
            } elseif ($action === 'commit' && $pending['stage'] === 'preview') {
                $sessionId = measurementCsvCommit($db, $pending, (int) $currentUser['id'], ($_POST['approve_separate'] ?? '') === '1');
                unset($_SESSION['measurement_csv_import']);
                header('Location: /measurements.php?view=compact&scope=actor&list=review&csv_imported=1&session_id=' . $sessionId, true, 303);
                exit;
            } else { throw new DomainException('Choose an import action from this page.'); }
        }
    } catch (DomainException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('Measurement CSV import failed: ' . get_class($e) . ' code ' . $e->getCode());
        $error = 'The import could not be saved. No partial import was kept. Preview again; if it still fails, ask the administrator to check the error log.';
    }
    if ($pending === null) { unset($_SESSION['measurement_csv_import']); }
    else { $_SESSION['measurement_csv_import'] = $pending; }
}
$catalog = $pending && $pending['stage'] === 'configure' ? measurementCsvCatalog($db) : null;
function measurementImportHidden(string $csrfToken, array $pending): void
{
    echo '<input type="hidden" name="csrf_token" value="' . collectionStewardEscape($csrfToken) . '">';
    echo '<input type="hidden" name="import_token" value="' . collectionStewardEscape($pending['token']) . '">';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import measurements — Collection Steward</title>
    <link rel="stylesheet" href="/app.css?v=20260904-3">
    <style>
        .csv-import { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
        .csv-import section { margin: 1.5rem 0; }
        .csv-import .csv-scroll { overflow-x: auto; }
        .csv-import table { border-collapse: collapse; width: 100%; }
        .csv-import th, .csv-import td { border-bottom: 1px solid #ccc; padding: .65rem; text-align: left; vertical-align: top; }
        .csv-import td select { min-width: 12rem; }
        .csv-import label { display: block; margin: .35rem 0; }
        .csv-import .csv-value { white-space: pre-wrap; overflow-wrap: anywhere; }
        .csv-import .csv-warning { color: #753f00; font-weight: bold; }
    </style>
</head>
<body>
<main class="csv-import">
    <nav><a href="/measurements.php">Back to Measurements</a></nav>
    <h1>Import measurements from CSV</h1>
    <p>Signed in as <?= collectionStewardEscape($currentUser['display_name']) ?>.</p>
    <?php if ($error !== null): ?><div class="error" role="alert"><?= collectionStewardEscape($error) ?></div><?php endif; ?>
    <?php if ($pending === null): ?>
        <p>Upload a CSV exported from Measurements, or use the same columns: <strong>Actor</strong>, <strong>Measurement date</strong>, then measurement names. Include units in headers when needed, such as <strong>Waist (in)</strong>.</p>
        <p>Dates may be 2026-09-08, September 8, 2026, September 2026, or 2026. Blank means not measured; N/A means not applicable. Rows with no measurements are skipped.</p>
        <p>You will review actor matches and approve new measurement names before importing. Each row creates a new dated session in the review queue. Existing measurements remain unchanged.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= collectionStewardEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="upload">
            <label for="csv-file">CSV file (UTF-8, up to 2 MB and 500 rows)</label>
            <input id="csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>
            <button type="submit">Read CSV</button>
        </form>
    <?php else: ?>
        <p><strong><?= collectionStewardEscape($pending['filename']) ?></strong> · <?= count($pending['data']['rows']) ?> rows with measurements · <?= $pending['data']['skipped'] ?> empty measurement rows skipped.</p>
        <?php if ($pending['stage'] === 'configure'): ?>
            <p>No records have been saved. Match the columns and actors, then preview the import.</p>
            <form method="post">
                <?php measurementImportHidden($csrfToken, $pending); ?>
                <input type="hidden" name="action" value="preview">
                <section>
                    <label for="import-production">Production for these measurements</label>
                    <select id="import-production" name="production_id">
                        <option value="">General fitting (no production)</option>
                        <?php foreach ($catalog['productions'] as $production): ?>
                            <option value="<?= (int) $production['id'] ?>" <?= (string) ($pending['choices']['production_id'] ?? '') === (string) $production['id'] ? 'selected' : '' ?>><?= collectionStewardEscape($production['name'] . (!empty($production['production_year']) ? ' — ' . $production['production_year'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help">Applies to all rows in this file. This records measurements; cast assignments are managed in Productions.</p>
                </section>
                <section><h2>Measurement columns</h2>
                    <p>A name already in the database uses its existing definition. For an unfamiliar name, choose an existing measurement or explicitly approve adding the new name. Values are not converted between units.</p>
                    <div class="csv-scroll"><table><thead><tr><th>CSV column</th><th>Measurement in database</th><th>New measurement approval</th></tr></thead><tbody>
                    <?php foreach ($pending['data']['columns'] as $index => $header):
                        $definition = measurementCsvDefinition($header, $catalog['types']);
                        $choice = $pending['choices']['columns'][$index] ?? [];
                        $defaultTarget = count($definition['matches']) === 1 ? (string) $definition['matches'][0]['id'] : ($definition['matches'] ? '' : 'new');
                        $target = $choice['target'] ?? $defaultTarget;
                    ?>
                        <tr><th scope="row"><?= collectionStewardEscape($header) ?></th><td>
                            <label class="visually-hidden" for="column-<?= $index ?>">Map <?= collectionStewardEscape($header) ?></label>
                            <select id="column-<?= $index ?>" name="columns[<?= $index ?>][target]" required>
                                <option value="">Choose measurement</option>
                                <?php if (!$definition['matches']): ?><option value="new" <?= $target === 'new' ? 'selected' : '' ?>>Add <?= collectionStewardEscape($definition['name']) ?></option><?php endif; ?>
                                <?php foreach ($catalog['types'] as $type): ?>
                                    <option value="<?= (int) $type['id'] ?>" <?= $target === (string) $type['id'] ? 'selected' : '' ?>><?= collectionStewardEscape($type['name'] . ($type['unit'] ? ' (' . $type['unit'] . ')' : '') . (!(int) $type['is_active'] ? ' — inactive' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td><td>
                            <?php if (!$definition['matches']): ?>
                                <label><input type="checkbox" name="columns[<?= $index ?>][approve]" value="1" <?= ($choice['approve'] ?? '') === '1' ? 'checked' : '' ?>> Approve new name: <?= collectionStewardEscape($definition['name']) ?></label>
                                <label>Value type <select name="columns[<?= $index ?>][kind]"><option value="number">Number</option><option value="text" <?= ($choice['kind'] ?? '') === 'text' ? 'selected' : '' ?>>Text / size</option></select></label>
                                <label>Unit (blank if none) <input name="columns[<?= $index ?>][unit]" maxlength="30" value="<?= collectionStewardEscape($choice['unit'] ?? $definition['unit']) ?>"></label>
                                <span class="help">Used only when adding this new measurement.</span>
                            <?php else: ?>Existing name; no new definition needed.<?php endif; ?>
                        </td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </section>
                <section><h2>Rows to import</h2>
                    <p>Uncheck any row you want to skip. If an actor has several dates, uncheck all of their rows to skip that actor entirely.</p>
                    <div class="csv-scroll"><table><thead><tr><th>Include</th><th>CSV row</th><th>Actor in CSV</th><th>Measurement date</th></tr></thead><tbody>
                    <?php foreach ($pending['data']['rows'] as $row):
                        $includeRow = !isset($pending['choices']['included_rows'])
                            || ($pending['choices']['included_rows'][$row['number']] ?? '') === '1';
                    ?>
                        <tr><td><label><input type="checkbox" name="included_rows[<?= $row['number'] ?>]" value="1" <?= $includeRow ? 'checked' : '' ?>> Include this row</label></td>
                            <td><?= $row['number'] ?></td>
                            <td><?= collectionStewardEscape($pending['data']['actors'][$row['actor_key']]['name']) ?></td>
                            <td><?= collectionStewardEscape($row['date_text']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </section>
                <section><h2>Actor matches</h2>
                    <p>Choose actor matches for included rows only. A skipped actor will not be created or imported.</p>
                    <div class="csv-scroll"><table><thead><tr><th>Name in CSV</th><th>Import measurements for</th></tr></thead><tbody>
                    <?php foreach ($pending['data']['actors'] as $key => $actor):
                        $matches = array_values(array_filter($catalog['people'], static fn($p) => measurementCsvKey($p['display_name']) === $key));
                        $defaultTarget = count($matches) === 1 ? (string) $matches[0]['id'] : ($matches ? '' : 'new');
                        $target = $pending['choices']['actors'][$actor['index']] ?? $defaultTarget;
                    ?>
                        <tr><th scope="row"><?= collectionStewardEscape($actor['name']) ?></th><td>
                            <label class="visually-hidden" for="actor-<?= $actor['index'] ?>">Match <?= collectionStewardEscape($actor['name']) ?></label>
                            <select id="actor-<?= $actor['index'] ?>" name="actors[<?= $actor['index'] ?>]">
                                <option value="">Choose actor</option>
                                <?php if (!$matches): ?><option value="new" <?= $target === 'new' ? 'selected' : '' ?>>Create actor: <?= collectionStewardEscape($actor['name']) ?></option><?php endif; ?>
                                <?php foreach ($catalog['people'] as $person): if (!(int) $person['is_active']) { continue; } ?>
                                    <option value="<?= (int) $person['id'] ?>" <?= $target === (string) $person['id'] ? 'selected' : '' ?>><?= collectionStewardEscape($person['display_name']) ?> (ID <?= (int) $person['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </section>
                <input type="hidden" name="row_selection_complete" value="1">
                <button type="submit">Preview import</button>
            </form>
        <?php else: $plan = $pending['plan']; $hasExisting = false; ?>
            <h2>Review before importing</h2>
            <p><strong><?= count($plan['rows']) ?> rows included; <?= (int) ($plan['skipped_rows'] ?? 0) ?> rows skipped by your selection.</strong></p>
            <p>Production: <strong><?= collectionStewardEscape($plan['production']['name'] ?? 'General fitting') ?></strong>. Each row below will create a new measurement session marked <strong>Needs review</strong>.</p>
            <p>Blank cells add no value. Original CSV cells are preserved. Values marked “Review original” remain available as source text until a steward corrects them.</p>
            <section><h3>Measurement definitions</h3><ul>
                <?php foreach ($plan['types'] as $type): ?>
                    <li><?= $type['id'] === null ? 'Add approved name: ' : 'Use existing: ' ?><?= collectionStewardEscape($type['name'] . ' — ' . $type['value_kind'] . ($type['unit'] ? ' (' . $type['unit'] . ')' : '')) ?></li>
                <?php endforeach; ?>
            </ul></section>
            <div class="csv-scroll"><table><thead><tr><th>CSV row</th><th>Actor</th><th>Date</th><th>Measurements to import</th></tr></thead><tbody>
                <?php foreach ($plan['rows'] as $row): $actor = $plan['actors'][$row['actor_key']]; $hasExisting = $hasExisting || (bool) $row['existing']; ?>
                    <tr><td><?= $row['number'] ?></td><td><?= collectionStewardEscape($actor['display_name']) ?><?= $actor['id'] === null ? ' (new actor)' : ' (ID ' . (int) $actor['id'] . ')' ?></td><td><?= collectionStewardEscape($row['date_text']) ?>
                        <?php if ($row['existing']): ?><p class="csv-warning">Already has <?= count($row['existing']) ?> session(s) on this date. This adds a separate session.</p><?php endif; ?>
                    </td><td>
                        <?php foreach ($row['values'] as $index => $value): ?>
                            <div class="csv-value"><strong><?= collectionStewardEscape($plan['types'][$index]['name']) ?>:</strong> <?= collectionStewardEscape($value['raw_value']) ?><?= $value['needs_review'] ? ' — Review original (numeric value not set)' : '' ?></div>
                        <?php endforeach; ?>
                    </td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <form method="post">
                <?php measurementImportHidden($csrfToken, $pending); ?>
                <input type="hidden" name="action" value="commit">
                <?php if ($hasExisting): ?><label><input type="checkbox" name="approve_separate" value="1" required> Create separate sessions for the actors and dates already recorded.</label><?php endif; ?>
                <button type="submit">Import <?= count($plan['rows']) ?> measurement sessions</button>
            </form>
            <form method="post"><?php measurementImportHidden($csrfToken, $pending); ?><button type="submit" class="secondary" name="action" value="configure">Change row, column, or actor choices</button></form>
        <?php endif; ?>
        <form method="post"><?php measurementImportHidden($csrfToken, $pending); ?><button type="submit" class="secondary" name="action" value="cancel">Cancel import</button></form>
    <?php endif; ?>
</main>
</body>
</html>
