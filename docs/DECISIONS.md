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
so a small `roles` + `role_user` (with granted/revoked actor and timestamps) model is simpler and gives a full grant
history for audit.

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
