# Independent review — UX-003 client workspace, reports and archives

Reviewed independently on 2026-10-13 under `CODEX_HANDOFF.md:97`. The installed predecessor is `201b1888abad434d37236e3f478b3e0b2068919b`. Application changes were reviewed before and at runtime freeze `a5a1b700811a6593f9ad0b7a8b42c60a1d169734`; the final metadata/source commit is `e0bed2b3726c05b52c5d2c943ccd887cd3b376b8`. Its runtime payloads and installer are byte-identical to the reviewed runtime freeze.

**Conclusion:** No remaining implementation, ownership, financial-state, historical-source or package-compatibility blocker was found in this candidate. Focused independent tests and the customer HTTPS browser passed. Exact-source privileged publication checks, publication evidence and installed-host acceptance remain separate gates; this review does not claim that they have completed.

## Scope and preserved contracts

The reviewed changes implement cancellation/list classification, conservative 14-day archives, customer navigation, inline order sections, recorded expense reports/PDF, rush highlighting and bold service names. Cancelled orders leave Open immediately. Production completion or explicit closure closes other orders; onsite completion alone does not. Archive requires reliable closure evidence strictly older than 14 days. An undated historical closure remains in Previous. Billing includes archived history. Classification does not delete orders or change lifecycle, calendar, payment or job records.

Customer report/list queries retain account ownership and active-account checks; staff lists remain behind the existing staff guards. The new report views perform no provider request. Original purchase, appointment, payment, consent, delivery and staff POST forms retain their native handlers, identifiers, session/CSRF/origin checks and provider verification. Incomplete-profile New Order navigation returns to the required profile fields; the purchase guard remains authoritative. Cancelled orders do not expose the future Manage Appointment tab.

Inline section navigation accepts only same-origin, same-order, allowlisted `/account.php` GET links. A returned document must contain the matching workspace. Errors, expired sessions, mismatches and script-bearing payment sections fall back to the native route. Native payment initialization and CSP remain in force. Imported sections initialize focus and arrival selection without granting consent: arrival windows and checkboxes reset on panel insertion and pageshow. Keyboard focus follows the selected section; modified-click and no-JavaScript navigation remain available. Published delivery links retain the original verified-payment and production gates.

## Findings resolved during independent review

1. **Accounting accuracy — known undated adjustments overstated net expenses.** The initial report could include the gross paid closeout amount despite a known refund/dispute. Missing historical refund dates and known undated manual refund/dispute adjustments now mark Net Cash Expenses unavailable and require Billing review. Pending refunds remain excluded and visible for review.
2. **Accounting period — refund request date was treated as the refund date.** A request in one month that succeeded in another could be attributed to the wrong period. The additive `booking_finance_refund_dates` table now records the first durable verified successful confirmation; reports use that evidence. Unknown historical dates are not guessed.
3. **Recovery — late readback invented dates for already-succeeded legacy refunds.** An independent harness using the real finance module and synthetic TEST provider reproduced an old succeeded, undated refund acquiring a new current date on reread, with no new refund POST. The corrected locked reread inserts a date only when the fresh saved state transitions from unconfirmed to succeeded. A legacy succeeded-undated refund stays undated. Successful state and its first date commit atomically; interruption rolls both back and recovery reuses the saved refund identity.
4. **Concurrency — stale pending readback could regress a verified success.** An independent interleaving reproduced a pending response overwriting a concurrent succeeded state while its date survived. The transaction now reselects the current saved immutable refund fields and refuses any non-succeeded incoming state when the fresh state is succeeded. The same interleaving passes after correction: succeeded state/date remain intact and no replacement refund is submitted.
5. **PDF integrity — extracted Unicode did not prove a visible glyph existed.** Independent font inspection found that the initial test's unsupported character mapped to glyph zero even though ToUnicode extraction returned its text. PDF generation now rejects unsupported glyphs instead of silently drawing a replacement. Covered accented text retains embedded glyphs and Unicode extraction; the controlled error returns to the complete on-screen report.
6. **Report identity — period rollover could change the downloaded date range.** Preset download links previously recomputed the current period. PDF download and error-return links now pin the displayed resolved dates using a custom range.
7. **Navigation accessibility — incomplete profile marked two items current.** New Order's required-profile fallback now has its distinct `required=1` URL; Profile alone receives `aria-current="page"`.

All seven findings are resolved in the reviewed final runtime. The new confirmation-date transaction retains the existing exact TEST refund/charge readback, amount, charge, payment intent, operation identity and immutable request checks. No cancellation rule, refund amount, idempotency key or credit/cash allocation is changed by this iteration.

## Accounting and PDF limits

Reports describe the account-owned recorded ledger, using Central calendar boundaries and an exclusive next-day end for inclusive custom dates. Verified cash deposits, balance payments and onsite payments are distinct from credit; applied credit is never added to cash paid. Payment/refund identities deduplicate cash activity. Estimates and unpaid attempts are excluded. Recorded/first-verified dates can differ from bank posting dates. Unobserved external adjustments are not established by a local report; its notes direct Billing review before tax use. Unknown refund-date evidence causes an unavailable net total rather than a fabricated historical value.

PDF is a real paginated attachment with the existing licensed Inter font, glyph widths and ToUnicode mapping. It uses PHP built-ins, native UTF-8 codepoint decoding and optional compression, with no external PDF service or iconv requirement. It supports the font's covered Unicode; arbitrary CJK/emoji coverage is not claimed. Unsupported text yields an explicit error and preserves the complete on-screen report. Independent validation parsed the PDF and extracted supported text; it does not establish exhaustive visual/font coverage for all possible property strings.

## Exact source and frozen package verification

The strict `documents/unified/client-workspace-source.json` layer covers exactly **16** changed runtime paths. Its before bytes/hash independently match exact installed 201b Git objects, including empty before bytes for the two added modules; its after bytes/hash independently match the final runtime. Historical reconstruction reverses this layer before the readiness, finance and older reviewed layers, and rejects unknown current edits. The final compatibility description and Customer Portal CI path filters include this iteration and its source test.

The independently inspected frozen package is `unified-test-e0bed2b3726c.tar.gz`:

| Item | Independently verified value |
| --- | --- |
| Source commit | `e0bed2b3726c05b52c5d2c943ccd887cd3b376b8` |
| Package SHA-256 | `82be15dbd74b6f0e22627c2992d0db0fc743adf752a47dfb9799e8a7702ad0b6` |
| Package size | 58,559,071 bytes |
| Manifest SHA-256 | `1d9f8f6bc14dccabf3894501c1e9b2792389232a3c6684628851b6f6d2a29fb0` |
| Installer SHA-256 | `068ad6744d61add55c2470f99e1ed7a07d87e4a93f527f108c27c7ec64b6add4` |
| Runtime/source inventory | 276 payloads; 689 source/test entries |
| Source-layer SHA-256 | `5353834336741e057e2a8fd6c6a40ab9e2e979d68bc5deacfb255555d56dc539` |
| Unchanged host-helper SHA-256 | `c6da86e9ecff3b57e126813a47f5ef1f698840e243022a5065a8f587eeb21d35` |

Every payload byte matches the exact e0 Git object and current frozen application file; every source/test manifest hash and length matches the complete exact Git tree. Archive entries are unique regular files with the expected deterministic metadata. The bundled/standalone installer bytes match exact source. Its actual loader successfully validates the exact package and returns the reviewed files/manifest. Stage is TEST and installation migration is none.

The actual archived 201b package independently matches SHA-256 `f7c94d3d65d206d98e08cf545a30996baffd4c27f133ce8e1b119d1a052d6285`, original manifest `38ef090abe2c6b24eabb445b85d8e3327a3e1a97f9922ca8ea97ff9c7a1bf19e`, the pinned original runtime-schema declaration and all **274** predecessor payload digests. Exactly two new private modules are allowed: `portal-reports.php` and `portal-report-pdf.php`. No predecessor payload is removed. The old release record is checked against its own schema declaration, not the candidate's new descriptor.

The candidate's runtime descriptor explicitly documents additive refund-date evidence on first normal application use. The installer performs no operational database migration or restore. Any financial activity, including rows in the new evidence table, prevents old-code restoration and requires repair forward. Existing path, ownership, source, TEST, host-binding, journal and recovery checks remain in force. The staged host helper is byte-identical to the installed predecessor's source helper; the existing pause/drain, backup, code/FPM verification and failure-preserving reopen procedure is retained.

## Independently observed validation and remaining gates

- Report/archive suite: **29 checks passed on PHP 8.2 and PHP 8.3**, freshly repeated on exact final source. Coverage includes owned recorded cash/credit/refunds, Central/DST ranges, missing/old/cross-month evidence, manual adjustments, supported PDF extraction, unsupported-glyph refusal, pagination, exact archive boundaries, disabled accounts and owner isolation.
- Finance suite: **95 assertions passed on PHP 8.2 and PHP 8.3**, freshly repeated on exact final source. Its original authorization, concurrent credit reservations, cash lineage and refund recovery checks remain; new checks cover immutable/legacy dates, atomic interruption recovery and stale-pending/concurrent-success interleaving.
- Client source suite: **2 cases passed** on exact final source, including exact installed before bytes, new-module reversal and unknown-edit rejection. Independent full package/source and predecessor inventories passed as recorded above.
- The independent legacy-date and stale-pending recovery harnesses passed after correction, using the actual finance implementation with synthetic TEST provider responses and no external transport.
- The complete focused customer HTTPS browser passed independently: authenticated/anonymous shell, actual inline services/payment/appointment, cancellation lists, account-owned reports and real PDF download, frozen report dates, invalid inputs, CSRF/origin, single-use/expiry/logout replay, profile isolation and responsive 320/390/736/1200/1600 layouts. External providers were disabled/blocked. The original native identity and server guards executed.

The implementation agent separately reported all 43 local PHP suites on both versions, all seven browser suites (including the added inline Delivery journey), 110 Node checks, historical discovery and host-protocol checks passing. Those broader results are attributed to that agent; this review independently observed the focused evidence listed above.

Exact-source privileged release/FPM publication checks and final asset evidence were pending when this artifact was written. Installed-host acceptance must still verify the delivered static versions, native and inline appointment/payment/delivery behavior, cancellation/archive classification, report/PDF range identity and actual host font availability. No host or external provider operation occurred in this review. Only this explicitly requested review artifact was written; implementation source remained frozen. Stripe remains TEST.
