# Antaaya Villas CRM — Development Changelog

> Internal build log kept during development (v2 → final). Newest sections are at the bottom. Kept here as a record of how the system evolved; see the main [README](../README.md) for an overview and setup.

## What's new in this version

- **3 website forms now feed the CRM directly**: SiteVisit-Record.html (Site Visit / Broker / Enquiry
  tabs) POSTs to both its original Google Apps Script (Sheets keep working exactly as before) AND a new
  `api/public_intake.php` endpoint that creates/updates CRM leads in real time.
- **Auto-routing by desk**: `users.desk` = 'broker' (Broker desk) or 'owner' (Owner desk). New leads are
  auto-assigned based on category:
  - Enquiry form: `clientType = Broker` → the Broker desk. `Investor` / `Other` → the Owner desk.
  - Broker form (with a client attached): always → the Broker desk.
  - Site Visit form: **always** → the Owner desk, regardless of who fills the form or what
    "Assigned Executive" says on the form itself (that value is just logged as a note).
- **Matching, not duplicating**: a form submission is matched to an existing client by mobile number.
  If matched, it appends a note/visit to that client rather than creating a duplicate — and does
  **not** change who the lead is already assigned to (only NEW leads get the auto-assignment).
  An admin can always manually reassign inside the CRM.
- **Dashboard**: "Outstanding Amount" card replaced with "Cancelled Leads" count.
- **Leads table**: new Category and Added By columns — admin can see whether a lead came from a
  website form (and which one) or was added directly in the CRM, and by whom.
- **New stage option**: "Cancelled" is now a selectable stage.

## Setup (fresh install)

Same as before — see the original steps: fill `config/db.php`, import `schema.sql`, edit and run
`seed.php`, delete `seed.php`.

## Setup (upgrading your existing live database)

Your database already has data (test leads, your admin login) — don't re-import `schema.sql`, it would
wipe everything. Instead:

1. Open phpMyAdmin → your database → **SQL tab** (not Import).
2. Paste in the contents of `migration_v3.sql` and run it. This only ADDS columns — nothing is deleted.
3. Edit `seed.php` — it now also sets `desk`. Update the desk users' emails/passwords, then
   run it once in the browser. It's safe to re-run even for your existing admin login — it'll just
   update the desk value, not recreate the account.
4. Delete `seed.php` again.

## Connecting the website forms

`SiteVisit-Record.html` now has a `CRM_INTAKE_URL` constant near the top of its `<script>` block:

```js
const CRM_INTAKE_URL = "/crm/api/public_intake.php";
```

This assumes the CRM is deployed at `antaayavillas.com/crm/` (same domain, per the agreed plan) — no
CORS setup needed. If the CRM ends up at a different path, update this one line to match.

## How the intake endpoint decides things

See `api/public_intake.php` — it's commented throughout. Key design choice: **assignment only happens
once, at lead creation.** A later form submission that matches an existing client updates that client's
notes (and, for site visits, adds a `visits` row) but never silently reassigns the lead away from
whoever already owns it. This is intentional — it stops a Site Visit form submission from ever
"stealing" a lead that was already a Broker desk client.

## Everything from the original README still applies

Folder structure, access control model, and the earlier bug fixes (escaped output, fixed dashboard
label, added Snagging field, real auto-increment lead IDs) are unchanged — see git history / earlier
version of this file if you need that context again.

## v6 — Source Leads (website & social intake, staged for review)

New table `source_leads` (run `migration_v6.sql`) holds every raw website/social submission
completely separately from `clients`. Nothing in `source_leads` ever affects the main CRM
(counts, dashboards, desk pipelines) until a human confirms it.

**Flow:**
1. A form (any of the 3 Antaaya forms, or the Akruti Developer site form) POSTs JSON to the new
   public endpoint `api/source_intake.php` with `{ site: "antaaya"|"akruti", form_type: "...", ...fields }`.
2. `source_intake.php` maps whatever field names it recognises (name/mobile/email/city/source/
   budget/config/purpose/etc. — see the `$ALIASES` list at the top of the file) onto `source_leads`
   columns. Any field it *doesn't* have a column for (e.g. a PAN card tab) is simply skipped as a
   structured field — nothing crashes, nothing is forced into the wrong column. The full raw payload
   is still kept as JSON in `raw_data` for audit purposes only.
3. The new **Source Leads** tab (sidebar, Entry Desk + Admin only — hidden for everyone else) lists
   every submission with status `New`. The reviewer cross-checks it, then either:
   - **Assign** — calls `api/source_leads.php` (`action: assign`). This is the ONLY moment a
     `clients` row gets created: real Lead ID, normal desk routing (Broker → Broker desk, Owner → Owner desk;
     admin can hand-pick instead), and it enters the exact same pipeline as any other lead from here on.
   - **Delete** — marks it `Rejected`. `clients` is never touched.
4. Once Assigned/Rejected, the row drops out of the default "New" filter (switch the dropdown to
   "Assigned"/"Rejected"/"All" to see history).

**Wiring up the forms:** point each form's submit handler at `api/source_intake.php` (same pattern as
`CRM_INTAKE_URL` above, just a different endpoint and payload — add `site` and `form_type` to what's
already being sent). Akruti Developer's site is a different domain, so CORS is already enabled on this
endpoint (`Access-Control-Allow-Origin: *`), same as `public_intake.php`. Send over the actual Akruti
form's field names when ready and I'll add its exact aliases to `$ALIASES` rather than guessing them.
Social media (Meta/Google lead ads etc.) will plug into the same endpoint later — same table, same
review tab, just a different `site` value.

## Post Sales split — Legal desk → Accounts desk (migration_v35.sql)

**Run `migration_v35.sql` in phpMyAdmin first** (adds `clients.accounts_handover_at` / `accounts_handover_by`).

- **Legal desk** works only the Booking & Legal tab (Booking Initiated → Registered). At **Registered**
  (Registration Date + mandatory documents done) it clicks **Transfer to Accounts**. The stage stays
  Registered; the lead then becomes read-only for Legal, whose Construction & Payment / Possession tabs
  show the lead's status progress only.
- **Accounts desk** sees only leads Legal has transferred (or already in Construction onward). It never
  sees the Booking & Legal tab — a newly transferred lead reads "New from Legal", and its first step is
  **Construction Customer**, then Payment → Possession → Handover Completed (incl. Allotment Letter upload).
- **Admin** sees and edits everything, can transfer to Accounts too, and moving a lead back before
  Registered ("Go to stage") undoes the transfer.
- Notifications: sales → Post Sales transfers go to the Legal desk's bell, Legal → Accounts transfers
  (`accounts_transfer`) to the Accounts desk's bell; both are in the admin Notification Log.

## Accounts desk — construction-linked payment demands (migration_v37.sql)

**Run `migration_v37.sql` in phpMyAdmin first**, then fill in the company / bank details at the bottom of
`config/site.php` (printed on demand letters, the email and the WhatsApp message).

One simple screen in **Construction & Payment**:
- **Villa price + 100% bar** — Received · Due now (red when overdue) · Pending (later stages). The schedule is made
  automatically from the Final Villa Price (10 / 20 / 15 / 15 / 10 / 10 / 10 / 5 / 5 %); the 10% booking Legal
  collected is already counted as received. Villa price only — no GST / TDS (GST is collected manually).
- **Construction Status** — pick the new status (Agreement Executed → Plinth → … → OC / CC Received), the date it was
  completed and the pay-by date, then **Update & Raise Demand**. That one click moves the lead to that stage AND raises
  its payment demand. Anything still unpaid is **carried forward** into it automatically. Picking a later status covers
  the stages in between. **Undo last update** reverses it (one stage at a time).
- **Payment Demand** card — the amount to pay now, with **Letter** (print / PDF), **Email** and **WhatsApp**. After the
  pay-by date (or once sent) these switch to reminders.
- **Receive Payment** — amount, date, mode, UTR / cheque no., optional proof; printable receipt. Money is applied to the
  oldest balance first.
- **Payment Schedule** table (Paid / Due / Not due yet) + Payments Received list, then Other Details (home loan,
  fit-out) and Documents (architect certificate, site photos, signed demand letter).
- **Handover Completed** stays locked until the villa price is fully received.
- Files: `api/payment_lib.php`, `api/payments.php` (new); `api/clients.php`, `api/sv_followup_lib.php`,
  `api/legal_docs_lib.php`, `api/client_documents.php`, `config/site.php`, `index.php`, `assets/js/app.js`.

## Demand letter as an A4 PDF — email & WhatsApp (no migration)

- **Email / Email Reminder** — the demand note (or payment reminder) now goes as an **A4 PDF attachment**
  (`Demand-Note-L522-04.pdf` / `Payment-Reminder-L522-04.pdf`) with a short covering message. The stage's
  architect / OC certificate still goes along if uploaded.
- **WhatsApp / WhatsApp Reminder** — WhatsApp chat links can't carry a file, so the message now ends with a private
  link to the same A4 PDF (`letter.php?t=<32-character code>`, opens without login). The PDFs are kept in
  `uploads/demand_letters/<lead id>/` (closed to the web; only reachable through that link).
- Email and WhatsApp each switch to "Reminder" once sent on **that** channel (or after the pay-by date).
- Letter / receipt print pages got a **Download PDF (A4)** button.
- PDFs are made by **Dompdf**, bundled in `lib/dompdf/` (upload the whole folder — no Composer needed).
  `uploads/` must be writable (it already is for document uploads).
- If the auto-detected link address is ever wrong (e.g. behind a proxy), set `CRM_PUBLIC_URL` in `config/site.php`.
- Files: `api/payments.php`, `assets/js/app.js`, `index.php`, `config/site.php`, `letter.php` (new), `lib/dompdf/` (new).

## Construction & Payment documents — kept stage by stage (no migration)

- Documents (Architect's certificate, Site progress photos, Signed demand letter, Other) now belong to one construction
  stage each. A stage strip on top — Agreement Executed · Plinth · First Slab … — opens the **current stage** by default;
  **earlier stages stay open** to view, download or add to anytime (count badge = files in that stage). Stages not
  reached yet are hidden, and the server refuses uploads to them.
- The Possession Due block also only shows its own stage's files now (the signed demand letter used to show every stage's copy).
- Files uploaded before this change keep the stage they were uploaded in.
- Files: `assets/js/app.js`, `index.php`, `api/client_documents.php`, `api/legal_docs_lib.php`.

## Demand amount follows payments received (no migration)

- Payments logged after a demand was raised now come off the amount asked for in the letter / PDF, the email and
  the WhatsApp message: the letter adds a "Less: received after this demand" row and "Amount payable" = live balance
  (same figure as the Payment Demand card's "Due now"). Fully paid → "Nil — paid in full".
- Files: `api/payments.php`, `assets/js/app.js`.

## Payments page — Construction & Payment pattern, lead search, Villa filter (no migration)

- Table follows the Edit Client → Construction & Payment pattern: Villa Price · Paid bar · Received (%) · Due Now
  (amount + Due / Overdue Nd / Paid pill, %) · Pay By · Pending (later stages) · Construction Status (Agreement
  Executed / Plinth Completed … + date; "Not started" for leads just in from Legal).
- Search finds the lead by name, co-applicant, mobile / WhatsApp (digits anywhere), Lead ID, email or villa.
- New **Villa** filter beside Payment Status: every villa with a lead in the payment process, grouped by villa name —
  pick "ALARA 1 — all units" or a single unit (e.g. ALARA 1 - A1-01). "Nothing due now" added to Payment Status.
- Files: `index.php`, `assets/js/app.js`.

## Accounts dashboard — Payment Due full width (no migration)

- Removed the "Construction Pending — Newly Transferred" panel (the Construction Pending card stays).
- "Payment Due — Oldest First" is now full width with: Pay By (Overdue Nd / Due today / Due in Nd), Lead ID, Client +
  mobile, Villa + price, Construction Status + date, Paid bar, Due Amount (% of villa price), and an Action button that
  opens Construction & Payment. Shows the 10 oldest; "View all in Payments →" when there are more.
- Files: `index.php`, `assets/js/app.js`.

## Accounts desk — payments only; Possession handled by admin (no migration)

- **Possession is admin-only now.** Accounts desk: no Possession page in the menu; in Edit Client the Possession tab is a
  read-only status (Possession Status, Expected Possession, Possession Date, Handover Date, Keys, Final Payment) — same
  pattern as the Sales / Legal read-only tabs. Server enforces it (`api/clients.php`): Accounts can't move a lead into,
  out of or within Possession Due → Handover Completed, and possession fields are dropped from its saves.
  Accounts still sees Possession-stage leads for payments; raising "OC / CC Received" still moves a lead to Possession Due
  (its OC certificate / signed demand letter are uploaded from Construction & Payment → Documents → OC / CC Received).
- **Accounts dashboard** — payments only, same figures as the Payments page: Leads with Accounts (+ new from Legal),
  Received, Due Now, Overdue, Pending (later stages). Payment Due (full width) + Recent leads (Lead ID, Client + mobile,
  Villa, Construction Status, Received, Due Now, Paid).
- **Paid % bar at the end** of the Accounts tables (Dashboard, Payments, Manage Leads).
- **Payments mismatches fixed**: Villa filter lists real villas ("ALARA 1 - A1-01", serial order like Villa Inventory —
  villa names are unique, so no "all units" grouping); % shown to 2 decimals everywhere (bar 37.06% = tiles); card
  amounts ₹33.75 L / ₹1.575 Cr instead of rounded ₹33.8 L / ₹1.57 Cr; "Not yet demanded" card renamed "Pending (later
  stages)" to match the table and Edit Client; Possession-stage leads show "OC / CC Received · with Admin".
- Files: `index.php`, `assets/js/app.js`, `api/clients.php`, `api/client_documents.php`, `api/legal_docs_lib.php`,
  `api/sv_followup_lib.php`.

## Construction & Payment — two inner tabs (no migration)

- **Demand & Payment** (opens first): villa price + 100% bar, Received / Due now / Pending, Receive Payment,
  Construction Status (Update & Raise Demand), Payment Demand (Letter / Email / WhatsApp), Other Details, Documents.
- **Schedule & Payments**: Payment Schedule table + Payments Received log (receipts, proofs, delete). The tab shows a
  count badge of payments received.
- Opening another lead always starts on Demand & Payment. Files: `index.php`, `assets/js/app.js`.

## Legal desk — booking & legal only, no Accounts details (no migration)

- **Lead Summary (Legal):** the KYC card stops at Registered On (no Construction / Amount Received / Payment Due /
  Balance / Handover); "Net Booking (after token)" sits beside Booking Amount; Post Sales History ends at the
  "Legal completed — lead transferred to Accounts" line; Current Stage reads "Registered · Legal completed" and
  Next Up "Handled by the Accounts team".
- **Legal dashboard:** "Registered — Ready for Accounts" panel removed; "Booking Pending — Newly Transferred" is full
  width with Lead ID, Client + mobile, Villa + offered price, Final Villa Price, Token Amount, Unit Blocked On, Sales
  Person, Transferred (+ "Nd waiting" pill) and Action (10 shown, "View all" link). "With Accounts" card is now
  "Transferred to Accounts" (opens those leads).
- **Rest of the Legal desk:** Accounts stage names never show — a transferred lead reads "Registered → Accounts".
  Manage Leads: Legal stages + "Transferred to Accounts" in the stage filter; Lead Type / Added By filters removed.
  Edit Client: read-only Construction & Payment / Possession tabs removed (lead opens read-only on Booking & Legal);
  progress bar Sales → Booking & Legal → Accounts. Bookings: view (eye) button once a lead is with Accounts.
- Files: `index.php`, `assets/js/app.js`.

## Bookings page — search + Villa + desk filter (no migration)

- Search by Lead ID or client name; **Villa** filter (every villa with a booking, serial order); second filter by desk:
  Admin "Booking Progress" (pending → in process → confirmed → agreement → registered → with Accounts → possession →
  cancelled), Legal "Stage" (its own stages + Transferred to Accounts), Sales "Booking Status".
- Files: `index.php`, `assets/js/app.js`.

## Call button — one tap opens the phone dialer (no migration, new file `api/call_log.php`)

- Phone button (`tel:` link — on a phone it opens the dialer with the lead's mobile; numbers without a country
  code get +91). Shown in: Dashboard (Recent Leads → new Action column, Today's / Upcoming Follow-ups), Manage Leads,
  Follow-ups page, Bookings, Lead Summary footer ("Call"), Edit Client header (beside Mobile), Edit Client → Contact
  Attempted (beside Attempt Result), and beside **Mode** in every follow-up stage (Site Visit / Post Site Visit /
  Re-Visit / Negotiation Visit / Villa Blocked Follow-up, Contacted) — shown only while Mode = Call.
- Legal desk: Dashboard (Booking Pending, Recent Legal Desk Leads), Manage Leads, Bookings. Accounts desk: Dashboard
  (Payment Due, Recent Accounts Desk Leads), Manage Leads, Payments. Admin also on Possession.
- **Edit Client → Construction & Payment → Payment Demand card:** "Call" beside Letter / Email / WhatsApp while an amount
  is due — logged as "Called … · Payment Demand #N".
- **Source Leads** (Entry Desk / Admin): call button on every row with a mobile number. Before it's assigned there's
  no lead yet, so the dialer just opens; once assigned, the call is logged in that lead's history ("· Source Leads").
- **Lead History:** every call is logged straight to the lead by `api/call_log.php` — "Called 9876543210 · Site Visit
  Follow-up" by who / when (same pattern as document uploads, kept even if Edit Client isn't saved). Calls also show in
  the Contact Attempted Attempt Log. Anyone who can see the lead can call it (same rule as the lead list).
- Files: `index.php`, `assets/js/app.js`, `api/call_log.php` (new).

## Payment Demand — Demand Log (no migration)

- The Payment Demand card (Edit Client → Construction & Payment) now keeps a **Demand Log** instead of the single
  "✓ WhatsApp 01 Oct, 13:30" line, which was overwritten on every resend. Every Letter / Reminder print, Email,
  WhatsApp and Call for that demand gets its own line, newest first, with who and when. Shown until the demand is paid.
- Letter / Reminder clicks are now logged too (new `letter_opened` action in `api/payments.php` → Lead History
  "Demand note #6 opened to print / PDF"). Email / WhatsApp already wrote Lead History entries. Calls come from the
  card's Call button ("· Payment Demand #6") or the payment lists ("· Payment due", counted from the day the demand
  was raised).
- Built from the lead's history, so nothing new to store. Call entries now use IST like the payment entries.
- Files: `assets/js/app.js`, `api/payments.php`, `api/call_log.php`, `index.php`.

## WhatsApp on PC — WhatsApp app if installed, else WhatsApp Web, picked automatically (no migration)

- **Why new tabs kept opening:** WhatsApp Web cuts the link to whichever site opened it (its own security setting), so
  no website can send a later chat into that same tab — every click landed in a new tab and WhatsApp asked "Use here".
- **Now on PC (no messages, no choices):** the first WhatsApp click tries the **WhatsApp desktop app**
  (`whatsapp://send?phone=…`). If the browser hands over to the app, the PC is remembered as *app* and every later click
  opens the chat in the same app window. If nothing opens within ~2 s, **WhatsApp Web** opens straight away (inside the
  browser's ~5 s pop-up allowance after a click) and the PC is remembered as *web* — later clicks go straight to Web.
- Self-correcting: an *app* PC whose app stops opening falls back to Web by itself (4 s); *web* is re-checked after
  7 days in case the app has been installed. Stored per browser (`crm_wa_mode`).
- App: the chat opens with the number only and the message is copied — press **Ctrl+V**, then Enter (through that link
  the Windows app turns emojis into "?"). Web / phones: the message is filled in. A note appears only in the app case
  (paste hint) or if the browser blocks pop-ups for the CRM.
- Payment demand WhatsApp: if making the letter PDF takes longer than the browser allows for opening an app after a
  click, an **Open WhatsApp** button appears instead.
- Files: `assets/js/app.js`, `index.php` (note styling).

## Bug fixes — Oct 2026 (no migration)

- **EMIRA villas** (serials with a comma, e.g. `A4-1,2`) now work in the villa pickers and Villa Inventory. Before, the
  comma split them into two broken pieces: Villa Inventory never updated for them, and re-saving the lead stored
  `EMIRA 1 - A4-1, 2`. That broken form is repaired automatically the next time the lead is saved.
  (`splitVillaText()` in `assets/js/app.js`, `villa_list()` in `api/clients.php`.)
- **Delete Lead** no longer fails with HTTP 500 for leads that have Notification Log entries. Its log rows are kept,
  with the lead's name and "lead deleted". Any villa it held goes back to Available, and its demand-letter PDFs
  (and their WhatsApp links) are removed.
- **Delete User**: a user who still has history shows a clear message instead of HTTP 500.
- **India time everywhere**: `config/db.php` sets PHP and the MySQL session to India time (+05:30). Document uploads,
  reassign, follow-up and handover entries now match the payment and call entries. Entries saved before this keep
  their old time.
- **Add Lead → Enquiry** with Lead Status "Site Visit Scheduled" now starts at New Lead (the old non-existent
  "Site Visit Planned" stage is gone). The form's status is kept in the lead's notes.
- **Category "Not set"** is saved as empty (NULL). On a strict-mode MySQL server, a blank used to fail the save.
- **Login required** for `api/public_intake.php` (CRM Add Lead only — the website forms use `api/source_intake.php`)
  and `api/check_mobile.php`. Legal / Accounts can't quick-add leads, same as in `api/clients.php`.
- **Server-side checks** now match the screens:
  - A salesperson can't upload or delete documents, or edit a lead, once it's with Post Sales (viewing still works).
  - Only the lead's own salesperson or an admin can add a site visit.
  - "Closed" on a booked or registered lead keeps its villa Booked / Sold.
- **Cleanup**: removed `api/visit_reschedule.php` (replaced in v97). `schema.sql` is now the full current schema plus
  the villa list, for a fresh install only.
- Files: `api/clients.php`, `api/admin_users.php`, `api/client_documents.php`, `api/visits.php`,
  `api/public_intake.php`, `api/check_mobile.php`, `config/db.php`, `assets/js/app.js`, `schema.sql`, `README.md`;
  `api/visit_reschedule.php` deleted.

## Lead Summary (Admin / IT) — Legal / Accounts headings only after transfer (no migration)

- In the Admin / IT Lead Summary's Full Lead History, the **Legal History** heading shows only once Sales has
  transferred the lead to Post Sales, and **Accounts History** only once Legal has transferred it to Accounts. Before
  that, only Sales History shows (no more "Not with the Legal / Accounts team yet" lines).
- If an admin moves a lead back before a transfer, that heading hides again. If that desk already has history, the
  heading stays, so nothing is hidden. Sales / Legal / Accounts desk summaries are unchanged.
- Files: `assets/js/app.js` (`renderHistorySections`).

## Phone / tablet — compact search & filters, smaller Add button (no migration)

- **Search bars and filters** on every page (Manage Leads, Follow-ups, Site Visits, Bookings, Payments, Possession,
  Source Leads, Notification Log, Villa Inventory status buttons) are one compact size on phones and tablets:
  36px tall with 13px text — the same size as on desktop (they were 42px / 14px). Form fields inside dialogs keep the
  bigger finger-sized fields. On a 360px phone, Manage Leads' filters now stay two to a row.
- **Floating Add button** (Add Lead / Add Follow-up / Schedule Site Visit / Add User) on phones: 48px with an 18px icon
  (was 56px / 21px); 44px when the phone is held sideways.
- Fixes from the full phone / tablet check:
  - Leads by Category on narrow phones (under 380px): one card per row, so "UNCATEGORIZED" no longer breaks in the
    middle of the word.
  - Admin dashboard's Sales | Legal | Accounts switch: under 400px the icons are left out so "Accounts" and its count
    fit on one line (the count ran past the edge); under 340px each name sits above its count.
  - Lead Summary: a very long name wraps inside the header instead of running under the × button.
- Files: `assets/css/responsive.css`, `README.md`.
