# Backup and restore guide

For the owner and the technical administrator.

## 1. What is backed up

`php artisan mtl:backup` runs every night at `BACKUP_AT` (default 02:30, Lagos time) through the cron job. It
writes one zip to `storage/app/backups/`, which is outside the web root. The zip contains:

- the whole database (clients, matters, invoices, messages, pages, posts, audit log …);
- all stored files:
  - confidential client and matter documents;
  - payment evidence;
  - job applications;
  - website media uploads.

Every file inside the zip is **AES-256 encrypted** with `BACKUP_PASSWORD` from `.env`. Nothing is backed up until
that password is set; the admin Operations page shows a warning until then. The newest `BACKUP_KEEP` backups (default 7)
are kept, and older ones are removed.

Not included: the code (it comes from the release zips) and `.env`. Keep a copy of `.env` somewhere safe and
private. It holds the database, email and payment passwords.

## 2. Keeping copies away from the server

A backup that sits on the same hosting account protects against mistakes, not against losing the account (host
failure, suspension, compromise). **At least once a week, download the newest backup and store it somewhere else.**
Use a firm-controlled cloud drive or an encrypted external disk.

- **Downloading.** In the admin panel go to Operations → Backups → *Download a backup*. Only a technical
  administrator can do this, after re-entering their password, and every download is recorded in the audit log.
- **The backup password.** Store `BACKUP_PASSWORD` separately from the backups, for example in the firm's
  password manager and a sealed paper copy. Without it no backup can be opened. With it and a backup file, anyone
  can read every client document, so treat both like the office safe key.
- **Checking.** Check the Operations page regularly. It shows the last backup, its size, the free disk space, and
  a warning if the nightly backup is late or failed.

The WordPress backup (the full cPanel backup and WordPress export taken before cutover) is kept separately and is
never deleted by this site.

## 3. Restoring

A restore never overwrites the live site. It goes into a **new, empty** database and a **new, empty** folder, so
you can check the result before switching anything over.

1. **Create the database.** cPanel → MySQL Databases: create an empty database (e.g. `USER_mtlrestore`) and add
   the site's existing database user to it with All Privileges.
2. **Restore.** In Terminal:

   ```
   cd ~/mtl_app
   php artisan mtl:restore mtl-backup-20261015-023000.zip --db-name=USER_mtlrestore --files-to=/home/USER/restore-2026-10-15
   ```

   Use `--ask-password` to type the backup password if it is not in this server's `.env`. For example, when
   restoring onto a fresh account. The command checks the backup's manifest and row counts, and refuses to touch a
   database or folder that is not empty.
3. **Switch.** To make the restored copy live, with the owner's approval:
   1. `php artisan down`
   2. In `.env`, set `DB_DATABASE` to the restored database.
   3. The restored folder has one subfolder per storage area. Move each current folder aside, then move the
      restored one into its place:

      | Restored subfolder | Goes to |
      |---|---|
      | `local/` | `storage/app/private` |
      | `confidential/` | `storage/app/confidential` (or `CONFIDENTIAL_STORAGE_ROOT`) |
      | `media/` | `public/media` (layout B: `public_html/media`) |
      | `public/` | `storage/app/public` |
   4. `php artisan optimize:clear`, then `php artisan up`.
4. **Keep the old copy.** Keep the previous database and folders until you are sure. Delete them by hand later.

### Restoring onto a new hosting account (disaster recovery)

1. Set up the site from the latest release zip ([DEPLOYMENT.md](DEPLOYMENT.md), sections 4–5).
2. Upload the backup file.
3. Run `mtl:restore … --ask-password` as above.

This is why copies must live off the server (section 2).

## 4. Tested

Automated tests cover:

- no backup without a password;
- an encrypted backup restored into an empty database and folder, with matching row counts and files;
- keeping only the newest N backups;
- download limited to technical administrators after a password check;
- refusal to restore over the live database.

A restore drill on the real server has **not** been run yet. Do one on staging before cutover (see
[OUTSTANDING.md](OUTSTANDING.md)).
