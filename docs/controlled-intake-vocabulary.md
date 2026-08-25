# Controlled Intake Vocabulary

## Purpose

This increment replaces the free-typed item name with a consistent name built
from approved database values:

`Wearer + Primary color + Length + Item type — Size`

For example, selecting **Women's**, **Black**, **Long**, **Skirt**, and
**Large** creates `Women's Black Long Skirt — Large`.

Every asset still receives its own permanent asset ID. Two assets may have the
same generated name when their photographs or other details are what distinguish
them.

## How unlisted values work

An intake user can select **Not listed — suggest an option** for item type,
wearer, primary color, size, or length. The user can also suggest one unlisted
other attribute. These suggestions are saved with the asset but are not added to
the approved vocabulary automatically.

An administrator or steward reviews suggestions on **Vocabulary** and can:

- match the suggestion to an existing approved option;
- approve it as a new canonical option; or
- dismiss it.

Resolving a structured attribute updates the asset and regenerates its name.
Resolving an other-attribute suggestion assigns the approved tag to the asset.

## Database migration

Back up the database, then run this file once through phpMyAdmin:

`database/schema/011_add_controlled_asset_attributes.sql`

The migration creates these tables:

- `asset_types`
- `wearer_options`
- `color_options`
- `size_options`
- `length_options`
- `vocabulary_suggestions`

It also adds nullable controlled-vocabulary columns to `assets`. Existing asset
names, categories, colors, sizes, and photographs are preserved. Existing items
can be standardized gradually rather than all at once.

Run migration 010 before migration 011 if it has not already been run.

## Add the approved starting values

After running migration 011, run this seed file through phpMyAdmin:

`database/schema/012_seed_controlled_asset_vocabulary.sql`

The seed inserts one record for each approved choice into the appropriate
lookup table. It does not insert anything into `vocabulary_suggestions`; that
table is populated automatically only when an intake user proposes an unlisted
value. The seed uses `INSERT IGNORE`, so running it again skips names already in
the database instead of creating duplicates.

The tables and relevant columns are:

| Table | Values to enter |
| --- | --- |
| `asset_types` | `name`; optional `category_id`; `display_order`; `is_active` = 1 |
| `wearer_options` | `name`; `display_order`; `is_active` = 1 |
| `color_options` | `name`; `display_order`; `is_active` = 1 |
| `size_options` | `name`; `display_order`; `is_active` = 1 |
| `length_options` | `name`; `display_order`; `is_active` = 1 |
| `tags` | Continue using this existing table for other attributes |

Lower `display_order` values appear first. Reusing the same display order is
allowed; names then appear alphabetically. For an asset type, `category_id` can
link a detailed type such as **Skirt** to an existing broad category. It may be
left blank until that mapping is decided.

A practical first set to consider is:

| Vocabulary | Possible starting values |
| --- | --- |
| Wearer | Women's; Men's; Unisex/Any; Child; Unknown |
| Length | Short; Medium; Long; Not applicable; Unknown |
| Size | Extra Small; Small; Medium; Large; Extra Large; One Size; Unknown |
| Item type | Skirt; Dress; Blouse; Shirt; Pants; Jacket; Coat; Vest; Hat; Shoes; Accessory |
| Primary color | Black; White; Gray; Brown; Tan; Beige; Cream/Ivory; Red; Burgundy; Orange; Yellow; Green; Teal; Blue; Navy; Purple; Pink; Gold; Silver; Multicolor; Unknown |
| Other attributes | Sleeves, closures, construction details, decoration, fit, style, matching-set, laundering, and repair tags listed in migration 012 |

These are starting values and can be expanded later. Use the wording that best
fits WBS, because that wording will appear in generated item names. The seed
also adds a practical starter list of other attributes to the existing `tags`
table. `Unknown` and `Not applicable` remain stored selections but are omitted
from generated item names.

## Files to deploy

After migrations 011 and 012 have run, upload these application files while
preserving their paths:

- `index.php`
- `intake.php`
- `checkout.php`
- `users.php`
- `vocabulary.php`
- `app.css`
- `lib/application.php`

Do not upload the `database` or `docs` directories into the public web root.
The version suffix in the page links to `app.css` makes browsers request this
new stylesheet instead of reusing the previously cached copy.

## Verification

1. Sign in as an administrator or steward and confirm **Vocabulary** is visible.
2. Open Intake and confirm the five structured attribute lists contain the
   approved values entered in phpMyAdmin.
3. Select Women's, Black, Long, Skirt, and Large and confirm the preview reads
   `Women's Black Long Skirt — Large`.
4. Save a test asset and confirm its photograph, permanent asset ID, generated
   name, structured fields, and other attributes appear.
5. Create an asset using **Not listed** for one field.
6. Open Vocabulary, match or approve that suggestion, and confirm the asset's
   field and generated name update.
7. Sign in with the intake-only account and confirm Vocabulary is not visible;
   opening `/vocabulary.php` directly should be denied.
8. Confirm older asset records still appear in the asset browser.
