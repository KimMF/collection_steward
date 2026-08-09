#  Collection Steward Manual Deployment Procedure

**Document version:** 0.2  
**Last updated:** August 6, 2026  
**Repository:** https://github.com/KimMF/collection_steward  
**Production website:** https://www.guntersville-creative-costumers.com  
**Hosting provider:** iFastNet  
**Deployment method:** Manual upload through cPanel File Manager

---

## 1. Purpose

This procedure describes how to deploy the Collection Steward application manually and reproducibly.

Automated deployment is intentionally deferred. The goal for the pilot is a simple process that can be followed, verified, corrected, and eventually handed to another maintainer.

The GitHub repository is the authoritative copy of the source code. Files on the web server are deployed copies, not the primary working copy.

---

## 2. Deployment principles

1. Make changes on the local computer, not directly on the production website.
2. Commit working changes to GitHub before deploying them.
3. Upload only the files required for the deployment.
4. Verify the website immediately after deployment.
5. Record what was deployed and any unexpected results.
6. Do not store passwords, database credentials, or other secrets in this document or in GitHub.
7. Keep the manual process simple until repeated experience shows that automation would be beneficial.

---

## 3. Before beginning

Confirm that:

- [ ] The intended changes are complete on the local computer.
- [ ] The application has been tested as far as reasonably possible on the local computer.
- [ ] The changed files have been committed to the Git repository.
- [ ] The commit has been pushed to GitHub.
- [ ] The GitHub repository shows the expected commit.
- [ ] You know which files need to be uploaded.
- [ ] You have the iFastNet/cPanel login information available.
- [ ] You have enough uninterrupted time to upload and verify the changes.

Do not begin a deployment when tired, rushed, or uncertain about which files changed.

---

## 4. Record the deployment starting point

Before uploading anything, record:

- **Date and time:**
- **Person performing deployment:**
- **Git commit ID or commit message:**
- **Files expected to change:**
- **Reason for deployment:**
- **Current production behavior:**

The Git commit ID can be copied from the repository history on GitHub.

---

## 5. Open the production file location

1. Sign in to the iFastNet hosting account.
2. Open **cPanel**.
3. Open **File Manager**.
4. Navigate to the production web root for:

   `www.guntersville-creative-costumers.com`

5. Confirm that the folder contains the currently deployed `index.php` file or other recognizable application files.

### Production directory

The confirmed production directory is:

`/home/guntersv/public_html`

The current live entry file is:

`/home/guntersv/public_html/index.php`

---

## 6. Protect the private database configuration

The production database configuration file is stored outside the public web root at:

`/home/guntersv/collection_steward_private/database-config.php`

The required server permission is:

`0600`

This permits only the hosting-account owner to read or write the file.

The file must remain outside `/home/guntersv/public_html`.

The server copy may contain the real database password when the application requires it. Follow these rules:

- Never upload this file to GitHub.
- Never place the real password in the repository.
- Never place the real password in an ordinary local working copy.
- A local reference copy may contain only a placeholder such as `PASSWORD_NOT_ENTERED_YET`.
- Never display, print, email, or paste the real password into project notes or conversations.
- Do not change the server permission from `0600` unless the hosting environment requires a different setting and the reason is documented.

---

## 7. Back up files that will be replaced

Before replacing an existing production file:

1. Select the existing file in cPanel File Manager.
2. Download a copy to the local computer, or create a clearly named backup copy on the server.
3. Use a name that includes the date and identifies it as a backup.

Example:

`index.php.backup-2026-08-06`

Do not leave old backup files publicly accessible longer than necessary. After the deployment is confirmed and a local backup exists, remove server-side backup copies that could expose source code or configuration details.

---

## 8. Upload the changed files

1. In cPanel File Manager, open the correct production directory.
2. Choose **Upload**.
3. Select the changed file or files from the local `collection_steward` repository.
4. Wait for each upload to complete.
5. Return to File Manager.
6. Confirm that the uploaded filenames are correct.
7. Confirm that files were placed in the intended directory.
8. If cPanel asks whether to overwrite an existing file, verify the filename before approving the replacement.

### Important cautions

- Do not upload the entire repository unless that is specifically required.
- Do not upload the `.git` directory.
- Do not upload planning notes or documentation into the public web root.
- Do not upload files containing passwords or private configuration values.
- Preserve the intended directory structure.
- Be especially careful when two files have the same name in different folders.

---

## 9. Verify the deployment

After the upload:

1. Open a new browser tab.
2. Visit:

   `https://www.guntersville-creative-costumers.com`

3. Refresh the page.
4. If the old version appears, perform a hard refresh:

   - Windows: `Ctrl + F5`

5. Confirm that the page loads without a browser error.
6. Confirm that the intended change appears.
7. Test the specific feature or page changed by this deployment.
8. Check at least one incorrect or unexpected action, when practical, to confirm that the application fails safely.
9. Confirm that no PHP warning, error message, blank page, or directory listing is visible.
10. If the application uses the database, confirm that the test did not damage or unintentionally alter production data.

For the current pilot landing page, the expected basic result is:

> The GCC pilot application is running.

---

## 10. If the deployment fails

Stop making additional changes until the failure is understood.

1. Record the exact error message or visible behavior.
2. Take a screenshot if useful.
3. Note which file was uploaded immediately before the problem appeared.
4. Restore the previous version of the affected file from the backup.
5. Refresh the website and confirm that the earlier working behavior has returned.
6. Record the rollback in the deployment log.
7. Correct the source code on the local computer.
8. Commit the correction to GitHub before attempting another deployment.

Do not make a chain of unrecorded edits directly in cPanel in an attempt to fix the problem.

An emergency production edit may occasionally be unavoidable. If one is made, immediately reproduce the same correction in the local repository and commit it to GitHub so the repository remains authoritative.

---

## 11. Complete the deployment record

After verification, record:

- **Deployment result:** Successful / Rolled back / Partially successful
- **Files actually uploaded:**
- **Production directory used:**
- **Verification performed:**
- **Problems encountered:**
- **Corrective actions:**
- **Follow-up work needed:**
- **Final production behavior:**

A short deployment log may be kept at:

`docs/deployment-log.md`

Do not place passwords, database credentials, private personal information, or security-sensitive server details in the log.

---

## 12. Current known environment

| Item | Current information |
|---|---|
| Project name | Collection Steward |
| GitHub repository | `KimMF/collection_steward` |
| Production website | `www.guntersville-creative-costumers.com` |
| Hosting provider | iFastNet |
| Server management method | cPanel |
| Current deployment method | cPanel File Manager upload |
| Database | MySQL database named `gcc_pilot` |
| Database user | `gcc_admin` |
| Current production test | Landing page reports that the GCC pilot application is running |
| Automated deployment | Deferred |
| Production web root | `/home/guntersv/public_html` |
| Live entry file | `/home/guntersv/public_html/index.php` |
| Private configuration file | `/home/guntersv/collection_steward_private/database-config.php` |
| Private configuration permission | `0600` |

Database passwords and connection secrets must not be added to this table.

---

## 13. Details still to confirm

The following items should be filled in as they become known:

- [ ] Whether the application entry file is stored at the repository root or under `app/`.
- [ ] Exact mapping between repository folders and production folders.
- [ ] Which files or folders must never be uploaded.
- [ ] Whether the host requires specific PHP file permissions.
- [ ] The PHP version selected in cPanel.
- [ ] Whether a non-public configuration directory is available.
- [ ] Whether the host provides an error log and where it is located.
- [ ] Whether a separate test or staging location is available.
- [ ] The safest method for backing up the production database.
- [ ] The safest method for restoring the production database.
- [ ] Who besides the repository owner may eventually perform deployments.

These items are not prerequisites for continuing the pilot unless a particular application change depends on them.

---

## 14. Procedure maintenance rule

Update this document whenever an actual deployment reveals:

- a missing step;
- an incorrect assumption;
- a cPanel screen or option that differs from this procedure;
- a recurring error;
- a safer or simpler sequence;
- a new file or folder that must be deployed;
- a new verification step; or
- information another maintainer would need.

The procedure should describe what was actually done successfully, not what the process is merely expected to be.

---

## 15. Simple deployment summary

For routine deployments, the process is:

1. Change and test files locally.
2. Commit and push to GitHub.
3. Record the commit and changed files.
4. Back up production files that will be replaced.
5. Upload changed files through cPanel File Manager.
6. Verify the live website.
7. Roll back if necessary.
8. Record the result.
9. Update this procedure when something new is learned.
