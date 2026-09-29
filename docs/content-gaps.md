# Content gaps, suspected typos and owner decisions

Status 28 September 2026. Nothing listed here has been changed in the new site; the source wording is preserved
until the owner approves a change.

## 1. Inputs still needed

| Item | Why | Blocking? |
|---|---|---|
| WordPress export (Tools → Export → All content, `.xml`) | Drafts, private/scheduled posts, SEO plugin fields and full comment data are not public. Published posts are already captured through the REST API. | No; needed before final migration sign-off |
| `wp-content/uploads` backup (zip) | All 41 image files referenced publicly (media library + CSS backgrounds) were retrieved; files never attached to a post or page only exist in the backup | No |
| Contact details for the Contact page (email, phone, address, WhatsApp number) | The live Contact page has none | Blocks final Contact page content |
| cPanel facts: PHP versions, SSH/terminal, document-root control, cron, MySQL version, disk | Deployment layout | Blocks Phase 6 only |
| SMTP, Paystack (test + live), bank instructions (NGN/USD), Tawk property/widget IDs, admin + digest recipient emails | Integrations | No; test doubles are used until provided |

Assets: the original logo (`mtl-blue.png`), white variant (`white.png`), favicon (`blue-1.png`) and all page imagery
were retrieved and self-hosted. No placeholder logo is needed.

## 2. Suspected typos in existing copy (awaiting approval; NOT corrected)

| Page / section | Existing text | Suggested |
|---|---|---|
| Home → What We Offer → Document Drafting & Review | "**rom** contracts to legal letters, we help draft, review, and refine the documents you need." | "From contracts…" |
| About → 01 Why We're Different | "…stores your legal documents safely in one place" (no full stop) | add "." |
| Practice Areas → Government & Regulatory Compliance, Data Protection & Privacy Law, Immigration & Residency Services, Alternative Dispute Resolution (ADR) | descriptions end without a full stop (the first four areas have one) | add "." for consistency |
| About → 02 How We Can Help You | "Get matched with a qualified legal professional." (same sentence as Home step 02; looks like placeholder text) | owner to supply intended text |
| Contact → heading | "Let's" uses a straight apostrophe; elsewhere curly ’ is used | cosmetic only |

## 3. Existing layout quirks (preserve or fix? owner decision)

1. **Home, desktop:** the "How it works?" heading overlaps the "01" marker of the first step card.
2. **Contact page:** the CTA button "Contact Us" links to the Contact page itself, and the page has no form or
   contact details. The new build adds the spec's enquiry form below the preserved copy; its field labels and privacy
   notice are new wording marked for approval.
3. **Home, section 2** uses a fixed "parallax" background image. Full-page screenshots show it as blank; in a normal
   browser it displays. This will be reproduced (with a static fallback on mobile, as browsers there ignore
   `background-attachment: fixed` anyway).

## 4. Claims in current copy that the new system should match

| Copy | Consideration |
|---|---|
| "Our secure client portal … updates you in real-time" (About) | The portal uses short-interval polling on shared hosting; messages appear within seconds while open, and email alerts can take several minutes. Owner may keep the wording or soften it. |
| "Get matched with a qualified legal professional" | Matching is an administrator assignment, not an automatic marketplace. Consistent with the spec. |
| "Flat Fees. Transparent Pricing" | Quotations are per-matter; the firm decides whether services carry fixed published prices. |
| "Request a Free Consultation" | Initial consultation stays free; paid follow-ups only if configured and clearly labelled. |

## 5. Content decisions needed

- `/hello-world/` (WordPress sample post with a sample comment): now imported as an unpublished draft, so its URL
  returns 404. A 410 redirect entry can be added in Redirects if preferred.
- Four genuine reader comments (posts 838, 857, 870): import as an approved read-only archive shown under each post,
  or keep them archived but hidden? Commenting on new posts: enable with moderation, or turn off?
- New wording needed for: privacy notice, enquiry-form help text, legal-team application page, portal onboarding
  (including the notice that daily conversation recaps are sent by email). Drafts will be supplied for approval.
- Existing `/terms-and-conditions/` describes the old portal; review against the new portal features before launch.

## 6. Found while building Phase 2 (awaiting owner)

| Item | Current state | Blocking? |
|---|---|---|
| **Privacy policy** | A draft exists in the page editor but is **not published**; `/privacy-policy/` returns 404 until the owner approves and publishes it. Forms link to it. | **Yes, blocks launch** |
| Careers page (`/join-our-legal-team/`) wording and application form labels | New wording, drafted for approval | Before launch |
| Image alt text | Imported as-is from WordPress. Some values are empty or meaningless (e.g. "blue", a random string, "woocommerce placeholder"). Not invented or changed; they can be corrected in the Media library. | No (accessibility) |
| Staff sign-in address | Staff now sign in at `/admin/login`; the public `/log-in/` is for clients only and refuses staff accounts | No; staff need telling |
| "Login/Register" menu label | Kept as on the live site; points to `/register/` (which links to sign-in). Signed-in users see "My Account" or "Staff Admin" instead | No |
| Comments | Imported comments display under their posts; **new** public comments are switched off by default (Settings → Publishing), with moderation when on | Owner decision |
| Author avatars | Not shown; the live site's Gravatar images are not loaded (no third-party request) | No |

## 7. New wording drafted in Phase 3 (approved by the owner, 28 Sep 2026)

The owner approved all of the wording below on 28 Sep 2026, except the engagement-terms outline, which is not real terms.

| Where | Wording |
|---|---|
| `/legal-assistance/` | Page intro, the service-choice step, field help text, and the notice "Sending this form does not make Maestro Touch Legal your lawyers…" plus the consent checkbox |
| `/legal-assistance/thank-you/` | Acknowledgement text and the "what happens next" steps |
| Contact page | "Send Us a Message" heading and form labels (below the preserved live copy) |
| Acknowledgement email to enquirers | Subject and body |
| Portal | "Needs Your Attention", "Your Matters", "Send Documents", upload help text, draft approval prompts, quotation acceptance statement, engagement signing text ("Type your full name to sign", "I have read these terms and agree to them") |
| Service catalogue | The eight practice-area services (names taken from the live Practice Areas page) and their intake questions, seeded as **published** version 1 |
| Engagement terms | "Standard engagement terms (draft for owner approval)" is a structural outline only and **inactive**. The firm must supply the real terms. |

## 8. New wording drafted in Phase 4 (awaiting owner approval)

Nothing below comes from the live site. Change any of it in the code before launch, or approve it as it is.

| Where | Wording |
|---|---|
| New-message email (client) | Subject "You have a new message from Maestro Touch Legal", body "There is a new message for you in your client portal." The message text itself is not included |
| New-message email (staff) | Subject "New client message on {matter reference}", body "The client has sent a message in the matter conversation." |
| End-of-day recap email (clients) | Subject "Your conversation with Maestro Touch Legal: {date}", new messages per matter, attachment counts, and a portal link |
| End-of-day recap email (firm) | Subject "Client conversations: daily recap for {date}", client messages per matter, for the listed full administrators only |
| Consultation emails | "Consultation request received", "Consultation confirmed", "Consultation moved", "Consultation cancelled" and "Reminder: consultation on …", including "Your request does not yet mean the firm has agreed to act for you." |
| Portal | "Messages" and "Appointments" pages, message box help text, the booking form ("Request a Consultation", "Past and Cancelled"), and the reschedule/cancel cut-off text |
| Consultation type | "Initial consultation", free, 30 minutes (edit in Admin → Consultation types) |

## 9. New wording drafted in Phase 5 (awaiting owner approval)

Nothing below comes from the live site. Change any of it before launch, or approve it as it is.

| Where | Wording |
|---|---|
| New-invoice email (client) | Subject "A new invoice from Maestro Touch Legal", body "An invoice has been issued to you. You can view it and see the payment options in your portal." |
| Payment email (client) | Subject "Payment received – thank you", body "We have received your payment of {amount}. Your receipt is in your portal." |
| Transfer not verified (client) | Subject "We could not verify your bank transfer", body "We could not match your reported bank transfer to our account. Please see the reason in your portal or contact us." |
| Client-funds email (client) | Subject "Your client-funds statement has been updated", body "A new entry has been recorded on the funds we hold for you. You can see your statement in your portal." |
| Portal → Invoices | "Amounts are shown in the currency of each invoice. Naira and dollar invoices are separate and are not added together." |
| Portal invoice page | "Pay this invoice"; card note "You will be charged in {currency}. Some cards issued outside Nigeria may be declined by the card issuer; if that happens, please use bank transfer."; transfer note "Please use {invoice reference} as the payment reference. Pay in {currency} only; we cannot convert between currencies."; "Already paid? Tell us about your transfer."; "Print or save as PDF" |
| Portal → Funds | Per-currency statement of funds held for the client |
| Staff alerts (finance) | "Bank transfer to verify", "Online payment needs review", "Refund needs review", "Refund failed", "Payment dispute" (the dispute alert says to respond in the Paystack dashboard and to reverse the payment only if the dispute is lost) |
