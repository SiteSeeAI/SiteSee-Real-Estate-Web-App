# FIN-001: automatic cancellation refunds and replacement credits

Status: exact source `8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b` reviewed, tested and published as `unified-test-8c3ae16c726d`, not installed on the host. The installed predecessor remains `unified-test-c40236e09375`. Stripe stays TEST. Operator installation and connected TEST acceptance remain separate evidence.

The package's review file is an immutable preparation snapshot. This updated document and CHANGELOG record the final publication evidence without changing the frozen application payloads.

## Confirmed policy

| Cancellation | Cash disposition |
| --- | --- |
| SiteSee management | Refund all collected booking payments, regardless of notice, before completed onsite work. |
| Customer, at least 24 hours before confirmed appointment | Refund the paid cash deposit. |
| Customer, under 24 hours | Give the cash deposit as account credit. |
| Customer with extra prepaid balance | Route that extra payment to staff review; apply the deposit policy independently. |

Credit automatically funds the next authenticated replacement booking's deposit first, then its remaining approved balance. Unused credit stays on the account. Credit used by a subsequently cancelled replacement is restored, without minting new value or refunding its original source payment again. The elapsed-time rule uses the saved cancellation request timestamp and confirmed calendar start, so retries and daylight-saving changes do not alter the decision. Previously cancelled orders are not automatically backfilled.

## Review 1: dependencies and call paths

The calendar cancellation state machine still verifies the owned event before committing an applied cancellation. That commit saves the financial outbox beside the existing lifecycle operation. Staff, authenticated customer and retained signed management endpoints attempt their original cancellation notice before processing finance, so slow Stripe calls do not prevent the notice. No callback URL, signed-link identity, scheduling command or role/session format changes.

The existing reconciliation worker independently selects unfinished financial outbox rows, even after their calendar date has passed. A separate bounded financial pass prevents calendar reconciliation from consuming the entire budget. Existing calendar and email provider writes remain excluded from the worker. Saved cancellation TEST refund POSTs are the explicitly requested new capability; deploying the code itself performs no provider operation.

The first authorized cancellation POST writes the configured TEST key to the account-owned private `booking-finance-key.json` using exclusive creation and restrictive permissions. CLI recovery checks regular-file type, owner, link count, size, private mode and TEST prefix before loading it. Unknown key rotation is refused. The file is excluded from package payloads, public paths, logs and arguments. No secret is embedded in source or fixtures.

## Review 2: source, ownership and financial invariants

Five additive finance tables retain cancellation decisions, immutable refund requests, grants, allocations and separate replacement funding. Original prices, internal references, payment rows and provider IDs are retained. Full-credit deposits keep their Stripe deposit IDs null and use verified source customer/card lineage with fresh consent when later cash collection is required.

Cash proof binds the TEST checkout, intent and captured undisputed charge to the exact reference, amount, currency and customer. Refund proof additionally binds charge, intent, amount and immutable operation metadata. A refund is confirmed only after succeeded status and matching charge readback. Stripe Refund objects do not expose `livemode`; TEST provenance is established by the original intent/charge and TEST transport credentials.

Refund creation uses one durable key and a saved first-submission time. A lost result is recovered by exact refund-ID or charge-list readback. An aged submitted request without conclusive evidence never gets a new key or POST. Existing external refunds, disputes, failed or uncertain provider state require review. Customer extra prepayments are held for staff without blocking the separate deposit disposition.

Stable portal order ownership governs grants and spending. Source funds are reverified before use, and account status, purchase token/capability and Vendor authorization are rechecked under `BEGIN IMMEDIATE`. Available capacity excludes reserved/used allocations. Concurrent reservations cannot overspend. Only positive-remaining grants are checked when creating new funding; exhausted adjusted credits cannot block unrelated cash purchases.

Signed paid balance events durably wake the original cancellation inside the payment transaction. Financial completion rechecks the current paid ledger, refund states, unapplied balance attempts, owner, completed-job guard and cancellation identity under a write lock, so a late payment cannot lose its recovery wake-up. Verified unpaid checkout expiration restores held credit and retires that reduced-price purchase capability. Duplicate paid events after cancellation cannot spend restored credit again.

`finance-source.json` preserves exact before bytes/hashes from the installed `c40236e0937546e14ebb50d835e3b911f700d616` source and reviewed after bytes/hashes. Historical builders reverse this layer before the UX and earlier layers and reject unknown edits. Existing historical source and receipt evidence is retained.

## Review 3: automated and browser parity

All 42 PHP suites pass locally on PHP 8.2 and PHP 8.3. The finance suite includes 87 assertions covering management/all-cash refunds, the 24-hour boundary, credit ownership, concurrent reservations, partial/full credit, unused value, increased final scope, verified card lineage, lost/aged refund replies, pending/failed status, disputes, manual adjustments, late payment races, checkout expiry and private worker credentials.

The existing seven synthetic HTTPS browser journeys cover customer, staff, job, Vendor, email, preview and calendar notice behavior with external browser egress blocked. Billing is checked after cancellation and paid balance. Unit finance dependencies and disposable browser refund boundaries fail closed; test credentials never select real provider operations. Existing authorization and TEST gates remain intact.

Node unit checks pass. Historical source-layer checks and local Python installer/recovery checks pass within available privileges. Local privileged ownership cases are skipped and must pass with zero skips on the source-pinned GitHub runner before publication. Twenty-seven host protocol checks and eight tests using actual isolated PHP-FPM pass locally.

## Review 4: installation, interruption and preservation

The candidate installer accepts the exact published predecessor archive, manifest and its historical schema description, rather than candidate-derived metadata. The predecessor package SHA256 is `e97785fb54e95b9862a865bdabd0ebb57eec84b60f494b30e15735a2e5bbb2aa`; its manifest SHA256 is `31d25002c7ec2bc1a5ddf488f58bed50b0135e653cae1f17692423976053378d`.

Deployment retains the existing backup, preflight, maintenance/drain, PHP-FPM refresh, install, exact verify and reopen process. Reflection verifies the new finance functions in actual FPM. No operational database migration, refund, credit grant, configuration/gate change or new schedule is performed by deployment. Tables are added during normal application use.

Any rows in any of the five financial tables block restoration of older application code. Preserve the operational database, WAL, provider operations, private key and deployment journal, then repair forward. An isolated backup restore rehearsal is permitted; reverting the operational ledger after money or credit activity is not. Existing deployments, backups and host-specific preserved files stay intact.

Independent findings, reproductions and corrections are recorded in [the independent finance review](INDEPENDENT_FINANCE_AUTOMATION_REVIEW.md).

## Frozen release evidence

Publication run [37999782655](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/37999782655) completed successfully with all five jobs and every step passing. Each job checks out exact source `8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b`. Directly privileged installer matrices completed 65 cases on each PHP version with zero skips; all seven browser completion markers, 87 finance assertions, 27 host protocol cases and eight actual FPM cases were checked in the authentic CI archive. Exact-source Server Form CI, historical privileged installer ownership and Customer Portal Checks also passed.

The independent reviewer compared all 274 deployment payloads and 678 source entries with Git, validated the loader/predecessor, reran 87 finance assertions on PHP 8.2/8.3, checked actual declarations, and exercised all five financial repair-forward guards with unchanged database bytes. No remaining source, build or compatibility blocker was reported.

All five GitHub assets were independently downloaded and verified against local bytes, SHA256, GitHub digest, size and attachment headers. [Package download](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/download/unified-test-8c3ae16c726d/unified-test-8c3ae16c726d.tar.gz), SHA256 `b79effc1e203bbc8d8d8d7c953edf45eb6bc101cafccd0798fbc9ef17429ce7e`. Installer SHA256 `5ed5bdf8a50d2f970044a892207d9c964132c34b77891bfa0e61db0aed5a2218`; root wrapper SHA256 `c6da86e9ecff3b57e126813a47f5ef1f698840e243022a5065a8f587eeb21d35`.

## Connected TEST acceptance after installation

Use approved TEST bookings only. Verify management cash refund, customer deposit refund at least 24 hours out, late customer deposit credit, automatic replacement deposit/balance use with unused value retained, and customer extra-prepayment staff review. Check Billing against Stripe's TEST refund records. Existing accepted calendar cancellation and notice checks need repetition only if these new scenarios reveal a regression. The existing worker log should report financial outbox processing without calendar/mail writes. The user has not yet accepted these connected finance scenarios.
