# Owner quick start

For the firm principal. The staff panel is at `/admin`; clients use `/portal`, and they register or sign in from the
website.

## First day

1. **Set up your account.** Open the one-time link from `mtl:invite-admin`
   ([DEPLOYMENT.md](DEPLOYMENT.md), section 4), set your password, and set up the 2-step sign-in code (an
   authenticator app, or a code sent by email). Staff sign-ins always need it.
2. **Settings** (System → Settings). Check:
   - the firm details, office hours and contact addresses;
   - the email sender name and address (an address the firm owns);
   - the bank details for transfers. These are left empty until you add them.
   - the WhatsApp and Tawk.to chat details, if used.
3. **Invite staff** (People → Staff invitations). Give each person only the roles they need: Lawyer, Case officer,
   Finance officer, Content editor, or a second administrator. They get a one-time link and choose their own
   password. [permission-matrix.md](permission-matrix.md) shows what each role can see.
4. **Services and engagement terms** (Firm setup). Review the service list, prices and intake questions, and the
   engagement terms text, **before** clients see them. The starting texts were written during the build and are
   marked for your approval.
5. **Website pages** (Website → Pages). Each page shows its current wording.
   1. Edit it; this saves a draft.
   2. Preview the draft.
   3. Publish it.

   Every earlier version is kept.

## How work flows

1. **Enquiry.** It comes in from the website form or a registered client (Practice → Enquiries).
2. **Conflict check.** Record the search and your decision. It is never cleared automatically.
3. **Quotation, then engagement.** The client accepts in the portal. Representation starts only when you approve
   the engagement internally. Payment does not start it.
4. **Matter.**
   - The team, tasks and deadlines.
   - Documents: drafts are released to the client only when approved.
   - Chat with the client. Internal notes are never shown to clients.
5. **Billing.**
   - Invoices. Once issued, an invoice is corrected with a credit note, never by editing it.
   - Card payments through Paystack, when keys are set.
   - Bank transfers, which are marked paid only when the money is confirmed in the bank. A screenshot is not proof.
   - A client-funds ledger, kept separately and never deducted automatically.
6. **Notarisation** (on a matter).
   1. Record the client's consent.
   2. Submit the document to Naija Virtual Notary yourself.
   3. Keep NVN's reference and the status updated.

   Nothing is sent to NVN from this system.

## Weekly checks

- **Operations** (System → Operations):
  - the scheduler ran in the last few minutes;
  - last night's backup is OK;
  - no failed emails.
- **Download a backup** to keep off the server. This is done by the technical administrator; see
  [BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md).
- **Reports.** Income is shown per currency. NGN and USD are never added together.
- **Audit log.** Who did what. It cannot be edited from the panel.

## Before cutover: staging checklist

Sign each item off on staging:

- [ ] Every public page and blog post reads correctly
  ([visual-comparison/README.md](visual-comparison/README.md) lists known differences).
- [ ] Old addresses work: an old blog post link, and an old image link (`/wp-content/uploads/...` goes to `/images/...`).
- [ ] Enquiry form: you receive the notification, and the enquiry appears in the panel.
- [ ] Register as a test client, verify the email, and sign in to the portal.
- [ ] Take one test enquiry through quotation, engagement, matter, invoice and a Paystack **test** payment.
- [ ] Record a bank-transfer payment and confirm it.
- [ ] Staff sign-in with the 2-step code, for each role you plan to use.
- [ ] Run a backup and a restore drill (the technical administrator).
- [ ] The texts marked for approval (terms, notices, service descriptions) are approved or changed.
