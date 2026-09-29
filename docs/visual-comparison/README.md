# Visual comparison: live WordPress site vs the new build

Run on 29 Sep 2026 against the local build (http://127.0.0.1:8765, seeded pages + imported posts), using the same
tool and viewports that captured the live site on 28 Sep 2026 (desktop 1440×900; phone 390×844 at 2×).

    node tools/site-audit/screenshot.mjs http://127.0.0.1:8765 docs/visual-comparison/build
    php -d memory_limit=2G tools/site-audit/compare.php

- `build/` – screenshots of the new build.
- `*-side-by-side.jpg` – live site left, new build right (top of each page).
- `comparison.md` – page heights and a first-screen difference score. The score only points at pages worth
  looking at: a few pixels of vertical shift over a photo gives a high score even when the page looks the same.
  **The side-by-side images are what to judge.**

All 24 page/viewport combinations loaded (HTTP 200) and were compared by eye.

## Fixed during this check

| Issue | Fix |
|---|---|
| About: the TLK / Maestro Law logo in "Our Lead Affiliate Firm" was missing (the live site shows it on tablets and phones only) | Added as an editable "Logo" field; shown below `lg` |
| About (phone): no divider lines between points 01/02/03 | Added |
| Blog post: article column 800px instead of 710px; paragraphs closer together than the live theme | Column 710px; one blank line (1.6em) between paragraphs |
| Blog post (phone): title 26px vs about 21px; wider side margins | Matched |
| Phone header 100px tall with a dotted box around the menu button (live: ~80px, no box) | 80px on phones, no box; still shows a focus ring for keyboard users |
| Phone page banners sat ~30px lower | Top padding reduced on phones |
| Blog/tag listings (phone): card titles 26px vs about 20px | 20px on phones, 26px from tablets up |
| Terms: "and" not highlighted in the banner; ~25% taller because of wide gaps around divider lines | Highlight restored; divider spacing tightened (now ~13% taller) |
| Phone body text 16px vs the theme's 14.6px | Body and article text 0.912rem on screens ≤ 544px |

## Differences that remain (deliberate or minor)

| Page | Difference | Why |
|---|---|---|
| Contact | Much taller | The new page has the enquiry form (with conflict-check and "not yet your lawyers" notices) the build spec asks for; the live page only has a button |
| Register | Taller on phones | The new form asks for phone number and a password confirmation and states the password rule; the old form's username and "profile cover image" (1 GB upload) fields are gone |
| Terms and conditions | ~13% taller | Same text; list items and paragraphs have a little more line spacing |
| Tag archive | Heading reads "Tag: sme" (live: "sme") | Clearer for visitors; easy to change back if preferred |
| Blog post | No round author avatar next to the byline | The live site loads it from Gravatar (a third-party request per visitor); left out on purpose |
| Various (phone) | Section text set at 15px in templates is ~0.4px larger than the live theme's 14.6px | Too small to justify restyling every template; can be tuned after owner review |
| Home (phone, menu open) | Not captured for the build | The capture script opens the old theme's menu button by its WordPress class; not compared in this run; open the menu on a phone during owner review |

Owner action: look through the side-by-side images and list anything that should change before cutover.
