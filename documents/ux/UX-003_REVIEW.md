# UX-003 — client workspace, reports and archives

Date: October 13, 2026, America/Chicago. Stripe TEST. User-requested refinements follow acceptance of the previous connected tests. Reviewed predecessor: `201b1888abad434d37236e3f478b3e0b2068919b`. The operator has not installed this candidate.

## Pass 1 — routes, dependencies and workflow

The shared list query classifies cancelled lifecycle state as closed for both customers and management. Production completion and explicit closure still close other orders; onsite completion alone stays open. Reliable closure timestamps older than 14 days move an order into Archive without deleting or modifying a record. Undated legacy closures remain accessible in Previous. Billing queries all closed history, including Archive. Account ownership joins and active-account checks remain mandatory.

Customer navigation is New Order, Reports, Billing, Archived Orders, Profile and My Orders. Incomplete Profile routes the first New Order link to required profile fields, without exposing the order wizard. Existing order details retain an appointment summary above their selected section. Appointment, job and billing views use the same wrapper. Native GET routes remain available without JavaScript; state gates retain control of appointment and delivery actions.

The two added private modules are `portal-reports.php` and `portal-report-pdf.php`. PDF uses the existing licensed Inter font and PHP built-ins; compression is optional. No external reporting service or new runtime dependency is introduced. Report navigation makes no provider request. The shared stylesheet and scripts use `v=ux-003`; the ordering script uses the same fresh version for the rush highlight. Package and service names are bold.

## Pass 2 — data, accounting and complete differences

The new source layer records exact predecessor/current bytes and hashes for 16 runtime files. Historical tests reverse this layer before the unchanged older reviewed layers and refuse unknown source edits.

Reports cover Central calendar month, quarter, year or inclusive custom dates. DST boundaries use local calendar midnights. Recorded cash deposits, balance payments and onsite final payments are counted separately from applied credit; unpaid attempts and estimates are excluded. Payment intent/refund identity deduplicates cash activity. Reports and PDF expose no provider/account identifiers.

The additive `booking_finance_refund_dates` evidence table stores the first verified successful refund confirmation. It changes no refund request, amount, idempotency key or cancellation policy. Exact provider charge/refund verification remains mandatory. Under a transaction, the current immutable refund fields are checked again; the first new successful state and its date commit together. An already-succeeded legacy refund without date evidence remains undated. Retries cannot invent or advance its date. A slower pending response cannot regress a concurrently verified succeeded refund under the re-read lock. Missing historical refund dates and known undated manual adjustments/disputes make net expenses unavailable rather than overstated. Pending refunds are excluded and labelled for review. The report explicitly describes recorded/verified dates and requires Billing review of manual adjustments before tax use.

PDF download links pin the displayed resolved dates, including across period rollover. PDF embeds Inter with glyph mapping, widths and Unicode extraction and paginates long reports. Unsupported glyphs fail transparently with a return to the complete on-screen report. PDF output never silently replaces property text.

## Pass 3 — browser parity, consent and accessibility

Inline navigation is GET-only, same-origin, `/account.php`, same order and an allowlisted section. Requests are abortable; authenticated responses must contain the matching workspace. Session expiry/error falls back to the native route. Secure payment scripts require their original full CSP/initialization and therefore use normal navigation. Billing's existing verified Stripe payment-method redirect remains allowed by form-action. No GET manufactures payment or consent.

Imported native forms retain their original action, account ownership, CSRF and origin checks. Action focus initializes each new panel once. New/restored arrival selections clear both the window and consent, including pageshow/bfcache. Payment forms still require explicit fresh agreement. Browser Back revalidates the native GET. The selected panel receives keyboard focus and the summary remains visible. Controls retain focus indicators, accessible headings/status, readable contrast and mobile layouts.

Coverage includes account isolation, actual inline services/payment/appointment, native appointment approval/cancellation, immediate customer cancellation classification, month/quarter/year/custom reports, real attachment PDF, invalid range refusal and responsive 320/390/736/1200/1600 layouts. Staff fixtures now explicitly expect both cancellation cases in Previous, preserving all nineteen original form contracts and three external-agent cases. Existing job payment and deliverable gates remain in the browser journeys.

## Pass 4 — deployment, interruption and preservation

Builder and installer independently pin the predecessor commit, original manifest SHA256 and original financial schema descriptor. Only the two reviewed private report modules may be absent from the predecessor payload set. The candidate runtime descriptor documents the additive evidence table; no installation-time database migration occurs. Configuration, TEST gates, records, schedules, provider callbacks and sessions are preserved. Existing host-owned `.htaccess`, illustration and Turnstile variants remain hash-pinned.

Financial activity, including refund-date evidence, prevents old-code restoration. Repair forward preserves the operational ledger; no operational database restore is authorized. The unchanged approved WHM helper retains its exact SHA256 and performs download verification, pause/drain, backup, shared graceful reload, install, exact code/FPM verification and reopen. The installer resumes known journals without overwriting unknown host edits.

Final local checks, exact-source CI, frozen package audit and GitHub asset evidence are recorded below after completion. Independent audit is required before publication. The assistant performs no hosting update or LIVE enablement.


## Local validation

All 43 PHP suites pass on PHP 8.2.34 and PHP 8.3.35. The report/archive suite completes 29 checks; financial automation completes 95 assertions, including immutable/legacy dates, atomic interruption recovery and a stale-pending/concurrent-success interleaving. Independent `pdftotext` extraction verifies supported Unicode; unsupported font coverage is explicitly rejected. The seven HTTPS browser journeys pass, including actual inline appointment and verified Delivery, real PDF attachment download, frozen report dates, account isolation and responsive layouts. 110 Node checks pass; Python discovery completes 153 cases with 22 unavailable local-privilege skips. Historical source-layer checks pass and 27 host-protocol checks pass. Required directly privileged fixed-package PHP 8.2/8.3 installer tests and actual FPM checks remain pending in exact-source publication CI; no local skip is counted as a pass.


## Frozen release and publication

Source `e0bed2b3726c05b52c5d2c943ccd887cd3b376b8`, release `unified-test-e0bed2b3726c`, package SHA256 `82be15dbd74b6f0e22627c2992d0db0fc743adf752a47dfb9799e8a7702ad0b6`, installer SHA256 `068ad6744d61add55c2470f99e1ed7a07d87e4a93f527f108c27c7ec64b6add4`. Independent audit verified all276 runtime payloads/all689 source entries and exact201b predecessor/manifest/schema/digests; only two private report modules added. Independent artifact: [INDEPENDENT_UX-003_REVIEW.md](INDEPENDENT_UX-003_REVIEW.md).

Publication run38470116563 passed allfivejobs/every step. Directly privileged65 installer/recovery cases perPHP8.2/8.3, zero skips; allseven browser markers, actual inline appointment and verified Delivery, finance95 assertions, report/PDF checks, host27 and actualFPM8 were checked in authentic CI logs. Allfive release assets were independently downloaded and verified by bytes, SHA256, GitHubdigest, size and attachment headers. Source Server Form CI and Customer Portal Checks passed before publication. Local65 installer cases also passed with one unavailable-privilege skip; CI supplied its real privileged evidence.

The operator command uses the unchanged verified WHM helper and exact package/installer pins. Package publication and local synthetic proof do not install the hosting update. This iteration's connected UI acceptance remains pending. Earlier accepted connected financial/cancellation/readiness tests are retained. Stripe remains TEST.


CI completes 28 core report/archive checks; the 29th local check uses independently installed `pdftotext`, absent from the CI image. This parser check is not claimed as a CI result.
