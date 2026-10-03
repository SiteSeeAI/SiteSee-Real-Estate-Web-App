## Onsite closeout and Production prepared — 2026 10 03

The active task is the user's onsite final-billing and Production request, beyond the previously completed email-change work. `install-re-job-closeout-20261002-r1.py` adds approved onsite services, a fixed final bill, photographer **Job Complete**, automatic TEST saved-card collection with recovery of the same payment, a **Production** queue and delivery-link editing, and payment-gated delivery in the customer portal. Phone login, original booking/deposit records, calendar/invitation history, CRM links and provider settings are preserved.

The application and installer are saved on isolated branch `feat/job-closeout-production-20261002`, based on the latest recovered `4a658e8408dd1fee9d4282eb6f6d9d0928f7c359`. First recoverable checkpoint: `0237c4d7ea50ef2f2508fc1d56050352e391819e`. The original feature branch and main remain unchanged. Core closeout tests, all 23 installer cases and the new and existing real HTTPS browser suites passed using isolated synthetic providers. An independent audit's three findings were fixed and retested. See `JOB-CLOSEOUT-REVIEW.md` for review evidence, limitations and the one WHM command.

This is a prepared TEST release, not a reported server installation or a provider-connected payment test. Do not repeat completed email/calendar/CRM setup as the next task. Install this one reviewed package, then validate a fresh authorized TEST job from onsite extras through final collection and Production delivery. The historical checkpoints below remain preserved; they do not describe the current remaining task.

---

## Separate TEST agent recipient update — 2026 10 02

The user wants a fresh TEST booking using `info@1789media.com`, with the existing staff-request route retained and calendar invitations sent from `sales@re.sitesee.ai`. The exact address was confirmed twice; `info@1789meidia.com` was a typo and is not approved. The existing code restricted the modern appointment/invitation workflow to sales. `install-re-test-recipient-20261002-r1.py` adds only the approved agent address under the existing sales TEST configuration. Four review passes, the independent audit and [CI run 37054475044](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37054475044) passed on source commit `18ca4fee63342213fe9e9b9af9e986ae2d2c0510`. All 23 installer tests and the actual HTTPS browser checks passed, including three external-agent cases and all 19 prior form contracts. The mobile receipt-status screenshot was visually reviewed. The single file-only installer is ready; no deployment or real booking test has been performed for this update.

CRM readiness still checks primary `Email`, not `Secondary_Email`. Use a separate test-agent contact with primary email `info@1789media.com`; preserve the historical sales contact and its links. The user can prepare that contact and then explicitly select it in staff readiness. No contact is created or changed by this release. Initial request routing remains unchanged (`SITESEE_REAL_ESTATE_SALES_EMAIL`); the development session has not inspected runtime environment settings.

External inbox receipt remains unverified in the application and must be confirmed by the recipient. This update does not add Graph access to the external mailbox. Test only a fresh booking after installation, retaining phone login, pricing and existing orders; do not repeat completed lifecycle/payment tests or reuse protected orders `5D99D336572661A00885`, `8D20B4EBFCD0BADC4DE5` or booking `D32FFC7458`. See `TEST-RECIPIENT-REVIEW.md` for scope and the single WHM command. This checkpoint supersedes older recipient limits below.

---

## Account email verification installed; separate test address approved — 2026 10 02

`install-re-email-change-20261002-r1.py` adds Account → Change Email with a code sent to the requested address. Phone login, account identity, pricing access and linked order history are retained. Old order recipients and existing invitations stay as recorded. Stale unsubmitted drafts require review; captured orders retain safe retry behavior.

Four review passes and the independent audit found no blockers. The application/concurrency suites, all 23 new installer tests, existing regression suites, immutable historical builders and actual HTTPS browser checks passed in [CI run 37032836110](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37032836110) on `92c430aad4f231ce657174c924ec644bd0b96d70`. Desktop/mobile screenshots were reviewed, including long-address wrapping. The operator confirmed installation; backup: `/home/sitesee/.sitesee-real-estate/deployment-backups/email-change-pl1t0yzw`. They supplied and reconfirmed `info@1789media.com` and reported that the verification message arrived in the actual Inbox, not spam. This confirms verification-mail receipt only; account activation and a new calendar invitation have not been independently observed. `sales@re.sitesee.ai` remains the invitation sender and original approved recipient. No mailbox investigation or settings change is authorized. Stripe stays TEST.

---

## Staff Booking Review installed and accepted — 2026 10 02

`install-re-staff-review-20261002-r1.py` simplifies the staff page into a booking summary, the current review or recovery action, and expandable detail sections. Exactly two application files change. Existing forms, consent, authentication, POST handlers, business rules and provider settings are preserved. A browser-confirmed staff sign-in header incompatibility is corrected with `Referrer-Policy: same-origin`; password, CSRF and origin checks remain unchanged.

Four review passes and the independent agent audit found no remaining blockers. All 22 staff installer tests, two source-preservation tests, existing PHP and portal suites, historical builder checks, and the new actual HTTPS staff browser suite passed on `11cf85cbfebf72f24b1934eae667d9c0faf7895d` ([CI run 37023461598](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37023461598)). The browser compares 19 saved-state form contracts and checks native login, rejected CSRF/origin/consent, unchanged ledgers, no provider calls, recovery visibility, Central Time, keyboard access and four responsive widths. Evidence and the one WHM command are in `STAFF-REVIEW.md`.

Installer SHA-256: `427cb1b274364eeabcc7190fc285027d5a7a41893ffa5c8ea09cd3795a114d0f`. The operator confirmed installation and accepted the staff layout. Backup: `/home/sitesee/.sitesee-real-estate/deployment-backups/staff-review-wgbtajhu`. It reuses durable backups, locks, interrupted-update recovery and unknown-edit preservation, accepts historical root-owned backup directories without changing ownership, and makes no database changes or provider calls. No real account login, message, payment or calendar action was performed for this update. Stripe remains TEST, only `sales@re.sitesee.ai` is approved, email processing remains deferred, and main has not been merged.

---

## Portal polish installed — 2026 10 02

`install-re-portal-polish-20261002-r1.py` consolidates the remaining customer payment presentation changes: one clear payment button beside the amount due, recorded balance status in My Orders and Order Details, the approved rush fee and recorded payment breakdown, and corrected staff text about TEST balance collection. Exactly four application files change. Payment authority, phone login, calendar rules, database contents and provider settings are preserved.

Four review passes and the independent agent audit found no remaining blockers. All 22 installer tests, PHP suites, historical builder checks and actual HTTPS desktop/mobile browser checks passed on source commit `1648041604ce4b43c0f0c4a94ba659929bdbedf2` ([CI run 37019286163](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37019286163)). Full evidence and the one WHM command are in `POLISH-REVIEW.md`. Installer SHA-256: `d6babf04d83da0a27c9e6ec79700ed3613dd0361c90e51617f9106c99e87b905`.

The operator reported successful installation of `portal-polish-20261002-r1`: clear portal payment buttons, recorded balance status and price breakdown. Its backup is `/home/sitesee/.sitesee-real-estate/deployment-backups/portal-polish-t1kz5az7`. Existing payment, phone login, calendar and rush approval rules were preserved; no database changes, provider calls, messages or configuration changes were made. The Staff Booking Review update is now installed and accepted as recorded above. The existing TEST billing and appointment evidence below remains valid; email processing remains deferred, only `sales@re.sitesee.ai` is approved, Stripe remains TEST, and main has not been merged.

---

## Verified installed TEST checkpoint — 2026 10 02

The customer portal is installed and operating in TEST. This checkpoint supersedes the older deployment and recipient statements below. Source branch: `feat/calendar-confirmation-20260925`; application head inspected before this documentation update: `2fee6fd57e101d5d75f379ddf02aa62a75972296`. No application changes were needed for this billing validation.

### Verified installed behavior

Phone-number sign-in, new portal orders, TEST deposits, staff review, Microsoft calendar confirmation, invitation evidence, Zoho history, rescheduling and cancellation were verified by the operator. Order `8D20B4EBFCD0BADC4DE5` remains cancelled; its calendar event disappeared. Both lifecycle notices reached `sent_observed`, `recipient_copy_observed` and CRM `associated`. Preserve this evidence and protected booking `D32FFC7458`; do not resend notices.

The operator reported successful installation of `install-re-calendar-notice-20261002-r1.py`. Residential, Commercial and portal date inputs use server time and America/Chicago to dim dates with no eligible arrival window. The 72-hour standard and 12-hour requested-rush limits retain partially eligible boundary dates. Existing server validation, staff approval and rush pricing remain authoritative. Calendar and portal source/browser CI and the independent installer audit passed before delivery; see `documents/calendar-notice-source.json` and the calendar tests.

On 2026 10 02, the operator verified the actual Stripe TEST billing journey:

| Check | Evidence and result |
| --- | --- |
| Deposit receipt | Cancelled order `8D20B4EBFCD0BADC4DE5`: $75 receipt opened successfully. |
| Saved payment-method access | Stripe Sandbox management page opened without errors and displayed the existing test card ending 4242. Adding or editing a method was not claimed as tested. |
| Fresh Order Again | New order `5D99D336572661A00885`, requested 2026 10 09, 09:00–11:00 Central Time; TEST deposit recorded. |
| Approved rush balance | Staff review approved the $150 job and $59 rush fee. Subtracting the $75 deposit produced the expected $134 balance. |
| Balance collection | Operator completed the TEST payment; portal showed “Test balance recorded: $134.00” and removed the balance-payment link. |
| Paid order and documents | Operator confirmed $0 remaining and successful access to the balance receipt, invoice and invoice PDF. |

These are operator screenshots and confirmations of the installed provider flow. Existing isolated tests separately cover ownership, consent, approved amount, recovery with the same payment attempt, verified expiry, signed-event replay/races and refund/dispute guards. They are not evidence that every failure case was repeated against Stripe. The agent did not complete a production login or submit a payment. The new order was left active; its last appointment screenshot showed Awaiting Confirmation. This billing result does not assert calendar confirmation for that order.

### Remaining work and controls

Next is the final customer-portal usability pass, followed by the Staff Booking Review redesign. Consolidate interface changes into one reviewed installer. The balance action is currently labelled “Review Test Balance”; the operator initially had difficulty finding it. The order-list/payment badge still says “Test Deposit Recorded” after balance payment, although the detail balance is correctly reduced to zero. The staff page's old statement that final balance collection is disabled is stale. Address these presentation issues together without changing payment authority or requiring a repeat of the completed appointment lifecycle.

Only `sales@re.sitesee.ai` is approved for real test messages. The Deleted Items issue remains deliberately deferred. Before go-live, obtain approval for a second business address and retest distinct sender/recipient addresses; do not select an address, alter mailbox settings or investigate email processing as part of this checkpoint. Stripe remains TEST; no live activation or merge to main is authorized. Preserve credentials, phone login, orders, deposits, calendar records, CRM links and provider settings. Installation must not change databases or call providers, and must retain backups, locks, interrupted-update recovery and unknown-edit preservation without changing ownership of historical root-owned backup directories.

The older checkpoints below are retained as history and do not describe the current installation or approved recipient.

---

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
