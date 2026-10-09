# Changelog

This is the iteration record from October 9, 2026 onward. Dates use America/Chicago. Earlier immutable releases and reviews remain historical evidence; this file does not reconstruct dates or acceptance that were not recorded.

Each iteration records its ID, purpose, status, source/release, changes, validation and user acceptance. Keep planned work separate from implemented, installed and accepted work. Add an entry for each subsequent implementation/release and update its acceptance after user review. Documentation-only commits do not change the installed application.

## Unreleased

### UX-001 — Application workspace

Status: implemented locally; targeted automated checks passed; visual acceptance and installation pending. Stripe remains TEST.

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

### Future feature — Refund and credit automation

Status: feasible, not implemented or enabled. The earlier yes/no answer confirmed feasibility.

Current cancellation processing does not automatically refund a payment or apply a credit. Automation needs a defined policy for customer versus manager cancellations, eligibility, amounts, credit use, duplicate protection, failed/uncertain responses and financial records. Record a separate implementation and TEST acceptance iteration if this work is requested.

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
