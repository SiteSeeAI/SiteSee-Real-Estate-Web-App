# Changelog

This is the iteration record from October 9, 2026 onward. Dates use America/Chicago. Earlier immutable releases and reviews remain historical evidence; this file does not reconstruct dates or acceptance that were not recorded.

Each iteration records its ID, purpose, status, source/release, changes, validation and user acceptance. Keep planned work separate from implemented, installed and accepted work. Add an entry for each subsequent implementation/release and update its acceptance after user review. Documentation-only commits do not change the installed application.

## DEP-001 — home-directory cleanup and versioned deployment preparation — 2026-10-13

Status: assessed; host archiving and version-folder migration have not been performed. Stripe remains TEST. This documentation checkpoint does not change any frozen release payload.

The operator supplied the home-directory listing and requested a complete release inside its version folder. Seventeen historical loose installer files are identified as first archive candidates; current public/private source has no references to their home-directory installer paths. Server schedule/service references still need checking before they move. The home `pricing.php`, diagnostic/recovery tools, cleanup reports and prior quarantine evidence need individual identification. Preserve the active public/private roots, the actively used `.sitesee-graph-mail.json`, account/cPanel/mail/SSL state and the separate CTC Publishing application. No blanket deletion is appropriate.

The current installer updates fixed roots; its version-named staging/backup directories are not the live application directory. The planned layout separates versioned code/public assets from shared private state and must preserve existing URLs, provider bindings, worker identity/lock, configuration and financial records. Full assessment and exact installer candidate list: [home-directory review](documents/unified/HOME_DIRECTORY_REVIEW.md).

## UX-003 — client workspace, reports and archives — 2026-10-13

Status: implemented, reviewed, tested and published as `unified-test-e0bed2b3726c`. Operator installation and connected acceptance of this new UX iteration are pending. Stripe remains TEST.

User acceptance recorded: “All tests confirmed and verified. All in working order.” This accepts the prior connected checks, including financial automation and CRM readiness. The prior UX-002 release is the user-confirmed working predecessor; its complete host transcript and new backup path were not supplied. No LIVE cutover was authorized.

Requested: cancellation moves immediately out of Open Orders for customer and management; closed/cancelled orders archive after 14 days; customer sections open below the persistent appointment overview; state-gated appointment/delivery actions use the same workspace. Customer navigation: New Order, Reports, Billing, Archived Orders, Profile, My Orders. Incomplete profiles lead from New Order to the required Profile form. Add month/quarter/year/custom recorded expense reports with on-screen results and a real PDF download, yellow rush scheduling highlight and bold service/package names.

Reports separate recorded cash payments, confirmed cancellation refunds and applied credits. Refunds use an immutable first verified confirmation date; undated legacy refunds or adjustments make the net expense total unavailable pending Billing review; pending refunds and estimates do not enter cash totals. Historical records and financial recovery remain reachable in Billing after archiving. No provider writes, schedule or financial-policy change is part of this iteration.

Local validation: all 43 PHP suites pass on PHP 8.2 and 8.3, including 29 report/archive checks and 95 financial assertions. All seven HTTPS browser journeys pass, including same-document customer sections, inline verified Delivery, account-owned reports/PDF and immediate cancellation classification. 110 Node checks pass. Python discovery passes 153 cases with 22 unavailable-privilege skips; the directly privileged checks are supplied by exact-source CI below. Historical source layers and host protocol checks pass. Four passes: [UX-003 review](documents/ux/UX-003_REVIEW.md). Frozen source: `e0bed2b3726c05b52c5d2c943ccd887cd3b376b8`. [GitHub release](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/unified-test-e0bed2b3726c). Package SHA256 `82be15dbd74b6f0e22627c2992d0db0fc743adf752a47dfb9799e8a7702ad0b6`; installer SHA256 `068ad6744d61add55c2470f99e1ed7a07d87e4a93f527f108c27c7ec64b6add4`. All 276 runtime payloads and 689 exact source entries were independently checked; the two report modules are the only added payloads. Exact installed predecessor package/manifest/schema/all274 digests were independently reproduced and verified.

Final publication run `38470116563` passed all five jobs and every step. Both PHP 8.2 and PHP 8.3 completed 65 directly privileged installation/recovery cases with zero skips; all seven browser completion markers, actual inline customer appointment and verified Delivery, 95 financial assertions, 28 core report/archive checks (the additional local `pdftotext` check is not available in the CI image), 27 host protocol checks and eight actual isolated FPM checks were verified in the authentic CI archive. All five published assets were independently downloaded and checked by full bytes, SHA256, GitHub digest, size and attachment headers. [Independent audit](documents/ux/INDEPENDENT_UX-003_REVIEW.md) closed without a blocker. Local startup was restarted and verified through TLS, real synthetic phone sign-in, the new navigation and PDF download. No hosting update was performed by the assistant.

Installation uses the existing approved WHM-root backup/pause/drain/shared Apache/PHP 8.2 graceful reload/install/FPM verification/reopen procedure. The additive date-evidence table initializes on normal application use; old undated refunds remain undated. Financial activity requires repair forward, never an operational database restore. After the operator reports HOST UPDATE PASS, check the new customer/management lists, inline sections, report/PDF and archive; prior connected financial/cancellation tests remain accepted. The prior complete host transcript/new backup for UX-002 was not supplied and is not reconstructed here.

## UX-002 — CRM readiness handoff — 2026-10-13

Status: reviewed, tested and published as `unified-test-201b1888abad`; working installation and connected readiness accepted by the user on 2026-10-13. Full operator host transcript/new backup path not supplied. Stripe remains TEST.

Frozen source: `201b1888abad434d37236e3f478b3e0b2068919b`. [GitHub release](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/unified-test-201b1888abad). Package SHA256 `f7c94d3d65d206d98e08cf545a30996baffd4c27f133ce8e1b119d1a052d6285`; installer SHA256 `2df3fd62449d9827b9047cd8102a85e97ae9ac8cd09f45194169946664263657`. All 274 runtime payloads are identical to reviewed implementation `de9bc48`; final source includes the test-fixture correction. Documentation-only checkpoints do not alter these frozen payloads.

Reported problem: Check Booking Readiness found existing CRM matches but the focused screen hid their required linking forms. Choosing another action performed a GET that discarded the returned matches, leaving staff stuck at Prepare Booking.

Changed: readiness results now present the CRM choice as the next action; a single match shows its unchecked verification form. Action choices and the back link switch existing forms within the same document, retaining the returned results and candidate identities. Linking still requires explicit verification and the original server/session/provider guards. Changing actions clears unsubmitted consent and alternative-window selection. A new `application.js?v=ux-002` URL delivers the correction to browsers with the prior script cached. Only the shared action script and its shell script URL change in the deployed application.

Validation: all 42 PHP suites on PHP 8.2/8.3, 110 Node unit checks and all seven HTTPS browser journeys pass locally. The staff browser regression uses the real authenticated readiness/link POST handlers and native forms with synthetic providers: two CRM matches, A/B selection/back navigation without reload, unchecked/fresh consent, explicit verified link, confirmation-screen handoff, single-match verification and alternative-window consent reset. Historical source checks strictly reverse the new two-file layer before financial/UX layers. Exact installed predecessor package/manifest/schema are pinned independently; financial repair-forward protections remain intact. Review: [UX-002](documents/ux/UX-002_REVIEW.md).

Release verification caught an older tampering fixture that assumed the staff handler changed. This presentation correction leaves that handler byte-identical, so the fixture now selects an actual changed predecessor payload and retains the assertions that unknown records/mixed deployments are refused without overwriting files. The initial candidate was withheld from publication. Application and installer bytes are unchanged by this test correction; fresh exact-source publication checks are required.

Final evidence: publication run `38446873572` passed all five jobs and every step. Both PHP 8.2 and PHP 8.3 completed 65 directly privileged installer cases with zero skips; all seven browser completion markers, the actual guarded CRM handoff, fresh consent/cache checks, 27 host-protocol checks and eight actual isolated FPM tests were verified in the authentic CI archive. The independent reviewer verified all 274 payloads and 683 exact source entries, unchanged runtime bytes, exact predecessor compatibility and the corrected real tampering regression. All five published assets were independently downloaded and verified by full bytes, SHA256, GitHub digest, size and attachment headers. Source Server Form CI and Customer Portal Checks pass. No server action has been performed by the assistant.

The user subsequently confirmed all tests and working behavior on 2026-10-13. Anonymous deployed shared CSS/JavaScript match the frozen `201b1888abad` source; this corroborates served assets without reconstructing the missing host transcript or backup path. Financial and connected readiness acceptance are recorded above.

## UX-001 — unified-test-c40236e09375 — 2026-10-09

Status: installed and verified in TEST. Automated checks and independent audit passed; connected UX acceptance remains pending.

Purpose: replace the long form-and-card layouts with a coherent software interface, starting with management Bookings/Booking Review and the Agent Account/Orders pages. The user rejected the current presentation; functional success does not establish UX acceptance.

Implemented components:

- Responsive role navigation with a desktop rail and active section; Corporate charcoal/yellow/off-white/gray palette, Poppins/Inter, separated panels and consistent controls.
- Wider, separated Open Orders and Previous Orders panels with independent 5/25/All pagination. Compact display controls sit below the lists and update automatically, preserving the other group’s page.
- Compact order rows with property address, readable order number, arrival window, amount and clear status.
- Four numbered, non-clickable progress stages; state-selected next action; dedicated action choices that open one existing form at a time. Desktop booking context sits beside the action; mobile summary details collapse while property/window remain visible.
- Focused review, appointment, closeout and billing screens. Recovery details are disclosed when needed.
- Open Onsite Closeout on its selected screen; collapsed overview shortcut; no duplicate Vendor Access section.
- Consistent controls, accessible status indicators, keyboard focus, responsive navigation and mobile layouts.

Design and acceptance contract: [UX iteration brief](documents/ux/ITERATIONS.md).

Validation: customer/staff/vendor/onsite-payment HTTPS journeys passed with synthetic providers and blocked external browser egress. Staff coverage retains all nineteen original form contracts, three external-agent cases, consent/origin/CSRF negatives, unchanged ledgers on navigation, automatic pagination, future-step gating, and stable identity for two saved decline notices. Responsive checks cover 320/390/736/1200/1600. PHP 8.2/8.3 presentation checks: 40 booking-step, 72 account-workflow and 96 shell assertions each. Historical staff/source-layer checks pass. Saved-result recovery remains primary; completed Vendor management and existing Job/Deliverables remain reachable as relevant secondary context.

Deployment preparation pins the exact installed `0ddef26c59a1` predecessor, including its original manifest/schema and independently reproduced package; the new shared script is the sole added deployment file. Exact fixed-commit installation/recovery CI passes 64 cases on each PHP version with zero privileged skips. Full Server Form and Customer Portal CI pass, including all seven browser journeys. Independent audit closed without a blocker. Evidence: [UX-001 review](documents/ux/UX-001_REVIEW.md). No provider/domain/financial transition was changed. CTC’s authenticated workspace could not be inspected; this implements the user’s requirements using the Corporate site’s effective palette.

The user subsequently requested an installable GitHub package and a single download/extract/install command. Package source `c40236e0937546e14ebb50d835e3b911f700d616` includes the test-history corrections with identical application payloads to implementation `40566ba`. Publication requires fresh source-pinned privileged PHP 8.2/8.3, all seven browser journeys and actual FPM checks. Installation remains an operator action; record its result and subsequent UX acceptance separately. Stripe remains TEST.

Published release: [unified-test-c40236e09375](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/unified-test-c40236e09375). Its exact-source publication run `37987873795` passes all five jobs, including 64 privileged cases per PHP version with zero skips, all seven browser journeys, 27 host-protocol tests and eight actual FPM tests. The five assets were independently downloaded and verified by full bytes, SHA256, GitHub digest, size and attachment headers. Package SHA256: `e97785fb54e95b9862a865bdabd0ebb57eec84b60f494b30e15735a2e5bbb2aa`. Installation uses the existing approved WHM-root backup/pause/drain/reload/verify/reopen process; no financial automation or LIVE enablement.

Installation: the operator supplied complete PREFLIGHT PASS, HOST PREFLIGHT PASS, INSTALLED AND VERIFIED, VERIFY PASS and HOST UPDATE PASS output. The preflight checked 273 files with 13 changes, read-only database integrity and the three preserved host variants. Actual PHP-FPM 8.2.34, code refresh, file ownership/modes, backup integrity and TEST gates passed. The existing canonical reconciliation schedule remained unchanged. No deployment database migration, provider operation, configuration/gate change, session reset, worker or callback change was reported.

Backup: `/home/sitesee/.sitesee-real-estate/unified-deployments/unified-test-c40236e09375-7wgcojxh`. Anonymous HTTPS checks confirm both account and staff sign-in pages respond with TEST/no-store, and the deployed shared CSS/JavaScript match the release exactly. This establishes serving assets, not authenticated UX acceptance. The next review is the redesigned agent and management screens on desktop/mobile; do not repeat previously accepted cancellation/payment tests solely for additional evidence.

## FIN-001 — unified-test-8c3ae16c726d — 2026-10-09

Status: installed and verified in TEST. Connected financial acceptance confirmed by the user on 2026-10-13. Stripe remains TEST.

Source: `8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b`. [Published GitHub release](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/unified-test-8c3ae16c726d); package SHA256 `b79effc1e203bbc8d8d8d7c953edf45eb6bc101cafccd0798fbc9ef17429ce7e`. The package contains 274 deployment files, including the new financial module, and 678 exact source entries. Documentation-only updates do not alter this frozen source or imply host installation.

Confirmed policy: management cancellation refunds all collected cash payments, regardless of notice. Customer cancellation at least 24 hours before the confirmed appointment refunds the paid deposit; later cancellation creates deposit credit. Extra customer prepaid payments go to staff review. Credits automatically fund the replacement deposit first, then its balance; unused credit is retained. Cancelling a credit-funded replacement restores its existing credit rather than issuing that value again.

Added an account-owned credit ledger, immutable cancellation/refund outbox, automatic replacement deposit/balance allocation and local Billing status. Gross prices and original payment records remain intact; cash and credit are verified separately. Full-credit deposits use verified payment lineage with fresh card consent, without fictitious Stripe deposit IDs. Unused credit survives cheaper replacements; cancelling a replacement returns its existing allocations.

Refunds use the original saved cancellation time, stable Stripe idempotency keys and exact charge/intent/refund readback. Lost replies, pending refunds, disputes, manual adjustments and aged unknown requests remain pending or require review. Signed late payments wake the original cancellation policy, including payments arriving during refund readback. Fresh account, purchase capability and Vendor authorization are checked again under the credit transaction lock.

The existing account-owned reconciliation worker gains a bounded financial outbox pass; no schedule is added. It can submit TEST refunds for saved, applied cancellations, while retaining its existing no-calendar/no-mail-write behavior. The first authorized cancellation saves the configured TEST key privately for that worker; credentials are excluded from releases, logs and command arguments. Existing cancelled orders are not automatically backfilled. Any financial ledger activity requires repair forward rather than an old-code rollback.

Validation: all 42 PHP suites pass on PHP 8.2 and 8.3, including 87 financial assertions and a real concurrent credit-reservation check. Exact-source publication run `37999782655` passed all five jobs: 65 directly privileged installer/recovery cases per PHP version with zero skips, all seven HTTPS browser journeys, 27 host-protocol checks and eight actual isolated FPM tests. Source Server Form CI, including historical installer ownership, and Customer Portal Checks pass. Node and historical source checks pass. Local Python discovery records 153 cases with 22 unavailable-privilege skips, and local unified release checks record 65 cases with one privilege skip; the corresponding privileged checks passed in CI. The five GitHub assets were independently downloaded and verified by full bytes, SHA256, GitHub digest, size and attachment headers. Four review passes and independent exact-source/package audit are recorded in [the financial review](documents/unified/FINANCE_AUTOMATION_REVIEW.md).

Installation: the operator supplied complete PREFLIGHT PASS, HOST PREFLIGHT PASS, INSTALLED AND VERIFIED, VERIFY PASS and HOST UPDATE PASS output for this exact release. Preflight checked 274 deployment files with 24 changes, read-only database integrity and the three preserved host variants. Actual PHP-FPM 8.2.34, compiled-code refresh, exact files, ownership/modes, backup integrity and TEST gates passed. The canonical reconciliation schedule remained unchanged; no deployment database migration, provider operation, configuration/gate change, session reset, new worker or callback change was reported.

Current installed authority is `unified-test-8c3ae16c726d`. Backup: `/home/sitesee/.sitesee-real-estate/unified-deployments/unified-test-8c3ae16c726d-ln1nwjy0`. The five finance tables initialize on normal application use. Financial ledger activity requires repair forward; do not restore an older operational database. The user confirmed the connected tests and working behavior on 2026-10-13; this supersedes the prior pending financial acceptance. Prior accepted calendar/cancellation notices are retained; no repeat installation is needed.

## Unreleased

### Future release — Production/LIVE

Status: not implemented or authorized for cutover. The user's TEST instruction remains in force.

The installed release rejects a non-TEST application stage and LIVE Stripe credentials. Production support needs a reviewed release, correct production configuration and provider bindings, appropriate validation, a concrete backup/recovery plan and explicit LIVE approval. Changing credentials alone does not create a supported production release. Final acceptance of the new account/Vendor/availability behavior can be included in the UX review; do not repeat already accepted cancellations solely for more evidence.

## unified-test-0ddef26c59a1 — 2026-10-09

Status: installed and verified in TEST. Manager and customer cancellation checks accepted by the user; UX rejected and scheduled for redesign. Broader new account/Vendor/availability connected acceptance is not individually recorded as complete.

Source: `0ddef26c59a1ee10c8d0c8656e9d8e49420ba827`. Application payloads are identical to the superseded b98 candidate; its installer correction has a matching replacement package.

### Added and changed

- Agent and management Open/Previous order columns, independent pagination and 5/25/All display controls.
- Required agent profile before new purchase; Billing navigation; removal of Order Again; state-selected customer screens.
- Active Vendor selection during paid review, atomic review/grant handling and grant visibility after calendar confirmation.
- Onsite Closeout as an open selected step, with no duplicate Vendor Access control.
- Immutable eight-character display order numbers, retaining original internal references and authorization/provider identities.
- Property-address subjects for new outgoing emails; saved messages, drafts and external incoming subjects retained.
- Sunday arrival starts at 1:30, 3:30 and 5:30 PM Central. Blocked actual dates: New Year's, Memorial Day, July 4, Labor Day, Thanksgiving, Christmas, Good Friday and Easter. Other holidays and observed dates were not added.
- Manager cancellation sends its saved customer notice after verified calendar cancellation, with guarded recovery of a proven unsent notice.

### Fixed

- The b98 installer compared the installed predecessor record against the candidate's new schema description. The correction pins and validates the predecessor's actual historical description.
- The predecessor fixture now reproduces the exact published installed package with its own builder/installer instead of copying the candidate's metadata.
- Wrapper failures report known categories and exit status without exposing raw configuration/provider output.

### Validation and installation

- Exact-source privileged installation/recovery: 64 cases on each of PHP 8.2 and 8.3, zero skipped cases.
- Host protocol: 27 cases; actual isolated FPM: 8 cases; all seven browser suite completion markers verified.
- Independent audit closed with no remaining blocker. Published downloads verified by full bytes, SHA256, asset digest, size and attachment headers.
- Operator reported PREFLIGHT PASS, INSTALLED AND VERIFIED, VERIFY PASS and HOST UPDATE PASS; actual FPM 8.2.34 and serving code verified. Backup integrity passed; existing schedules retained.
- Anonymous HTTPS checks: five role sign-in pages respond with TEST/no-store; shared CSS and JavaScript match the release. This does not establish authenticated functional acceptance by itself.

### User acceptance

- Earlier recorded sign-in, owned orders, Vendor/job/Production delivery, TEST payments, approval/decline emails and reconciliation evidence retained.
- Manager cancellation: user reports all checks passed, email received by the client and appointment removed from the calendar.
- Customer cancellation: user reports all checks passed, division email received and calendar cancellation email received. The tested reference was not restated in this latest report.
- Layout/UX: user rejected the current layouts and requested software components and a stepped experience. This is the active next iteration.
- Automatic refunds/credits: not part of this installed release; processing remains manual.

Reviews: [account workflow](documents/unified/ACCOUNT_WORKFLOW_REVIEW.md), [independent account review](documents/unified/INDEPENDENT_ACCOUNT_WORKFLOW_REVIEW.md). Full installation/acceptance evidence and backup location are retained in the project checkpoint outside the checkout.
