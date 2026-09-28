# Permission matrix

Enforced on the server by policies and query scopes; menus only reflect it. Legend:
**A** all records · **T** only matters where the user is an active team member · **O** only the user's own
(client) records · **R** read only · **—** no access.

TA = Technical Administrator · FP = Firm Principal / Managing Lawyer · LAW = Assigned/Affiliate Lawyer ·
CO = Case Officer / Support · FIN = Finance Officer · CE = Content Editor · CL = Client

| Area / action | TA | FP | LAW | CO | FIN | CE | CL |
|---|---|---|---|---|---|---|---|
| Users, roles, invitations, suspension, offboarding | A | A | — | — | — | — | — |
| Staff (legal-team) applications: review, notes, approve/decline | A | A | — | — | — | — | — |
| Assign/remove matter team members | A | A | — | — | — | — | — |
| Enquiries: view / triage / update | A | A | T¹ | T¹ | — | — | O (own submissions) |
| Conflict check: search suggestions / clear or flag | A | A | T¹ | R (T¹) | — | — | — |
| Client profiles | A | A | T² | T² | — (billing fields in Phase 5) | — | O |
| Client portal contacts: invite / remove access | A | A | — | — | — | — | — |
| Services, intake forms (publish) | A | A | — | — | — | — | — |
| Matters: view / update stage, next action, deadlines | A | A | T | T | — (billing metadata in Phase 5) | — | O (client-visible fields) |
| Open a matter (approve accepted engagement) | A | A | — | — | — | — | — |
| Internal legal assessment on a matter | A | A | T | T | — | — | — |
| Tasks, milestones, deadlines | A | A | T | T | — | — | — |
| Client chat: read / send | A | A | T | T | — | — | O |
| Internal notes | A | A | T | T | — | — | — |
| Documents: upload / view | A | A | T | T | invoices & payment evidence only | — | O (released/client-uploaded) |
| Draft deliverables: create / review / approve / release | A | A | T | T (no final approve) | — | — | O after release |
| Quotations: prepare / send | A | A | T | T | A | — | O (view) |
| Accept quotation / engagement / approve draft **as client** | — | — | — | — | — | — | O only |
| Engagement terms templates | A | A | — | — | — | — | — |
| Invoices, payments, credit notes, expenses | A | A | R (T) | R (T) | A | — | O (view, pay) |
| Verify bank-transfer payments, refunds, reversals | A | A | — | — | A | — | — |
| Client-funds ledger (recovered funds) | A | A | R (T) | — | A | — | O (statement) |
| Consultations: availability settings | A | A | own calendar | A | — | — | — |
| Consultations: bookings, meeting links, outcomes | A | A | T / own | A | — | — | O (book/reschedule/cancel) |
| Public pages, homepage sections, branding | A | A | — | — | — | draft (publish if granted) | — |
| Blog posts, media, SEO | A | A | — | — | — | draft (publish if granted) | — |
| Settings: SMTP, Paystack, bank details, Tawk, WhatsApp, digest time | A | A | — | — | bank details R | — | — |
| Reports & dashboards | A | A | own work | own work | financial | content | — |
| Exports | A | A | T | T | financial | content | O (own documents) |
| Audit history (search/view) | A | A | — | — | — | — | — |
| Audit history (edit/delete) | — | — | — | — | — | — | — |
| System health, queue, deliveries, backups | A | A | — | — | — | — | — |
| NVN handoff records | A | A | T | T | — | — | O (consent, status) |
| "View client portal" read-only preview | A (audited) | A (audited) | — | — | — | — | — |

¹ Enquiries and conflict checks are visible to non-administrators only once an administrator assigns them as the
enquiry owner or reviewer.
² A client profile is visible to assigned staff only in the context of matters they are assigned to; being assigned
to one matter never reveals the client's other matters.

## Invariants covered by feature tests

1. Client A cannot reach Client B's matters, chats, files, invoices or exports by changing IDs.
2. Removing a team member revokes matter access immediately (next request).
3. Finance and content roles cannot open legal evidence or internal notes.
4. Neither full administrator can accept, approve or sign as a client, or edit/delete audit events.
5. The last active full administrator cannot be suspended, deleted or demoted.
6. Legal-team applicants have no account and no access until an administrator approves and the invitation is accepted.
