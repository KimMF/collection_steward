# Collection Steward

Collection Steward is an open-source stewardship platform for creative collections.

Its purpose is to help organizations preserve, understand, share responsibly, and use collections so they continue to support future creativity.

## Application structure

The public PHP files in the web root are intentionally small and preserve the
application's established URLs. Each entry point loads one functional module:

| Public entry point | Functional module |
| --- | --- |
| `index.php` | `modules/view-assets.php` |
| `intake.php` | `modules/intake.php` |
| `checkout.php` | `modules/production-checkout.php` |
| `measurements.php` | `modules/measurements.php` |
| `vocabulary.php` | `modules/vocabulary.php` |
| `users.php` | `modules/users.php` |
| `change-password.php` | `modules/password.php` |

Shared session, database, authorization, request-protection, output, and asset
naming functions remain in `lib/application.php`.

Current release: 0.1.0 (Pilot Baseline)

Release notes are preserved in [`docs/releases`](docs/releases).

The current post-baseline pilot changes and deployment checks are described in
[`docs/sonya-pilot-improvements.md`](docs/sonya-pilot-improvements.md).

The database-controlled intake fields and vocabulary-review workflow are
described in
[`docs/controlled-intake-vocabulary.md`](docs/controlled-intake-vocabulary.md).

The removal of the legacy category structure after pilot-data standardization
is documented in
[`docs/asset-type-standardization.md`](docs/asset-type-standardization.md).

Individual accounts, temporary passwords, and required first-login password
changes are documented in
[`docs/individual-passwords.md`](docs/individual-passwords.md).
