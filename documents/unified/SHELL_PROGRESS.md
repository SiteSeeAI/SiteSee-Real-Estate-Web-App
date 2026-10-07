# Shared role shell

Subsequent local release and installer work is recorded in [RELEASE_PROGRESS.md](RELEASE_PROGRESS.md); that record tracks the remaining hosting and CI gates.

Baseline: bootstrap commit `989f09e7730a843dd367e4ec8ad602b8d99d5029`, following pinned application checkpoint `ff5e625a8ff4c51af336e9203fa4be29b3611570`. Brief: [CODEX_HANDOFF.md](../../CODEX_HANDOFF.md). This is application integration work; the consolidated installer and installed acceptance are still pending.

Customer, vendor and staff pages now use one private page renderer and a shared header, TEST notice, keyboard skip target and footer. Each keeps its original form styles and controls. Navigation appears only when the caller has authenticated its own role: customer Orders/Account/New Order, vendor My Jobs, and staff Bookings/Production/Vendors. Staff retain password authentication; customer and vendor retain their separate phone identities, cookies, sessions and permissions. The renderer starts no session, opens no database and makes no provider call.

Only three existing production files change in this slice: the customer page wrapper, the vendor page wrapper and staff presentation. Customer forms, vendor authorization/action code and staff authentication/POST handlers remain byte-identical, apart from moving the existing staff navigation into the common header. Original account truthiness and staff expiry comparisons are preserved. Staff CSP adds same-origin styles so the shared CSS can load; all other directives remain unchanged. Domain calculations, provider state machines, data schemas, public aliases and signed URLs retain their previous code and behavior.

[shell-source.json](shell-source.json) stores original/reviewed bytes and hashes. The historical compatibility helper reverses this reviewed layer before the bootstrap layer and refuses unknown edits. Original installer artifacts and release manifests remain byte-identical. [shell-preserved-source.json](shell-preserved-source.json) records the remaining 245 original application/public files; the three intentional presentation changes are explicitly excluded.

## Four reviews

1. Dependencies and roles: all aliases still target their original private handlers. The new renderer is required by each page wrapper before use; its role argument is a fixed caller literal. Its navigation provides no authorization and cannot change server permissions. Existing session/account checks remain responsible for access.
2. Complete differences and invariants: the source contract compares original customer form functions, vendor actions and staff authentication/action/form code independently of the rendered shell. Pricing, commission, payment, invitation, calendar and ownership functions are unchanged. New CSS is scoped to the shared header/notice/footer.
3. Automated and browser parity: final results and explicit completion markers are in [SHELL_TEST_RESULTS.json](SHELL_TEST_RESULTS.json). Existing HTTPS journeys additionally check anonymous/authenticated navigation, actual shared stylesheet delivery, parsed CSS rules and the distinctive computed brand size, role page classes, skip targets and brand fonts. Original nineteen staff form contracts and all financial/identity journeys remain active. Desktop screenshots were inspected; existing suites cover 320/390/736/1200 widths. Providers are synthetic and browser/provider egress is blocked.
4. Deployment and preservation: install the new private renderer and public stylesheet before activating changed wrappers. No schema migration, data write, provider replay or integration URL change is introduced. Historical packages are still recovery evidence, not the installer for this milestone. Full interruption, WAL backup/restore, worker/webhook ownership and installed TEST acceptance belong to the upcoming consolidated release gate.

## Completed checks

| Check | Result |
| --- | --- |
| PHP 8.2 and PHP 8.3 | 38/38 suites each; 87 bootstrap and 80 shell assertions included |
| Node | 109 passed, no failures or skips on current source |
| Browser | Seven suites; customer/vendor/staff CSS and role navigation before and after sign-in |
| Portal Python | 291 passed; one privileged case skipped |
| Historical Python | 131 passed; 22 privileged cases skipped on each PHP version |
| Historical builders | All 19 reproduce original artifacts; 20 unknown source edits rejected |

The skipped ownership checks require real privileged CI and remain release gates. Logs and synthetic screenshots are hashed in [SHELL_TEST_RESULTS.json](SHELL_TEST_RESULTS.json). The independent review is recorded in [INDEPENDENT_SHELL_REVIEW.md](INDEPENDENT_SHELL_REVIEW.md).

## Verification correction

The earlier bootstrap report included a 109-pass Node log produced before the shared loader. A current-source run exposed two tests requiring hard-coded private includes. Those contracts now verify the shared loader, its original private root and the exact original handler registration. All delivery-code exclusions and Turnstile/action controls remain asserted; all 109 current-source Node tests pass. The earlier record is marked historical, and the initial failed run is retained separately from final evidence.

The new browser assertions also exposed two synthetic router allowlists missing the new CSS file. Their lists now permit that exact asset; no other route or transport was opened. Final browser evidence verifies its delivery and application rather than relying on an HTML link alone.

## Remaining operator work

The user confirmed cPanel/WHM with PHP 8.2 and available Terminal, and chose `re.sitesee.ai` for installed TEST review without a new hostname. No cPanel change is required for this local milestone. Next assemble a release from a fixed reviewed commit and one preflight/install/resume/verify command, rehearse installation and SQLite-consistent backup/restore, complete privileged CI and independent review of that complete candidate, then present one exact server update for approval. Preserve all existing records, signing secrets, integration identities, worker/webhook ownership and backup histories. Keep Stripe in TEST mode.
