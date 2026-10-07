# Unified application: bootstrap milestone

Project brief: [CODEX_HANDOFF.md](../../CODEX_HANDOFF.md). Baseline: `ff5e625a8ff4c51af336e9203fa4be29b3611570`. Working branch: `feat/unified-server-app-20261007`. This is the first implementation milestone; the consolidated release and server acceptance remain pending.

The unchanged package and complete Git history were verified before coding. The unchanged PHP baseline completed 31 of 36 suites successfully and reproduced all five recorded inherited failures. Their corrected fixtures now use the approved primary TEST mailbox, complete Graph envelopes, and the exact historical files recorded by the release manifests. Original wrong-sender, replay, uncertain-operation, integrity and concurrency assertions remain active. Historical files have Git commit and SHA256 provenance beside them. Historical installer payloads and deployment manifests retain their original bytes.

Seventeen public PHP aliases now use a shared loader and explicit route registry. Existing handlers keep their own authentication, sessions, CSRF, ownership, transaction checks and provider state machines. The private root defaults to `/home/sitesee/.sitesee-real-estate`; a trusted server environment may set `SITESEE_APPLICATION_PRIVATE_ROOT` for development or an isolated deployment. Private source inside the document root and invalid paths fail closed. `SITESEE_APPLICATION_STAGE` defaults to TEST and accepts only TEST. LIVE Stripe key prefixes are rejected; the existing Stripe configuration and signature guards remain active. The physical private portal release flag still overrides inherited portal environment flags.

The existing customer, vendor and staff page shells remain in use. Presentation consolidation is the next application slice. No URL, signed-link argument, signing secret, database schema, provider identity, pricing function, commission formula or authentication model was changed. [ROUTES.csv](ROUTES.csv) maps retained handlers, including the standalone public clock and the loader's direct-request denial. [BROWSER_HANDLERS.csv](BROWSER_HANDLERS.csv) indexes source event-binding sites for subsequent shell review. [preserved-source.json](preserved-source.json) records 248 unchanged public, domain, view and pricing asset files against the pinned baseline.

## Review passes

1. **Dependencies and routes.** Each alias loads the same private handler as its original source. Customer/vendor physical release checks and staff Production/Vendor flags remain separate. The new loader opens no database, starts no session and invokes no provider. The public clock remains independent.
2. **Source and financial invariants.** All existing domain modules, views and pricing/public assets remain byte-identical. The source-change manifest retains both original and reviewed route bytes with hashes. Historical builders validate current reviewed bytes before retrieving their original inputs. Frozen historical fixtures were matched to Git and existing installer/manifest bytes, rather than replacing expected release hashes with current hashes.
3. **Automated and browser parity.** The result table below records completed tests. The bootstrap contract tests derive expected handlers and flags from original route bytes, independently of the new registry. Existing HTTPS suites exercise separate identities, ownership, original staff forms, exact pricing, saved-card recovery, paid-delivery gates, and 320/390/736/1200 layouts with synthetic providers and blocked egress.
4. **Deployment and preservation.** This change introduces no schema migration or provider replay. Deployment must install private bootstrap and public loader before activating aliases. A missing private bootstrap, invalid root or invalid stage returns a generic 503; direct loader access returns 404. Host installation/interruption, SQLite WAL backup/restore, worker/webhook ownership and repair-forward rehearsal belong to the consolidated installer gate and remain pending. Incremental historical installers are recovery evidence, not instructions to install this milestone.

## Validation

| Check | Result |
| --- | --- |
| PHP 8.2 application suites | 37 completed and passed, including all five inherited failures and the new bootstrap suite |
| Bootstrap contract | 87 checks passed |
| Node 24 tests | 109 passed |
| Portal/calendar Python scripts (`*.test.py`) | 15 suites, 292 cases, 291 passed and one privileged ownership case skipped |
| Historical Python discovery (`*_test.py`) | 153 cases, 131 passed and 22 privileged ownership/cron cases skipped |
| Historical builders | All 19 reproduce original installer and deployment-manifest bytes |
| Playwright 1.58.2 HTTPS/browser suites | All seven completed and passed |
| Syntax | 183 PHP, 22 JavaScript, 18 CommonJS and 105 Python files passed |
| PHP 8.3 general matrix | 37 PHP suites passed; historical Python completed 153 cases, with 131 passed and 22 privileged cases skipped |
| Privileged CI and server-connected TEST acceptance | Pending |

This cloud filesystem rejects root ownership changes and user-namespace mapping. The installer ownership guards remain intact. Tests requiring real root-owned cron files report a capability skip here; payload reproducibility tests execute independently of that prerequisite. `Historical Installer Ownership CI` was added to the preserved Server Form workflow. Its runner executes the original historical discovery and portal enrollment suite with real privileges and fails on any skipped case or empty/unsuccessful run. Its execution is required before release acceptance; local skips are not evidence that host ownership checks passed.

The local logs are under `/workspace/.sitesee-onboarding/unified-baseline/`. Installer tests and builders run in a disposable source copy to keep historical generated files unchanged. [TEST_RESULTS.json](TEST_RESULTS.json) records command outcomes and log digests, with separate selections for final evidence, unchanged baseline and diagnostic history. The first PHP 8.3 build lacked the test-only `pcntl` extension; after building that extension from the verified official PHP source, the affected concurrency suite completed successfully. Connected credentials, real records and host-installed private bridges are not in the source package.

[INDEPENDENT_REVIEW.md](INDEPENDENT_REVIEW.md) records the independent audit and resolved findings. Its review covers this bootstrap milestone; it does not approve deployment or substitute for the remaining release gates.

## Next gates

Continue the shared page shell with role-specific navigation and unchanged forms; assemble a release from a fixed reviewed commit with a source/test manifest; implement one preflight/install/resume/verify command with verified SQLite backup/restore and code rollback/repair rules; complete four reviews and an independent audit of that implementation; provide isolated server TEST acceptance before presenting a cutover.

The operator reports cPanel/WHM and PHP 8.2. cPanel Terminal availability is not yet confirmed. A separate HTTPS staging hostname, document root, private data/configuration paths and exclusive worker/webhook ownership remain unverified. Staging must explicitly isolate the booking database, sessions and callbacks; changing only the source-root variable is insufficient. Keep current server records and backups, including the protected references in the brief, throughout that work.
