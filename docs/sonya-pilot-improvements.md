# Sonya Pilot Improvements

## Purpose

This increment incorporates observations from Sonya's first hands-on intake
test. It preserves the working donation-entry workflow while making access,
tag selection, saved-item confirmation, and asset browsing easier to use as the
collection grows.

The Android camera application's missing accept control is deliberately not
changed in this increment. The working alternative is to take photographs
first and select the saved files during intake.

## Current office workflow

For a batch of items, place a temporary numbered card with each item and include
that number in its photograph. After photographing the batch, send the images
to the office account and save them on the office computer. Enter the items from
that computer, then write each permanent Collection Steward asset ID on its
temporary card. This preserves the link among the physical item, its photograph,
and its database record if the work is interrupted.

## Included changes

- Add role-based access with administrator, steward, and intake-only roles.
- Add an administrator-only Users page.
- Allow an administrator to create an individual intake-only account without
  storing a password in SQL or source files.
- Keep intake on its own focused screen.
- Replace the fixed tag list with a searchable, scrollable multi-select list.
- Show the complete saved item immediately after intake, with actions to enter
  another item or open its full record.
- Replace the asset dropdown and View button with a scrollable asset browser.
- Group assets by type/category.
- Search assets by ID, name, description, size, color, type, or tag.
- Update the asset preview after scrolling pauses, or when an item receives
  keyboard focus or pointer attention.

## Database migration

Back up the database, then run this file once through phpMyAdmin:

`database/schema/010_add_user_roles.sql`

The migration gives all existing users administrator access so their existing
permissions are preserved. Accounts created afterward default to intake-only
access.

Run the migration before uploading the changed PHP files. The older application
ignores the new role column, while the new application requires it.

## Files to deploy

Upload these files while preserving their paths:

- `index.php`
- `intake.php`
- `checkout.php`
- `users.php`
- `app.css`
- `lib/application.php`

Do not upload the `database` or `docs` directories into the public web root.

## Create Sonya's account

After the migration and files are deployed:

1. Sign in with the existing Kim account.
2. Open **Users**.
3. Leave the suggested username `sonya` and display name `Sonya`, or change them
   before saving.
4. Leave **Access** set to **Intake only**.
5. Enter and confirm a temporary password of 12–255 characters.
6. Select **Create account**.
7. Give Sonya the username and temporary password privately. Do not add the
   password to GitHub or project notes.
8. At her first login, Sonya must enter the temporary password again and choose
   a private replacement before she can use the rest of the application.

## Verification

### Administrator account

1. Sign in with the existing Kim account.
2. Confirm that View assets, Intake, Production checkout, and Users are visible.
3. Confirm that an asset's Steward actions remain available.
4. Confirm that production checkout still opens.

### Intake-only account

1. Sign out and sign in with Sonya's username and temporary password.
2. Confirm that the application requires a private replacement password.
3. Change the password, then confirm that View assets and Intake are visible.
4. Confirm that Production checkout and Users are not visible.
5. Open `/checkout.php` directly and confirm that access is denied.
6. Add a test donation with at least two tags and a saved photograph.
7. Confirm that the completed item card shows the photograph, permanent asset
   ID, type, size or color when entered, location, and selected tags.
8. Select **Enter another item** and confirm that a blank intake form appears.

### Asset browser

1. Search by part of an asset name.
2. Search by an asset ID.
3. Choose a type from the Type list.
4. Scroll the item list, pause, and confirm that the preview changes.
5. Select **Open full record** and confirm that the correct item record opens.
6. Repeat the browser checks on a phone held vertically.
