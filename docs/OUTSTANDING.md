# Outstanding items

For the owner. These are the things the build cannot finish by itself. The code for all six phases is done and
tested locally ([TEST-REPORT.md](TEST-REPORT.md)). Nothing has been deployed, and the live WordPress site is
untouched.

## A. Needed before staging

| # | Item | Who | Where it goes |
|---|---|---|---|
| A1 | cPanel access (or someone who will follow [DEPLOYMENT.md](DEPLOYMENT.md)), with Terminal enabled | Owner | — |
| A2 | Confirm on the account: MariaDB/MySQL version, whether the main domain's document root can be changed, disk space, PHP extensions | Owner / host | DEPLOYMENT.md sections 1 and 3 |
| A3 | A staging username and password for testers | Owner | `.env` on staging (`STAGING_USER`, and the hash from `mtl:staging-password`) |
| A4 | Paystack **test** keys on staging, and the webhook URL `https://staging.mtouchlegal.com/webhooks/paystack` in the Paystack test dashboard | Owner | staging `.env`, Paystack dashboard |

## B. Needed before cutover

| # | Item | Who | Where it goes |
|---|---|---|---|
| B1 | **WordPress export** (WordPress admin → Tools → Export → All content, `.xml`). Drafts, private posts and SEO fields are only in the export | Owner | `mtl:import-wordpress --wxr=…`, then `mtl:verify-import` |
| B2 | Full cPanel backup and a phpMyAdmin export of the WordPress database, kept **off the server** | Owner | Owner's own storage. Never deleted |
| B3 | An SMTP mailbox for the site's emails (address and password) | Owner | production `.env` (`MAIL_*`) |
| B4 | `BACKUP_PASSWORD`: long and random, stored in the firm's password manager and a sealed paper copy | Owner | production `.env` |
| B5 | NGN and USD bank account details | System administrator | Admin → Settings → Bank transfer |
| B6 | Staff and recap email recipients; Tawk.to IDs, if used | Owner | Admin → Settings |
| B7 | **Privacy policy** approved (a draft is in Website → Pages) | Owner | Blocks launch |
| B8 | **The firm's real engagement terms** (the seeded template is only an inactive outline) | Owner | Admin → Firm setup → Engagement terms |
| B9 | Contact page details (email, phone, address, WhatsApp): the live page has none | Owner | Website → Pages → Contact |
| B10 | Approve or change the new wording ([content-gaps.md](content-gaps.md), sections 8 and 9) and the typo and layout decisions (sections 2–6) | Owner | — |
| B11 | Review the visual differences ([visual-comparison/README.md](visual-comparison/README.md)) | Owner | — |
| B12 | `hello-world` sample post: keep hidden, or mark it gone (410) in Redirects. Also: show the four reader comments or keep them hidden, and allow new comments or not | Owner | Admin → Redirects / Comments |
| B13 | Staging checklist signed off ([OWNER-QUICKSTART.md](OWNER-QUICKSTART.md)), including a Paystack test payment, a webhook, and a backup **restore drill** | Owner and technical administrator | — |
| B14 | **Cutover approval** ([DEPLOYMENT.md](DEPLOYMENT.md), section 7) | Owner | — |

## C. Needed before taking payments online

| # | Item |
|---|---|
| C1 | Paystack **live** keys, and the live webhook URL `https://mtouchlegal.com/webhooks/paystack` |
| C2 | The currencies the firm's Paystack account accepts (`PAYSTACK_CURRENCIES`; now only `NGN`). Foreign cards are not guaranteed to work |

## D. Later

| # | Item | When |
|---|---|---|
| D1 | Move to PHP 8.3 or 8.4 ([UPGRADE-PATH.md](UPGRADE-PATH.md)) | Before 31 December 2026 |
| D2 | Laravel 13 | During 2027 |
| D3 | Naija Virtual Notary integration. Today the handoff is manual: consent, NVN's reference and the status are recorded, and nothing is sent to NVN. The code has a clear place to connect NVN later, if NVN offers an official, authorised way to do it (DECISIONS D40) | If NVN offers one |
| D4 | Delete the WordPress files and database | Only when the owner decides, well after cutover. Never automatic |
