# Test report

For the owner, and for whoever takes over the code. Last run: 29 September 2026, on the local build (Windows,
PHP 8.2.30, Laravel 12.69.2).

Every item below is in one of four groups:

- **Passed.** It was run and gave the expected result.
- **Failed.** It was run and did not.
- **Not run.** It can be done, but has not been done yet.
- **Externally blocked.** It cannot be done without something from outside the build: access, keys or a decision.

Nothing in this report has been run on the hosting account. Nothing has been deployed.

## Summary

| Area | Result |
|---|---|
| Automated suite, SQLite (the normal local run) | **Passed**: 230 tests, 1,764 checks; 1 skipped (the MariaDB-only column check). Re-run after the 30 September clean-up, which removed Laravel's sample test |
| Automated suite, MariaDB 10.4 (the same database family as cPanel) | **Passed**: 232 tests, 1,766 checks, none skipped. Run before the clean-up, so it includes the sample test since removed; the clean-up changed only file locations and unused settings, not how the site behaves |
| WordPress import dry-run and check against the live site | **Passed**: no differences |
| Visual comparison with the live site (24 page/phone/desktop views) | **Passed**, with listed differences for the owner to review |
| Security checks | **Passed** (automated), see below |
| Upload package build | **Passed**: app zip (939 files) and libraries zip (14,427 files) built, with SHA-256 checksums |
| Paystack sandbox checkout, webhooks, refunds on a real account | **Not run** (needs a public staging address) |
| Email through a real SMTP mailbox | **Externally blocked** (needs the mailbox details) |
| Staging on the hosting account, restore drill, cron, upload limits | **Externally blocked** (needs cPanel access) |

Failed: **none**.

## 1. Automated test suite

Run with `php artisan test` (SQLite in memory) and `php vendor/bin/phpunit -c phpunit.mariadb.xml` (a local
MariaDB database, `mtl_test`, which is emptied on every run).

| Area | What is covered |
|---|---|
| Public site (`tests/Feature/Site`) | Every public page and old WordPress address; trailing-slash and managed redirects; old image addresses redirecting to `/images/`; the sitemap, the feed and robots rules; previews; the enquiry form, including its spam checks |
| Content (`tests/Feature/Content`) | Page and post editing rights, sanitised HTML, settings, media, comment moderation, and the WordPress importer: repeat runs, local edits kept, dry-run, and a full export file imported and then checked |
| Accounts (`tests/Feature/Auth`) | Client registration and sign-in, no hint whether an email exists, staff refused at the client sign-in |
| Staff panel (`tests/Feature/Admin`) | Roles and screen access, invitations, 2-step sign-in, last-administrator protection, the audit log with no edit or delete, applications and their files, backups (encrypted, restored into an empty database, the download only for technical administrators), staging protection, database column checks |
| Practice (`tests/Feature/Practice`) | Enquiries, conflict checks never cleared automatically, quotations, engagement signing and approval, matters, teams as the access boundary, tasks, documents and the approval of deliverables, Naija Virtual Notary handoffs (consent first, manual reference and status) |
| Messages and bookings (`tests/Feature/Communication`) | Client chat, internal notes never shown to clients, email delays and read checks, recaps, consultations and reminders, the scheduler |
| Client portal (`tests/Feature/Portal`) | A client sees only their own matters, released documents and client-visible updates |
| Billing (`tests/Feature/Billing`) | Whole kobo and cents, NGN and USD never added together, issued invoices frozen, credit notes, Paystack against a fake Paystack (amount and currency checks, webhook signature, reconcile, refunds only when confirmed), bank transfers needing verification, the client-funds ledger (no automatic deductions, no negative balance), reports per currency |
| Security (`tests/Feature/SecurityChecksTest.php`) | See section 4 |

One test runs only on MariaDB. It checks the real column types, so it is skipped on SQLite.

## 2. WordPress migration

The importer only reads WordPress; it never writes to it.

| Check | Result |
|---|---|
| Dry-run against the live site (`mtl:import-wordpress --live=https://mtouchlegal.com --dry-run`) | **Passed**. 1 category, 30 tags, 40 images, 6 posts, 5 comments; all unchanged since the last import |
| Verify against the live site (`mtl:verify-import --live=…`) | **Passed**. "No differences found": every count matches, all 40 image files are present, the 6 posts match, 11 internal links resolve |
| Import from a WordPress export file (`--wxr`) | **Passed** in the automated tests with a sample export. Drafts, private and scheduled posts, and SEO fields only appear in a real export |
| A real export from the site's WordPress admin | **Externally blocked**: the owner has not supplied it yet |

The live site's REST interface only shows published content, so the real export is needed before cutover. The
reports are in [migration-reports/](migration-reports/). They contain no email addresses.

`hello-world`, WordPress's sample post, is imported as a hidden draft until the owner decides whether to keep it.

## 3. Visual and content comparison

**Passed**, with differences for review. There are 24 screenshots, of 12 pages on desktop and phone, taken of the
live site and of the build, and compared by eye. The fixes and the remaining differences are listed in
[visual-comparison/README.md](visual-comparison/README.md). The page text is copied word for word from the live
site (seeded by `database/seeders/PageSeeder.php`); new wording is listed in
[content-gaps.md](content-gaps.md) for approval.

**Not run:** the phone menu opened on the build. Open it on a phone during the staging review.

## 4. Security checks

**Passed** (automated):

- There is no web page that runs setup, database or console commands.
- Every address that is not public needs a sign-in or a signed link. A list of public addresses is kept in the
  test, and a new address fails the test until it is reviewed.
- More than 40 private pages were requested without signing in, and none of them opened.
- The security headers are set on every page:
  - no content-type guessing;
  - no framing by other sites;
  - a limited referrer;
  - no camera, microphone or location.
- HSTS is sent only in production over HTTPS, and it does not cover subdomains, so the Naija Virtual Notary
  subdomain is not affected.
- Signed-in pages are marked not to be stored by the browser.
- `index.php` is the only PHP file in the public folder. The image and media folders refuse to run scripts or
  serve web pages. There is no `.env`, `storage` link or `wp-content` folder there.
- Confidential files, payment evidence and job applications are downloaded as attachments, never opened as web
  pages. Application files are also sandboxed.
- Staff need the 2-step code, and full administrators cannot accept terms as the client. Internal notes, drafts
  and other clients' records are refused to clients.
- Live Paystack keys are refused on staging.

The Paystack secret key is read only from `.env`, and no settings screen shows it. That is how the code is
written; no test checks for it separately.

**Not run:** an outside penetration test. **Not done by design:** virus scanning. Uploaded files are checked for
type and size only; see DECISIONS D19.

## 5. Staging and cPanel

| Check | Result |
|---|---|
| `php artisan optimize` (cached config, routes and views, as on the server) | **Passed** locally |
| The public folder used as a separate document root (layout B, `app-path.php`) | **Passed** locally |
| Staging password prompt, noindex, test-mode payments only, emails redirected | **Passed** (automated) |
| Staging on the hosting account | **Externally blocked**: cPanel access |
| PHP version and extensions, MariaDB version, document-root control, disk quota and upload limits on the account | **Externally blocked**: cPanel access |
| The cron job and scheduler on the server | **Externally blocked**: cPanel access |
| A backup restore drill on the server | **Not run**: do it on staging |
| Paystack sandbox checkout and webhook | **Not run**: needs the staging address |
| Real email delivery (SMTP) | **Externally blocked**: mailbox details |

## 6. Known limitations

- The full MariaDB run took almost 6 hours on this computer, because every test rebuilds the tables. Run it before each release, not after every change.
- The tests use a fake Paystack. They show that the code handles each Paystack reply correctly. They do not show
  that Paystack sends those replies to this account.
