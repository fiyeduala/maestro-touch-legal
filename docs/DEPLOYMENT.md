# Deployment guide (cPanel)

For the owner and whoever uploads the site. Read it all once before starting.

**Nothing here is done on the live site until the owner approves the cutover plan (section 7).** Staging comes
first and uses a separate subdomain, database and folder. The WordPress site keeps running, untouched, until
cutover, and its files and database are kept afterwards.

## 0. What must not be touched

These live on the same hosting account and must keep working. The steps below never need to change them:

| Keep as is | How |
|---|---|
| Naija Virtual Notary and any other subdomain | Do not change its document root or files. Only the main domain's document root changes at cutover (and only a new `staging.` subdomain is added before that) |
| Business email (mailboxes, forwarders, MX records) | Do not edit DNS zone records or Email settings. The new site sends through an SMTP mailbox; it does not receive mail |
| DNS | No DNS change is needed: the domain already points at this hosting account |
| SSL | AutoSSL covers new subdomains automatically. Do not remove existing certificates |
| Other cPanel services (cron jobs, FTP accounts, databases) | Add new ones; do not edit or delete existing ones |

Before starting, take a **full cPanel backup** (cPanel → Backup → Download a Full Account Backup) and keep it off
the server. This is the owner's safety net for everything above.

## 1. Server requirements

- PHP **8.2 or newer** for both the web and the command line (cPanel → MultiPHP Manager, and
  `php -v` in Terminal). PHP 8.3 also works.
- PHP extensions (cPanel → Select PHP Version → Extensions): `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`,
  `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.
- MySQL 5.7+/8 or MariaDB 10.4+ (cPanel's default is fine).
- One cron job (section 4). No long-running processes are needed.
- Composer is optional: the `vendor` zip replaces it.

## 2. Two ways to get the code onto the server

- **Git (preferred).** The server downloads the code from the firm's private GitHub repository, and later updates
  are one command (section 2A). This needs cPanel Terminal.
- **Zip files.** Upload and extract zips with File Manager (section 2B). Use this if Git is not possible.

Either way, the PHP libraries (`vendor/`) come from Composer on the server or from the `mtl-vendor` zip. They are
not in Git.

### 2A. Git: one-time setup on the server

1. **A read-only key for the server.** In Terminal:

   ```
   ssh-keygen -t ed25519 -f ~/.ssh/mtl_deploy -N "" -C "mtouchlegal server"
   cat ~/.ssh/mtl_deploy.pub
   ```

   Copy the line it prints. On GitHub, open the repository → Settings → Deploy keys → Add deploy key, paste it,
   and leave **Allow write access unticked**. The server can then download the code but never change it.
2. **Tell SSH to use that key** for GitHub:

   ```
   printf 'Host github.com\n  IdentityFile ~/.ssh/mtl_deploy\n  IdentitiesOnly yes\n' >> ~/.ssh/config
   chmod 600 ~/.ssh/config
   ssh -T git@github.com          (answer yes; it should say "successfully authenticated")
   ```

   If the host blocks this connection, use an HTTPS address with a read-only fine-grained GitHub token instead.
   Never paste the token into a document.
3. **Download the code** into the app folder. It must not exist yet:

   ```
   cd ~
   git clone --depth 1 git@github.com:fiyeduala/maestro-touch-legal.git mtl_staging
   ```

   `--depth 1` downloads only the current code, not the project's full history, so the download stays small.

   Then continue with section 4, from step 3's library step.

### 2B. Zip files: build the upload packages (on the local computer)

```
npm run build
php tools/deploy/package.php
```

This writes to `dist/`:

- `mtl-app-<date>-<commit>.zip`: the code and the compiled CSS/JS. It excludes `.env`, uploads, backups,
  databases and tests.
- `mtl-vendor-<date>-<commit>.zip`: the PHP libraries, for when the server's Terminal cannot run Composer.
- `...-MANIFEST.txt`: the file counts and SHA-256 checksums of both zips.

Only committed code is packaged, so commit first. If the server has Composer, `php tools/deploy/package.php
--skip-vendor` skips the second zip, and the server installs the libraries itself (a download of about 30 MB).

## 3. Folder layout

The app folder is **not** web-accessible. Only its `public/` folder is.

**Layout A (preferred).** The domain's document root can be changed (cPanel → Domains → Manage):

```
/home/USER/mtl_app/          the whole app (.env, storage, vendor ...)
/home/USER/mtl_app/public    ← document root of the (sub)domain
```

**Layout B.** The main domain must stay on `public_html`:

```
/home/USER/mtl_app/          the whole app except the web files
/home/USER/public_html/      the contents of mtl_app/public/ (index.php, .htaccess, build/, images/, media/ ...)
                             plus app-path.php containing:  <?php return '/home/USER/mtl_app';
```

With layout B, set up the copy once from the app folder: `sh tools/deploy/copy-public.sh ~/public_html`. It copies
the web files, writes `app-path.php`, and records the web folder in `mtl_app/public-path.php` so Terminal commands
use it too (DECISIONS D45). After that, `server-update.sh` (section 6) copies the new web files on every update.
The copy never deletes anything, so uploads in `media/` are kept.

Never put the whole app inside `public_html`. Client documents, backups and logs stay in `mtl_app/storage`, which
is outside the web root.

## 4. Staging (do this first)

1. **Subdomain.** cPanel → Domains → Create: `staging.mtouchlegal.com`, document root `mtl_staging/public`
   (layout A). Wait for AutoSSL to issue the certificate (cPanel → SSL/TLS Status).
2. **Database.** cPanel → MySQL Databases:
   - Create a database, e.g. `USER_mtlstaging`, and a user with a long generated password.
   - Add the user to the database with **All Privileges**.
3. **Files.** With Git, the code is already in `mtl_staging` (section 2A). With zips, upload `mtl-app-….zip` to
   `/home/USER/` and extract it into `mtl_staging` with File Manager (Extract). Then, for the libraries, either:
   - extract `mtl-vendor-….zip` into the same folder, which creates `mtl_staging/vendor/`; or
   - in Terminal, run `cd ~/mtl_staging && composer install --no-dev --optimize-autoloader`.
4. **Settings.** Copy `.env.example` to `.env` (File Manager → Copy; show hidden files via Settings) and fill in:

   ```
   APP_ENV=staging
   APP_DEBUG=false
   APP_URL=https://staging.mtouchlegal.com
   DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…        (from step 2)
   MAIL_MAILER=log                                  (or SMTP with STAGING_MAIL_TO set to a tester's address)
   STAGING_USER=…                                   the username testers will type
   STAGING_PASSWORD_HASH=…                          see below
   PAYSTACK_SECRET_KEY=sk_test_…  PAYSTACK_PUBLIC_KEY=pk_test_…   (test keys only; live keys are refused here)
   SESSION_SECURE_COOKIE=true
   ```

   Do not set `BACKUP_PASSWORD` on staging unless you want staging backups.
5. **First run.** In Terminal:

   ```
   cd ~/mtl_staging
   php artisan key:generate
   php artisan mtl:staging-password          (type the staging password; paste the printed hash into .env)
   php artisan migrate --force
   php artisan db:seed --force               (pages, redirects, services, consultation types; no user accounts)
   php artisan filament:assets
   php artisan optimize
   php artisan mtl:invite-admin you@mtouchlegal.com "Your Name"
   ```

   The last command prints a one-time link for setting the first administrator's password. No password is ever
   preset or emailed.

   **Without Terminal:** these commands need a shell. If cPanel's Terminal is disabled, ask the host to enable
   "Shell access" for the account. This site deliberately has no web page that runs setup commands.
6. **Old site images.** `images/` is included in the app zip; nothing to do.
7. **Blog content.**
   - Export from WordPress: Tools → Export → All content, which gives a `.xml` file.
   - Upload it outside `public_html`, then run:

     ```
     php artisan mtl:import-wordpress --wxr=/home/USER/export.xml --dry-run   (shows what would change)
     php artisan mtl:import-wordpress --wxr=/home/USER/export.xml
     php artisan mtl:verify-import  --wxr=/home/USER/export.xml               (must end "No differences found.")
     ```

   Both commands only read the export; WordPress is not touched.
8. **Cron.** cPanel → Cron Jobs → every 5 minutes (`*/5 * * * *`):

   ```
   cd /home/USER/mtl_staging && php artisan schedule:run >> /dev/null 2>&1
   ```

   The Operations page in the admin panel shows when the scheduler last ran.
9. **Check.**
   - Open the staging address: it asks for the staging username and password.
   - Sign in at `/admin`.
   - Go through the owner checklist in [OWNER-QUICKSTART.md](OWNER-QUICKSTART.md).

## 5. Production settings (at cutover)

These are the same steps as staging, in a separate folder (`mtl_app`) with a separate database. The differences
in `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mtouchlegal.com
MAIL_MAILER=smtp   MAIL_USERNAME / MAIL_PASSWORD   (a mailbox created in cPanel → Email; the full address as username)
MAIL_HOST=premium280.web-hosting.com   MAIL_PORT=587   MAIL_SCHEME=   (STARTTLS; see below)
MAIL_FROM_ADDRESS=…    (the same mailbox, or another address the server may send as)
PAYSTACK_SECRET_KEY / PAYSTACK_PUBLIC_KEY   live keys, only when the firm is ready to take payments
BACKUP_PASSWORD=…      (long, random; keep a copy away from the server; section 6 of BACKUP-AND-RESTORE.md)
SESSION_SECURE_COOKIE=true
```

Mail settings are the ones Naija Virtual Notary uses on the same server. `mail.mtouchlegal.com` and the domain itself
go through Cloudflare, which carries only web traffic, so SMTP to them times out. Port 465 fails in the TLS handshake
on this host. `localhost` fails certificate checks. Every email is queued and is only sent when the scheduled task
runs (the cron job in section 4). Test with `php artisan queue:work --stop-when-empty -v`.

Leave `STAGING_*` empty. Then, in the Paystack dashboard, set the webhook URL to
`https://mtouchlegal.com/webhooks/paystack`.

## 6. Updating the site later

**With Git.** On the local computer, run `npm run build` if the design changed, run the tests, commit and push to
`main`. Then in cPanel Terminal:

```
cd ~/mtl_staging          (or ~/mtl_app for the live site)
sh tools/deploy/server-update.sh
```

The script:

- downloads only what changed;
- takes a backup, and asks before continuing without one;
- shows the maintenance page while it updates the database;
- clears and rebuilds the caches, then brings the site back.

It stops without changing anything if files were edited by hand on the server. It also stops if the libraries
changed and Composer is not available; it then says which vendor zip to extract. Update staging first, check it,
then update the live site.

**With zip files**, for each update:

1. Upload and extract the new `mtl-app` zip over `mtl_app`. `.env`, `storage/` and `public/media/` are not in
   the zip, so they are kept.
2. If `composer.lock` changed, update the libraries. The MANIFEST says which vendor zip goes with the release.
   Either extract the new vendor zip, or run `composer install --no-dev --optimize-autoloader`.
3. Run:

   ```
   php artisan down
   php artisan migrate --force
   php artisan filament:assets
   php artisan optimize
   php artisan up
   ```

Take a backup before step 1 (`php artisan mtl:backup`). If anything goes wrong, see [ROLLBACK.md](ROLLBACK.md).

## 7. Cutover plan (needs the owner's approval)

1. **Staging signed off.** Owner and staff checklists done, and the visual comparison reviewed
   ([visual-comparison/README.md](visual-comparison/README.md)).
2. **Final WordPress export.** Take a full cPanel backup, and a WordPress export (`.xml`) plus a database
   export from phpMyAdmin. Keep all three off the server too.
3. **Production app.** Set up `mtl_app` with its own database (sections 4–5), import the export, and run
   `mtl:verify-import`.
4. **Switch.** Change the main domain's document root to `mtl_app/public` (layout A); this can be undone in one
   step. With layout B, first move the WordPress files in `public_html` into a dated folder outside it (for
   example `~/wordpress-2026-10-xx/`) and copy in the new public files. **Do not delete WordPress.**
5. **Check.**
   - The home page, a blog post, an old post address (it should redirect), `/log-in/` and `/admin`.
   - An old image address such as `/wp-content/uploads/2025/08/tlk.jpg` should redirect to `/images/…`.
   - Send a test enquiry.
6. **Other services.** Confirm that email, the Naija Virtual Notary subdomain and other subdomains still work.
7. **Watch.** For the first days, watch the admin Operations page (scheduler, backups, failed emails).

The WordPress files and database stay on the account, unused, until the owner decides otherwise.
