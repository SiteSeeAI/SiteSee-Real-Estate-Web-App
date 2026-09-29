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

The portal source now provides sign in → My Orders → owned details → guided New Order → server review → durable order ownership → TEST deposit. It also includes saved profiles, verified historical claims and Order Again with property/services review and fresh scheduling, access instructions and consent. Original application source and accepted preview remain unchanged.

The release remains **not installed** and defaults off behind both portal and booking TEST flags. No live Stripe settings, provider credentials, cron, existing booking rows, calendar events or customer messages were changed.

## Checkpoint verification

Verified source/test commit: `107196ec8e6e50e6a0347da3607bb7f7b0a1fa00`. [Targeted CI 36578888924](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36578888924) passed PHP 8.2 syntax, account/session regression, the purchase assertions, original preview and actual HTTPS browser workflow. The logs contain explicit PASS markers for all five suites. Two isolated customers verified both price paths, retained Back answers, canonical server totals, private ownership, repeated submission with one reference/no repeated notices, fresh Order Again fields, same-account CSRF recovery, unauthorized payment rejection and browser-return status without false payment. Existing synthetic legacy rows remained unchanged. Four widths (320/390/736/1200) passed; desktop review/services/payment and mobile scheduling screens were inspected.

Purchase assertions exercised capture-before-bind interruption and recovery, exact submission ownership evidence, expiry, disabled accounts, canonical deposit amounts, fresh consent, failed/successful notice handoffs without replay, ambiguous/open hosted checkout retries, embedded checkout, signed webhook acceptance/replay, cancelled-order rejection and separation of payment/staff/calendar state. Provider adapters were mocked in those assertions; browser tests captured mail locally and blocked PHP provider transports and nonlocal browser traffic. Actual inbox delivery, live Stripe SDK/provider behavior and deployment remain release checks.

Independent audit findings were resolved: same-origin fetch permission in CSP, the established staff notice URL, retained-draft CSRF recovery after sign-in and the existing mailing preference default. Browser testing also corrected quantity edits consuming the Next click. Log review found and corrected an early-exit test setup error; a completion guard now fails the purchase test unless every assertion finishes. Earlier green job summaries are not treated as purchase assertion evidence.

Four review passes and audit closure are recorded in `PURCHASE-REVIEW.md`; exact source hashes are in `purchase-source-manifest.json`. The previous account review/manifest remain historical evidence. No partial installation package is supplied.

## Screen flow

My Orders is the landing screen. Current orders and past orders/receipts share one history location. An order opens its details, appointment status, eligible appointment actions, payment status and documents. Account holds sign-in and profile controls. The header carries one gold New Order button.

New Order progresses through property type, property details, services and pricing, scheduling and access, and review. Applicable test payment and confirmation follow. Next and Back retain answers. Review links return to the relevant screen. Order Again starts a separate new service order from the previous service selection, with property review, current pricing, a new appointment and fresh consent. It does not repurchase old photographs, replay an appointment or copy payment authorization, access instructions or media assets.

The body uses neutral surfaces, Poppins headings and Inter text. Hick’s Law informs one primary action per task; Jakob’s Law informs familiar order history and checkout conventions. Related inputs are grouped, optional access fields appear only when needed, choices have large labeled targets, and progress is explicit. The preview preserves existing calculator behavior rather than reimplementing rates. Reference: https://lawsofux.com/ .

## Integration still required

| Area | Existing foundation | Remaining work |
| --- | --- | --- |
| Identity | Implemented approval/mail adapters, one-use sign-in, CSRF, secure session lifecycle, persistent approval and saved profiles | Deployment verification and actual login email receipt |
| Order ownership | Verified historical claims, owned reads and durable new-order binding with interruption recovery | Staff reconciliation of ambiguous history and owned billing documents |
| New orders | Guided residential/commercial ordering, canonical review, availability feedback, durable submission, fresh Order Again and guarded notices | Deployment and actual mail/availability verification; CRM remains in the established staff workflow |
| Test deposits | Owned adapter, fresh consent, existing hosted/embedded checkout, signed webhook authority and return/status screens | Actual TEST-provider/SDK verification after complete integration and installation |
| Balances | Existing approved amounts, deposit totals and rush calculations | Balance ledger, eligible collection, duplicate-proof sessions, signed webhook processing, adjustments/refund presentation |
| Billing | Stripe customer IDs on bookings | Verified customer mapping, restricted TEST billing portal configuration, saved methods, billing address, owned invoices and receipts |
| Appointments | Completed SiteSee change/cancel, notice history, reconciliation | Owned authenticated adapters and targeted integration checks only |
| Delivery | Existing private/public deployment layout and recovery tools | One versioned installer/upload, manifests, rollback, operational checks and final operating guide |

Never attach orders or Stripe customers solely by matching email. Reuse established verifiers and require explicit ownership records. Never accept account IDs, approval flags, customer IDs, document URLs, final prices, or payment status from an untrusted request. Customer-facing responses must not reveal whether another account or order exists.

Stripe remains TEST. Deposit receipt is distinct from staff price approval, rush approval and confirmed calendar state. No subscriptions, administrative controls, pricing changes, live keys or automatic balance charges are authorized. Existing signed-webhook verification must remain the payment authority. A Stripe browser return is not proof of payment. A cancellation is not proof of a refund.

## Remaining estimate and release gates

The accepted delivery window is **2½–6 days from 2026 09 28**, with **2026 10 04** as the outer date in America/Chicago. The clock does not restart with a session. Target the full integrated TEST journey by **2026 10 01**, reserving the rest for integration fixes, package audit, installation and recovery checks. Apply the Golden Rule at every step: reuse verified foundations, batch complete workflows, minimize user interaction and raise only concrete blockers.

Next package: connect eligible appointment actions and the remaining approved-payment/billing journey. Reuse the completed lifecycle functions with owned authenticated adapters; add the approved balance ledger/collection and verified customer mapping for owned billing documents/payment methods. Retain the existing booking checkpoint and test only new authorization/integration paths. New-order capture/ownership/deposit integration is complete in source; do not rebuild it or assume installation has occurred.

Before release: test two isolated customers; expired/reused links and logout/session expiry; both pricing paths and their server parity; durable submission/payment retries; only eligible payment/appointment actions; owned invoice/receipt access; mobile keyboard use; and rollback. Carry forward completed booking evidence and test only changed integration paths. No full booking validation cycle is required because obsolete uploads were quarantined.

If a later package replaces working source, complete the four requested source/dependency/content/entry-point/package verification passes and an additional independent agent audit before proposing installation. Use hashes for unchanged files and document every intentional changed byte. Do not merge main or delete branches. Staff Booking Review redesign follows completion of the customer portal.
