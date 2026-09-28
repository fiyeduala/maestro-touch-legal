# Build status

Last updated: 28 September 2026 (end of Phase 2).

## Phase overview

| Phase | Scope | Status |
|---|---|---|
| 1 | Inspect project + live site, inventory/export content and assets, verify dependencies, architecture, permission matrix | **Done**, except hosting verification (blocked: needs cPanel access) |
| 2 | Public-site mirror, editable branding/pages/blog, authentication, roles/policies, staff applications | **Done** locally, 79 automated tests passing. Pixel comparison against the reference screenshots moves to Phase 6. |
| 3 | Enquiries, conflict checks, quotations/engagement, clients, matters, teams, tasks, documents, approvals | Not started (next) |
| 4 | Client portal, matter chat, internal notes, consultations, SMTP notifications, daily digests | Not started |
| 5 | NGN/USD billing, Paystack, manual transfers, client funds, reporting, audit views | Not started |
| 6 | Migration dry-run, visual/content comparison, security/workflow tests, staging checks, deployment package | Not started |

Nothing has been deployed. The live WordPress site is untouched.

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
| cPanel details (PHP CLI/web versions + extensions, SSH/terminal, document-root control, cron, MySQL version, upload limits, disk, symlinks) | Phase 6 deployment layout; hosting verification step of Phase 1 |
| WordPress WXR export + uploads backup | Final migration coverage (drafts/private/scheduled/SEO fields) |
| Decisions in `docs/content-gaps.md` §2–§6 (typos, layout quirks, comments, careers wording, alt text) | Final copy sign-off |
| SMTP, Paystack sandbox/live, bank instructions, Tawk IDs, admin/digest emails | Live integration checks (build proceeds with test doubles) |

## Local environment notes

- PHP here lacks a CA bundle (`curl.cainfo`), see DECISIONS D7. Local tooling uses `storage/certs/cacert.pem`.
- Local `upload_max_filesize` is 2M; raise in php.ini (e.g. 20M) before testing large uploads by hand. Tests use
  fakes.
- New uploads land in `public/media/` (git-ignored); back it up with the database on the server.

## Next steps (Phase 3)

1. Enquiry form on Contact (preserving the live copy), enquiry inbox, assignment, conflict-check record.
2. Clients (individual/organisation), quotations and engagement acceptance.
3. Matters with team membership as the access boundary, tasks, confidential documents with versioning,
   approvals.
4. Policies and tests for record-level access (a lawyer sees only their matters).
