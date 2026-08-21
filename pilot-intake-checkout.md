# Incoming Donation and Production Checkout Pilot

## Purpose

This increment adds two independent workflows:

1. Simple entry of a newly donated asset by a nontechnical user.
2. Checkout of an existing collection asset to an actor and character in a
   specific production.

The first checkout production is *Steel Magnolias*, opening September 25, 2026.

## Deliberate limits

- The pilot does not attempt to inventory the existing storage blob.
- Assets pulled from the blob for *Steel Magnolias* may be entered directly in
  phpMyAdmin before checkout.
- Incoming donation entry does not check an asset out.
- Checkout does not yet implement set-strike check-in or triage.
- Personal measurements and detailed costume plots are deferred.
- Permanent rack and bin records are deferred, although the intake form accepts
  an existing physical identifier in the location field.

## Incoming donation test

The benchmark user is Sonya, who is familiar with modern spreadsheet software
but is neither a costumer nor a technical computer user.

The test succeeds when Sonya can:

1. Sign in without receiving database or hosting access.
2. Photograph an incoming donation from a phone or choose an existing image.
3. Enter a short item name.
4. Add any category, text size, color, tags, or description she knows.
5. Leave uncertain information blank.
6. Record where she placed the item.
7. Save the record and find the resulting asset page.

Only the short item name is required. The application creates the asset photo
directory, prepares large camera images for upload when the browser supports
it, stores the uploaded image, and creates the `asset_photos` link.

## Checkout test

A costumer sets up the short cast list for *Steel Magnolias*. Each cast entry
links a person to the character portrayed in this production.

For each collection costume selected for the show, the costumer:

1. Selects the actor and character.
2. Selects an available asset record.
3. Adds an optional short note.
4. Checks out the asset.

An asset with an active checkout is removed from the available list. A mistaken
checkout can be undone without deleting its history.

## Database migrations

Run these files once, in order, using phpMyAdmin:

1. `database/schema/007_add_asset_intake_fields.sql`
2. `database/schema/008_create_production_checkout.sql`
3. `database/schema/009_seed_steel_magnolias.sql`

Migration 009 creates *Steel Magnolias* with an opening date of September 25,
2026. Actor and character names are entered through the checkout page.

Back up the production database before running the migrations. The existing
0.1.0 application will ignore the added columns and tables if file deployment
must be postponed or rolled back.

## Files to deploy

Upload these application files while preserving their paths:

- `index.php`
- `app.css`
- `intake.php`
- `checkout.php`
- `lib/application.php`

Do not upload the `database` or `docs` directories into the public web root.
Use the SQL files through phpMyAdmin and retain documentation in the repository.

## Verification

After deployment:

1. Confirm the public asset display still works while signed out.
2. Sign in and confirm the two new navigation links appear.
3. Add one test donation without a photograph.
4. Add one test donation with a photograph.
5. Confirm each appears in the asset selector and public display.
6. Add one actor and character to *Steel Magnolias*.
7. Check out one test asset to that cast assignment.
8. Confirm it appears under Currently checked out and no longer appears in the
   available-asset selector.
9. Undo that test checkout and confirm the asset becomes available again.

Use actual pilot records only after these checks succeed.
