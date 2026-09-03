# Production Management

The Version 3 **Productions** workspace is the single routine place to maintain
production details, venues, and cast assignments. It reuses the normalized
records already used by production checkout and actor measurements.

Access is limited to stewards and administrators through the
`manage_productions` capability.

## Production lifecycle

Each production records:

- name;
- venue;
- production year;
- opening and closing dates; and
- status.

The supported statuses are **Planned**, **Active**, **Completed**, and
**Cancelled**. Planned and active productions are available for new production
checkouts. Completed and cancelled productions remain visible in
**Productions** and **Measurements**, with their cast, measurement sessions,
and checkout history intact.

A production cannot be marked completed or cancelled while it has an active
asset checkout. The checkout must be resolved first so the item cannot become
stranded outside the normal checkout workspace.

Production records are not deleted through the application.

## Venues

Authorized users can add a venue and edit its name, optional short code, and
active status. Deactivating a venue removes it from new-production choices but
does not disconnect it from any historical production. Venue records are not
deleted through the application.

## Cast assignments

A cast assignment connects an existing or newly entered person to one
character in one production. The same person may have multiple character
assignments in the same production.

An assignment records its character name, display order, and active status.
Deactivating an assignment removes it from new checkout choices without
deleting the assignment or its checkout history. It does not remove the
person's measurement sessions: measurements connect directly to the person
and production and remain accessible.

## Deployment

Back up the current production database and public files before this
checkpoint. Then deploy in this order:

1. Run `database/schema/020_add_production_management.sql` once against the
   intended database.
2. Upload the updated public application files:
   - `app.css`;
   - `productions.php`;
   - `lib/application.php`;
   - `modules/productions.php`;
   - `modules/view-assets.php`;
   - `modules/intake.php`;
   - `modules/production-checkout.php`;
   - `modules/measurements.php`;
   - `modules/asset-review.php`;
   - `modules/vocabulary.php`;
   - `modules/users.php`; and
   - `modules/password.php`.
3. Load **Productions**, **Production checkout**, and **Measurements** before
   relying on the new management workflow.

The migration must precede the PHP files because the updated cast queries
require the new `production_cast.is_active` column.

## Smoke test

1. Sign in as an administrator or steward and confirm **Productions** appears
   in the navigation.
2. Open **Productions** and confirm existing productions, venues, and cast
   assignments appear.
3. Save an appropriate production's venue, year, dates, or status and confirm
   the change persists after reloading.
4. Add or update a real venue and confirm it appears in the production form.
5. Add an actor and character or update an existing character assignment.
6. Confirm the same person can be assigned a second, different character.
7. Confirm only active cast assignments appear as new checkout choices.
8. Mark a suitable production **Completed** or **Cancelled** and confirm it
   disappears from new checkout choices but remains visible in Productions.
9. Open that production's measurements and confirm its historical measurement
   sessions remain accessible.
10. Confirm an intake-only user cannot see or open the Productions workspace.

## Rollback

The safest rollback is to restore both the pre-deployment database backup and
the pre-deployment public-file backup. Restoring only the earlier PHP files
will leave `production_cast.is_active` intact, but the earlier checkout code
will not honor deactivated cast assignments. Do not remove the column after it
has been used unless the database is being restored from the matching backup.
