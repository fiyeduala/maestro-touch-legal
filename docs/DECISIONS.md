# Decisions log

Newest last. Each entry: decision, reason, consequence.

### D1 — Build on PHP 8.2, Laravel 12, Filament 5 (2026-09-28)
Owner confirmed hosting offers PHP 8.3+, but asked to build on the local PHP 8.2. Composer is pinned with
`config.platform.php = 8.2.0` so locked packages run on 8.2 and 8.3. Laravel 12 security support ends 24 Feb 2027 and
PHP 8.2 on 31 Dec 2026, so an upgrade to PHP 8.3 + Laravel 13 is planned before then (ARCHITECTURE §1).

### D2 — Server maintenance via cPanel terminal, no heavy local downloads (2026-09-28)
Frontend assets are compiled locally and uploaded; the server never needs Node. Routine updates are
`git pull`/upload + `php artisan migrate --force` + cache refresh. Composer dependency changes download on the
server; fallback is uploading a pre-built `vendor.zip`.

### D3 — Legacy media keep their original URLs (2026-09-28)
WordPress images are self-hosted at `public/wp-content/uploads/...`, the same paths they had, so existing links in
posts, search results and social shares keep working without redirects. New uploads use `public/media/`.

### D4 — In-app role model instead of spatie/laravel-permission (2026-09-28)
The current spatie/laravel-permission (8.x) requires PHP 8.3; the 8.2 pin would lock an older major. Roles here are a
fixed set of seven with capabilities defined in code, and the real control is record-level (matter team membership),
so a single `user_roles` table (one row per grant, with granted/revoked actor and timestamps; role names come from
the `App\Domain\Identity\Role` enum) is simpler and gives a full grant history for audit.

### D5 — Staff two-factor authentication uses Filament 5's built-in MFA (2026-09-28)
Authenticator-app (TOTP) with recovery codes, email code as an alternative. Required for every staff account.

### D6 — Tawk.to and Poppins (2026-09-28)
The live site already uses Tawk.to; it stays on public pages only. Rendered text on the live site is 100% Poppins
(400/500/600); the plugin-loaded Inter and Libre Franklin are unused, so dropping them causes no visible change.
Poppins is self-hosted (no Google Fonts request) to avoid a third-party dependency on portal pages.

### D7 — Local HTTPS certificate bundle (2026-09-28)
Local PHP has no `curl.cainfo`, so HTTPS calls fail. Verification is never disabled: local tooling uses the Mozilla
bundle at `storage/certs/cacert.pem` (git-ignored). Recommended permanent fix on this PC: set
`curl.cainfo` and `openssl.cafile` in `C:\Program Files\php 8.2\php.ini` to that file. Not needed on cPanel.

### D8 — Public URLs keep WordPress-style trailing slashes (2026-09-28)
Every public page and post URL ends in `/` exactly as on the live site, so existing links, search results and shares
keep working. The bare form (`/about`) answers 301 to `/about/`. Laravel's `url()` trims slashes, so public links,
canonicals, the sitemap and the feed use `App\Support\SiteUrl::to()`. The test client also trims slashes;
`tests/TestCase.php` restores them so tests request the real address.

### D9 — No external password-breach (HIBP) check (2026-09-28)
Passwords need at least 12 characters with mixed case, a number and a symbol. The "pwned passwords" lookup is not
used: it needs an outbound HTTPS call during registration, which is unreliable on shared hosting and would make
sign-up fail whenever the API is unreachable. It can be switched on later if the host proves reliable.

### D10 — Staff sessions carry a verified-sign-in marker (2026-09-28)
After the password and 2-step challenge, the staff session is marked (`EnsureStaffSessionVerified`). Staff-only
routes outside Filament's own pages (page/post previews, application file downloads) require that marker as well as
the role, so a session opened any other way cannot reach them. Staff sign in at `/admin/login`; the public
`/log-in/` refuses staff accounts.

### D11 — Content model and publishing rights (2026-09-28)
- **Pages** are the fixed set of mirrored templates (home, about, offering, contact, terms, privacy, blog, careers,
  log in, register). Their copy is editable by section, but pages cannot be created or deleted in the admin. Saving
  creates a draft revision; the live page changes only on **Publish**. Drafts can be previewed or discarded.
- **Publishing** pages and posts needs the `publish-content` gate: Technical Administrator or Firm Principal, or
  Content Editors when the owner turns on "editors can publish" (off by default). Editors can write drafts and submit
  posts for review. Editing a post that is already live also needs publish rights.
- Renaming a live post's slug adds a 301 from the old address automatically and re-points older redirects, so there
  are no chains. Every save stores a revision snapshot.
- Rich text is sanitised with HTML Purifier on save (profiles `content` and `comment`).
- Redirects are applied by global middleware before routing, but can never shadow `/admin`, `/portal`, Livewire,
  build assets or application-file routes. 410 Gone is supported for removed content.

### D12 — Public media storage (2026-09-28)
New blog and brand uploads go on the `media` disk (`public/media/`, git-ignored). Legacy WordPress files stay at
`/wp-content/uploads/` (D3). Accepted types are JPEG, PNG, WebP, GIF and PDF, up to 10 MB. SVG is refused because it
can carry scripts. The real file type is checked on the server, duplicates are rejected by SHA-256, and imported
WordPress media cannot be deleted from the library. Uploads are public by design and the upload screen says so;
confidential files never go here.

### D13 — WordPress import is repeatable and never overwrites local edits (2026-09-28)
`php artisan mtl:import-wordpress` reads the REST capture (`docs/source-capture/rest`) or, with `--live=`, the live
site read-only. Each record is mapped by its WordPress ID with a checksum of the source:
- re-running with WordPress unchanged changes nothing;
- if WordPress changed but the post was also edited here, the local version is kept and the run reports a conflict;
- `--dry-run` reports what would change without saving.

`hello-world` is imported as a draft. Comments are imported, but new public comments stay off until the owner
decides (`content.comments_open`).

### D14 — Site settings are a fixed list of typed keys (2026-09-28)
The Settings screen (full administrators only) edits a defined set of keys: site identity, menu, brand colours,
contact details, mail sender, admin notification recipients, Tawk.to IDs and publishing options. There is no
free-form "custom script" field. Tawk.to takes only the property and widget IDs, which are format-checked, and the
app builds the embed code itself, on public pages only. Changes are audited.

### D15 — Representation starts only at internal approval (2026-09-28)
A matter is opened only by a full administrator approving an accepted engagement. The engagement always starts
from an enquiry, so the conflict check and intake history travel with it. Payment plays no part in opening a
matter. A client accepting terms in the portal does **not** open the matter; the portal says the firm will confirm.

### D16 — Conflict checks are never automatic (2026-09-28)
A lawyer or administrator must record "cleared" with a note before engagement terms can be sent. The check is
repeated when the matter is approved. Adding a party to the enquiry reopens the check (status back to pending,
with an event and an audit entry). Possible matches are shown to help the reviewer; they never clear anything.

### D17 — Client acceptance is bound to a version (2026-09-28)
Quotations, engagement terms and released drafts are accepted against a specific version ID. The acceptance
record stores a hash of that version's content, the signed name where there is one, IP and user agent. If a newer
version exists, the old one can no longer be accepted. Acceptance recorded outside the portal (for example on
paper) is entered by staff with an evidence file and marked as offline. Full administrators can never accept,
approve or sign as a client.

### D18 — Role boundaries in legal work (2026-09-28)
Finance has no access to matter content, legal documents or internal notes. It sees billing metadata and
quotations only. A case officer can prepare and submit drafts but cannot give final approval; that needs a lawyer
on the team or an administrator. Clients see only the released version of a document and documents they uploaded
themselves. Internal drafts, tasks, internal notes, internal events and the internal assessment are never shown
in the portal.

### D19 — Confidential uploads (2026-09-28)
Uploads go to the private `confidential` disk and are served only through authorised, audited download routes.
Allowed types are PDF, DOCX, JPEG, PNG and WebP (`UploadGuard::TYPES`). Legacy `.doc` files are refused because they
can carry macros and cannot be previewed safely. The limit is 20 MB per file (`UploadGuard::MAX_KILOBYTES`).
Livewire's temporary uploads use the private `local` disk, never public storage. **No virus scanner is
configured**, and nothing in the app claims files are scanned. On cPanel, `upload_max_filesize` must be at least
20M and `post_max_size` at least 25M, or large files will fail before they reach the app.

### D20 — Portal contacts are invitation-only (2026-09-28)
A client record gets portal contacts only by staff invitation (a one-time link). The contact must be a verified
client account. Removing a contact's access needs a reason. It takes effect on their next request and is audited.
Registering on the site never links a person to an existing client.

### D21 — Deadlines are entered by hand; task reminders every 15 minutes (2026-09-28)
The system does not calculate limitation or court deadlines; staff enter them. `tasks:notify` runs every 15 minutes
from the scheduler, within the 5-minute cron limit.

### D22 — Filament closures must name the query `$query` (2026-09-28)
Filament resolves closure parameters by name. Always write `fn (Builder $query)`, never `$q`. A misnamed
parameter silently receives nothing.

### D23 — Service catalogue versioning (2026-09-28)
Intake forms are versioned. A published version is never edited; saving changes starts a new draft, so answers
already given always match the questions that were asked. An engagement template's version rises only when its
cleaned (sanitised) body changes. Renaming or deactivating does not change the version. Services and templates
are deactivated, never deleted. `ServiceSeeder` adds the eight practice areas from the live site with
intake questions **published** (owner approved the wording on 28 Sep 2026) and an **inactive** outline engagement
template, which stays off until the firm writes its real terms (content-gaps §7).

### D24 — Public enquiry form (2026-09-28)
The main form is at `/legal-assistance/`. Step one is choosing a service, which is a plain link, so the form works
without JavaScript. Step two holds the contact details, the service's intake questions, a summary, the
non-representation notice and consent. The Contact page keeps its live copy and adds a short "Send Us a Message"
form below it, recorded as a contact-form enquiry. Both forms have a honeypot and are rate-limited to 5 a minute
per IP. Submitting never creates an account and never creates a lawyer–client relationship.
