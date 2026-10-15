# Final TEST acceptance — October 15, 2026

Functional TEST acceptance passes. This records checks performed, prior operator acceptance, and the remaining release-identity and LIVE preparation limits. It does not authorize LIVE activation or a new installation.

## Application and tested source

The application remains UX-003, `unified-test-e0bed2b3726c`, commit `e0bed2b3726c05b52c5d2c943ccd887cd3b376b8`, with 276 deployed files. Package SHA256: `82be15dbd74b6f0e22627c2992d0db0fc743adf752a47dfb9799e8a7702ad0b6`. No runtime or deployment-installer bytes have changed since that release.

Regression source `1682a1e0912cc969b5fd52e7dd3cff6f70060fba` changes positive test dates only. On October 15, the previous relative date selected Sunday morning, which production correctly blocks. The shared fixture helper chooses a future Tuesday/Wednesday pair with adequate lead time and avoids fixed holidays. Original assertions, Sunday/holiday negative cases and production availability policy remain intact. Historical diagnostic fixture contracts remain unchanged. No application deployment is needed for this correction.

## Verification completed

| Check | Result |
| --- | --- |
| PHP suites, PHP 8.2.34 and 8.3.35 | All 44 on each version pass |
| Node unit tests | 110 pass; none skipped |
| Isolated HTTPS browser journeys | All seven pass, with synthetic providers and external browser egress blocked |
| PHP syntax | All 193 files pass |
| Python discovery | 153 cases complete successfully; 22 privileged cases skipped locally |
| Privileged installer CI | 168 cases pass, zero skips |
| Historical UX/finance/readiness/client source layers | All four pass |
| New date helper | 55 boundary checks and independent 18,996-case date sweep pass |

Exact-source [Server Form CI 38750940333](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/38750940333) and [Customer Portal Checks 38750940310](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/38750940310) pass every job and step at the regression source above. Authentic logs were checked for the privileged completion count and each of the seven browser completion markers. The independent fixture audit closed without an outstanding finding. Initial date-related failures and corrected logs remain separately retained in the execution evidence.

Counts, CI log digests and completion markers are preserved in [the result record](FINAL_TEST_RESULTS_2026-10-15.json).

Anonymous HTTPS checks of customer, staff and vendor sign-in pages return 200, TEST access and no-store. The three served shared client assets match the exact UX-003 source. These checks prove the observed responses and assets; they do not establish the complete private installation or current FPM code.

## Connected acceptance recorded

On October 15 the operator answered **“All three work”** for cancelled orders moving to Previous, order sections opening inline below the appointment overview, and Current Month reports displaying results with a readable PDF download.

Earlier operator acceptance of customer/staff/vendor sign-in and isolation, assignment, closeout, final payment, delivery, calendar changes and approval/decline notices, both cancellation paths, CRM readiness and financial automation remains accepted. The October 13 confirmation explicitly accepts the preceding connected tests. These are operator reports, not newly repeated provider transactions. No request to repeat those accepted tests is necessary.

## Installed release identity

The complete UX-003 installation transcript and backup name have not been supplied. Public assets and accepted UI behavior corroborate the working release but cannot reconstruct that transcript.

The companion [read-only release check](check-installed-test-release.py) checks the 276 expected file hashes, pinned release record, installed journal, absence of the application maintenance marker and presence of the exact journal-named backup directory. It opens only the two fixed SiteSee application roots and approved payload paths, rejects aliases, and performs no writes, PHP bootstrap, database access, provider calls, service operations or broad inventory. Run it as `sitesee` in cPanel Terminal, after verifying the distributed script checksum. It is a diagnostic, not an installer. A PASS does not establish current FPM code, backup-content integrity or a fresh restore rehearsal. Its exact host result remains pending.

Initial independent diagnostic review passes 22 synthetic cases: two complete controls and 20 correctly stopped negatives, including replacement of either root, aliases, edited files, untrusted paths, incomplete journals, wrong package identity and unsafe metadata. Instrumented outside-source sentinel reads remain zero. Initial reviewed script SHA256: `8a4a2c4f593d9ee0d05c845d1551096eae72d9e74907a82979fc85339f430522`.

The operator subsequently verified that exact download but the check stopped with errno 13 opening `/`, before accessing application files. QA-002 corrects its unnecessary directory-read requirement: the root anchor and intermediate directories now use search-only Linux `O_PATH` handles, retaining `O_DIRECTORY` and `O_NOFOLLOW`. Actual unprivileged mode-0111 directory probes reproduce the old denial and accept the corrected handles. No server chmod, root terminal or installation is needed. Corrected checker SHA256: `899645d659242a1f3243b8e8908e8c3ab2dc64c9b8df39084cd9cb8445f6dd82`. Its host result remains pending; prior application acceptance is unchanged.

The corrected checker independently passes all 28 expected outcomes: 22 previous regressions plus six actual UID-1000 permission cases. Outside-source reads remain zero. Published GitHub bytes match the exact reviewed hash, and the updated operator command passes shell syntax checking.

## LIVE preparation remains separate

Changing Stripe keys alone cannot launch this build. The current bootstrap explicitly rejects LIVE stage and LIVE credentials. Payment/webhook, portal/SMS, calendar/mail and financial-worker paths also enforce TEST. Pure local probes independently confirm TEST succeeds and LIVE is rejected; those guards remain intact.

A separately reviewed release must support LIVE consistently across those paths, separate TEST and LIVE ledger/provider identities, and verify the actual LIVE configuration before activation. Test orders, refunds and credits do not disappear when a key changes; preserve and isolate them so TEST credit cannot fund a real charge. Existing financial activity requires repair forward rather than restoring an old operational database.

Cleanup remains paused. CTC Publishing is outside this work, including read-only access. No hosting installation, provider write, schedule/service change, credential change, operational data reset or LIVE activation was performed during final testing.
