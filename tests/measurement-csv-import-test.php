<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/measurement-csv-import.php';
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $test, string $message): void {
    try { $test(); } catch (DomainException $e) { return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
$csv = "\xEF\xBB\xBF\"Actor\",\"Measurement date\",\"Waist (in)\",\"Notes\"\r\n"
    . "\"Zoë, \"\"Z\"\"\",\"September 2026\",\"32.5\",\"line one\nline two\"\r\n"
    . "\"Empty actor\",\"\",\"\",\"\"\r\n";
$data = measurementCsvParse($csv);
check(count($data['rows']) === 1 && $data['skipped'] === 1, 'Skip empty measurements');
check($data['actors']['zoë, "z"']['name'] === 'Zoë, "Z"', 'UTF-8 quoted name');
check($data['rows'][0]['date'] === '2026-09-01' && $data['rows'][0]['precision'] === 'month', 'Month precision');
check($data['rows'][0]['cells'][3] === "line one\nline two", 'Embedded newline preserved');
check(measurementCsvDate('2024-02-29') === ['2024-02-29', 'day'], 'Leap date');
check(measurementCsvDate('2026') === ['2026-01-01', 'year'], 'Year precision');
check(measurementCsvDate('September 8, 2026 (date uncertain)') === ['2026-09-08', 'unknown'], 'Uncertain exported date');
rejects(fn() => measurementCsvDate('2026-02-29'), 'Invalid leap date');
rejects(fn() => measurementCsvDate('09/08/26'), 'Ambiguous date');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist,waist\nA,2026,1,1\n"), 'Duplicate header');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist\nA,2026,1,extra\n"), 'Ragged row');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist\nA,,1\n"), 'Missing date with value');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist\n,2026,1\n"), 'Missing actor');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist\nA,2026,\"1\n"), 'Unclosed quote');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist\nA,2026,\"1\"oops\n"), 'Text after quoted cell');
rejects(fn() => measurementCsvParse("Actor,Measurement date,Waist\nA,2026,\xFF\n"), 'Invalid UTF-8');
check(measurementCsvValue('N/A', 'number')['value_status'] === 'not_applicable', 'N/A');
check(measurementCsvValue('Not applicable', 'text')['text_value'] === null, 'Text N/A');
check(measurementCsvValue('0', 'number')['numeric_value'] === '0.00', 'Zero retained');
check(measurementCsvValue('32.50', 'number')['numeric_value'] === '32.50', 'Numeric value');
foreach (['-1', '32 inches', '9999999', '1.234', '1e2', '=1+1'] as $invalid) {
    $value = measurementCsvValue($invalid, 'number');
    check($value['needs_review'] === 1 && $value['raw_value'] === $invalid && $value['numeric_value'] === null, 'Invalid numeric source retained: ' . $invalid);
}
check(measurementCsvValue("'=1+1", 'text')['text_value'] === '=1+1', 'Export formula prefix reversed as text');
$types = [
    ['id' => 1, 'name' => 'Waist', 'unit' => 'inches', 'value_kind' => 'number'],
    ['id' => 2, 'name' => 'Nape to Waist (Back)', 'unit' => 'inches', 'value_kind' => 'number'],
];
check(measurementCsvDefinition('Waist (in)', $types)['matches'][0]['id'] === 1, 'Exported unit mapping');
check(measurementCsvDefinition('Nape to Waist (Back)', $types)['matches'][0]['id'] === 2, 'Parentheses in existing name');
check(measurementCsvDefinition('Nape to Waist (Back) (in)', $types)['matches'][0]['id'] === 2, 'Name parentheses plus units');
check(measurementCsvDefinition('New length (cm)', $types)['matches'] === [], 'Unfamiliar name remains unresolved');
check(measurementCsvDefinition('New length (cm)', $types)['unit'] === 'cm', 'New unit parsed');
echo "PASS: CSV parsing, dates/precision, malformed input, blanks/N/A, normalization/review, header matching.\n";

$selectionData = ['rows' => [
    ['number' => 2, 'actor_key' => 'alex', 'date' => '2026-09-01'],
    ['number' => 3, 'actor_key' => 'blair', 'date' => '2026-09-01'],
    ['number' => 4, 'actor_key' => 'alex', 'date' => '2026-09-02'],
]];
$chosen = measurementCsvIncludedRows($selectionData, ['included_rows' => [2 => '1', 4 => '1']]);
check(array_column($chosen, 'number') === [2, 4], 'Excluded actor has no rows in import');
$chosen = measurementCsvIncludedRows($selectionData, ['included_rows' => [4 => '1']]);
check(array_column($chosen, 'number') === [4], 'Dates for the same actor can be selected independently');
rejects(fn() => measurementCsvIncludedRows($selectionData, ['included_rows' => []]), 'No rows selected');
rejects(fn() => measurementCsvIncludedRows($selectionData, []), 'Old preview cannot silently import every row');
rejects(fn() => measurementCsvIncludedRows($selectionData, ['included_rows' => [999 => '1']]), 'Unknown row numbers');
echo "PASS: actor/row exclusion, independent date selection, empty and stale selections.\n";
