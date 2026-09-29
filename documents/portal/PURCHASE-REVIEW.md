# Guided ordering and TEST deposit source review

Baseline: `e0d160180189573ef4edac0c712daca6b4e12bab`. Verified source/test commit: `107196ec8e6e50e6a0347da3607bb7f7b0a1fa00`. Source-only draft PR 38; not installed. Validation and the additional independent audit are complete for this source package.

## Pass 1 Source and dependencies

The existing public `account.php` includes the private portal controller. This package adds `portal-purchase.php` in server and views, plus public `order.js`, `order.css` and `payment.js`. It changes only the previously added portal controller, mail/view/styles and targeted CI/fixtures/tests. The accepted preview remains unchanged. The source manifest lists exact package hashes and baseline hashes for changed files.

Purchasing calls the unchanged canonical `real_estate_prepare_submission`, residential/commercial quote engines, `booking_capture`, booking ledger, calendar feedback, `booking_checkout_start` / `booking_start_checkout`, existing mail transport and signed webhook processor. Private dependencies remain in their established tree. New authenticated engine responses read exactly two fixed private JS paths. Fonts use the existing three local font assets. Embedded payment uses the existing Stripe dahlia SDK and private TEST configuration. No new package dependencies, prices or provider credentials are introduced.

## Pass 2 Complete content and differences

All 56 recovered original files were compared to their Git blob hashes. Every original application file is unchanged; differences from that early baseline are the already disclosed status and CI changes. The immediate prior account-package bytes are retained in the review workspace and complete unified diffs were generated for all 14 changed/added source and test files. No line-ending normalization is applied to installed application files.

Intentional behavior: authenticated New Order replaces the placeholder; canonical server review creates a private durable submission; capture and owner binding are separate transactions, recoverable using a reserved random reference and exact submission hash. The adapter establishes its own server-only payment capability before publishing ownership. Account identity, amounts and paid state are never taken from browser fields. Duplicate POSTs recover the same reference. Staff/customer notice handoff is at-most-once; failed or ambiguous mail needs staff review and is never automatically replayed. Existing CRM linkage stays in staff review. Order Again copies only property and service selections; historical quotes without original input quantities require quantity review. Checkout reuses established provider idempotency/state and adjusts only return URLs to the owned portal. Browser returns read local payment evidence only. Staff/rush/calendar approval remain separate.

## Pass 3 Entrypoints configuration jobs and permissions

Both portal and booking TEST flags remain required, default off. HTTPS, secure session, active-account checks, CSRF and same-origin POST protection apply to all purchase actions. Order and payment reads use ownership checks; unavailable and foreign references fail closed. Private SQLite retains existing 0600 file protection and session storage remains outside public root. POST size is raised from 8 KiB to 64 KiB for the complete order; profile/login field limits remain unchanged. Review has account-based limits and a 30-minute expiry. Checkout accepts only established TEST credentials/configuration. CSP opens Stripe sources only on the payment view when embedded configuration is enabled. No public JSON/config/data files, new cron, admin route, subscription or live payment is added. Tests block provider transports and browser egress.

## Pass 4 Package and validation

The branch tree preserves all unrelated paths. The manifest covers all 14 added/modified source and test files with final SHA-256 hashes and baseline hashes where applicable. No installer or partial upload is supplied. CI 36578888924 passed syntax, account/session checks, explicit purchase assertions, unchanged preview tests and actual HTTPS browser journeys. Original protected synthetic records were unchanged. Desktop and mobile screenshots were visually inspected.

The independent agent verified dependency coverage, original application byte preservation, declared differences, ownership/capture recovery and payment boundaries. Its four findings—base CSP fetch permission, staff notice URL, CSRF refresh after reauthentication and mailing default—are resolved and re-reviewed, with no remaining source blocker. Browser tests also exposed the numeric change/Next interaction and now verify the correction. The stale-CSRF recovery branch is explicitly exercised with retained answers. The final manifest was refreshed after corrections.

Detailed log review caught an early exit in the initial purchase unit-test setup. The isolated origin was configured and a shutdown completion guard added; final logs explicitly report purchase PASS. Initial job-success summaries do not prove those assertions ran and are superseded by this final evidence.

Tests use two synthetic customers and local mail capture. Purchase tests inject mock provider adapters; the HTTP harness blocks PHP provider transports and nonlocal browser requests. No actual Stripe session, charge, subscription, provider calendar/CRM write or customer notice was created. TEST-provider/SDK behavior and actual inbox delivery must still be verified after complete integration. Balances, billing, owned appointment adapters and installation remain outstanding.

Operational recovery: a submit retry uses its durable review ID to recover the same order. Missing/ambiguous mail handoffs (`sending` or `review_required`) require staff reconciliation; never replay notices automatically. A server pricing-secret rotation or staff replacement of this adapter's payment capability fails closed and needs staff assistance. Repeated checkout uses the existing ledger/idempotency key; only signed payment events record paid status.
