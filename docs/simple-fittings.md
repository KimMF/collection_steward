# Simple Fittings and Pull Lists

Version 3 adds a deliberately limited **Fittings** workspace. It is not a
costume plot: one fitting belongs to one production cast assignment and holds
a pull list of collection assets for that actor and character to try.

The workspace uses the existing private `checkout` capability. Administrators
and stewards can use it; intake-only accounts cannot open it or see its
navigation link.

## Fitting workflow

For a Planned or Active production, an authorized user can:

1. choose one active actor/character assignment and a fitting date;
2. optionally record overall notes;
3. add one or more active, available assets to the pull list;
4. print the pull list with each asset's permanent ID, name, type, photograph,
   size, color, storage location, and availability;
5. record **Selected for wear** or **Not selected for wear** for every
   candidate;
6. preserve the text from the physical fitting tag as a fitting note;
7. add controlled-vocabulary result or work tags; and
8. mark the fitting Completed after every candidate has a result.

The print controls also provide a **Print selected assets** option once at
least one candidate has been marked **Selected for wear**. That document omits
pending and not-selected candidates. The full fitting record remains printable
separately.

A pull-list candidate remains available until it is selected for wear. A
**Not selected for wear** result does not check out or otherwise reserve the
asset, but the candidate and result remain in the fitting history.

Completed and historical productions continue to show their fittings. New
fittings and candidates require a Planned or Active production. Completing a
fitting makes its details and results read-only without deleting anything. An
authorized user can reopen a completed fitting for corrections; all existing
candidates, results, notes, tags, and checkout links remain intact.

The open workspace separates candidates awaiting a result, assets selected
for wear, and assets not selected during the fitting. A completed fitting uses
the heading **Fitting results** rather than describing rejected assets as an
active pull list. It preserves selected and not-selected records in separate
groups so the fitting history remains complete.

## Checkout integration

Saving **Selected for wear** creates the normal active `asset_checkouts`
record for the fitting's exact actor/character assignment and changes the
asset's availability to `checked_out`. The fitting candidate keeps the
checkout ID so the two records remain visibly connected.

If the asset is already actively checked out to that same cast assignment,
the fitting links the existing checkout instead of creating a duplicate. An
active checkout to anyone else blocks the selection.

Before a fitting is completed, correcting a **Selected for wear** result to
**Not selected for wear** cancels its linked active checkout and returns the
asset to available. The cancelled checkout remains in checkout history.

## Result tags

Migration `023_add_simple_fittings.sql` ensures these initial work tags exist:

- **Needs alteration**;
- **Needs laundering**; and
- **Needs repair**.

The result form also offers every other active tag and accepts a new result
tag when the existing vocabulary is not adequate. A new tag is added to the
controlled `tags` table. Every selected result tag is recorded against the
fitting candidate and assigned to the asset through the existing
`asset_tags` relationship.

Previously recorded fitting result tags remain visible and cannot be removed
from the fitting through this simple workflow. The normal asset steward
controls remain available for later changes to the asset's current tags.

## Measurement-session connection

An open fitting may be connected to a grouped production measurement session.
Only sessions for the same production that include the fitting's actor appear
as choices. The fitting then links both to the grouped session and to the
actor's individual measurement record. Measurements remain accessible after
either the fitting or the production session is completed.

## Data model

Migration `023_add_simple_fittings.sql` creates:

- `fittings`, which stores the cast assignment, optional production
  measurement-session link, date, status, overall notes, creator, and
  completion information;
- `fitting_assets`, which stores every pull-list candidate, wear decision,
  physical fitting-tag note, checkout link, and recording information; and
- `fitting_asset_result_tags`, which preserves the controlled-vocabulary tags
  recorded as fitting results.

The migration is additive. It does not change existing production, cast,
measurement, checkout, asset, or tag records. `INSERT IGNORE` adds only any
missing initial work tags.

## Deployment

Back up the production database and public application files before this
checkpoint. Deploy in this order:

1. Run `database/schema/023_add_simple_fittings.sql` once against the intended
   database.
2. Upload the checkpoint deployment archive and extract it in `public_html`.
   It adds `fittings.php` and `modules/fittings.php`, updates `app.css`, and
   adds the Fittings navigation link to the existing private pages.
3. Open **Fittings**, **Production checkout**, **Productions**, **View assets**,
   and **Measurements** before relying on the new workflow.

The migration must precede the PHP files because the Fittings page immediately
queries the three new tables.

## Smoke test

Use a real fitting or a clearly identified test record:

1. Confirm **Fittings** appears in navigation for an administrator or steward
   and the page loads normally.
2. Choose a Planned or Active production, start a fitting for one
   actor/character, and confirm the date and overall notes persist.
3. If a compatible production measurement session exists, connect it and
   confirm both the grouped and individual measurement links open correctly.
4. Add at least two available assets. Confirm each card shows the expected
   permanent ID, name, type, photograph or placeholder, size, color, location,
   and availability.
5. Print or preview the pull list and confirm the actor, character, production,
   date, and candidate details are legible.
6. Save one candidate as **Not selected for wear** with fitting-tag text.
   Confirm the asset remains available and the note persists.
7. Save another as **Selected for wear**. Confirm the fitting shows an active
   checkout and Production checkout assigns the same asset to the same
   actor/character.
8. Where appropriate, assign **Needs alteration**, **Needs laundering**, or
   **Needs repair**. Confirm the tag appears in the fitting and on the asset.
9. Preview **Print selected assets** and confirm it includes only assets marked
   **Selected for wear**.
10. Add a harmless new result tag if testing that capability is appropriate.
   Confirm it appears in Vocabulary and on the asset.
11. Correct the selected test result to **Not selected for wear** if the
    checkout should not remain. Confirm the linked checkout is Cancelled and
    the asset becomes available again.
12. Confirm the fitting cannot be completed while a candidate still awaits a
    result. Record all remaining results, complete it, and confirm its results
    are grouped into **Selected for wear** and **Not selected during fitting**.
13. Reopen the completed fitting and confirm its candidates, results, notes,
    tags, measurement link, and checkout links remain intact and editable.
14. Complete it again and confirm the grouped results are read-only.
15. Confirm a completed production's fittings remain visible but cannot accept
    new fitting activity.
16. Confirm an intake-only account cannot open `/fittings.php` and does not see
    the Fittings navigation link.

## Rollback

The safest rollback is to restore both the pre-deployment database backup and
the matching public-file backup. Restoring only the earlier PHP and CSS files
leaves the additive fitting tables intact but removes access to the workflow.

Do not drop the fitting tables after the feature has been used. Doing so would
discard pull lists, fitting-tag notes, outcomes, result-tag relationships, and
checkout connections. Checkout records and asset tags created by fittings are
ordinary shared application records and must not be removed as a substitute
for restoring the matching database backup.
