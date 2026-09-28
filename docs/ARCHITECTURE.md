# Architecture — Maestro Touch Legal

Single-firm Laravel application replacing the WordPress site at https://mtouchlegal.com/. Not multi-tenant.

## 1. Stack and support horizon

| Component | Locked version | Notes |
|---|---|---|
| PHP | 8.2 (Composer `config.platform.php = 8.2.0`) | Security support ends **31 Dec 2026** (php.net). Hosting also offers 8.3+. |
| Laravel | 12.69.2 | Bug fixes ended 13 Aug 2026; security fixes until **24 Feb 2027** (laravel.com/docs/12.x/releases). |
| Filament | 5.9.0 (Livewire 4.4.6) | Staff/admin panel only. |
| HTMLPurifier | 4.19.1 via mews/purifier 3.4.4 | Sanitises blog/page rich text. |
| Database | MySQL/MariaDB (local: MariaDB 10.4 via XAMPP; tests: SQLite in memory) | Production version to be confirmed on cPanel. |
| Frontend | Blade + Livewire + Tailwind (Vite), compiled locally | Server never runs Node. |

**Upgrade path (recommended before February 2027):**
1. In cPanel "Select PHP Version"/MultiPHP, switch the domain to PHP 8.3; nothing else changes (code is 8.2/8.3 compatible).
2. Locally: set `config.platform.php` to 8.3, move to Laravel 13 (`laravel/framework:^13`), update Filament/Livewire
   within their majors, run the test suite, commit the new lockfile.
3. Deploy with the normal update procedure (`docs/DEPLOYMENT.md`). The server downloads packages with Composer, or a
   pre-built `vendor.zip` is uploaded if Composer hits shared-hosting memory limits.

## 2. Shared-hosting constraints and how the design meets them

| Constraint (Namecheap shared cPanel) | Design response |
|---|---|
| No root/Docker/Supervisor/Redis/WebSockets, no persistent daemons | Database queue, database cache/locks, file or database sessions; no daemons. |
| Cron no more often than every 5 minutes | One cron entry every 5 minutes: `php artisan ops:tick`. |
| Resource limits (CPU/memory/process time) | `ops:tick` works in bounded batches (≈ 4 minutes max, capped job count), then exits. |
| No Node on server | Assets built locally with `npm run build`; `public/build` is uploaded. |
| Main-domain document root may be fixed to `public_html` | Split layout (§8). |

### 2.1 `ops:tick` (the heartbeat)

```
cron (*/5) → php artisan ops:tick
  1. acquire an atomic lock (database cache lock, 10-minute TTL) — exits if a previous tick is still running
  2. record tick start in `system_heartbeats`
  3. run due periodic tasks: each task stores `last_success_at`; a task runs when now ≥ its next due time since
     that value, so missed ticks are caught up once (not replayed N times)
       · daily conversation digests (default 18:00 Africa/Lagos)
       · appointment reminders (window-based: "due since last run", deduplicated per appointment+offset)
       · overdue-task escalation, invoice overdue status, Paystack pending-reference reconciliation
       · export/archive cleanup, backups (when configured)
  4. drain the database queue: `queue:work --stop-when-empty --max-time=200 --max-jobs=100 --tries=3`
  5. record tick success + counts; failures are visible on the admin System Health page
```

Consequences stated plainly: emails and reminders can arrive up to ~5–10 minutes after the event. Chat does **not**
depend on the queue; messages are stored and visible immediately.

## 3. Application structure (modular monolith)

```
app/
  Domain/<Module>/            models, enums, services (transactional business logic), events
    Identity/                 users, roles, invitations, staff applications, sessions
    Clients/                  client profiles, organisations
    Intake/                   services, intake-form versions, enquiries, parties, conflict reviews
    Engagement/               quotations (+versions), engagement terms (+versions), acceptances
    Matters/                  matters, team assignments, stages, tasks, milestones, deadlines, timeline
    Messaging/                matter messages, internal notes, read markers, digests
    Documents/                documents, versions, requests, approvals, downloads
    Billing/                  invoices, lines, payments, allocations, receipts, credit notes, expenses,
                              client-funds ledger, Paystack, bank transfers
    Scheduling/               consultation availability, bookings, reminders
    Content/                  pages/sections, posts, taxonomies, media, revisions, redirects, WP import
    Operations/               settings, audit events, heartbeats, deliveries, backups, NVN handoffs
  Filament/                   staff panel (/admin) resources, pages, widgets
  Http/Controllers/Public     mirrored public site, blog, forms
  Http/Controllers/Portal     client portal (custom Blade/Livewire, /portal)
  Policies/                   record-level authorisation (one per model)
```

Business rules live in services (e.g. `ConvertEnquiryToMatter`, `RecordPaystackEvent`, `BookConsultationSlot`),
each running inside an explicit `DB::transaction` with row locks where races matter. Controllers, Livewire
components and Filament actions call services; they do not contain business logic.

## 4. Interfaces

| Surface | Path | Audience | Technology |
|---|---|---|---|
| Public site | `/`, `/about/`, `/offering/`, `/contact/`, `/blog/`, `/{post-slug}/`, `/tag/*`, `/category/*` | Everyone | Blade, pixel-matched to the WordPress site |
| New public pages | `/legal-assistance/`, `/consultations/`, `/join-our-legal-team/`, `/privacy/`, `/terms-and-conditions/` | Everyone | Blade, new styling consistent with brand |
| Auth | `/log-in/`, `/register/`, `/password-reset/` (existing paths kept) | Clients & staff | Blade/Livewire |
| Client portal | `/portal/*` | Clients | Blade + Livewire, mobile-first; no Filament terminology |
| Staff panel | `/admin/*` | All staff roles | Filament 5, Poppins theme, MFA required |

Legacy post permalinks live at the site root (`/{slug}/`), so the post route is registered last and only matches
slugs of published posts or recorded redirects; every fixed route wins first.

## 5. Authorisation model

- **Roles** (many per user, each grant/revocation recorded with actor and time): Technical Administrator,
  Firm Principal / Managing Lawyer, Lawyer (incl. Affiliate Lawyer flag), Case Officer, Finance Officer, Content Editor,
  Client.
- **Capabilities** are defined in code per role (`App\Domain\Identity\Capability`), not editable at runtime; this
  keeps the matrix in `docs/permission-matrix.md` true by construction.
- **Record-level access** is decided by policies. Staff other than full administrators see a matter only through an
  active `matter_team_members` row; clients see only matters of their client profile(s) where they are an authorised
  contact. Every query in lists/search/exports goes through a `visibleTo($user)` scope, so hiding navigation is never
  the control.
- **Full administrators** pass all policies for reading and operational actions, but client-only actions (accepting
  quotes/engagements, approving drafts as client, sending as client) check `actor is the client` and cannot be
  performed by anyone else. "View client portal" is a read-only preview mode that suppresses read receipts and
  disables every write.
- Final active full administrator cannot be suspended, deleted or demoted. Role/security changes require password
  re-confirmation within the last 10 minutes.

## 6. Key data-handling rules

| Area | Rule |
|---|---|
| Money | Integer minor units (`amount_minor`, kobo/cents) + `currency` (`NGN`/`USD`) on every financial row. No floats, no implicit FX. Consolidated reports require a stored rate, date and source. |
| Financial history | Issued invoices, payments and ledger entries are never edited; corrections are credit notes/reversal entries. |
| Audit | `audit_events` is insert-only from the application (no update/delete code paths or UI). Secrets are redacted before writing. Database/server administrators remain technically able to alter it; no cryptographic immutability is claimed. |
| Messages | Never edited or deleted; amendments are new records linked to the original with their own audience. Internal notes are a separate model/table, never loaded by client-facing queries, emails, digests or exports. |
| Files | Confidential files on the `private` disk (`storage/app/private`), served only via authorised controller routes with `Content-Disposition` rules; checksums recorded. Public blog/brand media on the `public_media` disk. No virus scanning is claimed unless a scanner is configured. |
| Time | Stored in UTC; firm business timezone setting (default `Africa/Lagos`); client timezone used for display. |
| Secrets | `.env` for infrastructure secrets; admin-editable integration keys stored encrypted (`encrypted` cast, `APP_KEY`), masked in UI, excluded from audit payloads. `APP_KEY` must never change after launch; `APP_PREVIOUS_KEYS` is used for rotation. |

## 7. Integrations

| Integration | Approach | Live status |
|---|---|---|
| SMTP | Laravel mail, sender identity from settings; each send logged in `deliveries` with status/attempts | Needs credentials |
| Paystack | Server-initialised hosted checkout from stored invoice amount; callback → server verify; signed webhook (HMAC-SHA512 of raw body) → idempotent event log keyed by event+reference; reconciliation job for stale pending references | Built against test doubles until sandbox keys arrive |
| Bank transfer | Per-currency instructions in settings; option shown only when instructions exist; client evidence → pending verification → staff verify/allocate/reject | Needs real instructions |
| Tawk.to | Public pages only; property ID + widget ID validated as `[a-f0-9]{24}` / `[a-z0-9]+`; script built from the IDs, never pasted | Needs IDs (existing site already uses Tawk) |
| WhatsApp | `https://wa.me/{E.164 digits}?text={generic text}` link only | Needs number |
| Naija Virtual Notary | Public link kept; internal manual handoff records (consent, external reference, status); adapter interface for a future API | Manual only |

## 8. Deployment layout (to be confirmed against the real cPanel account)

Preferred (if the main domain's document root can point anywhere):
```
/home/<user>/mtl_app/            Laravel app (source, vendor, storage, .env) — not web-accessible
/home/<user>/mtl_app/public      ← domain document root
```
Fallback when the main domain must serve from `public_html`:
```
/home/<user>/mtl_app/            everything except public/
/home/<user>/public_html/        contents of public/ only (index.php, .htaccess, build/, wp-content/uploads/, media/)
                                 index.php points to ../mtl_app/vendor and ../mtl_app/bootstrap/app.php, and the
                                 app sets its public path to public_html
```
Never copy the whole application into `public_html`. Private uploads, backups and logs stay under `mtl_app/storage`.
