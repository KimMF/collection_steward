# Asset Type Standardization

## Decision

Collection Steward now uses `asset_types` as the only item-type vocabulary.
The original `asset_categories` structure is retired after all pilot assets
have been assigned a controlled asset type.

Item-type names and definitions are database data rather than PHP literals.
PHP retains only application rules such as field labels, permissions, and the
order used to construct a standardized asset name.

**Pants** is the canonical type for garments with one waist opening and two leg
openings. Shorts and culottes are classified as Pants rather than maintained as
separate item types. Length and other attributes can describe meaningful
differences when needed.

## Duplicate descriptive names

Two physical assets may legitimately have the same standardized description.
The `name` field therefore remains descriptive rather than artificially unique.
Every user-facing selection and principal heading combines that description
with the permanent asset ID, for example:

`Asset 12 — Women's Black Sweater`

This gives duplicate garments stable, unmistakable labels without introducing
a second sequence number that could change or require manual maintenance.

## Prerequisite

Run this query before migration 013:

```sql
SELECT COUNT(*) AS assets_without_standardized_type
FROM assets
WHERE asset_type_id IS NULL;
```

Do not proceed unless the result is `0`. This was confirmed for the pilot data
before the cleanup package was prepared.

## Deployment order

This cleanup is packaged with the individual-password improvement. The new PHP
requires the columns added by migration 014, while the older PHP is not
compatible after migration 013 removes the category table. Use this order:

1. Make a fresh database export.
2. Run `database/schema/014_add_password_change_requirement.sql` once through
   phpMyAdmin.
3. Upload the application files listed below.
4. Confirm the main page, Intake, Users, and Password pages load.
5. Run this file once through phpMyAdmin if it has not already been run:

`database/schema/013_retire_asset_categories.sql`

6. Repeat the verification checks below.

Do not rerun migration 013 if the `asset_categories` table has already been
removed. See `docs/individual-passwords.md` for the account checks that follow
the file upload.

The migration:

- adds editable definitions to `asset_types`;
- removes the category relationship from `asset_types` and `assets`;
- removes the obsolete `asset_categories` table; and
- records definitions for the seeded item types, including Pants and Sweater.

## Existing names

Migration 013 does not alter any asset name. Older names may contain useful
facts that have not yet been transferred into controlled fields. Before
shortening or regenerating one of those names, record each fact in the proper
place:

- garment kind in `asset_type_id`;
- intended wearer in `wearer_option_id`;
- primary color in `primary_color_option_id`;
- standardized size in `size_option_id`;
- length in `length_option_id`;
- precise garment-label size in `exact_size_label`; and
- material, construction, or other descriptive facts as tags or description.

After those facts are preserved, the asset name can be regenerated without
losing information.

## Application files to deploy

Upload these files after migration 014 and before migration 013, while
preserving their paths:

- `index.php`
- `intake.php`
- `vocabulary.php`
- `app.css`
- `checkout.php`
- `users.php`
- `change-password.php`
- `lib/application.php`

`checkout.php` applies the permanent-ID label to asset selections.
Do not upload the `database` or `docs` directories into the public web root.

## Verification

1. Confirm the main page loads and every asset remains grouped under its
   standardized type.
2. Confirm the Type filter contains the standardized types without legacy
   plural category names.
3. Open an existing asset and confirm its photograph, name, status, and other
   details remain unchanged.
4. Open Intake and confirm the asset-type list includes Sweater.
5. Confirm the generated-name preview still updates as controlled fields are
   selected.
6. Open Vocabulary and confirm the review page still loads.
