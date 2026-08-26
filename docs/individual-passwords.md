# Individual Passwords

## What changes

Every Collection Steward user can have an individual account. An administrator
creates the account with a temporary password. At the first login, the user is
sent to **Choose your password** and cannot open the rest of the application
until the temporary password is replaced.

The administrator knows only the temporary password. The replacement is chosen
and entered by the user and is stored only as a password hash.

An existing account is not forced to change its password when the database
migration is installed.

## Safe deployment order

This application package also contains the asset-type cleanup. Use this order:

1. Export a fresh database backup through phpMyAdmin.
2. Run `database/schema/014_add_password_change_requirement.sql` once.
3. Upload the application files listed below, preserving the `lib` path.
4. Sign in with the existing administrator account and confirm **Users** and
   **Password** open.
5. If migration 013 has not already been run, run
   `database/schema/013_retire_asset_categories.sql` once.
6. Repeat the smoke test and confirm assets remain grouped by standardized
   type.

Migration 014 must precede the new PHP because the PHP reads its two new
columns. Do not rerun migration 013 if the legacy category table has already
been removed.

## Application files to upload

Upload these files to `public_html`:

- `app.css`
- `index.php`
- `intake.php`
- `checkout.php`
- `users.php`
- `vocabulary.php`
- `change-password.php`

Upload this file to `public_html/lib`:

- `lib/application.php`

The SQL and documentation files are references for the administrator and do
not belong in `public_html`.

## Create Sonya's account

1. Sign in with the existing administrator account.
2. Open **Users**.
3. Use username `sonya`, display name `Sonya`, and access **Intake only**.
4. Enter the same temporary password in both fields. It must contain 12–255
   characters.
5. Select **Create account**.
6. Give Sonya the username and temporary password privately.

Do not put either her temporary password or her eventual private password in
email, GitHub, SQL files, or project documentation.

## Sonya's first login

1. Sonya opens the normal Collection Steward address in her browser.
2. She signs in with the username and temporary password you supplied.
3. The application opens **Choose your password** automatically.
4. She enters the temporary password once, then enters her private replacement
   twice.
5. She selects **Save new password**, then **Continue to Intake**.

Afterward, Sonya can use **Password** in the navigation to change her own
password again. The existing role permissions still apply; an Intake-only user
cannot open checkout, vocabulary review, or user administration.

## Forgotten password

An administrator can open **Users**, find the account, expand **Issue temporary
password**, and enter a new temporary password twice. At the user's next login,
the application again requires a private replacement. An administrator cannot
use this reset form on the administrator account currently signed in; that
account uses the normal **Password** page instead.

## Verification

1. Confirm the existing administrator can still sign in with the existing
   password.
2. Create a temporary Intake-only test account.
3. Sign in as that account and confirm no application page can be opened before
   replacing the temporary password.
4. Replace it, sign out, and confirm the new password works while the temporary
   password no longer works.
5. Confirm the Intake-only account sees View assets, Intake, and Password, but
   not checkout, vocabulary review, or Users.
6. As administrator, issue that account another temporary password and confirm
   the required-change screen appears again.
