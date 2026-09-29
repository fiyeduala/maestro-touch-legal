# Build status

Last updated: 29 September 2026 (end of Phase 6: the build is complete locally).

## Phase overview

| Phase | Scope | Status |
|---|---|---|
| 1 | Inspect project + live site, inventory/export content and assets, verify dependencies, architecture, permission matrix | **Done**, except hosting verification (blocked: needs cPanel access) |
| 2 | Public-site mirror, editable branding/pages/blog, authentication, roles/policies, staff applications | **Done** locally, 79 automated tests passing. Pixel comparison against the reference screenshots moves to Phase 6. |
| 3 | Enquiries, conflict checks, quotations/engagement, clients, matters, teams, tasks, documents, approvals, portal pages | **Done** locally, 134 automated tests passing in total |
| 4 | Portal chat, internal notes, consultations, SMTP notifications, daily digests | **Done** locally, 170 automated tests passing in total |
| 5 | NGN/USD billing, Paystack, manual transfers, client funds, reporting, audit views | **Done** locally, 204 automated tests passing in total. Paystack verified in test mode for authentication only (see below) |
| 6 | Migration dry-run, visual/content comparison, security/workflow tests, staging checks, deployment package | **Done** locally: 232 automated tests passing on SQLite and on MariaDB. Staging on the hosting account is waiting on cPanel access ([OUTSTANDING.md](OUTSTANDING.md)) |

Nothing has been deployed. The live WordPress site is untouched.

## Phase 6 — completed

**Where to start:** [OUTSTANDING.md](OUTSTANDING.md) (what the owner needs to supply), [TEST-REPORT.md](TEST-REPORT.md)
(what was tested, and how), [DEPLOYMENT.md](DEPLOYMENT.md), [ROLLBACK.md](ROLLBACK.md),
[BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md), [OWNER-QUICKSTART.md](OWNER-QUICKSTART.md),
[STAFF-QUICKSTART.md](STAFF-QUICKSTART.md), [UPGRADE-PATH.md](UPGRADE-PATH.md).

- **Migration.** `mtl:import-wordpress` now also reads a WordPress export file (`--wxr`), and `mtl:verify-import`
  compares the result with the source. Against the live site: no differences (6 posts, 40 images, 30 tags,
  5 comments). The reports are in [migration-reports/](migration-reports/).
- **Images** moved from `wp-content/uploads` to `/images/`. Old addresses redirect permanently (D41).
- **Visual comparison.** 24 page views compared with the live site; fixes made, and the remaining differences are
  listed for the owner ([visual-comparison/README.md](visual-comparison/README.md)).
- **Staging mode:** password prompt, noindex, test payments only, emails redirected (D37).
- **Backups:** nightly encrypted backup, download for technical administrators, and restore into an empty
  database (D38).
- **Naija Virtual Notary:** manual handoff on each matter (consent, NVN reference, status). Nothing is sent to NVN
  (D40).
- **Security:** security headers, locked-down public folders, and automated checks for open routes and files
  (D43).
- **Package:** `php tools/deploy/package.php` builds the upload zips with a checksum manifest. The server can run
  from its own document root, or from `public_html` using `app-path.php`.

## Phase 5 — completed

**Invoices** (D30, D31). Admin → Billing → Invoices.
- Invoices are in NGN or USD, never both, and amounts are stored as whole kobo or cents.
- Drafts can be edited. Once issued, an invoice is frozen and changes go through credit notes.
- An invoice can be raised from an accepted quotation (the whole quotation or one payment stage) and from billable
  expenses. "New invoice" on a matter pre-fills the client and matter.
- Clients see issued invoices under Portal → Invoices and can download a printable copy.

**Payments** (D31, D32). Admin → Billing → Payments.
- **Paystack:** the client pays from the invoice. The payment only counts once the server has checked it with
  Paystack, whether by webhook, on the client's return, or by the 15-minute reconcile. A wrong amount or currency
  goes to "Needs review". Refunds complete only when Paystack confirms them.
- **Bank transfer:** the client sees the firm's account for that currency and uploads a slip. The invoice stays
  unpaid until finance verifies the amount against the bank statement.
- **Recorded by finance:** money already received, applied to an invoice or held as client credit. Credit can be
  applied to a later invoice in the same currency.
- Manual refunds need evidence. Reversals undo a payment without editing it.

**Expenses.** Admin → Billing → Expenses. Expenses marked billable to the client go onto a draft invoice. Voided
expenses stay visible.

**Client funds** (D33). Admin → Billing → Client funds.
- This is a ledger of money held for clients, such as recovered debts, kept separate from the firm's fees.
- Outflows need a written authorisation and can never take the balance below zero.
- Mistakes are corrected by reversal entries, never by editing.
- Reconciling against a bank statement records any difference; the system does not adjust anything.
- Clients see their own statement under Portal → Funds.

**Reports and overview** (D34).
- Admin → Billing → Reports gives finance figures for each currency: invoiced, received, outstanding by age,
  credit, unverified transfers, client funds and quotations awaiting a reply. It can filter by date, service and
  team member.
- Full administrators also see practice figures: enquiries, time to first action, matters, tasks and
  consultations.
- A CSV invoice export is available; every export is audited.
- The admin dashboard shows a live overview for full administrators.

**Audit log.** You can now filter by record type and record ID. The log remains append-only in the admin screens
(no edit or delete).

**Access** (D35). Finance officers can choose any client or matter on a billing form, but still cannot open
matters. Lawyers and case officers see billing only for their own matters. Client-funds entries and evidence files
are for finance only; every evidence download is audited.

**Fixed:** amounts of 1,000 or more pre-filled into edit forms included a thousands comma. This failed validation
(it affected editing draft invoice lines and quotation stages). Forms now receive plain `1000.00`.

**Paystack status:** the test keys in `.env` were confirmed as test-mode keys and authenticate with Paystack. Not
yet exercised end to end:
- a real sandbox checkout;
- webhooks, which need a public URL. Set `https://<domain>/webhooks/paystack` in the Paystack dashboard at
  staging;
- the refund API payload.

All provider calls are covered by automated tests with a fake Paystack.

## Phase 4 — completed

**Conversations** (D25)
- Portal → Messages: the client's conversation for each matter, with file attachments on private storage.
- Admin → Messages: every conversation the staff member can open, with unread counts. The conversation screen
  (also linked from each matter) shows the client thread and the internal notes side by side. It supports read
  receipts, corrections, and call/meeting notes.
- New-message emails wait 10 minutes and are skipped if the message has already been read.

**End-of-day recaps** (D26)
- Settings → Email: switch on or off, the send time, and the firm recipients.
- Admin → Operations: recap status, with an audited "Send again".

**Consultations** (D27)
- Portal → Appointments: request, reschedule and cancel within the firm's rules.
- Admin → Consultations: book for an enquiry or matter, confirm with host and https link, reschedule, cancel,
  record outcome. Also available from each enquiry and matter.
- Admin → Consultation types; Settings → Consultations (hours, closed dates, rules, reminders).
- Calendar (.ics) files on confirmation and changes; reminders sent by the scheduler.

**Operations** (D28, D29)
- The scheduler, queue and heartbeat are set up for a 5-minute cPanel cron. Admin → Operations shows their health.
- Tawk.to is removed from portal and account pages.

**Emails** use Laravel's mailer. Locally they go to the log (`MAIL_MAILER=log`). SMTP is set in `.env` on the
server. Every email is recorded in the delivery log.

## Phase 3 — completed

**Intake** (decisions D15, D16, D23, D24)
- Public enquiry form at `/legal-assistance/`: choose a service, then answer that service's published intake
  questions. Works without JavaScript. Honeypot, 5 per minute per IP, emailed acknowledgement, and a notice that
  sending the form creates no lawyer–client relationship. A short form sits on the Contact page below the preserved
  live copy.
- Enquiry inbox (staff admin): assignment, parties, conflict check with suggested matches (never automatic),
  status history, client record creation.
- Service catalogue: services with their own matter stages, versioned intake forms (a published version is
  immutable), versioned engagement templates. `ServiceSeeder` adds the eight live practice areas as drafts.

**Engagement** (D17)
- Quotations: fee and expense lines in integer minor units (NGN/USD, no conversion), payment stages, validity,
  versions, sending, client accept or decline in the portal, and offline acceptance with evidence.
- Engagement terms from a template, sanitised HTML, sent only after conflict clearance. The client signs a specific
  version by typing their name, and the content hash is recorded. A full administrator then approves the accepted
  engagement and the matter opens (the conflict check is re-run at that point).

**Clients and matters** (D18, D20, D21)
- Clients (individual or organisation), invitation-only portal contacts, and removal of access with a reason.
- Matters: team membership is the access boundary; stages, next action (optionally client-visible), manual
  deadlines, internal assessment, client summary, events (internal and client-visible), close and reopen.
- Tasks with assignment, start/complete, and reminders (`tasks:notify` every 15 minutes). "My work" page lists
  your tasks and deadlines for the next 30 days.
- Documents (D19): private storage, versioning, audited downloads, requests to the client, the deliverable workflow
  (draft → review → lawyer approval → release → client decision). A case officer cannot give final approval.

**Client portal**
- Overview with "Needs your attention": terms to sign, quotations, drafts to review, documents requested.
- Matter page: progress, client-visible next step and deadlines, requested documents and upload, released
  documents and your own uploads, approve or request changes on drafts, client-visible updates. Internal drafts,
  tasks, notes and assessment are never shown.
- Quotation and engagement pages with version-bound accept or decline.

**Tests added in Phase 3** (55): engagement lifecycle 12, matter work 10, practice admin screens 12, firm setup
screens 8, public enquiry 6, portal 7. Full suite: **134 tests / 694 assertions, all passing** (28 Sep 2026).

## Phase 2 — completed

**Public site** (all addresses keep the WordPress form with a trailing slash; bare forms 301, see D8)
- Home, About, Offering, Contact, Terms, Blog (with pagination), category and tag archives, post pages at the
  legacy `/{slug}/` permalinks, RSS `/feed/`, `sitemap.xml`, and `robots.txt`, which blocks everything outside
  production. Verbatim copy from `content-manifest.json`, Poppins self-hosted, legacy images at their original URLs.
- Canonical links, Google site verification tag, Tawk.to on public pages only when configured (IDs only).
- Managed redirects (301/302/410) with hit counts, seeded with the old WordPress account/login addresses.
- `/privacy-policy/` exists as an **unpublished draft** awaiting the owner (404 until published).

**Content management** (Filament admin at `/admin`, groups Website / People / System)
- Pages: section-by-section editing of the fixed templates, draft → preview → publish or discard, revision history.
- Blog posts: rich text with sanitised HTML and image attachments, draft / review / scheduled / published /
  private / archived states, categories, tags, cover image, SEO fields, revisions, automatic 301 when a live post's
  slug changes, preview.
- Media library (public files only; type and duplicate checks), comment moderation, redirects, categories, tags.
- Site settings: identity, menu, brand colours, contact, email sender and admin recipients, Tawk.to, publishing
  options (D14).
- WordPress importer `php artisan mtl:import-wordpress` (repeatable, dry-run, local edits never overwritten; D13).
  Tested against the real capture of the live site.

**Identity and security**
- Seven roles (D4), invitation-only staff accounts (`php artisan mtl:invite-admin` for the first administrator;
  no fixed passwords seeded, no passwords emailed).
- Staff: `/admin/login` with required 2-step verification (authenticator app or email code) and a verified-session
  marker for staff-only downloads and previews (D10). Last-administrator protection, suspension, role grant history.
- Clients: `/register/`, `/log-in/`, `/password-reset/`, email verification, and a basic portal account page
  (profile, password, sign out other sessions). Registration responses don't reveal whether an email exists;
  staff accounts are refused at the public login.
- Append-only audit log viewer (no edit/delete in the UI; not claimed to be cryptographically tamper-proof).

**Legal-team applications**
- `/join-our-legal-team/` form with CV/document uploads on private storage, applicant status link, withdrawal,
  and replies to information requests. Admin review workflow with status history; file downloads audited.

**Tests** (`php artisan test`): 79 tests / 273 assertions covering public pages and URLs, redirects, previews,
page and post editing permissions, sanitisation, settings, media, comment moderation, client auth, WordPress import
repeatability/conflicts, and application file access.

## Blocked / waiting on owner

| Item | Blocks |
|---|---|
| **Privacy policy approval** (draft in the page editor) | **Launch** |
| Contact page details (email/phone/address/WhatsApp); live page has none | Final Contact page content |
| Namecheap shared hosting (owner, 28 Sep). Published Namecheap docs say: PHP 8.2–8.5 via "Select PHP Version" with per-extension toggles; SSH on port 21098; cron no more often than every 5 minutes and at most 5 cron jobs; default `upload_max_filesize`/`post_max_size` 1024M. **Still to confirm on the actual account:** MySQL/MariaDB version, document-root control for the domain, disk quota | Phase 6 deployment layout |
| WordPress WXR export + uploads backup | Final migration coverage (drafts/private/scheduled/SEO fields) |
| Decisions in `docs/content-gaps.md` §2–§6 (typos, layout quirks, comments, careers wording, alt text). §7 new wording approved 28 Sep | Final copy sign-off |
| **The firm's real engagement terms**, written in Admin → Engagement templates (the seeded template is an inactive outline) | Sending engagement terms in production |
| Upload limits ≥ 20M/25M (D19). Namecheap default is 1024M, so likely fine; owner will check at deployment | Uploads over the host default |
| New Phase 4 wording (`docs/content-gaps.md` §8) | Final copy sign-off |
| New Phase 5 wording (`docs/content-gaps.md` §9) | Final copy sign-off |
| **NGN and USD bank details** (Settings → Bank transfer, left empty for the system administrator) | Clients paying by transfer. Until then the portal shows no transfer option |
| Which currencies the firm's Paystack account accepts (`PAYSTACK_CURRENCIES`, currently `NGN`) | USD card payments |
| Paystack webhook URL in the Paystack dashboard, and live keys | Online payments in production |
| SMTP (owner fills `.env` on cPanel), Tawk IDs, admin notification emails (Settings → Email) | Live integration checks (build proceeds with test doubles). Owner reviews everything locally before any cPanel upload |

## Local environment notes

- PHP here lacks a CA bundle (`curl.cainfo`), see DECISIONS D7. Local tooling uses `storage/certs/cacert.pem`.
- Local `upload_max_filesize` is 2M; raise in php.ini (e.g. 20M) before testing large uploads by hand. Tests use
  fakes.
- New uploads land in `public/media/` (git-ignored); back it up with the database on the server.

## Next steps

All of them are in [OUTSTANDING.md](OUTSTANDING.md): staging on the hosting account (cPanel access), the WordPress
export, the owner's approvals, and then cutover, which only happens with the owner's approval.
