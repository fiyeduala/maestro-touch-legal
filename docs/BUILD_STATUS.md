# Build status

Last updated: 28 September 2026 (end of Phase 1).

## Phase overview

| Phase | Scope | Status |
|---|---|---|
| 1 | Inspect project + live site, inventory/export content and assets, verify dependencies, architecture, permission matrix | **Done**, except hosting verification (blocked: needs cPanel access) |
| 2 | Public-site mirror, editable branding/pages/blog, authentication, roles/policies, staff applications | Not started (next) |
| 3 | Enquiries, conflict checks, quotations/engagement, clients, matters, teams, tasks, documents, approvals | Not started |
| 4 | Client portal, matter chat, internal notes, consultations, SMTP notifications, daily digests | Not started |
| 5 | NGN/USD billing, Paystack, manual transfers, client funds, reporting, audit views | Not started |
| 6 | Migration dry-run, visual/content comparison, security/workflow tests, staging checks, deployment package | Not started |

## Phase 1 — completed

- Laravel 12.69.2 skeleton with Filament 5.9.0, Livewire 4.4.6, mews/purifier; Composer pinned to PHP 8.2; Git
  repository initialised on `main`.
- Local MariaDB 10.4 database `mtl_local` with a dedicated user; base migrations run.
- Live site captured: 71 sitemap URLs (70 × 200, 1 already 404 on live), REST data for 6 posts, 31 pages, 40 media,
  1 category, 30 tags, 5 comments (`docs/source-capture/`).
- `docs/content-manifest.json`: verbatim copy for 9 public pages + 6 posts, by section and widget, status `extracted`.
- `docs/asset-manifest.json`: 43 assets; 41 retrieved and self-hosted under `public/wp-content/uploads/` with SHA-256,
  dimensions and MIME (2 Gravatar author avatars intentionally skipped). Original logo and favicon obtained.
- `docs/reference-screenshots/`: 24 full-page captures (12 pages × desktop 1440px / mobile 390px), mobile menu,
  computed styles (fonts, colours, sizes) in `computed-styles.json`.
- `docs/site-inventory.md`, `docs/content-gaps.md`, `docs/ARCHITECTURE.md`, `docs/permission-matrix.md`,
  `docs/DECISIONS.md`.
- Repeatable audit tooling in `tools/site-audit/` (extract, fetch assets, screenshots — also used later to compare the
  Laravel build against these references).
- Support horizons verified from official pages: Laravel 12 security fixes until 24 Feb 2027; PHP 8.2 until
  31 Dec 2026.

## Blocked / waiting on owner

| Item | Blocks |
|---|---|
| cPanel details (PHP CLI/web versions + extensions, SSH/terminal, document-root control, cron, MySQL version, upload limits, disk, symlinks) | Phase 6 deployment layout; hosting verification step of Phase 1 |
| Contact page details (email/phone/address/WhatsApp) — live page has none | Final Contact page content |
| WordPress WXR export + uploads backup | Final migration coverage (drafts/private/scheduled/SEO fields) |
| Decisions in `docs/content-gaps.md` §2–§5 (typos, layout quirks, hello-world post, comments) | Final copy sign-off |
| SMTP, Paystack sandbox/live, bank instructions, Tawk IDs, admin/digest emails | Live integration checks (build proceeds with test doubles) |

## Local environment notes

- PHP here lacks a CA bundle (`curl.cainfo`), see DECISIONS D7. Local tooling uses `storage/certs/cacert.pem`.
- Local `upload_max_filesize` is 2M; raise in php.ini (e.g. 20M) before testing large uploads, or tests use fakes.

## Next steps (Phase 2)

1. Theme tokens (Poppins self-hosted, brand colours), public layout (header/footer/mobile menu) and the four mirrored
   pages, then blog archive/article/tag/category routes with legacy permalinks.
2. Settings + page/section content model seeded from `content-manifest.json`; branding editor.
3. Identity: users, roles (D4), invitations, verified email, password reset at existing paths, staff MFA, session
   management, suspension, last-admin protection, audit event log.
4. Legal-team application form + review workflow.
5. Visual comparison of the Laravel pages against `docs/reference-screenshots/`.
