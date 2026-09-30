# Rollback guide

For the owner and whoever manages the cPanel account. Choose the case that fits.

## A. Right after cutover: go back to WordPress

WordPress was not deleted (see [DEPLOYMENT.md](DEPLOYMENT.md), section 7), so going back is quick.

- **Layout A** (the document root was changed): cPanel → Domains → Manage → set the main domain's document root
  back to `public_html`. The WordPress site is live again within a minute.
- **Layout B** (files were swapped in `public_html`):
  1. Move the new site's files out of `public_html` into a dated folder.
  2. Move the WordPress files back from the dated folder they were kept in.

Nothing else is needed: DNS, email, SSL and the Naija Virtual Notary subdomain were never changed.

Anything clients or staff entered in the new site meanwhile (enquiries, registrations, messages) stays in the new
site's database. Nothing is lost, but it is not visible in WordPress. Export what matters from the admin panel
before switching back.

## B. A bad update: go back to the previous release

1. `php artisan down`
2. Put the previous code back.
   - **With Git:** `git checkout <previous commit>`. The update script prints it at the end ("Previous
     version: …"), and `git log --oneline` lists them all. When the fix is pushed later, run
     `git checkout main`, then the update script.
   - **With zips:** extract the **previous** `mtl-app` zip (and its matching vendor zip, if the libraries changed) over the app
     folder. `.env`, `storage/` and uploads are not in the zips, so they are untouched.
3. If the bad update changed the database (its release notes, or `php artisan migrate:status`, show new
   migrations):
   - Normally, restore the backup taken just before the update. See
     [BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md), section 3.
   - `php artisan migrate:rollback --step=N` also exists, but only use it when the release notes say it is safe.
     Rolling back a migration can remove data entered since the update.
4. `php artisan optimize:clear && php artisan optimize`
5. `php artisan up`

Keep the last three release zips on your computer so step 2 is always possible.

## C. The site shows an error page after an update

1. **Check the log.** Look at `storage/logs/laravel.log`, the last lines, in File Manager. It never contains
   passwords or payment keys, but it can contain client names. Do not share it outside the firm.
2. **Clear the caches.** Most post-upload errors are stale caches: run `php artisan optimize:clear`, then
   `php artisan optimize`.
3. **Roll back.** If that does not fix it, roll back (B).
