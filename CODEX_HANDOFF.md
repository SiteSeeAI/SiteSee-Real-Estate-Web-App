# SiteSee Real Estate — unified server application

Prepared 2026 10 05, America/Chicago. User target: operational by close of business Friday, 2026 10 09. This is a target governed by the acceptance gates below, not permission to omit testing or deploy unresolved regressions.

## The user's instruction

Create one coherent, server-hosted application using as much of the existing successful code as possible. Preserve every feature, function, calculation, workflow, permission, provider interaction and existing record with exacting precision. Carry forward every test and documented correction. Establish the unchanged baseline first, repeat automated testing after consolidation, then let the user perform the full installed TEST acceptance review. Package one consistent release and one consolidated installer. Minimize operator interaction and avoid repeated small deployment steps.

The current system already runs on the server as PHP, SQLite and browser JavaScript. "Compile into a single uniform system" means an integrated application and reproducible deployment artifact; it does not require a single source file, desktop executable, language rewrite, new framework, database replacement or provider migration. Prefer the current stack and preserve tested modules. No rewrite is justified solely by calling the result an app.

## Exact starting point

| Item | Pinned value |
| --- | --- |
| Repository | https://github.com/SiteSeeAI/SiteSee-Real-Estate |
| Working feature branch | `feat/job-closeout-production-20261002` |
| Complete source/document checkpoint | `ff5e625a8ff4c51af336e9203fa4be29b3611570` |
| Final application code commit | `1f5247847a45722ba9ed185ff6800c9854dccb9b` |
| Parent vendor implementation | `0d732cf4dca2ac3d8511a114b9bec3b2276df846` |
| Exact source tree | `8e8fb9597306760c6d8323160e7522b964285b37` |
| Source snapshot | 576 Git-tracked files, 71,554,071 bytes before handoff additions |
| Draft PR 39 | https://github.com/SiteSeeAI/SiteSee-Real-Estate/pull/39 ; base `feat/calendar-confirmation-20260925` |
| Draft PR 38 | https://github.com/SiteSeeAI/SiteSee-Real-Estate/pull/38 ; base `main`; head `4a658e8408dd1fee9d4282eb6f6d9d0928f7c359` |
| Server origin | https://re.sitesee.ai |
| Current public root | `/home/sitesee/public_html/re` |
| Current private root | `/home/sitesee/.sitesee-real-estate` |
| Stripe | TEST only |

Do not start from `main` alone; it excludes the unmerged application work. Do not treat the old workspace's artificial one-commit recovery repository as genuine history. Use the exact source snapshot or clone the supplied verified Git bundle. The bundle includes the complete parent graph reachable from the pinned checkpoint, including merged branches. Git metadata, fixtures and source manifests retain prior committed iterations; unsuccessful intermediate revisions are historical evidence, not deployment candidates. Uncommitted chat drafts cannot be reconstructed from Git and are not claimed to be included.

The source tree is the code authority. `OPERATING_STATUS.md` records the latest operator report and supersedes installation-status text in the immutable source snapshot; source release reviews and STATUS preserve earlier evidence. Older design documents and early README sections are historical and sometimes conflict with later implementations. Do not "fix" implemented pricing from an old rate sheet or memory without an explicit user decision.

## What is installed and what remains unverified

| Checkpoint | Evidence and status |
| --- | --- |
| Pricing, booking, 50% TEST deposit, phone portal, Microsoft calendar/invitation and CRM history | Implemented; prior operator acceptance reported. Phone sign-in is required. Preserve all existing proofs and retest in the unified app. |
| Appointment self-service | Reschedule/cancel and recovery were installed and accepted in TEST. Existing Microsoft and legacy Zoho appointments must retain identities and history. |
| 72-hour unavailable-date dimming; simplified staff review; payment-button polish | Operator reported successful installations. |
| Change Email | Installed; code verification and inbox receipt accepted. This changes profile email, not the phone-only login method. |
| Original closeout / Production | `job-closeout-20261002-r1.1` reported installed. Backup `/home/sitesee/.sitesee-real-estate/deployment-backups/job-closeout-1gte4ywn`. |
| Complete onsite services / commission | `onsite-services-20261005-r1` reported installed. Backup `/home/sitesee/.sitesee-real-estate/deployment-backups/onsite-services-en6k5ecg`. Installed final collection and completed deliverable release have not yet been accepted. |
| Separate vendor account and Property Video title | `vendor-accounts-20261005-r1` reported successfully installed at 2026 10 05, 21:01 Central. Backup `/home/sitesee/.sitesee-real-estate/deployment-backups/vendor-accounts-uegv5you`. Vendor account creation, assignment, sign-in and connected closeout acceptance remain outstanding. |
| Unified application | Requested now; not yet built or installed. This handoff is not evidence of its success. |

Do not invent a new booking to replace an existing paid one or treat a deployment success message as payment, SMS, inbox, calendar or CRM acceptance. Retain these protected records and all backups: orders `5D99D336572661A00885`, `8D20B4EBFCD0BADC4DE5`, booking `D32FFC7458`.

## Application structure and reuse map

| Area | Existing implementation to carry forward |
| --- | --- |
| Public website, galleries, navigation, brand assets | `public/`, including all images/fonts, `assets/css`, `assets/js`, individual HTML pages and public PHP route stubs |
| Pricing access, signed approvals, verified calculator and validation | `_private/real-estate-form-config.php`, `_private/real-estate-pricing.php`, `_private/server/pricing-*.php`, `_private/server/quote-submit.php`, `_private/views/pricing.php`, `_private/pricing-assets/` |
| Booking ledger, staff review, approved price, deposit and webhook | `_private/server/booking-store.php`, `booking-schedule.php`, `booking-staff.php`, `booking-checkout.php`, `booking-webhook.php`, `booking-pay.php` |
| Availability, Microsoft calendar, legacy appointment handling, recovery | `booking-availability*.php`, `booking-scheduling-provider.php`, `booking-microsoft-calendar.php`, `booking-confirmation.php`, `booking-calendar-client.php`, `booking-lifecycle*.php`, `booking-manage.php` |
| Invitations, sender/recipient safeguards, CRM association | `booking-communication.php`, `booking-mail-client.php`, `booking-invitation.php`, `booking-workflow.php`, `booking-crm.php`, `booking-test-recipients.php` |
| Customer account, cell phone identity, sessions, ownership and email change | `portal-app.php`, `portal-access.php`, `portal-phone.php`, `portal-sms.php`, `portal-session.php`, `portal-orders.php`, `portal-email.php`, `portal-mail.php`, `portal-release.php` |
| New orders, order-again draft, customer self-service and billing | `portal-purchase.php`, `portal-service.php`, `portal-billing.php`, `_private/views/portal*.php`, `public/portal-assets/` |
| Onsite catalog, bill, commission, saved-card TEST collection, production | `booking-job-catalog.php`, `booking-job.php`, `booking-job-ui.php`, `_private/views/portal-job.php`, `public/portal-assets/onsite-services.js`, `job-payment.js` |
| Vendor identities, assignments, sign-in and job access | `vendor-access.php`, `vendor-admin.php`, `vendor-app.php`, `public/vendor.php`, `public/staff-vendors.php`, `public/portal-assets/vendor.css` |
| Reproducible deployment and history | `tools/`, `documents/deployment/`, `documents/portal/*-source*.json`, `tools/portal_source_chain.py`, original installers and fixtures |

`PHP_FUNCTION_INVENTORY.csv` indexes every named PHP declaration and records anonymous/arrow declarations separately across the production source. It is an inventory, not a claim of statement-level test coverage. `TEST_INVENTORY.csv`, the preserved workflows and acceptance matrix provide the test map. Inventory all browser event handlers and public routes when mapping the final unified shell; do not drop features simply because they are absent from a menu.

## Behavior that must remain exact

**Identity and roles.** Customer login is cell phone plus Twilio Verify code, never email. Email change retains verification, ownership, account revision and notification safeguards. Vendors have independent phone identities, Secure/HttpOnly host cookies, private session storage and explicit job grants. A matching name does not grant access. Vendor requests permit only assigned-job view, onsite preview/save and Job Complete; direct manager, customer-account, production, calendar or recovery actions remain denied. Preserve throttling, expiry, single-use codes, CSRF, same-origin checks, revision-based revocation and in-transaction authorization. Staff currently use a separate password-protected session. Do not silently replace manager authentication while unifying navigation. Production is presently staff-only, not a separately provisioned production login.

**Pricing and ordering.** Use the existing canonical PHP pricing functions and unchanged browser/server parity fixtures as the contract. Preserve all categories, packages, quantity ranges, cent rounding, licensing, hosting and platform terms. Current code supersedes older conflicting commercial area formulas. Keep required fields, access instructions, contact choices, must-have shots, mailing preference, quote-copy/email actions and exact notice semantics. Order Again is an editable new draft copying existing property/service selections; it does not replay the old charge, booking or consent, and missing historical quantities require review. Preserve this actual implementation; do not infer a newly forbidden property or automatically repeat a prior shoot.

**Appointment lifecycle.** Preserve America/Chicago and daylight-saving transitions, 72-hour notice behavior, displayed arrival windows and the distinct $59 / 12-hour rush rules exactly as code/tests express them. Dim unavailable dates using the server clock. Paid booking review, availability, calendar confirmation, invitation, recipient-copy evidence and CRM association are separate recorded states. Microsoft 365 dedicated mailbox/default calendar is the current provider; legacy Zoho records remain recoverable. Keep idempotency, provider readback, etags, durable uncertain operations and old/new interval reservations. Do not create duplicate events or resend saved notices during recovery. Retain the scheduled five-minute reconciliation worker and its locking and CLI working-directory repair.

**Payments.** Keep TEST credentials/gates, verified 50% deposit, existing saved-card reuse consent, signed webhook validation, immutable amounts and evidence-based remaining balance. Browser redirects never prove payment. An onsite final bill is separate from the original booking and credits verified deposits and prior balance payments. Record provider intent before confirmation; preserve idempotency and lost-response recovery. Declines/authentication needs remain visible and recoverable through the agent portal/manager. Never auto-recharge an uncertain attempt. Refunded/disputed payments require billing review and withhold delivery; they do not manufacture a new full unpaid balance. Preserve invoice/receipt history; direct final PaymentIntents do not automatically create a Stripe invoice.

**Onsite additions and commission.** Use every applicable ordering service: nine residential and eight commercial. Repeating selectors remove keys already selected in another added row; removing/changing a row restores availability. Original/package services remain selectable with the Already ordered label and earn no onsite commission. Matterport shows square-foot coverage; video shows minutes plus seconds, and commercial video quantity. The title is **Property Video**, retaining duration and quantity in service descriptions. Other quantities/terms retain current calculator behavior. Server rechecks every price and stale scope. Commission is 18% of eligible newly added one-time service base, rounded once using cents; exclude original/package services, subscriptions, hosting and licensing. Commission is recorded separately and neither increases the customer charge nor triggers an automatic payout. Residential platform monthly billing remains separate after publication; this closeout does not create a subscription.

**Job Complete and delivery.** Vendor or staff confirms onsite completion and the agent's verbal approval of the displayed services/amount. Changes invalidate that confirmation. Job Complete fixes the bill, records the authenticated vendor where applicable, marks onsite work complete, moves to Production and attempts final saved-card TEST collection. Onsite completion persists even if payment needs action. A duplicate action must not duplicate a bill or charge. Production has independent website, photo download, video download, SiteSee Platform, floor plan and additional named deliverable links. Required links reflect both original and added service scope. Drafts remain private. Production Complete publishes a finished set only after payment verification; later draft edits preserve the last published set. Vendors cannot edit Production.

**Presentation.** Poppins + Inter; SiteSee brand and "Show More. Decide Faster." Use "experience" rather than "tour" in new copy and avoid "demo". Keep the Property Video title correction. Preserve existing provider/proper product names where changing them would alter a contract. Clear mobile-first controls, minimal visible decisions, accessible labels, keyboard operation and 320/390/736/1200 checks. No unrelated redesign, pricing edits, new dashboards or new business features before parity.

## Build strategy

1. Verify source hashes and Git history. Establish a new working branch from the pinned application commit. Read `OPERATING_STATUS.md`, then the snapshot STATUS and release reviews before older docs. Run the unchanged baseline and record every result, including the known inherited failure below.
2. Produce a short reuse and route map. Keep tested domain functions, database tables, provider adapters and state machines. Introduce a consistent application bootstrap, configuration boundary and role-aware shell around them. Preserve route aliases and existing signed-link/webhook/return URLs. Any unavoidable signature/path change needs a contract test and explicit migration mapping.
3. Build a single reproducible versioned application release and one preflight/install/resume/verify command with backups, exact source checks, permissions and rollback/repair-forward rules. New releases must preserve old signed links, provider IDs, completed bills, grants and deployment histories. Old incremental installers are historical recovery artifacts, not scripts to run indiscriminately.
4. Execute parity testing on a separate database and synthetic providers, then an isolated server TEST staging environment. Compare old and new behavior for the acceptance matrix. Resolve differences rather than weakening assertions. Use the user's full manual review only after the automated candidate is coherent and complete.
5. After the user accepts the TEST candidate, present one concrete cutover: exact version/hash, database compatibility, backup, worker/webhook ownership, verification and rollback/repair procedure. Server cutover and Stripe LIVE are separate decisions. Nothing here authorizes LIVE charging, account deletion, reset of live records, broad cleanup or merging PR 38/39.

## Test gates and inherited failures

Preserve the entire `tests/` tree and every workflow command. Copying old PASS labels is not a retest. Run the current PHP 8.2 portal/calendar workflows, PHP 8.3 general validation, Node 24 tests, Python installer/recovery tests and Playwright 1.58.2 HTTPS suites with browser egress blocked and synthetic providers. Check explicit assertion-completion markers; an early PHP exit or a green job shell alone is insufficient evidence.

Final application commit `1f5247847a45722ba9ed185ff6800c9854dccb9b` passed Customer Portal Checks, including all six portal preview/HTTPS suites and reproducible builders: PR run `37397048266`, job `112055379311`; push run `37397043707`. Calendar Notice Checks passed run `37397048141`, job `112055378938`. Local vendor, full closeout and nineteen original staff form contracts also passed. Full logs and details are in `evidence/` and `TEST_EVIDENCE.json`.

**Known inherited failures:** Final Server Form CI stops at `tests/booking-communication.test.php:76`, because its legacy fixture uses an obsolete TEST recipient rejected by the intact sender/recipient guard at `_private/server/booking-communication.php:57`. The earlier closeout review also records four failures reproduced individually on the unchanged recovered baseline: obsolete-recipient fixtures in `tests/booking-lifecycle.test.php`, `tests/booking-workflow.test.php` and `tests/microsoft-scheduling.test.php`, plus stale deployed-file hashes in `tests/calendar-audit.test.php`. These five known fixture issues predate the vendor work; the final CI first-error stop does not revalidate the later suites. Do not report all repository tests as passing. Reproduce all five on the pinned baseline, review the fixture/hash corrections against the actual canonical source, preserve every production recipient and integrity guard, then require a clean complete run. Any other failure must also be investigated.

Keep four review passes for every consolidation: dependency/call graph, complete source differences and data/financial invariants, automated/browser parity, and deployment/interruption/preservation. Then obtain an independent agent audit. The original closeout, onsite and vendor releases already have these reviews; that evidence does not replace review of the unified implementation.

## Delivery schedule

| Central date | Required outcome |
| --- | --- |
| Monday evening 2026 10 05 / Tuesday 2026 10 06 | Verify package and full source, establish unchanged test baseline, inventory server-only dependencies, settle the minimal reuse/route plan, repair the inherited fixture issues. |
| Wednesday 2026 10 07 | Unified shell/bootstrap and versioned release assembled with working role flows; original core modules retained; automated parity running. |
| Thursday 2026 10 08 | Full automated, browser, recovery and installation rehearsal; four review passes and independent audit; provide a complete TEST candidate for the user's self-directed acceptance. |
| Friday 2026 10 09 | Resolve acceptance findings, freeze the release, perform user-authorized server cutover and verify each integration. Retain a tested rollback/repair path. |

If a correctness gate cannot pass, surface the specific blocker promptly. Do not add scope or trade safety/financial accuracy for the date. Give concise progress updates and ask only for information/access that materially blocks work. Collect infrastructure questions together after reading the supplied runtime inventory.

## First response in the next Codex session

Confirm the pinned commit and package verification; state which existing modules will be reused and the baseline test result. Identify only genuinely missing server access/configuration. Begin the consolidation work, keep a short active plan, and proceed through the gates above. Do not stop after offering a plan or ask the user to recreate this history.
