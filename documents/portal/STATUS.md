## Installer Compatibility Update 2026 09 29

Current installer revision is **r2.1-cpanel**; application release remains portal-20260929-r2. The first aggregate server run stopped before changes. Its four blockers were installer recognition gaps: website-root mode 0750 and three existing, hash-matched calendar maintenance scripts. Corrected without changing application payload or website-root metadata. Local suite now passes 32 tests. Deliver the updated full ZIP and rerun the same --deploy command; no individual application upload is required.

# Current Consolidated Delivery Checkpoint — 2026 09 29

The user requires one complete package, not repeated single-file fixes. Delivery window is **2½–5 days from 2026 09 28**, without restarting the clock. Phone-number login is mandatory; email login is rejected.

`portal-20260929-r2` / `tools/install-portal-complete.py` supersedes the r1 deployment instructions and the standalone path correction. It packages current phone authentication and the complete account/order/payment integration, with aggregate source inspection, hash-qualified metadata repair, missing dependency recovery, transactional backup/recovery, and private SMS setup. The website/public manifest routing defect and the first-error-only metadata behavior are corrected. The retained r1 builder/fixtures remain historical and are not the deployment entry point.

Local consolidated installer tests cover baseline inspection, both reported failures, multiple blockers, missing file/CRLF recovery, metadata preservation, symlink/hardlink rejection, concurrent edits before backup and before replacement, interrupted installations, repeated installation, activation and rollback guards. Independent audit findings were corrected and regression tested. Full PHP and browser CI passed on f1d97c5f3d2eb7f42ee4d5d93a76a306cdf8d369 (run 36609050555). Evidence is recorded in COMPLETE-REVIEW.md.

**Not installed on the user's server.** No direct server access, provider call, real SMS, email, payment, calendar or CRM write was performed during package preparation. Twilio Verify credentials and verified phone enrollment are activation prerequisites; the one deployment command can collect them privately. Without them, the complete package remains installed but disabled. Browser/provider verification remains outstanding. The package does not change main navigation; the direct account.php URL is the controlled TEST entry.

The canonical Word manual is updated to v1.8 with phone login, the 2½–5-day window, consolidated deployment and recovery. Use the r2 installation guide and this current checkpoint for this package. Do not merge main or delete branches. Protect D32FFC7458 and preserve completed appointment evidence.

---

The following older checkpoints are historical; their email-login, r1 installation, and 2½–6-day directions are superseded above.

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

The portal source connects sign in → My Orders → owned details → guided New Order → server review → durable order ownership → TEST deposit. It also provides saved profiles, verified historical claims, fresh Order Again, eligible owned appointment changes/cancellation, approved TEST balance collection, verified receipts/available invoices, and restricted billing address/payment-method access. The shared signed webhook now dispatches balance events; all other legacy application bytes and the accepted preview remain unchanged.

The release remains **not installed** and defaults off behind both portal and booking TEST flags. No live Stripe settings, provider credentials, cron, existing booking rows, calendar events or customer messages were changed.

## Installation Package Checkpoint

Release `portal-20260929-r1` is prepared as one self-contained `tools/install-customer-portal.py`, with `documents/deployment/Customer_Portal_20260929.md` as its operator guide. Final tested source `902f1380bd4fa67a571e3338b570092605f9d3be` passed [run 36592621821](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36592621821), job 109489338713: seven PHP/browser suites, 13 Python installer tests, source syntax and artifact reproduction. The four review passes and independent audit have no unresolved blocking findings; see `INSTALLATION-REVIEW.md` and `installation-source.json`.

The package embeds 19 files and verifies 46 retained dependencies plus existing fonts and release metadata. A private release flag controls account access without editing PHP-FPM. Installation starts disabled, unknown server edits stop replacement, and incomplete attempts can recover from verified backups. Before activation, rollback restores files without touching data. After any activation, disable retains the webhook and ledger and blocks destructive rollback so delayed payment events remain processable. The manual is version 1.7 with installation and recovery instructions.

**Not installed.** No direct server access exists. Next, the operator uploads the one installer through cPanel and runs the documented WHM command block, then verifies the actual TEST journey using a fresh controlled order. Provider/SDK, active PHP-FPM, inbox, Microsoft/CRM and historical reconciliation checks remain outstanding. No real provider operation was performed here. Earlier source checkpoint evidence below is retained for continuity.

## Checkpoint verification

Verified source/test commit: `5726d6a2a17ba70fa67fa432947b5739a592c010`. Final targeted run: [36583982555](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36583982555). Job 109459250660 completed successfully, with all six explicit PASS markers verified in its logs.

The preceding complete candidate `3a0f4fbc13807a17908e17e79aaeb0aa38a5ae92` passed run 36583404418/job 109457223240 with six explicit PASS markers: access, session, purchase, service, preview and HTTPS browser. Final corrections hide unavailable appointment actions while notices remain unresolved and model Stripe provider acceptance before a lost response. The source asserts owned appointment windows/change/cancel/recovery, restricted billing customer/configuration checks, exact receipt/invoice evidence, approved amount and consent, refund/dispute blocking, durable same-key retries, expiry, early/repeated signed events and disabled-account isolation. Browser checks cover three isolated customers, actual forms and routing, owned billing/balance views and appointment reschedule/cancel. Four established responsive widths remain covered; new billing, balance and appointment mobile screens were visually inspected.

Four primary review passes and an additional independent audit found no blocking source issue. The reviewer verified all 75 manifest entries against the immediate baseline and checked the original 56-file baseline separately. The only intentional legacy application edit is the shared webhook dispatcher, preserving signature verification and canonical deposit processing. `SERVICE-REVIEW.md` records this package and `service-source-manifest.json` records exact source hashes. Prior account/purchase reviews and manifests remain historical evidence; prior purchase source `107196ec8e6e50e6a0347da3607bb7f7b0a1fa00` passed run 36578888924.

All provider behavior in these checks used isolated mocks or blocked transports; mail was captured locally. No real Stripe, calendar, mail or CRM operation was performed. Actual TEST-provider/SDK and inbox behavior, historical reconciliation, installation and rollback remain release checks. The manual is updated to version 1.6 with a customer quick guide. No partial installation package is supplied.

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
| Balances | Approved amount and fresh consent, durable attempt ledger, hosted/embedded TEST checkout, signed events, refund/dispute display and collection guard | Actual TEST-provider/SDK verification and staff reconciliation of ambiguous provider records |
| Billing | Exact owned payment evidence, available invoices/receipts, restricted TEST saved methods and billing name/address/phone | Actual TEST-provider verification; shared/unknown customer records and lists above 100 require staff reconciliation |
| Appointments | Existing lifecycle reused through owned authenticated adapters; targeted UI/authorization/recovery tests passed | Complete TEST-provider integration check; existing cro@sitesee.ai recipient restriction remains |
| Delivery | Existing private/public deployment layout and recovery tools | One versioned installer/upload, manifests, rollback, operational checks and final operating guide |

Never attach orders or Stripe customers solely by matching email. Reuse established verifiers and require explicit ownership records. Never accept account IDs, approval flags, customer IDs, document URLs, final prices, or payment status from an untrusted request. Customer-facing responses must not reveal whether another account or order exists.

Stripe remains TEST. Deposit receipt is distinct from staff price approval, rush approval and confirmed calendar state. No subscriptions, administrative controls, pricing changes, live keys or automatic balance charges are authorized. Existing signed-webhook verification must remain the payment authority. A Stripe browser return is not proof of payment. A cancellation is not proof of a refund.

## Remaining estimate and release gates

The accepted delivery window is **2½–6 days from 2026 09 28**, with **2026 10 04** as the outer date in America/Chicago. The clock does not restart with a session. Target the full integrated TEST journey by **2026 10 01**, reserving the rest for integration fixes, package audit, installation and recovery checks. Apply the Golden Rule at every step: reuse verified foundations, batch complete workflows, minimize user interaction and raise only concrete blockers.

Next package: execute the prepared complete TEST installer and verify installed configuration without exposing credentials, then run actual TEST-provider/SDK, inbox and CRM integration checks. Reconcile historical ownership where evidence is available. No direct server access is established; use the existing cPanel upload and WHM procedure as one complete package. Carry forward the completed lifecycle checkpoint and test only changed integration paths. Do not rebuild the completed source packages or assume installation has occurred.

Before release: test two isolated customers; expired/reused links and logout/session expiry; both pricing paths and their server parity; durable submission/payment retries; only eligible payment/appointment actions; owned invoice/receipt access; mobile keyboard use; and rollback. Carry forward completed booking evidence and test only changed integration paths. No full booking validation cycle is required because obsolete uploads were quarantined.

If a later package replaces working source, complete the four requested source/dependency/content/entry-point/package verification passes and an additional independent agent audit before proposing installation. Use hashes for unchanged files and document every intentional changed byte. Do not merge main or delete branches. Staff Booking Review redesign follows completion of the customer portal.
