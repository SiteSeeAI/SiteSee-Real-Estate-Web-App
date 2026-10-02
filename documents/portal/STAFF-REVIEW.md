# Staff Booking Review release review — 2026-10-02

Release: `staff-review-20261002-r1`.

The branch `feat/calendar-confirmation-20260925` was inspected before editing at `cbc6035812c7875dbc073903c1a421052d910c88`. The final application, package and browser-test commit is `11cf85cbfebf72f24b1934eae667d9c0faf7895d`. Installation on the user's server has not been asserted.

## Four review passes

1. **Scope and source.** Exactly two application files change: `booking-staff.php` and the presentation function in `booking-workflow.php`. The page uses the existing branding and local fonts, a readable booking summary, and native expandable sections. Paid-request review appears before readiness and recovery. Secondary actions and completed records start compact; actionable, blocked and uncertain states open their relevant section. No JavaScript, new workflow or provider operation was added. Recent-request timestamps are formatted in America/Chicago on the server. `staff-review-source.json` pins both files' exact before/after bytes and 21 unchanged dependencies; the before bytes were checked against the remote baseline.
2. **Controls and state.** Automated preservation checks prove that the authentication, CSRF function, POST dispatcher and every POST handler are byte-identical, as are the workflow logic and form builder preceding the changed HTML function. The browser compares original and updated forms across 19 saved states, including names, types, values, required consent, limits, selections, fingerprints and button labels. Existing pricing, rush approval, payment, confirmation, recovery, rescheduling and cancellation authority is preserved. Cancelled, moved and pending-change records cannot offer original confirm/send/resume actions. Private token links retain `noopener noreferrer`.
3. **Installation and recovery.** The installer reuses the portal-polish safeguards: private durable backups, directory and appointment locks, fsynced writes and journal, forward recovery after interruption, exact dependency/source checks, and preservation of unknown edits and file metadata. Historical root-owned backup parents are accepted without ownership or mode changes. The workflow presentation installs before the staff page. Only these two application files, affected entries in five existing release manifests, and backup/journal files can change. PHP runs only with `-l`; installation does not execute application code, alter databases or configuration, call providers or send messages. Stripe must already be TEST. An unfinished earlier update blocks installation.
4. **Runtime and package.** All 22 installer tests and two source-preservation tests passed locally and in CI. Tests cover interrupted writes and final-journal failure, repeat installation, unknown/concurrent edits and permission changes, corrupt backups, manifests and dependencies, symlinks/hardlinks, root-owned backup parents, prior unfinished updates and locks. The installer reproduces exactly from its pinned manifest and template. Historical complete-portal, business-workflow, draft-recovery and portal-polish builders recognize only the exact reviewed upgrade chain and reproduce their original installer bytes. Full PHP, Chromium and visual evidence is below.

## Independent audit and corrections

The independent `calendar_audit` agent reviewed the source diff, forms, visibility conditions, source/dependency pins, payload, template, deployment order, recovery tests and historical builders. Three actionable states initially folded too far were corrected and covered in the browser suite: confirmed but unsent invitations blocked by readiness prerequisites, submitted invitations missing expected communication evidence, and legacy uncertain calendar claims. The final audit found no blockers and independently reran all 22 installer tests plus two source-preservation tests, Node syntax and diff checks.

The first actual staff browser run exposed an inherited header incompatibility: `Referrer-Policy: no-referrer` caused native Chromium form submissions to carry `Origin: null`, which the existing origin guard correctly rejected. The staff page now uses `Referrer-Policy: same-origin`, matching the working customer portal. This keeps cross-origin referrers suppressed without weakening authentication, CSRF or origin validation. Passwords and CSRF values remain in POST fields or cookies, staff navigation carries booking references, private token links retain `noreferrer`, and the separate payment page retains `no-referrer`. The independent agent separately audited this change and the regenerated package and found no blocker. The final browser test uses native login and form submissions without a forged Origin header and verifies that explicit foreign and `null` origins remain rejected.

## Full validation evidence

[Customer Portal Checks run 37023461598](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37023461598), job `110892078832`, completed successfully on `11cf85cbfebf72f24b1934eae667d9c0faf7895d`.

- PHP 8.2 syntax and all eight existing portal suites passed: access, session, purchase, service, draft recovery, release, phone and SMS.
- All seven installer suites passed, including 22 staff tests and 22 portal-polish tests. The historical enrollment suite retained its existing root-only skip on the non-root runner; no new staff test was skipped. Both source-preservation tests passed.
- All six installer builders reproduced their checked-in output.
- Existing portal preview and HTTPS browser suites passed. The new staff suite passed all 19 baseline form contracts, native HTTPS login, CSRF and origin rejection, missing-consent rejection, recovery visibility, Central Time display with a Honolulu device timezone, keyboard disclosure access and layout at widths 320, 390, 736 and 1200.
- Fixture ledgers remained unchanged through page reads, disclosure navigation and rejected submissions. External browser requests were blocked; PHP network functions were disabled and the isolated provider boundary rejected/logged calls. No provider call was observed.
- Fonts loaded; no PHP warnings/fatals or browser page errors were observed. Desktop paid, complete, draft-recovery and request-list screenshots and the mobile rush screenshot were visually inspected. Primary review and recovery controls are visible when needed; completed detail sections are compact and mobile content remains readable without clipping.

The browser uses synthetic records in throwaway databases and a local HTTPS server. These results do not assert fresh real-provider verification, a login to the user's staff account, or server installation. The user-confirmed billing and appointment evidence in `STATUS.md` remains authoritative; no real lifecycle operation was repeated.

## Delivery

Installer: `tools/install-re-staff-review-20261002-r1.py` (72,009 bytes).

SHA-256: `427cb1b274364eeabcc7190fc285027d5a7a41893ffa5c8ea09cd3795a114d0f`.

Upload the single installer to `/home/sitesee/` and run in root WHM Terminal:

```sh
python3 -B /home/sitesee/install-re-staff-review-20261002-r1.py --deploy
```

The same command resumes an interrupted update or verifies an already installed copy. Refresh Staff Booking Review after installation. The prior portal-polish installation was confirmed by the operator; this installer requires that reviewed source baseline.

Preserve active order `5D99D336572661A00885`, cancelled order `8D20B4EBFCD0BADC4DE5`, protected booking `D32FFC7458` and all existing credentials, data and provider settings. Only `sales@re.sitesee.ai` is approved for real test messages. Email processing and the Deleted Items issue remain deferred. Stripe remains TEST; no merge to main or live activation occurred.
