# Version 3 Plan (`v0.3.0`)

## Purpose

Version 3 turns the working Version 2 pilot into a practical tool for reviewing
assets, managing productions, preparing measurement sessions, and recording
simple fittings. It should build on the existing intake, browsing,
measurements, vocabulary, and production-checkout workflows without turning
Collection Steward into a full costume-plot system.

The starting point for this work is the tagged `v0.2.2` release.

## Agreed scope

### 1. Asset review and correction

- [ ] Add an **Assets awaiting review** queue.
- [ ] Place every newly entered asset in the review queue automatically.
- [ ] Allow a steward or administrator to send an existing asset back to the
  review queue.
- [ ] Let an authorized reviewer inspect and record:
  - smell;
  - stains;
  - damage;
  - wear; and
  - general condition.
- [ ] Record **Clear**, **Issue found**, or **Not assessed**, with an optional
  note for each condition category, plus an overall note, reviewer, and review
  date.
- [ ] Show when the asset was most recently reviewed and by whom.
- [ ] Allow a steward or administrator to correct user-maintained asset data:
  type, wearer, primary color, standardized size, exact label size, length,
  storage location, acquisition information, description, notes, photograph,
  and tags.
- [ ] Protect permanent identity and history fields from ordinary editing,
  including the asset ID, audit timestamps, checkout history, and strike-work
  history.
- [ ] Allow a steward or administrator to **retire** a physical asset that has
  left the active collection. Record its disposition, effective date, note,
  and the user who retired it; preserve its permanent ID and history.
- [ ] Exclude all retired assets from the normal **View assets** list and from
  checkout by default.
- [ ] Give authorized users a simple **Include retired assets** checkbox on
  **View assets**. Retired assets remain hidden whenever the box is unchecked.
- [ ] Allow an administrator to mark a mistaken database record **Entered in
  error**. Hide it from normal workflows while preserving its permanent ID and
  any history that already exists.
- [ ] Do not permanently delete an established asset record through the web
  application.
- [ ] Allow the reviewer to mark the asset reviewed and remove it from the
  queue.
- [ ] Use the approved simple generated-name format when structured fields are
  saved, for example:
  `Women's black dress — Size: Small (10); Length: Medium`.
- [ ] Use `Size: Not recorded` when no size is known.
- [ ] Do not automatically rewrite existing asset names. Regenerate a legacy
  name when that asset is deliberately reviewed and saved.

### 2. Production management

- [ ] Add a dedicated **Productions** page so routine production setup does not
  require phpMyAdmin.
- [ ] Create and edit a production's name, venue, year, opening date, closing
  date, and status.
- [ ] Create and edit venues through the application.
- [ ] Manage the cast for a production.
- [ ] Allow one person to portray more than one character in the same
  production.
- [ ] Use the same production and cast records in measurements, fittings, and
  production checkout.
- [ ] Preserve historical productions rather than deleting them when they are
  no longer active.

### 3. Production measurement sessions and templates

- [ ] Allow more than one measurement session for a production.
- [ ] Start a production measurement session by selecting the participating
  cast members.
- [ ] Choose an existing measurement template or create a reusable template.
- [ ] Allow additional actor-specific measurements without changing the shared
  template for everyone else.
- [ ] Create an individual dated measurement record for each participating
  actor while keeping the records connected to the production session.
- [ ] Generate a blank printable spreadsheet for the selected cast and
  measurement columns.
- [ ] Keep the existing individual, unscheduled **Record a new measurement
  session** workflow.
- [ ] Preserve the existing compact and expanded measurement views and current
  printable worksheets.

### 4. Simple fitting workflow

The Version 3 fitting is intentionally limited. It is not a costume plot.

- [ ] Choose one production and one actor/character for the fitting.
- [ ] Create a pull list containing one or more candidate assets for that
  individual to try on.
- [ ] Display enough asset information to locate and identify every item on the
  pull list.
- [ ] Record the result for each tried asset in the database.
- [ ] Preserve any text written on the physical fitting tag as a fitting note.
- [ ] Support **Selected for wear** as a result and check that asset out through
  the existing production-checkout workflow.
- [ ] Support results that assign **Needs alteration**, **Needs laundering**,
  and **Needs repair** through the existing tagging system, adding a missing
  tag to the controlled vocabulary when necessary.
- [ ] Permit other fitting results through that same tagging system rather
  than restricting the workflow to only the initial examples.
- [ ] Leave an asset available when it is not selected for wear, while
  retaining its fitting history.
- [ ] Mark the fitting complete after the candidate assets have been recorded.
- [ ] Where applicable, connect the completed fitting to its production
  measurement session.

### 5. Immutable measurement change history

- [ ] Add append-only history for every measurement change, including
  deletions.
- [ ] Record the measurement session, measurement type, prior value, new value,
  action, user, timestamp, and reason or source.
- [ ] Record acceptance, correction, removal, and **Not applicable** as explicit
  actions.
- [ ] Keep imported source and staging values unchanged.
- [ ] Do not allow history records to be edited or deleted through the
  application.
- [ ] Preserve the current private access rules, review queue, actor history,
  original-value display, and separate Thigh and Pants Size measurements.

### 6. Administrator data maintenance

- [ ] Add administrator-only search and query views for routine data checking.
- [ ] Provide validated correction forms for the application records an
  administrator is expected to maintain.
- [ ] Reuse the normal application rules for names, controlled vocabulary,
  relationships, and audit history.
- [ ] Do not expose an arbitrary SQL console through the web application.
- [ ] Do not make normal database maintenance depend on direct phpMyAdmin
  editing.

### 7. Release completion

- [ ] Use new numbered, one-time database migrations for Version 3 schema
  changes.
- [ ] Keep private data, imported personal information, passwords, and
  production credentials out of GitHub.
- [ ] Add deployment and rollback instructions for every schema or file change.
- [ ] Test administrator, steward, and intake-only permissions.
- [ ] Test the affected workflows on a computer and a phone.
- [ ] Confirm that Version 2 intake, asset browsing, vocabulary review,
  measurements, printing, checkout, user administration, and password-change
  behavior still work.
- [ ] Add `docs/releases/0.3.0.md` and update the README release reference.
- [ ] Create and push tag `v0.3.0` only after the deployed application passes
  the release smoke test.

## Explicitly outside Version 3

- Full costume plots, including scene-by-scene or look-by-look planning.
- Full alteration, repair, laundering, or other preparation workflows. Version
  3 may record or tag that work as needed, but does not manage the work itself.
- Automated or calculated fit recommendations.
- Password-handling improvements, including administrator recovery codes and a
  secure password generator. These are deferred to Version 4.

## Implementation order

1. Add the asset-review data model, queue, condition review, and correction
   screen.
2. Add the dedicated Productions page and cast management.
3. Add production measurement sessions and reusable templates.
4. Add immutable measurement change history before expanding routine
   measurement editing further.
5. Add pull lists and the simple fitting workflow on top of productions, cast,
   assets, tags, and checkout.
6. Add the administrator data-maintenance views.
7. Complete regression testing, deployment documentation, release notes, and
   the `v0.3.0` release.

## Scope rule

If a proposed change is not required by one of the checkboxes above, it should
be treated as a later-version candidate unless it is necessary to correct a
defect or safely complete an included Version 3 feature.
