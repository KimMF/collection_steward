# Administrator Data Maintenance

Version 3 adds a deliberately restricted **Data maintenance** workspace for
routine database checking and correction without requiring direct phpMyAdmin
editing. Only an account with the Administrator role can see or open it.

The workspace is not a SQL console and is not a general-purpose database table
editor. Every query and editable field is explicitly defined by Collection
Steward. It provides no record-deletion action.

## Routine checks

The page shows fixed counts and links for:

- assets awaiting review;
- pending controlled-vocabulary suggestions;
- measurement values flagged for review;
- fitting candidates still awaiting a result; and
- mismatches between an asset's availability and its active checkout records.

The availability check identifies an asset marked Available with an active
checkout, an asset marked Checked out without an active checkout, or a retired
asset with an active checkout. Those results are read-only and link to the
normal asset workflow for investigation.

## Record searches

An administrator can search these allowlisted record views by ID, name, status,
or other displayed text where applicable:

- assets;
- people and actors;
- productions;
- individual measurement sessions;
- checkout history;
- fittings;
- user accounts;
- controlled asset vocabulary and tags; and
- append-only administrator change history.

Assets, productions, measurements, checkouts, fittings, and users link to their
existing Collection Steward pages. This keeps their established validation,
relationship, status, and history rules in one place.

## Validated corrections

Two record types previously lacked an ordinary correction path:

1. **People and actors.** An administrator can correct the display, first, and
   last names or change whether the person is active. The same person ID and
   every cast, measurement, and fitting relationship remain intact.
2. **Controlled asset vocabulary and tags.** An administrator can correct an
   approved name, display order where applicable, active status, and a tag's
   description. An inactive option remains attached to existing records but is
   no longer offered for new entry.

Renaming an asset type, wearer, color, size, or length deliberately refreshes
the generated names of assets using that option. Renaming a tag does not alter
asset identity.

Both forms require a reason. They validate identifiers, lengths, display-order
ranges, duplicate approved names, permissions, and CSRF protection. Each save
runs in one database transaction with its audit entry.

## Append-only audit history

Migration `024_add_administrative_change_history.sql` creates
`administrative_change_history`. Each correction stores:

- record type, permanent record ID, and a readable label;
- values before and after the correction;
- the required reason;
- the administrator; and
- the timestamp.

The application can search and display these events but cannot edit or delete
them.

## Deployment

Back up the production database and the public application files before this
checkpoint. Deploy in this order:

1. Run `database/schema/024_add_administrative_change_history.sql` once against
   the intended database.
2. Upload the checkpoint deployment archive and extract it in `public_html`.
   It adds `admin.php` and `modules/admin.php`, updates the shared capability
   map and stylesheet, and adds permission-gated navigation to the existing
   private pages.
3. Sign in as an administrator and open **Data maintenance**.

The migration must precede the PHP files because the page immediately queries
the new history table.

## Smoke test

1. Confirm **Data maintenance** appears for an administrator and its page loads.
2. Confirm the five routine-check counts appear and any availability mismatch
   rows link to the intended asset.
3. Search each record type and confirm expected records and normal-workflow
   links appear.
4. Select a person record, make a harmless correction with a reason, and save.
   Confirm the same person ID and related cast or measurement records remain.
   Correct the test value back if appropriate, supplying another reason.
5. Select an unused or safely testable vocabulary record, make a correction
   with a reason, and save. Confirm validation rejects a duplicate approved
   name. Restore the original value if appropriate with another reason.
6. Search **Administrative change history** and confirm every test correction
   has its before value, after value, reason, administrator, and timestamp.
7. Confirm the page has no SQL-entry or delete control.
8. Sign in as a steward and confirm the navigation link is absent and direct
   access to `/admin.php` is denied.
9. Repeat the direct-access check with an intake-only account.
10. On a phone, confirm the routine-check cards stack and the result tables can
    scroll horizontally.

## Rollback

The safest rollback is to restore both the pre-deployment database backup and
the matching public-file backup. Restoring only the earlier PHP and CSS files
leaves the additive history table in place but removes access to the workspace.

Do not drop `administrative_change_history` after the correction forms have
been used. That would erase the reason and before/after evidence for real
administrator changes.
