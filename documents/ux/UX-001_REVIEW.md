# UX-001 application workspace review

Implemented and installed October 9, 2026. The operator reports HOST UPDATE PASS for `unified-test-c40236e09375`, with Stripe TEST. Connected user screen acceptance remains separate from installation and automated validation.

Application source: `40566ba556f92b9114cfd8b696ca39842fed7f97`. Follow-up `c40236e0937546e14ebb50d835e3b911f700d616` changes only the historical test helper and CI checkout depth. Its application, builder, installer and reviewed source-manifest bytes are identical to the implementation commit.

Download [the six-screen desktop/mobile review](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/download/ux-001-review-40566ba556f9/UX-001-review.html), then open the downloaded HTML in a browser. Its buttons switch between actual synthetic screenshots; it does not install or connect to the app. SHA256: `c506d0731d349104c32803b3f33cf40f3729b0a32fb927a478220a839854f4ce`. Full downloaded bytes, GitHub digest, size and attachment headers were verified.

## Resulting behavior

Authenticated desktop pages use a stable role-specific navigation rail, wider work area, Corporate colors and consistent components. Smaller screens use compact navigation and stacked panels. Open/Previous order columns have independent pagination; compact 5/25/All controls appear below both lists and update automatically. Changing one list's page size preserves the other list's page.

Four numbered stages show the saved workflow position. A state-derived primary action opens a focused screen. Where more than one action is relevant, choosing a task reveals its original form, including existing consent, revision, CSRF and origin controls. Required saved-result recovery appears first. An attempted action remains bound to its exact saved request or message; it cannot silently switch to a different notice after the original form disappears.

Desktop Booking Review places the property/window/payment context beside the action. Mobile summary details collapse while property and window remain visible. Proposed customer windows remain separate from the confirmed appointment. Onsite Closeout is open on its selected screen and collapsed on the overview. Reviewed Vendor assignments and saved completed-job deliverables remain accessible when their recorded state permits them.

CTC's authenticated workspace was unavailable. This uses the user's explicit requirements and the Corporate site's effective palette; exact CTC parity is not claimed. The review contains synthetic records only.

## Four review passes

1. **Dependencies and call graph:** the shared shell loads presentation CSS/JavaScript for each role. The customer-specific script remains confined to customers. List preferences use GET; action selection displays existing forms. The domain handlers are unchanged.
2. **Source differences and invariants:** the reviewed layer records exact installed before bytes and implemented after bytes for eight presentation files. Staff authentication and POST handlers are byte-preserved. No financial, provider or authorization transition changed. Original pricing, ownership, fresh consent, recovery identities, amounts and payment gates remain authoritative.
3. **Automated and browser parity:** all 41 PHP suites pass on PHP 8.2 and 8.3 locally. Presentation assertions include 40 booking-step, 72 account-workflow and 96 shell checks per version. Full customer CI retains all seven browser journeys and original historical builders/installers. Responsive checks cover 320/390/736/1200/1600 pixels. Staff checks preserve nineteen baseline form contracts and three external-agent cases, plus automatic independent pagination, future-step gating, duplicate-request task identity and required recovery. No external browser provider calls are allowed.
4. **Deployment, interruption and preservation:** exact source release rehearsal builds the installed predecessor with its original builder/installer and verifies its published package hash. The candidate has 273 deployment files; `application.js` is the sole added file. Runtime schema and host-variant preservation are unchanged. Local PHP 8.2/8.3 rehearsals each complete 64 cases with one honest foreign-ownership skip. Host protocol passes 27 tests; actual isolated FPM passes eight tests. Privileged CI status is recorded below.

## Independent audit and CI

The independent audit closed without an implementation or accessibility blocker. Findings resolved include saved Vendor access, primary recovery priority, saved Job links, duplicate-request identity, automatic-refresh instructions and strict source reversal. The test-only follow-up was independently audited with disposable probes for unknown after bytes, wrong before hashes and malformed base64; all reject. Calendar audit retains twenty passing checks on each PHP version.

CI initially exposed shallow checkout history and a PHP historical helper that needed to reverse the new UX layer before the account layer. The follow-up fixes both prerequisites while keeping all integrity assertions. The successful reruns are authoritative; the earlier failed runs are retained.

| Check | Source / run | Result |
| --- | --- | --- |
| Full Server Form and privileged historical ownership | `c40236e`, [37986772292](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/37986772292) | PASS |
| Full Customer Portal and all seven browser journeys | `c40236e`, [37986772231](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/37986772231) | PASS |
| Exact-source release and privileged PHP 8.2/8.3 matrix | `40566ba`, [37986491039](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/37986491039) | PASS; 64 cases per version, zero privileged skips |
| Visual download publication | `40566ba`, [37986491011](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/37986491011) | PASS; downloads independently verified |
| Installable package: exact-source privileged matrices, full Portal and actual FPM | `c40236e`, [37987873795](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/37987873795) | PASS; 64 cases per PHP version, zero skips; seven browser journeys, 27 host and eight FPM tests |

The user subsequently requested GitHub package publication and an installation command. [Download the TEST package](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/download/unified-test-c40236e09375/unified-test-c40236e09375.tar.gz). All five release assets were downloaded and verified against local exact bytes, SHA256, GitHub digest/size and attachment headers. The operator executed the supplied root wrapper successfully: preflight, installation, exact-file/ownership/backup/TEST verification, actual FPM code refresh and serving checks pass. Connected UX acceptance remains pending.

The host preflight reports 273 files and 13 changes, read-only database integrity, all three known host variants preserved, PHP-FPM 8.2.34 with observable cache, and the unchanged canonical reconciliation job among 27 local scheduling sources. Backup: `/home/sitesee/.sitesee-real-estate/unified-deployments/unified-test-c40236e09375-7wgcojxh`. Operator output reports no deployment database migration, provider operation, configuration/gate/session/worker/callback change. Anonymous HTTPS account/staff pages respond with TEST/no-store; shared application CSS and JavaScript are byte-exact. These checks do not establish authenticated workflow or visual acceptance.

Evidence is retained outside the checkout in `/workspace/.sitesee-onboarding/ux-001-*`: original CI archives, local logs, six-screen review, downloaded asset evidence and history verification. The all-ref application bundle was verified through an isolated bare clone, exact pinned commits, all branch heads and full Git fsck. Earlier bundles and the original mirror remain preserved. Fresh-task restoration of the original mirror's local commits is not established by this check.

## Review and deployment boundary

Review the installed desktop and mobile layouts against [the UX brief](ITERATIONS.md). Record acceptance or subsequent changes in [CHANGELOG.md](../../CHANGELOG.md). Installation is complete; do not rerun the installer merely for more evidence. Earlier accepted cancellation/payment results remain historical evidence and need no redundant rerun for this presentation change. Provider records, schedules, sessions and configuration remain preserved according to the host output. Automatic refunds/credits and production/LIVE support remain separate work.
