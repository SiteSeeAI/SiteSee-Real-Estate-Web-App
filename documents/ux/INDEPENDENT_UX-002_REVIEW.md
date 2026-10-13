# Independent review — UX-002 readiness handoff

Reviewed independently on 2026-10-13 under `CODEX_HANDOFF.md:97`. This review covers the working-tree correction against installed source `8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b`. The documentation checkout HEAD was `931486c52cc801e360eb98fa1af937befe1d4b82`; the new release source had not yet been frozen, published or installed.

**Conclusion:** No remaining implementation or source-compatibility blocker was found in the corrected draft. Independent browser validation passed. A frozen package, its CI/release evidence and installed-host verification remain separate gates.

## Root cause and corrected behavior

Source inspection confirmed that a successful `workflow_check` POST selected the old check form and hid the returned `workflow_link` contact forms. The generated task links then performed a GET, which discarded the transient readiness report and its choices.

`public/portal-assets/application.js` now presents the returned contacts as the next action. Multiple contacts require an explicit choice; a sole matching contact exposes its original unchecked verification form. Choosing or going back selects existing forms in the same document, preserving the returned report. These links do not submit a form or link a contact. Keyboard focus follows the selected action heading. Native form verification remains required before the unchanged staff POST handler calls the original guarded link function.

Initial saved-operation selection still matches the exact submitted action and identity. The readiness transition is limited to returned contact forms after `workflow_check`. Normal GET/reload and modified-click fallbacks continue through the original session-ticket, expiry, booking fingerprint, contact identity and provider-read guards. Calendar confirmation remains a separate unchecked action after linking.

Only two application payloads differ from the installed source: the shared script and the shared shell's script query version. Authentication, role/session handling, CSRF/origin checks, business/domain modules, payment/credit/refund processing, provider identities and original form templates remain byte-identical to installed 8c source.

## Findings resolved during independent review

- **Medium — stale alternative choice and consent after same-document navigation.** The first script draft cleared checkboxes only in selected action forms. Alternative-window forms are separate and excluded from the action entries. An independent browser harness using the actual application and window-picker scripts reproduced: choose window A, check consent, choose cancellation, then return to alternatives; the picker remained A, its form remained visible and consent remained checked. Action transitions now clear the panel's window picker, hide its alternative forms and clear their checkboxes without discarding the alternatives or returned report. The same harness passed after correction: blank picker, unchecked consent and no visible alternative form. The production staff browser regression also covers this transition.
- **Medium — browser cache could retain the blocked script.** The shared shell originally continued requesting `application.js?v=ux-001`, with no repository static-asset no-store rule for that script. It now requests `application.js?v=ux-002`. The browser regression verifies the new script URL; stylesheet and role-specific dependencies are retained.

No unresolved security, financial or workflow defect was found in the final draft. The change does not automatically consent, select another saved operation, reserve an appointment, send an invitation, create a CRM contact or initiate a payment/refund.

## Source and release compatibility

`documents/unified/readiness-source.json` contains exactly the two changed application files, with before bytes and hashes independently matched to exact installed 8c Git objects, and after bytes and hashes matched to the reviewed working tree. Python and PHP historical reconstruction reverse this layer before the finance and earlier presentation layers. Independent probes accepted exact source and refused an unknown current edit, an incorrect before hash and invalid before base64.

The new builder and installer pin the installed predecessor commit, its published manifest SHA-256 `dabd82a7d294cabb6994c71ef3fc4a5c96d28391f390379dcef289d4a89e069d`, its existing financial runtime-schema declaration and the unchanged complete payload-path set. Independent reading of the archived predecessor confirmed package SHA-256 `b79effc1e203bbc8d8d8d7c953edf45eb6bc101cafccd0798fbc9ef17429ce7e` and those exact metadata values. Release tests construct the predecessor with its own historical builder/installer and validate that published package hash.

The financial repair-forward guards remain unchanged: financial ledger activity prevents restoring old code, and operational financial state is not rolled back. The existing host traffic/worker drain, installer ownership/path/source/TEST checks, recovery journal and FPM verification procedure remain in force. No new schema, worker, callback, credential or provider operation is introduced by this presentation correction.

Reviewed draft hashes: application script `6c86b90e2f8659036a5050fbd99321c782598df7103d90c4fb07f9a226d8ddbb`; shared shell `1c7f4a149488fc91b2f4e9c53edf6e076458946a5b2c7dc3efe0bee4b5f59b5f`; readiness source layer `b5a8075a8daf0f492cb8dc2bd739ce9413ca2d92aa8b1aa29bab56eaee98faec`. These describe the draft review, not a verified new release package.

## Independent validation and remaining gates

- The complete focused staff HTTPS browser suite passed independently: 19 preserved baseline states, Vendor and external-agent cases, real guarded readiness POST, two contacts with choose/back and fresh consent, native required verification, explicit contact 790 linking, separate calendar handoff, single unchecked contact, stable saved-request identities, action-transition window reset, keyboard focus and responsive layouts at 320/390/736/1200/1600 pixels. The fixture calls the original readiness/link implementations with synthetic GET-only provider dependencies and disables external transports; original server/session guards execute.
- The unchanged booking-workflow suite passed **85 checks on PHP 8.2 and PHP 8.3**, including missing/expired/cross-booking selections, unlisted contacts, changed provider identities and concurrent local changes.
- The application-shell suite passed **104 checks on PHP 8.2 and PHP 8.3**. Readiness-source and UX-source suites each passed **2 cases**. JavaScript syntax checks passed.
- The independent alternative-window action-transition harness passed after the reported correction. Exact source-layer inventory and strict negative reconstruction probes passed.

The implementation agent additionally reported all seven local browser suites, all 42 PHP suites on both versions, 110 Node checks and the finance-source checks passing. Those broader results are attributed to the implementation agent; this review independently ran the focused checks above.

The exact frozen commit/package, source-pinned CI, privileged release rehearsal, selected final evidence and publication remain pending at this draft closure. The installed host still requires verification that the new script URL loads, readiness choices survive selection/back, explicit verification links the intended existing contact, and calendar confirmation remains separate. This review performed no host or external provider operation and changed only this explicitly requested audit artifact. Stripe remains TEST.
