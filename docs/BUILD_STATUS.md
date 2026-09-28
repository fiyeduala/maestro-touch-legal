# Build status

Last updated: 28 September 2026 (end of Phase 3).

## Phase overview

| Phase | Scope | Status |
|---|---|---|
| 1 | Inspect project + live site, inventory/export content and assets, verify dependencies, architecture, permission matrix | **Done**, except hosting verification (blocked: needs cPanel access) |
| 2 | Public-site mirror, editable branding/pages/blog, authentication, roles/policies, staff applications | **Done** locally, 79 automated tests passing. Pixel comparison against the reference screenshots moves to Phase 6. |
| 3 | Enquiries, conflict checks, quotations/engagement, clients, matters, teams, tasks, documents, approvals, portal pages | **Done** locally, 134 automated tests passing in total |
| 4 | Portal chat, internal notes, consultations, SMTP notifications, daily digests | Not started (next) |
| 5 | NGN/USD billing, Paystack, manual transfers, client funds, reporting, audit views | Not started |
| 6 | Migration dry-run, visual/content comparison, security/workflow tests, staging checks, deployment package | Not started |

Nothing has been deployed. The live WordPress site is untouched.

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
| SMTP (owner fills `.env` on cPanel), Paystack test keys (owner supplying), NGN and USD bank details (Settings → Bank transfer), Tawk IDs, admin notification emails (Settings → Email) | Live integration checks (build proceeds with test doubles). Owner reviews everything locally before any cPanel upload |

## Local environment notes

- PHP here lacks a CA bundle (`curl.cainfo`), see DECISIONS D7. Local tooling uses `storage/certs/cacert.pem`.
- Local `upload_max_filesize` is 2M; raise in php.ini (e.g. 20M) before testing large uploads by hand. Tests use
  fakes.
- New uploads land in `public/media/` (git-ignored); back it up with the database on the server.

## Next steps (Phase 4)

1. Matter chat between the client and the team (portal and admin), with attachments on private storage.
2. Internal notes (staff only).
3. Consultations: availability, booking, reschedule/cancel, meeting links, outcomes.
4. Email notifications through the configured SMTP (test doubles until the owner supplies SMTP) and the daily
   conversation digest.
