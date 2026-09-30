# Maestro Touch Legal

The website, client portal and practice-management system for Maestro Touch Legal (mtouchlegal.com), built with
Laravel 12, Filament 5 and Livewire 4. It replaces the previous WordPress site.

## Start here

- [docs/OWNER-QUICKSTART.md](docs/OWNER-QUICKSTART.md) and [docs/STAFF-QUICKSTART.md](docs/STAFF-QUICKSTART.md):
  using the admin panel
- [docs/BUILD_STATUS.md](docs/BUILD_STATUS.md): what is built
- [docs/OUTSTANDING.md](docs/OUTSTANDING.md): what is still needed from the owner before launch
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/DECISIONS.md](docs/DECISIONS.md): how it works and why
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/ROLLBACK.md](docs/ROLLBACK.md) and
  [docs/BACKUP-AND-RESTORE.md](docs/BACKUP-AND-RESTORE.md): putting it on cPanel and keeping it safe
- [docs/TEST-REPORT.md](docs/TEST-REPORT.md): test results

## Everyday commands

```sh
php artisan test                 # run the tests
npm run build                    # rebuild CSS/JS after changing resources/ (commit public/build)
sh tools/deploy/server-update.sh # on the server: update to the latest code on GitHub
```

Secrets (Paystack keys, mail passwords, the backup password) go only in `.env`, never in Git.
