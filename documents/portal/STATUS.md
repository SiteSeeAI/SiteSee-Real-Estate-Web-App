# Customer portal implementation status

Updated 2026-09-28. Working branch: `feat/calendar-confirmation-20260925`; draft PR #38. Foundation inspected at `faf46ff27f604edeb8ddaaba35258b147d1be713`.

## Completed cleanup

The server report confirms 16 obsolete deployment files (917,302 bytes) moved to `/home/sitesee/.sitesee-cleanup-quarantine/20260928T212040279138Z`. Nothing was permanently deleted. The six superseded installers and the old communications upload folder were cleared; 297 protected static application and recovery files were unchanged. All 72 active release checks, 41 backup hashes, and six targeted PHP syntax checks passed. Cron configuration was unchanged and the service active. No booking database, calendar, mail, CRM, or payment operation was performed.

Recovery manifest: `manifest.json` in that quarantine directory. Restore only if needed:

```sh
python3 -B /home/sitesee/.sitesee-cleanup-quarantine/20260928T212040279138Z/restore-cleanup.py --restore /home/sitesee/.sitesee-cleanup-quarantine/20260928T212040279138Z
```

The current r4 recovery tool, diagnostics, credentials, booking data, logs and usable rollback backups were retained. Quarantine does not reclaim disk space. Future downloadable root-generated reports must be owned by `sitesee:sitesee`, mode 0600, outside the public directory.

Appointment modification and cancellation validation remains complete. The successful reconciliation checkpoint at 20:10:05 UTC is authoritative. Direct Microsoft calendar editing is outside the supported workflow and is not a remaining prerequisite. Protect booking `D32FFC7458`; do not resend existing notices.

## Current deliverables

- `documents/portal/preview.html`, `preview.css`, `preview.js`: interactive design preview with example records, guided residential/commercial ordering, preserved answers, canonical pricing engines, review/edit links, and distinct payment/appointment states. No API calls or customer records. This is not a live portal.
- `_private/server/portal-access.php`: private, unwired account primitives for hashed one-time email tokens, persistent approved accounts, throttling, disabled accounts, and historical ownership requiring both verified email and existing token authority. No public route includes this module; nothing is installed on the server.
- `tests/portal-access.test.php`: isolated in-memory identity and authorization checks. No booking, calendar, mail, CRM or Stripe API calls.
- `tests/portal-preview.browser.cjs`: optional Playwright checks of navigation, retained answers, real calculator totals, dependency errors, escaped user input, and responsive layout.

The source comparison found that the investigated deployment/repository hash differences were exactly explained by CRLF versus LF line endings. Keep server bytes unchanged. No existing application, installer, cron or webhook source is changed by this checkpoint. No consolidation or replacement package is proposed here.

## Checkpoint verification

GitHub Actions run [36487880589](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36487880589) passed on commit `62b176407ed6f05efc8333ebfaeabec489abfc61`: PHP 8.2 syntax and account isolation tests, plus Chromium preview interaction and 320/390/736/1024-pixel overflow checks. This validates the unwired primitives and design preview, not the unfinished HTTP/session/payment integrations. Existing application files were checked against the baseline Git blob hashes before the additive commit and were unchanged.

## Screen flow

My Orders is the landing screen. Current orders and past orders/receipts share one history location. An order opens its details, appointment status, eligible appointment actions, payment status and documents. Account holds sign-in and profile controls. The header carries one gold New Order button.

New Order progresses through property type, property details, services and pricing, scheduling and access, and review. Applicable test payment and confirmation follow. Next and Back retain answers. Review links return to the relevant screen. Order again preloads reusable choices and must clear dates, consent and sensitive access instructions before a new submission.

The body uses neutral surfaces, Poppins headings and Inter text. Hick’s Law informs one primary action per task; Jakob’s Law informs familiar order history and checkout conventions. Related inputs are grouped, optional access fields appear only when needed, choices have large labeled targets, and progress is explicit. The preview preserves existing calculator behavior rather than reimplementing rates. Reference: https://lawsofux.com/ .

## Integration still required

| Area | Existing foundation | Remaining work |
| --- | --- | --- |
| Identity | Approved pricing leads, secure PHP sessions, server mail transport | HTTP routes, CSRF and session lifecycle, login delivery/consumption, profile persistence, timing/abuse review |
| Order ownership | Booking references and valid payment/management token verifiers | Trusted claim adapter, new-order account binding, staff reconciliation for ambiguous history, owned-only queries/documents |
| New orders | Canonical PHP/JS pricing, required fields, availability and booking capture | Production wizard, exact server payloads, durable submission idempotency, retry-safe ownership binding, existing communications and CRM integration |
| Test deposits | Hosted/embedded Stripe checkout, signed webhook verification, saved customer/payment IDs | Authenticated portal adapter and payment return/status screens |
| Balances | Existing approved amounts, deposit totals and rush calculations | Balance ledger, eligible collection, duplicate-proof sessions, signed webhook processing, adjustments/refund presentation |
| Billing | Stripe customer IDs on bookings | Verified customer mapping, restricted TEST billing portal configuration, saved methods, billing address, owned invoices and receipts |
| Appointments | Completed SiteSee change/cancel, notice history, reconciliation | Owned authenticated adapters and targeted integration checks only |
| Delivery | Existing private/public deployment layout and recovery tools | One versioned installer/upload, manifests, rollback, operational checks and final operating guide |

Never attach orders or Stripe customers solely by matching email. Reuse established verifiers and require explicit ownership records. Never accept account IDs, approval flags, customer IDs, document URLs, final prices, or payment status from an untrusted request. Customer-facing responses must not reveal whether another account or order exists.

Stripe remains TEST. Deposit receipt is distinct from staff price approval, rush approval and confirmed calendar state. No subscriptions, administrative controls, pricing changes, live keys or automatic balance charges are authorized. Existing signed-webhook verification must remain the payment authority. A Stripe browser return is not proof of payment. A cancellation is not proof of a refund.

## Remaining estimate and release gates

The earlier 2½-day target was a compressed planning assumption. With account access, ownership binding, the production wizard and balance/billing integration still outstanding, budget roughly 4–7 further active working days. This is an engineering estimate, not an elapsed-time promise; server handoff and test feedback can extend calendar duration.

Before release: test two isolated customers; expired/reused links and logout/session expiry; both pricing paths and their server parity; durable submission/payment retries; only eligible payment/appointment actions; owned invoice/receipt access; mobile keyboard use; and rollback. Carry forward completed booking evidence and test only changed integration paths. No full booking validation cycle is required because obsolete uploads were quarantined.

If a later package replaces working source, complete the four requested source/dependency/content/entry-point/package verification passes and an additional independent agent audit before proposing installation. Use hashes for unchanged files and document every intentional changed byte. Do not merge main or delete branches. Staff Booking Review redesign follows completion of the customer portal.
