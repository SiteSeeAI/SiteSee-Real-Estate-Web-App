# Changelog

This is the iteration record from October 9, 2026 onward. Dates use America/Chicago. Earlier immutable releases and reviews remain historical evidence; this file does not reconstruct dates or acceptance that were not recorded.

Each iteration records its ID, purpose, status, source/release, changes, validation and user acceptance. Keep planned work separate from implemented, installed and accepted work. Add an entry for each subsequent implementation/release and update its acceptance after user review. Documentation-only commits do not change the installed application.

## Unreleased

### UX-001 — Application workspace

Status: design scope defined; implementation and visual acceptance pending. Stripe remains TEST.

Purpose: replace the long form-and-card layouts with a coherent software interface, starting with management Bookings/Booking Review and the Agent Account/Orders pages. The user rejected the current presentation; functional success does not establish UX acceptance.

Planned components:

- Consistent application navigation, page header and compact action toolbar.
- Open Orders and Previous Orders in separate columns with independent 5/25/All pagination.
- Compact order rows with property address, readable order number, arrival window, amount and clear status.
- Booking detail workspace with a concise summary, visible progress and one primary next action appropriate to the saved state.
- Focused review, appointment, closeout and billing screens. Recovery details are disclosed when needed.
- Open Onsite Closeout on its selected screen; collapsed overview shortcut; no duplicate Vendor Access section.
- Consistent controls, accessible status indicators, keyboard focus, responsive navigation and mobile layouts.

Design and acceptance contract: [UX iteration brief](documents/ux/ITERATIONS.md).

Validation: relevant existing browser/workflow checks after implementation; visual review at 320/390/736/1200 widths; user acceptance before a server update. Previously passed functional checks remain evidence for their exact releases; the redesign requires checks for its changed presentation and interactions.

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
