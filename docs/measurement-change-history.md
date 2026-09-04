# Immutable Measurement Change History

Version 3 adds a private, append-only history for normalized measurement
values. Every value recorded, accepted, corrected, marked **Not applicable**,
or removed through the Measurements page receives a separate history event in
the same database transaction as the value change.

The history is available in the selected actor session's Expanded view under
**Measurement change history**. It is read-only: Collection Steward provides
no action for editing or deleting an event.

## Data model

Migration `022_add_measurement_value_history.sql` creates
`measurement_value_history`. Each event stores:

- the measurement session, measurement type, and value-record ID when one
  exists;
- the explicit action;
- complete before-and-after value and review-flag snapshots;
- the imported source-cell ID when applicable;
- a source description and reason;
- the user who made the change; and
- the timestamp.

The value-record ID and imported source-cell ID are deliberately retained as
snapshots rather than foreign keys. A **Removed** event therefore continues to
identify the deleted normalized value, and its imported source reference is
not erased if source data is later maintained outside this workflow.

The migration also creates one **History started** baseline event for every
measurement value that already exists when the migration runs. A baseline
captures the current state; it does not claim to reconstruct edits made before
history tracking began. Its timestamp uses the latest recorded review time,
or the value's creation time when no review time exists.

## Recorded actions

- **Recorded**: a new measured value is entered.
- **Corrected**: an existing normalized value or status changes.
- **Accepted**: a flagged value is explicitly accepted without changing its
  displayed value.
- **Removed**: the normalized stored value is deleted; its history remains.
- **Marked Not applicable**: a new or existing field is explicitly given that
  status.
- **History started**: the migration's one-time baseline for a value that
  predates this feature.

The optional **Reason or source for these changes** field applies one note to
every action in a single save. When it is blank, Collection Steward records an
action-specific reason automatically.

## Imported source protection

Corrections, acceptance, Not applicable decisions, and removals continue to
operate only on the normalized `measurement_values` records. The import batch,
row, column, and cell tables are not updated. History events snapshot the
original raw text and source-cell ID so the read-only display can continue to
show the original import alongside the action.

## Deployment

Back up the production database and public application files before this
checkpoint. Deploy in this order:

1. Run `database/schema/022_add_measurement_value_history.sql` once against
   the intended database.
2. Upload the changed public application files:
   - `app.css`;
   - `modules/measurements.php`; and
   - `modules/users.php`.
3. Open **Measurements** in both Compact and Expanded view before recording
   further values.

The migration must precede the PHP file because the updated Measurements page
reads and writes `measurement_value_history`.

## Smoke test

Use a test session or values that can safely be changed:

1. Open an existing session in Expanded view and confirm its current values
   still appear.
2. Expand **Measurement change history** and confirm existing values have
   **History started** entries with the expected current state.
3. Record a previously blank measurement with a short reason. Confirm a
   **Recorded** event shows no prior value, the new value, the signed-in user,
   timestamp, and reason.
4. Correct that value and confirm a **Corrected** event shows the old and new
   values while the earlier event remains.
5. Mark a suitable field **Not applicable** and confirm the explicit history
   action.
6. If a flagged value is available, accept it without changing it and confirm
   an **Accepted** event is added.
7. Remove the test value and confirm a **Removed** event remains after the
   normalized value disappears.
8. For an imported value, confirm **Original import** remains unchanged in the
   form and in its history after correction or removal.
9. Confirm the history contains no edit or delete controls.
10. Confirm Compact view, the review queue, actor history, printable
    worksheets, Thigh, and Pants Size still behave normally.
11. Confirm an intake-only account still cannot open the private Measurements
    workspace.
12. Confirm the **Users** page provides the same **Sign out** control as the
    other private workspaces.

This checkpoint also corrects the missing **Sign out** control discovered on
the **Users** page during permission testing. It uses the established logout
action and does not change account administration behavior.

## Rollback

The safest rollback is to restore both the pre-deployment database backup and
the matching public-file backup. Restoring only the earlier PHP and CSS files
leaves the additive history table and baseline events intact, but earlier code
will neither display new history nor create events for later value changes.

Do not drop or empty `measurement_value_history` after the feature has been
used. That would permanently discard the change record the checkpoint is
designed to preserve.
