# Portal payment polish review — 2026-10-02

Release: `portal-polish-20261002-r1`.

Inspected branch: `feat/calendar-confirmation-20260925`, starting at `45f7892d79b7465726ddc93d7a964cb099375eec`. Application and package changes are committed at `357faa0079e1eb2a8c017314bfe2af048bb44dd5`; historical builder compatibility and CI path coverage finish at `1648041604ce4b43c0f0c4a94ba659929bdbedf2`. This is a presentation update, separate from the later Staff Booking Review redesign.

## Four review passes

1. **Scope and source.** Exactly four application files change: the portal view, its CSS, the owned-order display projection, and one staff-page sentence. The source manifest pins the exact before/after bytes. Before bytes were checked against the remote baseline. The calendar adjustment, phone sign-in, checkout, webhook, provider adapters and credentials remain unchanged.
2. **Payment and ownership.** My Orders reads the existing recorded-balance ledger only for orders returned by the ownership query. Order Details reuses the existing controller's paid balance and remaining amount. The label says “Test Balance Recorded”; it does not claim an unrefunded payment. Approved rush fees are displayed from the existing scheduling record. The single payment link sits beside the amount due and opens the existing deposit or balance review route. Consent, eligibility, price calculation and server validation remain authoritative. The staff text describes the approved balance after deposit rather than asserting a current net amount due.
3. **Installation and recovery.** The installer reuses the proven calendar installer safeguards: private backups, directory and appointment locks, durable journal and writes, forward recovery after interruption, exact source/dependency checks, and preservation of unknown edits and metadata. Existing root-owned historical backup directories are accepted without ownership changes. CSS and the display field install before the new view. Only the four application files, affected entries in existing release manifests, and new backup/journal files can change. Installation runs PHP syntax checks only; it does not execute application code, modify databases, call providers, send messages or change configuration. Stripe must already be TEST.
4. **Runtime and package verification.** Local source checks, Node syntax, builder reproduction and all 22 new installer tests passed. Full PHP and isolated browser evidence is recorded below. No real account login or payment was submitted by the agent.

## Independent audit

The independent `calendar_audit` agent reviewed the application diff, source pins, generated payload, deployment order, historical builders, recovery tests and browser fixtures. It independently ran all 22 installer tests and found no blockers. After CI identified the draft builder's stale source expectation, the agent separately verified the exact historical-to-polish source chain and confirmed the historical draft installer remained byte-identical. No application or installer payload changes were needed for that correction.

The complete portal, business workflow and draft recovery builders retain their original installer bytes. Their source checks recognize only the exact reviewed upgrade chain. CI now also runs when a draft builder changes.

## Full validation evidence

[Customer Portal Checks run 37019286163](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37019286163), job `110877889582`, completed successfully on `1648041604ce4b43c0f0c4a94ba659929bdbedf2`.

- PHP 8.2 syntax checks and all eight existing portal suites passed: access, session, purchase, service, draft recovery, release gate, phone and SMS. These include payment ownership, explicit consent, approved amounts, signed webhook race/replay, refunds/disputes and interrupted payment recovery.
- All 22 polish installer tests passed both locally and in CI. All five existing installer suites also passed; the enrollment suite retained its one root-only skip on the non-root runner. No new polish test was skipped.
- All five installer builders reproduced their checked-in output, including the historical draft recovery installer. The first CI attempt stopped on the old draft builder's strict source expectation; the final run validates the correction and every subsequent step.
- Both Chromium preview and actual HTTPS portal suites passed. New checks cover the $59 approved rush fee, exactly one eligible payment action in the Payment panel, keyboard navigation to the unchanged consent page, open checkout versus recorded payment, zero remaining balance, removal of paid/cancelled payment actions, My Orders status and account isolation. Residential and Commercial orders both retain their deposit route. Existing rescheduling and cancellation checks passed.
- Responsive checks passed at widths 320, 390, 736 and 1200. The reviewer visually inspected the unpaid desktop and recorded-balance mobile screenshots from the successful run: the balance action is directly beneath the amount due, labels and amounts are readable, and the paid mobile view shows $0 remaining without a payment button.

The HTTP fixture uses throwaway databases and synthetic provider boundaries with external requests blocked. Its accepted balance event exercises the unchanged event handler; signature verification is separately covered by the existing PHP suite. This evidence is not a claim of fresh real-provider testing or installation on the user's server.

## Delivery

Installer: `tools/install-re-portal-polish-20261002-r1.py` (73,254 bytes).

SHA-256: `d6babf04d83da0a27c9e6ec79700ed3613dd0361c90e51617f9106c99e87b905`.

Upload the single installer to `/home/sitesee/` and run in root WHM Terminal:

```sh
python3 -B /home/sitesee/install-re-portal-polish-20261002-r1.py --deploy
```

The same command safely resumes an interrupted update or verifies an already installed copy. Refresh My Orders after installation. Server installation is operator-run and is not asserted by this review.

The installed TEST billing checkpoint in `STATUS.md` remains the evidence for the actual provider journey. Preserve active order `5D99D336572661A00885`, cancelled order `8D20B4EBFCD0BADC4DE5` and protected booking `D32FFC7458`. No appointment lifecycle repeat is required for this display update. Only `sales@re.sitesee.ai` remains approved for real test messages. Email processing and the Deleted Items issue remain deferred. No merge to main or live activation occurred.
