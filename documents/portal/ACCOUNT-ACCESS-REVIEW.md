# Account access source review

2026 09 29. Baseline `cc72f3088c161bb8e6a5266c8c73d11973d180c3`. This is a source checkpoint on draft PR 38, not a server installation.

## Pass 1 Source and dependencies

Recovered 56 relevant baseline files from the pinned commit. Every recovered byte sequence matched its Git blob SHA before editing. The account entry point includes the private portal controller. The controller loads the existing booking ledger and management-token verifier through `portal-orders.php`, the unchanged identity primitives through `portal-access.php`, and new session, mail and view modules. The existing ledger loads the canonical pricing validator and additive scheduling/lifecycle schema. Literal private server includes resolve in the recovered source set.

Approval reads the stored approved pricing-lead JSON files under the existing private root. Returning accounts use the persistent account record. Login delivery calls the existing `real_estate_send_mail` transport; it does not call booking invitation or communication replay functions. Historical linking calls the existing payment/management token verifiers and requires the authenticated email as a second proof. Order queries start from explicit ownership records, use prepared statements and return a field allowlist.

## Pass 2 Complete content comparison

All existing application PHP, pricing engines, views and preview source retain their exact baseline Git blob hashes. The only changed baseline executable configuration is `.github/workflows/customer-portal-ci.yml`: it now watches the added routes/assets/fixtures, lints portal files, runs session tests and runs the isolated HTTP browser suite. There are no rate, booking, payment, webhook, mail transport, calendar, credential, installed-file or cron replacements. New files add the account controller, session controls, approval/ownership/profile adapters, login email template, rendered views, public route/assets and isolated test fixtures.

Changed content is intentionally new implementation; it is not described as byte-equivalent to old source. STATUS.md is separately updated to reflect the accepted 2½–6-day window and current implementation evidence. No consolidation occurred.

## Pass 3 Entry points and configuration

`public/account.php` includes `/home/sitesee/.sitesee-real-estate/server/portal-app.php`. Public CSS and JavaScript are under `public/portal-assets`. The controller requires both `SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED=1` and the existing booking TEST flag. The new gate defaults off. HTTPS is required; production code does not trust forwarded headers. Existing pricing site URL and secret remain required. New private SQLite tables hold account profiles and the existing portal ownership/challenge data. PHP sessions use a dedicated private 0700 directory, strict cookie-only IDs, Secure/HttpOnly/Lax host-only cookies, sign-in rotation, 30-minute idle and 12-hour absolute expiry, and authenticated-account revalidation. POST requires CSRF and same-origin checks; logout destroys the session. Token exchange requires a deliberate POST; GET/prefetch never consumes it.

No installer, cron task or live deployment is included. The CLI-only fixture preparation and CLI-server router are test assets, not deployment files. The next complete deployment package must explicitly include only production assets and preserve private/public placement, ownership and rollback.

## Pass 4 Final source package verification

The 13-file implementation commit `5697b9f36b074d477f938b331e90e6e66d3bc62a` was built from the pinned baseline tree and fast-forwarded only after rechecking remote HEAD. No force update occurred. Local JavaScript syntax checks passed. PHP 8.2 syntax, primitive and session checks passed in targeted CI; browser results are recorded in STATUS.md when complete. The isolated browser suite seeds two approved synthetic customers and three synthetic bookings, captures mail locally, blocks provider traffic, tests both existing ownership proof adapters, compares booking/payment/scheduling tables before and after, and checks four viewport widths. Final changes and any audit fixes must be included in a subsequent fast-forward checkpoint with fresh targeted CI evidence.

## Independent audit

The additional independent agent reviews these four passes, the complete added account path, tests and unchanged-source evidence. Record any findings and their resolution here before treating the package as reviewed. This audit does not authorize installation or payment-mode changes.
