# CSV import and a worksheet without actors

Starting point: `84caba0` (CSV export tested and pushed by Kim).

## Workflow

Open **Measurements → Import CSV**. Upload a UTF-8 CSV with **Actor**,
**Measurement date**, and measurement columns, such as **Waist (in)**. The format
matches the current CSV export. Maximum: 2 MB, 500 data rows, 80 total columns,
and 200 distinct actor names. Use a separate file for each production; select
its production during import, or leave General fitting selected.

1. Match measurement columns and actors. Existing measurement names are matched
   without case/spacing differences; exported unit abbreviations are recognized.
   Ambiguous actor names require choosing an actor ID. Unknown actor names can
   be matched to an existing actor or created using their full display name.
2. Each unfamiliar measurement name must either map to an existing definition
   or have its **Approve new name** checkbox selected. For new definitions,
   choose Number or Text / size and confirm the unit. Units are never converted.
3. Preview the complete rows, definitions, and actor matches. Nothing has been
   written to the database at this point.
4. Import the previewed rows. Each becomes a new dated session in the existing
   review queue. If an actor already has a session on the same normalized date,
   explicitly approve a separate session before importing. Existing sessions
   and values are never overwritten. Re-uploading exactly the same file is
   rejected. Repeated actor/date rows within one CSV must first be combined or
   placed in separate files.

Dates accept ISO days/months/years and the export's English display formats,
including `(date uncertain)`. Precision is retained. Blank cells add no value;
rows with no measurements are skipped. N/A and Not applicable use the existing
not-applicable status. Invalid numeric values (including units inside cells,
fractions, negative values, excess precision, or out-of-range decimals) are
retained as raw source text and flagged for review, with no fabricated numeric
value. Correct these using the existing Measurements review interface.

An uploaded preview expires after one hour and is bound to the signed-in user
and one import token. Import revalidates database matches under transaction
locks. Stale previews must be regenerated. A failed write rolls back the entire
batch. Import requires the existing measurements capability and a valid CSRF
token. New actors retain their full display name; first/last name fields can be
edited later without guessing how a full name should be divided.

## Reuse of the initial import

The original private package contains a generated SQL import, rather than an
upload parser. Its source-row/cell preservation, column-map, normalized-session,
value, and review workflow is reused in a PHP adapter. The existing tables from
migrations 015, 021, and 022 are used; no new migration is needed. Source cells
remain preserved independently from later corrections. New values receive
append-only history entries attributed to the importing steward. The private
legacy data is not included in this change.

## Display: None

In Compact layout, choose **Display → None**, choose measurement columns, and
select **Print blank worksheet**. The table has twelve empty rows with Actor
and Measurement date columns. Printed headings contain no actor or production
names. Selected-actor and production-cast worksheets retain their existing
behavior. The page also has a **Blank worksheet without actors** shortcut, so
an empty review list or a database with no measurement sessions does not prevent
printing. Page action links and notices are hidden in print output.

## Changed production files

| Repository file | Server destination |
| --- | --- |
| `lib/measurement-csv-import.php` (new) | `/home/guntersv/public_html/lib/measurement-csv-import.php` |
| `modules/measurement-import.php` (new) | `/home/guntersv/public_html/modules/measurement-import.php` |
| `measurement-import.php` (new) | `/home/guntersv/public_html/measurement-import.php` |
| `app.css` (updated) | `/home/guntersv/public_html/app.css` |
| `modules/measurements.php` (updated) | `/home/guntersv/public_html/modules/measurements.php` |

Commit/push the changes and back up the two existing server files using the
manual deployment procedure. Upload in the order above, with the Measurements
page last. Documentation and tests stay in the repository. No source-data file,
private configuration, or database migration is part of this upload.

## Validation status

`git diff --check`, JavaScript syntax, and static source checks passed. The
existing CSV export regression checks also passed with the updated page script
(single actor/cast, selected columns, blanks, N/A, Unicode, and quoting). PHP CLI/MySQL and a browser
are unavailable in this editing workspace, so PHP execution, SQL integration,
and visual print-preview tests have **not** been run here. Regression tests are
included at `tests/measurement-csv-import-test.php`; run with PHP CLI before
release when available. The tests cover parsing, malformed input, exported
dates/units, missing data, numeric review behavior, and measurement-name matching.

Required live verification, preferably using test actors:

1. Choose None with a session selected, then from an empty review/search result.
   Print preview must show twelve empty Actor/date rows and no named people or
   productions. Test a subset of columns and multiple printed pages.
2. Verify Selected actor, Production cast, current PDF, CSV export, and existing
   named-actor blank worksheets still work.
3. Upload a small CSV with an existing actor/measurement, blank, N/A, and an
   invalid numeric value. Verify the preview. Cancel once and confirm no writes.
4. Add an unfamiliar header. Preview must refuse new-definition creation unless
   approved. Mapping it to an existing measurement should require no new name.
5. Preview an existing actor/date. Import must require separate-session approval.
   Confirm existing values remain unchanged after import.
6. Complete an import and confirm source/history, review flags, date precision,
   and production. Re-upload the same file and confirm it is rejected.
7. Confirm malformed CSV, missing date with values, wrong units, duplicate
   headers, conflicting actor/date rows, and stale preview tokens do not write.

Rollback: restore `modules/measurements.php` and `app.css`, then remove the three
new import files to disable the feature. This does not undo completed imports;
use the recorded import batch/session IDs for any required data review.

## Follow-up: skip selected actor rows

The configuration page now includes **Rows to import**, with **Include this row**
checked initially for every actor/date row. Uncheck a row to skip it; uncheck all
rows for an actor to skip that actor entirely. Actor matches are required only
for included rows. The preview shows included/skipped counts and displays only
included rows. Changing choices retains the selection. At least one row must
remain included. Incomplete/truncated forms are rejected rather than silently
omitting rows.

Excluded actors are removed before planning and are never created by this
import; their sessions, source rows/cells, values, and history are not written.
The batch description records the excluded-row count. Existing duplicate-file
protection remains: after a partial import, save the skipped rows in a separate
CSV if you later want to import them.

Only two production files change for this follow-up:

1. `lib/measurement-csv-import.php`
2. `modules/measurement-import.php`

Commit/push and back up these two server files, then upload the helper followed
by the module. Reopen the import page and regenerate any older preview. No
change to `app.css`, `modules/measurements.php`, or the root entry file is needed.

Verification: upload a small CSV, uncheck an actor row, and confirm the preview
omits it and reports the expected count. Return to choices and confirm it stays
unchecked. A skipped new/ambiguous actor should not require a match. Test that
clearing every checkbox prevents import. Confirm a completed import creates
records only for included rows. PHP regression cases were added, but could not
be executed in this workspace; server verification is still required.
