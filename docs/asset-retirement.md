# Asset Retirement

Asset retirement removes an item from the active collection without deleting
its database record or history. It is intended both for physical items that
leave the collection and for records that were created in error.

## Steward workflow

A steward or administrator can retire an active asset from either:

- **View assets** → the full asset record → **Steward actions**; or
- **Asset review** → **Retire this asset instead of reviewing it**.

The retirement form requires a disposition, an effective date, and explicit
confirmation. A note is optional. For a record created in error when no
physical asset existed, choose **Discarded** and explain that in the note.

The available dispositions are:

- Discarded;
- Donated or transferred;
- Returned to owner or lender;
- Sold;
- Lost or missing; and
- Other.

An asset with an active production checkout cannot be retired. Check it in or
undo the mistaken checkout first.

## What retirement changes

Retirement sets the asset's separate `collection_status` to `retired`, removes
it from the asset-review queue, and adds a dated append-only record to
`asset_lifecycle_events`. It does not change or delete the asset's permanent
ID, photograph, tags, condition reviews, checkout history, strike-work
history, or prior availability status.

Retired assets are excluded from normal asset browsing and production checkout.
Authorized users can select **Include retired assets** on **View assets** to
see them. Included retired records are clearly marked and show their recorded
date, disposition, note, and user.

There is no separate **Entered in error** state and no permanent-delete action
in the web application.

## Deployment

Back up the current production database and public files before this
checkpoint. Then deploy in this order:

1. Run `database/schema/019_add_asset_retirement.sql` once against the intended
   database.
2. Upload the updated public application files:
   - `app.css`;
   - `lib/application.php`;
   - `modules/view-assets.php`;
   - `modules/asset-review.php`; and
   - `modules/production-checkout.php`.
3. Load **View assets**, **Asset review**, and **Production checkout** before
   using the retirement action.

The migration must precede the PHP files because the updated queries require
the new `collection_status` column and `asset_lifecycle_events` table.

## Smoke test

Use a genuine mistaken record or another item that is appropriate to preserve
as retired:

1. Confirm **View assets** loads with **Include retired assets** unchecked.
2. Open an active asset and confirm **Retire asset** appears under **Steward
   actions**.
3. Retire it with a disposition, date, optional note, and confirmation.
4. Confirm the asset disappears from the default list and from the review
   queue if it had been awaiting review.
5. Select **Include retired assets** and confirm the item reappears marked
   **Retired**, with its retirement record visible.
6. Uncheck **Include retired assets** and confirm all retired items disappear.
7. Confirm the retired item is absent from the production-checkout asset list.
8. Confirm an intake-only user cannot see the retired-asset checkbox or
   retirement controls.

If an asset is actively checked out, also confirm that a retirement attempt is
blocked with instructions to resolve the checkout first.

## Rollback

The safest rollback is to restore both the pre-deployment database backup and
the pre-deployment public-file backup. Restoring only the earlier PHP files
will leave the new retirement data intact, but that earlier code will not hide
retired records. Do not drop the new column or table after retirement activity
unless the database is being restored from the matching backup.
