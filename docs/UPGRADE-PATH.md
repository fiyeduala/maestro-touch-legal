# Support horizons and upgrade path

For the owner and whoever maintains the site. Dates are the published support schedules as at September 2026.
Check them again at each review.

## Current versions

| Part | Version | Security fixes until |
|---|---|---|
| PHP | 8.2 (8.3 and newer also work) | **31 December 2026** |
| Laravel | 12.69 | **24 February 2027** |
| Filament (admin panel) | 5 | follows the Laravel versions it supports |
| Livewire | 4 | as Filament |
| MariaDB / MySQL | the host's version | the host's responsibility |

PHP 8.2 was chosen because it is what the local computer has. It reaches end of life first, so the first upgrade
is PHP, and it is small.

## Step 1: PHP 8.3 or 8.4 (before the end of 2026)

The code needs no change. Namecheap offers PHP 8.2–8.5 per account.

1. On staging, choose PHP 8.4 in cPanel → Select PHP Version, with the same extensions as today
   ([DEPLOYMENT.md](DEPLOYMENT.md), section 1).
2. Run the checks in the owner's staging checklist.
3. Switch production the same way. To go back, choose 8.2 again in the same screen.

If the account's command-line PHP differs from the web PHP, the cron job must use the new one. Check with `php -v`
in Terminal.

## Step 2: Laravel 13 (in 2027, before Laravel 12's support ends)

This is a code update and is done on the local computer, not on the server:

1. Check Laravel's upgrade guide and that Filament supports Laravel 13.
2. In `composer.json`, set `config.platform.php` to the server's PHP version (8.3 or newer) and require
   `laravel/framework:^13`. Run `composer update`, then fix what the upgrade guide lists.
3. Run the full test suite on SQLite and on MariaDB.
4. Build and upload a new release ([DEPLOYMENT.md](DEPLOYMENT.md), section 6), first to staging.

Laravel 13 needs PHP 8.3 or newer, so step 1 comes first. The local computer also needs PHP 8.3 or newer for this
step; it has 8.2 today (XAMPP).

## Routine updates

Every one to three months, run `composer update` locally within the same major versions to pick up security
fixes. Run the tests, then release as normal. `composer audit` lists known vulnerabilities in the installed
libraries.
