# UX-002: CRM readiness handoff

Date: October 13, 2026, America/Chicago. Status: implemented and locally validated; exact-source CI, publication, operator installation and connected acceptance pending. Installed authority remains `unified-test-8c3ae16c726d`. Stripe stays TEST. No financial/provider/schedule behavior is changed.

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

Independent findings and results: [independent UX-002 review](INDEPENDENT_UX-002_REVIEW.md). Source-pinned publication evidence and operator installation results will be recorded separately.
