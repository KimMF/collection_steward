<?php

declare(strict_types=1);

// CSV adapter for the staging -> normalized values -> review workflow introduced
// by 015/016. No private legacy data belongs in this source file.
function measurementCsvKey(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function measurementCsvUnit(string $unit): string
{
    return match (measurementCsvKey($unit)) {
        'in', 'inch', 'inches' => 'inches',
        'lb', 'lbs', 'pound', 'pounds' => 'pounds',
        'cm', 'centimeter', 'centimeters' => 'cm',
        'mm', 'millimeter', 'millimeters' => 'mm',
        'kg', 'kilogram', 'kilograms' => 'kg',
        default => trim($unit),
    };
}

function measurementCsvDate(string $value): array
{
    $value = trim($value);
    $precision = 'day';
    if (str_ends_with($value, ' (date uncertain)')) {
        $value = substr($value, 0, -17);
        $precision = 'unknown';
    }
    $formats = ['Y-m-d' => 'day', 'F j, Y' => 'day', 'Y-m' => 'month', 'F Y' => 'month', 'Y' => 'year'];
    foreach ($formats as $format => $formatPrecision) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value && (int) $date->format('Y') >= 1000) {
            return [$date->format('Y-m-d'), $precision === 'unknown' ? 'unknown' : $formatPrecision];
        }
    }
    throw new DomainException('Use a measurement date such as 2026-09-08, September 8, 2026, September 2026, or 2026.');
}

function measurementCsvCheckSyntax(string $contents): void
{
    $state = 'start';
    $length = strlen($contents);
    for ($i = 0; $i < $length; $i++) {
        $character = $contents[$i];
        if ($state === 'quoted') {
            if ($character === '"') {
                if ($i + 1 < $length && $contents[$i + 1] === '"') { $i++; }
                else { $state = 'closed'; }
            }
        } elseif ($character === ',' || $character === "\r" || $character === "\n") {
            $state = 'start';
        } elseif ($character === '"' && $state === 'start') {
            $state = 'quoted';
        } elseif ($state === 'closed' || $character === '"') {
            throw new DomainException('The CSV has malformed quoting. Save it again as CSV from your spreadsheet application.');
        } else { $state = 'unquoted'; }
    }
    if ($state === 'quoted') { throw new DomainException('The CSV ends inside a quoted cell. Save it again as CSV.'); }
}

function measurementCsvParse(string $contents): array
{
    if (strlen($contents) > 2 * 1024 * 1024 || !preg_match('//u', $contents) || str_contains($contents, "\0")) {
        throw new DomainException('Upload a UTF-8 CSV file no larger than 2 MB.');
    }
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
    measurementCsvCheckSyntax($contents);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $contents);
    rewind($stream);
    try {
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if ($headers === false || count($headers) < 3 || count($headers) > 80) {
            throw new DomainException('Use Actor, Measurement date, and one or more measurement columns (maximum 80 columns).');
        }
        $headers = array_map(static fn($v) => trim((string) $v), $headers);
        $keys = array_map('measurementCsvKey', $headers);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new DomainException('Each CSV column must have a different name.');
        }
        foreach ($headers as $header) {
            if ($header === '' || strlen($header) > 150 || preg_match('/[\r\n\t]/', $header)) {
                throw new DomainException('Column names must be nonempty, on one line, and at most 150 bytes.');
            }
        }
        $actorIndex = array_search('actor', $keys, true);
        $dateIndex = array_search('measurement date', $keys, true);
        if ($actorIndex === false || $dateIndex === false) {
            throw new DomainException('The CSV must include Actor and Measurement date columns. A CSV exported from Measurements has this format.');
        }
        $columns = [];
        foreach ($headers as $index => $header) {
            if ($index !== $actorIndex && $index !== $dateIndex) {
                $columns[$index] = $header;
            }
        }
        $rows = [];
        $actors = [];
        $skipped = 0;
        $number = 1;
        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $number++;
            if ($number > 501) {
                throw new DomainException('Import at most 500 rows at a time.');
            }
            if ($cells === [null]) { continue; }
            if (count($cells) !== count($headers)) {
                throw new DomainException("Row {$number}: the number of cells does not match the headers.");
            }
            $cells = array_map(static fn($v) => (string) $v, $cells);
            foreach ($cells as $cell) {
                if (strlen($cell) > 255) {
                    throw new DomainException("Row {$number}: each cell must be at most 255 bytes.");
                }
            }
            $hasValues = false;
            foreach ($columns as $index => $_) {
                if (trim($cells[$index]) !== '') { $hasValues = true; }
            }
            if (!$hasValues) { $skipped++; continue; }
            $actor = trim($cells[$actorIndex]);
            // Reverse only the protective prefix used by our own CSV exporter.
            if (preg_match('/^\'[\s\x{FEFF}]*[=+@-]/u', $actor)) { $actor = substr($actor, 1); }
            if ($actor === '' || strlen($actor) > 150 || preg_match('/[\r\n\t]/', $actor)) {
                throw new DomainException("Row {$number}: supply an actor name on one line (at most 150 bytes).");
            }
            try { [$date, $precision] = measurementCsvDate($cells[$dateIndex]); }
            catch (DomainException $e) { throw new DomainException("Row {$number}: " . $e->getMessage()); }
            $actorKey = measurementCsvKey($actor);
            if (!isset($actors[$actorKey])) { $actors[$actorKey] = ['name' => $actor, 'index' => count($actors)]; }
            $rows[] = ['number' => $number, 'actor_key' => $actorKey, 'date' => $date, 'precision' => $precision,
                'date_text' => trim($cells[$dateIndex]), 'cells' => $cells];
        }
        if (!$rows) { throw new DomainException('No measurements found. Blank measurement rows are skipped.'); }
        if (count($actors) > 200) { throw new DomainException('Import at most 200 actors at a time.'); }
        return compact('headers', 'columns', 'rows', 'actors', 'skipped');
    } finally { fclose($stream); }
}

function measurementCsvDefinition(string $header, array $types): array
{
    // Try the entire name first: parentheses may be part of a measurement name.
    $name = $header;
    $unit = '';
    foreach ($types as $type) {
        if (measurementCsvKey($header) === measurementCsvKey($type['name'])) {
            return ['name' => $type['name'], 'unit' => (string) $type['unit'], 'matches' => array_values(array_filter($types,
                static fn($t) => measurementCsvKey($t['name']) === measurementCsvKey($header)))];
        }
    }
    if (preg_match('/^(.*?)\s+\(([^()]+)\)$/u', $header, $match)) {
        $candidateUnit = measurementCsvUnit($match[2]);
        // Only recognize known units here; e.g. Nape to Waist (Back) is a name.
        $knownUnits = array_merge(['inches', 'pounds', 'cm', 'mm', 'kg'], array_map(
            static fn($t) => measurementCsvUnit((string) $t['unit']), $types));
        if (in_array($candidateUnit, $knownUnits, true)) {
            $name = trim($match[1]);
            $unit = $candidateUnit;
        }
    }
    $matches = array_values(array_filter($types, static fn($t) => measurementCsvKey($t['name']) === measurementCsvKey($name)));
    return compact('name', 'unit', 'matches');
}

function measurementCsvValue(string $raw, string $kind): array
{
    $value = trim($raw);
    if (preg_match('/^\'[\s\x{FEFF}]*[=+@-]/u', $value)) { $value = substr($value, 1); }
    if (in_array(strtolower($value), ['n/a', 'not applicable'], true)) {
        return ['raw_value' => $raw, 'numeric_value' => null, 'text_value' => null, 'value_status' => 'not_applicable', 'needs_review' => 0];
    }
    $numeric = $kind === 'number' && preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value);
    return ['raw_value' => $raw, 'numeric_value' => $numeric ? number_format((float) $value, 2, '.', '') : null,
        'text_value' => $kind === 'text' ? $value : null, 'value_status' => 'recorded',
        'needs_review' => $kind === 'number' && !$numeric ? 1 : 0];
}

function measurementCsvCatalog(PDO $db, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    return [
        'types' => $db->query('SELECT id, code, name, value_kind, unit, is_active FROM measurement_types ORDER BY id' . $suffix)->fetchAll(),
        'people' => $db->query('SELECT id, display_name, first_name, last_name, is_active FROM people ORDER BY id' . $suffix)->fetchAll(),
        'productions' => $db->query('SELECT id, name, production_year FROM productions ORDER BY id' . $suffix)->fetchAll(),
    ];
}

function measurementCsvIncludedRows(array $data, array $choices): array
{
    $included = is_array($choices['included_rows'] ?? null) ? $choices['included_rows'] : [];
    $rows = array_values(array_filter($data['rows'], static fn($row) => ($included[$row['number']] ?? '') === '1'));
    if (!$rows) {
        throw new DomainException('Select at least one row to import. Return to row choices if this preview was opened before the row-selection update.');
    }
    return $rows;
}

function measurementCsvPlan(PDO $db, array $pending, array $choices, bool $lock = false): array
{
    $sourceRows = measurementCsvIncludedRows($pending['data'], $choices);
    $includedActorKeys = array_fill_keys(array_column($sourceRows, 'actor_key'), true);
    $skippedRows = count($pending['data']['rows']) - count($sourceRows);
    $catalog = measurementCsvCatalog($db, $lock);
    $typesById = array_column($catalog['types'], null, 'id');
    $peopleById = array_column($catalog['people'], null, 'id');
    $productionId = ($choices['production_id'] ?? '') === '' ? null : filter_var($choices['production_id'], FILTER_VALIDATE_INT);
    $production = null;
    if ($productionId !== null) {
        foreach ($catalog['productions'] as $item) { if ((int) $item['id'] === $productionId) { $production = $item; } }
        if (!$production) { throw new DomainException('Choose an available production.'); }
    }
    $typePlan = [];
    $usedTypes = [];
    foreach ($pending['data']['columns'] as $index => $header) {
        $choice = $choices['columns'][$index] ?? [];
        $target = is_string($choice['target'] ?? null) ? $choice['target'] : '';
        $definition = measurementCsvDefinition($header, $catalog['types']);
        if ($target === 'new') {
            if ($definition['matches']) {
                throw new DomainException("{$header}: this name already exists. Select its existing measurement definition.");
            }
            if (($choice['approve'] ?? '') !== '1') {
                throw new DomainException("{$header}: approve this new measurement name or map it to an existing measurement.");
            }
            $kind = $choice['kind'] ?? '';
            $unit = is_string($choice['unit'] ?? null) ? measurementCsvUnit($choice['unit']) : '';
            if (!in_array($kind, ['number', 'text'], true) || strlen($unit) > 30 || preg_match('/[\r\n\t]/', $unit)) {
                throw new DomainException("{$header}: choose Number or Text and a unit of at most 30 bytes.");
            }
            if ($definition['unit'] !== '' && $unit !== $definition['unit']) {
                throw new DomainException("{$header}: the new measurement unit must match the CSV header.");
            }
            $type = ['id' => null, 'name' => $definition['name'], 'value_kind' => $kind, 'unit' => $unit, 'is_active' => 1];
            $key = 'new:' . measurementCsvKey($definition['name']);
        } else {
            $id = filter_var($target, FILTER_VALIDATE_INT);
            $type = $typesById[$id] ?? null;
            if (!$type) { throw new DomainException("{$header}: select a measurement definition."); }
            if ($definition['unit'] !== '' && measurementCsvUnit((string) $type['unit']) !== $definition['unit']) {
                throw new DomainException("{$header}: units do not match {$type['name']}. Convert the CSV values and header to the database unit before importing.");
            }
            $key = 'id:' . $type['id'];
        }
        if (isset($usedTypes[$key])) { throw new DomainException("{$header}: two columns map to the same measurement. Combine or rename them before importing."); }
        $usedTypes[$key] = true;
        $typePlan[$index] = $type;
    }
    $actorPlan = [];
    foreach ($pending['data']['actors'] as $key => $actor) {
        if (!isset($includedActorKeys[$key])) { continue; }
        $target = $choices['actors'][$actor['index']] ?? '';
        $matches = array_values(array_filter($catalog['people'], static fn($p) => measurementCsvKey($p['display_name']) === $key));
        if ($target === 'new') {
            if ($matches) { throw new DomainException($actor['name'] . ': an actor with this name already exists. Select the correct actor.'); }
            $actorPlan[$key] = ['id' => null, 'display_name' => $actor['name'], 'first_name' => null, 'last_name' => null];
        } else {
            $id = filter_var($target, FILTER_VALIDATE_INT);
            if (!isset($peopleById[$id]) || !(int) $peopleById[$id]['is_active']) {
                throw new DomainException($actor['name'] . ': select an active actor or create a new actor.');
            }
            $actorPlan[$key] = $peopleById[$id];
        }
    }
    $rows = [];
    $seen = [];
    foreach ($sourceRows as $row) {
        $actor = $actorPlan[$row['actor_key']];
        $identity = ($actor['id'] === null ? 'new:' . $row['actor_key'] : 'id:' . $actor['id']) . '|' . $row['date'];
        if (isset($seen[$identity])) {
            throw new DomainException('Rows ' . $seen[$identity] . ' and ' . $row['number'] . ' refer to the same actor and date. Combine them or import separate sessions in separate files.');
        }
        $seen[$identity] = $row['number'];
        $existing = [];
        if ($actor['id'] !== null) {
            $statement = $db->prepare('SELECT id, production_id, date_precision, session_sequence FROM measurement_sessions WHERE person_id = ? AND measured_on = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
            $statement->execute([$actor['id'], $row['date']]);
            $existing = $statement->fetchAll();
        }
        $values = [];
        foreach ($typePlan as $index => $type) {
            if (trim($row['cells'][$index]) !== '') { $values[$index] = measurementCsvValue($row['cells'][$index], $type['value_kind']); }
        }
        $rows[] = $row + ['values' => $values, 'existing' => $existing];
    }
    $duplicate = $db->prepare('SELECT id FROM measurement_import_batches WHERE source_sha256 = ?');
    $duplicate->execute([$pending['sha256']]);
    if ($duplicate->fetchColumn() !== false) { throw new DomainException('This exact CSV file has already been imported. No records were added.'); }
    return ['types' => $typePlan, 'actors' => $actorPlan, 'production' => $production, 'rows' => $rows, 'skipped_rows' => $skippedRows];
}

function measurementCsvInsert(PDO $db, string $table, array $values): int
{
    // Table and field names are supplied only by the application below.
    $statement = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
    return (int) $db->lastInsertId();
}

function measurementCsvCommit(PDO $db, array $pending, int $userId, bool $approveSeparate): int
{
    $db->beginTransaction();
    try {
        $plan = measurementCsvPlan($db, $pending, $pending['choices'], true);
        if (!hash_equals($pending['plan_hash'], hash('sha256', serialize($plan)))) {
            throw new DomainException('The matching records changed since preview. Return to column and actor choices, then preview again.');
        }
        foreach ($plan['rows'] as $row) {
            if ($row['existing'] && !$approveSeparate) {
                throw new DomainException('Approve creating separate sessions for the actors and dates already recorded.');
            }
        }
        $batchId = measurementCsvInsert($db, 'measurement_import_batches', [
            'source_name' => $pending['filename'], 'source_sha256' => $pending['sha256'],
            'source_description' => 'CSV import by user ' . $userId . '; source rows/cells preserved. Blank measurement rows skipped: ' . $pending['data']['skipped'] . '; rows excluded by steward: ' . $plan['skipped_rows'],
        ]);
        foreach ($plan['types'] as &$type) {
            if ($type['id'] === null) {
                $type['id'] = measurementCsvInsert($db, 'measurement_types', [
                    'code' => 'csv_' . bin2hex(random_bytes(16)), 'name' => $type['name'],
                    'value_kind' => $type['value_kind'], 'unit' => $type['unit'] === '' ? null : $type['unit'],
                ]);
            }
        }
        unset($type);
        foreach ($plan['types'] as $index => $type) {
            measurementCsvInsert($db, 'measurement_import_column_maps', ['import_batch_id' => $batchId,
                'source_column_name' => $pending['data']['columns'][$index], 'measurement_type_id' => $type['id'],
                'mapping_notes' => 'CSV column mapping approved by steward ' . $userId]);
        }
        foreach ($plan['actors'] as &$actor) {
            if ($actor['id'] === null) {
                $actor['id'] = measurementCsvInsert($db, 'people', ['display_name' => $actor['display_name']]);
            }
        }
        unset($actor);
        $firstSessionId = 0;
        foreach ($plan['rows'] as $row) {
            $actor = $plan['actors'][$row['actor_key']];
            $productionId = $plan['production']['id'] ?? null;
            $sequence = 1;
            foreach ($row['existing'] as $existing) {
                if ($existing['production_id'] == $productionId) { $sequence = max($sequence, (int) $existing['session_sequence'] + 1); }
            }
            $sessionId = measurementCsvInsert($db, 'measurement_sessions', [
                'person_id' => $actor['id'], 'production_id' => $productionId, 'measured_on' => $row['date'],
                'date_precision' => $row['precision'], 'session_sequence' => $sequence, 'review_status' => 'needs_review',
                'source_import_batch_id' => $batchId, 'notes' => 'Imported from CSV by steward ' . $userId . '. Original cells are preserved.',
            ]);
            $firstSessionId = $firstSessionId ?: $sessionId;
            $sourceRowId = measurementCsvInsert($db, 'measurement_import_rows', [
                'import_batch_id' => $batchId, 'source_sheet' => 'CSV', 'source_row_number' => $row['number'],
                'resolved_venue_name' => '', 'production_text' => $plan['production']['name'] ?? '',
                'measurement_period_text' => substr($row['date_text'], 0, 30), 'measured_on' => $row['date'],
                'resolved_character_name' => '', 'first_name_text' => $actor['first_name'] ?? '', 'last_name_text' => $actor['last_name'] ?? '',
                'session_import_key' => hash('sha256', $pending['sha256'] . ':' . $row['number']),
                'normalized_person_id' => $actor['id'], 'normalized_production_id' => $productionId,
                'normalized_measurement_session_id' => $sessionId, 'needs_review' => 1,
                'review_notes' => 'CSV source. Actor and date text are preserved in source cells; review in Measurements.',
            ]);
            measurementCsvInsert($db, 'measurement_session_import_rows', ['measurement_session_id' => $sessionId, 'import_row_id' => $sourceRowId]);
            foreach ($row['cells'] as $index => $raw) {
                $value = $row['values'][$index] ?? null;
                $cellId = measurementCsvInsert($db, 'measurement_import_cells', ['import_row_id' => $sourceRowId,
                    'source_column_name' => $pending['data']['headers'][$index], 'raw_value' => $raw,
                    'needs_review' => $value['needs_review'] ?? 0,
                    'review_notes' => !empty($value['needs_review']) ? 'Not a nonnegative decimal with at most two decimal places; original text retained for review.' : null]);
                if ($value === null) { continue; }
                $typeId = $plan['types'][$index]['id'];
                $valueId = measurementCsvInsert($db, 'measurement_values', $value + [
                    'measurement_session_id' => $sessionId, 'measurement_type_id' => $typeId,
                    'source_import_cell_id' => $cellId,
                    'review_notes' => $value['needs_review'] ? 'Review the original CSV value before accepting.' : null,
                ]);
                measurementCsvInsert($db, 'measurement_value_history', [
                    'measurement_session_id' => $sessionId, 'measurement_type_id' => $typeId, 'measurement_value_id' => $valueId,
                    'change_action' => 'recorded', 'new_raw_value' => $value['raw_value'], 'new_numeric_value' => $value['numeric_value'],
                    'new_text_value' => $value['text_value'], 'new_value_status' => $value['value_status'], 'new_needs_review' => $value['needs_review'],
                    'source_import_cell_id' => $cellId, 'source_context' => 'csv_import',
                    'change_reason' => 'Imported CSV row ' . $row['number'] . '; original source preserved.', 'changed_by_user_id' => $userId,
                ]);
            }
        }
        $db->commit();
        return $firstSessionId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}
