# Production Measurement Sessions and Templates

The Version 3 **Production measurement sessions** workspace groups a dated
cast-measurement event without replacing the established individual actor
records. A steward chooses one planned or active production, a reusable
template, and the participating cast members. Collection Steward then creates
one individual `measurement_sessions` record for each selected actor and links
all of them to the new production session.

Access uses the existing private `measurements` capability. Administrators and
stewards can use the workspace; intake-only accounts cannot open it.

## Data model

Migration `021_add_production_measurement_sessions.sql` adds:

- `production_measurement_sessions`, which records the production, shared
  template, name, date, status, notes, creator, and timestamps for the grouped
  event;
- `measurement_sessions.production_measurement_session_id`, which connects an
actor's dated record to that event; and
- `measurement_session_additional_types`, which adds measurement fields to one
  actor record without modifying the shared template.

The parent session has **Planned** and **Completed** states. Completing it does
not hide, lock, or delete any actor record. The production, actor, imported
source values, measurement values, reviews, and dated history remain in their
existing normalized tables.

While a session is Planned, more active cast members can be added. Each added
person receives the same kind of linked individual record and immediately
appears on the blank worksheet. Participants are not removed through this
workspace because their records may already contain measurements; an
unneeded record remains part of the dated history.

## Reusable templates

The workspace lists active templates already stored in
`measurement_templates` and their selected `measurement_template_items`.
Authorized users can create another reusable template by naming it, recording
an optional owner/source and description, and choosing at least one active
measurement type.

Creating or using a template never changes an existing actor measurement
value. A template chosen for a production session is also linked to every
individual actor record created for that event.

## Actor-specific fields

After a production session is created, each participant card offers the active
measurement types that are not already part of the shared template. Selecting
one adds it only to that actor's record and to that row of the blank worksheet.
Removing the selection removes the expected field from the group, but does not
delete a value that has already been recorded.

When an individual record belongs to a production session, its expanded
Measurements form shows:

- fields from the shared template;
- actor-specific fields; and
- any existing stored value, even if its field was later removed or made
  inactive.

Older imported records and individually created, unscheduled sessions retain
their existing behavior and continue to show the active measurement set.

## Blank worksheet

The production session page previews a blank row for each selected actor. Its
columns are the union of the shared template and all actor-specific additions.
A dash identifies a field that applies only to another participant. Before
printing, the user may select which measurement columns to include. The print
layout repeats actor and character columns and divides a wide worksheet across
landscape pages.

The existing current-value worksheet, blank worksheet, Compact view, Expanded
view, review queue, and individual **Record a new measurement session** form
remain available on the Measurements page.

## Deployment

Back up the production database and public application files before this
checkpoint. Deploy in this order:

1. Run `database/schema/021_add_production_measurement_sessions.sql` once
   against the intended database.
2. Upload the changed public application files:
   - `app.css`;
   - `production-measurements.php`;
   - `modules/production-measurements.php`;
   - `modules/measurements.php`; and
   - `modules/productions.php`.
3. Load **Production measurement sessions**, **Measurements**, and
   **Productions** before relying on the new workflow.

The migration must precede the PHP files because both updated measurement
modules query the new group and actor-specific-field tables.

## Smoke test

1. Sign in as an administrator or steward and open **Measurements**. Confirm
   the **Production measurement sessions** link appears and its page loads.
2. Confirm **Productions** offers **Plan measurements** for a Planned or Active
   production.
3. Create a small reusable template and confirm it remains available after
   reloading.
4. Start a session for a Planned or Active production with at least two cast
   members. Confirm one grouped session and one actor record per selected
   person appear.
5. Use **Add cast members** to add another active cast member. Confirm the new
   actor record and worksheet row appear without changing the existing rows.
6. Open each actor record and confirm it shows the shared-template fields. Save
   a harmless test value if appropriate and confirm it persists.
7. Add one actor-specific field to only one participant. Confirm that actor's
   expanded record shows **Actor only**, while another participant does not
   receive the field.
8. Print or preview the blank worksheet. Confirm actor and character rows,
   chosen measurement columns, page ranges, and dashes for non-applicable
   actor-only fields are correct.
9. Mark the grouped session Completed, reload it, and confirm all individual
   actor records and saved measurements remain accessible.
10. Create or open an ordinary individual session and confirm the existing
   Compact, Expanded, current worksheet, and blank worksheet behavior remains.
11. Confirm an intake-only account cannot open
    `/production-measurements.php`.

## Rollback

The safest rollback is to restore both the database backup and the matching
public-file backup. Do not manually drop the new tables or column after the
feature has been used: doing so would discard the grouping and actor-specific
field relationships. Restoring only the earlier PHP files leaves the additive
schema in place, but the earlier application will ignore the new group links.
