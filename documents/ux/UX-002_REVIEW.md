# UX-002: CRM readiness handoff

Date: October 13, 2026, America/Chicago. Status: reviewed, validated and published as `unified-test-201b1888abad`, exact source `201b1888abad434d37236e3f478b3e0b2068919b`. Operator installation and connected readiness acceptance pending. Installed authority remains `unified-test-8c3ae16c726d`. Stripe stays TEST. No financial/provider/schedule behavior is changed.

## Problem and corrected behavior

The readiness POST returned verified exact-email CRM candidates. The focused action script continued to select the already completed Check Booking Readiness form and hid the candidate forms. Its chooser/back links then performed GET navigation, losing the ephemeral report and preventing the staff member from linking a returned contact.

The completed check now leads to the explicit CRM choice. A sole candidate displays its native, unchecked verification form; multiple candidates offer their separately labelled identities. Chooser/back links toggle existing forms in the returned document and move keyboard focus to the action heading. They issue no request, link no contact and submit no form. The native Link This CRM Contact POST remains the explicit next action, followed by the existing appointment-confirmation screen.

## Pass 1: call paths and dependencies

Only `public/portal-assets/application.js` and `_private/views/application-shell.php` change in deployment. The shell requests `application.js?v=ux-002`, avoiding reuse of the old cached asset. CSS, all routes, private configuration, sessions, database schema, role modules, provider adapters and domain handlers remain unchanged.

Same-document navigation retains returned readiness/contact/history data and native form tokens. Original modified-click and GET links remain server-validated fallbacks. Navigation itself never executes a domain operation. A refresh can discard a transient report; another explicit readiness check obtains fresh candidates, while the original session expiry/fingerprint guard prevents stale selection.

## Pass 2: identity, consent and complete source differences

Initial render still matches the exact posted action plus request/contact/message/revision identity; a completed saved operation cannot silently select another remaining operation. The only presentation transition is from a completed readiness check with returned contact candidates to their next selection/verification step.

Newly selected forms require fresh checkbox consent. Alternative-window pickers and excluded window-option forms also reset when changing actions; a previous window/consent cannot survive a detour to cancellation. The results and available alternatives themselves remain retained. An independent audit reproduced this transition regression during development; the reset correction and regression test resolved it.

Staff authentication, CSRF, same-origin checks, exact candidate membership, fifteen-minute selection expiry, booking fingerprint, CRM organization/email identity and transactional concurrent-change guards remain in the untouched original handlers. No contact is created automatically. Financial, payment, calendar and mail invariants are unchanged. Existing refund/credit connected acceptance remains pending.

`documents/unified/readiness-source.json` records exact before bytes from `8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b` and reviewed after bytes/hashes for precisely two files. Historical helpers reverse it before financial, workspace, account and earlier layers, rejecting unknown edits. Earlier manifests and package evidence remain immutable.

## Pass 3: automated and browser parity

All 42 PHP suites pass locally on PHP 8.2 and PHP 8.3, including the unchanged 85-check booking workflow suite and 87 financial assertions. The shared shell has 104 checks, including the updated script URL. All 110 Node checks and all seven synthetic HTTPS browser journeys pass.

The staff browser regression calls the actual authenticated readiness/link handlers with test-only GET provider adapters in a disposable copy. It verifies two exact-email matches, candidate A/B navigation without GET reload, native unchecked/fresh verification, required-checkbox submission prevention, original explicit verified contact linking and the confirmation screen without an automatic reservation. It also covers a single unchecked match, alternative-window action transitions, stable saved-operation identity, keyboard focus and 320/390/736/1200/1600 layouts. Network/mail transports and browser external egress remain blocked.

Readiness/financial/workspace source checks pass and compare pinned historical Git bytes. No production recipient, provider or integrity guard is relaxed to obtain passing fixtures. Privileged installer checks and final exact-source browser/FPM evidence must pass before publication; local unavailable-privilege skips remain distinct.

## Pass 4: deployment, interruption and preservation

The installer accepts exact installed predecessor `8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b`, package SHA256 `b79effc1e203bbc8d8d8d7c953edf45eb6bc101cafccd0798fbc9ef17429ce7e`, manifest SHA256 `dabd82a7d294cabb6994c71ef3fc4a5c96d28391f390379dcef289d4a89e069d`, its independently pinned schema description and all 274 predecessor files. No file is added or removed. Historical fixture construction uses the predecessor's own builder/installer and its published checksum.

The existing approved WHM-root download/checksum, preflight, backup, pause/drain, Apache/PHP 8.2 graceful reload, install/verify/FPM refresh and reopen process is retained. No deployment migration, provider operation, finance change, new schedule, session reset or configuration/gate change. Financial activity still blocks old-code/operational database rollback; preserve SQLite/WAL/journals and repair forward.

Initial exact-source installer verification found one historical fixture assumption: its mixed-deployment test always chose the staff handler even when that handler was unchanged. The corrected fixture chooses a genuinely changed predecessor payload and still asserts refusal of a corrupted release record and unjournaled mixed files, with exact preserved bytes. No application or installer guard changes were needed. Initial publication was withheld; the final source requires fresh privileged checks before publication.

Independent findings and results: [independent UX-002 review](INDEPENDENT_UX-002_REVIEW.md). Source-pinned publication evidence and operator installation results will be recorded separately.

## Frozen release and publication

Final source adds the fixture correction/CI paths/documentation to implementation `de9bc48`; all 274 deployed files and installer bytes remain identical. The independent agent verified all 683 source entries against exact Git, every predecessor hash and the exact archived 8c schema/manifest, and reran the real corrected tampering regression without weakened assertions.

Publication run [38446873572](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/actions/runs/38446873572) passed all five jobs and every step. Each job checks out exact source `201b1888abad434d37236e3f478b3e0b2068919b`. PHP 8.2 and PHP 8.3 each completed 65 directly privileged installer/recovery cases with zero skips. All seven browser markers, guarded readiness/contact handoff, consent/cache regression, 27 host protocol cases and eight actual FPM tests were verified in authentic CI logs. Source Server Form CI and Customer Portal Checks passed. The initial uncorrected fixture failure remains preserved as withheld-candidate evidence.

All five GitHub assets were independently downloaded and checked against full local bytes, SHA256, GitHub digest, size and attachment headers. [Package download](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/download/unified-test-201b1888abad/unified-test-201b1888abad.tar.gz), SHA256 `f7c94d3d65d206d98e08cf545a30996baffd4c27f133ce8e1b119d1a052d6285`. Installer `2df3fd62449d9827b9047cd8102a85e97ae9ac8cd09f45194169946664263657`; unchanged approved root wrapper `c6da86e9ecff3b57e126813a47f5ef1f698840e243022a5065a8f587eeb21d35`; manifest `38ef090abe2c6b24eabb445b85d8e3327a3e1a97f9922ca8ea97ff9c7a1bf19e`.

Operator execution remains pending; no host or provider operation was performed by the assistant. After installation, reload the booking and run readiness for a fresh contact-selection ticket, verify the correct contact explicitly, and continue to the existing confirmation step. Do not replace or recreate the paid booking to bypass the UI issue. Existing refund/credit connected acceptance is retained as pending.
