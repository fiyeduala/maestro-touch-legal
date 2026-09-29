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

### D25 — Matter conversations (2026-09-29)
Each matter has two threads: the client conversation and internal notes. Internal notes are a separate pane on the
staff screen, headed "INTERNAL — never shown to the client". They never appear in the portal, client emails,
client exports or client recaps. Messages are never edited or deleted. A correction is a new message linked to the
original ("Add correction"), and both stay visible. Staff can also record a phone call or meeting as a note on
either thread. Files a staff member attaches to a client message are released to the client with that message,
without a separate "document released" email. Files stay on private storage and download only through checked
links. Email notices about new messages wait 10 minutes (`Conversations::NOTICE_DELAY_MINUTES`) and are skipped
if the recipient has read the message by then, which avoids one email per message during a live exchange.

### D26 — End-of-day recaps (2026-09-29)
One recap is sent each day at the time set in Settings → Email (default 18:00, Lagos time). A client contact gets
only their own client's matters. A client email never mixes clients and is never CC'd to anyone else. The firm
recap goes to active full administrators whose addresses are listed in Settings → Email. No address is invented:
if the list is empty, no firm recap is sent. Unverified or suspended users get nothing, and access is checked
again when each recap is sent. Recaps never contain internal notes, passwords, identity-document contents or the
attached files themselves, only a count of attachments and a portal link. Delivery is not guaranteed to happen
exactly once. A recap that fails, or that was being sent when a run stopped ("Outcome unknown"), is never resent
automatically. A full administrator can resend it from Admin → Operations, and this is audited.

### D27 — Consultations (2026-09-29)
There are no per-lawyer calendars. The firm sets its opening hours, closed dates, buffer between bookings, notice
period, how far ahead clients can book, reminder times and how many consultations may run at the same time
(Settings → Consultations, full administrators only). Clients request a time in the portal. Staff confirm it, choose
the host, and paste the meeting link, which must start with `https://`. No video service is integrated.
Confirmation, reschedule and reminder emails carry an .ics calendar file. Clients can reschedule or cancel until
the cut-off set in Settings. `ConsultationTypeSeeder` adds one free 30-minute "Initial consultation" type. Paid
types store their fee in minor units, but taking payment for consultations is left to Phase 5. A consultation
never starts representation by itself.

### D28 — Background work on shared hosting (2026-09-29)
Namecheap allows cron no more often than every 5 minutes, so one cPanel cron runs `schedule:run` every 5 minutes.
Every scheduled task is locked (`withoutOverlapping`). The queue is processed by a bounded
`queue:work --stop-when-empty --max-time=180`, never a long-running daemon. Each run records a heartbeat. Admin →
Operations shows when the scheduler last ran and warns after 15 minutes without a run. It also shows the queue,
failed emails, the mail transport (it warns while mail is only logged), and the recaps.

### D29 — Tawk.to only on public pages (2026-09-29)
The Tawk.to chat widget loads on public marketing pages only, never in the client portal, on account pages or in
the admin panel. This keeps a third-party script away from confidential client screens.

### D30 — Office hours and admin address (2026-09-29)
The owner confirmed that the firm keeps 24/7 office hours (Settings → Consultations) and that the administrator
address is `admin@mtouchlegal.com`. NGN and USD bank details were deliberately left empty, for the system
administrator to enter in Settings → Bank transfer. Until they are entered, clients are not offered bank transfer.

### D31 — Billing model (2026-09-29)
- Every financial document is in one currency, NGN or USD. Amounts are whole kobo or cents in integer columns.
  There is no currency conversion anywhere.
- An invoice can be edited only as a draft. Once issued, corrections are made with credit notes (capped at the
  unpaid balance) or payment reversals. Only a draft can be cancelled, and it needs a recorded reason.
- A payment is money in one currency from one client. It can be split across invoices through allocations, and
  anything left over becomes client credit. Credit is applied only by a finance action, never automatically.
- Paying an invoice does not start representation (see the engagement rules from Phase 3).
- Reports never add NGN to USD, because no exchange rate, date or source is recorded.
- Paid consultation types still only store a fee. Staff raise an invoice for it by hand.

### D32 — Paystack and bank transfers (2026-09-29)
- The secret key lives only in `.env` and is never shown on a page, in logs or in the audit. Test and live keys
  are kept apart by the owner in `.env`.
- The amount charged is always the invoice's stored balance and currency.
- Only currencies listed in `PAYSTACK_CURRENCIES` are offered for card payment. The default is `NGN`, because
  USD needs the firm's Paystack account to be enabled for it. The page warns that foreign cards may be declined.
- A payment is marked paid only after the server calls Paystack's verify endpoint. Webhooks are
  signature-checked (HMAC-SHA512) and duplicates are ignored. A success message sent to the site but not confirmed
  by that check is not trusted.
- `payments:reconcile` runs every 15 minutes. It verifies checkouts nobody returned from, and marks them abandoned
  after 48 hours.
- A wrong amount or currency, or a dispute, goes to "Needs review" for finance, and no money is moved automatically.
- Refunds are "pending" until Paystack confirms them. Manual refunds need evidence.
- A bank-transfer slip is only a claim. Finance must verify the amount and date against the bank statement before
  the invoice counts as paid. Slips are private files and are not virus-scanned.

### D33 — Client funds (2026-09-29)
- This ledger records money held for a client, such as recovered debts or settlement sums. It is separate from fee
  invoices, and its figures are never counted as the firm's income.
- Entries are receipts, remittances to the client, payments to third parties, or authorised transfers to fees.
  Any outflow needs a written authorisation reference and cannot take the balance for that client and currency
  below zero.
- The system never takes fees from this ledger and never pays anything out; it only records what a person did.
- Mistakes are corrected by a reversal entry.
- Reconciliation records the bank statement balance against the ledger and shows any difference, without changing
  anything.

### D34 — Report definitions (2026-09-29)
Dates are in the firm's time zone and the date range includes both ends. Every figure is shown per currency.
- **Invoiced:** issued (not draft or cancelled) invoices, counted by issue date.
- **Received:** verified payments, counted by the bank-statement date for manual payments or by Paystack's paid
  date for online payments. Confirmed refunds are shown separately.
- **Outstanding:** today's unpaid balance on open invoices, aged by days past the due date.
- **Awaiting verification:** transfer slips not yet checked. They are not counted as received.
- **Quotations awaiting a reply:** sent quotations. These are estimates only.
- **Time to first action:** time from an enquiry's submission to the first change of status away from "New" by a
  staff member.
- A service or team filter only counts records linked to a matter.
- Every CSV export is audited with its filters, and spreadsheet formulas in text cells are neutralised.

### D35 — Finance officers and matters (2026-09-29)
Finance officers need to bill every client but must not read matter files. On billing forms (invoices, payments,
expenses and client funds) they can search any client and choose any matter by reference and title. They still
cannot open matter, document or conversation screens. Lawyers and case officers only see clients and matters on
their own teams.

### D36 — Test databases (2026-09-29)
The automated tests run on in-memory SQLite for speed. Development runs on local MariaDB (`mtl_local`), and every
migration is applied there as it is written. Before any upload to cPanel, the full suite will also be run
against a MariaDB test database (`mtl_test`) to catch dialect differences. Date filters use `whereDate`, which
behaves the same on both databases.
