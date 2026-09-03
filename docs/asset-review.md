# Asset Review — Version 3 Checkpoint 1

## Purpose

This checkpoint adds a steward-only asset-review queue. Every new intake item
enters the queue automatically. Existing assets remain outside the queue until
a steward deliberately selects **Send to asset review** from the full asset
record.

A completed review can correct the asset's descriptive fields, tags, notes,
acquisition information, location, and primary photograph. It also creates a
dated condition record for smell, stains, damage, wear, and general condition.
Each condition uses **Clear**, **Issue found**, or **Not assessed**, with an
optional note.

Earlier condition reviews are retained as history.

## Database migration

Back up the production database, then run this file once through phpMyAdmin:

`database/schema/018_add_asset_review.sql`

Run the migration before uploading the changed PHP files. The migration:

- adds the current review-queue state to `assets`;
- leaves existing assets outside the queue until selected by a steward;
- makes future assets default to pending review; and
- creates `asset_condition_reviews` for dated, append-only review records.

## Files to deploy

Upload these files while preserving their paths:

- `asset-review.php`
- `app.css`
- `lib/application.php`
- `modules/asset-review.php`
- `modules/intake.php`
- `modules/measurements.php`
- `modules/password.php`
- `modules/production-checkout.php`
- `modules/users.php`
- `modules/view-assets.php`
- `modules/vocabulary.php`

Do not upload the `database` or `docs` directories into the public web root.

## Verification

### Existing asset

1. Sign in as an administrator or steward.
2. Open an existing full asset record.
3. Confirm that its review state says **Not queued** if it has never been
   reviewed.
4. Open **Steward actions** and select **Send to asset review**.
5. Confirm that the Asset review page opens with that asset selected.
6. Leave at least one condition as **Not assessed**, mark one **Clear**, and
   mark one **Issue found** with a note.
7. Correct one harmless descriptive field or note, then select **Save changes
   and mark reviewed**.
8. Confirm that the asset leaves the queue and its full record reports the
   review date and reviewer.
9. Send the same asset to review again and confirm that the earlier condition
   review appears as history.

### New intake item

1. Enter a test donation through Intake.
2. Confirm that the saved-item card says **Awaiting steward review**.
3. Open Asset review and confirm that the new asset appears in the queue.
4. Complete its review and confirm that it leaves the queue.

### Correction and naming

1. Review an asset with standardized size **Small**, exact label **10**, and
   length **Medium**.
2. Confirm that the saved name uses
   `Size: Small (10); Length: Medium`.
3. Review an asset without a known size and confirm that its generated name
   uses `Size: Not recorded`.
4. Select a replacement photograph and confirm that it becomes the primary
   photograph while the earlier database photo record remains intact.

### Permissions and regression checks

1. Confirm that administrator and steward accounts can open
   `/asset-review.php`.
2. Confirm that an intake-only account receives **Access denied** when opening
   `/asset-review.php` directly.
3. Confirm that existing asset browsing, intake, vocabulary review,
   measurements, production checkout, Users, and Password pages still open.
4. Repeat the queue and form checks on a phone held vertically.

## Rollback note

The migration is intentionally not accompanied by an automatic destructive
rollback. If deployment must be reversed, restore the pre-migration database
backup and the previous PHP/CSS files together so the code and schema remain
matched.
