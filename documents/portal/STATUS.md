# Customer portal implementation status

Updated 2026 09 29. Working branch: `feat/calendar-confirmation-20260925`; draft PR #38. Source baseline verified at `cc72f3088c161bb8e6a5266c8c73d11973d180c3`.

## Completed cleanup

The server report confirms 16 obsolete deployment files (917,302 bytes) moved to `/home/sitesee/.sitesee-cleanup-quarantine/20260928T212040279138Z`. Nothing was permanently deleted. The six superseded installers and the old communications upload folder were cleared; 297 protected static application and recovery files were unchanged. All 72 active release checks, 41 backup hashes, and six targeted PHP syntax checks passed. Cron configuration was unchanged and the service active. No booking database, calendar, mail, CRM, or payment operation was performed.

Recovery manifest: `manifest.json` in that quarantine directory. Restore only if needed:

```sh
python3 -B /home/sitesee/.sitesee-cleanup-quarantine/20260928T212040279138Z/restore-cleanup.py --restore /home/sitesee/.sitesee-cleanup-quarantine/20260928T212040279138Z
```

The current r4 recovery tool, diagnostics, credentials, booking data, logs and usable rollback backups were retained. Quarantine does not reclaim disk space. Future downloadable root-generated reports must be owned by `sitesee:sitesee`, mode 0600, outside the public directory.

Appointment modification and cancellation validation remains complete. The successful reconciliation checkpoint at 20:10:05 UTC is authoritative. Direct Microsoft calendar editing is outside the supported workflow and is not a remaining prerequisite. Protect booking `D32FFC7458`; do not resend existing notices.

## Current deliverables

The account-to-order access implementation adds `public/account.php`, public portal CSS/JavaScript, private controller/session/approval/ownership/mail/profile adapters and server-rendered views. It provides sign in → My Orders → owned order details → sign out, plus saved contact/company profiles and verified claims of previous orders. The existing preview and all installed application source are unchanged. New Order clearly states that production ordering is the next test release; it does not submit example data or use a competing booking workflow.

The release remains **not installed**. The new portal gate defaults off and requires the existing booking TEST mode. No live Stripe settings, provider credentials, cron, booking rows, calendar events or customer messages have been changed by this work.

## Checkpoint verification

Implementation commits and final targeted results are recorded here when the security and browser checks and independent audit finish. PHP 8.2 primitive/session checks passed in the initial run. The initial HTTP test startup failed; its isolated fixture state and safe diagnostics were corrected before rerunning. The test harness captures new login email locally and does not deliver customer mail. Production deployment and real inbox delivery are not yet verified.

Four review passes and independent audit findings are in `ACCOUNT-ACCESS-REVIEW.md`. Every recovered existing application file retains its baseline Git blob hash; intentional executable changes to the baseline are limited to the portal CI workflow. New implementation files are additions, not byte-equivalent replacements.

## Screen flow

My Orders is the landing screen. Current orders and past orders/receipts share one history location. An order opens its details, appointment status, eligible appointment actions, payment status and documents. Account holds sign-in and profile controls. The header carries one gold New Order button.

New Order progresses through property type, property details, services and pricing, scheduling and access, and review. Applicable test payment and confirmation follow. Next and Back retain answers. Review links return to the relevant screen. Order Again starts a separate new service order from the previous service selection, with property review, current pricing, a new appointment and fresh consent. It does not repurchase old photographs, replay an appointment or copy payment authorization, access instructions or media assets.

The body uses neutral surfaces, Poppins headings and Inter text. Hick’s Law informs one primary action per task; Jakob’s Law informs familiar order history and checkout conventions. Related inputs are grouped, optional access fields appear only when needed, choices have large labeled targets, and progress is explicit. The preview preserves existing calculator behavior rather than reimplementing rates. Reference: https://lawsofux.com/ .

## Integration still required

| Area | Existing foundation | Remaining work |
| --- | --- | --- |
| Identity | Implemented approval/mail adapters, one-use sign-in, CSRF, secure session lifecycle, persistent approval and saved profiles | Deployment verification and actual login email receipt |
| Order ownership | Implemented trusted existing-token claim adapters and owned-only history/details | New-order account binding, staff reconciliation for ambiguous history and owned billing documents |
| New orders | Canonical PHP/JS pricing, required fields, availability and booking capture | Production wizard, exact server payloads, durable submission idempotency, retry-safe ownership binding, existing communications and CRM integration |
| Test deposits | Hosted/embedded Stripe checkout, signed webhook verification, saved customer/payment IDs | Authenticated portal adapter and payment return/status screens |
| Balances | Existing approved amounts, deposit totals and rush calculations | Balance ledger, eligible collection, duplicate-proof sessions, signed webhook processing, adjustments/refund presentation |
| Billing | Stripe customer IDs on bookings | Verified customer mapping, restricted TEST billing portal configuration, saved methods, billing address, owned invoices and receipts |
| Appointments | Completed SiteSee change/cancel, notice history, reconciliation | Owned authenticated adapters and targeted integration checks only |
| Delivery | Existing private/public deployment layout and recovery tools | One versioned installer/upload, manifests, rollback, operational checks and final operating guide |

Never attach orders or Stripe customers solely by matching email. Reuse established verifiers and require explicit ownership records. Never accept account IDs, approval flags, customer IDs, document URLs, final prices, or payment status from an untrusted request. Customer-facing responses must not reveal whether another account or order exists.

Stripe remains TEST. Deposit receipt is distinct from staff price approval, rush approval and confirmed calendar state. No subscriptions, administrative controls, pricing changes, live keys or automatic balance charges are authorized. Existing signed-webhook verification must remain the payment authority. A Stripe browser return is not proof of payment. A cancellation is not proof of a refund.

## Remaining estimate and release gates

The accepted delivery window is **2½–6 days from 2026 09 28**, with **2026 10 04** as the outer date in America/Chicago. The clock does not restart with a session. Target the full integrated TEST journey by **2026 10 01**, reserving the rest for integration fixes, package audit, installation and recovery checks. Apply the Golden Rule at every step: reuse verified foundations, batch complete workflows, minimize user interaction and raise only concrete blockers.

Next package: connect the production guided purchase journey to the unchanged PHP/JS pricing engines and established booking capture. `booking_capture` owns its transaction; bind account ownership using a durable, retry-safe submission record and recovery, without nesting transactions or relying on an email match. Then connect TEST deposits, eligible appointment actions, approved balance collection and owned billing access. Keep the existing booking lifecycle test checkpoint closed; validate only new authorization/integration paths.

Before release: test two isolated customers; expired/reused links and logout/session expiry; both pricing paths and their server parity; durable submission/payment retries; only eligible payment/appointment actions; owned invoice/receipt access; mobile keyboard use; and rollback. Carry forward completed booking evidence and test only changed integration paths. No full booking validation cycle is required because obsolete uploads were quarantined.

If a later package replaces working source, complete the four requested source/dependency/content/entry-point/package verification passes and an additional independent agent audit before proposing installation. Use hashes for unchanged files and document every intentional changed byte. Do not merge main or delete branches. Staff Booking Review redesign follows completion of the customer portal.
