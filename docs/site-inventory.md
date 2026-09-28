# Site inventory — mtouchlegal.com

Captured 28 September 2026 from the live WordPress site (WordPress 7.1.2, Astra theme, Elementor 4.3.2,
Header Footer Elementor, WooCommerce 11.1.2, WP Customer Area, WP 2FA, a Zoom meetings plugin, Tawk.to).

Sources: every URL in `/sitemap_index.xml` plus the public REST API (`/wp-json/wp/v2/*`). Raw HTML, REST JSON and
theme/Elementor CSS are kept in `docs/source-capture/`. Exact copy is in `docs/content-manifest.json`; images in
`docs/asset-manifest.json`; screenshots in `docs/reference-screenshots/`. Re-run with:

```
php tools/site-audit/extract.php        # copy + asset list + CSS
php tools/site-audit/fetch-assets.php   # self-host images into public/wp-content/uploads
node tools/site-audit/screenshot.mjs    # desktop 1440px + mobile 390px captures
```

Canonical host: `https://mtouchlegal.com/` (no `www`), trailing slash on every path. Keep both.

## Site-wide

| Element | Detail |
|---|---|
| Header | Logo `mtl-blue.png` (450×130, alt "mtl blue") → `/`; menu **Home, About, Offerings, Contact**; outlined button **Login/Register** → `/register/`. Mobile: hamburger, same items. |
| Footer | Single line: `© 2026 Maestro Touch Legal` (year is dynamic in WordPress). |
| Favicon | `blue-1.png` (192×192) and `blue-1-150x150.png` (32×32 declared); same file as apple-touch-icon. |
| Font | Poppins is the only rendered family (weights 400/500/600). Inter and Libre Franklin are requested by a plugin but no element uses them. |
| Colours | Brand blue `#007BF8`, heading ink `#0F172A`, body text `#364151`, pale section background `#E7F6FF`, white. |
| Buttons | Solid blue, white 16px/600 text, 6px radius, padding 17px 26px, trailing circle-arrow icon. Text-link variant in blue with arrow. |
| Third-party | Tawk.to live chat on all pages. Google site verification meta tag `M5H3PDZo-JzmJ6zNppKGN_AfoGk3BKzry32-oCGr0ac` (carry over). |
| SEO | Title pattern `{Page} - Maestro Touch Legal`; meta description = first page text; Open Graph + Twitter card; `index, follow`. |

## Public pages to mirror

| URL | Title | Sections (in order) | Forms / links | Screenshots |
|---|---|---|---|---|
| `/` | Home - Maestro Touch Legal | 1 Hero: eyebrow "Legal Solutions. Anywhere. Anytime.", H1, sub-line, **Sign In** → `/log-in/`. 2 Fixed-attachment background band (`4-scaled.jpg`, 500/400/300px desktop/tablet/mobile). 3 Pale-blue: image + "Modern Law for the Modern World" + Learn more → `/about/`; "How it works?" 01–03 cards. 4 "What We Offer?" four icon boxes + image `offer-1.png`. 5 Pale-blue "Why Choose Us?" logo `blue-2.png` + five check items. 6 CTA "Request a Free Consultation" → `/contact/`. | Sign In, Learn more, Contact Us | home-desktop / home-mobile |
| `/about/` | About - Maestro Touch Legal | 1 Title band "About Us". 2 Who We Are (text + image), Our Lead Affiliate Firm (TLK, image `tlk.jpg`), 01 Why We're Different, 02 How We Can Help You, 03 Get Legal Support Online. 3 Our Vision / Our Mission. 4 CTA. | Contact Us | about-* |
| `/offering/` | Practice areas - Maestro Touch Legal | 1 Title band "Practice Areas". 2–3 Eight image boxes (4 + 4) with icon images. 4 "Virtual Notarization" + **Proceed to Notarize** → `https://naijavirtualnotary.mtouchlegal.com/`. 5 CTA. | external NVN link | offering-* |
| `/contact/` | Contact - Maestro Touch Legal | 1 Title band "Contact Us". 2 "Let's be Your Trusted Legal Partner in Innovation". 3 CTA "Request a Free Consultation" whose button links back to `/contact/`. **No form, email, phone or address** (see content-gaps). | none | contact-* |
| `/blog/` | Blog - Maestro Touch Legal | Astra archive: cards with title, date, excerpt, "Read More »". | — | blog-* |
| `/terms-and-conditions/` | Terms and conditions - Maestro Touch Legal | Portal Terms & Conditions (numbered sections). Linked from the register form. | — | terms-and-conditions-* |
| `/log-in/`, `/register/`, `/password-reset/` | Log In / Register / Password Reset | Plugin forms. Replaced by Laravel auth at the same paths. | — | log-in-*, register-*, password-reset-* |

## Blog

Permalinks are `/%postname%/` at the site root, so Laravel must resolve root-level slugs after all fixed routes.

| ID | Date (site time) | Slug | Tags | Featured | Comments |
|---|---|---|---|---|---|
| 878 | 2026-02-05 | hiring-your-first-staff-the-employment-contract-mistakes-that-cost-millions | 13 | 879 | 0 |
| 870 | 2026-01-26 | stop-donating-your-profit-why-your-copy-paste-tcs-are-a-legal-suicide-mission | 12 | 871 | 1 |
| 857 | 2025-12-11 | is-your-side-hustle-legally-a-business-the-danger-of-unregistered-vendor-accounts | 8 | 858 | 1 |
| 852 | 2025-11-29 | i-got-it-from-google-why-that-free-contract-template-could-cost-your-nigerian-business-everything | 8 | 853 | 0 |
| 838 | 2025-11-15 | the-rise-of-the-virtual-law-firm-why-your-nigerian-sme-needs-a-lawyer-not-a-lawsuit | 7 | 840 | 2 |
| 1 | 2025-08-09 | hello-world | 0 | — | 1 (WordPress sample) |

- Taxonomies: 1 category (`/category/uncategorized/`), 30 tags (`/tag/{slug}/`, all captured).
- Authors: WordPress user IDs 1 ("Admin") and 5. Imported as bylines only, never as login accounts.
- Comments: 5 public (4 genuine reader comments + the WordPress sample). Drafts/private/scheduled posts are not
  visible publicly; they need the WXR export.

## Pages not mirrored (redirect plan)

| URL | What it is | Proposed handling |
|---|---|---|
| `/my-account/`, `/account/`, `/profile/`, `/customer-area/`, `/customer-area/dashboard/`, `/customer-area/my-account/*` | WooCommerce / WP Customer Area / profile plugin | 301 → `/portal` (sends to login if signed out) |
| `/customer-area/files/*`, `/customer-area/pages/*` | Customer Area files/pages | 301 → `/portal/documents` |
| `/customer-area/payments/*`, `/payments-invoice/` | Customer Area payments | 301 → `/portal/billing` |
| `/shop/`, `/cart/`, `/checkout/` | WooCommerce (no products observed) | 301 → `/offering/` |
| `/wp-2fa-config/` | WP 2FA settings page | 410 Gone |
| `/zoom-meetings/`, `/zoom-meetings/test/` | Zoom plugin test entry | 410 Gone |
| `/hello-world/` | WordPress sample post | Owner decision: import as draft (not public) and 410, or keep |
| `/?elementor_library=*`, `/wp-admin/*`, `/wp-login.php`, `/feed/`, `/wp-json/*` | WordPress internals | `/feed/` → new RSS feed; `wp-login.php` → `/log-in/`; others 410 |
| `/wp-content/uploads/*` | Media URLs | Serve the same files from `public/wp-content/uploads` so old image links keep working |
| `/customer-area/my-account/edit-account/` | Already 404 on live site | 410 |

## Subdomains and external services to protect during cutover

- `naijavirtualnotary.mtouchlegal.com` (Naija Virtual Notary): separate document root; must not be touched.
- Business email (MX records), SSL certificates, DNS: unaffected by an application swap; verify after cutover.
- Unknown until cPanel access: other addon domains/subdomains, existing `.htaccess` rules, existing cron jobs.
